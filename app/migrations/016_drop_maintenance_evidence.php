<?php

/**
 * Removes the Maintenance Evidence module's tables. They held no rows when the
 * module was removed. The audit table goes first because it references the records.
 */
class Drop_maintenance_evidence
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->database();
    }

    public function up()
    {
        $this->_lava->db->raw('DROP TABLE IF EXISTS maintenance_data_audit');
        $this->_lava->db->raw('DROP TABLE IF EXISTS maintenance_data_records');
    }

    public function down()
    {
        // The tables held no rows, so there is nothing to restore. Migration 008 has their definition.
    }
}
