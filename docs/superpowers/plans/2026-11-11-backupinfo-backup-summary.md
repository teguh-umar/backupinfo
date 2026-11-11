# Backup summary on the Backup objects page: implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Summary cards, a "Last backup age" bar, three side cards, an Age column, a Needs attention toggle, a source switch and an Excel export (Summary + Objects sheets) on the existing Backup objects page, plus a "Backup summary" menu entry.

**Architecture:**
- A pure model `Summary` turns the visible object rows, their newest runs, the RPO, "now" and the per-type gaps into card data. It is unit-tested without GLPI.
- A pure renderer `SummaryView` turns that model into HTML.
- `front/objects.php` loads every visible row of the chosen source once. It feeds all of them to `Summary` and filters the table rows in PHP (state, age, attention). It also serves `?export=xlsx` through the existing `Xlsx` writer.

**Tech Stack:** PHP 8, GLPI 11, Tabler (cards, badges, `progress`), the plugin's own test runner (`tests/run_all.php`, `check()`/`done()` from `tests/bootstrap.php`).

**Spec:** `docs/superpowers/specs/2026-11-11-backupinfo-backup-summary-design.md`

**Branch:** `feat/backup-summary` (cut from `feat/v0.5-veeam` at v0.5.0). **Do not bump `setup.php`.** The version number is the user's open decision. CHANGELOG gets an `## Unreleased` entry.

## Global Constraints

- GLPI 11, PHP 8. No Composer dependencies beyond what GLPI ships. Reuse `AuditTable`/`TableUi` for the table and `Xlsx` for the export.
- No live call to any backup system on page load or export. Read only plugin tables and GLPI tables.
- No chart library. Bars are Tabler `progress` and plain CSS.
- Do not change how the sync stores objects, and do not change `Status::of()` / `Rpo`.
- Do not change `Status::KIND_LABELS` values. They feed the "Backup Media" Fields text, and a change writes history on every Computer. Card labels use them as they are (`File`, not "File / NAS").
- Visibility as today: rows with a Computer follow the user's active entities. Rows without a Computer only for config READ. **Cards count only the rows this user can see.** The export follows the same rule.
- Rights: the page keeps `RightDefinition::VIEW` READ. Pin/unpin keep `RightDefinition::CONFIG` UPDATE. The export needs VIEW READ.
- Brand-neutral: no brand names in page code paths. "Not reported" facts come from each source class's `GAPS`.
- Data hygiene:
  - Tests use invented names only (`vm-app-01`, `vm-db-01`, `JOB-DAILY`) and documentation IPs (192.0.2.x).
  - Nothing from the reference GIF or the user's screenshots.
- Test command, which must end with `SUITES OK`:
  `php -d extension_dir=C:/Users/<user>/Downloads/php/ext -d extension=openssl -d extension=curl -d extension=mbstring tests/run_all.php`
- After each code commit: `graphify update .` (AST only).
- User-visible strings in English through `__('…', 'backupinfo')`. Bar name **"Last backup age"**; buckets **"≤ 24 hours", "1–3 days", "3–7 days", "> 7 days", "No timestamp"**.

## Review Focus

1. **A size string the parser does not know** (`""`, `"n/a"`, `"1,5 GB"`, `"12"`, `"0B"`). It must count 0 in totals, never throw, and the table keeps the stored text. Owner: Task 1 tests.
2. **A `last_backup` in the future or unparseable** (clock skew, `0000-00-00`). Future means age 0, so it lands in "≤ 24 hours". Unparseable means "No timestamp" plus the attention reason "no backup". Owner: Task 1 tests.
3. **No visible rows at all** (new install, or a user without config READ and no matched objects). The cards render zeros and "—", with no division by zero, and the export still writes both sheets with headers. Owner: Task 1 and Task 3 tests.
4. **A `source` parameter for a connection the user cannot use** (inactive, deleted, or not an int). Fall back to All, silently. Owner: Task 4 test.
5. **Object and job names with HTML or formula text** (`<b>x</b>`, `=1+1`). Escaped in cards and lists. Written as text in Excel by the existing `Xlsx` safety. Owner: Task 2 test (escaping); `Xlsx` is already covered.

---

### Task 1: `Summary` model (pure)

