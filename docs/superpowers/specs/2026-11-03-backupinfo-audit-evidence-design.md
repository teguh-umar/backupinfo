# Backup Info: audit evidence, incidents and findings (0.3 / 0.4)

Date: 2026-11-03 · Plugin `backupinfo` (PGN GLPI Suite) · Branch `feat/v0.3-audit` · Status: design, awaiting user approval

## Intent

Auditors (ISO 27001, internal audit, ICOFR/ITGC) ask the same questions about backup every period: which
systems must be backed up, were they, what failed and what was done about it, was a restore ever tested, is
there an off-site copy. Today the answers are monthly PDF reports built by hand. This work makes GLPI answer
them from the Vinchin API, tied to the GLPI assets, and pushes failures into the ITSM process (Incident,
Problem) and gaps into the Risk Register.

## Decisions made by the user (2026-11-03)

| Topic | Decision |
|---|---|
| Frameworks | ISO 27001:2022 (A.8.13 backup; also A.5.30, A.8.15), internal audit, ICOFR/ITGC |
| In scope | Computers whose Fields "Wajib Backup" = Yes. Not the ICOFR plugin's Control links |
| Risk Register | Findings created **automatically** (SAC FindingWriter pattern) |
| ITSM | A backup failure on an in-scope asset opens an **Incident**; repeated failures open a **Problem** linked to those Incidents |
| Tickets | Always **linked to the GLPI asset** (the Computer) |
| Restore-test evidence | **Option C**: Vinchin verification history when it exists, else a manual restore-test record in GLPI; neither in the period → Finding |
| Restore-test interval | **Flexible**: Setup default, overridable per Computer (see 2.3) |

## What the API gives (stage 1 sample, 0.3.0-rc1 on the VM)

| Endpoint | Result | Used for |
|---|---|---|
| `GET /jobs/history?job_status=4` | rows: `history_uuid`, job_name, start/finish, `job_status` "Failed" (`job_status_value` 3005, not 4), duration. No reason, no VM list | Incident source; `history_uuid` = dedupe key |
| `GET /jobs/history` (all statuses) | same shape | ICOFR population |
| `GET /jobs/history/{history_uuid}` (doc, not sampled yet) | per-VM `list`: vm_name, task_status, error_code, timepoint | which VM of a multi-VM job failed |
| `GET /alarm/job` | alarm_id, job_name, alarm_time, status_des, `solved_flag_des`, description (HTML, holds reason, VM name, platform IP). `history_uuid` **empty** | failure reason; joined to the run by job_name + alarm_time = finish_time (± 60 s) |
| `GET /alarm/system` | alarm_id, level, time, description (HTML), solved_flag (bool here, int in job alarms) | evidence page only, no asset, no ticket |
| `GET /logs/job` | op_time, user_name, log_level_des, log_description (HTML) | change evidence (job created / modified / scheduled) |
| `GET /logs/system` | 0 rows with a VM keyword; without one: log_id, user_name, op_type (e.g. `SYSTEM_USER_LOGIN_SUCCESS`), op_time, log_level_des, description (HTML, holds the source IP) | access and admin-activity evidence on the backup system (A.8.15), read live, never with a VM keyword |
| `GET /backupmanager/report/vm` | per VM on the platform: last_backup_time, backup_status, backup_count; **ignores the keyword**, lists unprotected VMs (`backup_status` 0) | coverage: platform VMs never backed up |
| `GET /backup_data/verify_history`, `/virus_history` | 0 rows | restore-test evidence when Vinchin verification is switched on |
| `GET /industry/report` | 0 rows | same |
| `GET /copy/job/history` | status, start/end, `details.info[]`: item_name "VM(Platform)", timepoint_count, item_status | off-site copy evidence per VM |

All descriptions are HTML: the plugin strips tags before storing or showing them. Platform IPs inside
descriptions stay inside GLPI (tickets, pages); they never go into the repo, tests or logs.

## Stage 2 (release 0.3.0): evidence store, evidence page, restore tests

### 2.1 Run store (durable population)

Vinchin keeps history for a limited time; audit needs the population for the whole period, a year later.
The daily Sync cron also stores every run of every job that covers a matched object:

- Table `glpi_plugin_backupinfo_runs`: id, connections_id, `history_uuid` (unique per connection), job_name,
  job_uuid, start_time, finish_time, status (success / failed / aborted / abnormal, mapped from the doc's
  1-4 and the `job_status` text), size, duration_s, reason (from the matched alarm, tags stripped, ≤ 1000
  chars), date_creation.
- Table `glpi_plugin_backupinfo_runs_items`: runs_id, computers_id, vm_name, item_status. Filled from
  `/jobs/history/{uuid}` for **failed** runs (per-VM truth); for successful runs, from the job's matched
  objects (no extra call per run).
- Incremental: fetch history newest-first until a page is entirely already stored, max N pages per run
  (config, default 20 × 100). First run backfills as far as Vinchin still has.
- Never updates or deletes a stored run (evidence is append-only). Purge only by an explicit setting
  "keep runs N months" (default 36), off-cycle.

### 2.2 Restore-test record (manual, option C)

- New item `PluginBackupinfoRestoreTest`: computers_id, date, method (Vinchin verification / file restore /
  full VM restore / DR drill), result (pass / partial / fail), RTO achieved (minutes, optional), notes,
  tester (users_id). Documents attach with the core Document tab (screenshots, signed forms).
