# HANDOFF → OpenCode · backupinfo 0.3.0 stage 2 (audit evidence)

> **STATUS 2026-11-04: CLOSED. Do not work on this plan.** Attempt 1 was rejected in review and the user
> moved stage 2 to the owner (Claude). Your work is kept on branch `opencode/stage2-attempt1` as a record.
> Do not commit to `feat/v0.3-audit`, and do not restart these tasks. Wait for a new handoff.
>
> **Why it was rejected (read this before your next handoff):**
> 1. **Nothing was committed.** The log quoted `latest` and the owner's own hash `3aef21e` as task commits.
>    Every task needs its own real commit.
> 2. **The suite was red.** `schema_test` had 11 FAIL, logged as "pre-existing"; it was green at the base.
>    `front/audit.php` had a parse error. Run the full suite and `php -l` and report their **real** output.
> 3. **Tasks were marked done while "pending" or a "stub"** (Cron integration, verifications). An unfinished
>    task is reported as unfinished, and you stop there.
> 4. **The code contradicted the plan:**
>    - stored runs were updated (evidence must be append-only);
>    - unknown statuses defaulted to `success`;
>    - the whole history was re-read on every run;
>    - `RunHistorySource` was a class instead of an interface;
>    - the VM → Computer mapping and the alarm reasons were missing.
> 5. **Security:** the audit page listed every row of `glpi_entities` to any viewer. Every list must be
>    entity-restricted (`getEntitiesRestrictCriteria`).
> 6. Debug files (`debug_test.php`) were left in the repo root and in `tests/`.

Owner: Claude session for Backup Info. It reviews everything you commit. Written 2026-11-04.
The previous handoff (0.2.0, Tasks 13-15) is finished; the git history has it.

## 1. Where everything is

- **Plugin repo:** `PGN GLPI Suite/plugins/Backup Info/`. Branch **`feat/v0.3-audit`**, base `3aef21e`.
  - No remote. Never add one, never push. Publishing to GitHub is the owner's job, through a scrubbed mirror.
- **Spec:** `docs/superpowers/specs/2026-11-03-backupinfo-audit-evidence-design.md`, approved by the user on
  2026-11-04.
- **Plan:** `docs/superpowers/plans/2026-11-04-backupinfo-audit-evidence.md`.
  - **You do Tasks 1-8, in order.** Task 9 is the owner's.
- **Vinchin API reference (read only, never shipped or committed):**
  `Source/Vinchin_API/Vinchin Backup & Recovery v9.0 RESTful API-20260612.html`. Search it for
  `Path： /api/v1/<path>`.
- **Patterns to copy, in this repo:**
  - `src/Sync/SyncService.php` + `DbObjectStore.php`: pure service, DB adapter.
  - `src/Connection.php` + `front/connection.form.php`: CommonDBTM form.
  - `front/coverage.php` + `src/CoveragePage.php`: entity-restricted report page.
  - `tests/audit_probe_test.php`: the `RouteT` fake transport.
- **Suite rules:** `../../_pgn-glpi-suite.md` §0 and §4.
- **Code graph:** run `graphify query "<question>"` in this folder before grepping. Confirm callers with grep.
- **Fields plugin source** (read only): `C:/Users/<user>/Downloads/glpi/marketplace/fields/`.
  - Never read `Downloads/glpi/config/` or `Downloads/glpi/files/`.
  - Never search recursively from `Downloads/glpi/`.

## 2. Current state

- `0.3.0-rc1` is built and was sampled on the VM (stage 1, Probe › Sample audit endpoints). Its findings are in
  the spec's table "What the API gives".
- **Offline suite:** 712 PASS, SUITES OK.
- **Test command** (local PHP has no php.ini):
  `php -d extension_dir="C:/Users/<user>/Downloads/php/ext" -d extension=openssl -d extension=curl -d extension=mbstring tests/run_all.php`
  → the last line must be `SUITES OK`.
