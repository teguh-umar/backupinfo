# Changelog — Backup Info (backupinfo)

## 0.4.0-rc4 — 2026-11-06 (release candidate)

- **Audit evidence: filters, sort, pages and a column choice on every tab.**
  - Each tab uses GLPI's own table: a Filter button with a filter row, sorting by column, and
    pages with the user's page size. The 500-row screen limit is gone.
  - Columns with few values (statuses, Computers, jobs) filter by a choice list; the others by text.
  - Filters run on every row of the period, not only the rows on screen. The tab title shows
    "(12 of 131)" while a filter is on.
  - **Columns** hides or shows columns. The choice is kept per tab in the browser.
  - The Excel and CSV exports are unchanged: always every row and every column.
- **Backup objects: the backup results that exist, for auditors.**
  - New columns: Restore points, Size, Last run, and Result (green success, red failed, yellow
    other), with the failure reason on hover.
  - Last run is the newest backup run the sync stored for that object (same connection and VM name).
    No call to the backup system.
  - The list uses the same GLPI table: filter row, sort, pages, and the Columns menu. The buttons
    Needs review, Pinned, Matched and All stay as they were.
## 0.4.0-rc3 — 2026-11-06 (release candidate)

- **Optional follow-up "Backup succeeded again"**, off by default. When a later backup of the same
  Computer succeeds, one follow-up is added to its open plugin Incident, with the time, job and VM.
  - It is added once per Incident.
  - The Incident is not solved or closed; the technician decides.
  - The dry run counts them.
- Setup: the "Sync now" text no longer says "hourly"; the interval is the automatic action's own.
- **Setup page in tabs:** one card with the tabs Connections, Backup policy, Fields mapping, and
  Tickets and findings in its header. There is no extra card inside a tab.
  - The Fields tab shows a warning mark when the sync cannot write Fields.
  - Save on any settings tab saves them all.
  - The browser remembers the last tab, so it opens again after Save.

## 0.4.0-rc2 — 2026-11-05 (release candidate)

- **Risk Register codes are now `BCKP-COVERAGE`, `BCKP-RESTORE` and `BCKP-REPEAT`.** The rc1 codes
  started with "BI-", which reads as Business Intelligence.
  - Findings written by rc1 are renamed at the next sync. Their status and links are kept.

## 0.4.0-rc1 — 2026-11-05 (release candidate)

Follow-up of backup failures in GLPI (stage 3). **Everything is off until switched on** in
Setup › Backup Info › Tickets and findings.

- **Incident per failed backup.** One Incident is opened for each failed run × Computer that must be
  backed up, once. Only Failed runs count, not Warning. Runs that started before the switch was
  turned on never open one. The Computer is linked, and the content gives the reason, job, times,
  VM, connection and run reference.
- **Problem for repeated failures.** It opens after 3 failures within 30 days; both numbers are in
  Setup. The Computer and its Incidents are linked to it, and new Incidents are linked while it is
  open. After it is solved, a new streak opens a new Problem.
- **Ticket templates.** "Backup Info - Backup failed" (Incident) and "Backup Info - Repeated backup
  failure" (Problem) are created on upgrade, in the root entity and recursive.
  - Their predefined fields are copied into each ticket: requester, assigned groups, supplier,
    category, urgency and impact. GLPI does not apply them to a ticket added by code.
- **The plugin never edits, solves or closes a ticket.** It only adds tickets and links them.
- **Dry run.** "What would be created now" lists the would-be tickets and writes nothing. It warns
  when a template assigns nobody or has no requester.
- **Risk Register findings**, when Risk Register is active. There is one finding per entity and
  code, mapped to ISO 27001 A.8.13:
  - BI-COVERAGE (BCKP-COVERAGE from rc2): not protected;
  - BI-RESTORE (BCKP-RESTORE from rc2): no passed restore test in its interval;
  - BI-REPEAT (BCKP-REPEAT from rc2): a Problem is open.
  - The status someone sets is kept. When the signal goes, a "Signal cleared" note is added.
- **Tab 4 Failures** shows the Incident and the Problem of each failure.

## 0.3.0 — 2026-11-05

Audit evidence release (ISO 27001, internal audit, ICOFR). Same code as 0.3.0-rc7, confirmed on the
test VM. The entries below, rc1 to rc7, are its content.

## 0.3.0-rc7 — 2026-11-05 (release candidate)

