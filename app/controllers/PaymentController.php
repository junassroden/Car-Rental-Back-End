<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

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
        $user = $this->requireUser();

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
        // Payment confirms a pending reservation. Confirmed ones approved before automatic
        // confirmation may still be unpaid, so they can be paid too.
        if (!in_array($reservation['status'], ['pending', 'confirmed'], true)) {
            $this->response->send_json_error('Only reservations awaiting payment can be paid.', 409);
            return;
        }
        if ($reservation['status'] === 'pending' && $reservation['start_date'] < date('Y-m-d')) {
            $this->response->send_json_error('The pickup date has passed. Please make a new reservation.', 409);
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

        // An open invoice is reused, so a second click cannot start a second payment. Xendit
        // invoices last 24 hours (XenditClient), so an older pending one is replaced.
        $existing = $this->PaymentModel->latest_for_reservation((int) $reservation['id']);
        if (
            $existing && $existing['status'] === 'pending'
            && filter_var($existing['checkout_url'], FILTER_VALIDATE_URL)
            && strtotime($existing['created_at']) > time() - 23 * 3600
        ) {
            $this->response->send_json_success([
                'checkout_url' => $existing['checkout_url'],
                'checkout_session_id' => $existing['checkout_session_id'],
            ], 'Xendit checkout is ready.');
            return;
        }

        try {
            $client = $this->call->library('XenditClient', getenv('XENDIT_SECRET_KEY') ?: '');
            $checkout = $client->createInvoice(
                $reservation,
                getenv('FRONTEND_URL') ?: 'http://127.0.0.1:5173'
            );
            $attempt = [
                'checkout_session_id' => $checkout['id'],
                'checkout_url' => $checkout['url'],
                'reference_number' => $checkout['reference_number'],
                'amount' => $checkout['amount'],
            ];
            $created = $existing
                ? $this->PaymentModel->restart_checkout((int) $existing['id'], $attempt)
                : $this->PaymentModel->create_checkout($attempt + [
                    'reservation_id' => (int) $reservation['id'],
                    'status' => 'pending',
                ]);
            if (!$created) {
                throw new RuntimeException('The checkout session could not be stored.');
            }
        } catch (Throwable $exception) {
            error_log('Xendit checkout creation failed: ' . $exception->getMessage());
            $this->response->send_json_error('Xendit checkout could not be started. Please try again later.', 503);
            return;
        }

        $this->response->send_json_success([
            'checkout_url' => $checkout['url'],
            'checkout_session_id' => $checkout['id'],
        ], 'Xendit checkout is ready.', 201);
    }

    public function webhook()
    {
        $secret = trim((string) getenv('PAYMONGO_WEBHOOK_SECRET'));
        $rawBody = $this->request->raw_body();
        if ($secret === '' || $rawBody === '') {
            $this->response->send_json_error('PayMongo webhook is not configured.', 503);
            return;
        }

        $signature = $this->request->header('Paymongo-Signature') ?? $this->request->header('X-Paymongo-Signature') ?? '';
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

    public function xenditWebhook()
    {
        $token = trim((string) getenv('XENDIT_CALLBACK_TOKEN'));
        $received = (string) $this->request->header('X-Callback-Token');
        if ($token === '' || !hash_equals($token, $received)) {
            $this->response->send_json_error('Invalid Xendit callback token.', 401);
            return;
        }

        $rawBody = $this->request->raw_body();
        $event = json_decode($rawBody, true);
        $status = is_array($event) ? strtoupper((string) ($event['status'] ?? '')) : '';
        if (!in_array($status, ['PAID', 'SETTLED', 'EXPIRED'], true) || !is_string($event['external_id'] ?? null)) {
            $this->response->send_json_error('Unsupported Xendit webhook event.', 422);
            return;
        }

        $payment = $this->PaymentModel->find_by_session($event['external_id']);
        if (!$payment) {
            $this->response->send_json_error('The Xendit invoice does not match a reservation.', 404);
            return;
        }

        // An expired invoice was never paid. The reservation stays pending so the customer can retry.
        if ($status === 'EXPIRED') {
            $this->PaymentModel->mark_expired($event['external_id']);
            $this->response->send_json_success(null, 'Xendit invoice expiry recorded.');
            return;
        }

        // PAID and SETTLED both mean the money was received. The amount must be stated and match.
        $currency = strtoupper((string) ($event['currency'] ?? 'PHP'));
        if (
            $currency !== 'PHP'
            || !is_numeric($event['paid_amount'] ?? null)
            || abs((float) $event['paid_amount'] - (float) $payment['amount']) > 0.01
        ) {
            $this->response->send_json_error('The Xendit payment amount could not be verified.', 422);
            return;
        }

        try {
            if (!$this->PaymentModel->mark_paid($event['external_id'], hash('sha256', $rawBody))) {
                $this->response->send_json_error('The Xendit payment could not be recorded.', 500);
                return;
            }
        } catch (Throwable $exception) {
            error_log('Xendit webhook processing failed: ' . $exception->getMessage());
            $this->response->send_json_error('The Xendit payment could not be recorded.', 500);
            return;
        }

        $this->response->send_json_success(null, 'Xendit payment received.');
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

    /** Returns the signed-in user, or sends a 401. */
    private function requireUser()
    {
        $user = $this->UserModel->find_active($this->authenticated_user_id());
        if (!$user) {
            $this->response->send_json_error('Authentication is required.', 401);
            exit;
        }

        return $user;
    }
}
