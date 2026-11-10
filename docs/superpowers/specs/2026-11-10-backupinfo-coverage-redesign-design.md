# Backup Info: Coverage page redesign (design)

Date: 2026-11-10. Owner session: backup-info-09.

**Status: on hold.** The user is holding version 0.6.0 for discussion (2026-11-10). Do not write a plan or
build from this document until the user releases it. The version and branch in §5 are a proposal only.

## 1. Intent

The Coverage page answers one question for the ICT team and for auditors: **"Of the VMs that must be
backed up, how many are safe, and which ones need action now?"** Today the page is a sentence, four
numbers and a list of at most 200 rows. The user's DCOC "Backup & Snapshots" portal shows the same
facts in a far more usable way. The user gave it as the reference, as a GIF (local only, see
"Constraints").

This redesign brings that portal's **Backup coverage** tab into GLPI, built on GLPI data:

- clusters from Management > Clusters;
- VMs per host from the host's Virtualization tab;
- environment from the VM name and from the Addressing plugin's IP ranges.

## 2. Goals

1. **KPI cards** at the top:
   - Coverage %, as "34 of 87 must-backup VMs".
   - Protected (backup within RPO).
   - Expired (last backup older than RPO).
   - No backup (no backup object).
   - Not connected: the number of clusters with no backup at all (§4.4).
2. **Policy card.** It shows:
   - the policy mode ("Wajib Backup" field or manual list) and the RPO rule, as today;
   - how many VMs are counted;
   - how many VMs are outside the policy, and how many of those still have a backup;
   - a warning when hypervisor hosts are marked must-backup: "N hypervisor hosts are marked Wajib
     Backup and are not counted" (§4.5), so the data can be cleaned.
3. **Action list.** Must-backup VMs with status No backup or Expired.
   - Order: No backup first, then Expired with the oldest backup first.
   - Columns: VM (link to the Computer), Cluster, Environment, Status badge, Last backup with age
     ("287 days ago"; "never" when there is none).
   - A **Show in list** button applies the same filter to the VM list below.
4. **By cluster table.** One row per hypervisor cluster that holds at least one must-backup VM the
   user can see.
   - Columns: Cluster, Platform, Coverage (bar and %), Protected, Expired, No backup, Notes.
   - Notes say "No backup source yet" (§4.4).
   - A last row, **Unknown / review**, holds the VMs whose cluster could not be found (§4.2).
   - Clicking a cluster filters the VM list.
5. **VM list.** Every must-backup VM the user can see.
   - Search on VM, cluster, job or IP. Status filter, defaulting to "Needs action (no backup or
     expired)". Environment filter. Cluster filter.
   - Columns: VM (link) with its app-cluster name as a small note (§4.3), Cluster, Environment,
     Status, Last backup, Source / Job, Restore points.
   - It uses the same GLPI table component as Audit evidence and Backup objects: filter row, sort,
     pages, Columns menu (`AuditTable`).
6. **Excel export** (.xlsx, the user's preferred format) of the VM list and the cluster table, with the
   policy card text as header notes.
7. **"Backup objects without a computer"** stays, for config READ only, as today.

## 3. Non-goals

- The portal's other tabs:
  - Backup summary: maybe later, as its own design.
  - Policy compliance and Expired snapshots: these read the hypervisor, not the backup system.
  - Sources & exclusions: not in scope. A cluster-exclusion setting was considered and dropped by the
    user (§4.5).
- Writing to GLPI. Backup Info never edits Clusters, cluster members, Computers or virtual machine
  rows.
- Filling the Sangfor gap itself. That is the job of the new suite plugin `hypervisor` (§4.2).
- A chart library. Bars are plain CSS (Tabler `progress`).
- Changing how Protected, Expired and No backup are computed. `Status::of()` and `Rpo` stay as they
  are.

## 4. Decisions (the user's, unless marked "my call")

### 4.1 Population

The same as today: mandatory Computers (Policy: "Wajib Backup" field or the manual list) in the user's
active entities, not deleted and not templates. Backup objects are matched to Computers as today.

### 4.2 Cluster of a VM: VM → host → hypervisor cluster

- **Clusters are filled by hand** in Management > Clusters (`glpi_clusters`). The members of a
  hypervisor cluster are its **host** Computers (`glpi_items_clusters`, itemtype `Computer`).
  Examples: CLUSTER-A holds two Hyper-V hosts; a Sangfor HCI cluster holds five hosts.
- **VM → host** comes from the host Computer's Virtualization tab (`glpi_computervirtualmachines`,
  where `computers_id` is the host).
  - The match is on the VM Computer's `uuid`. GLPI stores Hyper-V UUIDs in two byte orders, so it
    must accept both, the way core GLPI does when it links a VM row to its Computer.
  - If the UUID gives no match, the fallback is the VM row's `name` = the Computer's `name`, case
    insensitive, used only when exactly one Computer has that name.