**Files:**
- Create: `src/Summary.php`
- Modify: `src/Status.php`: add `public static function kindLabel(string $kind): string`, reading the existing private `KIND_LABELS` (unknown or empty kind → `'Other'`).
- Modify: `src/Veeam/VeeamSource.php` `GAPS`: add `'size' => 'Size is not reported by this source.'` and `'chain' => 'Broken backup chains and deleted sources are not reported by this source.'`.
- Modify: `src/Vinchin/VinchinSource.php`: none (`GAPS = []`).
- Test: `tests/summary_test.php` (new; require `src/Rpo.php`, `src/Status.php`, `src/Summary.php` after `bootstrap.php`).

**Interfaces:**
- Produces, all `public static` on `final class Summary` in namespace `GlpiPlugin\Backupinfo`:
  - `const AGE_BUCKETS = ['day' => '≤ 24 hours', 'days3' => '1–3 days', 'days7' => '3–7 days', 'older' => '> 7 days', 'none' => 'No timestamp'];`
  - `const REASONS = ['chain' => 'Broken chain', 'deleted' => 'Source deleted', 'expired' => 'Older than RPO', 'failed' => 'Last run failed', 'never' => 'No backup'];` Order = severity for sorting: chain, deleted, failed, never, expired.
  - `bytes(string $size): int`. Parses `"683.47GB"`, `"317.5 GB"`, `"2.9 KB"`, `"0B"`, `"1 TB"` with 1024 steps (B, KB, MB, GB, TB, PB, case-insensitive, optional space, decimal point only). Anything else → 0.
  - `human(int $bytes): string`. 1024 steps, one decimal, e.g. `"1.1 PB"`, `"22.0 GB"`, `"0 B"`.
  - `ageBucket(?string $lastBackup, int $nowS): string`. One of the `AGE_BUCKETS` keys. Bounds: ≤ 86400 s → `day`; ≤ 3×86400 → `days3`; ≤ 7×86400 → `days7`; else `older`. Null, empty or unparseable → `none`. A future time → `day`.
  - `reasons(array $object, ?array $run, Rpo $rpo, int $nowS): list<string>`. `REASONS` keys in severity order:
    - `chain` when `abnormal_chain` = 1;
    - `deleted` when `source_deleted` = 1;
    - `failed` when `$run !== null` and `ObjectsView::result($run)` is `failed` or `fail`;
    - `never` when the age bucket is `none`;
    - `expired` when the last backup is older than `$rpo->hoursFor($object)` hours.
  - `build(array $objects, array $last, Rpo $rpo, int $nowS, array $gapsByConnection): array`.
    - `$objects` are page rows. They carry `id`, `connections_id`, `connection_name`, `connection_active` (0/1), `name`, `kind`, `job_name`, `last_backup`, `restore_points`, `data_size`, `abnormal_chain`, `source_deleted`, `items_id`, `schedule_hours`.
    - `$last` is `ObjectsView::lastRuns()` output, keyed `connections_id|lower(name)`.
    - `$gapsByConnection` is `int connection id => Registry::gaps(type)`.
    - Rows with `connection_active` = 0 are skipped.
    - Returns:
    ```php
    [
      'count' => int, 'kinds' => int, 'sources' => int,
      'fresh' => int, 'freshPct' => float|null,          // null when count = 0
      'bytes' => int, 'points' => int, 'pointsAvg' => float|null,
      'matched' => int,                                   // items_id > 0
      'age' => ['day' => int, 'days3' => int, 'days7' => int, 'older' => int, 'none' => int],
      'byKind' => list<['kind' => string, 'label' => string, 'count' => int, 'bytes' => int, 'freshPct' => float]>, // count desc
      'attention' => list<['id' => int, 'name' => string, 'job' => string, 'ageDays' => int|null, 'reasons' => list<string>]>, // worst reason, then oldest
      'attentionIds' => array<int, true>,
      'reasonCounts' => array<string, int>,
      'largest' => list<['id' => int, 'name' => string, 'bytes' => int]>,  // top 10, bytes > 0 only
      'notes' => list<string>, // "Size not reported by: <connection names>." and "Broken chain and source deleted not checked for: <names>." when any counted connection's gaps hold 'size' / 'chain'
    ]
    ```

