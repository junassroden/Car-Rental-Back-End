<?php

/**
 * ChatDataSource backed by the application's models (MySQL). Read-only: it only calls
 * select methods; reservations, payments and maintenance changes stay in their own
 * controllers.
 *
 * Loaded with LavaLust's loader after ChatRetrieval, AiServiceClient and
 * ComponentMaintenanceRules (see ChatController):
 *   $this->call->library('ChatDatabaseSource', ['cars' => ..., 'reservations' => ..., 'maintenance' => ..., 'components' => ...]);
 */
class ChatDatabaseSource implements ChatDataSource
{
    private $cars;
    private $reservations;
    private $maintenance;
    private $components;
    private $assessments;
    private $vehicleRows = [];

    /** @param array $models ['cars' => CarModel, 'reservations' => ReservationModel, 'maintenance' => MaintenanceModel, 'components' => ComponentMaintenanceModel, 'assessments' => VehicleAssessmentModel] */
    public function __construct(array $models)
    {
        $this->cars         = $models['cars'];
        $this->reservations = $models['reservations'];
        $this->maintenance  = $models['maintenance'];
        $this->components   = $models['components'];
        $this->assessments  = $models['assessments'];
    }

    public function vehicles($is_admin)
    {
        $key = $is_admin ? 'admin' : 'customer';
        if (!isset($this->vehicleRows[$key])) {
            $this->vehicleRows[$key] = $this->cars->chat_vehicles($is_admin);
        }
        return $this->vehicleRows[$key];
    }

    public function booked_vehicle_ids($start_date, $end_date)
    {
        return $this->reservations->booked_car_ids($start_date, $end_date);
    }

    public function reservations_for_user($user_id, $limit)
    {
        return $this->reservations->chat_context_for_user((int) $user_id, $limit);
    }

    public function reservations_all($limit)
    {
        return $this->reservations->chat_context_all($limit);
    }

    public function vehicle_status_counts()
    {
        return $this->cars->chat_status_counts();
    }

    public function maintenance_records($vehicle_id, $limit)
    {
        return $this->maintenance->chat_context($vehicle_id, $limit);
    }

    public function maintenance_due()
    {
        return $this->maintenance->chat_due_overview();
    }

    public function component_inspections($vehicle_id, $limit)
    {
        return $this->components->chat_inspections($vehicle_id, $limit);
    }

    public function maintenance_cost_records()
    {
        return $this->maintenance->records();
    }

    public function reassessment_overview()
    {
        $today    = new DateTimeImmutable('today');
        $interval = (int) ComponentMaintenanceRules::assessment_catalog()['reassessment_interval_days'];
        return array_map(static function ($row) use ($today, $interval) {
            return $row + ['reassessment' => ComponentMaintenanceRules::reassessment_status($row['assessed_on'], $today, $interval)];
        }, $this->assessments->fleet_overview());
    }

    public function assessment_condition()
    {
        return $this->assessments->chat_condition();
    }

    public function model_feature_vehicles()
    {
        return array_map(static function ($vehicle) {
            return AiServiceClient::model_vehicle($vehicle, ComponentMaintenanceRules::body_type($vehicle['category']));
        }, $this->maintenance->model_feature_vehicles());
    }
}
