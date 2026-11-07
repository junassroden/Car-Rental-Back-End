<?php

/**
 * Removes the mechanic role added by migration 013: administrators conduct
 * assessments, and a mechanic is recorded by name on maintenance outcomes.
 * Stops rather than silently changing the role of any existing mechanic account.
 */
class Remove_mechanic_role
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->database();
    }

    public function up()
    {
        $mechanics = $this->_lava->db->raw("SELECT COUNT(*) FROM users WHERE role = 'mechanic'")->fetchColumn();
        if ((int) $mechanics > 0) {
            throw new RuntimeException('Mechanic accounts still exist. Decide what to do with them before running this migration.');
        }

        $this->_lava->db->raw("ALTER TABLE users MODIFY role ENUM('admin','customer') NOT NULL DEFAULT 'customer'");
    }

    public function down()
    {
        // Intentionally empty: nothing depends on the removed role.
    }
}
