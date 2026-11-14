<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Payment attempts (checkout_payments) and the webhook events that settle them.
 *
 * Every checkout is its own row: a retry adds a new attempt and never overwrites an older
 * invoice ID, so a payment made on any invoice the customer opened can always be matched.
 * checkout_session_id is the external ID this system gives the provider; provider_invoice_id
 * is the provider's own ID, used to expire an invoice through its API.
 */
class PaymentModel extends Model
{
    protected $table = 'checkout_payments';
    protected $primary_key = 'id';
    protected $fillable = [
        'reservation_id', 'provider', 'checkout_session_id', 'provider_invoice_id', 'checkout_url',
        'reference_number', 'amount', 'currency', 'status', 'expires_at', 'webhook_event_hash',
        'paid_at', 'paid_amount', 'review_status', 'review_reason',
    ];
    protected $timestamps = false;

    const EVENTS = 'payment_webhook_events';

    public function latest_for_reservation($reservation_id)
    {
        return $this->db->table($this->table)
            ->where('reservation_id', (int) $reservation_id)
            ->order_by('id', 'DESC')
            ->limit(1)
            ->row_array();
    }

    /** An unexpired, unpaid attempt that can still be paid, or NULL. */
    public function open_attempt($reservation_id)
    {
        return $this->db->table($this->table)
            ->where('reservation_id', (int) $reservation_id)
            ->where('status', 'pending')
            ->where('expires_at', '>', date('Y-m-d H:i:s', time() + 120))
            ->order_by('id', 'DESC')
            ->limit(1)
            ->row_array();
    }

    /** Every unpaid attempt of a reservation that the provider may still accept. */
    public function pending_attempts($reservation_id)
    {
        return $this->db->table($this->table)
            ->where('reservation_id', (int) $reservation_id)
            ->where('status', 'pending')
            ->get_all();
    }

    public function find_by_session($session_id)
    {
        return $this->find_by('checkout_session_id', $session_id);
    }

    /** Adds a new attempt. Earlier attempts are left exactly as they are. */
    public function create_attempt(array $attempt)
    {
        return $this->insert($attempt + ['status' => 'pending', 'currency' => 'PHP']);
    }

    /** Marks a still-pending attempt as expired. A paid attempt is never changed. */
    public function mark_expired($session_id)
    {
        $this->db->table($this->table)
            ->where('checkout_session_id', $session_id)
            ->where('status', 'pending')
            ->update(['status' => 'expired']);
        return true;
    }

    /** TRUE when the reservation still has an attempt the customer could pay. */
    public function has_pending_attempt($reservation_id)
    {
        return (bool) $this->db->table($this->table)
            ->where('reservation_id', (int) $reservation_id)
            ->where('status', 'pending')
            ->limit(1)
            ->row_array();
    }

    /**
     * Records a webhook event once. Returns FALSE when an identical event was already
     * recorded, so a redelivered webhook is acknowledged without being processed again.
     */
    public function record_event($provider, $event_hash, $external_id, $event_status, $payment_id, $outcome)
    {
        $seen = $this->db->table(self::EVENTS)
            ->select('id')
            ->where('provider', $provider)
            ->where('event_hash', $event_hash)
            ->limit(1)
            ->row_array();
        if ($seen) {
            return false;
        }
        try {
            $this->db->table(self::EVENTS)->insert([
                'provider' => $provider,
                'event_hash' => $event_hash,
                'external_id' => $external_id !== null ? substr((string) $external_id, 0, 100) : null,
                'event_status' => $event_status !== null ? substr((string) $event_status, 0, 30) : null,
                'payment_id' => $payment_id,
                'outcome' => substr((string) $outcome, 0, 40),
            ]);
        } catch (Exception $exception) {
            // A concurrent delivery of the same event won the unique key.
            return false;
        }
        return true;
    }

    public function event_seen($provider, $event_hash)
    {
        return (bool) $this->db->table(self::EVENTS)
            ->select('id')
            ->where('provider', $provider)
            ->where('event_hash', $event_hash)
            ->limit(1)
            ->row_array();
    }

    /**
     * Records a provider-verified payment on its own attempt and settles the reservation in the
     * same transaction. The money is always recorded, whichever attempt it came through and
     * whatever happened to the reservation since; anything unusual is flagged for review.
     *
     * Returns 'paid' | 'confirmed' | 'review' | 'duplicate', or FALSE when the attempt is unknown.
     */
    public function mark_paid($session_id, $paid_amount, $event_hash, ReservationModel $reservations)
    {
        if (!$this->db->transaction()) {
            return false;
        }

        try {
            $payment = $this->db->table($this->table)
                ->where('checkout_session_id', $session_id)
                ->for_update()
                ->row_array();
            if (!$payment) {
                $this->db->roll_back();
                return false;
            }
            if ($payment['status'] === 'paid') {
                $this->db->commit();
                return 'duplicate';
            }

            $otherPaid = (bool) $this->db->table($this->table)
                ->select('id')
                ->where('reservation_id', (int) $payment['reservation_id'])
                ->where('status', 'paid')
                ->where('id', '<>', (int) $payment['id'])
                ->limit(1)
                ->row_array();

            $settlement = $reservations->settle_payment((int) $payment['reservation_id'], $otherPaid);

            $this->db->table($this->table)
                ->where('id', (int) $payment['id'])
                ->update([
                    'status' => 'paid',
                    'webhook_event_hash' => $event_hash,
                    'paid_at' => date('Y-m-d H:i:s'),
                    'paid_amount' => number_format((float) $paid_amount, 2, '.', ''),
                    'review_status' => $settlement['review'] !== null ? 'needs_review' : null,
                    'review_reason' => $settlement['review'] !== null ? substr($settlement['review'], 0, 255) : null,
                ]);

            if (!$this->db->commit()) {
                return false;
            }
            return $settlement['outcome'];
        } catch (Exception $exception) {
            $this->db->roll_back();
            throw $exception;
        }
    }

    /** Paid attempts an administrator must act on (refunds, duplicates). Newest first. */
    public function needing_review()
    {
        return $this->db->table($this->table)
            ->select('checkout_payments.id, checkout_payments.reservation_id, checkout_payments.provider, checkout_payments.reference_number, checkout_payments.amount, checkout_payments.paid_amount, checkout_payments.paid_at, checkout_payments.review_reason, reservations.status AS reservation_status, users.full_name')
            ->inner_join('reservations', 'reservations.id = checkout_payments.reservation_id')
            ->inner_join('users', 'users.id = reservations.user_id')
            ->where('checkout_payments.review_status', 'needs_review')
            ->order_by('checkout_payments.paid_at', 'DESC')
            ->get_all();
    }
}