- **The audit page no longer calls the backup system.** Every tab reads GLPI, so the page loads
  faster and still works when the backup system cannot be reached.
- **Daily snapshots.** Each sync stores today's backup targets and platform VMs, one snapshot per
  connection and day; a later sync on the same day replaces it. Retention follows "keep runs (months)".
  A read that fails keeps the last good snapshot.
- **8. Backup targets** shows the newest snapshot inside the chosen period, so a past period shows the
  targets as they were at its end. New columns: **Used at period start** (from the oldest snapshot
  of the period) and **As of**. A period before the first snapshot says so.
- **2. Coverage** takes its platform report from the same snapshots.
- The short-lived cache of live reads (rc4) is removed.

## 0.3.0-rc6 — 2026-11-05 (release candidate)

Groundwork for many backup brands. The plan covers Vinchin, NetBackup, Backup Exec, Veeam, Acronis,
Commvault, Rubrik, HCI backup (Sangfor, VMware, H3C, Proxmox) and RBA-GX.

- **One event shape for every brand.** Copy runs, job logs, system logs and system alarms are stored
  as common events. The fields are: time, end time, ref, user, level, action, message, job, VM,
  status, item status, error, source IP, restore points and size.
  - Each source converts its own rows. Vinchin is the first.
  - The rc5 events, stored in Vinchin's own shape, are cleared once on upgrade. The next sync reads
    them back.
- **8. Backup targets** (new tab, read live) lists the disk, NAS and tape storages with node,
  capacity and online status, then the tape libraries.
  - Each tape group lists the copy jobs that write to it.
  - On Vinchin, storage type 10 is a tape group and 6 is an NFS share.
- **Tab 7 hides this GLPI server's own logins.** These are the plugin's API sessions, several per
  sync.
  - The page says how many it hid. Tick **Show this server's logins** to list them; exports follow
    the same choice.
  - Other actions from that address stay listed.
  - The server's addresses are set in Setup › Backup Info. Blank = the address the web server
    answers on.
  - A new Source IP column is added.
- **Per-run detail reads stop after the first refusal in a sync.** On the VM, Vinchin answers
  `/jobs/history/{uuid}` with "code -1". The failed runs still take their job's Computers.

## 0.3.0-rc5 — 2026-11-05 (release candidate)

- **Runs are linked to Computers when read, not when stored.** A VM matched or pinned later (Backup
  objects › Pin) now brings its older runs into tabs 3 and 4. Stored runs still never change.
  The sync also keeps the VM name of runs whose objects are not matched yet.
- **Off-site copies and activity are stored in GLPI.** These are copy runs, job logs, system logs and
  system alarms.
  - They are read by the sync and stored once, never changed, so they outlive Vinchin's retention.
  - The first sync reads back up to 400 days, bounded by each list's row cap.
  - They are kept as long as runs (Setup: runs kept, months).
  - The cron log shows `events stored copy N, joblog N, syslog N, sysalarm N`.
- Tabs 6 and 7 read the stored events. Only tab 2 (Coverage) is still read live.
- **Probe › Sample audit endpoints** adds cards for backup targets: `/backupmanager/report/storage`,
  `/storages`, `/tapes/job` and `/tapes`. The `/jobs/history/{uuid}` sample now prefers a VM job's
  failed run.

## 0.3.0-rc4 — 2026-11-04 (release candidate)

- The audit page shows one tab per section instead of seven stacked cards.
- Exports name the real user under "Generated by", not "cron".

## 0.3.0-rc3 — 2026-11-04 (release candidate)

Changes after the rc2 VM test.

- **Excel export.** Every section exports as a real `.xlsx` sheet. The CSV link stays.
  - The sheet has a bold header block, a frozen header row, a filter and readable column widths.
  - Text is always written as text, so a value like `=1+1` can never run as a formula.
  - **Export all sections (Excel)** gives one workbook: an Info sheet, then one sheet per section.
  - The workbook is zipped with the ZipStream library GLPI 11 ships, or with ext-zip. Without
    either, the export falls back to CSV.
- **Faster audit page.** The page opens at once, then fills each section with its own request: the
  GLPI sections first, then the three read from the backup system.
- **Live reads are cached a short while** (5 minutes for an open period, 1 hour for a closed one), so
  viewing and then exporting does not page through Vinchin again. A failed read is never cached.
