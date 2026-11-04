# Plan: Backup Info 0.3.0, audit evidence (stage 2)

Spec: `docs/superpowers/specs/2026-11-03-backupinfo-audit-evidence-design.md`. The user approved it on 2026-11-04
("1 dan 2 ok"), including these defaults: Problem after 3 failures in 30 days, runs kept 36 months, stage 2 =
0.3.0 and stage 3 = 0.4.0. **This plan covers stage 2 only**; stage 3 (Incidents, Problems, Findings) gets
its own plan after 0.3.0 is confirmed on the VM.

Branch `feat/v0.3-audit`. Base: `3aef21e`, which is 0.3.0-rc1 plus the spec plus the fixture clean-up.
Release target: `0.3.0-rc2` (Task 9 is Claude's, not OpenCode's).

## Ground rules (in addition to HANDOFF §4)

- **Fixtures are invented.** Use `VM-A`, `JOB-1`, `10.0.0.x`, `uuid-1`. Never paste a VM name, IP, user or
  description from a screenshot or probe into a test or docblock (hub §4; every pasted value became a scrub rule).
- **Pure core, thin GLPI shell.** Same split as `SyncService` / `DbObjectStore`. The logic lives in pure classes
  tested offline with fakes; `Db*` classes and `front/*.php` only adapt.
- **Every Vinchin description is HTML.** One helper, `Text::plain(string $html, int $max): string`, does:
  `strip_tags`, then `html_entity_decode`, then collapse whitespace, then trim, then `mb_substr` to `$max`.
  Use it everywhere.
- **Vinchin times are local wall-clock strings** (`"2026-07-31 00:05:09"`). Parse them the same way
  `JobHistoryMapper` does today and store them as GLPI `timestamp` columns. Do not convert them twice.
- **Append-only evidence.** Code never updates or deletes a stored run, except the retention purge in Task 4.

## Task 1: Schema and config (install, uninstall, schema_test)

New tables. Each is created when missing; install must be safe to run again.

- `glpi_plugin_backupinfo_runs`
  - Columns:
    - `id`
    - `connections_id`
    - `history_uuid` varchar(64)
    - `job_name` varchar(255)
    - `job_uuid` varchar(64)
    - `start_time` timestamp NULL
    - `finish_time` timestamp NULL
    - `status` varchar(20): `success`, `failed`, `aborted` or `abnormal`
    - `status_text` varchar(64): as Vinchin wrote it
    - `size_text` varchar(64)
    - `duration_s` int unsigned NULL
    - `reason` text NULL
    - `date_creation` timestamp NULL
  - Keys: UNIQUE `(connections_id, history_uuid)`; KEY `(start_time)`; KEY `(status)`.
- `glpi_plugin_backupinfo_runs_items`
  - Columns:
    - `id`
    - `runs_id`
    - `computers_id`
    - `vm_name` varchar(255)
    - `item_status` varchar(20)
    - `tickets_id` int unsigned 0
    - `problems_id` int unsigned 0
  - The last two are for stage 3; create them now so 0.4.0 needs no ALTER.
  - Keys: UNIQUE `(runs_id, computers_id)`; KEY `(computers_id)`.
- `glpi_plugin_backupinfo_restoretests`
  - Columns:
    - `id`
    - `entities_id`
    - `is_recursive` tinyint 0
    - `computers_id`
    - `date` date
    - `method` varchar(30): `vinchin_verify`, `file_restore`, `vm_restore` or `dr_drill`
    - `result` varchar(20): `pass`, `partial` or `fail`
    - `rto_minutes` int unsigned NULL
    - `users_id`
    - `source` varchar(20): `manual` or `vinchin`
    - `source_ref` varchar(128) NULL: the Vinchin verify row id, for dedupe
    - `comment` text
    - `date_creation`
    - `date_mod`
  - Keys: KEY `(computers_id, date)`; UNIQUE `(source, source_ref)`. A NULL `source_ref` does not collide.
