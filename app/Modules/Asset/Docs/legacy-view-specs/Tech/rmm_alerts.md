# Asset Alerts – Data Model & Sync (tdPSA)

Date: 2026-04-23
Status: In progress
Scope: Normalize RMM alerts (Tactical + N-able) into a single model, persist, and expose for UI queries.

---

## Goals

* Ingest alerts from multiple RMMs
* Store a stable, deduplicated alert state
* Support reopen/resolve lifecycle
* Keep model simple (no severity engine yet)
* Make it easy to query “assets with active alerts”

---

## Decisions (v1)

* Table name: `asset_alerts`
* Status: `active | resolved`
* No offline-as-alert (deferred to settings later)
* Message stored (title + output)
* One alert per (integration + asset + check)
* Reopen same alert when issue returns

---

## Database Schema

### Table: asset_alerts

```
id (PK)
asset_id (FK → assets.id)
integration_type (string: tactical | nable)

external_check_id (string/int, nullable)
external_alert_id (string/int, nullable)

fingerprint (string, UNIQUE)

title (string)
message (text, nullable)

status (enum: active | resolved)

first_seen_at (datetime)
last_seen_at (datetime)
resolved_at (datetime, nullable)

created_at\ nupdated_at
```

### Indexes

* UNIQUE(fingerprint)
* INDEX(asset_id, status)
* INDEX(status)

---

## Fingerprint (Critical)

Unique identity for a problem:

```
fingerprint = integration_type + ":" + asset_id + ":" + external_check_id
```

Examples:

* `tactical:124:77`
* `nable:5574558:148737867`

---

## Lifecycle

```
CREATE → UPDATE → RESOLVE → REOPEN
```

### Create

* Not found by fingerprint → insert
* `status = active`
* `first_seen_at = now()`
* `last_seen_at = now()`

### Update (still failing)

* Found and `status = active`
* Update `last_seen_at`

### Resolve

* Not present in current sync (N-able diff) OR
* Tactical check becomes OK

```
status = resolved
resolved_at = now()
```

### Reopen

* Found and `status = resolved`

```
status = active
resolved_at = null
last_seen_at = now()
```

---

## Sync Architecture

```
Scheduler
  ↓
SyncIntegrationJob
  ↓
Dispatch per-asset jobs
  ↓
Workers (rate limited)
```

---

## RMM API Endpoints

### Tactical RMM

* List checks per agent (source of truth):

    * `GET /agents/{tactical_rmm_asset_id}/checks/`

### N-able RMM

* List failing checks (event-style feed):

    * `GET https://wwweurope1.systemmonitor.eu.com/api/?apikey={API_KEY}&service=list_failing_checks`

---

## Tactical Mapping

Endpoint:
`/agents/{asset_id}/checks/`

For each item:

Map:

* `external_check_id = id`
* `title = readable_desc`
* `message = readable_desc + "\n" + stdout`
* `retcode`, `fail_count`, `fails_b4_alert`

Alert condition:

```
if retcode != 0 AND fail_count >= fails_b4_alert:
    active alert
else:
    resolved
```

---

## N-able Mapping

Endpoint:
`https://wwweurope1.systemmonitor.eu.com/api/?apikey={API_KEY}&service=list_failing_checks`

Response format: XML

For each check:

Map:

* `external_check_id = checkid`
* `title = description`
* `message = description`

These are already active alerts.

### Diff Logic (Required)

1. Build set of current fingerprints from API
2. For each DB alert (active):

    * If NOT in API → resolve
3. For each API alert:

    * create/update/reopen

---

## Tactical Mapping Details

Endpoint:
`list_failing_checks`

For each check:

Map:

* `external_check_id = checkid`
* `title = description`
* `message = description`

These are already active alerts.

### Diff Logic (Required)

1. Build set of current fingerprints from API
2. For each DB alert (active):

    * If NOT in API → resolve
3. For each API alert:

    * create/update/reopen

---

## Core Upsert Logic (Pseudo)

```
alert = findByFingerprint(fp)

if alert exists:
  if alert.status == resolved:
    reopen
  else:
    update last_seen
else:
  create new
```

---

## Resolve Logic (Pseudo)

Tactical:

```
if check now OK:
  resolve
```

N-able:

```
if alert not in API set:
  resolve
```

---

## Queries

### Assets with active alerts

```
SELECT a.*, COUNT(al.id) as active_alerts
FROM assets a
JOIN asset_alerts al ON al.asset_id = a.id
WHERE al.status = 'active'
GROUP BY a.id
```

---

## Notes / Constraints

* Do NOT delete alerts during sync
* DB is source of truth
* No time-based cleanup required now
* Asset deletion cleanup handled later

---

## UI Components

### Asset Detail (Show)
* `AssetAlerts` Livewire component: Displays active and recently resolved alerts for a specific asset.
* Includes manual "Update Alerts" button.

### Asset Index (Index)
* `ClientAlertsSummary` Livewire component: Displays a summary (Active/Resolved counts) for the selected client or all assets.
* Includes manual "Sync All" button that triggers real-time synchronization for all relevant assets.
* Enhanced Filter: Added "With Active Alerts" checkbox to the asset filter bar.

---

## Next Steps

* Implement model + migration
* Build Tactical sync job
* Build N-able sync job
* Add rate limiting
* Add UI listing

---

End of spec.
