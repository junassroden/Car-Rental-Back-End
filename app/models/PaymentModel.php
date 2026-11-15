<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class PaymentModel extends Model
{
    protected $table = 'checkout_payments';
    protected $primary_key = 'id';
    protected $fillable = [
        'reservation_id', 'checkout_session_id', 'checkout_url',
        'reference_number', 'amount', 'status', 'webhook_event_hash', 'paid_at',
    ];
    protected $timestamps = false;

    public function __construct()
    {
        parent::__construct();
        $this->call->library('ReservationPolicy');
    }

    public function latest_for_reservation($reservation_id)
    {
        return $this->db->table($this->table)
            ->where('reservation_id', (int) $reservation_id)
            ->order_by('id', 'DESC')
            ->limit(1)
            ->row_array();
    }

    public function find_by_session($session_id)
    {
        return $this->find_by('checkout_session_id', $session_id);
    }

    public function create_checkout(array $payment)
    {
        return $this->insert($payment);
    }

    /**
     * Replaces an unpaid attempt with a new checkout. A reservation keeps one payment row
     * (checkout_payments_reservation_unique), so a retry reuses it. A paid row is never touched.
     */
    public function restart_checkout($id, array $checkout)
    {
        return (bool) $this->db->table($this->table)
            ->where('id', (int) $id)
            ->where('status', '<>', 'paid')
            ->update($checkout + [
                'status' => 'pending',
                'paid_at' => null,
                'webhook_event_hash' => null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
    }

    /** Marks a still-pending checkout as expired. Paid payments are left as they are. */
    public function mark_expired($session_id)
    {
        $this->db->table($this->table)
            ->where('checkout_session_id', $session_id)
            ->where('status', 'pending')
            ->update(['status' => 'expired']);
        return true;
    }

    /**
     * Records a gateway-verified payment and confirms standard reservations in the same
     * transaction. Special-duration reservations wait for administrator approval.
     * Safe to repeat: a payment already marked paid is not processed again.
     * A pending reservation moves to the policy-selected status. A cancelled or rejected one
     * stays as it is, and the paid record shows the administrator that a refund is needed.
     * Pending reservations already hold their dates (ReservationModel::BLOCKING_STATUSES),
     * so confirming one cannot create a double booking.
     */
    public function mark_paid($session_id, $event_hash)
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
                return true;
            }

            $reservation = $this->db->table('reservations')
                ->select('id, status, special_duration_request')
                ->where('id', (int) $payment['reservation_id'])
                ->for_update()
                ->row_array();

            $this->db->table($this->table)
                ->where('id', (int) $payment['id'])
                ->update([
                    'status' => 'paid',
                    'webhook_event_hash' => $event_hash,
                    'paid_at' => date('Y-m-d H:i:s'),
                ]);
            if ($reservation && $reservation['status'] === 'pending') {
                $this->db->table('reservations')
                    ->where('id', (int) $reservation['id'])
                    ->update(['status' => ReservationPolicy::status_after_payment((bool) $reservation['special_duration_request'])]);
            }
            if (!$this->db->commit()) {
                return false;
            }
            return true;
        } catch (Exception $exception) {
            $this->db->roll_back();
            throw $exception;
        }
    }
}
