# Legacy tables in `car_rental_db`

Audited on 2026-10-31 (row counts from `SELECT COUNT(*)`, references from a search of
`Back-End/app`, `Front-End/src` and `automotive_ai_service/ai_api`). Nothing has been dropped:
dropping any of these needs the owner's approval and a backup first.

| Table | Rows | Used by the application | Foreign keys | Notes |
|---|---:|---|---|---|
| `tblusers` | 1 | No | referenced by `tblpayment`, `tblreservation`, `tblai_recomendations`, `tblai_risk_assesment` | Earlier user schema (`userID`, `fullName`, `email`, `passwordHash`, `phoneNumber`, `role`, `walletAddress`, `createdAt`, `username`). The app uses `users`. Holds one historical row with a password hash and phone number: treat as personal data. |
| `tblvehicles` | 0 | No | `categoryID` → `tblvehicle_category`; referenced by `tblreservation`, `tblai_recomendations`, `tbldemand_predictions` | Superseded by `cars`. |
| `tblvehicle_category` | 0 | No | referenced by `tblvehicles`, `tbldemand_predictions` | Categories are a column on `cars`. |
| `tblreservation` | 0 | No | → `tblusers`, `tblvehicles`; referenced by `tbltransactions`, `tbl_blockchain_records`, `tblai_risk_assesment` | Superseded by `reservations`. |
| `tbltransactions` | 0 | No | → `tblreservation`; referenced by `tblpayment`, `tbl_blockchain_records` | No transaction flow exists. |
| `tblpayment` | 0 | No | → `tbltransactions`, `tblusers` | Online payment is not implemented; the Payment pages say so. Do not treat this table as payment data. |
| `tbl_blockchain_records` | 0 | No | → `tbltransactions`, `tblreservation` | No blockchain feature exists. |
| `tblai_recomendations` | 0 | No | → `tblusers`, `tblvehicles` | Unused. |
| `tblai_risk_assesment` | 0 | No | → `tblusers`, `tblreservation` | Unused. |
| `tbldemand_predictions` | 0 | No | → `tblvehicles`, `tblvehicle_category` | Unused; the "Demand Intelligence" page does not read it. |
| `tblai_maintenance_predictions` | 1 | **Yes** (`MaintenanceModel::predictions`, `generate_predictions`) | `vehicleID` → `cars` | Stores the rule-based service estimates generated from the admin page. Keep. |
| `tblvehicle_maintenance` | 0 | **Yes** (`MaintenanceModel`) | `vehicleID` → `cars` | The maintenance log. Keep. Currently empty. |

## If they are removed later

The unused group depends on itself only, so it can be dropped as a unit, children first:
`tbl_blockchain_records`, `tblpayment`, `tbltransactions`, `tblai_risk_assesment`, `tblai_recomendations`,
`tbldemand_predictions`, `tblreservation`, `tblvehicles`, `tblvehicle_category`, `tblusers`.
Export `tblusers` first if its one historical row must be kept.