- [ ] **Step 1: Write the failing tests** in `tests/summary_test.php`:
  - `check('bytes: Vinchin style', Summary::bytes('683.47GB') === (int) round(683.47 * 1024**3));`
  - `bytes`: `'317.5 GB'`, `'2.9 KB'` and `'0B'` (→ 0) are each right.
  - Review Focus 1: `''`, `'n/a'`, `'1,5 GB'` and `'12'` each give 0.
  - `human(0) === '0 B'`, `human(1024**3 * 22) === '22.0 GB'`, `human((int) (1.1 * 1024**5)) === '1.1 PB'`.
  - `ageBucket`, with `$now = 1767225600`, at `$now - 3600` gives `day`. At `-86400` it gives `day`, and at `-86401` `days3`. At `-3*86400-1` it gives `days7`, and at `-7*86400-1` `older`.
  - Review Focus 2: `ageBucket(null, …)`, `ageBucket('0000-00-00 00:00:00', …)` and `ageBucket('garbage', …)` each give `none`. A future date (`$now + 3600`) gives `day`.
  - `reasons`: with `abnormal_chain` = 1 and `source_deleted` = 1 and a 400-day-old backup it gives `['chain','deleted','expired']`. With a failed run and an old backup it gives `['failed','expired']`. A fresh object with a success run gives `[]`. No `last_backup` gives `['never']`.
  - `reasons` respects the RPO: with `schedule_hours` = 336 and grace 24, a backup 300 h old is not `expired`, and one 400 h old is.
  - `build`: three active rows (`vm-app-01` vm 1 GB fresh; `vm-db-01` database `''` size 10 days old; `vm-web-01` vm 2 GB, chain broken) plus one row with `connection_active` = 0. The result has `count` 3 and `kinds` 2. `bytes` is 3 GiB. `age['day']` is 1 and `age['older']` is 2. `attention` holds 2 rows with the chain row first. `largest` holds 2 rows, `vm-web-01` first. `matched` counts rows whose `items_id` > 0.
  - Review Focus 3: `build([], [], $rpo, $now, [])` gives `count` 0, `freshPct` null, `pointsAvg` null, every `age` bucket 0, and `attention === []`.
  - `build` notes: when `gapsByConnection[2]` has `'size'` and connection 2 is named `veeam-test`, `notes` contains `'Size not reported by: veeam-test.'`.
  - `Status::kindLabel('file') === 'File'`, `kindLabel('') === 'Other'`, `kindLabel('weird') === 'Other'`.
  - `VeeamSource::GAPS` has the keys `size` and `chain`.
- [ ] **Step 2: Run it.** Command: `php … tests/summary_test.php`. Expected: fatal "Class … Summary not found".
- [ ] **Step 3: Implement `src/Summary.php`, `Status::kindLabel()`, and the two Veeam `GAPS` keys** to the interfaces above. `ObjectsView::result()` is reused for run results: require `src/ObjectsView.php` in the test.
- [ ] **Step 4: Run `tests/summary_test.php`, then the full suite.** Expected: `ALL PASS`, then `SUITES OK`.
- [ ] **Step 5: Commit** `feat(summary): pure model for the backup summary cards (age buckets, attention reasons, sizes)`, then run `graphify update .`.

### Task 2: `SummaryView` renderer (pure HTML)

**Files:**
- Create: `src/SummaryView.php`
- Test: `tests/summary_view_test.php` (stubs `__`/`htmlescape` come from `bootstrap.php`; check that they exist there and add a stub only if one is missing).

**Interfaces:**
- Consumes: `Summary::build()` output, `Summary::AGE_BUCKETS`, `Summary::REASONS`, `Summary::human()`.
- Produces: `final class SummaryView`:
  - `public static function render(array $model, array $sources, int $activeSource, string $baseUrl, array $ageOn, ?string $lastSync): string`.
    - `$sources` is `int id => name` (active connections only).
    - `$activeSource` = 0 means All.
    - `$ageOn` lists the bucket keys currently filtering the table.
    - `$lastSync` is the oldest `last_sync` among the chosen connections, or null.
  - The output is one `<div id="backupinfo-summary">`, holding in order:
    1. A source button group, `btn-group` with `btn btn-outline-secondary` and `active` on the current one. The links are `$baseUrl . '?state=all&source=' . $id` (`source=0` for All).
    2. A right-aligned muted line: "Last synced %s · %d of %d matched to a Computer". It reads "never synced" when `$lastSync` is null.
    3. A row of 5 Tabler cards: Protected objects, Fresh backups, Total data, Restore points, Needs attention.
       - Each card's sub-line follows spec §2.2.
       - Fresh % and the restore-point average show "—" when null.
       - The Needs attention sub-line lists each `REASONS` label with its count, skipping zeros.
    4. A **"Last backup age"** card with one `progress` stack.
       - One `progress-bar` per non-zero bucket, `width:%.1f%%`, coloured `bg-success`, `bg-lime`, `bg-warning`, `bg-danger` and `bg-secondary` in `AGE_BUCKETS` order.
       - Each segment is an `<a>` to `$baseUrl . '?state=all&source=' . $activeSource . '&age[]=' . $key`. A segment already in `$ageOn` gets `opacity-50` on the others.
       - A legend lists label, count and %. The muted hint reads "Click a segment to filter the table".
    5. Three cards in a `row`:
       - **By kind**: label, `count · human(bytes)`, and a `progress` of `freshPct` with the text "N% ≤ 24 hours".
       - **Needs attention**: a list in a `max-height:22rem; overflow:auto` box. Each line shows the name, then "Last backup N days ago · job" ("No backup" when `ageDays` is null) and one badge per reason.
       - **Largest objects**: name, `human(bytes)`, and a `progress` against the first row.
    6. `$model['notes']` as one `alert alert-info` with one line per note.
  - Every object, job, connection and note text goes through `htmlescape()`.

