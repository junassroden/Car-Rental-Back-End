<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class CarImageModel extends Model
{
    protected $table       = 'car_images';
    protected $primary_key = 'id';
    protected $fillable    = ['car_id', 'image_path'];
    protected $timestamps  = false;

    /** Inserts one row per image path in a single transaction and returns the new rows. */
    public function create_images($car_id, array $image_paths)
    {
        $this->db->transaction();

        try {
            $images = [];
            foreach ($image_paths as $image_path) {
                $id       = $this->insert(['car_id' => (int) $car_id, 'image_path' => $image_path]);
                $images[] = $this->find($id);
            }
            $this->db->commit();

            return $images;
        } catch (Exception $exception) {
            $this->db->roll_back();
            throw $exception;
        }
    }

    /**
     * Deletes an image that belongs to the given car.
     * Returns the deleted row (so the file can be removed) or NULL when not found.
     */
    public function delete_image($image_id, $car_id)
    {
        $image = $this->query()
            ->where('id', $image_id)
            ->where('car_id', $car_id)
            ->row_array();

        if (!$image) {
            return null;
        }

        $this->delete($image_id);

        return $image;
    }
}
