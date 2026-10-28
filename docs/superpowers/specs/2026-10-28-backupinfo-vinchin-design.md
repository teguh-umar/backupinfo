# Backup Info: backup status on GLPI assets (Vinchin first)

Date: 2026-10-28 · Plugin `backupinfo` (PGN GLPI Suite) · Status: design, awaiting user approval

## Intent

The DCOC team runs a Python backup-monitoring app for daily operations: coverage, per-source recap,
policy compliance, expired snapshots, exports. It has no API. **GLPI is the single source of truth for
assets**, so backup status has to live on the asset in GLPI, where it can be searched, filtered and
reported like any other attribute. This plugin does that. It does **not** copy the Python dashboards.

## Goals

1. Pull backup objects **directly from the backup systems** (the Python app has no API). Vinchin now; the
   design must let Sangfor SCP and Hyper-V be added later as further source types without rework.
2. Match each backup object to a GLPI asset: **VM name first, then IP**, the way the OpManager plugin does.
3. On each matched asset, write two existing Fields-plugin fields and show detail in the plugin's own tab.
4. Hold the backup policy (which assets must be backed up) and show coverage against it.

## Non-goals (v1)

- Storage appliances (capacity, trend, location). Not in scope yet (user, 2026-10-28).
- Sangfor SCP and Hyper-V clients. Their API files are not here yet; only the extension point is built.
- Re-creating the Python app's dashboards (freshness bar, needs-attention list, largest objects).
- Excel import (`src/Import/ExcelImport.php` exists from rc1). Parked, not deleted silently: kept out of v1 menus.

## Anti-goals

- Overwriting what a person entered. The plugin writes only the fields it owns (below), never "Wajib Backup".
- Secrets in plain text anywhere: DB, form, logs, error messages.
- Raw-writing another plugin's tables (hub §0 rule 5): Fields values go through the Fields plugin's own classes.

## Decisions made by the user

| Topic | Decision |
|---|---|
| Role of the plugin | Backup status as asset attributes in GLPI; Python app stays the ops tool |
| Data source | Directly from Vinchin API (no Python API exists) |
| Sources in v1 | Vinchin only; prepare for others |
| Credentials | Host/IP, API key or username/password set in Setup, **encrypted** like OpManager / Mist / integrasipaman |
| Matching | Like OpManager: VM name, then IP |
| Fields: **Wajib Backup** (Yes/No, new) | Set by people: the backup policy |
| Fields: **Backup Status** (Yes/No) | Written by the plugin: the asset **has** a backup |
| Fields: **Backup Media - Content** | Written by the plugin when the information exists |
| Expired backup | Backup Status = **Yes** (it has a backup; freshness is shown separately) |
| Policy location | Setup of the plugin: pick mandatory assets there, **or** read the "Wajib Backup" field |
| Display | Plugin's own tab on the asset, **and** it updates the Fields values |
| Storage appliances | Not now |

## How it works

### Sources (extension point)

- A `Connection` row per backup system: `type` (`vinchin` now), name, host, auth mode (API key | username+password),
  TLS verify flag, active flag. Secrets (`api_key`, `password`) are stored with `(new GLPIKey())->encrypt()`,
  decrypted only inside the client at call time, never echoed into the form (show "set / unchanged"; an empty
  submit keeps the stored value; an explicit clear button removes it). Model: `plugins/OPmanager GLPI Plugin/src/Config.php`.
- An interface, e.g. `Source\BackupSource::fetch(): iterable<BackupObject>`, with one class per type; a registry maps
  `type` → class. Adding Sangfor later = one new class + one entry, no schema change.
- `BackupObject` (normalised): source connection id, source key (VM name for VMs, `item_uuid` otherwise), display
  name, IP (may be empty), kind (VM / database / file / agent), job/task name, last backup time (UTC), restore point
  count, data size, flags `abnormal_chain`, `source_deleted`.

### Vinchin client (rewrite of `src/Api/VinchinClient.php`)

The rc1 client does not sign requests, so Vinchin would reject it. Port `python/vinchin (1).py` and follow
`python/vinchin-openapi.yaml`:

- Base `https://<host>/api/v1`; headers `x-api-version: 1.0-rev0`, `X-Requested-With: XMLHttpRequest`,
  `Accept: application/json`; auth `X-API-Key: <key>` **or** `Authorization: <access_token>` (no `Bearer`).
- CSRF: send the last response's `__token__` header as `X-Csrf-Token`; on code `910087` retry with the new token, max 3.
- Sign every request: params + `timestamp` (epoch ms), keys sorted A→Z, JSON like `JSON.stringify` (no spaces, types
  kept, unicode unescaped), `sign = hex(AES-256-CBC-PKCS7)` with the console's static key/IV (`openssl_encrypt`).
  GET → query string; POST → JSON body sent as `application/x-www-form-urlencoded; charset=UTF-8`.
