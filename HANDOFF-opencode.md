# HANDOFF → OpenCode · backupinfo 0.2.0-rc1 (Vinchin)

Owner: Claude session `backup-info-7c` (reviews everything you commit). Written 2026-10-28.

## 1. Where everything is

- Plugin repo: `PGN GLPI Suite/plugins/Backup Info/` (git, branch `main`, no remote; never add one, never push).
- Spec: `docs/superpowers/specs/2026-10-28-backupinfo-vinchin-design.md` (approved by the user 2026-10-28).
- Plan: `docs/superpowers/plans/2026-10-28-backupinfo-vinchin.md` (13 tasks). **Do the tasks in order.**
- Vinchin reference (read only, not shipped): `python/vinchin (1).py`, `python/vinchin-openapi.yaml`,
  `python/integrations (1).py`. `python/vinchin.py` and `python/integrations.py` are empty; ignore them.
- Patterns to copy: `../OPmanager GLPI Plugin/` (tests harness, `src/Matching/`, `src/Config.php` secrets,
  `src/Cron.php`). Shared suite files: `../../shared/pgnsuite/`.
- Suite rules: `../../_pgn-glpi-suite.md` §0 and §4. Code graph: run `graphify query "<question>"` in this folder
  before grepping.

## 2. Current state

- First commit = 0.1.0-rc2 (deployed on the VM; settings section and Connections page confirmed by the user).
- rc2 still has: plain-text secrets, an unsigned Vinchin client (Vinchin rejects it), rc1 pages that the spec drops.

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
- Do not write manuals or `docs/manual/` (Copilot's job). `CHANGELOG.md` is fine (Task 13).
- Do not deploy, and do not build a zip before Task 13.

## 5. Decisions not to re-litigate

Vinchin only (Sangfor SCP / Hyper-V later via `Source\Registry`); Computer only; match name then IP (`hybrid_name`);
Backup Status = Yes for protected **and** expired; RPO 26 h default; plugin never writes "Wajib Backup"; Python app
stays the ops dashboard (no charts, no freshness bar); storage appliances out of scope.

## 6. Parked (do not fix silently, list them in the log if you hit them)

- Excel import (`src/Import/ExcelImport.php`) stays in the tree, unwired.
- Search option ID range 8370–8379 is pending the orchestrator's confirmation; if it is not confirmed in this file
  before Task 12, use it and flag it.

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

Update **§9 Progress log** after **every** task, then stop at the end of Task 13 (or at the first blocker) and tell
the user "handoff updated". The owner reviews from this file: commits, tests output, deviations.

## 9. Progress log (OpenCode fills this in)

For each task: task number · commit hash · `php tests/run_all.php` last line · files touched · deviations from the
plan and why · anything unverified · questions for the owner.

| Task | Commit | Tests | Deviations / notes | Questions |
|---|---|---|---|---|
| 1 | | | | |
