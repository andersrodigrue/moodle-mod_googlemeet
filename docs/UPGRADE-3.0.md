# Upgrade readiness: 2.1.1 to 3.0.0

This report defines the supported in-place migration from the last stable tag
preserved by the fork to the Moodle 5.2 alpha line.

## Supported baseline

The repository history establishes the upgrade baseline:

| Item | Value |
|---|---|
| Last inherited stable tag | `v2.1.1` |
| Stable release string | `2.1.1` |
| Stable Moodle version number | `2023050101` |
| Stable Moodle requirement | Moodle 3.7 |
| Current alpha release | `3.0.0-alpha.1` |
| Current Moodle requirement | Moodle 5.2 |
| Current PHP matrix | PHP 8.3 and 8.4 |

There is no `v2.1.2` tag or `2.1.2` version declaration in the preserved
repository history. The supported source baseline is therefore `2.1.1`.
Installations with an older version number also retain the guarded
`2023042200` entry point that adds the legacy `eventid` column when necessary.

## Savepoint inventory

| Savepoint | Purpose | Representative verification |
|---|---|---|
| `2023042200` | Add the historical Calendar link field. | The pre-`eventid` entry point traverses the complete guarded chain. |
| `2026072601` | Add managed Calendar identity, canonical time and synchronization state. | Legacy-linked and manual activities are classified separately; URL and creation time are preserved. |
| `2026072608` | Add recording authorization and discovery state, then enforce activity-scoped recording identity. | A legacy recording survives the migration and the final unique index is checked against `install.xml`. |
| `2026072610` | Convert old schedule fields, consolidate occurrences and receipts, and associate stable keys. | Event-backed and field-only schedules are converted; duplicate occurrences and reminder receipts are consolidated. |
| `2026072612` | Add explicit guest policy and attendee snapshot storage. | Historic invitation values are reset to the safe `none` policy and the guest table/index contract is checked. |
| `2026072613` | Add bounded privacy-safe operational diagnostics. | The diagnostics table and indexes are checked against the fresh-install contract. |
| `2026072614` | Refresh Calendar-selection behavior. | No schema mutation; the savepoint is exercised and recorded. |
| `2026072615` | Refresh server-side meeting-entry policy. | No schema mutation; the savepoint is exercised and recorded. |
| `2026072616` | Refresh namespaced recording services and JavaScript. | No schema mutation; the savepoint is exercised and recorded. |
| `2026072617` | Refresh renderables and Mustache presentation. | No schema mutation; the savepoint is exercised and recorded. |
| `2026072618` | Declare and test the complete compatibility contract. | Unit tests bind retained fields and savepoints to one contract. |
| `2026072619` | Add the real CLI migration rehearsal. | CI upgrades seeded `v2.1.1` tables and verifies the resulting data and schema. |
| `2026072620` | Add the protected Google Workspace acceptance contract. | No schema mutation; tests bind the closed scenarios and sanitized evidence to the current version. |
| `2026072621` | Add complete acceptance-campaign accounting. | No schema mutation; evidence binds each runbook case to the exact Moodle/PHP runtime and the offline gate requires both supported PHP series. |
| `2026072622` | Add protected dual-environment readiness. | No schema mutation; read-only checks prove runtime/site isolation, mail suppression, exact source installation, OAuth issuers and dedicated fixtures before provider access. |
| `2026072623` | Preserve remote Calendar cancellation across activity and course deletion. | Adds a durable cleanup outbox, owner-scoped ad hoc cancellation and hourly recovery of pending, blocked or abandoned work. |
| `2026081900` | Freeze the first installable alpha testing boundary. | No schema mutation; advances the plugin version and binds upgrade and acceptance fixtures to `3.0.0-alpha.1`. |

The automated upgrade suite calls the real `xmldb_googlemeet_upgrade()` function.
It starts once from `2023050101`, once from immediately before `2023042200`, and
replays the stable upgrade a second time to verify idempotency. No Google request,
OAuth operation, task execution or permission change occurs during migration.

## Fresh-install and upgraded-schema contract

`db/install.xml` remains the canonical schema for a new installation. After the
upgrade chain runs, the test suite:

1. loads every table and field declared by `install.xml`;
2. compares the complete field set of each plugin table with the installed
   database;
3. verifies every declared non-primary index by definition;
4. verifies that every intentionally retained legacy activity column is present;
5. fails if an upgrade-only field is added without a corresponding fresh-install
   declaration.

Moodle Plugin CI separately validates XMLDB syntax and savepoint ordering on
Moodle 5.2 under PHP 8.3 and 8.4.

## Real CLI migration rehearsal

The CI contains a migration gate independent from the fresh-install PHPUnit
database:

1. the branch is checked out with its complete preserved history;
2. the exact commit tagged as `v2.1.1` is materialized in an isolated Git
   worktree;
3. Moodle Plugin CI installs Moodle 5.2 and the unmodified legacy plugin;
4. a CLI-only fixture script confirms that the database still has the old
   four-table schema;
5. the script inserts manual and Calendar-linked activities, expanded and
   duplicate occurrences, matching legacy Moodle Calendar events, overlapping
   reminder receipts, and duplicate and distinct recording references;
6. the current branch is overlaid on the installed legacy plugin directory;
7. Moodle's `admin/cli/upgrade.php --non-interactive --allow-unstable` performs
   the normal plugin upgrade;
