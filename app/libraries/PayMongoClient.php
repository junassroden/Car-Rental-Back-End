<?php

class PayMongoClient
{
    const CHECKOUT_URL = 'https://api.paymongo.com/v2/checkout_sessions';

    private $secretKey;

    public function __construct($secretKey)
    {
        $this->secretKey = trim((string) $secretKey);
        if ($this->secretKey === '' || !function_exists('curl_init')) {
            throw new RuntimeException('PayMongo is not configured. Set PAYMONGO_SECRET_KEY and enable PHP cURL.');
        }
    }

    public function createCheckoutSession(array $reservation, $frontendUrl)
    {
        $reservationId = (int) $reservation['id'];
        $reference = 'DR-RES-' . $reservationId;
        $amount = (int) round((float) $reservation['total_amount'] * 100);
        $attributes = [
            'line_items' => [[
                'name' => trim($reservation['make'] . ' ' . $reservation['model'] . ' rental'),
                'amount' => $amount,
                'currency' => 'PHP',
                'quantity' => 1,
            ]],
            'payment_method_types' => ['gcash'],
            'success_url' => rtrim($frontendUrl, '/') . '/users/reservations?payment=success',
            'cancel_url' => rtrim($frontendUrl, '/') . '/users/reservations?payment=cancelled',
            'reference_number' => $reference,
            'send_email_receipt' => true,
            'metadata' => [
                'reservation_id' => (string) $reservationId,
            ],
        ];

        $curl = curl_init(self::CHECKOUT_URL);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $this->secretKey . ':',
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['data' => ['attributes' => $attributes]]),
        ]);
        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($response === false) {
            throw new RuntimeException('PayMongo checkout request failed: ' . $error);
        }

        $result = json_decode($response, true);
        $session = $result['data'] ?? null;
        $checkoutUrl = $session['attributes']['checkout_url'] ?? null;
        $checkoutHost = is_string($checkoutUrl) ? parse_url($checkoutUrl, PHP_URL_HOST) : null;
        if (
            $status < 200 || $status >= 300
            || !is_string($session['id'] ?? null)
            || !is_string($checkoutUrl)
            || parse_url($checkoutUrl, PHP_URL_SCHEME) !== 'https'
            || !in_array($checkoutHost, ['checkout.paymongo.com'], true)
        ) {
            $providerMessage = $result['errors'][0]['detail'] ?? null;
            throw new RuntimeException(is_string($providerMessage) ? $providerMessage : 'PayMongo did not create a checkout session.');
        }

        return [
            'id' => $session['id'],
            'url' => $checkoutUrl,
            'reference_number' => $reference,
            'amount' => number_format((float) $reservation['total_amount'], 2, '.', ''),
        ];
    }
}
