# HANDOFF → OpenCode · backupinfo 0.5.0 (brand-neutral sources, Veeam B&R 13 preview)

Owner: the Claude session for Backup Info (`-fe` in hub §6). It reviews everything you commit.
Written 2026-11-09. The previous handoff (0.3.0 stage 2) is closed; the git history has it.

**Before the first edit**, load these skills:
- `pgn-suite-handoff`, which loads `handoff-discipline`;
- `glpi-plugin-development`, for GLPI 11 API questions.

## 1. Where everything is

- **Plugin repo:** `PGN GLPI Suite/plugins/Backup Info/`.
  - Branch **`feat/v0.5-veeam`**. Its base is the commit that adds this handoff, on top of `feat/v0.4-tickets` at 0.4.0-rc6.
  - Do not commit to `feat/v0.4-tickets` or `main`.
  - The repo has no remote. Never add one and never push.
- **Spec:** `docs/superpowers/specs/2026-11-06-backupinfo-veeam-design.md`, approved by the user.
- **Plan:** `docs/superpowers/plans/2026-11-07-backupinfo-veeam.md`.
  - **You do Tasks 1–10, in order.**
  - Task 11 (installer checklist, version, CHANGELOG, zip, tag) is the owner's.
  - The plan's **Global Constraints** and **Review Focus** apply to every task.
- **Veeam field reference:** `reports/Veeam VBR 13 REST API.md`.
  - It is local only: read it, but never commit, copy or quote it into the repo.
  - You may also read Veeam's public online reference for REST 1.3-rev2: https://helpcenter.veeam.com/references/vbr/13/rest/1.3-rev2/.
  - A field or enum value not in the reference counts as **unknown**. It is handled as unknown (null, `abnormal`, or `''`), never guessed.
- **Patterns to copy, in this repo:**
  - `src/Vinchin/Client.php` and `src/Vinchin/VinchinSource.php`: the source shape and the error style.
  - `tests/fakes.php` (`RouteT`) and `tests/run_source_test.php`: the fake transport style.
  - `src/Sync/RunSync.php`: the cap and the stop-after-refusal rule.
- **Code graph:** run `graphify query "<question>"` in this folder before grepping. Confirm callers with grep, because class names in strings are invisible to the graph.

## 2. Current state

- **Version:** 0.4.0-rc6. The user confirmed its UI on the VM on 2026-11-07. Tickets and findings testing is parked by the user. That is not your concern.
- **Test command** (local PHP has no php.ini):
  `php -d extension_dir="C:/Users/<user>/Downloads/php/ext" -d extension=openssl -d extension=curl -d extension=mbstring tests/run_all.php`
  The last line must be `SUITES OK`. It is green at the base.
- **Test files load classes by hand.** A new test must `require` every class and interface it uses, in dependency order. A missing require is a fatal, not a FAIL line. When Task 1 moves the transport, update every test that requires `src/Vinchin/Transport.php` or `CurlTransport.php`.

## 3. Rules for every task

- **One commit per task.**
  - Work test-first: failing test, then code, then passing test, then commit.
  - Use the commit message the plan gives, with the trailer line `Co-Authored-By: <your model> <noreply@...>`.
  - Never `--amend`, `--no-verify` or push.
