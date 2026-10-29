# Component maintenance (3D Predictive Maintenance page)

Powers `/admin/maintenance/predictions`. Separate from the legacy service
records (`tblvehicle_maintenance`) and the evidence-verification module
(`maintenance_data_records`); neither is altered.

## Database setup

Run `database/component_maintenance.sql` once (phpMyAdmin or the mysql CLI),
or migration `009_create_component_maintenance.php`. Both are additive and
idempotent. Tables:

| Table | Purpose |
|---|---|
| `maintenance_component_types` | Stable component keys (`engine`, `brakes-front`, `tire-rear-left`, ...), display names, and which body types' 3D models contain each component. Seeded. |
| `vehicle_component_inspections` | Append-only inspection / service history per `cars.id` + component. Rows are never edited; a newer record supersedes an older one. |
| `maintenance_service_intervals` | Administrator-configured intervals, per vehicle or per body type. **Not seeded**: each rule must state its source (`basis`). |

A database created from an earlier copy of the SQL file needs:
`ALTER TABLE vehicle_component_inspections ADD COLUMN reported_symptoms TEXT NULL AFTER condition_rating, ADD COLUMN dtc_codes VARCHAR(255) NULL AFTER reported_symptoms;`
and the `steering` row from the seed `INSERT IGNORE`.

## API (active administrators only; customers get 403)

| Method and path | Purpose |
|---|---|
| `GET /api/maintenance/vehicles/{id}/components` | Vehicle, body type, and per-component condition, schedule, prediction, latest record, recommended action. |
| `GET /api/maintenance/vehicles/{id}/components/{key}/history` | Inspection history, newest first (max 100). |
| `POST /api/maintenance/vehicles/{id}/components/{key}/inspections` | Append an inspection. Client-sent status fields are ignored. |
| `PUT /api/maintenance/vehicles/{id}/components/{key}/schedule` | Create/replace an interval (`scope`: `vehicle` or `body_type`). |
| `DELETE /api/maintenance/vehicles/{id}/components/{key}/schedule?scope=` | Remove an interval. |

## Status rules (`app/libraries/ComponentMaintenanceRules.php`)

The three results are calculated independently on the server and never merged.

**Condition**, from the newest inspection only:
- `critical` / `needs_attention`: recorded by an administrator, with findings
  required. Persists until a newer inspection records otherwise.
- `good`: only while the inspection is younger than
  `component_condition_valid_days` (default 90, `app/config/api.php`, env
  `COMPONENT_CONDITION_VALID_DAYS`). Older results become `unknown`.
- `unknown`: no inspection, a stale "good", or the latest record did not
  assess condition.

**Schedule**: due points are the configured interval measured from the last
service performed (days and/or km) and any next-service date or km recorded on
an inspection; the earliest wins. A service completed after a recorded due
point satisfies it. `overdue` once a point is passed, `due_soon` within the
rule's warning window (30 days / 1,000 km for recorded points without a rule),
otherwise `not_yet_due`. `not_configured` when there is no rule and no recorded
point; `unknown` when a rule exists but there is no service baseline or no
current odometer for a mileage-only point. An overdue schedule is not a fault.

**Prediction**: LavaLust asks the Django service (`/api/capabilities/`). Only
when its maintenance model is `evaluated_accepted`, eligible for inference, and
lists the component as a target is `/api/predict/` called and a score shown.
Otherwise every component shows "Prediction unavailable: insufficient
validated data." with the service's reason. If Django is unreachable the page
still works; predictions are reported unavailable.

## 3D models

No GLB/glTF assets exist in the project, so the page uses parametric three.js
rigs (`Front-End/src/Pages/Admin/Components/maintenance3d/`) for sedan, SUV,
pickup, van, minivan and motorcycle. Every selectable component is real
geometry tagged with its component key, so hotspots, highlighting and camera
framing always target an existing mesh. Internal parts are revealed with an
x-ray cutaway. The rigs represent the body type, not the exact make/model.
`cars.category` is mapped to a body type by keyword; unmapped categories show
a list-based fallback with only the components common to every body type.

## Tests

`php tests/component_maintenance_rules_test.php` covers the status rules and
input validation.
