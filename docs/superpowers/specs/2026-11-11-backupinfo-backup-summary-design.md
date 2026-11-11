# Backup Info: Backup summary on the Backup objects page (design)

Date: 2026-11-11. Owner: the backupinfo session (hub §6 contact).

**Branch: separate** (the user, 2026-11-11): `feat/backup-summary`, cut from `feat/v0.5-veeam` at
0.5.0-rc4, so 0.5.0 final (Veeam + manual) is not delayed. **Version number not decided:** the user
is still discussing 0.6.0. Ask before bumping `setup.php`.

## 1. Intent

The user's DCOC "Backup & Snapshots" portal has a **Backup summary** tab. The user gave it as the
reference, as a GIF (local only, see §5). It answers: **"Across every backup system, what is
protected, how old is the last backup, how much data is it, and what needs attention?"**

Two audiences ask for that summary:

- the ICT team, **on screen**;
- managers and auditors, **as an Excel file** they receive.

The user wants both (2026-11-11).

Backup Info already stores nearly every fact the portal shows, in `glpi_plugin_backupinfo_objects`,
and the **Backup objects** page already lists them. Its columns since 0.4.0-rc5 are Restore points,
Size, Last run and Result. So this is **not a new page.** The summary cards go **on top of the Backup
objects page**, and the objects table stays the single table (the user's choice: "lebih simple").

## 2. Goals

1. **Source switch** at the top: **All sources**, then one button per active connection (e.g. "Vinchin A"), using the connection's name. Every card and the table follow the choice.
   - A line on the right says when the data was last synced (the oldest `last_sync` among the chosen
     connections) and how many objects are matched to a Computer, e.g. "248 of 271 matched to a
     Computer".
2. **Five KPI cards:**
   - **Protected objects:** the object count, with "N kinds · M sources" under it.
   - **Fresh backups:** % of objects with a last backup ≤ 24 hours, with "N objects ≤ 24 hours".
   - **Total data:** the sum of object sizes (§4.3), "protected data size".
   - **Restore points:** the sum, with "average X per object".
   - **Needs attention:** the count (§4.2), with a one-line rule under it.
3. **"Last backup age" bar** (the user's renamed "freshness"; Indonesian "Umur backup terakhir").
   - One stacked bar with five segments: ≤ 24 hours, 1–3 days, 3–7 days, > 7 days, No timestamp.
   - The legend gives the count and % of each.
   - **Clicking a segment filters the table.** More than one segment can be on at once.
   - Colours: green, light green, amber, red, grey.
4. **Three cards side by side:**
   - **By kind:** one row per object kind (§4.1) with count, total size, and a bar of the % ≤ 24 hours.
   - **Needs attention:** a scrollable list. Each line gives the name, then "Last backup N days ago ·
     job", newest problem first (§4.2).
   - **Largest objects:** the top 10 by size, with a bar relative to the largest.
5. **The objects table** stays as it is today (filter row, sort, pages, Columns menu, pin/unpin,
   restore-test link). It gains:
   - an **Age** column with the age bucket badge;
   - the filter from the age-bar clicks;
   - a **Needs attention** quick filter button next to the existing Needs review / Pinned / Matched
     buttons.
6. **Menu entry "Backup summary"** in the Backup Info menu. It opens the same Backup objects page
   scrolled to the cards (an anchor), so someone looking for "summary" finds it. There is still only
   one page.
7. **Excel export** (.xlsx, the user's preferred format):
   - **Sheet 1 "Summary":** the KPIs, the Last backup age buckets, By kind, Needs attention (every
     row) and Largest objects.
   - **Sheet 2 "Objects":** every row of the table under the current source choice and filters, all
     columns.
   - Header block as on the Audit evidence exports: source choice, filters, last sync time, generated
     by (the real user), when, and the plugin version.
   - The same text-not-formula safety as the existing Xlsx writer.

## 3. Non-goals

- The portal's other tabs. Backup coverage has its own design (on hold). Policy compliance, Expired
  snapshots and Sources & exclusions are out of scope.
- Live calls to any backup system on page load or export.
- A chart library. Bars are Tabler `progress` and plain CSS.
- Changing how the sync stores objects, or how Protected / Expired are computed (`Status`, `Rpo`).
- The portal's "Inventory VM" mapping and the Environment and Platform/host filters. Environment and
  platform come from the Coverage design (on hold). They can be added to this table when that design
  is built.

## 4. Decisions

### 4.1 Kind (object type)

- Kinds are already stored brand-neutral: both mappers write `vm`, `database`, `file`, `agent` or
  `other`, and `Status::KIND_LABELS` gives the labels. "By kind" groups by these, with no new mapping.
- An unknown kind is **Other**, never dropped (my call).

### 4.2 Needs attention (my call; the portal uses "abnormal / source deleted / > 7 days")

An object needs attention when **any** of these holds:

- `abnormal_chain` = 1 (the backup chain is broken);
- `source_deleted` = 1 (the protected VM/DB is gone at the source, but backups remain);
- its last backup is **older than its own RPO** (schedule + grace, or the global RPO). This is the
  plugin's existing **Expired** rule, not a fixed 7 days, so a two-week job is not flagged on day 8;
- the newest stored run for it **failed** (the Result column is red);
- it has **no last backup** at all.

The card's sub-line names the reasons and their counts. The list shows the reason as a badge per
line.

### 4.3 Size

- `data_size` is stored as text (e.g. "22.0 GB"). Totals and "Largest" parse it to bytes (KB, MB,
  GB, TB, PB, decimal point).
- A value that cannot be parsed counts 0 in totals, and the table shows it as stored.
- Displayed totals use binary or decimal units consistently with how the sources write them. The
  builder checks Vinchin's and Veeam's stored strings and states the choice.

### 4.4 Visibility (same as the page today)

- Rows matched or pinned to a Computer follow the user's **active entities** (Computer READ).
- Rows with no Computer are shown only to **config READ** holders.
- **Every card counts only the rows this user can see**, so the cards and the table always agree.
- The Excel export follows the same rule.

### 4.5 What the cards count, and the buttons (my call, after the builder check)

- **The cards follow only the source switch**, never the match-state buttons, the filter row or the
  age-bar clicks. They always describe every visible object of the chosen source, so "Protected
  objects" and "Total data" mean the same thing on every visit. The page loads every visible row of
  the source once. The cards use all of them, and the table applies its filters to them.
- **Excel:** the "Summary" sheet follows the cards (source only). The "Objects" sheet follows the
  table (source and filters). Each sheet's header says which.
- **Default view:** the "Backup summary" menu entry opens the page with the table on **All**. The
  existing "Backup objects" entry keeps today's default (`state=attention`), so the habits and URLs
  of today stay the same.
- **Button names:**
  - The existing "Needs review or not found" button (key `attention`, kept) is relabelled
    **"Not matched"**, so it no longer clashes with the new name.
  - The new **"Needs attention"** is a **toggle** that combines with the state buttons, e.g.
    Matched + Needs attention.

### 4.6 Inactive connections (my call)

- Objects of an inactive connection already stop counting as backups (0.2.0 safety rule). The cards,
  the source buttons and the "last synced" line therefore **leave them out**.
- The table under **All** still lists them as today, with their Source. The Excel header notes how
  many such rows exist.

### 4.7 Facts a source does not report (my call)

- Veeam stores no size, and it does not set `abnormal_chain` or `source_deleted`.
- This follows the existing **declared gaps** rule. The source declares these as gaps, and the page
  says so instead of showing silent zeros:
  - Total data, By kind (size) and Largest objects carry the note "Size not reported by: Veeam A";
  - with only Veeam chosen, those cards show "Not reported by this source";
  - the Needs attention sub-line notes that the abnormal and source-deleted reasons are not checked
    for that source.
- The Excel Summary sheet carries the same notes.
- Reading Veeam sizes is not part of this design; it is a later Veeam change.

### 4.8 Look

GLPI 11 / Tabler cards, badges and progress bars, native to GLPI, not a copy of the portal. Status
colours as in §2.3; Needs attention uses red/amber badges.

## 5. Constraints

- **GLPI 11, PHP 8**, no Composer dependencies beyond what GLPI ships. Reuse `AuditTable`/`TableUi`
  for the table and `Xlsx` for the export.
- **Rights:** the page keeps `plugin_backupinfo` (VIEW) READ as today. Pin/unpin keep config UPDATE.
  The export needs the same right as viewing.
- **Performance:** hundreds of objects. The cards are computed in one pass over the rows the page
  already loads. No query per object.
- **Brand-neutral:** no brand names in code paths of the page. Kinds come from the sources (§4.1).
- **Data hygiene:** the reference GIF holds real VM names, IPs and job names. Never copy them into
  the repo, the zip, test fixtures or docs. Tests use invented names (`vm-app-01`, `JOB-DAILY`) and
  documentation IP ranges.
- **A pure model builder** (like `CoveragePage::build`) computes the cards from rows plus "now", with
  unit tests for the buckets, needs-attention reasons, size parsing and visibility.

## 6. Left to the builder

- Class split, and whether the age filter passes as a query string or JS on the existing table.
- Where in the menu "Backup summary" sits, and its icon.
- The exact Needs attention ordering when reasons tie (default: worst reason first, then oldest).
- How the source switch is remembered (query string; the browser may remember the last choice).
- Whether the Largest objects card shows 10 or fits the card height.