- `glpi_plugin_backupinfo_restoreintervals`
  - Columns: `id`, `computers_id` (UNIQUE), `months` smallint unsigned. A value of 0 means exempt.

Also:
- New config defaults (only keys that are not set yet):
  - `run_pages_max` 20
  - `run_keep_months` 36
  - `restore_interval_months` 12
- `uninstall.php` drops the four new tables.
- `schema_test` asserts each table, the unique keys and the defaults, and that install has no unconditional DROP.

## Task 2: Text helper, run status mapping, alarm join (pure)

- `src/Text.php`: `Text::plain()` as above.
  - Test: tags removed; `&quot;` decoded; whitespace collapsed; multibyte cut; `''` gives `''`.
- `src/Vinchin/RunMapper.php`: `map(array $row): ?Run`. `Run` is a readonly value class in `src/Source/Run.php`:
  - Fields: `historyUuid`, `jobName`, `jobUuid`, `start` (?string, `Y-m-d H:i:s`), `finish`, `status`,
    `statusText`, `sizeText`, `durationS`.
  - Returns null when `history_uuid` is empty.
  - Status comes from the **text** (`job_status`); the sample's `job_status_value` 3005 shows the number is not
    the doc's 1-4:
    - matches `JobHistoryMapper::FAILED` → `failed`, except that `terminat|abort|cancel|stop` → `aborted`
      (checked first);
    - matches `abnormal` → `abnormal`;
    - matches `SUCCESS` → `success`;
    - anything else → `abnormal`, and the text is kept in `statusText`.
  - Skips rows whose `job_type` matches `JobHistoryMapper::SKIP` (restore, verify, ...).
  - `durationS` comes from `execution_duration` `HH:MM:SS`, or null.
  - Expose `FAILED`, `SUCCESS` and `SKIP` from `JobHistoryMapper` as public constants rather than copying them.
- `src/Vinchin/AlarmJoin.php`: `reasons(list<Run> $failed, list<array> $alarms, int $toleranceS = 60): array<string historyUuid, string reason>`.
  - An alarm matches when its `job_name` equals the run's job name (case-insensitive, trimmed) and
    |`alarm_time` − `finish_time`| ≤ tolerance. The nearest alarm wins.
  - The reason is `Text::plain(description, 1000)`.
  - A run with no matching alarm has no entry; it is never guessed.
  - Tests:
    - exact second;
    - 61 s → no match;
    - two alarms → the nearest wins;
    - other job → no match;
    - HTML stripped.

## Task 3: Vinchin reads for runs (`RunHistorySource`)

New interface `src/Source/RunHistorySource.php`, implemented by `VinchinSource`:

```php
/** Newest first. Stops when $isKnown says every run of a page is already stored, or after $maxPages. */
public function runs(callable $isKnown, int $maxPages, int $pageSize = 100): array; // list<Run>
/** Per-VM result of one run (GET /jobs/history/{uuid}): list<array{vm_name:string,status:string,error:string}> */
public function runItems(string $historyUuid): array;
/** Job alarms newest first, back to $sinceTime (Y-m-d H:i:s), at most $max rows. */
public function jobAlarms(string $sinceTime, int $max = 2000): array;
```

- `runs`: `GET /jobs/history`, `sort=start_time`, `order=desc`, offset paging through `Client`. Uses
  `$client->get()` per page, because `getPaged` has no "stop when the page is known" hook. Add a stop callable to
  `getPaged` if that is cleaner.
- `runItems`: the doc says `data.list[]` with `vm_name`, `task_status`, `error_code`.
  - The field set differs per module. Read `vm_name` (or `display_name`, or `db_name`).
  - Map `task_status` through the same status mapping.
  - An empty list is fine.
  - **Not sampled on the VM yet.** Task 8 adds it to the audit probe, and the code must tolerate any shape:
    unknown → `[]` plus an error line, never a throw that stops the run sync.
