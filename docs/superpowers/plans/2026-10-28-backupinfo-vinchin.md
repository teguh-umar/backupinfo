# Backup Info v0.2 (Vinchin) Implementation Plan

> **For agentic workers:** executed by OpenCode via `HANDOFF-opencode.md` (hub §0 rule 10). Steps use checkbox
> (`- [ ]`) syntax. After each task, update the handoff's **Progress log** (see handoff) before moving on.

**Goal:** Pull Vinchin backup objects, match them to GLPI Computers (name, then IP), show backup status on the
asset, and write the Fields values "Backup Status" and "Backup Media - Content".

**Architecture:** Pure, GLPI-free classes (signer, mapper, matcher, status) carry the logic and are unit-tested with
the suite's plain-PHP harness; thin GLPI classes (DB store, Fields writer, cron, tab, forms) wire them in. Sources sit
behind one interface so Sangfor SCP / Hyper-V are one class each later.

**Tech Stack:** PHP 8.2+, GLPI 11 plugin API, ext-openssl, ext-curl. No Composer dependencies.

**Spec:** `docs/superpowers/specs/2026-10-28-backupinfo-vinchin-design.md`

## Global Constraints

- Namespace `GlpiPlugin\Backupinfo`, PSR-4 from `src/`; every global GLPI class used in a namespaced file is imported
  with `use` (rc1 broke three times on this).
- Version string `0.2.0-rc1` in `setup.php` for the build at the end (hub §0 rule 8: lower-case `rc`, no dot).
- Secrets (`api_key`, `password`) encrypted with `(new \GLPIKey())->encrypt()`; never in form values, logs, errors.
- Never raw-write another plugin's tables; Fields values go through the Fields plugin's classes.
- Default RPO 26 hours. Default sync interval 1 hour. Vinchin page size 100, cap 20 000 rows. Job-history lookback
  14 days, cap 3 000 rows. CSRF retry on code `910087`, max 3 retries.
- Lists and pages filter by the user's active entities (`getEntitiesRestrictCriteria`); cron runs across all.
- `lib/pgnsuite/*.php` and `front/pgnsuite.settings.php` are byte-identical copies of `../../shared/pgnsuite/`;
  never edit them.
- Tests: plain PHP like the OpManager plugin (`tests/bootstrap.php` with `check()` / `done()`, `tests/run_all.php`).
  Run: `php tests/run_all.php` → last line `ALL PASSED`.

## Review Focus

1. Vinchin returns `last_time` as epoch seconds, epoch ms, or a date string → all three parse to the same UTC time;
   an unparsable value counts as "no time", never as 1970.
2. Two Computers share a name or an IP → the object is **review** (ambiguous), never matched to the first one.
3. A connection whose secret was saved earlier is edited with the secret field left empty → stored secret kept.
4. Vinchin answers HTML or non-JSON (wrong port 8080 answers 405) → the connection's `last_error` says so in one line;
   other connections still sync; no secret in the message.
5. Fields plugin inactive, or container/field mapping not set → sync and tab still work; Fields writes skipped; Setup
   shows a warning.

---

### Task 1: Test harness, schema and claim-ready install

**Files:**
- Create: `tests/bootstrap.php`, `tests/run_all.php` (copy the OpManager versions, rename `OPM_ROOT` → `BI_ROOT`)
- Rewrite: `install/install.php`, `install/uninstall.php`
- Test: `tests/schema_test.php`

**Interfaces:**
- Produces tables `glpi_plugin_backupinfo_connections` (columns: `id, name, type, host, auth_mode, api_key, username,
  password, verify_tls, is_active, last_sync, last_status, last_error, comment, date_creation, date_mod`),
  `glpi_plugin_backupinfo_objects` (`id, connections_id, source_key, name, ip, kind, job_name, last_backup,
  restore_points, data_size, abnormal_chain, source_deleted, itemtype, items_id, match_state, date_mod`; unique
  `(connections_id, source_key)`; index `(itemtype, items_id)`), `glpi_plugin_backupinfo_policies`
  (`id, itemtype, items_id`; unique `(itemtype, items_id)`).
- Config context `plugin:backupinfo` keys: `rpo_hours` (26), `policy_mode` (`field` | `manual`, default `field`),
  `fields_container`, `fields_status_field`, `fields_media_field`, `fields_mandatory_field` (all store **names**, not
  ids; defaults '' — orchestrator 2026-10-28: resolve by name at run time), `rc_tables_dropped` (one-shot marker).
