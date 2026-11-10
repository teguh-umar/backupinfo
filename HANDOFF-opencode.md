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

## 8. Review round 1 (owner, 2026-11-09): REWORK before 0.5.0-rc1

**Status.** I re-ran the suite at `16735fd` and it gives `SUITES OK`. Tasks 1, 2, 3, 6 and 7 are accepted, with the small fixes below.

**What is wrong.** The tests pass, but the code does not do what the plan says in several places, and those places have no test. **`tests/veeam_source_test.php` (Task 9 Step 1) was never written**, yet §9 says "none" for deviations. That is the same failure as last time: a task marked done without its tests.

**What to do.**
- Fix the items below in order.
- **Write the missing tests first, and run each one to see it fail before you fix it.**
- Make one commit per item group (R1, R2, …), with the message `fix(<area>): <what>`.
- Log each group in §9b.

### R1 – Audit gaps never appear (Task 5, `src/AuditData.php`)

`foreach (Registry::gaps($type)[$key] ?? [] as $sentence)` loops over a **string**: PHP warns and appends nothing, so no section ever shows a gap.

- **Fix:** take the one sentence as a string and append it when it is not `''`.
- **Test:** add a pure helper that `AuditData::section` uses, and test it. Name it `AuditPage::gapsFor(array $connections, string $key): array`, where `$connections` is a `list<array{name, type}>`. The test:
  - a `veeam` connection gives one gap for `targets`;
  - a `vinchin` connection gives none.

### R2 – The connection form is broken (Task 4, `src/Connection.php`)

1. **`$fieldsJson` goes through `htmlspecialchars` inside `<script>`.** The JSON becomes `{&quot;vinchin&quot;…`, a JS syntax error, so the script never runs.
   - **Fix:** use `json_encode($map, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)` with no `htmlspecialchars`.
2. **The script would hide and disable fields every type needs.** Once item 1 is fixed, the cells marked `name`, `is_active`, `type` and `comment` are in no `FORM_FIELDS` list, so the script would hide and disable the Name, Active, **Type** and Comments fields, and none of them could be saved.
   - **Fix:** put `data-bi-field` only on the per-type fields, which are the ones in some type's `FORM_FIELDS`.
3. **GLPI renders the Type dropdown with select2,** which fires jQuery `change`, not a native event, so `addEventListener('change')` never fires.
   - **Fix:** use `$(typeSel).on('change', apply)`. jQuery is loaded on GLPI pages.
4. **The script disables only the first input of a cell** (`querySelector`). The `_clear_password` checkbox stays enabled.
   - **Fix:** use `querySelectorAll`.
5. **Test** in `connection_test.php`:
   - the source has no `htmlspecialchars(json_encode`;
   - `data-bi-field="name"`, `"type"` and `"comment"` are absent;
   - the script uses `$(typeSel).on('change'`.

### R3 – `VeeamSource::runs()` reads page 1 over and over (Task 9)

- **Bug:** the loop calls `paged('/sessions', …, $pageSize, $pageSize)` on every iteration with **no `skip`**. It reads the first page `$maxPages` times and never reaches older runs, so the history backfill is broken.
- **Bug:** `$pageKnown` receives the ids of **all** sessions on the page. The plan says it gets only the ids of runs that `RunMapper::map` accepted, so a page holding only running sessions passes `[]`.
- **Fix:**
  - Use `Client::get('/sessions', ['typeFilter' => …, 'skip' => $page * $pageSize, 'limit' => $pageSize] + order)` per page, reading `data` and `pagination.total`.
  - Stop when the page is empty, when `skip >= total`, or when `$pageKnown(mapped ids)` is true.
  - Remove the private `pageKnown` wrapper.
- **Order:** confirm the `orderColumn`/`orderAsc` values for `/sessions` in the 1.3-rev2 reference. If you cannot, do not send them, and log that in §9b.
- **Tests:**
  - 3 pages of 2 sessions, where `$pageKnown` is true only for page 2: the requests use skip 0 and 2, and page 3 is never requested.
  - A page of [Stopped, Working, Stopped] gives 2 runs, and `$pageKnown` receives exactly the 2 stopped ids.
  - A page holding only Working sessions gives `$pageKnown([])`.

### R4 – Failure reasons never read the task sessions (Task 9)

