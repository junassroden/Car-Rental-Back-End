# Vehicle assessments

Powers `/admin/maintenance/predictions`.

## Database setup

Back up the database, then run migrations 013, 014 and 015:

```powershell
$env:MIGRATION_ENABLED = 'true'; php lava migration run; Remove-Item Env:MIGRATION_ENABLED
```

Or run `database/vehicle_assessments.sql`, which is equivalent to all three. Do not apply both
without checking the `migrations` table. Both are additive: existing users,
vehicles and inspections are not changed.

| Table | Purpose |
|---|---|
| `vehicle_assessments` | One submission: date, odometer, assessor, overall screening, AI status, next due date. |
| `assessment_components` | One row per category: rating, answers, symptoms, measurements, fault codes, maintenance date, notes, screening status. |
| `assessment_findings` | Rule results. Each stores the observation, reason and recommended action. |
| `assessment_ai_analyses` | One row per analysis attempt, including failed ones. |
| `assessment_ai_findings` | AI service result per category for a completed attempt. |
| `assessment_evidence` | Photos, stored in `storage/private/assessment-evidence`. |
| `assessment_outcomes` | Append-only record of what happened to a recommendation, with its original text and time. |

New columns on `cars`: `acquisition_date`, `usage_frequency`,
`previous_maintenance_date`. `current_mileage` already existed. All are NULL until
entered, and NULL means unknown; 0 is a real reading.

## Ratings

`excellent` (5), `good` (4), `fair` (3), `poor` (2), `critical` (1), and three states
with no score: `not_applicable`, `unknown`, `not_assessed`. A missing rating is stored
as `not_assessed` with a NULL score, never as 5.

`vehicle_component_inspections` keeps its original three-level ratings. They are shown
as recorded in component history and are not converted. The existing 3-level inspection
view stays available to administrators as the Component records tab.

## Questionnaire and rules

`app/libraries/assessment_catalog.json` defines categories, questions, symptoms,
measurements with valid ranges, and screening rules. Thresholds are general workshop
guidance, not manufacturer limits. **Edit both copies:** the file must stay
byte-identical to `automotive_ai_service/ai_api/assessment_catalog.json`.
`ai_api/test_assessment.py` fails if they differ, or if the PHP and Python rule
evaluators disagree on any case.

## Status meanings

These are separate and never merged:

- **Physical condition rating**: what the assessor recorded.
- **Condition-based screening**: which rules fired. `no_concerns_recorded` only means no
  rule fired; it does not mean the component is healthy.
- **AI analysis**: whether the Python service processed the assessment.
- **Validated predictive risk**: `Insufficient Data` until a validated model exists.

## Processing

Submission runs in one request, with no queue:

1. The backend validates and saves the assessment, components and findings in one
   transaction. Safety warnings exist from this point.
2. It claims the analysis (`ai_status = processing`), calls the Python service, and stores
   the result.
3. If the service fails, the assessment stays saved and `ai_status = failed`.
   `POST /api/assessments/{id}/analysis` retries it.

A row lock makes the claim atomic. A completed assessment, or one claimed in the last 120
seconds, returns 409. A claim older than that is treated as a dead request and may be
retried. Each attempt adds a row, so earlier attempts are kept. The same
`idempotency_key` returns the existing assessment instead of creating another.

## Monthly reassessment

There is no scheduler. Status (`never_assessed`, `current`, `due_soon`, `overdue`) is
worked out from the latest assessment when a page loads, using
`reassessment_interval_days` in the catalog. Nothing notifies anyone in the background.

## Mileage

A reading lower than the highest earlier reading is saved and flagged
(`odometer_flag = decrease`), and it does not change `cars.current_mileage`.

## Roles

Only active administrators can use assessments; everyone else gets 403. There are no
mechanic accounts. The mechanic who did the work is recorded by name on an outcome, and
whoever is signed in is recorded as the assessor.

## Predictive models

Each category has its own model, dataset, evaluation and status. LavaLust exports verified
records; `automotive_ai_service/ai_api/category_models.py` re-checks them, trains and
evaluates. `training_policy` in the catalog holds every threshold, set before training.

**What a model predicts:** whether a verified fault follows an assessment within
`horizon_days`. `confirmed_failure` is a fault, and so is `successful_repair` when a
mechanic confirmed it. `no_fault_found` is healthy. An unconfirmed repair is left out
because it does not establish that anything had failed.

**A category qualifies** with at least 30 eligible outcomes, 5 vehicles, no vehicle above
50% of the records, 10 faults and 10 healthy results. Excluded, each with a stated reason:
unverified records, demo vehicles (`DEMO-` plates), a missing odometer or outcome date, an
odometer decrease, an unrated category, outcomes dated on or before the assessment, outcomes
beyond the horizon, invalid mileage, duplicates and conflicting results.

**Evaluation:** the latest assessments are the test set. Training only uses assessments
whose outcomes were already known before the test period began. A model must meet the
recall, recall lower bound, precision, F1 and PR-AUC limits. Safety-critical categories
need higher recall. Accuracy is reported but is not a criterion.

**Status:** a run ends as `evaluated_rejected`, or `evaluated_pending` if it passes. Pending
models serve nothing until an administrator accepts one. Only then does it become
`evaluated_accepted` and start predicting. A rejected run can never replace an accepted
model, and a model stops serving if its files change or it was built for a different
questionnaire or feature version.

**Predictions** are stored on `assessment_ai_findings` with the model version. They report a
risk level, not a percentage, because scores are not calibrated. Condition-based screening
runs regardless.

Training runs inside one request, because there is no queue, so the interface can only show
that it is running, not a percentage. Model files live in
`trained_models/category_models/`, which git ignores; a model whose files are missing simply
reports prediction unavailable.

## Tests

```powershell
php tests/vehicle_assessment_rules_test.php
# End to end, against a COPY of the database:
php tests/vehicle_assessment_http_test.php ai_down   # Python service stopped
php tests/vehicle_assessment_http_test.php ai_up     # Python service running
php tests/vehicle_assessment_http_test.php training  # Seeds SYNTHETIC history. Disposable database only
```

See the header of `tests/vehicle_assessment_http_test.php` for its environment variables.
