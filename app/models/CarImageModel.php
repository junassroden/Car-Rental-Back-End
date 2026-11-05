<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class CarImageModel extends Model
{
    protected $table       = 'car_images';
    protected $primary_key = 'id';
    protected $fillable    = ['car_id', 'image_path', 'variant_id'];
    protected $timestamps  = false;

    /** Inserts one row per image path in a single transaction and returns the new rows. */
    public function create_images($car_id, array $image_paths, $variant_id = null)
    {
        $this->db->transaction();

        try {
            $images = [];
            foreach ($image_paths as $image_path) {
                $id       = $this->insert(['car_id' => (int) $car_id, 'image_path' => $image_path, 'variant_id' => $variant_id]);
                $images[] = $this->find($id);
            }
            $this->db->commit();

            return $images;
        } catch (Exception $exception) {
            $this->db->roll_back();
            throw $exception;
        }
    }

    /** One image of the given car, or NULL when it belongs to a different car. */
    public function find_for_car($image_id, $car_id)
    {
        return $this->query()
            ->where('id', (int) $image_id)
            ->where('car_id', (int) $car_id)
            ->limit(1)
            ->row_array() ?: null;
    }

    /**
     * Deletes an image that belongs to the given car.
     * Returns the deleted row (so the file can be removed) or NULL when not found.
     */
    public function delete_image($image_id, $car_id)
    {
        $image = $this->find_for_car($image_id, $car_id);

        if (!$image) {
            return null;
        }

        $this->delete($image_id);

        return $image;
    }
}
