# Backup Info 0.5.0: brand-neutral sources and Veeam VBR 13 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the plugin's core brand-neutral, then add a read-only Veeam Backup & Replication 13 source at parity with Vinchin where VBR 13 REST allows. Each gap is declared in words.

**Architecture:**
- Tasks 1–5 move every Vinchin-specific piece out of the core behind interfaces and per-type facts in `Source\Registry`, with no change to Vinchin output.
- Tasks 6–10 add `src/Veeam/`. It holds an OAuth2 client with a route allowlist, pure mappers per shape, and one `VeeamSource` that implements the same interfaces as `VinchinSource` where Veeam has the data.
- Task 11 ships the installer checklist and the release.

**Tech Stack:** PHP 8.x and GLPI 11 plugin; curl; the plugin's own test runner (`tests/run_all.php`, no PHPUnit, no autoloader: each test `require`s its files).

**Spec:** `docs/superpowers/specs/2026-11-06-backupinfo-veeam-design.md`. Field reference: `reports/Veeam VBR 13 REST API.md` (local only, never committed or zipped).

## Global Constraints

- **Tests.**
  - Command: `php -d extension_dir=C:/Users/<user>/Downloads/php/ext -d extension=openssl -d extension=curl -d extension=mbstring tests/run_all.php`.
  - It must end `SUITES OK` after every task.
- **Vinchin unchanged.** Stored rows, Probe output, page text and exports are unchanged. The only allowed difference is the transport error text: "Vinchin connection failed: …" becomes "Connection failed: …".
- **Fixtures.**
  - Use no real hostnames, IPs, VM names or customer data.
  - Use invented names only: `vbr.example.test`, `vm-app-01`, `vm-db-01`, `repo-main`, `JOB-DAILY`.
  - Shapes come from Veeam's public 1.3-rev2 reference. A value that is not in the reference counts as unknown.
- **Read-only.** The Veeam client sends GET only. The only POSTs allowed are `/api/oauth2/token` and `/api/oauth2/logout`. Any other method or route throws `\LogicException` before the transport is called.
- **Secrets.**
  - Never print, log or store a password, access token or refresh token.
  - Error messages carry no secret.
  - Probe output goes through `Probe::redact`.
- **Stored shapes.** Run, Event and Target rows keep their common shapes. Veeam enums may appear only inside text fields (Target `type`, Run `statusText`, Event `action`/`level`/`status`).
- **Type labels.**
  - The Type dropdown shows `Veeam B&R 13 (preview, not verified on a server)`.
  - The short label used in media text is `Veeam`.
- **API version.**
  - The per-connection field `api_version` defaults to `1.3-rev2`; `1.3-rev1` covers 13.0.1.
  - Every Veeam request carries the header `x-api-version: <api_version>`.
- **Host and port.** The default port is `9419` when the host has none. The base is `https://<host>:<port>/api/v1`.
- **Times.**
  - Veeam times are parsed with their offset and written `Y-m-d H:i:s` in PHP's default timezone, which is GLPI's.
  - A time with no offset is read in that timezone.
  - An unparseable time is `null`.
- **Status table.** This is exact, from the spec:
  - `state` ≠ `Stopped`: not stored.
  - `Failed`: `failed`, even when `isCanceled` is true.
  - `isCanceled` with Success, Warning or None: `aborted`.
  - `Success`: `success`.
  - `Warning`: `abnormal`.
  - Anything else, including a stopped `None`: `abnormal`.
- **Hourly cost.** The cap is `VeeamSource::MAX_TASK_CALLS = 100` task-session reads per sync, newest failed runs first, as for `RunSync::MAX_ITEM_CALLS`.
- **Local only.**
  - `graphify-out/`, `CLAUDE.local.md`, `.claude/`, `reports/` and `research_notes/` stay local.
  - Nothing goes in `docs/manual/`.
  - No push without the user's approval.