- `jobAlarms`: `GET /alarm/job`, `sort=alarm_time`, `order=desc`; stop at the first row older than `$sinceTime`.
- Tests use the `RouteT` fake from `audit_probe_test.php` (copy it into a shared `tests/fakes.php` if you need it
  twice):
  - paging stops on a known page;
  - `maxPages` is respected;
  - items with `vm_name` or `display_name`;
  - a bad shape gives `[]` plus an error;
  - alarms stop at `sinceTime`.

## Task 4: Run store and run sync

- `src/Sync/RunStore.php` (interface) and `src/Sync/DbRunStore.php`:
  - `known(int $conn, list<string> $uuids): array<string,bool>`
  - `insert(int $conn, Run $run, string $reason, list<array{computers_id:int,vm_name:string,item_status:string}> $items): int`
    - `INSERT` only. A duplicate key is a no-op that returns 0, never an update.
  - `purgeOlderThan(int $months, int $nowS): int`
    - Deletes runs whose `start_time` is older than the cut-off, plus their items. A value of 0 means never.
- `src/Sync/RunSync.php` (pure):
  - `run(int $conn, RunHistorySource $src, RunStore $store, callable $vmToComputer, callable $jobComputers, int $maxPages): array{new:int,failed:int,errors:list<string>}`.
  - Steps:
    1. `runs = $src->runs(fn(page) => all known)`. Keep only the unknown ones.
    2. For failed runs: alarms since the oldest failed start minus 1 day, then `AlarmJoin::reasons`.
    3. Items:
       - Failed run: `runItems(uuid)`, with each `vm_name` mapped by `$vmToComputer(name): int` (0 = not
         matched; keep the row with `computers_id` 0). If `runItems` gives nothing, fall back to
         `$jobComputers(jobName)`.
       - Other runs: `$jobComputers(jobName)` (no API call per successful run).
    4. Insert each run with its items.
  - `$vmToComputer`: look up the connection's stored objects by name (case-insensitive) and take
    `items_id` where `itemtype = Computer`.
  - `$jobComputers`: stored objects whose `job_name` list contains the job. `job_name` holds `", "`-joined names;
    split it, never `LIKE`.
  - Both callables are built in `Cron` from `DbObjectStore::matched()` (add a method if needed).
- `Cron::runSync`:
  - After a connection's object sync returns `ok` and its source is a `RunHistorySource`, run `RunSync`.
  - A throw there is caught, logged as `Runs <conn>: <error>` and does **not** change the connection status.
  - Log line: `<conn>: runs stored N (failed F)`.
  - Then `purgeOlderThan(run_keep_months)`, once per cron run.
- Tests (offline, fakes):
  - first run backfills;
  - a second run inserts 0;
  - failed run → `runItems` called, VM mapped, reason joined;
  - success run → no `runItems` call;
  - `runItems` empty → job fallback;
  - a throw in `runItems` → error line, the run is still stored without items;
  - duplicate insert → 0;
  - purge with 0 → nothing.

## Task 5: Restore tests (itemtype, interval, rights)

- `src/RestoreTest.php`: `CommonDBTM`.
  - Table `glpi_plugin_backupinfo_restoretests`; `$rightname = 'plugin_backupinfo_restoretest'`.
  - `prepareInputForAdd`/`Update`:
    - `computers_id` is required and must exist;
    - `entities_id` comes from the Computer;
    - `source` is forced to `manual`;
    - `method`/`result` must be in the lists;
    - `date` must be a date, not in the future;
    - `users_id` defaults to the session user.
  - Rows with `source = vinchin` are read-only: `canUpdateItem`/`canPurgeItem` return false.
  - `defineTabs`: main plus `Document_Item` (documents) and `Log`. `rawSearchOptions` for the list.
  - Form: `showForm` with the standard Twig generic form, or `CommonDBTM::showFormHeader` the way
    `Connection.php` does. Follow `Connection`.