- **Host → cluster:** the cluster whose members include that host.
- **Hyper-V:** works today, because the GLPI Agent on each host fills the Virtualization tab.
- **Sangfor HCI:** the hosts cannot run the agent, so their Virtualization tab is empty. The suite
  plugin **`hypervisor`** (separate, multi-brand, Sangfor HCI first; folder plugins/Hypervisor) will
  fill it. The contract, agreed with the suite orchestrator:
  - `hypervisor` writes one `ComputerVirtualMachine` row per VM on each host Computer;
  - cluster membership stays manual;
  - Backup Info reads core tables only and never calls `hypervisor` classes.

  Until it ships, Sangfor VMs fall in **Unknown / review**.
- **Unknown / review** (the user's label; Indonesian UI text "Tidak diketahui / review"): any VM with
  no host, a host in no cluster, or a host in more than one cluster. The label says these rows need an
  admin to check the data.
  It is counted like every other VM. In the multi-cluster case, the Notes cell names the clusters
  (my call).

**Must-backup Computers that are not VMs:**
- Hypervisor hosts are never counted (§4.5, the user's rule).
- Any other must-backup Computer that no host lists as a VM goes to **Unknown / review**. This
  includes physical servers and VMs whose host is missing (my call).
- The Notes cell of that row explains that it holds both cases. Telling a physical server from an
  orphan VM is not in scope.

### 4.3 App clusters are not used for grouping

Some clusters hold VMs, not hosts, for example Redis (4 VMs), Kubernetes or SQL2019. Those are not
grouping keys, so no VM is counted twice. When a must-backup VM is itself a member of a cluster, the
VM list shows that cluster's name as a small note under the VM name (my call).

A cluster is a "hypervisor cluster" when it is reached through a host's Virtualization tab. A
cluster is an "app cluster" when the VM itself is a member. The builder may refine this rule; it
must not need a manual flag.

### 4.4 "Not connected to backup"

A hypervisor cluster is **not connected** when none of its VMs that the plugin can see has any
matched backup object. Both must-backup and other VMs count here.

- These clusters **are still counted**: their must-backup VMs count as No backup in the coverage %.
  The user chose this because it is honest audit evidence.
- The Notes cell says "No backup source yet".
- The KPI card counts these clusters.
- Scope (my call):
  - It is computed only from what the user can see, like every other number on the page.
  - Only clusters that appear in the cluster table count, that is, clusters with at least one visible
    must-backup VM.
  - A hypervisor cluster whose hosts have no VM rows yet (Sangfor until `hypervisor` ships) does not
    appear. Its VMs are in Unknown / review.

### 4.5 Only VMs are backed up; hosts and clusters never are

The user's rule: **only VMs are backed up. Hypervisor hosts and clusters are never backup objects.**

- A Computer that is a host member of a hypervisor cluster (§4.3) is **never counted**, even if it is
  marked Wajib Backup. No setting is needed. The policy card warns when such hosts are marked
  Wajib Backup (§2.2), so the data can be cleaned.
- Clusters are only a grouping for VMs (VM → host → cluster). They are never counted as objects.
- Whether a VM is counted is decided by its own Wajib Backup status alone. To leave a VM out, remove
  its Wajib Backup status.
- **No cluster-exclusion setting.** The user first asked for one in Setup, because "a hypervisor
  cluster cannot be backed up", and then dropped it once the host rule above covered that case. So
  there is no new table, no exclusion reason and no "Excluded" status.
- The same host rule applies to the **Audit evidence > Coverage** tab, and to the BCKP-COVERAGE /
  BCKP-RESTORE findings and the automatic tickets, so no page counts a host that Coverage leaves out
  (my call).

### 4.6 Environment badge

The environment is shown as a badge. It is derived, never stored in GLPI. The first rule that matches
wins:

1. **Name tokens.** The VM name is split on `-` and `_`, case insensitive.
   - A token equal to `dev` means **Development** (`dev-app01`, `DEV-DB-01`, `VM-DEV-APP-A1`).
   - A token equal to `stag` or `stg` means **Staging** (`VM-STAG-WEB`).
   - Whole tokens only, so `device-01` does not match.
   - The keyword lists can be edited in Setup, and those are the defaults.
2. **IP range (Addressing plugin).** The VM's IP is matched against the ranges in Tools > IP
   Addressing (First IP to Last IP).
   - The range names are not clean labels: there are typos, and some ranges are VLANs or a node
     network, not environments.
   - So Setup lists every Addressing range, and the admin picks an environment for each one:
     Production, Production-OT, Development, Staging, or "not used". The ranges themselves are
     always read live from Addressing.
   - If Addressing is not installed or not active, this rule is skipped.
3. **No match: no badge.**

- The VM's IP comes from the Computer's GLPI network addresses first, then from the matched backup
  object's IP.
- The four environment names are a fixed list (my call; the builder may make it editable if that is
  cheap).

### 4.7 Look

Use GLPI 11 / Tabler cards, badges and progress bars. It must look native to GLPI, not like the DCOC
portal. Colours carry the status: green Protected, amber Expired, red No backup, grey unknown.

## 5. Constraints

- **GLPI 11, PHP 8.** No Composer dependencies beyond what GLPI ships.
- **Entity rights.**
  - Only Computers in the user's active entities are counted or listed.
  - Cluster and host names are shown as plain text even when the cluster record is in an entity the
    user cannot open. They link only when the user can see the cluster (my call).
- **Rights.**
  - The page needs `plugin_backupinfo_report` READ, as today.
  - Changing the environment keywords or mappings needs config UPDATE.
- **No live calls** to backup systems on page load. Everything comes from the plugin's tables and
  GLPI's.
- **Brand-neutral.** No brand-specific fields in stored evidence (multi-brand goal).
- **Data hygiene.** The reference GIF and the user's screenshots hold real VM names and IPs. They are
  never copied into the repo, the zip, test fixtures or docs. Tests use invented names and
  documentation IP ranges.
- **Cross-plugin.**
  - Addressing: read with an `isPluginActive('addressing')` guard and its table name only. Confirm the
    column names of `glpi_plugin_addressing_addressings` (first and last IP, name, entity) on the
    installed version.
  - `hypervisor`: never called. Its data arrives in core tables.
- **Performance.** Hundreds of VMs and tens of clusters. The page must build in one request without
  N+1 queries per VM.

- **Branch and version** (my proposal; on hold, the user is discussing 0.6.0):
  - Build on a new branch `feat/v0.6-coverage` cut from `feat/v0.5-veeam`, after 0.5.0-rc1.
  - Any install/upgrade step (environment settings) is keyed to 0.6.0. No new table is expected; if
    the builder needs one, its name is claimed in the suite hub through the orchestrator first.
  - 0.6.0 is not released before 0.5.0 is final.

## 6. Left to the builder

- Class split. A pure model builder (like `CoveragePage::build`) with unit tests is expected. Data
  loading stays in `front/coverage.php` or a small loader.
- The exact Setup UI for the environment keywords and Addressing mappings: a new Setup tab or a
  section in an existing one.
- How "Show in list" and the cluster click pass the filter to the VM list: query string or JS.
- Sorting inside the cluster table. Default by name, Unknown / review last.
- Whether the action list has its own cap with a "Show all in list" link, or is scrollable as in the
  reference.
- Migration and upgrade steps for the environment settings, following the plugin's install pattern.