- Cron: `GlpiPlugin\Backupinfo\Cron`, task `Sync`, frequency `HOUR_TIMESTAMP`.

- [ ] Write `tests/schema_test.php`: string checks on `install.php` that each table/column above is created
  idempotently (`tableExists` / `fieldExists` guards), that `_assets`, `_jobs`, `_storages`, `_mappings` are dropped,
  that `CronTask::register(Cron::class, 'Sync', HOUR_TIMESTAMP` appears, and that `uninstall.php` drops the three
  tables, the config context and the cron. Run → FAIL.
- [ ] Rewrite install/uninstall with `Migration`; install must be safe to re-run (hub §4: install runs on every upgrade).
  Keep `plugin_backupinfo_install()` / `_uninstall()` names; `hook.php` already requires both files.
- [ ] Run `php tests/run_all.php` → PASS. Commit `feat: v0.2 schema, cron and test harness`.

### Task 2: Connection with encrypted secrets

**Files:** Modify `src/Connection.php`, `front/connection.form.php`, `setup.php` (keep `secured_fields` for
`connections.api_key` and `connections.password`; remove `api_secret`, `token`). Test `tests/connection_test.php`.

**Interfaces:**
- Produces `Connection::prepareSecrets(array $input, array $current): array` (pure: empty submitted secret keeps
  current; `_clear_api_key` / `_clear_password` = '' ; non-empty is encrypted via an injected
  `callable(string): string`), `Connection::secret(string $field): string` (decrypted, '' if unset).
- Form fields: name, type (select from `Source\Registry::types()`), host, auth_mode (`api_key` | `login`), api_key,
  username, password, verify_tls, is_active, comment. Secret inputs are `type=password`, value always empty,
  placeholder "set / unchanged" when stored, plus a "clear" checkbox.

- [ ] Tests: empty secret keeps current; new secret is passed through the encryptor; clear flag empties it; form source
  (`src/Connection.php`) never contains `$this->fields['api_key']` or `['password']` in an output call. Run → FAIL.
- [ ] Implement; `prepareInputForAdd/Update` call `prepareSecrets` with `fn($s) => (new GLPIKey())->encrypt($s)`.
- [ ] Run → PASS. Commit `feat: encrypted connection secrets`.

### Task 3: Source contract and registry

**Files:** Create `src/Source/BackupObject.php`, `src/Source/BackupSource.php`, `src/Source/Registry.php`. Test
`tests/registry_test.php`.

**Interfaces (produced):**
- `final class BackupObject` readonly props: `string $sourceKey, string $name, string $ip, string $kind`
  (`vm|database|file|agent|other`), `string $jobName, ?int $lastBackup` (UTC epoch s), `int $restorePoints,
  string $dataSize, bool $abnormalChain, bool $sourceDeleted`.
- `interface BackupSource { /** @return list<BackupObject> */ public function fetch(): array; }`
- `Registry::types(): array<string,string>` → `['vinchin' => 'Vinchin']`;
  `Registry::create(array $connectionRow, callable $decrypt): BackupSource` throws `\InvalidArgumentException` for an
  unknown type.

- [ ] Tests: `types()` value above; unknown type throws. → FAIL → implement → PASS. Commit.

### Task 4: Vinchin signer

**Files:** Create `src/Vinchin/Signer.php`. Test `tests/vinchin_signer_test.php`.

