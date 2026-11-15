<?php

/**
 * Xendit Invoice API: creates invoices and expires them.
 * Payment is never confirmed here; only the signed webhook (PaymentController) does that.
 */
class XenditClient
{
    const API_URL = 'https://api.xendit.co';

    private $secretKey;

    public function __construct($secretKey)
    {
        $this->secretKey = trim((string) $secretKey);
        if ($this->secretKey === '' || !function_exists('curl_init')) {
            throw new RuntimeException('Xendit is not configured or PHP cURL is unavailable.');
        }
    }

    /**
     * Creates an invoice that expires after $durationSeconds, normally when the reservation's
     * hold ends, so an invoice can never be paid long after the dates were released.
     */
    public function createInvoice(array $reservation, $frontendUrl, $durationSeconds)
    {
        $reservationId = (int) $reservation['id'];
        $externalId = 'caren-reservation-' . $reservationId . '-' . bin2hex(random_bytes(6));
        $duration = max(60, min(86400, (int) $durationSeconds));
        $payload = [
            'external_id' => $externalId,
            'amount' => round((float) $reservation['total_amount'], 2),
            'currency' => 'PHP',
            'description' => trim($reservation['make'] . ' ' . $reservation['model']) . ' rental #' . $reservationId,
            'invoice_duration' => $duration,
            // The redirect only tells the page which reservation to show. Payment is confirmed by the webhook.
            'success_redirect_url' => rtrim($frontendUrl, '/') . '/users/reservations?payment=success&reservation=' . $reservationId,
            'failure_redirect_url' => rtrim($frontendUrl, '/') . '/users/reservations?payment=cancelled&reservation=' . $reservationId,
            'should_send_email' => false,
        ];
        if (filter_var($reservation['email'] ?? null, FILTER_VALIDATE_EMAIL)) {
            $payload['payer_email'] = $reservation['email'];
        }

        [$status, $result] = $this->request('POST', '/v2/invoices', $payload);
        $invoiceUrl = $result['invoice_url'] ?? null;
        if (
            $status < 200 || $status >= 300
            || !is_string($result['id'] ?? null)
            || !is_string($invoiceUrl)
            || parse_url($invoiceUrl, PHP_URL_SCHEME) !== 'https'
        ) {
            throw new RuntimeException($this->providerMessage($result, 'Xendit did not create an invoice.'));
        }

        $expiry = isset($result['expiry_date']) ? strtotime((string) $result['expiry_date']) : false;

        return [
            'id' => $externalId,
            'provider_id' => $result['id'],
            'url' => $invoiceUrl,
            'reference_number' => $externalId,
            'amount' => number_format((float) $reservation['total_amount'], 2, '.', ''),
            'currency' => 'PHP',
            'expires_at' => date('Y-m-d H:i:s', $expiry ?: time() + $duration),
        ];
    }

    /**
     * Asks Xendit to expire an unpaid invoice. Returns 'expired' only when Xendit confirms it,
     * 'paid' when the invoice was already paid (the webhook will record it), and throws otherwise.
     */
    public function expireInvoice($providerInvoiceId)
    {
        $id = rawurlencode((string) $providerInvoiceId);
        [$status, $result] = $this->request('POST', '/invoices/' . $id . '/expire!', null);
        $invoiceStatus = strtoupper((string) ($result['status'] ?? ''));

        if ($status >= 200 && $status < 300 && $invoiceStatus === 'EXPIRED') {
            return 'expired';
        }
        if (in_array($invoiceStatus, ['PAID', 'SETTLED'], true)) {
            return 'paid';
        }
        throw new RuntimeException($this->providerMessage($result, 'Xendit did not confirm the invoice expiry.'));
    }

    private function request($method, $path, $payload)
    {
        $curl = curl_init(self::API_URL . $path);
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $this->secretKey . ':',
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        ];
        if ($payload !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($payload);
        }
        curl_setopt_array($curl, $options);
        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($response === false) {
            throw new RuntimeException('Xendit request failed: ' . $error);
        }
        $result = json_decode($response, true);

        return [$status, is_array($result) ? $result : []];
    }

    private function providerMessage(array $result, $fallback)
    {
        $message = $result['message'] ?? $result['error_code'] ?? null;
        return is_string($message) ? $message : $fallback;
    }
}