- Coverage and off-site copies no longer call Vinchin when no Computer is in scope.
- **The page says why it is empty.** Either nothing is marked as must be backed up, or N Computers
  are marked but none is in your active entities.

## 0.3.0-rc2 — 2026-11-04 (release candidate)

Audit evidence, stage 2 (spec approved 2026-11-04). Nothing is written to Vinchin; nothing is
written to tickets or the Risk Register yet (stage 3, 0.4.0).

**Backup runs are stored in GLPI** (the ICOFR population)
- The Sync cron now stores every backup run, newest first. It stops at the first page that is
  already stored; the first run backfills up to 20 pages (Setup: runs kept 36 months, 0 = forever).
- Stored runs are never changed. The cron log shows `runs stored N (failed F)`.
- Failed runs:
  - get their per-VM result from Vinchin (at most 100 detail reads per sync);
  - get their reason from the matching job alarm (same job, ±60 s). With no matching alarm the reason
    stays empty; it is never guessed.
- Successful runs take the Computers of their job.

**Restore tests** (ISO 27001 A.8.13)
- New record per Computer: date, method, result, restore time achieved, tester and comments.
  Evidence is attached in its Documents tab.
- Menu: Tools ICT › Backup Info › Restore tests.
- New right "Restore tests"; Super-Admin gets every level.
- How often a test is required is flexible:
  - a Setup default (12 months; 0 = not required);
  - overridable per Computer on its Backup tab (blank = default, 0 = exempt).
- Only a **passed** test restarts the clock. A failed one is listed but does not count.
- The Backup tab of a Computer shows:
  - the status (tested / due within 30 days / overdue / none / not required);
  - the next due date;
  - the last 5 tests;
  - an "Add restore test" link.
- Vinchin restore-point verifications (ping, heartbeat, boot screenshot, integrity) are imported as
  read-only restore tests. Up to 50 restore points are read per sync, in rotation.

**Audit evidence page** (Tools ICT › Backup Info › Audit evidence, report right)
- Per month, quarter or year (default: last full month), for the Wajib Backup Computers in your
  entities. Seven sections:
  1. backup policy;
  2. coverage, including the backup system's own list of unprotected VMs;
  3. backup runs (population);
  4. failures with reason;
  5. restore tests at period end;
  6. off-site copies;
  7. job changes, logins and alarms.
- System log and alarms are shown only to Backup Info configuration users.
- Each section exports as CSV:
  - UTF-8, opens in Excel with accents intact;
  - a header block giving period, scope, who exported it and when, and the plugin version;
  - cells that a spreadsheet would run as a formula are neutralised.

**Probe:** Sample audit endpoints also reads `/jobs/history/{uuid}` (the per-VM result of the
first failed run). This endpoint is in the doc but has not been seen on the VM yet.

## 0.3.0-rc1 — 2026-11-03 (release candidate)

Audit evidence, stage 1 (user 2026-11-03: ISO 27001, internal audit, ICOFR):
- Probe page: **Sample audit endpoints** button. Read-only, one page per Vinchin endpoint:
  failed runs (`/jobs/history`, status 4), job and system alarms, job and system logs, VM
  report, verification reports (`/industry/report`), restore verification and virus scan of a
  sampled restore point, copy history of a copy or replica job. Credentials masked. A refused
  endpoint is one warning line, so the sample shows what this API key may read before the
  audit evidence page and Risk Register / ITSM ticket integration are built on it.

## 0.2.0 — 2026-11-03

Final release: the tree of 0.2.0-rc11, confirmed by the user on the VM (2026-11-03):
Probe and RPO per job schedule, Backup Status = Yes and Backup Media on vm-test-02, no
history on a second sync, values visible from other entities, wrench in Setup > Plugins,
upgrades without errors. Changes since 0.1.0: see the 0.2.0-rc1 to rc11 entries below.

## 0.2.0-rc11 — 2026-11-03 (release candidate)

rc10 checked on the VM: Probe RPO per job (360 h two-week, 48 h daily, 192 h weekly).
Fields were not written at all ("Computers updated in Fields: 0"):
- Cause: "Backup Media - Content" is a **multiple** dropdown, which the plugin refused, and
  that one problem stopped every write, Backup Status included.
- A multiple dropdown media field is now supported: the sync writes one entry (the field is
  filled by the sync), compared with the stored value so history is written only on change.
