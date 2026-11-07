<?php

// Prints evaluate_training_records results for record sets in a JSON file.
// ai_api/test_category_models.py compares them with the Python evaluator.
// Usage: php tests/assessment_training_dump.php cases.json

require_once __DIR__ . '/../app/libraries/ComponentMaintenanceRules.php';

$results = [];
foreach (json_decode((string) file_get_contents($argv[1]), true) as $records) {
    $result = ComponentMaintenanceRules::evaluate_training_records($records);
    $results[] = [
        'eligible' => array_column($result['eligible'], 'outcome_id'),
        'excluded' => $result['excluded'],
        'summary'  => $result['summary'],
    ];
}
echo json_encode($results, JSON_UNESCAPED_UNICODE);