8. a second CLI-only script checks the final plugin version, every activity
   invariant, occurrence and receipt consolidation, Moodle Calendar
   association, recording deduplication, all fresh-install fields, all declared
   indexes and the exact plugin table set;
9. the normal fresh-install and PHPUnit job starts only after both PHP 8.3 and
   PHP 8.4 migration jobs succeed.

The fixture manifest is stored only in the disposable CI site's plugin
configuration. Both scripts refuse web execution and make no Google or OAuth
request. The rehearsal therefore validates Moodle's real upgrade dispatcher and
database DDL while keeping external integration acceptance separate.

## Retained legacy columns

Physical removal is intentionally out of scope for `3.0.0-alpha.1`. The central
`compatibility_contract` class is the machine-readable source for the following
policy:

| Fields | Current policy | Why retained | Removal gate |
|---|---|---|---|
| `eventdate`, `starthour`, `startminute`, `endhour`, `endminute` | Legacy conversion only | Reconstruct an absolute start and end for old records and backups. | The supported restore window no longer includes backups without canonical timestamps. |
| `addmultiply`, `days`, `period`, `eventenddate` | Legacy conversion only | Reconstruct the historical weekly recurrence representation. | The supported restore window no longer includes legacy recurrence backups. |
| `creatoremail` | Privacy lifecycle only | Find, export and erase organizer data that predates Moodle user ownership. | A documented privacy retention decision and migration of all supported legacy installations are complete. |
| `eventid` | Legacy read only | Identify an old Calendar-linked activity and preserve its bounded Calendar detail link until explicit reconnection. | The supported reconnect window is closed and all remaining legacy activities have been detached or migrated. |

Current activity forms remove all nine legacy schedule fields before persistence.
Current backups do not emit them. Restoring an old backup may read them once to
produce `timestart`, `timeend`, `timezone` and `recurrence`. New or restored
activities never acquire a new `creatoremail`; remote ownership is represented by
Moodle user and OAuth issuer IDs.

The similarly named `googlemeet_events.eventdate` and
`googlemeet_notify_done.eventid` columns are not deprecated activity fields.
They are current local occurrence and reminder-receipt foreign-key data and are
outside this removal decision.

## Data invariants after migration

The upgrade tests require these outcomes:

- a stable manual Meet URL remains usable and becomes `meetinguri`;
- a row without the old external link becomes `manual` and `ready`;
- a row with the old external link becomes `legacy` and `disconnected`;
- the old external identifier is never promoted to `googleeventid`;
- canonical start and end prefer expanded legacy occurrence rows, then fall back
  to the activity's old date and clock fields;
- an empty timezone receives Moodle's server timezone;
- repeated occurrence timestamps are deduplicated before both recurrence
  generation and stable-key persistence;
- duplicate reminder receipts are consolidated without losing a distinct user;
- the historical organizer email and Calendar link remain available only for
  their declared legacy/privacy purposes;
- existing recording references remain local and are never used to make a
  provider request during upgrade;
- attendee management resets to `none`, preventing historic data from generating
  unsolicited Calendar invitations;
- rerunning the upgrade produces the same occurrence and receipt identifiers.

## Operational release gate

Before promoting `3.0.0` from alpha, a site administrator should perform a
production-like rehearsal:

1. clone the Moodle application and database into an isolated environment;
2. disable outbound mail and Google network access;
3. record counts for activities, occurrences, recordings and reminder receipts;
4. replace only `mod/googlemeet` with the release candidate;
5. run the normal Moodle CLI upgrade;
6. execute the Moodle 5.2 cron once;
7. compare record counts and inspect all `legacy` and `disconnected` activities;
8. verify one manual meeting, one single legacy meeting, one recurring legacy
   meeting, one recording list and one old backup restore;
9. enable outbound Google access only after the local results are accepted;
10. make both protected Google Workspace environment-readiness jobs green;
11. follow the dedicated
    [Google Workspace acceptance runbook](GOOGLE-WORKSPACE-ACCEPTANCE.md);
12. complete one closed campaign on the PHP 8.3 and PHP 8.4 dedicated runners;
13. run the offline campaign gate against the downloaded sanitized evidence and
    closed manual review;
14. reconnect a dedicated test teacher and reconcile one managed event before a
    wider rollout.

The database backup taken before step 4 is the rollback boundary. Rolling plugin
files back without restoring the database is not a supported rollback after the
Moodle upgrade has advanced the plugin version.

## Remaining release risks

The code-level migration contract is complete, but these environment-dependent
checks remain mandatory before a stable release:

- publish the branch and confirm both CLI-upgrade matrix jobs and both
  fresh-install PHPUnit jobs;
- rehearse the CLI upgrade with a sanitized copy of a real `2.1.1` database;
- restore representative backup archives created by the old plugin, not only
  programmatically generated fixtures;
- provision independent PHP 8.3/8.4 test sites and pass both protected readiness
  jobs;
- execute and pass one dual-runtime Google Workspace campaign with test Calendar
  and Meet accounts;
- measure the upgrade and first cron duration on a course population comparable
  to the target site;
- confirm the site's backup retention and privacy policy before setting any date
  for physical legacy-column removal.

No retained legacy column should be removed merely because all current automated
tests pass. Removal requires a separate schema proposal, a backup compatibility
decision and a new release boundary.