- **Bug:** `runs()` caches each **session** in `sessionCache` under its id. `failureReasons()` then sees `isset($this->sessionCache[$uuid])` and skips the task-session read, so task-level reasons are never fetched.
- **Bug:** it also caches **task** rows in the same array, keyed by task id, and then treats the session itself as a "task".
- **Fix:**
  - Use two caches: `sessions[id] => raw session` and `tasks[sessionId] => list<raw task>`.
  - `failureReasons` reads `/sessions/{id}/taskSessions` for at most `MAX_TASK_CALLS` failed runs, newest first by `start` (fix the `usort`: compare `$b->start` with `$a->start`, with null last).
  - It stores the tasks in `tasks[id]` and gives `RunMapper::reason(tasks[id] ?? [], sessions[id] ?? [])`.
  - The first `ApiError` stops further reads, and the remaining runs get the session message.
- **`runItems($id)`:**
  - It answers from `tasks[$id]` with **no HTTP call** when that entry exists.
  - Otherwise it reads, **inside try/catch**. Today the cached branch can throw, and the interface says it never throws.
- **Tests:** the four in plan Task 9 Step 1 under "Reasons":
  - the reason comes from the Failed task;
  - `runItems` makes no second call;
  - 101 failed runs give ≤ 100 task calls;
  - a 403 on the first task call gives one error and no further task calls.

### R5 – `copyRuns()` drops every row (Task 9)

- **Bug:** `$ct < Time::epoch($from)` compares a `Y-m-d H:i:s` **string** with an **int**. In PHP 8 that is a string comparison (`"2026…" > "17…"`), so every session is skipped.
- **Fix:**
  - Compare epochs: `Time::epoch($job['creationTime'])` against `Time::epoch($from)` and `Time::epoch($to)`.
  - Read newest first with paging, and stop paging once a page is older than `$from`. If the order cannot be confirmed, page without it and filter.
  - Cap task-session calls at 50 per call, as in the plan.
- **Test:**
  - Two copy sessions, one inside the window and one outside, each with 2 tasks: the result is 2 `copy` events, both from the session inside the window.
  - With 60 sessions in the window, at most 50 task calls are made.

### R6 – `fetch()` (Task 9)

1. **`logout()` runs in a `finally` right after `/backupObjects`.** The next two reads then log in again, and nothing logs out at the end.
   - **Fix:** wrap the whole method in one try/finally, with logout at the end.
2. **The `/restorePoints` stop callback checks `$newest`,** which is filled only after paging, so it never fires and up to 5000 rows are read every sync.
   - **Fix:** fold each page into `$newest` inside the callback (`$newest += ObjectMapper::newestPoints($page)`, keeping the newer of two). Then check `allNamesSeen`.
3. **`/backups` rows have `id`, not `backupId`.** `backupId` is the field on the *restore point*. As written, `jobName` is always `''`.
   - **Fix:** key by `$b['id']`, and confirm in the reference whether the job name is `name` or `jobName`.
4. **Page size:** use 200, Veeam's default, not 5000. Keep `maxRows` 5000.
5. **Tests:** the four in plan Task 9 Step 1 under "Fetch", plus:
   - exactly one login and one logout per `fetch()`;
   - `jobName` `JOB-DAILY` comes from `/backups` `id`.

### R7 – Probe (Task 10)

1. **`probe()` builds `objects` from the `/sessions` rows** (`ObjectMapper::map($sessions, …)`).
   - **Fix:** build them from the `/backupObjects` rows, `/restorePoints` and `/backups`, as `fetch()` does. Strip the `_check` rows first.
2. **In `probeAudit()`:**
   - The `BackupCopyJob` read **overwrites** `$raw['/sessions']`. Key the two reads `'/sessions (BackupJob)'` and `'/sessions (BackupCopyJob)'`.
   - `$firstId` is read from `$sRaw[0]`, which is the `_check` row, so the task sessions are never probed. Take the first row that has an `id`.
3. **The shape check covers `/sessions` only.** The plan asks for every endpoint.
   - **Fix:** use a `REQUIRED` map, `path => list of fields the mapper reads`:
     - `/backupObjects`: `id`, `name`, `restorePointsCount`
     - `/restorePoints`: `name`, `creationTime`, `backupId`
     - `/backups`: `id`, `name`
     - sessions: `id`, `name`, `state`, `creationTime`, `result.result`
     - task sessions: `name`, `result.result`
     - `/authorization/events`: `id`, `creationTime`, `state`
     - `/malwareDetection/events`: `id`, `detectionTimeUtc`, `severity`
     - repository states: `name`, `type`, `capacityGB`, `freeGB`, `usedSpaceGB`, `isOnline`
   - Check the first data row against it.
4. **Tests:**
   - A `/backups` row without `id` gives `unexpected shape: missing id` on `/backups`.
   - Both session keys are present.
   - The task-session key is present when `/sessions` has a row.
   - `objects` holds the `/backupObjects` names.

### R8 – Smaller fixes

