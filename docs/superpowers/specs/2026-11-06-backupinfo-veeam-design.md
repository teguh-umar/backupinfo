# Backup Info 0.5.0: brand-neutral sources and a Veeam VBR 13 connector

Status: design, 2026-11-06. Builds on branch `feat/v0.4-tickets` (0.4.0-rc3, not yet final).
Research: `reports/Veeam VBR 13 REST API.md` (local only, not in the repo). It is the field
reference for every Veeam endpoint named below.

## Why

The plugin will be given to **other organisations** that run backup brands other than Vinchin. It
must also **prove that its architecture takes a new brand cleanly**. The goal is 10+ brands (see the
multi-brand goal: common Event and Target shapes, no brand fields in stored evidence). Veeam Backup &
Replication 13 is the second brand. It is the first one written with **no server to test against**.
PGN has no Veeam, and there is **no promised feedback channel** from whoever runs it later.

Veeam users use the plugin exactly as Vinchin users do. Backup Tech looks at backup status on the
Computer tab and on Coverage. Auditors pull ISO 27001, internal-audit and ICOFR evidence from Audit
evidence (8 tabs plus the Excel export). Tickets and Risk Register findings follow from the same runs.

## Goals

1. **Brand-neutral seams.** After 0.5.0, a new brand means three things and nothing else in the
   plugin's core: a folder `src/<Brand>/`, one line in `Source\Registry`, and the brand's own tests.
   The core here means sync, stores, pages, tickets, findings and the Excel export.
2. **Vinchin unchanged.** Every existing test still passes. No Vinchin output changes: not stored
   rows, Probe output, page text or exports.
3. **Veeam at parity, as far as VBR 13 REST allows.** It covers:
   - objects and Computer status;
   - runs with per-VM results and failure reasons (ICOFR population, tab 3, tab 4, tickets);
   - repositories as Targets (tab 8);
   - backup copy runs (tab 6);
   - security events (tab 7): four-eyes authorization events and malware detection events;
   - platform VMs with protected or not (tab 2), only if the inventory read can be done safely
     (see Open for the builder).
4. **Honest gaps.** What Veeam REST cannot give says so on the page in words, never as an empty
   table that reads like "nothing happened". This covers:
   - tape;
   - system alarms (Veeam ONE only);
   - login source IP;
   - SureBackup boot checks, which are unconfirmed.
5. **Self-explaining failures.** The person who installs it cannot ask us, so every refusal or
   unexpected answer must name enough for them to act. It names:
   - the endpoint;
   - the HTTP status;
   - the field that was missing or unknown;
   - the API version in use;
   - the likely cause, such as a missing role (403), a wrong API version (400) or a TLS problem.

## Anti-goals (a failure even if it "works")

- **A Computer shown as backed up when that is not proven.** If a field Veeam returns is missing or
  has an unknown value, that data counts as *unknown*. The Computer's status falls to unknown or not
  protected, never to OK.
  - An unknown run result is never success.
  - A run still in progress is not stored until it has a final result.
- **Silent wrong numbers.** Capacities whose unit is not certain are shown as the source text with
  its unit (e.g. "512 GB"), not converted on a guess.
- **Any write to Veeam.** The connector reads only. The only POSTs allowed are:
  - the OAuth2 token, refresh and logout;
  - the inventory browser read, if used, which Veeam exposes as POST.

  Each is allowlisted by route in the Veeam client, and any other method or route throws before it
  is sent.
- **Secrets in logs, Probe output or errors.** Same rule as Vinchin: never print, log or store a
  decrypted secret or token, and redact token-like keys in Probe output.
- **Brand fields leaking into stored evidence.** Stored Run, Event and Target rows keep the common
  shapes. Veeam's raw enums may appear inside text fields (e.g. Target `type`), not as new columns.

## Non-goals for 0.5.0

- Tape, Veeam ONE alarms, Enterprise Manager, or PowerShell or CSV exports to fill Veeam REST gaps.
- Veeam Backup for Microsoft 365, or Veeam agents run by a Veeam Service Provider console.
- Testing against a live server (none exists). Verification is left to the installer, with Probe and
  the checklist below.
- Finishing 0.4.0. Tickets and findings testing is parked by the user. 0.5.0 stacks on rc3, and both
  stay unreleased until the user decides.

## Constraints

- GLPI 11 and PHP 8.x. Everything comes from what the plugin already uses: curl, `GLPIKey` for
  secrets, and the plugin's test runner (`php tests/run_all.php`, no PHPUnit, fakes in
  `tests/fakes.php`).
- The repository and zip rules hold as before:
  - no real hostnames, IPs, VM names or customer data in fixtures; fixtures are written from the
    Veeam documentation's examples;
  - the research notes stay local.
