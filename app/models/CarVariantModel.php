<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class CarVariantModel extends Model
{
    protected $table       = 'car_color_variants';
    protected $primary_key = 'id';
    protected $fillable    = ['car_id', 'color', 'stock'];
    protected $timestamps  = false;

    const MAX_STOCK = 9999;

    /** One colour of the given car, or NULL when it belongs to a different car. */
    public function find_for_car($variant_id, $car_id)
    {
        return $this->query()
            ->where('id', (int) $variant_id)
            ->where('car_id', (int) $car_id)
            ->limit(1)
            ->row_array() ?: null;
    }

    /** TRUE when the car already has this colour. Comparison ignores letter case. */
    public function color_exists($car_id, $color, $ignore_id = 0)
    {
        $query = $this->query()
            ->where('car_id', (int) $car_id)
            ->where('color', $color);
        if ($ignore_id) {
            $query->where('id', '<>', (int) $ignore_id);
        }

        return (bool) $query->limit(1)->row_array();
    }

    /** TRUE when a reservation has booked this colour, so it must not be deleted. */
    public function has_reservations($variant_id)
    {
        return (bool) $this->db->table('reservations')
            ->where('variant_id', (int) $variant_id)
            ->limit(1)
            ->row_array();
    }
}
