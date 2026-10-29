# Maintenance data collection and verification

This feature is separate from the existing service-interval table and AI
predictions. It records observations and maintenance outcomes; it does not
produce a fault prediction or connect to the experimental anomaly detector.

## Database setup

The application has migrations disabled by default in
`app/config/migration.php`. Back up the database, configure the database
connection, temporarily enable migrations, and run the existing LavaLust
migration CLI from `Back-End`:

```powershell
php console\cli.php migration run
```

Then restore `migration_enabled` to `FALSE`. Migration `008` creates
`maintenance_data_records` and `maintenance_data_audit`; it does not alter or
replace existing vehicle, service-history, prediction, or chatbot tables.
Alternatively, execute the corresponding `CREATE TABLE` statements at the end
of `database/schema.sql` against a database where the base schema has already
been installed. Do not apply both routes to a database without checking the
migrations ledger; the table creations are idempotent, but the manually
executed schema does not record migration `008`.

The vehicle foreign key uses `ON DELETE RESTRICT` so vehicles with evidence
cannot be removed. Audit events also restrict record deletion. The application
provides no delete route.

## Record data and label meaning

`maintenance_data_records` references the existing `cars.id`; it does not
create a second vehicle inventory. On creation it snapshots vehicle category,
make, model, and year from that row. `engine_details` is optional free text:
the existing fleet schema does not provide an engine specification catalog.
Odometer values are optional and include an explicit `km` or `mi` unit when
present. Timestamps are ISO-8601 values with an explicit timezone and are
normalized to UTC. Missing data is not filled with a presumed normal value.

Record types distinguish vehicle inspections, sensor measurements, diagnostic
scans and trouble codes, suspected faults, mechanic-confirmed faults, repairs,
component replacement, follow-up inspections, and preventive services.
Sensor measurements are a list of `{name, value, unit}` values. DTCs,
suspected causes, mechanic-confirmed causes, diagnostic test results, repair
actions, replacement components, and repair outcomes have separate fields.
A DTC, sensor outlier, suspected cause, or repair entry is not itself a
confirmed fault label.

The verification state is independent of the record type:

| State | Allowed next state |
|---|---|
| `draft` | `awaiting_verification` |
| `awaiting_verification` | `verified`, `rejected` |
| `verified` | `superseded` |
| `rejected` | none |
| `superseded` | none |

Only active users with the existing `admin` role can access the routes and
change verification status. New records are always drafts regardless of
client-supplied status. Verification records the server-authenticated user
and timestamp; the submitting account cannot verify or reject its own record.
Rejection and supersession require reasons. Supersession
requires another verified record for the same vehicle. Create/edit/status
changes write snapshots to `maintenance_data_audit`; submitted data cannot be
edited after draft, and corrections should be entered as a new record.

The existing application has no dedicated technician role. Until one is
introduced through the established role and authorization design, admins are
the only staff permitted to collect and verify this evidence.

Evidence is captured as a source description and evidence reference (URL or
local reference). This feature does not upload, serve, or validate attachment
files; do not enter credentials or sensitive personal information in evidence
references.

## Routes

All endpoints are under `/api/maintenance/data-records` and require an active
admin session:

| Method and path | Purpose |
|---|---|
| `GET /api/maintenance/data-records` | Filtered, paginated list; supports `search`, `vehicle_id`, `record_type`, `component`, `verification_status`, `date_from`, `date_to`, `page`, and `limit`. |
| `POST /api/maintenance/data-records` | Create a draft. Optional `Idempotency-Key` must be a UUID. |
| `GET /api/maintenance/data-records/{id}` | Record and immutable audit history. |
| `PATCH /api/maintenance/data-records/{id}` | Edit supported fields on a draft only. |
| `POST /api/maintenance/data-records/{id}/submit` | Move draft to awaiting verification. |
| `POST /api/maintenance/data-records/{id}/verify` | Set `verification_status` to `verified` or `rejected`; rejection requires `reason`. |
| `POST /api/maintenance/data-records/{id}/supersede` | Supersede a verified record using `replacement_record_id` and `reason`. |
| `GET /api/maintenance/data-records/export?format=json` | Export verified records only. |
| `GET /api/maintenance/data-records/export?format=csv` | Download the same verified-only export as CSV. |

The React admin interface is `/admin/maintenance/data`. The legacy maintenance
records, mileage, and rule-based schedule endpoints remain unchanged.

## Export schema, version 1.0

Each row contains a stable `id` and `vehicle_id`, the snapshotted vehicle make,
model, year, and category, record type, record timestamp, optional odometer
and its unit,
component, symptoms, inspection findings, DTC list, sensor measurement list
with units, separate suspected and confirmed causes, diagnostic tests, repair
actions, replaced components, repair outcome, source, verification state,
verification timestamp, and creation time. Evidence references and user
identifiers are intentionally omitted from exports; they remain available to
authorized staff in the application audit history. The export excludes user
names, credentials, contact details, and vehicle registration plates.
`component_identifier` is optional and must refer to an existing external
fleet/component identifier; the module does not invent or allocate component
IDs. Component text alone is not guaranteed to be a normalized component key.

Unrecorded scalar values are null/empty; lists are empty arrays. They do not
mean that a fault was absent. The export only includes rows currently in the
`verified` state; drafts, awaiting, rejected, and superseded rows are omitted.
`confirmed_fault_label` is `true` only for an explicitly verified
`mechanic_confirmed_fault` record with a non-empty confirmed cause. It is
`null` for all other record types; no negative labels are inferred. Future
failure targets, remaining useful life, maintenance schedules, DTCs, and
sensor observations remain distinct and are not generated automatically.

CSV cells beginning with spreadsheet formula characters are escaped to reduce
formula injection when opened in spreadsheet software.
