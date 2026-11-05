# Backup Info 0.4.0 (stage 3): Incidents, Problems and Risk Register findings

Date: 2026-11-05. Branch to create: `feat/v0.4-tickets` from `main` (= v0.3.0).
Status: **approved 2026-11-05** (answers below). Spec: `docs/superpowers/specs/2026-11-03-backupinfo-audit-evidence-design.md` § Stage 3.
Delivery: Claude implements inline, TDD, one commit per task (as for 0.3.0). Every build is an RC until the VM confirms.

## What changed since the spec (and how this plan adapts)

| Spec said | Since 0.3.0 | This plan |
|---|---|---|
| Dedupe on `runs_items.tickets_id` | A run's Computer is decided **at read time** (`RunResolve`: match, pin, job), not stored once | A separate link table keyed by **(runs_id, computers_id)**, filled from the resolved rows |
| Failed run = Vinchin run | Common Run shape; more brands coming | Writers read the GLPI run tables only, so every brand gets tickets without brand code |
| — | Runs are backfilled up to 36 months | **Start time** set when the feature is enabled: older runs never open tickets |

## Rules (from the approved spec, numbers in Setup)

1. **Incident**: one per failed run × in-scope Computer ("Wajib Backup" = Yes), created once, ever.
   - It is created in the Computer's entity, with the Computer linked through `Item_Ticket`.
   - The title is `Backup failed: <computer> (<job>, <start>)`.
   - The content gives the reason, start and end, job, run reference, connection, and a link to the Computer's Backup Info tab.
   - Actors, category, urgency and impact come from the ticket template (see Templates).
2. **Problem**: opened when a Computer has **≥ 3 failed runs in 30 days** and no open plugin Problem.
   - The Computer and every one of those Incidents are linked to it.
   - While that Problem is open, each new Incident is linked to it.
   - Once it is solved or closed, a new streak opens a new Problem.
3. **The plugin never edits, solves or closes a ticket.** It only does these:
   - **add** a ticket;
   - **link** items to a ticket;
   - optionally, **one follow-up** "Backup succeeded again at …" on the open Incident after the next successful run. This is off by default.
4. **Findings**: only when Risk Register is active and `GlpiPlugin\Riskregister\Finding` exists. They are written through the class API only, following the pattern of SAC's `FindingWriter`.
   - Each finding is upserted by (entities_id, code). The plugin never sets `status`, and `is_recursive` is 0.
   - Computers are linked once through `Finding_Item`, and each finding is mapped to ISO 27001:2022 **A.8.13**.
   - When the signal clears, the plugin adds a "Signal cleared on <date>" note and does nothing else.

   | Code | Raised (per entity) when |
   |---|---|
   | `BI-COVERAGE` | an in-scope Computer is Not found / Stale after the sync |
   | `BI-RESTORE` | an in-scope Computer has no passed restore test within its interval |
   | `BI-REPEAT` | a plugin Problem is open for a Computer |
5. **Off until enabled**: Incident/Problem and Findings have separate switches. Enabling stores `tickets_since` = now.
6. **Never fails the sync**: each writer is wrapped; errors go to `backupinfo.log` and one log line.

## Tasks

1. **Schema** (test first: schema_test).
   - Add table `glpi_plugin_backupinfo_tickets` with columns id, runs_id, computers_id, tickets_id, problems_id and date_creation. UNIQUE (runs_id, computers_id), KEY (computers_id).
   - Add config keys: `tickets_enabled`, `tickets_since`, `ticket_template`, `problem_template`, `problem_count` = 3, `problem_days` = 30, `followup_on_success` = 0, `findings_enabled`.
   - Uninstall drops the table.
2. **IncidentPlan (pure).** Input: the resolved failed rows (run × Computer), the existing links, `tickets_since` and the in-scope ids. Output: the Incidents to create and their texts.
   - Tests: dedupe; runs before the start time skipped; out-of-scope Computers skipped; unmatched VMs (id 0) never ticketed; the reason is optional.
3. **ProblemPlan (pure).** Input: failures per Computer within the window, the open plugin Problems, and the new Incidents. Output: the Problems to open and the Incidents to link.
   - Tests: the threshold, the window edges, linking to an already open Problem, and a new streak after a Problem is solved.
4. **TicketWriter (GLPI).**
   - Calls `Ticket::add` (type Incident, entity, `_users_id_requester`, `_groups_id_assign`, `itilcategories_id`, urgency/impact, `items_id` => ['Computer' => [id]]).
   - Then calls `Problem::add`, `Item_Problem::add` and `Problem_Ticket::add`.
   - Writes the link row only after the add succeeds.
   - Runs as the cron user. Rights are checked once, and a missing right gives a clear log line.
5. **FindingWriter (GLPI, guarded).** Port the SAC pattern for the three codes. Before writing it, grep riskregister for the field names of `Finding`, `Finding_Item` and `Finding_FrameworkControl`.
6. **Cron wiring.** After `storeRuns`/`storeEvents`/`storeSnapshots`, call `raiseTickets()`, then `raiseFindings()`. Both use the same `RunResolve` and scope as the audit page.
7. **Setup UI.** A "Tickets and findings" card with the switches, the dropdowns (user, group, category), the numbers, and a dry-run button: "What would be created now", which writes nothing.
8. **Audit page.** Tab 4 Failures gets **Incident** and **Problem** columns, linked to the tickets. This is the remediation evidence auditors ask for.
9. **Release.** CHANGELOG, version 0.4.0-rc1, zip, then a VM test with the switches first off, then on.

## VM test outline (rc1)

1. Upgrade: no ticket appears, because both switches are off.
2. Dry run lists the would-be Incidents for failures after "now". It should list none, since the start time is now.
3. Enable, wait for or cause one failed run on a test VM, then click Sync now:
   - one Incident is created, linked to the Computer;
   - a second sync creates nothing.
4. Three failures within 30 days give one Problem, with the 3 Incidents linked.
5. With Risk Register active: BI-RESTORE appears for a Computer without a restore test, and the status the user sets is kept by the next sync.

## User answers (2026-11-05)

1. The requester is "Backup Info". 2. The actors are DCOC, Backup Tech and the supplier. 3. Only **Failed** runs open an Incident. 4. One Incident per run × Computer.
5. **Requester, groups, supplier and category come from GLPI ticket templates**, not from plugin fields.

### Templates (replaces the requester, group, category, urgency and impact settings of Task 1)

- On install or upgrade, the plugin creates two templates if they are missing, in the root entity and recursive:
  - **"Backup Info - Backup failed"** (TicketTemplate);
  - **"Backup Info - Repeated backup failure"** (ProblemTemplate).
- The admin fills in their predefined fields in Setup › Templates, the normal GLPI screen: requester "Backup Info", assigned groups DCOC and Backup Tech, the assigned supplier, category, urgency and impact.
- The plugin does not create users, groups or suppliers.
- Setup › Backup Info has two dropdowns. They hold the template ids and default to the two templates above.
- GLPI 11 does **not** apply a template's predefined fields when a ticket is added through code (`CommonITILObject::enforceReadonlyFields` applies only the read-only ones). So TicketWriter loads `getFromDBWithData()` itself and merges `$tt->predefined` into the add input. The plugin's own keys win: name, content, type, entity, the linked Computer and the template id.
- The dry run shows which template and which predefined actors will be used. It warns when a template has no assigned group or supplier.