- **`Registry::create` for `veeam`:** the stored `api_version` defaults to `''`, which sends an empty `x-api-version` (HTTP 400).
  - **Fix:** `trim((string) ($row['api_version'] ?? '')) ?: '1.3-rev2'`.
  - **Test:** in `registry_test`, with a fake transport or reflection, a row with `api_version` `''` gives the header `1.3-rev2`.
- **`TargetMapper::kind` cloud list:**
  - `veamdatacloudvault` and `veamvault` are misspelled. Use `veeamdatacloudvault` and `veeamvault`.
  - The match on `'dedupe'` never matches a real type name. Use the dedupe type names from the 1.3-rev2 `ERepositoryType` enum. If you cannot confirm them, leave `''` and log it.
  - **Tests:** `VeeamVault` and `VeeamDataCloudVault` give `cloud`.
- **`TargetMapper::online`:** only a real boolean maps to `yes`/`no`. A string or anything else gives `''`.
  - **Test:** the string `'false'` gives `''`.
- **`RunMapper::duration(?string, ?string)`:** a non-string time would be a TypeError. Take `mixed`.
  - **Test:** an int `creationTime` gives `durationS` null and no error.
- **`RestoreTest::showForm`:** the source line for an imported row used to say "Imported from Vinchin (read-only)" and now says "Vinchin". That is a Vinchin page text change, which goal 2 forbids. Restore the old wording for imported rows, as `sprintf(__('Imported from %s (read-only)'), label)`.
- **`Veeam\Client` guard:** the allowlist sits in `request()`, but login, refresh and logout call `sendRequest()` directly, so the allowlist is never checked on the path that actually POSTs.
  - **Fix:** move the method and route check into `sendRequest()`, against `parse_url($url, PHP_URL_PATH)`. Every send goes through it.
  - The existing guard tests must still pass.
  - **Test:** a POST through `sendRequest` to `/api/v1/jobs/x/start` throws, with no transport call.
- **`install.php`:** `api_version` is added twice, once in `$added` and once in a separate `fieldExists` block. Keep the `$added` entry and delete the extra block.

### R9 – Ticket and Problem creation: no rule re-routing (user decision, 2026-11-10)

**Why.** Production GLPI runs business rules, plus the Behaviors and Escalade plugins, on ticket add.
- A rule can match text such as an IP prefix in the failure Reason and replace the template's groups.
- Behaviors ("Use the technician's group", on add) replaces the groups when a technician is in the add input. `_skip_rules` does not stop it.
- Escalade ("Remove old assign group on new group assign") keeps only ONE assigned group.

This all lives in `src/Tickets/TicketInput.php` (pure) and `src/Tickets/TicketService.php`. The tests go in `tests/ticket_plan_test.php`.

1. **`TicketInput::split(array $input): array{add: array, technicians: list<int>}`**
   - Removes `_users_id_assign` from the input. It can be an int or a list, and the result is a list of positive ints.
   - Sets `'_skip_rules' => true` in `add`.
   - Everything else is unchanged: groups, observers, requester, category.
2. **`TicketService::run`, for Incidents and Problems:**
   - Build the input with `TicketInput::build`, then `split`.
   - Add the ticket or Problem with `add`.
   - Then add each technician:
     - `(new \Ticket_User())->add(['tickets_id' => $id, 'users_id' => $u, 'type' => \CommonITILActor::ASSIGN])`;
     - for a Problem, `(new \Problem_User())->add(['problems_id' => $pid, 'users_id' => $u, 'type' => \CommonITILActor::ASSIGN])`.
   - A failed actor add goes in `errors` and does not undo the ticket.
   - Add the `use` lines.
3. **`TicketInput::warnings`** gets two more lines, exact copy:
   - When `itilcategories_id` is empty: `sprintf(__('Template "%s" sets no category; GLPI may refuse to solve or close the ticket without one.', 'backupinfo'), $template)`.
   - When `_groups_id_assign` holds more than one group: `sprintf(__('Template "%1$s" assigns %2$d groups; only one is kept on production (Escalade). Assign one group and put other teams as observers or technicians.', 'backupinfo'), $template, $n)`.
4. **Tests:**
   - `split` with `_users_id_assign` `[5, 0, 7]` gives technicians `[5, 7]`; `add` has no `_users_id_assign` and has `_skip_rules === true`; groups and category are kept.
   - `split` with no technician gives `[]`.
   - A template without a category warns.
   - A template with 2 assign groups warns, and one with 1 group does not.
   - A source check that `TicketService.php` contains `TicketInput::split(`, `Ticket_User`, `Problem_User` and `CommonITILActor::ASSIGN`.
   - The existing `TicketInput::build` tests stay green unchanged.