- The hourly sync must stay cheap. There are no per-object HTTP calls in the hourly path beyond a
  fixed cap, the same idea as `RunSync::MAX_ITEM_CALLS`.
- Access token: 15 minutes, refresh token 24 hours. Log in once per sync and refresh on a 401 or near
  expiry. Log out at the end.

## Decided (by the user in this conversation)

- Veeam is used the same way as Vinchin. It is for other organisations and for proving the
  architecture.
- No feedback can be counted on. So the connector must be safe when unsure, and it must explain
  itself.

## Decided by Claude (calls the user has not reviewed in detail)

- **Pattern.** Mirror the Vinchin folder. In `src/Veeam/` that means a `Client` (OAuth2, version
  header, paging), pure mappers per shape, and one `VeeamSource` implementing the same interfaces as
  `VinchinSource` where Veeam has the data.
- **Labelled preview.** The Type dropdown shows **"Veeam B&R 13 (preview, not verified on a
  server)"**. The README and CHANGELOG say what preview means. Dropping the label later is a one-line
  change, once someone confirms the checklist.
- **Connection form.**
  - Veeam uses username and password (OAuth2 password grant), with no API key.
  - A new per-connection field **API version** defaults to `1.3-rev2`; `1.3-rev1` covers 13.0.1.
  - Host may include a port, and the default port is 9419.
  - TLS verification works as today.
  - Fields that do not apply to the chosen type are hidden or disabled in the form, not merely
    ignored.
- **Statuses.**

  | Veeam value | Plugin value |
  |---|---|
  | Success | `success` |
  | Failed | `failed`, even when `isCanceled` = true |
  | Warning | `abnormal` |
  | `isCanceled` = true with Success, Warning or None | `aborted` |
  | session still running (`state` ≠ Stopped) | not stored yet |
  | any other value, or a stopped session with result None | `abnormal`, stored (Vinchin's RunMapper rule) |

  Only `failed` opens tickets, as for Vinchin. Failed wins over cancel because a cancelled run that
  failed still left no backup. An unknown value is stored as `abnormal`, never left out, so the ICOFR
  population stays complete.
- **Computer status.** No new status. "Unknown" in the anti-goals means the existing `expired` (no
  proven backup time) or `none`, so nothing outside `src/Veeam/` changes for Vinchin.
  - `lastBackup` is the time of the object's newest **restore point**, as Vinchin uses its newest
    backup point. A Warning run that wrote a restore point therefore counts as a backup. The restore
    point is the proof.
  - No restore point, or an unreadable time, means `lastBackup` = null, which gives `expired`.
- **Docs while building.** The builder may read Veeam's public online reference for 1.3-rev2 (the
  pages, or the swagger JSON when it is published) to write fixtures and enum tables. The user asked
  for internet research earlier. A value that is not in the reference is treated as unknown.
- **Failure reason.** The task session's `result.message`, else the session's `result.message`. A
  Veeam run's reason comes with its sessions, not from alarms.
- **Restore tests.** No Veeam import in 0.5.0, because SureBackup results over REST are unconfirmed.
  Manual restore tests still work. The Restore tests tab notes that Veeam verifications are not
  imported.
