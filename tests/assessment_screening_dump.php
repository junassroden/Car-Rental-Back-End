<?php

// Prints screen_component results for cases in a JSON file. ai_api/test_assessment.py
// compares them with the Python rules, so the two evaluators cannot drift apart.
// Usage: php tests/assessment_screening_dump.php cases.json

require_once __DIR__ . '/../app/libraries/ComponentMaintenanceRules.php';

$cases   = json_decode((string) file_get_contents($argv[1]), true);
$results = [];
foreach ($cases as $case) {
    $results[] = ComponentMaintenanceRules::screen_component($case['key'], $case['component'], $case['previous'] ?? null, $case['assessed_on'] ?? null);
}
echo json_encode($results, JSON_UNESCAPED_UNICODE);