- [ ] **Step 1: Write the failing tests** in `tests/summary_view_test.php`. Build a model with `Summary::build()` from invented rows, then:
  - the output contains `id="backupinfo-summary"`, `Last backup age`, `Needs attention`, `Largest objects` and `By kind`;
  - a segment link contains `age[]=older` and `source=0`;
  - the source buttons hold one `active` link, and it is All when `$activeSource` = 0;
  - Review Focus 5: an object named `<b>x</b>` appears as `&lt;b&gt;x&lt;/b&gt;` and never as `<b>x</b>`;
  - Review Focus 3: the empty model renders "—" for Fresh backups and has no `progress-bar` with a width of `NAN` or `INF`;
  - a model whose `notes` holds `'Size not reported by: veeam-test.'` renders it inside `alert-info`.
- [ ] **Step 2: Run it.** Expected: fatal "Class … SummaryView not found".
- [ ] **Step 3: Implement `SummaryView::render()`** to the interface above.
- [ ] **Step 4: Run it, then the full suite.** Expected: `ALL PASS`, `SUITES OK`.
- [ ] **Step 5: Commit** `feat(summary): HTML for the summary cards, age bar and side lists`, then run `graphify update .`.

### Task 3: Table changes (Age column, state/age/attention filters in PHP)

**Files:**
- Modify: `src/ObjectsView.php`:
  - insert `'Age'` after `'Last backup'` in `COLUMNS`;
  - shift the constants `COL_RESULT` 9, `COL_RESTORE` 10, `COL_COMPUTER` 13, `COL_ACTION` 14;
  - `rows()` gains a trailing parameter `int $nowS`. It fills Age with `Summary::AGE_BUCKETS[Summary::ageBucket($o['last_backup'], $nowS)]`;
  - add `public static function select(array $objects, ?array $states, array $ages, bool $attentionOnly, array $attentionIds, int $nowS): array`. It keeps the rows whose `match_state` is in `$states` (null = all), whose age bucket is in `$ages` (empty = all), and, when `$attentionOnly`, whose `id` is in `$attentionIds`.
- Modify: `tests/objects_view_test.php`: pass the new `$nowS` argument, and add the column and `select` checks.

**Interfaces:**
- Consumes: `Summary::ageBucket`, `Summary::AGE_BUCKETS`.
- Produces: `ObjectsView::rows(array $objects, array $last, array $tests, array $types, array $matchLabels, int $nowS): array` and `ObjectsView::select(...)` as above, used by Task 4.

- [ ] **Step 1: Write the failing tests:**
  - `ObjectsView::COLUMNS[5] === 'Age'`;
  - the index check still names `Result`, `Restore test`, `Computer` and `Action` (that line stays, with constants);
  - `rows(...)` gives `'> 7 days'` in column 5 for a 10-day-old backup;
  - `select` with `['review','unmatched']` keeps only those states;
  - `select` with `ages = ['none']` keeps only rows without `last_backup`;
  - `select` with `attentionOnly` true and `attentionIds = [2 => true]` keeps only id 2;
  - every filter empty or null returns all rows unchanged.
- [ ] **Step 2: Run `tests/objects_view_test.php`.** Expected: FAIL on the new checks.
- [ ] **Step 3: Implement `rows()`, `select()` and the constants.**
- [ ] **Step 4: Run the full suite.** Expected: `SUITES OK`. The `front/objects.php` source checks in `objects_view_test.php` still pass, because they use the constants.
- [ ] **Step 5: Commit** `feat(objects): Age column; state, age and attention filters in PHP`, then run `graphify update .`.