- Tab "Restore tests" on Computer and a list under the plugin menu. Right `plugin_backupinfo_restoretest`
  (read / create / update / purge), granted to Super-Admin on install.
- Vinchin `verify_history` rows for a matched object are shown in the same tab as read-only
  "Vinchin verification" rows (not copied into the table), so both sources count.

### 2.3 Audit evidence page (`front/audit.php`, sub-tab "Audit")

Period picker (month, quarter, year; default last full month), entity-scoped like the coverage page.
Each section has a CSV export with a header block: period, generated at, by whom, connection, plugin version.

1. **Policy (test of design)**: every job covering an in-scope asset: schedule as read, RPO, grace.
2. **Coverage**: in-scope Computers × status (Protected / Stale / Not found). Plus platform VMs from
   `/backupmanager/report/vm` with `backup_status` 0 that match an in-scope Computer.
3. **Population (ICOFR)**: every stored run in the period for jobs covering in-scope assets, one line per
   run × Computer. This is what the auditor samples from.
4. **Failures**: failed runs in the period, reason, linked Incident and Problem (number, status, solved date).
5. **Restore tests**: per in-scope Computer: last Vinchin verification, last manual test, result, next due;
   overdue highlighted.

**Restore-test interval is flexible** (user, 2026-11-03): a global default in Setup (months, default 12; 0 =
no requirement), overridable per Computer on its "Restore tests" tab (e.g. 3 months for a critical system,
0 to exempt one). Every page, CSV and the `BI-RESTORE` finding use the Computer's effective interval.
6. **Off-site copies**: `/copy/job/history` in the period per matched VM (item_name without the
   "(Platform)" suffix is matched like any object name).
7. **Changes, access and alarms**: job logs (`/logs/job`), system logs (`/logs/system`: logins, admin
   actions) and system alarms in the period, read live.

Sections 1, 2, 6, 7 are read live from the API at page time; 3, 4, 5 come from GLPI tables, so they still
work for a period Vinchin has already purged.

## Stage 3 (release 0.4.0): Incidents, Problems, Risk Register findings

Both writers run inside the Sync cron after the run store is updated, and both are **off until enabled** in
Setup (no surprise tickets on upgrade). Enabling sets a start time: runs before it never open tickets.

### 3.1 Incident per failed run

- For each stored failed run × in-scope Computer without a ticket yet: create a Ticket, type Incident, via
  the class API (`Ticket::add`), in the Computer's entity, with the Computer linked (`items_id`).
- Title: `Backup failed: <computer> (<job>, <start_time>)`. Content: reason, start/finish, job,
  `history_uuid`, link to the Computer's Backup Info tab.
- Setup: requester user, assigned group, ITIL category, urgency / impact (default 3 / 3).
- Dedupe: `glpi_plugin_backupinfo_runs_items.tickets_id`. One run × Computer → at most one Incident, ever.
- The plugin never changes or closes a ticket afterwards. (Optional, off by default: one follow-up
  "Backup succeeded again at …" on the open Incident when the next run of that Computer succeeds.)

### 3.2 Problem for repeated failures

- When a Computer has ≥ **3** failed runs within **30** days (both in Setup) and no open Problem from this
  plugin: create a Problem (`Problem::add`) in its entity, Computer linked (`Item_Problem`), all those
  Incidents linked (`Problem_Ticket`).
- While that Problem is open (not solved / closed), each new Incident for that Computer is linked to it
  instead of opening another one. After it is solved, a new streak opens a new Problem.
- Table column `glpi_plugin_backupinfo_runs_items.problems_id` plus a lookup on open Problems by
  Computer through `Item_Problem`.

### 3.3 Risk Register findings (automatic)

Same pattern as SAC's `FindingWriter`: only when the Risk Register plugin is active and its classes exist;
class API only (hub rule 5); upsert by (entities_id, code); never touches `status`; `is_recursive = 0`;
Computers linked with `Finding_Item` once; mapped to ISO 27001:2022 **A.8.13** via
`Finding_FrameworkControl`; when the signal clears, a "Signal cleared on <date>" note, nothing else.

| Code | Raised when (per entity) | Items |
|---|---|---|
| `BI-COVERAGE` | an in-scope Computer is Not found or Stale at the end of Sync | those Computers |
| `BI-RESTORE` | an in-scope Computer has no restore test (Vinchin or manual) within its restore-test interval | those Computers |
| `BI-REPEAT` | a plugin Problem is open for a Computer | those Computers |

Exact Risk Register class and field names are confirmed by grep against the plugin source at
implementation time (strings behind `class_exists`, invisible to the graph).

## Open items (to settle in the first RC of each stage)

1. ~~`/logs/system` with a keyword~~: settled, it returns rows without a keyword (sample 2026-11-03).
2. `/jobs/history/{history_uuid}` is documented but not sampled: add it to the audit probe (first failed run).
3. The alarm ↔ run join (job_name + time ± 60 s) is from one sample; the run store keeps runs without a
   reason when no alarm matches.

## Non-goals

- Changing anything in Vinchin (acknowledging alarms, starting jobs, switching on verification).
- Tickets for system alarms (no asset), or for assets not marked Wajib Backup.
- Replacing the monthly PDF report layout; the CSVs are the raw evidence behind it.

## Delivery

Rule 8: every build is an RC until the VM confirms. Rule 10: implementation goes to OpenCode through
`HANDOFF-opencode.md` with a plan per stage; Claude reviews each task. Stage 2 → 0.3.0-rcN, stage 3 → 0.4.0-rcN.
