<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once ROOT_DIR . 'app' . DIRECTORY_SEPARATOR . 'libraries' . DIRECTORY_SEPARATOR . 'PayMongoClient.php';

class PaymentController extends Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->security_headers->apply_api();
        $this->call->model(['PaymentModel', 'ReservationModel']);
    }

    public function createCheckout($id)
    {
        $user = $this->authenticatedUser();
        if (!$user) {
            return;
        }
        if (!filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) {
            $this->response->send_json_error('Reservation not found.', 404);
            return;
        }

        $reservation = $this->ReservationModel->find_with_details((int) $id);
        if (
            !$reservation
            || ($user['role'] !== 'admin' && (int) $reservation['user_id'] !== (int) $user['id'])
        ) {
            $this->response->send_json_error('Reservation not found.', 404);
            return;
        }
        if ($reservation['status'] !== 'confirmed') {
            $this->response->send_json_error('The reservation must be approved before payment.', 409);
            return;
        }
        if ($reservation['end_date'] <= date('Y-m-d')) {
            $this->response->send_json_error('The reservation period has ended. Contact an administrator for help.', 409);
            return;
        }
        if ((int) $reservation['payment_required'] !== 1) {
            $this->response->send_json_error('No payment is due for this reservation.', 409);
            return;
        }
        if ($reservation['payment_status'] === 'paid') {
            $this->response->send_json_error('This reservation has already been paid.', 409);
            return;
        }
        if (!empty($reservation['checked_out_at'])) {
            $this->response->send_json_error('Payment cannot be started after vehicle handover.', 409);
            return;
        }

        $existing = $this->PaymentModel->latest_for_reservation((int) $reservation['id']);
        if ($existing && $existing['status'] === 'pending') {
            $this->response->send_json_success([
                'checkout_url' => $existing['checkout_url'],
                'checkout_session_id' => $existing['checkout_session_id'],
            ], 'GCash checkout is ready.');
            return;
        }

        try {
            $client = new PayMongoClient(getenv('PAYMONGO_SECRET_KEY') ?: '');
            $checkout = $client->createCheckoutSession(
                $reservation,
                getenv('FRONTEND_URL') ?: 'http://127.0.0.1:5173'
            );
            $created = $this->PaymentModel->create_checkout([
                'reservation_id' => (int) $reservation['id'],
                'checkout_session_id' => $checkout['id'],
                'checkout_url' => $checkout['url'],
                'reference_number' => $checkout['reference_number'],
                'amount' => $checkout['amount'],
                'status' => 'pending',
            ]);
            if (!$created) {
                throw new RuntimeException('The checkout session could not be stored.');
            }
        } catch (Throwable $exception) {
            error_log('PayMongo checkout creation failed: ' . $exception->getMessage());
            $this->response->send_json_error('GCash checkout could not be started. Please try again later.', 503);
            return;
        }

        $this->response->send_json_success([
            'checkout_url' => $checkout['url'],
            'checkout_session_id' => $checkout['id'],
        ], 'GCash checkout is ready.', 201);
    }

    public function webhook()
    {
        $secret = trim((string) getenv('PAYMONGO_WEBHOOK_SECRET'));
        $rawBody = $this->request->raw_body();
        if ($secret === '' || $rawBody === '') {
            $this->response->send_json_error('PayMongo webhook is not configured.', 503);
            return;
        }
        $signature = $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? $_SERVER['HTTP_X_PAYMONGO_SIGNATURE'] ?? '';
        if (!$this->validSignature($rawBody, $signature, $secret)) {
            $this->response->send_json_error('Invalid PayMongo webhook signature.', 401);
            return;
        }

        $event = json_decode($rawBody, true);
        $session = $event['data']['data'] ?? null;
        if (
            !is_array($event)
            || ($event['data']['type'] ?? '') !== 'checkout_session.payment.paid'
            || !is_array($session)
            || !is_string($session['id'] ?? null)
        ) {
            $this->response->send_json_error('Unsupported PayMongo webhook event.', 422);
            return;
        }

        $payment = $this->PaymentModel->find_by_session($session['id']);
        if (!$payment || ($session['attributes']['reference_number'] ?? '') !== $payment['reference_number']) {
            $this->response->send_json_error('The PayMongo checkout session does not match a reservation.', 404);
            return;
        }

        $paidAmount = null;
        foreach (($session['attributes']['payments'] ?? []) as $attempt) {
            $attributes = $attempt['attributes'] ?? [];
            if (
                ($attributes['status'] ?? '') === 'paid'
                && strtoupper((string) ($attributes['currency'] ?? '')) === 'PHP'
            ) {
                $paidAmount = $attributes['net_amount'] ?? null;
                break;
            }
        }
        if ((int) $paidAmount !== (int) round((float) $payment['amount'] * 100)) {
            $this->response->send_json_error('The PayMongo payment amount could not be verified.', 422);
            return;
        }

        try {
            if (!$this->PaymentModel->mark_paid($session['id'], hash('sha256', $rawBody))) {
                $this->response->send_json_error('The payment could not be recorded.', 500);
                return;
            }
        } catch (Throwable $exception) {
            error_log('PayMongo webhook processing failed: ' . $exception->getMessage());
            $this->response->send_json_error('The payment could not be recorded.', 500);
            return;
        }

        $this->response->send_json_success(null, 'Payment received.');
    }

    private function authenticatedUser()
    {
        $user = $this->UserModel->find((int) $this->authenticated_user_id());
        if (!$user || (int) $user['is_active'] !== 1) {
            $this->response->send_json_error('Authentication is required.', 401);
            return null;
        }
        return $user;
    }

    private function validSignature($rawBody, $header, $secret)
    {
        $parts = [];
        foreach (preg_split('/[,;]/', (string) $header) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) === 2) {
                $parts[$pair[0]] = $pair[1];
            }
        }

        $timestamp = $parts['t'] ?? '';
        $key = str_starts_with((string) getenv('PAYMONGO_SECRET_KEY'), 'sk_live_') ? 'li' : 'te';
        $signature = $parts[$key] ?? '';
        if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300 || $signature === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
        return hash_equals($expected, $signature);
    }
}