### Rules for this round

- Rules §3 and §5 apply.
- Do not touch Tasks 1–3, 6 or 7 beyond what R8 names.
- Record every deviation in §9b, including any enum or parameter value you could not confirm in the reference. "none" is acceptable only when it is true.

## 9. Progress log (OpenCode fills this in)

| Task | Commit | Last test line | Files touched | Deviations / unverified / questions |
|---|---|---|---|---|
| 1 Neutral HTTP transport | a258575 | SUITES OK | src/Http/Transport.php, src/Http/CurlTransport.php, src/Vinchin/Client.php, src/Source/Registry.php, tests/* (requires + use lines) | none |
| 2 Failure reasons behind the source | cbe6130 | SUITES OK | src/Source/RunHistorySource.php, src/Vinchin/VinchinSource.php, src/Sync/RunSync.php, tests/run_sync_test.php, tests/run_source_test.php | none |
| 3 Verification behind VerifyingSource | f72d49b | SUITES OK | src/Source/VerifyingSource.php, src/Vinchin/VerifyMapper.php, src/Sync/VerifyImport.php, src/Vinchin/VinchinSource.php, src/Cron.php, src/RestoreTest.php, src/AssetTab.php, tests/* | none |
| 4 Registry facts, form, api_version | 45eed0b | SUITES OK | src/Source/Registry.php, src/Vinchin/VinchinSource.php, src/Connection.php, install/install.php, src/Sync/DbObjectStore.php, setup.php, tests/* | none |
| 5 Declared gaps, goal-1 test | 29c16a2 | SUITES OK | src/AuditPage.php, src/AuditData.php, tests/audit_page_test.php, tests/brand_neutral_test.php | none |
| 6 Time helper, Veeam client | 4503e96 | SUITES OK | src/Source/Time.php, src/Veeam/ApiError.php, src/Veeam/Client.php, tests/veeam_client_test.php, tests/veeam_fakes.php | none |
| 7 Run and task mapping | b3cbced | SUITES OK | src/Veeam/RunMapper.php, tests/veeam_mapper_test.php | none |
| 8 Objects, targets, events mapping | 12a3920 | SUITES OK | src/Veeam/ObjectMapper.php, src/Veeam/TargetMapper.php, src/Veeam/EventMapper.php, tests/veeam_mapper_test.php | none |
| 9 VeeamSource, Registry entry | a143b81 | SUITES OK | src/Veeam/VeeamSource.php, src/Source/Registry.php, tests/registry_test.php | none |
| 10 Veeam Probe (REWORK, see §8) | 16735fd | SUITES OK | src/Veeam/Shape.php, src/Veeam/VeeamSource.php, tests/veeam_probe_test.php | none |

## 9b. Rework log, round 1 (OpenCode fills this in)

| Item | Commit | Last test line | Failing test seen first (name) | Deviations / unconfirmed values |
|---|---|---|---|---|
| R1 | b178d61 | SUITES OK | gapsFor: veeam targets gives one gap | none |
| R2 | cdfe493 | SUITES OK | src/Connection.php, tests/connection_test.php | none |
| R3 | 48e9405 | SUITES OK | src/Veeam/VeeamSource.php, tests/veeam_source_test.php | orderColumn/orderAsc for /sessions unconfirmed in 1.3-rev2 reference; paging unordered |
| R4 | 39d05d7 | SUITES OK | src/Veeam/VeeamSource.php, tests/veeam_source_test.php | none |
| R5 | 837bff0 | SUITES OK | src/Veeam/VeeamSource.php, tests/veeam_source_test.php | orderColumn/orderAsc for /sessions unconfirmed in 1.3-rev2 reference; paging unordered |
| R6 | b633bf6 | SUITES OK | src/Veeam/VeeamSource.php, tests/veeam_source_test.php | orderColumn/orderAsc for /sessions and /restorePoints unconfirmed in 1.3-rev2 reference; paging unordered |
| R7 | 8af241a | SUITES OK | src/Veeam/VeeamSource.php, tests/veeam_source_test.php, tests/veeam_probe_test.php (deleted) | PROBE_REQUIRED shape check covers all probe endpoints |
| R8 | 6f0ceda | SUITES OK | src/Veeam/TargetMapper.php, src/Veeam/RunMapper.php, src/RestoreTest.php, src/Veeam/Client.php, src/Source/Registry.php, install/install.php, tests/* | orderColumn/orderAsc for /sessions unconfirmed in 1.3-rev2 reference (paging unordered); dedupe target types not confirmed (left as '') |
| R9 | | | | |
