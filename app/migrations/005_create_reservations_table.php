<?php

class Create_reservations_table
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->database();
    }

    public function up()
    {
        $this->_lava->db->raw("
            CREATE TABLE IF NOT EXISTS reservations (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                car_id INT UNSIGNED NOT NULL,
                start_date DATE NOT NULL,
                end_date DATE NOT NULL,
                rental_days SMALLINT UNSIGNED NOT NULL,
                daily_rate DECIMAL(10,2) NOT NULL,
                total_amount DECIMAL(10,2) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY reservations_user_created_idx (user_id, created_at),
                KEY reservations_car_period_status_idx (car_id, start_date, end_date, status),
                CONSTRAINT reservations_user_fk
                    FOREIGN KEY (user_id) REFERENCES users(id)
                    ON DELETE RESTRICT ON UPDATE CASCADE,
                CONSTRAINT reservations_car_fk
                    FOREIGN KEY (car_id) REFERENCES cars(id)
                    ON DELETE RESTRICT ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down()
    {
        // Intentionally non-destructive so rollback cannot erase booking records.
    }
}
