<?php

/**
 * Component-level maintenance tables for the 3D Predictive Maintenance page.
 * Executes database/component_maintenance.sql so the migration and the
 * phpMyAdmin route stay identical. Additive and idempotent.
 */
class Create_component_maintenance
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->database();
    }

    public function up()
    {
        $sql = file_get_contents(ROOT_DIR . 'database/component_maintenance.sql');
        $sql = preg_replace('/^\s*--.*$/m', '', (string) $sql);

        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            $this->_lava->db->raw($statement);
        }
    }

    public function down()
    {
        // Keep inspection history; this migration is intentionally non-destructive.
    }
}