- Backup Status is written whenever it can be, even if the media field has a problem; Setup
  still lists the problem.
- Sync now shows why Fields were not updated (the mapping problems) instead of only "0", and
  a value Fields refuses is logged once per run with the item and columns.

## 0.2.0-rc10 — 2026-11-03 (release candidate)

rc9 checked on the VM: wrench, Setup, RPO 360 h for VM-TEST-02 with a search, asset status.
- Probe without a keyword showed every row as "26 h (global)": it read the schedules of the
  first five jobs in Vinchin's list, not of the sampled objects' jobs. Probe now reads the
  whole job list (as the sync does) and the details of the sampled objects' jobs; the list is
  not filtered by the keyword, since a VM name need not appear in its job name. The sync was
  not affected.
- Build: the zip is checked for local-only files (.graphifyignore, graphify, CLAUDE*.md,
  dotfiles) and refused if any is found.

## 0.2.0-rc9 — 2026-11-03 (release candidate)

- Setup > Plugins: the wrench opens the PGN Suite settings page on Backup Info's section
  (`config_page`, user 2026-11-03).
- Manual and one-time Vinchin jobs are named as such in the sync log and Probe ("manual job,
  no schedule (global RPO used)") instead of "schedule not understood"; their rows show
  "(global)".

## 0.2.0-rc8 — 2026-11-02 (release candidate)

Job schedule format checked against the official Vinchin v9 RESTful API doc (create/modify
job and archive sections; the GET /jobs/{ID} section is out of date):
- `frequency` "s<N>" = every N weeks, s1–s20, full backups only; blank = every week (was
  read as "unknown"). Anything else ("d3", "s21", 0) = unknown schedule: global RPO, an error
  line naming the job in the sync log and Probe. Never guessed or capped.
- `days`: weekly 7-flag mask with index 0 = Monday, monthly 31-flag mask; 0/1 or booleans.
- `time_strategy_backup_type` "oncetime" / "manual": no schedule (global RPO).
- Any strategy not understood makes the job's whole schedule unknown (it could be the
  shorter one).

## 0.2.0-rc7 — 2026-11-02 (release candidate)

- Job schedule read in the shape this Vinchin really returns (Probe on the VM): weekly `days`
  is a 7-flag weekday mask (`[0,0,0,0,1,0,0]`) and `frequency` a string (`"s2"` = every
  2 weeks). rc6 read them as day numbers and as 1 week, so a two-week job showed 144 h;
  it is now 336 h (RPO 360 h with the default grace). The documented shapes still work.

## 0.2.0-rc6 — 2026-11-02 (release candidate)

- **RPO per backup object from the job's real schedule.** Job names do not follow the schedule
  (a "(WEEKLY)" job runs every two weeks), so the sync reads each job's `time_strategy` from
  Vinchin (`GET /jobs`, then `GET /jobs/{ID}` for the jobs the objects name): daily 24 h,
  every N weeks, weekdays, month days; the longest gap between runs is the interval, and with
  several strategies (full weekly + incremental daily) the shortest wins.
- A backup is **expired** when older than *schedule + grace*. Grace is set in Setup
  (default 24 h, 0–168). The existing RPO (default 26 h) applies only when a job's schedule
  is unknown.
- Asset tab and Probe show the RPO per row and where it came from:
  `360 h (schedule 336 h + 24 h)` or `26 h (global)`. Coverage states the rule.
- A schedule that cannot be read never fails the sync: that object uses the global RPO, and
  the sync log says how many schedules were not read.
- Fields "Backup Status" is unchanged (Yes for protected and expired).
- Upgrade: new column `objects.schedule_hours` (NULL = unknown), new setting `rpo_grace_hours`.

## 0.2.0-rc5 — 2026-11-02 (release candidate)

- Probe page: Backup Info sub-nav (Coverage, Backup objects, Probe) now shows on the Probe page
  too; before, opening Probe hid the other tabs.
- Probe page: job schedules. One page of `GET /jobs` (with the search keyword), then
  `GET /jobs/{ID}` per job, shown raw: `time_strategy` (type 1 daily, 2 weekly, 3 monthly;
  `frequency`; `days`) and `next_time`. Job names do not follow the real schedule (a "(WEEKLY)"
  job runs every two weeks), so the RPO per job will be read from this, once the VM sample
  shows the fields. No RPO change in this build.

## 0.2.0-rc4 — 2026-11-02 (release candidate)

