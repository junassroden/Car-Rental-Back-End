<?php

class XenditClient
{
    const INVOICE_URL = 'https://api.xendit.co/v2/invoices';

    private $secretKey;

    public function __construct($secretKey)
    {
        $this->secretKey = trim((string) $secretKey);
        if ($this->secretKey === '' || !function_exists('curl_init')) {
            throw new RuntimeException('Xendit is not configured or PHP cURL is unavailable.');
        }
    }

    public function createInvoice(array $reservation, $frontendUrl)
    {
        $reservationId = (int) $reservation['id'];
        $externalId = 'caren-reservation-' . $reservationId . '-' . bin2hex(random_bytes(6));
        $payload = [
            'external_id' => $externalId,
            'amount' => round((float) $reservation['total_amount'], 2),
            'currency' => 'PHP',
            'description' => trim($reservation['make'] . ' ' . $reservation['model']) . ' rental #' . $reservationId,
            'invoice_duration' => 86400,
            'success_redirect_url' => rtrim($frontendUrl, '/') . '/users/reservations?payment=success',
            'failure_redirect_url' => rtrim($frontendUrl, '/') . '/users/reservations?payment=cancelled',
            'should_send_email' => false,
        ];
        if (filter_var($reservation['email'] ?? null, FILTER_VALIDATE_EMAIL)) {
            $payload['payer_email'] = $reservation['email'];
        }

        $curl = curl_init(self::INVOICE_URL);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $this->secretKey . ':',
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);
        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($response === false) {
            throw new RuntimeException('Xendit invoice request failed: ' . $error);
        }

        $result = json_decode($response, true);
        $invoiceUrl = $result['invoice_url'] ?? null;
        if (
            $status < 200 || $status >= 300
            || !is_string($result['id'] ?? null)
            || !is_string($invoiceUrl)
            || parse_url($invoiceUrl, PHP_URL_SCHEME) !== 'https'
        ) {
            $providerMessage = $result['message'] ?? $result['error_code'] ?? null;
            throw new RuntimeException(is_string($providerMessage) ? $providerMessage : 'Xendit did not create an invoice.');
        }

        return [
            'id' => $externalId,
            'provider_id' => $result['id'],
            'url' => $invoiceUrl,
            'reference_number' => $externalId,
            'amount' => number_format((float) $reservation['total_amount'], 2, '.', ''),
        ];
    }
}