- **After each task's commit,** run `python tools/graphify_refresh.py` from the suite root. This step is local and nothing is committed from it.
- **Commit trailer:** `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. **A run still running when the sync reads it.**
   - Expect it not to be stored, and to be stored on the next sync once Stopped.
   - Pinned in Task 7: `runs()` skips it, and the page holding only that run is not "known".
2. **A 401 in the middle of a long sync** (token expired after 15 minutes).
   - Expect one refresh, falling back to a fresh login, then one retry with no loop.
   - A second 401 is an error naming the endpoint.
   - Pinned in Task 6.
3. **A restore point whose time or object name is missing or unparseable.**
   - Expect `lastBackup` null, which shows `expired`, never OK.
   - Pinned in Task 8.
4. **A 403 on the optional security endpoints** (no Security Administrator or Incident API role).
   - Expect empty rows with no error line each sync.
   - The Activity tab's gap sentence tells the reader to check Probe.
   - Probe marks it "refused (role?)".
   - Pinned in Tasks 9 and 10.
5. **A wrong API version** (13.0.1 server asked with `1.3-rev2`).
   - Expect a 400 error text that names the endpoint, the status, the version in use and the hint "check the API version (1.3-rev2 = 13.1, 1.3-rev1 = 13.0.1)".
   - Pinned in Task 6.

---

### Task 1: Neutral HTTP transport

**Files:**
- Create: `src/Http/Transport.php`, `src/Http/CurlTransport.php` (moved from `src/Vinchin/`)
- Delete: `src/Vinchin/Transport.php`, `src/Vinchin/CurlTransport.php`
- Modify: `src/Vinchin/Client.php` (type of `$transport`), `src/Source/Registry.php`, `tests/fakes.php`, and every test that requires or uses the old files (`registry_test`, `vinchin_client_test`, `vinchin_mapper_test`, `audit_probe_test`, `probe_test`, plus any found by `grep -l "Vinchin\\\\Transport\|Vinchin/Transport.php\|Vinchin/CurlTransport" tests`)

**Interfaces:**
- Produces: `GlpiPlugin\Backupinfo\Http\Transport::send(string $method, string $url, array $headers, ?string $body, bool $verifyTls): array{status:int, headers:array<string,string>, body:string}` (unchanged contract). `Http\CurlTransport` throws `\RuntimeException('Connection failed: ' . curl_error)`.

- [ ] **Step 1:** In `tests/registry_test.php`, add `check('transport is brand-neutral', is_subclass_of(\GlpiPlugin\Backupinfo\Http\CurlTransport::class, \GlpiPlugin\Backupinfo\Http\Transport::class) && !file_exists(BI_ROOT . '/src/Vinchin/Transport.php'));` and switch its requires to `src/Http/*.php`.
- [ ] **Step 2:** Run the suite. Expected: registry_test FAILS (class or file missing).
- [ ] **Step 3:** Move the two files with `git mv`, change the namespace to `GlpiPlugin\Backupinfo\Http`, change the error text to `'Connection failed: '`, and update every `use` and `require`.
- [ ] **Step 4:** Run the suite. Expected: `SUITES OK`.
- [ ] **Step 5:** Commit: `refactor: HTTP transport moves to a brand-neutral namespace`.

### Task 2: Failure reasons behind the source; RunSync neutral

**Files:**
- Modify: `src/Source/RunHistorySource.php`, `src/Sync/RunSync.php`, `src/Vinchin/VinchinSource.php`
- Test: `tests/run_sync_test.php` (fake source), `tests/run_source_test.php`

**Interfaces:**
- Produces, on `RunHistorySource`:
  - It **replaces** `jobAlarms` in the interface. `jobAlarms` stays a public Vinchin-only method.
  - `failureReasons(array $failedRuns): array{reasons: array<string, string>, error: string}`. `$failedRuns` is a `list<Run>`; the keys of `reasons` are `historyUuid`, and the call never throws.
- `VinchinSource::failureReasons` is today's RunSync logic moved as-is: `jobAlarms(dayBefore($failed))` then `AlarmJoin::reasons`. The private `dayBefore` moves with it.
- `RunSync` calls `$source->failureReasons($failed)` and imports nothing from `Vinchin\`.

- [ ] **Step 1:** Tests.
  - In `run_sync_test.php`, change the fake source to implement `failureReasons` (return `['reasons' => ['u-failed' => 'Disk full'], 'error' => '']`) and drop `jobAlarms`.
  - Add `check('reason comes from the source', <stored reason of u-failed> === 'Disk full')`.
  - Add `check('RunSync names no brand', !preg_match('/Vinchin|Veeam/', file_get_contents(BI_ROOT . '/src/Sync/RunSync.php')))`.
  - In `run_source_test.php`, add a case: two failed runs plus `/alarm/job` rows give the same reasons `AlarmJoin` gave before. Reuse the existing alarm fixture.
- [ ] **Step 2:** Run. Expected: FAIL (interface method missing).
- [ ] **Step 3:** Implement as described in Interfaces.
- [ ] **Step 4:** Run. Expected: `SUITES OK`, with the existing Vinchin run tests unchanged.
- [ ] **Step 5:** Commit: `refactor(runs): failure reasons come from the source; RunSync is brand-neutral`.

### Task 3: Verification import behind an interface

**Files:**
- Create: `src/Source/VerifyingSource.php`, `src/Vinchin/VerifyMapper.php`
- Modify: `src/Sync/VerifyImport.php` (keep `PER_RUN` and `batch()`, drop `toInput`), `src/Vinchin/VinchinSource.php`, `src/Cron.php::importVerifications`, `src/RestoreTest.php`, `src/AssetTab.php:182`
- Test: `tests/verify_import_test.php`, `tests/restore_test.php`

**Interfaces:**
- `interface VerifyingSource { public function verificationInputs(string $timepointUuid, int $computerId): array; }` returns `array{inputs: list<array<string,mixed>>, error: string}`, with RestoreTest inputs as `VerifyImport::toInput` builds them today.
- `Vinchin\VerifyMapper::toInput(array $row, int $computerId): ?array` is the old `VerifyImport::toInput` body, unchanged.
- `VinchinSource implements VerifyingSource`. Its `verificationInputs` maps `verifications($tp)` rows through `VerifyMapper`.
- `RestoreTest`:
  - `public const IMPORTED_SOURCES = [self::SOURCE_VINCHIN];`
  - `importVinchin` is renamed `importVerification(array $input): bool`, which accepts only `$input['source']` in `IMPORTED_SOURCES`.
  - `isImported(): bool` is used by `canUpdateItem`/`canPurgeItem`/the form.
  - `public static function sourceLabels(): array` returns `manual => 'Entered by hand'` and `vinchin => 'Vinchin'`. The search option and `AssetTab` use it.
  - Stored values are unchanged.
- `Cron::importVerifications` keeps a source only when it is `instanceof VerifyingSource`, with no `method_exists`.

- [ ] **Step 1:** Tests.
  - `verify_import_test.php` calls `VerifyMapper::toInput` with the same assertions as before, plus `check('VerifyImport names no brand', !preg_match('/Vinchin/', file_get_contents(BI_ROOT.'/src/Sync/VerifyImport.php')))`.
  - In `restore_test.php`, replace the two `SOURCE_VINCHIN` regex checks with checks that `canUpdateItem` and `canPurgeItem` call `$this->isImported()`.
  - Add `check('import path takes only listed sources', str_contains($cls, 'in_array($input[\'source\'] ?? \'\', self::IMPORTED_SOURCES, true)'))`.
  - Add `check('Cron uses the interface', str_contains($cron, 'instanceof VerifyingSource') && !str_contains($cron, "method_exists(\$source, 'verifications')"))`.
- [ ] **Step 2:** Run. Expected: FAIL.
- [ ] **Step 3:** Implement as described in Interfaces.
- [ ] **Step 4:** Run. Expected: `SUITES OK`.
- [ ] **Step 5:** Commit: `refactor(restore tests): verification import behind VerifyingSource; Vinchin mapping in its folder`.

### Task 4: Per-type facts in the Registry; connection form; API version column

**Files:**
- Modify: `src/Source/Registry.php`, `src/Connection.php`, `install/install.php`, `src/Sync/DbObjectStore.php:87,150`, `setup.php` (description), `src/Vinchin/VinchinSource.php` (constants)
- Test: `tests/registry_test.php`, `tests/connection_test.php`, `tests/schema_test.php`

**Interfaces:**
- `Registry::types(): array<string,string>` returns the dropdown labels. It is unchanged for Vinchin, and Task 9 adds Veeam.
- `Registry::labels(): array<string,string>` returns short labels for media text (`vinchin => 'Vinchin'`). `DbObjectStore` uses it in place of `types()`.
- `Registry::formFields(string $type): list<string>` returns the source class's `FORM_FIELDS`, or `[]` for an unknown type.
- `Registry::defaults(string $type): array<string,string>` returns the source class's `DEFAULTS`.
- `Registry::gaps(string $type): array<string,string>`: section key (`coverage|restore|activity|targets|...`) => one sentence. It is the source class's `GAPS`, or `[]`.
- `Registry::DEFAULT_TYPE = 'vinchin'`.
- `VinchinSource` constants:
  - `FORM_FIELDS = ['host','verify_tls','auth_mode','api_key','username','password']`
  - `DEFAULTS = ['auth_mode' => 'api_key']`
  - `GAPS = []`
- Schema: `api_version varchar(20) NOT NULL DEFAULT ''` goes both in `CREATE TABLE` and in the `$added` upgrade list.
- Form:
  - Each field row and cell carries `data-bi-field="<name>"`.
  - An inline script reads `Registry::formFields` for every type as JSON and, on Type change, adds `d-none` and `disabled` to the cells not in the selected type's list. Hidden inputs are not posted.
  - The new `api_version` input sits on the Password row.
  - New records take `type` from `DEFAULT_TYPE` and `auth_mode` from `defaults(type)`.
- `setup.php`: the description becomes "Backup Info - backup systems overview and audit evidence for GLPI 11 (PGN GLPI Suite)".

- [ ] **Step 1:** Tests.
  - registry: `formFields('vinchin') === VinchinSource::FORM_FIELDS`, `formFields('nope') === []`, `labels()['vinchin'] === 'Vinchin'`, `gaps('vinchin') === []`.
  - connection: the page source holds `data-bi-field="api_version"`, `Registry::formFields(` and `Registry::DEFAULT_TYPE`, and no literal `?? 'vinchin'`.
  - schema: `install.php` has `'api_version' => "varchar(20) NOT NULL DEFAULT ''"` and the column in `CREATE TABLE`.
- [ ] **Step 2:** Run. Expected: FAIL.
- [ ] **Step 3:** Implement as described in Interfaces.
- [ ] **Step 4:** Run. Expected: `SUITES OK`, including `sync_test`'s `source_label` checks, which are unchanged.
- [ ] **Step 5:** Commit: `refactor(connections): per-type form fields, defaults and gaps from the Registry; api_version column`.

### Task 5: Declared gaps on Audit evidence and in the export; goal-1 test

**Files:**
- Modify: `src/AuditPage.php`, `src/AuditData.php::section`
- Create: `tests/brand_neutral_test.php`
- Test: `tests/audit_page_test.php`

**Interfaces:**
- `AuditPage::withGaps(array $table, array $gaps): array` is pure. `$gaps` is `list<array{connection: string, sentence: string}>`.
  - Each gap appends `' ' . sprintf(__('Not available from %1$s: %2$s', 'backupinfo'), $connection, $sentence)` to `$table['note']`.
  - With no gaps it returns `$table` unchanged.
- `AuditData::section` collects, for the active connections (`sources()` plus each row's `type`), `Registry::gaps($type)[$key]` and returns `AuditPage::withGaps($table, …) + ['errors' => $errors]`.
  - The export already prints `$section['note']` as the sheet's Note line (`front/audit.php:67`), so the Excel sheet carries the same sentence with no export change.

- [ ] **Step 1:** Tests.
  - audit_page:
    - `withGaps(['note' => 'N', 'columns' => [], 'rows' => []], [['connection' => 'VBR-1', 'sentence' => 'Tape is not available from Veeam REST.']])['note'] === 'N Not available from VBR-1: Tape is not available from Veeam REST.'`
    - `withGaps($t, []) === $t`.
  - `brand_neutral_test.php`:
    - For every `*.php` under `src/` (recursive), `front/`, `ajax/` (if present), `hook.php`, `setup.php` and `install/`, excluding `src/Vinchin/`, `src/Veeam/` and `src/Source/Registry.php`, assert no match of `/Backupinfo\\\\(Vinchin|Veeam)\\\\/`.
    - Each offending file is named in the detail.
- [ ] **Step 2:** Run. Expected: audit_page FAIL. brand_neutral should already PASS after Tasks 1–3; if it fails, the detail names what is left.
- [ ] **Step 3:** Implement as described in Interfaces.
- [ ] **Step 4:** Run. Expected: `SUITES OK`.
- [ ] **Step 5:** Commit: `feat(audit): sections and sheets state what a source cannot provide; brand-neutral core test`.

### Task 6: Neutral time helper and the Veeam client

**Files:**
- Create: `src/Source/Time.php`, `src/Veeam/Client.php`, `src/Veeam/ApiError.php`, `tests/veeam_fakes.php`
- Test: `tests/veeam_client_test.php`

**Interfaces:**
- `Source\Time`:
  - `local(mixed $v): ?string` returns `Y-m-d H:i:s` in `date_default_timezone_get()`.
  - `epoch(mixed $v): ?int` accepts ISO 8601 strings with `Z` or an offset, and strings without an offset (read in the default zone).
  - Anything else, including `''`, non-strings and garbage, returns `null`.
- `Veeam\ApiError extends \RuntimeException`:
  - Constructed as `(string $method, string $path, int $status, string $apiVersion, string $detail)`.
  - The message format is `"{$method} {$path}: HTTP {$status}" . ($detail ? " ({$detail})" : '') . " [api {$apiVersion}] - {$hint}"`.
  - `$detail` is the body's `errorCode` and `message`, cut to 200 characters.
  - Hints:

    | Status | Hint |
    |---|---|
    | 400 | `check the API version (1.3-rev2 = 13.1, 1.3-rev1 = 13.0.1)` |
    | 401 | `check the username and password (an account without MFA)` |
    | 403 | `the account's role cannot read this (Backup Viewer; Security Administrator for authorization events)` |
    | 404 | `endpoint not on this server version` |
    | 0 | `no answer: check host, port 9419 and the TLS certificate` |
    | other | `unexpected answer` |

  - It has a public readonly `int $status`.
- `Veeam\Client`:
  - The constructor takes `string $host, Http\Transport $transport, string $username, string $password, string $apiVersion = '1.3-rev2', bool $verifyTls = true, ?\Closure $clock = null`. The clock returns seconds.
  - `login(): void` sends a form-encoded POST to `/api/oauth2/token` (`grant_type=password`, `username`, `password`, built with `http_build_query`).
  - `logout(): void` POSTs `/api/oauth2/logout`. It never throws and is a no-op without a token.
  - `get(string $path, array $query = []): array` returns the decoded JSON.
  - `paged(string $path, array $query = [], int $limit = 200, int $maxRows = 2000, ?callable $stop = null): array` returns the rows as a `list<array>` and sets the public readonly-after-call `bool $lastTruncated`. `$stop` receives each `list<array>` page and returns a bool.
- Client behaviour:
  - Every request sends `x-api-version`, `Accept: application/json`, and `Authorization: Bearer <token>` when logged in.
  - `get`/`paged` log in first when there is no token.
  - The client refreshes when `clock() >= expiresAt - 60`, and on a 401 refreshes once (`grant_type=refresh_token`), else logs in again, then retries once. A second 401 throws `ApiError`.
  - Paging reads `data[]` and `pagination.total`. It stops when the page is empty, at `skip >= total`, when `$stop` returns true, or at `$maxRows`; `lastTruncated` is true only in the last case.
  - Non-JSON or a status ≥ 400 throws `ApiError`.
  - The guard is `private const POST_ROUTES = ['/api/oauth2/token', '/api/oauth2/logout']`. A POST to any other route, or any method other than GET or POST, throws `\LogicException` with no transport call.
  - A transport `\RuntimeException` becomes `ApiError` with status 0 and the curl text as detail.
- `tests/veeam_fakes.php`:
  - `final class VeeamT implements Http\Transport` routes by `"METHOD /path"` (path after the host, query stripped) to queued responses. It records `calls` (method, url, headers, body).
  - Helpers: `vj(mixed $data, int $status = 200): array` and `vpage(array $rows, int $total, int $skip = 0): array`, which gives `{data, pagination:{total,count,skip,limit}}`.

- [ ] **Step 1:** Tests in `veeam_client_test.php`.
  - Time:
    - `Time::local('2026-10-31T17:00:00Z')` is `'2026-11-01 00:00:00'` with `date_default_timezone_set('Asia/Jakarta')`.
    - `Time::local('2026-11-01T00:00:00+07:00')` gives the same.
    - `Time::local('2026-11-01 00:00:00')` gives the same.
    - `Time::local('x')`, `Time::local('')` and `Time::local(null)` are null.
  - Login:
    - The body equals `http_build_query(['grant_type' => 'password', 'username' => 'DOMAIN\\svc', 'password' => 'p&w'])`.
    - The header `x-api-version: 1.3-rev1` appears when constructed with `'1.3-rev1'`.
    - Default port: the URL starts `https://vbr.example.test:9419/api/`. `vbr.example.test:9500` keeps 9500.
  - Paging: three pages of 2 rows with `total` 5 give 5 rows, `skip` values 0, 2, 4, and `lastTruncated` false. With `maxRows` 4, `lastTruncated` is true.
  - 401: a 401 on GET, then a refresh, then a 200 makes exactly one refresh POST and the rows are returned. A refresh answered 400, then a login, then a 200 also works. A 401 after the retry throws `ApiError` with status 401.
  - Near expiry: with `expires_in` 900 and the clock advanced 850 s, the next GET is preceded by a refresh.
  - Guard: invoking the private `request` via reflection with POST `/api/v1/jobs/x/start` throws `\LogicException` and `VeeamT->calls` is unchanged. DELETE throws too.
  - Errors:
    - A 400 message contains `GET /api/v1/jobs`, `HTTP 400`, `[api 1.3-rev2]` and `1.3-rev1 = 13.0.1`.
    - A 403 mentions `Backup Viewer`.
    - A transport throw gives status 0 and the text `port 9419`.
  - No secret:
    - No exception message contains the password, `access-xyz` or `refresh-xyz`.
    - `logout()` swallows a 500.
- [ ] **Step 2:** Run. Expected: FAIL (classes missing).
- [ ] **Step 3:** Implement as described in Interfaces.
- [ ] **Step 4:** Run. Expected: `SUITES OK`.
- [ ] **Step 5:** Commit: `feat(veeam): read-only OAuth2 client with version header, paging, route allowlist and self-explaining errors`.

### Task 7: Veeam run and task-session mapping

**Files:**
- Create: `src/Veeam/RunMapper.php`
- Test: `tests/veeam_mapper_test.php`

**Interfaces:**
- `Veeam\RunMapper::map(mixed $session): ?Run`.
  - It returns null for a non-array, an empty `id`, or `state` ≠ `Stopped` (case-insensitive).
  - The Run fields:

    | Run field | Source |
    |---|---|
    | `historyUuid` | `id` |
    | `jobName` | `name` |
    | `jobUuid` | `jobId` |
    | `start` | `Time::local(creationTime)` |
    | `finish` | `Time::local(endTime)` |
    | `status` | `status(result)` |
    | `statusText` | raw `result.result`, cut to 64 characters |
    | `sizeText` | `''` |
    | `durationS` | `finish - start` in seconds when both exist and are ≥ 0, else null |

- `RunMapper::status(mixed $result): string` applies the Global Constraints status table to `result{result,isCanceled}`.
- `RunMapper::item(mixed $task): ?array{vm_name: string, status: string, error: string}`.
  - `vm_name` is `name`, `status` is `status(result)` and `error` is `result.message`, cut to 255 characters.
  - It returns null when `name` is empty.
- `RunMapper::reason(array $tasks, mixed $session): string` gives the first task `result.message` whose result is Failed, else the first Warning one, else the session's `result.message`, else `''`.

- [ ] **Step 1:** Tests, one `check` per row.
  - Status:
    - Success is `success`.
    - Warning is `abnormal`.
    - Failed is `failed`.
    - Failed with `isCanceled` is `failed`.
    - Success with `isCanceled` is `aborted`.
    - None with `isCanceled` is `aborted`.
    - Stopped None is `abnormal`.
    - `'Bogus'` is `abnormal`.
    - A missing `result` is `abnormal`.
  - Map:
    - `state: Working` gives null.
    - A missing `state` gives null, because it is unsure.
    - A full fixture gives the expected Run, with times converted from `Z` in Asia/Jakarta and `durationS` 3600.
    - A missing `endTime` gives `finish` null and `durationS` null.
  - Item and reason:
    - `item` with no name gives null.
    - `reason` prefers a Failed task, then a Warning task, then the session message, then `''`.
- [ ] **Step 2:** Run. Expected: FAIL.
- [ ] **Step 3:** Implement as described in Interfaces.
- [ ] **Step 4:** Run. Expected: `SUITES OK`.
- [ ] **Step 5:** Commit: `feat(veeam): sessions to runs and per-VM results (unsure is never success)`.

### Task 8: Veeam objects, targets and events mapping

**Files:**
- Create: `src/Veeam/ObjectMapper.php`, `src/Veeam/TargetMapper.php`, `src/Veeam/EventMapper.php`
- Test: `tests/veeam_mapper_test.php`

**Interfaces:**
- `ObjectMapper::newestPoints(iterable $restorePoints): array` returns `lower(name) => array{time: int, backupId: string}`.
  - It keeps the newest **parseable** `creationTime` per `name`.
  - Rows with no name or an unparseable time are ignored.
- `ObjectMapper::map(array $objects, array $newest, array $backupNames): array` returns a `list<BackupObject>`; `$backupNames` maps backupId => name. Each object gets:

  | Field | Value |
  |---|---|
  | `sourceKey` | `'veeam:' . id` |
  | `name` | `name` |
  | `ip` | `''` |
  | `kind` | `type` `VM` → `vm`; `Computer`/agent types → `agent`; otherwise `other` |
  | `jobName` | `$backupNames[newest.backupId] ?? ''` |
  | `lastBackup` | `newest.time ?? null` |
  | `restorePoints` | `(int) restorePointsCount` |
  | `dataSize` | `''` |
  | `abnormalChain` | false |
  | `sourceDeleted` | false |
  | `scheduleHours` | null |
  | `timepointUuid` | null |

  - Rows with an empty `id` or `name` are skipped.
  - When `restorePointsCount` is 0, `lastBackup` is null even if a point matched by name.
- `TargetMapper::map(array $repoStates): array` returns `Target::make()` rows.
  - It skips the scale-out parent (`type` `ExtendableRepository`), whose extents arrive as their own rows.
  - `kind` comes from the type table in the research report. The disk types are WinLocal, LinuxLocal, LinuxHardened and the dedupe appliances. The nas types are Smb and Nfs. The cloud types are AmazonS3*, Azure*, S3Compatible, GoogleCloud*, IBMCloud, WasabiCloud, VeeamDataCloudVault*, VeeamVault and ElevenElevenS3. An unknown type gives `''`.
  - `type` is the raw value and `node` is `hostName`.
  - `total`, `used` and `free` are `"<n> GB"` from `capacityGB`, `usedSpaceGB` and `freeGB`, with the number as sent and no conversion. A field that is missing or not numeric gives `''`.
  - `online` is `yes` or `no` from a boolean `isOnline`, else `''`.
  - `jobs` is `''`.
  - `detail` is `path` plus `' · out of date'` when `isOutOfDate` is true.
- `EventMapper::authorization(array $row): array` returns `Event::make('syslog', …)`, with:
  - `time` = `Time::local(creationTime)`;
  - `end_time` = `processedTime`;
  - `ref` = `id`;
  - `user` = `createdBy` (plus ` → processedBy` when set);
  - `level` = `warning` for Pending, Rejected or Expired, else `info`;
  - `action` = `name`;
  - `message` = `description`;
  - `status` = `state`;
  - `source_ip` = `''`.
- `EventMapper::malware(array $row): array` returns `Event::make('sysalarm', …)`, with:
  - `time` = `Time::local(detectionTimeUtc)`;
  - `ref` = `id`;
  - `user` = `createdBy`;
  - `level` = `critical` for Infected, `warning` for Suspicious, else `info`;
  - `action` = `type`;
  - `message` = `details` + ` · ` + `machine.displayName`;
  - `vm` = `machine.displayName`;
  - `status` = `state`;
  - `item_status` = `severity`.
- `EventMapper::copy(array $session, array $item): array` returns `Event::make('copy', …)`, with:
  - `time` and `end_time` from the session;
  - `ref` = session `id`;
  - `job` = session `name`;
  - `vm` = item `vm_name`;
  - `status` = `RunMapper::status(session.result)`;
  - `item_status` = item `status`;
  - `error` = item `error`.

- [ ] **Step 1:** Tests.
  - Newest points: of two points for `vm-app-01`, the newer wins. A newer point with time `'n/a'` is ignored and the older parseable one is kept. Matching names is case-insensitive.
  - Objects:
    - `vm-app-01` with 7 points and a matched point gives `lastBackup` set and `jobName` `JOB-DAILY`.
    - `vm-db-01` with no point gives `lastBackup` null.
    - An object with count 0 and a stray point gives null.
    - No `id` means the object is skipped.
  - Targets:
    - LinuxHardened is `disk`.
    - Smb is `nas`.
    - AmazonS3 is `cloud`.
    - `'NewThing'` gives `''` with `type` `NewThing`.
    - `capacityGB` 512.5 gives `'512.5 GB'`.
    - A missing `freeGB` gives `''`.
    - `isOnline` false gives `no`, and a missing `isOnline` gives `''`.
    - The ExtendableRepository row is skipped.
  - Events:
    - The authorization level for Rejected is `warning`.
    - Malware Infected is `critical`, with `vm` set.
    - `source_ip === ''` on both.
    - A copy event carries the job, VM and both statuses.
- [ ] **Step 2:** Run. Expected: FAIL.
- [ ] **Step 3:** Implement as described in Interfaces.
- [ ] **Step 4:** Run. Expected: `SUITES OK`.
- [ ] **Step 5:** Commit: `feat(veeam): objects, repositories and security events in the common shapes`.

### Task 9: VeeamSource and its Registry entry

**Files:**
- Create: `src/Veeam/VeeamSource.php`
- Modify: `src/Source/Registry.php`, `src/Source/Run.php` and `src/Source/Event.php` (doc comments: times are the source's wall clock, or GLPI's timezone when the source sends an offset)
- Test: `tests/veeam_source_test.php`, `tests/registry_test.php`

**Interfaces:**
- `final class VeeamSource implements BackupSource, PartialFetch, RunHistorySource, AuditSource`. Task 10 adds the probe interfaces. It does not implement `VerifyingSource` in 0.5.0.
  - The constructor is `(Client $client)`.
  - `public const MAX_TASK_CALLS = 100`.
  - `RUN_TYPES = ['BackupJob']`. Add `AgentBackup` only if the 1.3-rev2 `ESessionType` reference lists it; say so in the commit message.
  - `FORM_FIELDS = ['host','verify_tls','username','password','api_version']`.
  - `DEFAULTS = ['auth_mode' => 'login', 'api_version' => '1.3-rev2']`.
  - `GAPS` is exact copy:

    | Section | Sentence |
    |---|---|
    | `coverage` | `the platform VM list (protected or not) is not read from Veeam in this version.` |
    | `restore` | `SureBackup verifications are not imported; record restore tests by hand.` |
    | `activity` | `Veeam REST has no job operation log, no system alarms (Veeam ONE only) and no login source IP. Four-eyes authorization events need the Security Administrator role and malware events the Incident API Operator role; without them these lists stay empty (see Probe).` |
    | `targets` | `tape is not available from Veeam REST.` |

- `fetch()`:
  - It pages `/backupObjects` (maxRows 5000). Truncation sets the partial flag.
  - It pages `/restorePoints` newest first (`orderColumn=CreationTime`, `orderAsc=false`; confirm the column value in the reference, else page unordered). The `$stop` callback ends paging once every object name has a point, or at maxRows 5000.
  - It pages `/backups` for backupId => name.
  - Then it calls `ObjectMapper`. A `/restorePoints` or `/backups` failure is not fatal: those objects get `lastBackup` null or `jobName` `''`.
  - It always calls `logout()` in a `finally`, and an exception from `/backupObjects` propagates, as Vinchin's fetch does.
- `runs($pageKnown, $maxPages, $pageSize)`:
  - It reads `/sessions` with `typeFilter`, newest first, page by page.
  - It maps through `RunMapper::map`, skipping nulls.
  - It caches each raw session by id for `failureReasons`.
  - It stops after a page whose mapped ids `$pageKnown` accepts, or after `$maxPages`. A page with only running sessions gives `[]` to `$pageKnown`, which RunSync treats as not known.
- `runItems($id)`: `/sessions/{id}/taskSessions` mapped by `RunMapper::item`, from the cache when `failureReasons` already read it.
- `failureReasons($failed)`:
  - It reads task sessions for at most `MAX_TASK_CALLS` failed runs, newest first, into the cache, and gives `RunMapper::reason`.
  - The first refused call stops further reads, and the rest use the session message.
  - The error line is the first `ApiError` message.
- `copyRuns($from, $to)`: `BackupCopyJob` sessions in the window (filtered on `Time::local(creationTime)`), each with its task sessions (cap 50 per call), as `EventMapper::copy`.
- `systemLogs`: `/authorization/events` → `EventMapper::authorization`, filtered to the window.
- `systemAlarms`: `/malwareDetection/events` → `EventMapper::malware`, filtered to the window.
- A 403 on `systemLogs` or `systemAlarms` returns `['rows' => [], 'error' => '']`, so the declared gap applies.
- `jobLogs` and `platformVms` return `['rows' => [], 'error' => '']`.
- `targets()`: `/backupInfrastructure/repositories/states` → `TargetMapper::map`.
- Every AuditSource and RunHistorySource method catches `ApiError` into its `error` field and never throws.
- `Registry`:
  - `types()['veeam'] = 'Veeam B&R 13 (preview, not verified on a server)'` and `labels()['veeam'] = 'Veeam'`.
  - `create()` builds `new VeeamSource(new Veeam\Client(host, new CurlTransport(), username, decrypt(password), api_version ?: '1.3-rev2', verify_tls))`.

- [ ] **Step 1:** Tests in `veeam_source_test.php`, using `VeeamT`.
  - Fetch:
    - Two objects, with points for one only. The `/restorePoints` stop fires once both names are seen.
    - Logout is called.
    - A 500 on `/backups` still returns the objects, with `jobName` `''`.
    - A truncated `/backupObjects` makes `lastFetchWasPartial()` true.
  - Runs:
    - A page of [Stopped Success, Working, Stopped Failed] returns 2 runs.
    - A page with only a Working session sends `[]` to `$pageKnown`.
  - Reasons:
    - The failed run's reason comes from its Failed task.
    - `runItems` for the same id makes no second HTTP call.
    - With 101 failed runs, at most 100 task calls are made.
    - A 403 on the first task call gives one error and no more task calls.
  - Security and targets:
    - A 403 on `/authorization/events` gives `rows` `[]` and `error` `''`.
    - A 500 there gives an error naming the path.
    - `targets()` skips the SOBR parent.
  - Registry:
    - `create(['type' => 'veeam', ...])` is a `VeeamSource`.
    - `formFields('veeam')` contains `api_version` but not `api_key`.
    - `gaps('veeam')['targets']` starts with `tape`.
    - The brand_neutral_test still passes.
- [ ] **Step 2:** Run. Expected: FAIL.
- [ ] **Step 3:** Implement as described in Interfaces.
- [ ] **Step 4:** Run. Expected: `SUITES OK`.
- [ ] **Step 5:** Commit: `feat(veeam): VeeamSource (objects, runs, copies, security events, repositories) behind the common interfaces`.

### Task 10: Veeam Probe

**Files:**
- Create: `src/Veeam/Shape.php`
- Modify: `src/Veeam/VeeamSource.php`
- Test: `tests/veeam_probe_test.php`

**Interfaces:**
- `Shape::missing(array $row, array $required): array` takes `required` as a `list<string>`, in dot paths such as `result.result`, and returns the paths absent from the row.
- `VeeamSource implements ProbeableSource, AuditProbeableSource`.
  - `probe($limit, $search)` reads one page (`limit=$limit`, plus `nameFilter=*<search>*` when given) of `/backupObjects`, `/restorePoints`, `/backups` and `/sessions?typeFilter=BackupJob`. It returns the raw rows, `objects` from the mapper, and `errors`.
  - `probeAudit($limit, $search)` reads `/sessions/{first id}/taskSessions`, `/sessions?typeFilter=BackupCopyJob`, `/authorization/events`, `/malwareDetection/events` and `/backupInfrastructure/repositories/states`.
  - The first raw row of each endpoint is a status line keyed `_check`, with one of these values:
    - `OK`;
    - `refused (role?): <ApiError message>` for a 403;
    - `unexpected shape: missing <paths>`, checked against the fields each mapper reads;
    - `error: <ApiError message>`.
  - Raw rows go through `Probe::redact` as for Vinchin.
  - Its errors include the API version in use.

- [ ] **Step 1:** Tests.
  - Every endpoint answers with valid fixtures, and every `_check` is `OK`.
  - A 403 on `/authorization/events` gives `refused (role?)` there and `OK` elsewhere.
  - A `/sessions` row without `result` gives `unexpected shape: missing result.result`.
  - The probe output, serialized, contains no `access-xyz`.
  - `Probe::fromSource` and `Probe::fromAuditSource` accept a `VeeamSource`.
- [ ] **Step 2:** Run. Expected: FAIL.
- [ ] **Step 3:** Implement as described in Interfaces.
- [ ] **Step 4:** Run. Expected: `SUITES OK`.
- [ ] **Step 5:** Commit: `feat(veeam): Probe checks each endpoint (OK, refused, unexpected shape)`.

### Task 11: Installer checklist and release 0.5.0-rc1

**Files:**
- Create: `docs/veeam-installer-checklist.md`
- Modify: `setup.php` (`PLUGIN_BACKUPINFO_VERSION` `'0.5.0-rc1'`), `CHANGELOG.md`, `README.md` (if present: a Veeam preview section)
- Test: `tests/ui_wiring_test.php` (version string), `tools/build_zip.py` output

- [ ] **Step 1: Write the checklist.** Written for the person installing at another organisation, with no access to us. It covers:
  - the service account: Backup Viewer, plus Security Administrator and Incident API Operator if security events are wanted, and no MFA;
  - choosing the API version: 13.1 → `1.3-rev2`, 13.0.1 → `1.3-rev1`;
  - TLS: the self-signed certificate on 9419;
  - how to read each Probe `_check` line;
  - the research report's 17 open points, rewritten as "what to look for in Probe or swagger". The report itself is not copied, and no link to the local file is given.
- [ ] **Step 1b: Manual page (suite rule, hub §4, 2026-11-09).**
  - `tools/build_zip.py` copies `<suite>/docs/manual/backupinfo.md` to `docs/manual.md` and allowlists it. If the source is missing, the build goes on without it.
  - `front/manual.php`:
    - checks VIEW READ;
    - renders with League\CommonMark (`html_input => 'escape'`, `allow_unsafe_links => false`);
    - shows a plain notice when there is no manual.
  - A **Manual** link (`ti ti-book`) goes in the menu sub-navigation and in the Setup section.
  - The version parser reads "Versi didokumentasikan: X.Y.Z" and skips leading `*`, `_` and backtick characters. It is tested against a Copilot-style bold header (`**0.5.0**`).
  - The page warns when the manual lags the plugin, by major.minor only.
- [ ] **Step 2: Release text.**
  - The CHANGELOG `0.5.0-rc1` entry covers brand-neutral sources, Veeam B&R 13 preview, every declared gap (tape, alarms, job log, login IP, SureBackup, platform VMs), and the new `api_version` field.
  - Bump the version, and update the version test in `ui_wiring_test.php` if it pins it.
- [ ] **Step 3: Test.** Run the full suite. Expected: `SUITES OK`.
- [ ] **Step 4: Zip.**
  - Run `python -I tools/build_zip.py`. Expected: `backupinfov0.5.0-rc1.zip`.
  - The listing contains `src/Veeam/` and `src/Http/` and no `reports/`, `research_notes/` or `.pdf`.
  - Delete `backupinfov0.4.0-rc6.zip` only after the user confirms they no longer need it on the VM; until then keep both.
- [ ] **Step 5: Commit and tag.**
  - Commit: `release: 0.5.0-rc1, brand-neutral sources and Veeam B&R 13 preview`.
  - Tag `v0.5.0-rc1` locally. There is no push.