- `front/restoretest.php` (search list) and `front/restoretest.form.php` (the standard add/update/purge
  controller, as `connection.form.php` does).
- `src/RestoreInterval.php` (pure plus a small DB part):
  - `effective(int $global, ?int $override): int`
  - `status(?string $lastDate, int $months, int $nowS): string`: `exempt` (months 0), `none` (no test), `ok`,
    `due` (within 30 days of the due date) or `overdue`.
  - `nextDue(?string $lastDate, int $months): ?string`.
  - DB part: `get(int $computerId): ?int` and `set(int $computerId, ?int $months)` (null deletes the
    override).
- Right: `RightDefinition::RESTORETEST = 'plugin_backupinfo_restoretest'`.
  - It goes in the Profile matrix (READ, CREATE, UPDATE, PURGE).
  - `Profile::install` gives Super-Admin `ALLSTANDARDRIGHT`.
  - It runs on every install and only touches Super-Admin, as today.
- Asset tab: a "Restore tests" block in `AssetTab::render`, under the backup objects. It shows:
  - the effective interval and its status badge;
  - the next due date;
  - the last 5 tests (date, method, result, source);
  - an "Add restore test" link to the form with `computers_id` prefilled, if the user has CREATE;
  - for users with CONFIG UPDATE, a small form to set the per-Computer interval (blank = global, 0 = exempt).
  - Entity: only rows the user can see.
- Setup (`SuiteConfig`): `restore_interval_months` (0–120) and `run_keep_months` (0–120, 0 = keep forever),
  sanitized like the RPO inputs.
- Tests:
  - `RestoreInterval` status table (exempt, none, ok, due, overdue, boundary days);
  - effective override;
  - input validation (future date refused, bad method refused, `source` forced);
  - ui_wiring: the form controller checks rights, CSRF comes from the GLPI 11 form, the asset tab block exists.

## Task 6: Live audit reads (`AuditSource`)

New interface `src/Source/AuditSource.php`, implemented by `VinchinSource`. Each method returns
`array{rows: list<array>, error: string}` and never throws:

- `platformVms()`: `GET /backupmanager/report/vm`, all pages (max 5000). Rows `vm_name`, `last_backup_time`,
  `backup_status`, `backup_count`.
- `copyRuns(string $from, string $to)`: `GET /copy/job/history` per copy or replica job found in `/jobs`; rows in the
  period. Flatten `details.info[]` to one row per item: item_name with any trailing `"(...)"` removed,
  item_status, timepoint_count, start, end.
- `jobLogs(string $from, string $to)`, `systemLogs(...)` and `systemAlarms(...)`: newest first, stop at `$from`,
  max 2000 rows. Descriptions go through `Text::plain(…, 500)`. **No search keyword** on `/logs/system`.
- Tests with `RouteT`:
  - period cut;
  - item flattening and suffix strip;
  - one endpoint refused → that method's `error` set, the others fine.

## Task 7: Audit page and CSV export

- `src/AuditPeriod.php` (pure):
  - `parse(string $p, int $nowS): array{from,to,label}` for `YYYY-MM`, `YYYY-Qn` and `YYYY`; the default is the
    last full month.
  - `to` is inclusive at day end. Bad input → default.
- `src/AuditPage.php` (pure builders, one per section, taking plain arrays):
  1. `policy(objects, rpo)`
  2. `coverage(computers, objectsByItem, rpo, platformVms)`
  3. `population(runsRows)`
  4. `failures(runsRows)`
  5. `restore(computers, tests, intervals, now)`
  6. `copies(copyRows, computersByName)`
  7. `activity(jobLogs, systemLogs, systemAlarms)`
  - Each returns `array{columns: list<string>, rows: list<list<scalar>>, note: string}`.