- **Times.** Veeam sends ISO 8601 with an offset or `Z` (e.g. `detectionTimeUtc`).
  - Every time is parsed with its offset and then written as `Y-m-d H:i:s` in GLPI's own timezone
    (PHP's default timezone at sync time).
  - Unlike Vinchin, whose times carry no offset and are kept as sent, nothing is dropped. So a UTC
    time does not land 7 hours off in WIB, and runs and events fall in the right audit period.
  - `BackupObject::lastBackup` is the same instant as an epoch.
  - A time without an offset is read in GLPI's timezone.

## Brand-neutral seams (refactor before Veeam)

Code found coupled to Vinchin, to be made neutral with **no behaviour change for Vinchin**:

1. **`Sync\RunSync`** imports `Vinchin\AlarmJoin`, `ItemMapper` and `RunMapper`.
   - Move the failure-reason lookup behind the source. For example, `RunHistorySource` gains a
     "reasons for these failed runs" call: Vinchin answers it from job alarms and AlarmJoin, Veeam
     from the sessions it already read.
   - Move the time helpers RunSync needs into a neutral place.
   - `jobAlarms()` may stay as a Vinchin-only method.
2. **`Vinchin\Transport` and `CurlTransport`** are generic HTTP (the error text says "Vinchin").
   Move them to a neutral namespace that both brands use, with a neutral error text.
3. **`Sync\VerifyImport`, `RestoreTest::importVinchin` and `SOURCE_VINCHIN`, and Cron's
   `method_exists($source, 'verifications')`.**
   - Replace the `method_exists` check with an interface (verification-capable source).
   - Name the import path neutrally while keeping the stored `source = 'vinchin'` value for existing
     rows.
   - Veeam does not implement the interface in 0.5.0.
4. **`Connection::showForm`** shows Vinchin's fields for every type. Each source type declares the
   fields it needs. The first-time defaults (`type`, `auth_mode`) come from the Registry.
5. **Gaps by contract.**
   - Each source states which audit sections it cannot fill and why, in one sentence per section.
   - Audit evidence prints that sentence in the section's note for that connection.
   - Its Excel sheet says it too, so an auditor sees "Not available from Veeam REST: tape" and not
     an empty sheet.
   - A source with no gaps (Vinchin) shows nothing new.
6. **Text that names Vinchin in shared code** (AssetTab size comment, ObjectStore doc, setup.php
   description "Vinchin/Veritas backup overview") becomes brand-neutral where users see it.

A test proves goal 1. It reads `src/` outside `src/Vinchin/` and `src/Veeam/` and finds no reference
to either namespace, except in `Source\Registry`.

## Veeam data, by plugin feature

The research report has the field-level tables. Here they are by where the data lands.

| Plugin feature | Veeam REST | Notes |
|---|---|---|
| Objects (`fetch()`), Computer status | `GET /backupObjects` (name, type, path, restorePointsCount) plus the newest restore point or newest successful task session per object for `lastBackup` and job name | The route to `lastBackup` is open (see below). No per-object call in the hourly path beyond a cap. |
| Runs (`RunHistorySource`) | `GET /sessions?typeFilter=BackupJob`, newest first, paged with `skip`/`limit` | Same stop-when-known paging as Vinchin, keyed on session `id`. Agent backup types are included if their type value is confirmed by the builder from the docs. |
| Per-VM results | `GET /sessions/{id}/taskSessions` | Same cap and refusal rule as Vinchin. |
| Tab 6, off-site copies | Sessions `typeFilter=BackupCopyJob` plus task sessions → Event `copy` | Restore points per VM from backupObjects if cheap, else empty. |
| Tab 7, changes, access and alarms | `/authorization/events` → Event `syslog` (needs Security Administrator; a 403 is a gap, not an error storm); `/malwareDetection/events` → Event `sysalarm` | Job operation log (`joblog`): Veeam has none, so it is a declared gap. Source IP: always '' plus a declared gap. |
| Tab 8, targets | `GET /backupInfrastructure/repositories/states` → Target | kind from the repository type table in the report; GB values shown as "N GB" text; online from `isOnline`; scale-out repositories as their extents. Tape: declared gap. |
| Tab 2, platform VMs | inventory browser joined to backupObjects | Only if the builder can do it with read-only calls and a cap. Otherwise a declared gap for 0.5.0. |
| Probe | one sample page per endpoint above, raw (redacted) | This is the installer's verification tool. Each endpoint row says "OK", "refused (role?)", or "unexpected shape: missing field X". |

## Verification without a server

- **Fixtures.** Fake transport fixtures are written from the documentation's response examples and
  schemas, with invented names (e.g. `vm-app-01`, `repo-main`). They cover:
  - each status value;
  - a missing field;
  - an unknown enum;
  - a 401 then refresh;
  - a 403 per endpoint;
  - a 400 for a wrong API version;
  - paging across pages;
  - a run still in progress.
- **Unsure-is-safe tests.** For each anti-goal, a test shows that the unsure input yields unknown or
  not stored, never OK.
- **Installer checklist.** A short doc in `docs/` covers the role to give the service account, the
  API version to choose, how to read Probe, and the research report's 17 open points, rewritten as
  "what to look for in Probe". It is not in `docs/manual/`.
- **Vinchin regression.** The full existing suite passes, plus the goal-1 test.

## Open for the builder

- How each object's newest restore point time and its job name are found cheaply. One route is the
  newest restore points sorted by time, with the job name from `/backups` or `/jobs/states`. Pick
  one that is cheap per sync, and that leaves `lastBackup` null when unsure.
- Whether platform VMs (tab 2) make 0.5.0 or become a declared gap.
- Exact interface names and method signatures for the seams, and how a source declares its
  connection-form fields and its audit gaps.
- Whether the Veeam client refreshes or simply logs in again on a 401.
- Schema change for the API version field, as an installer migration in the usual style.
- The order of commits. The seams go first, with Vinchin regression green, then Veeam.

## Release

- Version `0.5.0-rc1` on the same branch.
- CHANGELOG entry: brand-neutral sources, Veeam preview, the gaps listed.
- The zip goes through `tools/build_zip.py` as usual. No push until the user approves; 0.4.0 final
  comes first, or the two are released together, as the user decides.
