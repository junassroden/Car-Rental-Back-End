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
        if ($reservation['status'] === 'pending' && !ReservationModel::hold_active($reservation)) {
            $this->response->send_json_error('The time to pay for this reservation has run out and its dates were released. Please make a new reservation.', 409);
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

        // An open invoice is reused, so a second click does not start a second payment.
        $open = $this->PaymentModel->open_attempt((int) $reservation['id']);
        if ($open && filter_var($open['checkout_url'], FILTER_VALIDATE_URL)) {
            $this->response->send_json_success([
                'checkout_url' => $open['checkout_url'],
                'checkout_session_id' => $open['checkout_session_id'],
                'expires_at' => $open['expires_at'],
            ], 'Xendit checkout is ready.');
            return;
        }

        // The invoice ends with the reservation hold; a confirmed but unpaid booking already
        // holds its dates, so its invoice gets the provider's standard 24 hours.
        $duration = $reservation['status'] === 'pending'
            ? strtotime($reservation['expires_at']) - time()
            : 86400;

        try {
            $client = $this->call->library('XenditClient', getenv('XENDIT_SECRET_KEY') ?: '');
            $checkout = $client->createInvoice(
                $reservation,
                getenv('FRONTEND_URL') ?: 'http://127.0.0.1:5173',
                $duration
            );
            // Each invoice is a new attempt. Older attempts keep their IDs, so a payment made
            // on any of them is still matched by the webhook.
            $created = $this->PaymentModel->create_attempt([
                'reservation_id' => (int) $reservation['id'],
                'provider' => 'xendit',
                'checkout_session_id' => $checkout['id'],
                'provider_invoice_id' => $checkout['provider_id'],
                'checkout_url' => $checkout['url'],
                'reference_number' => $checkout['reference_number'],
                'amount' => $checkout['amount'],
                'currency' => $checkout['currency'],
                'expires_at' => $checkout['expires_at'],
            ]);
            if (!$created) {
                throw new RuntimeException('The checkout attempt could not be stored.');
            }
        } catch (Throwable $exception) {
            error_log('Xendit checkout creation failed for reservation ' . (int) $reservation['id'] . ': ' . $exception->getMessage());
            $this->response->send_json_error('Xendit checkout could not be started. Please try again later.', 503);
            return;
        }

        $this->response->send_json_success([
            'checkout_url' => $checkout['url'],
            'checkout_session_id' => $checkout['id'],
            'expires_at' => $checkout['expires_at'],
        ], 'Xendit checkout is ready.', 201);
    }

    /**
     * Xendit invoice callback. Authenticated by the X-Callback-Token shared secret.
     * Idempotent: an identical redelivery is acknowledged without processing, and a second
     * PAID/SETTLED event for an attempt already paid changes nothing. Events may arrive late
     * or out of order: an EXPIRED never undoes a payment, and a PAID after EXPIRED is recorded.
     */
    public function xenditWebhook()
    {
        $token = trim((string) getenv('XENDIT_CALLBACK_TOKEN'));
        $received = (string) $this->request->header('X-Callback-Token');
        if ($token === '' || !hash_equals($token, $received)) {
            $this->response->send_json_error('Invalid Xendit callback token.', 401);
            return;
        }

        $rawBody = $this->request->raw_body();
        $eventHash = hash('sha256', $rawBody);
        if ($this->PaymentModel->event_seen('xendit', $eventHash)) {
            $this->response->send_json_success(null, 'Xendit event already processed.');
            return;
        }

        $event = json_decode($rawBody, true);
        $status = is_array($event) ? strtoupper((string) ($event['status'] ?? '')) : '';
        $externalId = is_array($event) ? ($event['external_id'] ?? null) : null;
        if (!in_array($status, ['PAID', 'SETTLED', 'EXPIRED'], true) || !is_string($externalId)) {
            $this->response->send_json_error('Unsupported Xendit webhook event.', 422);
            return;
        }

        $payment = $this->PaymentModel->find_by_session($externalId);
        if (!$payment) {
            $this->response->send_json_error('The Xendit invoice does not match a reservation.', 404);
            return;
        }
        // When the provider's own invoice ID was stored, the event must name the same invoice.
        if (
            !empty($payment['provider_invoice_id']) && isset($event['id'])
            && !hash_equals((string) $payment['provider_invoice_id'], (string) $event['id'])
        ) {
            $this->response->send_json_error('The Xendit invoice ID does not match the payment attempt.', 422);
            return;
        }

        if ($status === 'EXPIRED') {
            $this->PaymentModel->mark_expired($externalId);
            // The invoice ends with the reservation hold, so release any holds that have run out.
            $this->ReservationModel->expire_stale(50);
            $this->PaymentModel->record_event('xendit', $eventHash, $externalId, $status, (int) $payment['id'], 'expired');
            $this->response->send_json_success(null, 'Xendit invoice expiry recorded.');
            return;
        }

        // PAID and SETTLED both mean the money was received. Currency and amount must be stated and match.
        $currency = strtoupper((string) ($event['currency'] ?? ''));
        $paidAmount = $event['paid_amount'] ?? null;
        if (
            $currency !== strtoupper((string) ($payment['currency'] ?? 'PHP'))
            || !is_numeric($paidAmount)
            || abs((float) $paidAmount - (float) $payment['amount']) > 0.01
        ) {
            error_log('Xendit payment for attempt ' . (int) $payment['id'] . ' failed verification: currency ' . $currency . ', amount ' . (is_scalar($paidAmount) ? $paidAmount : 'missing'));
            $this->response->send_json_error('The Xendit payment amount could not be verified.', 422);
            return;
        }

        try {
            $outcome = $this->PaymentModel->mark_paid($externalId, (float) $paidAmount, $eventHash, $this->ReservationModel);
            if ($outcome === false) {
                $this->response->send_json_error('The Xendit payment could not be recorded.', 500);
                return;
            }
        } catch (Throwable $exception) {
            error_log('Xendit webhook processing failed for attempt ' . (int) $payment['id'] . ': ' . $exception->getMessage());
            $this->response->send_json_error('The Xendit payment could not be recorded.', 500);
            return;
        }

        $this->PaymentModel->record_event('xendit', $eventHash, $externalId, $status, (int) $payment['id'], $outcome);
        if ($outcome === 'review') {
            // Administrators see it in the payment review queue (GET /api/payments/review).
            error_log('ADMIN REVIEW: payment attempt ' . (int) $payment['id'] . ' for reservation ' . (int) $payment['reservation_id'] . ' needs review.');
        }

        $this->response->send_json_success(null, 'Xendit payment received.');
    }

    /** Paid attempts that need an administrator: payments after cancellation or expiry, duplicates. */
    public function reviewQueue()
    {
        $user = $this->requireUser();
        if ($user['role'] !== 'admin') {
            $this->response->send_json_error('Administrator access is required.', 403);
            return;
        }
        $this->response->send_json_success($this->PaymentModel->needing_review(), 'Payments needing review retrieved.');
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
