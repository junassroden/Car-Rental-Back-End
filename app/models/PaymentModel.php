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

    public function resubmit_manual($id, $reference, $amount)
    {
        return (bool) $this->db->table($this->table)
            ->where('id', (int) $id)
            ->update([
                'reference_number' => $reference,
                'amount' => $amount,
                'status' => 'pending',
                'paid_at' => null,
                'webhook_event_hash' => null,
            ]);
    }

    public function mark_manual_paid($reservation_id, $admin_id)
    {
        return (bool) $this->db->table($this->table)
            ->where('reservation_id', (int) $reservation_id)
            ->where('status', 'pending')
            ->update([
                'status' => 'paid',
                'webhook_event_hash' => hash('sha256', 'manual:' . (int) $reservation_id . ':' . (int) $admin_id . ':' . microtime(true)),
                'paid_at' => date('Y-m-d H:i:s'),
            ]);
    }

    public function mark_manual_rejected($reservation_id)
    {
        return (bool) $this->db->table($this->table)
            ->where('reservation_id', (int) $reservation_id)
            ->where('status', 'pending')
            ->update(['status' => 'rejected']);
    }

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

            $this->db->table($this->table)
                ->where('id', (int) $payment['id'])
                ->update([
                    'status' => 'paid',
                    'webhook_event_hash' => $event_hash,
                    'paid_at' => date('Y-m-d H:i:s'),
                ]);
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