**Interfaces:** `Signer::sign(array $params, int $timestampMs): array` (adds `timestamp`, `sign`);
`Signer::json(array $v): string` (JSON.stringify: `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`, empty array
stays `[]`); `Signer::rsaEncrypt(string $plain): string` (base64, PKCS#1 v1.5). Key `7ebec7acd38b0643c34b09c84ea54393`,
IV `sNONwyJtvi2ch2in`, public key `PUBLIC_KEY_B64` copied from `python/vinchin (1).py` lines 24-26 (wrap as PEM).

- [ ] Golden tests (values produced by the Python client, 2026-10-28):
```php
check('golden 1', Signer::sign(['offset'=>0,'limit'=>100,'search'=>''], 1761638400000)['sign'] ===
  '72eb19f0a053bfc9633ccfe25d735f1a8a7ac8e21b5312ca2be76137abce67a5e4b11f8c4734f6c68943f1bee6b8d49897a9a183db90a71082eaf950148daace');
check('golden 2 (array + bool kept)', Signer::sign(['search'=>'','sort'=>'item_name','order'=>'desc','module_type'=>[],'offset'=>0,'limit'=>100,'flag'=>true], 1761638400000)['sign'] ===
  '4e36b188bfd24df99fae9734f515e1aa05c060301ffa6a02961ec8c92e10cf7a78754f2060d8f5e09c5e9db66db480690e1213d189181a9a822f7a9dd9df611f5cfa5fe8560c92c6dd11d6dd2219a68fb1f383d067cf6c0241556768f7c35db38419aef8979b267c126cfb560b3324c797ad345332ea55e38b7c02c6bb124ee1');
check('rsa output is 128 bytes base64', strlen(base64_decode(Signer::rsaEncrypt('x'))) === 128);
```
  Note: keys are sorted **before** encoding and `timestamp` is included in the sort.
- [ ] → FAIL → implement with `openssl_encrypt(..., 'aes-256-cbc', KEY, OPENSSL_RAW_DATA, IV)` + `bin2hex` → PASS. Commit.

### Task 5: Vinchin client (transport, CSRF, paging)

**Files:** Create `src/Vinchin/Transport.php` (interface), `src/Vinchin/CurlTransport.php`, rewrite
`src/Api/VinchinClient.php` → move to `src/Vinchin/Client.php` (delete the old file). Test `tests/vinchin_client_test.php`.

**Interfaces:**
- `interface Transport { /** @return array{status:int, headers:array<string,string>, body:string} */
  public function send(string $method, string $url, array $headers, ?string $body, bool $verifyTls): array; }`
- `Client::__construct(string $host, Transport $t, string $apiKey = '', string $username = '', string $password = '',
  bool $verifyTls = false, ?\Closure $clock = null)`; base URL `https://<host>/api/v1` (host may already carry
  `https://`; strip trailing `/`).
- `Client::login(): void`, `Client::postPaged(string $path, array $params, int $page = 100, int $max = 20000): array`,
  `Client::getPaged(string $path, array $params, int $page = 100, int $max = 3000, ?callable $stop = null): array`.
- Errors: `\RuntimeException` with `"<path>: code <code> <message>"` or `"<path>: HTTP <status> not JSON (<first 120
  chars>)"`; never the key, password or token.

- [ ] Tests with a fake Transport: headers `x-api-version: 1.0-rev0`, `X-Requested-With`, `X-API-Key` (api-key mode,
  no `Authorization`); `__token__` from a response is sent as `X-Csrf-Token` next; code `910087` retried, stops after
  3 retries; POST body is JSON with `Content-Type: application/x-www-form-urlencoded; charset=UTF-8`; GET puts params
  in the query with booleans as `true`/`false`; paging stops at `total`; login tries RSA password then plain and sets
  `Authorization` without `Bearer`; non-JSON body → RuntimeException without secrets (Review Focus 4). → FAIL.
- [ ] Implement; `CurlTransport` sets `CURLOPT_SSL_VERIFYPEER/HOST` from `$verifyTls`, timeout 30 s. → PASS. Commit.

### Task 6: Vinchin mapping and source

**Files:** Create `src/Vinchin/ItemMapper.php`, `src/Vinchin/JobHistoryMapper.php`, `src/Vinchin/VinchinSource.php`
(implements `BackupSource`). Register in `Registry`. Tests `tests/vinchin_mapper_test.php`.

**Interfaces:**
- `ItemMapper::map(list<array> $rows): list<BackupObject>`; `ItemMapper::parseTime(mixed $v): ?int`.
- `JobHistoryMapper::map(list<array> $rows, list<string> $knownVmNames, int $nowS, int $lookbackDays = 14): list<BackupObject>`.
- `VinchinSource::fetch()`: `postPaged('/backup_data/items/list', <empty filter body from the YAML BackupItemsFilter>)`
  → `ItemMapper`; on RuntimeException, `getPaged('/jobs/history', ['search'=>'','sort'=>'start_time','order'=>'desc'])`
  (retry without `sort` if refused) → `JobHistoryMapper`.

- [ ] Tests (all from `python/vinchin-openapi.yaml`): `module_type_des=Virtualization` → kind `vm`, `sourceKey` = VM
  name, two rows with the same VM name merge (latest `last_time`, summed restore points, job names joined with ", ");
  `item_name` `"VM-A(10.1.2.3)"` → name `VM-A`, ip `10.1.2.3`; `vcenter_name` never becomes ip; non-VM keyed by
  `item_uuid`, name `"<item_name> @ <instance_name>"`; `parseTime(1761600000)`, `parseTime(1761600000000)` and
  `parseTime('2025-10-28 04:20:00')` all equal `1761600000` (Review Focus 1; strings are Vinchin-server local time,
  `Asia/Jakarta`, UTC+7);
  `parseTime('x')` and `parseTime(null)` → null; flags map; job history: `WEEKLY COPY_VM-APP-HUB` with known
  `VM-APP-HUB` → that VM, restore/recovery/verify/migrat/mount/instant skipped, status regex
  `succe|complet|finish|ok|done|成功|完成`. → FAIL → implement → PASS. Commit.

### Task 7: Matcher

**Files:** Copy `plugins/OPmanager GLPI Plugin/src/Matching/{Matcher,AssetLookup}.php` and its DB lookup
(`DbAssetLookup`) into `src/Matching/`, change namespace, restrict lookup to `Computer` (not deleted, not template).
Copy and adapt `tests/matcher_test.php`.

**Interfaces:** `Matcher::match(string $name, string $ip, string $mode): array{state,itemtype,items_id,source}`;
backupinfo always calls mode `hybrid_name` (name, then IP).

- [ ] Tests: name hit wins; no name → IP; two Computers with the name → `review` (Review Focus 2); name match is
  case-insensitive. → FAIL → implement → PASS. Commit.

### Task 8: Status rules

**Files:** Create `src/Status.php`. Test `tests/status_test.php`.

**Interfaces:** constants `PROTECTED='protected'`, `EXPIRED='expired'`, `NONE='none'`;
`Status::of(list<array{last_backup:?int}> $objects, int $rpoHours, int $nowS): string`;
`Status::hasBackup(string $status): bool` (true for protected and expired);
`Status::mediaText(list<array{kind:string,job_name:string}> $objects, string $sourceLabel): string`
→ e.g. `"Vinchin · VM · DAILY_PRODUCTION"`, '' if no objects.

- [ ] Tests: newest backup age 25 h with RPO 26 → protected; 27 h → expired; objects with only null times → expired
  (it has a backup object); no objects → none; `hasBackup(expired) === true`. → FAIL → implement → PASS. Commit.

### Task 9: Fields bridge

**Files:** Create `src/Fields/FieldsBridge.php`. Test `tests/fields_bridge_test.php` (pure parts only).

**Interfaces:**
- `FieldsBridge::available(): bool` (Fields plugin active and container/field config set).
- `FieldsBridge::readMandatory(string $itemtype, int $id): ?bool`.
- `FieldsBridge::write(string $itemtype, int $id, bool $hasBackup, string $media): bool` — writes only changed values.
- Setup helpers: `containers(): array<int,string>` for Computer, `fields(int $containerId): array<string,string>`.

- [ ] Fields source (read only): `C:/Users/<user>/Downloads/glpi/marketplace/fields/` (version **1.23.3**; it is
  under `marketplace/`, not `plugins/`). Write with:
```php
(new \PluginFieldsContainer())->updateFieldsValues([
    'plugin_fields_containers_id' => $containerId,
    'items_id' => $id, 'itemtype' => $itemtype, 'entities_id' => $entityId,
    $statusField => $hasBackup ? 1 : 0,                       // yesno field: column = field name
    'plugin_fields_' . $mediaField . 'dropdowns_id' => $dropdownId, // dropdown field
], $itemtype, true);   // true = massiveaction: validate only the keys sent (container.class.php:1703)
```
  It adds or updates the row in the generated `PluginFields<Itemtype><container>` class and builds history
  (`container.class.php:1366-1453`). Read the current values first with the same generated class
  (`PluginFieldsContainer::getClassname($itemtype, $containerName)`, `getFromDBByCrit(['items_id'=>$id])`) and skip
  the call when nothing changed. Dropdown entries: class `PluginFieldsDropdown::getClassname($systemName)` =
  `PluginFields<fieldname>Dropdown`; find by `name`, `add(['name'=>…, 'entities_id'=>0, 'is_recursive'=>1])` if absent.
  Check that "Backup Media - Content" is really a dropdown (`glpi_plugin_fields_fields.type`) and handle `text` too.
- [ ] Rules (orchestrator, 2026-10-28): resolve container and fields **by name at run time** on every sync (ids are
  never stored); if the container or a field is missing or inactive, skip that write and log one warning per run
  (`Toolbox::logInFile('backupinfo', …)`) plus show it in Setup; **never create containers or fields** (creating a
  value entry inside an existing dropdown field is allowed); write only when the value changed, so an asset gets
  history only on a real change, never hourly.
- [ ] Tests: `available()` false when the plugin is inactive or config empty (Review Focus 5); a missing or inactive
  field → skipped with a warning, no write, no create; a write with unchanged values does nothing (fake reader). →
  FAIL → implement → PASS. Commit.

### Task 10: Sync service and cron

**Files:** Rewrite `src/Sync/SyncService.php`; create `src/Sync/ObjectStore.php` (interface) + `src/Sync/DbObjectStore.php`;
create `src/Cron.php`. Delete `src/Import/ExcelImport.php` from the menu wiring only (keep the file, unused). Test
`tests/sync_test.php`.

**Interfaces:**
- `SyncService::run(array $connectionRow, BackupSource $source, ObjectStore $store, Matcher $m, int $nowS): array{fetched:int,matched:int,review:int,unmatched:int}`:
  upsert by `(connections_id, source_key)`, keep a manual match if present, match only `kind=vm`, delete rows of this
  connection not seen in this run.
- `SyncService::applyToAssets(ObjectStore $store, FieldsBridge $f, int $rpoHours, int $nowS): int` (assets touched).
- `Cron::cronSync(\CronTask $task): int` runs every active connection; one failure records `last_status='error'`,
  `last_error` (one line) and continues (Review Focus 4); then `applyToAssets`.
- `Cron::cronInfo(string $name): array` description "Sync backup objects".

- [ ] Tests with fakes: stale rows deleted; one throwing source doesn't stop the next; counts returned. → FAIL →
  implement → PASS. Commit.

### Task 11: Policy

**Files:** Create `src/Policy.php`. Test `tests/policy_test.php`.

**Interfaces:** `Policy::isMandatory(string $itemtype, int $id): bool` (mode `field` → `FieldsBridge::readMandatory`
=== true; mode `manual` → row in `_policies`); `Policy::setManual(array $ids): void`; `Policy::coverage(): array{mandatory:int,protected:int,expired:int,none:int}`
(entity-restricted).

- [ ] Tests: mode switch uses the right source; field unset → not mandatory. → FAIL → implement → PASS. Commit.

### Task 12: UI

**Files:** Create `src/AssetTab.php` (tab "Backup" on Computer), `front/coverage.php`, `front/objects.php`
(unmatched/review list with a pin-to-Computer action requiring config UPDATE); modify `src/SuiteConfig.php`
(section: connections link, RPO, policy mode + manual picker, Fields mapping selects, Sync now, warning when
`FieldsBridge::available()` is false), `src/Menu.php` (entries: Coverage, Objects, Connections only),
`setup.php` (register tab on Computer, `$PLUGIN_HOOKS['getAddSearchOptionsNew']` for Computer: backup status, last
backup). Delete `front/{dashboard,jobs,report,storage,assets}.php`. Test `tests/ui_wiring_test.php`.

- [ ] Tests (source-string checks, OpManager style): tab registered on Computer; every front controller calls
  `Session::checkRight` with the right constant; Sync now checks config UPDATE and CSRF; deleted pages absent from
  `Menu.php`; search options IDs in 8370–8379 (confirmed free by the orchestrator, 2026-10-28). →
  FAIL → implement → PASS. Commit.

### Task 13: Build

**Files:** Create `tools/build_zip.py` (top folder `backupinfo/`; exclude `python/`, `docs/`, `tests/`, `tools/`,
`graphify-out/`, `CLAUDE.local.md`, `.claude/`, `.git*`, `HANDOFF-opencode.md`, `*.pdf`, `*.xlsx`, `*.zip`); set
version `0.2.0-rc1`; add `CHANGELOG.md`.

- [ ] Build; list the zip; confirm none of the excluded paths and both shared-file SHAs (`46feaa6c…`, `4bff4a4b…`)
  inside. Run all tests. Commit `build: 0.2.0-rc1`. Do **not** deploy or push.
