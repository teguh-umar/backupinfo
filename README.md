# Backup Info (backupinfo)

Backup Info is a GLPI 11 plugin that gives an overview of your backup systems and turns them into audit evidence. It belongs to the PGN GLPI Suite.

The plugin reads your backup servers through their REST APIs and stores what it reads in GLPI. It then shows, per Computer, whether each machine is backed up and whether its backups are fresh and successful. It never changes anything on a backup server: login and logout are the only requests that are not reads.

Current version: **0.5.2**. See [CHANGELOG.md](CHANGELOG.md) for the history.

## Supported backup systems

| Type | Status |
|---|---|
| Vinchin Backup & Recovery | Supported, verified on a test server |
| Veeam Backup & Replication 13 | Preview: built from the REST API documentation (1.3-rev1 / 1.3-rev2), not yet verified on a real server |

All sources share common data shapes: object, run, event and target. More backup brands can be added without changing the stored evidence. When a source cannot provide a section, the page and the export say so in that section's note.

## Features

- **Backup summary** cards:
  - protected objects, fresh backups, total data, restore points, needs attention;
  - a last-backup age bar;
  - breakdowns by kind and largest objects.

  Every card is clickable and narrows the table below it.
- **Backup objects**: every backed-up VM, database or file set, with these columns:
  - job, type (backup or copy) and storage;
  - last backup and its age;
  - restore points and size;
  - last run and result (the failure reason shows on hover);
  - restore test;
  - the matched GLPI Computer.

  The table has filters and sorting, and exports to Excel.
- **Coverage**: which Computers that must be backed up are protected, and which are not.
- **Audit evidence** for ISO 27001 (A.8.13), internal audit and ICOFR:
  - coverage, failures, restore tests, activity and storage targets for a chosen period;
  - an Excel export of the whole evidence pack.
- **Restore tests**: record restore tests with documents as evidence. A restore interval flags Computers without a recent passed test.
- **Computer tab**: a Backup section on the shared PGN Suite asset tab, plus search options on Computer.
- **Tickets and findings**: off until switched on.
  - One Incident per failed backup.
  - A Problem for repeated failures.
  - Risk Register findings, when the Risk Register plugin is active.

  The plugin only adds and links tickets; it never edits, solves or closes them. A dry run shows what would be created.
- **Probe**: checks a connection, and lists the API endpoints the account may read.
- **Scheduled sync** through GLPI automatic actions, with a sync log per connection.

## Requirements

- GLPI 11.0 or later (tested up to 11.x), PHP 8.
- Network access from the GLPI server to the backup server's API, for example port 9419 for Veeam.
- A read-only API account on each backup server.
- Optional:
  - the **Fields** plugin, to write backup status and backup media to Computer fields;
  - the **Risk Register** plugin (PGN GLPI Suite), for findings.

## Installation

1. Download the release zip, for example `backupinfov0.5.2.zip`.
2. Extract it into GLPI's `plugins/` folder, which gives `plugins/backupinfo/`.
3. In GLPI, open **Setup > Plugins**, then install and enable **Backup Info**.
4. Give rights in **Administration > Profiles > Backup Info**:
   - configuration;
   - view;
   - reports;
   - restore tests.
5. Add a connection in **Setup > General > PGN Suite > Backup Info** (or click the wrench in Setup > Plugins).
6. Run **Probe** to check the connection, then let the scheduled sync run, or start it manually.

Upgrading: extract the new zip over the old folder, then open Setup > Plugins and run the upgrade if GLPI asks for one. The changelog states any database change.

For Veeam, see [docs/veeam-installer-checklist.md](docs/veeam-installer-checklist.md). It covers:
- the service account and role;
- the API version and TLS;
- how to read the Probe result.

## Usage

The pages are under **Backup Info** in the suite's **ICT Tools** sidebar menu. The menu label can be renamed in the suite settings, and each page is behind its own right:

| Page | What it shows |
|---|---|
| Backup summary / Backup objects | the summary cards and the object table |
| Coverage | protection of the Computers that must be backed up |
| Audit evidence | the evidence tabs and the Excel export for a period |
| Restore tests | recorded restore tests and their documents |
| Probe | the connection check |
| Manual | the user manual shipped with the plugin |

## Security

- API passwords and keys are stored as GLPI secured (encrypted) fields and are never shown or logged.
- Requests to the backup server are reads only, apart from login and logout.
- Rows follow GLPI entities. A user sees the objects whose Computer is in their active entities. Objects that are not matched to a Computer need the configuration right.

## Development

Run the tests (no GLPI installation needed) with:

```
php tests/run_all.php
```

The run ends with `SUITES OK`. The Excel writer test needs GLPI's ZipStream library and is skipped or fails outside a GLPI tree.

To build the release zip:

```
python -I tools/build_zip.py
```

The zip is built from an allowlist of runtime files only.

## License

GPL-3.0-or-later.
