<?php

/**
 * Adds tblvehicle_maintenance.serviceItems: the service items (oil filter, brake
 * fluid, ...) a completed visit actually included, as a JSON array of the keys in
 * MaintenanceModel::SERVICE_ITEMS. These real fleet visits are the training and
 * test data for the AI service-need model. Additive: existing rows stay NULL
 * ("items not recorded"), which is never read as "nothing was serviced".
 */
class Add_maintenance_service_items
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->database();
    }

    public function up()
    {
        $database = getenv('DB_NAME') ?: 'CaRen';
        $exists = $this->_lava->db->raw(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$database, 'tblvehicle_maintenance', 'serviceItems']
        )->fetchColumn();

        if (!(int) $exists) {
            $this->_lava->db->raw('ALTER TABLE tblvehicle_maintenance ADD COLUMN serviceItems JSON NULL AFTER description');
        }
    }

    public function down()
    {
        // Intentionally non-destructive: recorded service items are training data.
    }
}