- **Checks before each commit:**
  - Run `php -l` on each changed PHP file.
  - Run the full suite, and report its real last line.
  - Grep for unimported global classes (`Foo::` without a `use` line or a leading `\`).
- **Vinchin must not change.** Every existing test passes unedited, except where the plan names the edit:
  - Task 1: requires and use lines.
  - Task 2: the `run_sync_test` fake.
  - Task 3: the `restore_test` regexes and `verify_import_test` calls.
  - The only visible Vinchin difference allowed is the transport error text, `Connection failed: …`.
- **Read-only against Veeam.**
  - GET only, plus POST to `/api/oauth2/token` and `/api/oauth2/logout`.
  - The client throws before sending anything else.
- **Secrets.** No password, access token or refresh token in any output, log, exception message, Probe row or fixture. Fixtures use the literal fake tokens `access-xyz` and `refresh-xyz`, and the tests check that neither leaks.
- **No real data.**
  - Fixtures use invented names only: `vbr.example.test`, `vm-app-01`, `vm-db-01`, `repo-main`, `JOB-DAILY`.
  - Never use names, IPs or users from screenshots, PDFs, `python/`, `Source/` or the VM.
- **Install runs on every upgrade.** Add the `api_version` column behind `fieldExists`, as the existing `$added` list does.
- **Do not touch:**
  - `lib/pgnsuite/*.php` and `front/pgnsuite.settings.php`;
  - `python/`, `Source/`, `*.pdf`, `reports/`, `research_notes/`, `graphify-out/`, `CLAUDE.local.md`, `.claude/`, `.graphifyignore`;
  - `docs/manual/`.
- **Release work is the owner's.** Do not change the version, the CHANGELOG or `tools/build_zip.py`, and do not build a zip.
- **After each commit, if it works for you:** run `python tools/graphify_refresh.py` from the suite root. Nothing from it is committed.

## 4. Decisions not to re-litigate

These were decided by the user, or by the owner in the plan:

- **Veeam scope.** Veeam is used like Vinchin, for other organisations, and as proof of the architecture. There is no Veeam server and no feedback channel. So the code is **safe when unsure** (unknown means not OK, and a running session is not stored) and **explains itself**: errors name the endpoint, HTTP status, API version and a hint.
- **Status table** (plan, Global Constraints). Failed beats cancelled. Unknown values are stored as `abnormal`. A session with `state` ≠ `Stopped` is not stored.
- **lastBackup** is the newest parseable restore point time. Anything unsure gives null, which shows `expired`.
- **Capacities** are shown as sent, `"<n> GB"`, never converted.
- **Times** keep their offset and are written in PHP's default timezone. The helper is `Source\Time`.
- **Declared gaps:**
  - Tab 2 platform VMs is a declared gap in 0.5.0, so there is no inventory call and no POST for it.
  - Tape, alarms, the job log and the login source IP are declared gaps, using the exact `GAPS` text in Task 9.
  - SureBackup is not imported: `VeeamSource` does not implement `VerifyingSource`.
- **403 handling.** A 403 on `/authorization/events` or `/malwareDetection/events` gives empty rows and no error line. The gap sentence and Probe explain it.
- **On a 401,** refresh once, else log in again, then retry once. A second 401 is an error.
- **Type labels.** The dropdown shows `Veeam B&R 13 (preview, not verified on a server)`. The short media label is `Veeam`.

## 5. Gotchas

- **`RunSync` treats a page with no ids as not known.** `runs()` must pass only the ids of stored-able (Stopped) runs to `$pageKnown`, so a page holding only running sessions is fetched again next sync.
- **Do not trust option names you have not seen in the reference.** That covers `orderColumn` values such as `CreationTime`, and `typeFilter` values beyond `BackupJob` and `BackupCopyJob`. Veeam may answer 400 to an unknown value. If you cannot confirm one, page unordered with the cap and filter client-side. Say so in the task's notes.
- **Paging.** Veeam paging is `skip`/`limit`, with `{data:[…], pagination:{total,count,skip,limit}}`. Parameter names are case-sensitive.
- **Login body.** Build the login form with `http_build_query`, so `DOMAIN\user` and `&` in a password are encoded.
- **Vinchin times stay as they are.** They have no offset and are kept unshifted. Do not route Vinchin through `Source\Time`.
- **`Event::make` and `Target::make`** cut and trim every field. Pass plain strings and never add keys.
- **The connection form JS** must work with GLPI's select2 Type dropdown. Listen to `change` on the `select[name=type]` element. Hidden cells get `d-none`, and their inputs get `disabled`, so they are not posted.

## 6. When done (or blocked)

- After **every** task, fill its row in **§9 Progress log**, with the real hash and the real last test line.
- **Stop at the first blocker.** Write it as BLOCKED in §9 with what you tried. Do not guess around a plan line.
- Stop after Task 10, and tell the user "handoff updated".
- The owner checks `git log`, re-runs the suite and reviews each commit against the plan. A hash or pass count that is not in the tree counts as not done.

## 9. Progress log (OpenCode fills this in)

| Task | Commit | Last test line | Files touched | Deviations / unverified / questions |
|---|---|---|---|---|
| 1 Neutral HTTP transport | | | | |
| 2 Failure reasons behind the source | | | | |
| 3 Verification behind VerifyingSource | | | | |
| 4 Registry facts, form, api_version | | | | |
| 5 Declared gaps, goal-1 test | | | | |
| 6 Time helper, Veeam client | | | | |
| 7 Run and task mapping | | | | |
| 8 Objects, targets, events mapping | | | | |
| 9 VeeamSource, Registry entry | | | | |
| 10 Veeam Probe | | | | |