### Task 4: Wire the page, the source switch, the toggle, the rename and the menu entry

**Files:**
- Modify: `front/objects.php`
- Modify: `src/Menu.php`: a new `$options['summary']` **before** `'objects'`, same VIEW READ guard, title `__('Backup summary', 'backupinfo')`, page `/plugins/backupinfo/front/objects.php?state=all#backupinfo-summary`, icon `ti ti-chart-bar`.
- Test: `tests/objects_view_test.php`, with source checks on `front/objects.php` and `src/Menu.php`, in the existing style.

**Interfaces:**
- Consumes: Task 1 `Summary::build`, Task 2 `SummaryView::render`, Task 3 `ObjectsView::rows/select`, `Registry::gaps`, `Rpo::fromConfig()`.
- Produces:
  - query parameters `source` (int, 0 = All), `age[]` (bucket keys), `attention` (`1` = on) and `state` (unchanged keys);
  - the anchor `#backupinfo-summary`;
  - `export=xlsx`, which Task 5 serves.

Page changes:
1. **Load every visible row once.**
   - Drop the `match_state` condition from the SQL.
   - Add `glpi_plugin_backupinfo_connections.is_active AS connection_active` to the SELECT.
   - When `source` > 0, add `$table.connections_id = source`.
2. **`source`.** Accept it only if it is an int that names an **active** connection, read once from `glpi_plugin_backupinfo_connections` (`is_active` = 1, `id`, `name`, `type`, `last_sync`). Anything else becomes 0 (Review Focus 4).
3. **Run the model.** `$model = Summary::build($objects, $last, Rpo::fromConfig(), time(), $gapsByConnection)`, where `$gapsByConnection[id] = Registry::gaps(type)` for the active connections.
4. **Filter the table rows in PHP** with `ObjectsView::select($objects, $states[$state][1], $ages, $attentionOn, $model['attentionIds'], $now)`, before `ObjectsView::rows(...)`.
   - `$ages` is `$_GET['age']` filtered to the `Summary::AGE_BUCKETS` keys.
   - `$attentionOn` is `($_GET['attention'] ?? '') === '1'`.
5. **Render the cards.** `echo SummaryView::render($model, $activeSources, $source, $page, $ages, $oldestLastSync)` right after the sub-nav and before the state buttons.
6. **State buttons.**
   - Rename the label of `attention` to `__('Not matched', 'backupinfo')`. The key stays `attention`, and so does the default when `state` is missing.
   - Each button link keeps `source`.
   - Add a toggle button **"Needs attention"** (`btn btn-outline-danger`, `active` when on). Its link is the current URL with `attention=1` set or removed.
   - When `age[]` is set, show a "Clear age filter" link.
7. **Keep the parameters on table reloads.** `reloadTab` keeps `source`, `age[]` and `attention` next to `state`, so sort, pages and filters do not drop them.
8. **Export button.** Add **"Export (Excel)"** next to the Columns button, linking to the current URL plus `export=xlsx`.
9. **Inactive connections.**
   - Their rows stay in the table under All, as today.
   - Cards leave them out (Task 1).
   - The source buttons list active connections only.

- [ ] **Step 1: Write the failing source checks** in `tests/objects_view_test.php`:
  - `$obj` contains `Summary::build(`, `SummaryView::render(` and `ObjectsView::select(`;
  - `$obj` no longer contains `$where[$table . '.match_state']`;
  - `$obj` contains `__('Not matched', 'backupinfo')` and not `Needs review or not found`;
  - `$obj` contains `connection_active` and `'attention' => [`;
  - `$obj` contains `export=xlsx`;
  - `$menu` contains `'summary'`, `Backup summary` and `#backupinfo-summary`, and `'summary'` appears before `$options['objects']`;
  - Review Focus 4: `$obj` checks `source` against the active connection list (`isset($activeSources[` …).
- [ ] **Step 2: Run them.** Expected: FAIL.
- [ ] **Step 3: Implement the page changes 1–9 and the menu entry.**
- [ ] **Step 4: Run the full suite.** Expected: `SUITES OK`. Also run `php -l front/objects.php` and expect "No syntax errors".
- [ ] **Step 5: Commit** `feat(objects): summary cards, source switch, Needs attention toggle, Backup summary menu entry`, then run `graphify update .`.

### Task 5: Excel export (Summary + Objects sheets)

