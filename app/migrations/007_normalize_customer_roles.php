<?php

class Normalize_customer_roles
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->database();
    }

    public function up()
    {
        $this->_lava->db->raw(
            "ALTER TABLE users MODIFY role ENUM('admin','moderator','user','customer') NOT NULL DEFAULT 'customer'"
        );
        $this->_lava->db->raw(
            "UPDATE users SET role = 'customer' WHERE role IN ('moderator', 'user')"
        );
        $this->_lava->db->raw(
            "ALTER TABLE users MODIFY role ENUM('admin','customer') NOT NULL DEFAULT 'customer'"
        );
    }

    public function down()
    {
        // Intentionally non-destructive: customer roles cannot be safely distinguished after migration.
    }
}
