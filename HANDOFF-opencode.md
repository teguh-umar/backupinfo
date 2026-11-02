# HANDOFF → OpenCode · backupinfo 0.2.0-rc1 (Vinchin)

Owner: Claude session `backup-info-7c` (reviews everything you commit). Written 2026-10-28.

## 1. Where everything is

- Plugin repo: `PGN GLPI Suite/plugins/Backup Info/` (git, branch `main`, no remote; never add one, never push).
- Spec: `docs/superpowers/specs/2026-10-28-backupinfo-vinchin-design.md` (approved by the user 2026-10-28).
- Plan: `docs/superpowers/plans/2026-10-28-backupinfo-vinchin.md` (15 tasks). **Tasks 1-12 are DONE (see §2).
  You do Tasks 13, 14 and 15, in that order.**
- Vinchin reference (read only, not shipped): `python/vinchin (1).py`, `python/vinchin-openapi.yaml`,
  `python/integrations (1).py`. `python/vinchin.py` and `python/integrations.py` are empty; ignore them.
- Patterns to copy: `../OPmanager GLPI Plugin/` (tests harness, `src/Matching/`, `src/Config.php` secrets,
  `src/Cron.php`). Shared suite files: `../../shared/pgnsuite/`.
- Fields plugin 1.23.3 source (read only, for Task 9): `C:/Users/<user>/Downloads/glpi/marketplace/fields/`.
  Never read `Downloads/glpi/config/` or `Downloads/glpi/files/` (hub §0 rule 7), and never search recursively from
  `Downloads/glpi/`.
- Suite rules: `../../_pgn-glpi-suite.md` §0 and §4. Code graph: run `graphify query "<question>"` in this folder
  before grepping.

## 2. Current state

- Work on branch **`feat/v0.2-vinchin`** (not `main`). `main` = 0.1.0-rc2 (deployed on the VM).
- Tasks 1-12 were implemented by Claude sub-agents (2026-10-28..11-02, while rule 10 was suspended) and each passed a
  task review: commits `c32bc7d..a6c7885`. Offline suite: 511 PASS, 0 FAIL.
- Read before starting: the existing code under `src/` (Source, Vinchin, Matching, Fields, Sync, Policy, UI classes)
  and `tests/` — follow their style. Exact interfaces are in the plan's Task 1-12 blocks; the code is authoritative
  where they differ.
- **Test command** (local PHP has no php.ini):
  `php -d extension_dir="C:/Users/<user>/Downloads/php/ext" -d extension=openssl -d extension=curl tests/run_all.php`
  → last line `SUITES OK` (each suite prints `ALL PASS`).
- Orchestrator rulings since the plan was written: AssetTab section order is **100**; search options 8370-8372 are
  registered via `plugin_backupinfo_getAddSearchOptionsNew()` in `hook.php`; `plugin_backupinfo_config` now offers
  READ|UPDATE|CREATE|PURGE (Task 13 grants the new bits on upgrade).

## 3. Verify first

- `php -v` ≥ 8.2 with openssl and curl. `php tests/run_all.php` does not exist yet; Task 1 creates it.

## 4. Rules for every task

- TDD: failing test → code → passing test → one commit per task (`feat: …`, end the message with your tool's
  attribution if any). Never `--amend`, never `--no-verify`, never push.
- Every global GLPI class used in a namespaced file needs a `use` line (or a leading `\`).
- Never edit `lib/pgnsuite/*.php` or `front/pgnsuite.settings.php` (byte-identical shared copies, `-text`).
- Never write another plugin's tables directly; Fields goes through Fields classes (Task 9).
- No secret (API key, password, access token) in any output, log, exception message or test fixture that looks real.
- Entity rule: user-facing lists use `getEntitiesRestrictCriteria`; cron sees all entities.
- Install must be safe to run again (it runs on every upgrade).
- Do not touch `python/`, `*.pdf`, `graphify-out/`, `CLAUDE.local.md`, `.claude/`.
- Do not write manuals or `docs/manual/` (Copilot's job). `CHANGELOG.md` is fine (Task 15).
- Do not deploy, and do not build a zip before Task 15. The zip also excludes `.superpowers/`; check the SHA-256 of
  **all four** shared files inside the zip (`lib/pgnsuite/{AssetTab,ConfigTab,Menu}.php`, `front/pgnsuite.settings.php`)
  against `../../shared/pgnsuite/`.
- The probe page (Task 14) must never write anything and never print a secret; redact before output.

## 5. Decisions not to re-litigate

Vinchin only (Sangfor SCP / Hyper-V later via `Source\Registry`); Computer only; match name then IP (`hybrid_name`);
Backup Status = Yes for protected **and** expired; RPO 26 h default; plugin never writes "Wajib Backup"; Python app
stays the ops dashboard (no charts, no freshness bar); storage appliances out of scope.

## 6. Parked (do not fix silently, list them in the log if you hit them)

- Excel import (`src/Import/ExcelImport.php`) stays in the tree, unwired.

Orchestrator conditions (2026-10-28, binding): search option IDs 8370–8379 on Computer are confirmed free; Fields
container/fields resolved **by name at run time**, missing or inactive → skip with a logged warning, **never
auto-create** them; Fields written **only on change**; the rc-table drop runs once behind the `rc_tables_dropped`
marker.

## 7. Gotchas that already cost a fix round

- rc1 died on missing `use Toolbox;`, `use Dropdown;`, `use Html;` and on `Html::autocompletionTextField()` (removed
  in GLPI 10). Run `php -l` and grep for unimported `Foo::` before each commit.
- `hook.php` + `setup.php` both declaring `plugin_backupinfo_install()` → "Cannot redeclare". Only `hook.php`
  requires `install/*.php`.
- A pgnsuite section provider must **return** `['title','icon','order','render']`, not echo HTML.
- `displayRightsChoiceMatrix()` needs `[['itemtype'=>'','rights'=>[...],'label'=>...,'field'=>...]]` and the form
  needs `Html::hidden('id')`.
- `$DB` values arrive as strings; cast before comparing.
- Vinchin sign: sort keys **including** `timestamp` before JSON; JSON must keep `[]`, booleans and unicode unescaped;
  the golden values in Task 4 come from the Python client, so a mismatch is a PHP bug.
- `vcenter_name` on a VM is the hypervisor host IP, never the VM's IP.
- Port 8080 on Vinchin is not the API (answers 405); the API is on 443.

## 8. When done (or blocked)

Update **§9 Progress log** after **every** task, then stop at the end of Task 15 (or at the first blocker) and tell
the user "handoff updated". The owner reviews from this file: commits, tests output, deviations.

## 9. Progress log (OpenCode fills this in)

For each task: task number · commit hash · `php tests/run_all.php` last line · files touched · deviations from the
plan and why · anything unverified · questions for the owner.

| Task | Commit | Tests | Deviations / notes | Questions |
|---|---|---|---|---|
| 1-12 | c32bc7d..a6c7885 | SUITES OK (511 pass) | done by Claude sub-agents, reviewed | — |
| 13 | | | | |
| 14 | | | | |
| 15 | | | | |
