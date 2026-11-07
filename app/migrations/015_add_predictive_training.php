<?php

/**
 * Supports category-specific predictive models. Additive: no existing value changes,
 * and outcome_date is only filled from dates already recorded on the same outcome.
 */
class Add_predictive_training
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->database();
    }

    public function up()
    {
        $db = $this->_lava->db;

        $this->addColumn('assessment_outcomes', 'outcome_date', 'DATE NULL AFTER repair_date');
        $db->raw('UPDATE assessment_outcomes SET outcome_date = COALESCE(failure_date, repair_date) WHERE outcome_date IS NULL');

        $this->addColumn('assessment_ai_findings', 'risk_level', 'VARCHAR(20) NULL AFTER prediction_horizon');
        $this->addColumn('assessment_ai_findings', 'model_score', 'DECIMAL(6,5) NULL AFTER risk_level');
        $this->addColumn('assessment_ai_findings', 'prediction', 'JSON NULL AFTER model_score');

        $db->raw("
            CREATE TABLE IF NOT EXISTS model_training_runs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                category_key VARCHAR(40) NOT NULL,
                status ENUM('running','completed','failed') NOT NULL DEFAULT 'running',
                dataset_rows INT UNSIGNED NOT NULL DEFAULT 0,
                dataset_vehicles INT UNSIGNED NOT NULL DEFAULT 0,
                model_version VARCHAR(160) NULL,
                evaluation_status VARCHAR(40) NULL,
                error_message VARCHAR(255) NULL,
                started_by INT UNSIGNED NULL,
                started_at DATETIME NOT NULL,
                completed_at DATETIME NULL,
                PRIMARY KEY (id),
                KEY model_training_run_lookup_idx (category_key, started_at),
                CONSTRAINT model_training_run_user_fk FOREIGN KEY (started_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down()
    {
        // Intentionally non-destructive: outcome dates and training history are kept.
    }

    private function addColumn($table, $column, $definition)
    {
        $exists = $this->_lava->db->raw(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [getenv('DB_NAME') ?: 'CaRen', $table, $column]
        )->fetchColumn();

        if (!(int) $exists) {
            $this->_lava->db->raw("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        }
    }
}