- `src/Csv.php`:
  - `render(array $meta, array $section): string`, UTF-8 with BOM, via `fputcsv` on `php://temp`.
  - Header lines first (`Period`, `Generated at`, `Generated by`, `Connection(s)`, `Plugin version`), then a blank
    line, then the table.
  - **Formula injection:** a cell starting with `=`, `+`, `-`, `@`, tab or CR gets a leading `'`.
  - Test: every one of those characters, plus a normal negative number shown as text.
- `front/audit.php`:
  - right `REPORT` READ; `renderSubNav('backupinfo','audit')`; period picker (GET) and tabs or anchors per section.
  - **In scope** = mandatory Computers (`Policy::fromConfig()->mandatoryIds()`), restricted to the user's
    entities (`getEntitiesRestrictCriteria`, as `coverage.php` does).
  - Sections 3–5 come from DB tables filtered to the in-scope Computers; 1, 2, 6 and 7 are live through
    `AuditSource` on each active connection.
  - A live error shows as a warning in its section only.
  - `?export=<section>` streams the CSV (`Content-Disposition: attachment; filename=backupinfo-<section>-<label>.csv`)
    and exits before any HTML.
  - Rows on screen are capped at 500 per section, with a "showing 500 of N, export for all" note; the CSV has
    all rows.
- `Menu::entry`: an `audit` option (title "Audit evidence", icon `ti ti-file-certificate`), behind `REPORT` READ.
- Tests:
  - period parse table;
  - each builder with small fixtures (population lines = runs × Computers; failures only `failed`; restore status
    per Computer; copies matched by stripped name);
  - CSV header and BOM;
  - ui_wiring: export happens before `Html::header`, `renderSubNav` present, the page writes nothing.

## Task 8: Vinchin verification import and probe additions

- `ItemMapper`: keep the items-list `timepoint_uuid` on `BackupObject`, as `?string $timepointUuid`, with
  `withSchedule` updated.
  - Store it in a new `glpi_plugin_backupinfo_objects.timepoint_uuid` varchar(64) NULL, through an ALTER behind
    `fieldExists`, as `schedule_hours` did.
- `VinchinSource::verifications(string $timepointUuid): array` (rows of `/backup_data/verify_history`).
- In `Cron` after `RunSync`: for each matched, **in-scope** object with a `timepoint_uuid`, at most 200 per cron
  run:
  - read its verifications;
  - insert each new one as a `RestoreTest` row: `source=vinchin`, `source_ref=<verify id>`,
    `method=vinchin_verify`, result pass/fail from its status fields, date from its time.
  - Insert through the class (`add()`) so Log and entity rules apply; the unique key prevents doubles.
  - Unknown shape → skip and log; never fail the sync.
- Audit probe: add a `/jobs/history/{uuid}` sample using the first failed run's `history_uuid`. Otherwise the note
  "…: skipped, no failed run in the sample".
- Tests:
  - verify rows → RestoreTest inputs (pass/fail mapping, dedupe ref);
  - 200 cap;
  - the probe calls `/jobs/history/uuid-1`.

## Task 9 (Claude): review, release 0.3.0-rc2

Steps: review every task diff; run the suite yourself; `/code-review low` is not required (not a major); bump the
version to `0.3.0-rc2`; add a CHANGELOG entry; run `python -I tools/build_zip.py`; give the user the VM test steps.

## VM test outline (for the rc2 message)

1. Upgrade. The cron Sync log shows `runs stored N (failed F)`. A second run shows `runs stored 0`.
2. Audit page, last month: 7 sections; the population count looks right against Vinchin's job history;
   failures show a reason.
3. Export each CSV and open it in Excel: Indonesian characters intact, and no cell starts with `=`.
4. Add a manual restore test on one Wajib Backup Computer and attach a document. The asset tab status turns `ok`.
   Set a per-Computer interval of 3 months and check the status and next-due change.
5. Probe › Sample audit endpoints: the new `/jobs/history/{uuid}` card (send a screenshot).