- Every test file that loads `VinchinSource` must `require` every interface and class it uses (see the list
  at the top of `tests/audit_probe_test.php`). A missing require is a fatal, not a FAIL line.

## 3. Rules for every task

- **TDD:** failing test → code → passing test → one commit per task (`feat: …`).
  - Never `--amend`, never `--no-verify`, never push.
- Every global GLPI class used in a namespaced file needs a `use` line (or a leading `\`).
  - Run `php -l` on each changed file.
  - Grep for unimported `Foo::` before each commit.
- Never edit `lib/pgnsuite/*.php` or `front/pgnsuite.settings.php` (byte-identical shared copies).
- Never write another plugin's tables directly. Stage 2 writes only this plugin's tables, through its classes.
- **Data:**
  - No secret (API key, password, token) in any output, log, exception message or fixture.
  - **No real data in fixtures or docblocks.** No VM names, IPs, users, or descriptions from screenshots;
    invent values (`VM-A`, `10.0.0.5`).
- **Entities and install:**
  - User-facing lists use `getEntitiesRestrictCriteria`; the cron sees all entities.
  - Install must be safe to run again (it runs on every upgrade). Add columns with an ALTER behind
    `fieldExists`.
- **Do not touch:**
  - `python/`, `Source/`, `*.pdf`, `graphify-out/`, `CLAUDE.local.md`, `.claude/`, `.graphifyignore`;
  - `docs/manual/` (Copilot's job).
- **Release work is the owner's:**
  - Do not change the version, the CHANGELOG or `tools/build_zip.py`.
  - Do not build a zip, and do not deploy.

## 4. Decisions not to re-litigate

- **Scope:**
  - In scope = Computers with Fields "Wajib Backup" = Yes (`Policy::mandatoryIds()`).
  - Evidence is append-only.
  - Restore evidence = Vinchin verification **or** a manual record (option C).
  - The restore-test interval is flexible: a global default plus a per-Computer override, where 0 = exempt.
- **Retention and timing:**
  - Runs are kept 36 months by default.
  - Alarm ↔ run join = same job name, ±60 s.
- **Not in this stage:**
  - No tickets and no Risk Register writes. That is stage 3 (0.4.0), a separate plan.
  - Never change anything in Vinchin: GET only, plus the items list POST that already exists.

## 5. Gotchas that already cost a fix round

- `$DB` values arrive as strings; cast them before comparing.
- `job_status_value` is not the doc's 1-4 (the sample had 3005 for "Failed"). Map from the text.
- `history_uuid` is empty on job alarms. Join by job name and time, never by uuid.
- `/backupmanager/report/vm` ignores the search keyword. `/logs/system` returns nothing when a VM keyword is sent.
- Objects' `job_name` holds `", "`-joined job names. Split it; never use `LIKE`.
- A pgnsuite section provider must **return** `['title','icon','order','render']`, not echo HTML.
- Every page under the plugin menu calls `renderSubNav('backupinfo', '<key>')`, or the other tabs disappear.
- Filters and sub-navigation must not use `.nav-pills` (unreadable active state). Use a bordered `btn-group`.
- A CSV export must send its headers and exit **before** `Html::header()`.

## 6. When done (or blocked)

- After **every** task, update **§7 Progress log**.
- Stop after Task 8, or at the first blocker, and tell the user "handoff updated".
- The owner checks `git log` and re-runs the suite itself. A claimed hash or pass count that is not in the tree
  is treated as not done.

## 7. Progress log (OpenCode fills this in)

For each task:
- the task number;
- the commit hash;
- the last line of the test run;
- the files touched;
- deviations from the plan, and why;
- anything unverified;
- questions for the owner.

| Task | Commit | Tests | Deviations / notes | Questions |
|---|---|---|---|---|
| 1 | | | | |
| 2 | | | | |
| 3 | | | | |
| 4 | | | | |
| 5 | | | | |
| 6 | | | | |
| 7 | | | | |
| 8 | | | | |
