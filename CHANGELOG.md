# Changelog — Backup Info (backupinfo)

## 0.2.0-rc4 — 2026-11-02 (release candidate)

- Probe page: optional **Search** (VM or job name). Vinchin's `search` is sent to both endpoints,
  so the newest runs of one job, with their status, can be checked with API access only
  (no Vinchin console needed). Needed to settle the RPO for WEEKLY jobs.

## 0.2.0-rc3 — 2026-11-02 (user: Setup placement and filter OK on the VM)

From the first Probe run on the VM (rc2):
- **Connections are managed in Setup**: list, add, edit and delete inside Backup Info's section
  of the PGN Suite settings page. The "Connections" entry is gone from the Tools ICT menu; old
  `connection.php` / `connection.form.php` links forward to that section.
- Vinchin's `"ip": "--"` (and `-`, `N/A`) is read as "no IP", so it never takes part in matching.
- Probe's job-history sample is the newest rows (`sort=start_time`, `order=desc`, one retry
  without sort); before, Vinchin returned the oldest rows.
- Backup objects page: the state filter is a bordered button group (the pills had no visible
  borders and an unreadable active state).

## 0.2.0-rc2 — 2026-11-02 (superseded by rc3; Probe checked on the VM)

- Upgrade step "CREATE/PURGE for config UPDATE holders" now reads the rights back and sets its
  one-shot marker `config_rights_v020` only when every holder is complete; otherwise the next
  upgrade retries (hub §4 rule of 2026-11-02). rc1 was never deployed.

## 0.2.0-rc1 — 2026-11-02 (superseded by rc2, never deployed)

Backup status from Vinchin on GLPI Computers. Spec:
`docs/superpowers/specs/2026-10-28-backupinfo-vinchin-design.md`.

### Added
- **Connections** to backup systems (Vinchin now; the source contract is ready for Sangfor SCP and
  Hyper-V). API key and password are encrypted with GLPIKey, never shown again in the form, never
  written to the item history.
- **Vinchin client** speaking the console protocol: signed requests (AES `sign` + `timestamp`),
  CSRF token rotation with retry on code 910087, API-key or login (RSA password) mode, paging.
  Source data: Backup Object list; job history as fallback.
- **Matching** of backup objects to Computers: VM name first, then IP; ambiguous results go to
  review, never to the first hit. Manual pin / unpin on the **Backup objects** page.
- **Status** per Computer: protected (last backup within the RPO, default 26 h), expired, none.
- **Fields plugin integration**: writes "Backup Status" (Yes for protected and expired) and
  "Backup Media - Content", only when the value changes; reads "Wajib Backup" for the backup
  policy and never writes it. Container and fields are chosen by name in Setup.
- **Backup policy and coverage**: mandatory Computers from the "Wajib Backup" field or a manual
  list; **Coverage** page in Tools ICT.
- **Backup** section in the shared Tools ICT asset tab (order 100); search options 8370–8372 on
  Computer.
- **Setup** section on the PGN Suite settings page: connections, RPO (1–720 h), policy mode,
  Fields mapping, Sync now.
- **Probe** page (config UPDATE): a read-only five-row sample per endpoint from one connection,
  showing the raw rows (credentials masked), the plugin's reading and the match result.
- Automatic action **Sync** (hourly).

### Changed
- Rights: `plugin_backupinfo_config` now offers READ, UPDATE, CREATE, PURGE. On upgrade, profiles
  that held config UPDATE receive CREATE and PURGE once.
- Connections table: `host`, `auth_mode`, `username`, `password`, `verify_tls`, sync status
  columns; `api_url` is copied into `host`; `api_url`, `api_secret`, `token`, `site` are dropped.

### Removed
- rc1 pages Dashboard, Jobs, Storage, Report, Assets and their tables (dropped once on upgrade).
  Excel import is kept in the tree but not wired.

### Safety
- A failing connection never deletes its stored objects; an empty answer while objects are stored
  is treated as an error; a job-history fallback run never deletes objects.
- Objects of inactive or deleted connections stop counting as backups.

### Needs confirmation on the VM
Fields writes under cron (history only on real changes, dropdown entries visible to all
entities), connection add/delete for a non-Super-Admin profile, upgrade from 0.1.0-rc2
(markers, dropped tables, rights), Probe page against the real Vinchin.

## 0.1.0-rc2 — 2026-10-28
- Zip with a top folder; duplicate install functions removed; missing `use` imports; Profile
  rights matrix; settings section returned to the shared page; ConfigTab r5.
