# Veeam B&R 13 connector: installer checklist (Backup Info 0.5.0)

This checklist is for the person who connects Backup Info to a Veeam Backup & Replication 13 server.
The Veeam connector is a **preview**: it was built from Veeam's published REST reference
(1.3-rev2) and has not yet been run against a real server. The plugin only reads from Veeam. Apart
from login and logout, it never sends a write request.

Work through the sections in order. Section 5 lists what to look for on the first real server; send
those results back to the plugin's maintainer.

## 1. Service account

Create a dedicated Veeam account for Backup Info:

- [ ] **Role: Backup Viewer.** It is read-only and covers jobs, sessions, task sessions, restore
  points, backup objects and repository states.
- [ ] **Optional: Security Administrator**, only if four-eyes authorization events should appear on
  the Audit evidence page. Without it, that list stays empty and Probe shows `refused (role?)`.
- [ ] **Optional: Incident API Operator**, only if malware detection events should appear. Without
  it, that list also stays empty.
- [ ] **No MFA** on this account. The plugin logs in with a username and password (OAuth2 password
  grant) and cannot answer an MFA prompt.
- [ ] Use a `DOMAIN\user` or local account name exactly as the Veeam console shows it. The plugin
  URL-encodes it.

Each extra role widens what the account can see. Add a role only when you need its list.

## 2. API version

Every request carries an `x-api-version` header. Set it in the connection's **API version** field to
match the server build:

| Veeam B&R build | API version |
|---|---|
| 13.1 (13.1.0.411 and later) | `1.3-rev2` (default) |
| 13.0.1 (13.0.1.180) | `1.3-rev1` |

- [ ] Check the build under Help > About in the Veeam console.
- [ ] If the field is left empty, the plugin uses `1.3-rev2`.
- [ ] An HTTP 400 on every call usually means a wrong API version. The error text says so.

## 3. Network and TLS

- [ ] The REST API listens on **port 9419** (HTTPS only). If the **Host** field has no port, the
  plugin adds `:9419`. Allow GLPI → Veeam server on 9419 in the firewall.
- [ ] Veeam ships a **self-signed certificate** on 9419. Prefer one of these:
  - replace it with a certificate from your internal CA (Veeam console, or
    `Add-VBRBackupServerCertificate`), and make that CA trusted on the GLPI server; or
  - add the self-signed certificate to the GLPI server's trust store.
- [ ] Keep **Verify TLS** on. Turn it off only for a first test, and turn it back on after.
- [ ] "no answer: check host, port 9419 and the TLS certificate" in an error means the plugin got no
  HTTP answer at all: a firewall, a wrong host, or a certificate that does not verify.

## 4. First connection and Probe

1. [ ] Setup > Backup Info > Connections > Add. Choose type **Veeam B&R 13 (preview)**, then fill in
   Host, Username, Password, API version and Verify TLS. Save.
2. [ ] Open **Probe** (Backup Info menu) and choose the connection.
3. [ ] Click **Take sample**. It reads one page each of `/backupObjects`, `/restorePoints` and
   `/backups`.
4. [ ] Click **Sample audit endpoints**. It reads one page each of sessions (backup jobs and backup
   copy jobs), the task sessions of the newest backup session, authorization events, malware events
   and repository states.
5. [ ] Only then turn the connection **active** and run **Sync now**.

### Reading a Probe `_check` line

Each raw endpoint starts with one row named `_check`:

| `_check` | Meaning | What to do |
|---|---|---|
| `OK` | The call worked and the first row has every field the plugin reads. An empty list is also `OK`. | Nothing. |
| `unexpected shape: missing a, b` | The call worked, but the first row lacks these fields. The plugin would store those values as empty. | Send the field names and one redacted raw row to the maintainer. |
| `refused (role?): …` | HTTP 403: the account's role cannot read this endpoint. | Expected for authorization events without Security Administrator, and for malware events without Incident API Operator. Anywhere else, check the Backup Viewer role. |
| `error: … HTTP 401 …` | Login refused. | Check the username and password, and that the account has no MFA. |
| `error: … HTTP 400 …` | Usually a wrong API version. | See section 2. |
| `error: … HTTP 404 …` | This endpoint does not exist on this server version. | Note the endpoint and the build, and send them to the maintainer. |
| `error: … HTTP 0 …` | No HTTP answer. | See section 3. |

Probe redacts secrets and tokens from the raw rows. Still check a sample for names or IPs before you
send it outside your organisation.

## 5. What to look for on the first real server

These points could not be confirmed from the documentation. Check them with Probe, or in Swagger at
`https://<veeam-server>:9419/swagger/index.html`, and report what you find.

1. **Wrong API version.** Set the API version to a value the server does not support, and note the
   HTTP status and error text. On a 13.0.x server, does `1.3-rev2` fail?
2. **Long syncs.** A token lasts 15 minutes. On a large server, does a sync that runs longer than 15
   minutes finish without a 401 error?
3. **Page size.** In Swagger, is there a maximum `limit`? The plugin asks for up to 200 rows per
   page for most lists, and up to 5000 for security events and repository states. If the server
   returns fewer rows, the plugin requests the next page.
4. **Role coverage.** With a Backup Viewer-only account, are all lines in section 4 `OK` except
   authorization and malware events?
5. **Custom roles.** If your organisation uses a custom Veeam role, does it work through REST?
6. **Status values.** In the sessions raw rows, which `result.result` and `state` values appear?
   The plugin knows `Success`, `Warning` and `Failed`, and stores only sessions with state
   `Stopped`.
7. **Sizes.** Do `progress.transferredSize` and `processedSize` look like bytes? Compare one session
   with the console.
8. **Failure reasons.** For a failed session, does its task session have a filled `result.message`?
   That message becomes the failure reason on the Audit evidence page.
9. **Who started a run.** How does `initiatedBy` read for a scheduled run, a manual run and a
   restore?
10. **`usn` field.** In Swagger, can sessions be filtered or sorted by `usn`?
11. **Other workloads.** If you back up Hyper-V, agents, file shares or Proxmox/Nutanix, do those
    machines appear in `/backupObjects` (Take sample)?
12. **Repositories.** In repository states, what `type` does a scale-out repository have (the plugin
    expects `ExtendableRepository` and skips it, keeping its extents)? Which fields show
    immutability for hardened Linux and object storage repositories?
13. **Server facts.** In Swagger, which fields do managed servers, server info and license return?
14. **SureBackup.** Do SureBackup results appear as sessions or task sessions? The plugin does not
    import them yet.
15. **File-level restore audit and log export.** In Swagger, what do these responses contain?
16. **API changelog.** Does the 1.3 changelog in Swagger list changes to sessions, restore points or
    repository states?
17. **Tape and alarms.** Confirm that Swagger has no Tape and no Alarms section. If it has either,
    report it.

## 6. What the Veeam connector does not show

These limits also appear as a note on the Audit evidence page and in the Excel export:

- **Coverage:** the platform VM list (protected or not) is not read from Veeam in this version.
- **Restore tests:** SureBackup verifications are not imported; record restore tests by hand.
- **Activity:** Veeam REST has no job operation log, no system alarms (Veeam ONE only) and no login
  source IP. Authorization events need Security Administrator, and malware events need Incident API
  Operator.
- **Targets:** tape is not available from Veeam REST.