- Probe page: optional **Search** (VM or job name). Vinchin's `search` is sent to both endpoints,
  so the newest runs of one job, with their status, can be checked with API access only
  (no Vinchin console needed). Needed to settle the RPO for WEEKLY jobs.

## 0.2.0-rc3 — 2026-11-02 (user: Setup placement and filter OK on the VM)

From the first Probe run on the VM (rc2):
- **Connections are managed in Setup**: list, add, edit and delete inside Backup Info's section
  of the PGN Suite settings page. The "Connections" entry is gone from the Tools ICT menu; old
  `connection.php` / `connection.form.php` links forward to that section.
- Vinchin's `"ip": "--"` (and `-`, `N/A`) is read as "no IP", so it never takes part in matching.
- Probe's job-history sample is the newest rows (`sort=start_time`, `order=desc`, one retry
  without sort); before, Vinchin returned the oldest rows.
- Backup objects page: the state filter is a bordered button group (the pills had no visible
  borders and an unreadable active state).

## 0.2.0-rc2 — 2026-11-02 (superseded by rc3; Probe checked on the VM)

- Upgrade step "CREATE/PURGE for config UPDATE holders" now reads the rights back and sets its
  one-shot marker `config_rights_v020` only when every holder is complete; otherwise the next
  upgrade retries (hub §4 rule of 2026-11-02). rc1 was never deployed.

## 0.2.0-rc1 — 2026-11-02 (superseded by rc2, never deployed)

Backup status from Vinchin on GLPI Computers. Spec:
`docs/superpowers/specs/2026-10-28-backupinfo-vinchin-design.md`.

### Added
- **Connections** to backup systems (Vinchin now; the source contract is ready for Sangfor SCP and
  Hyper-V). API key and password are encrypted with GLPIKey, never shown again in the form, never
  written to the item history.
- **Vinchin client** speaking the console protocol: signed requests (AES `sign` + `timestamp`),
  CSRF token rotation with retry on code 910087, API-key or login (RSA password) mode, paging.
  Source data: Backup Object list; job history as fallback.
- **Matching** of backup objects to Computers: VM name first, then IP; ambiguous results go to
  review, never to the first hit. Manual pin / unpin on the **Backup objects** page.
- **Status** per Computer: protected (last backup within the RPO, default 26 h), expired, none.
- **Fields plugin integration**: writes "Backup Status" (Yes for protected and expired) and
  "Backup Media - Content", only when the value changes; reads "Wajib Backup" for the backup
  policy and never writes it. Container and fields are chosen by name in Setup.
- **Backup policy and coverage**: mandatory Computers from the "Wajib Backup" field or a manual
  list; **Coverage** page in Tools ICT.
- **Backup** section in the shared Tools ICT asset tab (order 100); search options 8370–8372 on
  Computer.
- **Setup** section on the PGN Suite settings page: connections, RPO (1–720 h), policy mode,
  Fields mapping, Sync now.
- **Probe** page (config UPDATE): a read-only five-row sample per endpoint from one connection,
  showing the raw rows (credentials masked), the plugin's reading and the match result.
- Automatic action **Sync** (hourly).

### Changed
- Rights: `plugin_backupinfo_config` now offers READ, UPDATE, CREATE, PURGE. On upgrade, profiles
  that held config UPDATE receive CREATE and PURGE once.
- Connections table: `host`, `auth_mode`, `username`, `password`, `verify_tls`, sync status
  columns; `api_url` is copied into `host`; `api_url`, `api_secret`, `token`, `site` are dropped.

### Removed
- rc1 pages Dashboard, Jobs, Storage, Report, Assets and their tables (dropped once on upgrade).
  Excel import is kept in the tree but not wired.

### Safety
- A failing connection never deletes its stored objects; an empty answer while objects are stored
  is treated as an error; a job-history fallback run never deletes objects.
- Objects of inactive or deleted connections stop counting as backups.

### Needs confirmation on the VM
Fields writes under cron (history only on real changes, dropdown entries visible to all
entities), connection add/delete for a non-Super-Admin profile, upgrade from 0.1.0-rc2
(markers, dropped tables, rights), Probe page against the real Vinchin.

## 0.1.0-rc2 — 2026-10-28
- Zip with a top folder; duplicate install functions removed; missing `use` imports; Profile
  rights matrix; settings section returned to the shared page; ConfigTab r5.