**Files:**
- Create: `src/SummaryExport.php`, a pure sheet builder.
- Modify: `front/objects.php`: before any HTML, handle `export=xlsx` the way `front/audit.php` does, falling back to CSV of the Objects sheet when `!Xlsx::available()`.
- Test: `tests/summary_export_test.php`.

**Interfaces:**
- Consumes: `Summary::build()` output, `ObjectsView::rows()` output, `Xlsx::sheet()`.
- Produces: `SummaryExport::sheets(array $model, array $columns, array $rows, array $meta): array`. It returns two `Xlsx::sheet()` arrays:
  1. **`Summary`**. Meta = `$meta` + `'Counts' => 'Cards: source choice only'`. Columns `['Section', 'Item', 'Value', 'Detail']`. Rows, in order:
     - the 5 KPIs;
     - the 5 age buckets, as count and %;
     - By kind, as count, size and fresh %;
     - every Needs attention row, as name, reasons, last backup age and job;
     - Largest objects;
     - each note, as a `Note` row.
  2. **`Objects`**. Meta = `$meta` + `'Rows' => 'Table: source and filters'`. The table columns **without** `Action`, and every filtered row.
- `$meta` comes from the page:
  - `'Report' => 'Backup Info backup summary'`;
  - `'Source'` (All, or the connection name);
  - `'Filters'` (state label, ages, attention), plus `'Inactive connection rows'` when there are any;
  - `'Last synced'`;
  - `'Generated at'`;
  - `'Generated by' => getUserName(Session::getLoginUserID())`;
  - `'Plugin version' => PLUGIN_BACKUPINFO_VERSION`.
- The file name is `backupinfo-summary-YYYYMMDD-HHMM.xlsx`.

- [ ] **Step 1: Write the failing tests** in `tests/summary_export_test.php`:
  - two sheets, named `Summary` and `Objects`;
  - the Summary rows hold `['KPI', 'Protected objects', '3', …]` for the invented 3-row model;
  - one Summary row per Needs attention entry;
  - the Objects columns do not hold `Action`, and every row is one cell shorter than `ObjectsView::COLUMNS`;
  - Review Focus 3: an empty model still gives both sheets, with their columns and meta;
  - `front/objects.php` contains `Xlsx::write(` and `SummaryExport::sheets(`, and the export branch comes before `Html::header(`.
- [ ] **Step 2: Run it.** Expected: FAIL.
- [ ] **Step 3: Implement `SummaryExport::sheets()` and the page branch** (headers `Content-Type: Xlsx::MIME`, `Content-Disposition`, `Cache-Control: no-store`; `exit` after writing).
- [ ] **Step 4: Run the full suite.** Expected: `SUITES OK`.
- [ ] **Step 5: Commit** `feat(summary): Excel export with Summary and Objects sheets`, then run `graphify update .`.

### Task 6: CHANGELOG, build check, handover

**Files:**
- Modify: `CHANGELOG.md`: a new top entry `## Unreleased (feat/backup-summary)`. It lists, in the CHANGELOG's existing user-facing style:
  - the cards;
  - Last backup age;
  - By kind, Needs attention and Largest objects;
  - the Age column;
  - the Needs attention toggle;
  - "Not matched" (renamed from "Needs review or not found");
  - the source switch;
  - the Backup summary menu entry;
  - the Excel export;
  - "Size not reported" for Veeam.

- [ ] **Step 1: Write the CHANGELOG entry.** Do not touch `setup.php`.
- [ ] **Step 2: Run the full suite.** Expected: `SUITES OK`.
- [ ] **Step 3: Check that the new files ship, without building a zip.** No zip: the version is not bumped, and `backupinfov0.5.0.zip` must not be touched. `tools/build_zip.py` ships the whole `src/` directory. Confirm with:
  `python -I -c "import importlib.util as u;s=u.spec_from_file_location('b','tools/build_zip.py');m=u.module_from_spec(s);s.loader.exec_module(m);print([e for e in m.entries() if 'Summary' in e])"`
  Then delete `tools/__pycache__`. Expected: `src/Summary.php`, `src/SummaryView.php` and `src/SummaryExport.php` are listed.
- [ ] **Step 4: Commit** `docs: changelog for the backup summary`, then run `graphify update .`.
- [ ] **Step 5: Hand over.** Report what the user should check on the VM:
  - the cards against the Backup objects table under All;
  - the age segment filter;
  - the Needs attention toggle;
  - the source switch;
  - the Excel file opening in Excel with two sheets.