- Login (when no API key): `POST /login`, password RSA PKCS#1 v1.5 with the console public key, base64
  (`openssl_public_encrypt`); retry with the plain password, as the Python client does.
- Primary data: `POST /backup_data/items/list`, empty filters, pages of 100 until `offset >= total` (cap 20 000).
  Mapping as in the YAML: `module_type_des = Virtualization` → VM, merged per VM name; others keyed by `item_uuid`,
  name `"<object> @ <instance>"`; `NAME(10.x.x.x)` in `item_name` → name + IP; `vcenter_name` is the hypervisor host
  and is **never** used as the VM's IP; `last_time` may be epoch s / ms or a date string.
- Fallback `GET /jobs/history` only if the primary call fails: lookback 14 days, VM name found inside `job_name`,
  skip restore/recovery/verify/migrate/mount/instant jobs.
- A golden test pins `sign` for a fixed timestamp against a value produced by the Python client.

### Matching

Reuse the OpManager approach (`plugins/OPmanager GLPI Plugin/src/Matching/Matcher.php`): exact name (case-insensitive)
among non-deleted, non-template Computers; if none, by IP via core network ports / IP addresses; ambiguous = unmatched
and listed. Only VM-kind objects are matched to Computers in v1; other kinds are stored and shown but not matched.
Objects that stay unmatched are listed on a plugin page so someone can see them.

### Per-asset status

For each matched Computer, derived from its objects:

- **Protected**: last backup ≤ RPO. **Expired**: has backup, last backup > RPO. **No backup**: no object.
- RPO is a Setup value, default 26 hours (the Python app's value).
- Flags shown when set: abnormal backup chain, source deleted.

### Writes to Fields

- Setup maps which Fields container and fields to use (chosen from lists, not hard-coded names). Defaults point at
  the "Backup Information" container: **Backup Status** and **Backup Media - Content**.
- Backup Status = Yes when the asset has at least one backup object (Protected or Expired), No otherwise.
- Backup Media - Content = source and kind (e.g. "Vinchin · VM · DAILY_PRODUCTION"), only when known; otherwise left
  as is.
- Writes go through the Fields plugin's generated item class so history is recorded; written only when the value
  changes. If Fields is missing or the mapping is unset, sync still runs and the tab still works; a Setup warning
  says why Fields is not updated.

### Policy (mandatory assets)

Setup offers one mode:
1. **From field:** read "Wajib Backup" (mapped like above) on each asset.
2. **Manual list:** pick Computers in Setup (stored by the plugin).

Coverage = Protected mandatory assets / all mandatory assets.

### UI

- **Asset tab "Backup"** on Computer: status badge, last backup (with age), source, kind, job, restore points, size,
  flags, mandatory yes/no, last sync time. Visible with `plugin_backupinfo_view` READ.
- **Search options** on Computer for backup status and last backup time, so core search and saved searches can filter.
- **Setup section** (shared PGN Suite settings page): connections, RPO, Fields mapping, policy mode, sync now.
- **Coverage page** in the Tools ICT menu: coverage figure, mandatory assets without backup, unmatched objects.
  Plain tables, no charts.

### Sync

- GLPI automatic action (cron), default hourly; plus a "Sync now" button. One run per connection; one failing
  connection does not stop the others; last result and error (without secrets) kept per connection.
- Objects no longer returned by a source are removed after the run, so "No backup" is accurate.

### Rights and entities

- Rights unchanged: `plugin_backupinfo_config` (READ|UPDATE), `_view` (READ), `_report` (READ).
- The tab and pages show only assets in the user's active entities (hub entity rule). Sync runs across all entities.

## Data model (needs a §2 claim update before code)

- `glpi_plugin_backupinfo_connections`: keep; add `auth_mode`, `username`, `password` (encrypted), `verify_tls`,
  `last_sync`, `last_status`, `last_error`; drop `api_secret`/`token`/`site` if unused.
- `glpi_plugin_backupinfo_objects` (new): one row per backup object, with `items_id` (Computer) when matched.
- `glpi_plugin_backupinfo_policy` (new): manual list of mandatory Computers.
- Config values (RPO, mapping, policy mode) in core config context `plugin:backupinfo`.
- Drop rc1 tables not used by v1: `_assets`, `_jobs`, `_storages`, `_mappings` (rc only; no production data).
- Cron: `GlpiPlugin\Backupinfo\Sync` task `sync`.

## Constraints

- GLPI 11, PHP 8.x, OpenSSL ext; no Composer dependencies needed.
- Hub §0 rules: every build `-rcN` until the user confirms on the VM (rule 8); manual via Copilot (rule 9);
  multi-task implementation via `HANDOFF-opencode.md` (rule 10); new tables/rights/cron claimed with the
  orchestrator first.
- Zip: top folder `backupinfo/`; exclude `python/`, `docs/`, `graphify-out/`, `CLAUDE.local.md`, `.claude/`, PDFs.

## Left to the builder

Class and file layout, exact Setup form layout, column widths, wording of labels, test structure.
