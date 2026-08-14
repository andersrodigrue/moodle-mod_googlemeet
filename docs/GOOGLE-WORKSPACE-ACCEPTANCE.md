# Google Workspace acceptance

This runbook defines the real-provider release gate for `mod_googlemeet` 3.0.
It complements PHPUnit and the Moodle 5.2 upgrade rehearsal; it does not replace
either one.

The acceptance workflow is intentionally absent from `push` and `pull_request`.
It can be dispatched only from the repository's protected default branch, after
approval of the `google-workspace-acceptance` GitHub Environment, on a dedicated
self-hosted runner. OAuth credentials remain in Moodle core's user OAuth storage.
No Google password, access token, refresh token or client secret is copied to
GitHub.

## Security boundary

Use a disposable Moodle site and a dedicated Google Workspace test tenant. Do
not point this workflow at a production Moodle database, production Google Cloud
project, staff calendar or real course.

The live fixture requires all of the following:

- `PHP_SAPI=cli`; web requests receive no fixture output;
- an explicit config switch:

  ```php
  $CFG->mod_googlemeet_workspace_acceptance = true;
  ```

- a runtime-specific profile and disabled outbound email:

  ```php
  // PHP 8.3 site. Use php84 on the independent PHP 8.4 site.
  $CFG->mod_googlemeet_workspace_acceptance_profile = 'php83';
  $CFG->noemailever = true;
  ```

- an activity name beginning with `[ACCEPTANCE]`;
- `managed` integration mode;
- a valid canonical schedule;
- a stored Calendar owner, issuer and exact writable Calendar ID;
- execution as the persisted owner;
- the exact `MUTATE-GOOGLE-WORKSPACE` acknowledgement for synchronization or
  cancellation;
- a bounded campaign slug and a `GW-01` through `GW-14` case whose selected
  scenario matches the runbook;
- execution on the selected PHP 8.3 or PHP 8.4 runner, with the actual runtime
  and Moodle 5.2 branch recorded by the fixture rather than trusted from input;
- a closed scenario, schedule expectation, timeout and count range;
- JSON evidence that passes a second dependency-free secret scan before upload.

The fixture never accepts credentials as command-line options. It loads the
owner's grant through Moodle's OAuth API, including Moodle's normal refresh-token
behavior. Provider response bodies, OAuth values, email addresses, Calendar IDs,
Calendar event IDs, conference request IDs, meeting codes, Meet URIs and recording
links are excluded from the evidence schema.

The retained evidence contains only:

- the campaign, case, scenario, result, source commit, plugin version, local
  activity ID and time;
- the actual PHP major/minor series plus Moodle version and branch;
- closed check results;
- local lifecycle states;
- booleans indicating whether an owner, issuer, event or valid join link exists;
- guest and recording counts;
- a stable local failure code.

Artifacts are retained for seven days. Failed output is uploaded only if it still
passes the schema and secret validator.

## Environment readiness gate

Before dispatching a provider scenario, run
`.github/workflows/google-workspace-readiness.yml`. It is a manual-only,
protected-default-branch workflow using the same reviewed Environment and the
same `php-8.3` and `php-8.4` runner labels as live acceptance. It performs no
Google request and no Moodle write.

Both jobs must pass. The gate verifies only closed conditions:

- the runner label agrees with its actual PHP binary;
- the Moodle site is branch `502`, HTTPS and explicitly acceptance-enabled;
- outbound Moodle email is disabled;
- the config profile is `php83` or `php84` as appropriate;
- the same profile is stored in that site's own Moodle database;
- Calendar and recording issuers exist, are enabled, configured for Google's
  HTTPS authorization host and are different services;
- the installed plugin tree is byte-for-byte equivalent to the dispatched
  commit, excluding only `.git`, and the installed version matches;
- exact `[ACCEPTANCE] Single`, `Recurring`, `Guests` and `Recording` activities
  exist with their required managed ownership, schedule, guest and recording
  bindings.

The workflow uploads only schema-validated booleans, closed states, versions,
runtime, commit and profile. It never exports the site URL, database address,
OAuth issuer ID, Moodle user ID, Calendar ID or activity ID.

The live acceptance workflow repeats the same readiness check after installing
and upgrading the dispatched source. A provider scenario therefore cannot start
when readiness is missing or stale.

## Dedicated environment

### Google

Create a Google Cloud project used only for this acceptance tenant:

1. Enable Google Calendar API and Google Meet REST API.
2. Configure an OAuth consent screen for test users only.
3. Create a Calendar OAuth client with the redirect URI used by the Moodle site.
4. Create a second OAuth client for recording discovery.
5. Keep the recording issuer separate from the Calendar/login issuer.
6. Add only the teacher test accounts to the consent-screen test-user list.
7. Create a writable test calendar that supports `hangoutsMeet`.
8. Configure a retention period sufficient for the recording-discovery test.

The Calendar grant must contain only:

- `https://www.googleapis.com/auth/calendar.events`;
- `https://www.googleapis.com/auth/calendar.calendarlist.readonly`.

The recording grant must contain only:

- `https://www.googleapis.com/auth/meetings.space.readonly`.

The plugin does not request Drive API access and must not add an `anyone`
permission to a recording.

### Moodle

Provision a disposable Moodle 5.2 site with PHP 8.3 or 8.4 and:

- one administrator;
- one teacher used as both Calendar and recording owner;
- at least two active students;
- one suspended or unenrolled account for negative attendee checks;
- the dedicated Calendar issuer;
- the dedicated recording issuer;
- cron enabled at least once per minute for the UI queue case;
- debug logging configured according to the test site's policy;
- `$CFG->mod_googlemeet_workspace_acceptance = true`.

Assign the closed environment profile in both config and database. On the PHP
8.3 site:

```bash
php admin/cli/cfg.php \
  --component=googlemeet \
  --name=workspaceacceptanceprofile \
  --set=php83
```

Use `php84` on the PHP 8.4 site. Because the database value must differ, two
frontends pointing at the same Moodle database cannot both pass readiness. Also
set the corresponding `$CFG->mod_googlemeet_workspace_acceptance_profile` and
`$CFG->noemailever = true` in each site's `config.php`.

Create separate activities for destructive and non-destructive scenarios. A
recommended fixture set is:

| Activity | Shape | Guests | Purpose |
|---|---|---:|---|
| `[ACCEPTANCE] Single` | one occurrence | none | creation, idempotency and cancellation |
| `[ACCEPTANCE] Recurring` | weekly, at least three occurrences | none | recurrence preservation |
| `[ACCEPTANCE] Guests` | one occurrence | course | attendee reconciliation |
| `[ACCEPTANCE] Recording` | completed real meeting | optional | Meet recording discovery |

Use start times in the near future and delete or cancel the dedicated events after
the run.

### GitHub

Create a GitHub Environment named `google-workspace-acceptance`:

1. Require at least one reviewer.
2. Prevent the initiator from approving their own deployment when the repository
   plan supports that control.
3. Restrict deployment to the protected default branch.
4. Add an environment variable named `MOODLE_CONFIG` containing the absolute path
   to the disposable site's `config.php`.
5. Do not add Google or Moodle credentials as Actions secrets.

Register dedicated Linux runners with the common label
`google-workspace-acceptance` and runtime-specific labels `php-8.3` and
`php-8.4`. The workflow adds the selected runtime label to `runs-on` and then
verifies the PHP binary itself before installing the plugin. Each runner should:

- serve only this repository and disposable tenant;
- own an independent Moodle 5.2 application and database with equivalent fixture
  data, so PHP 8.3 state cannot satisfy or contaminate a PHP 8.4 case;
- expose its local test site's `config.php` at the same absolute `MOODLE_CONFIG`
  path configured in the shared GitHub Environment, even though the underlying
  sites and databases are separate;
- have no route or credential to a production Moodle or production database;
- provide PHP, `rsync` and the extensions required by Moodle;
- be rebuilt or cleaned between acceptance cycles;
- restrict interactive shell access and protect its Moodle configuration.

The workflow checks out the dispatch event's exact commit without persisting a
Git credential and verifies `HEAD` against `GITHUB_SHA`. It does not resolve the
mutable branch tip a second time after Environment approval. It places that exact
source in the dedicated plugin directory while maintenance mode is enabled, runs
Moodle's non-interactive upgrader and disables maintenance mode before executing
the selected scenario. Checkout and artifact upload use reviewed immutable action
commit hashes.

## Acceptance matrix

The `Evidence` column names the workflow scenario where one exists. `Manual`
means the operator must verify a browser or provider behavior that should not be
automated with stored passwords.

| ID | Boundary | Procedure | Passing result | Evidence |
|---|---|---|---|---|
| GW-01 | Calendar consent | As the teacher, create a managed activity and complete Google consent in the browser. Inspect the consent screen before accepting. | Only Calendar event management and read-only Calendar-list scopes are requested; no Drive scope and no login-wide silent scope expansion. | Manual + `preflight` |
| GW-02 | Token refresh | Leave the test grant connected beyond the access-token lifetime, then dispatch preflight without signing in again. | Moodle refreshes the grant and the writable Calendar read succeeds. No token value appears in logs or evidence. | `preflight` |
| GW-03 | Calendar selection | Select a non-primary writable test calendar, save, then run preflight. Repeat after removing writer access. | The exact selected calendar passes while writable. Removed/downgraded access fails closed and never switches to another calendar. | `preflight` |
| GW-04 | Single creation | Dispatch synchronization for `[ACCEPTANCE] Single` with mutation confirmation. | One controlled Calendar event exists. The local state becomes `pending` or `ready`; a successful conference produces one valid Meet join link. | `synchronise`, then `poll` if pending |
| GW-05 | Idempotency | Dispatch synchronization again without changing the activity; optionally repeat after interrupting transport on the dedicated runner. | No second Calendar event or second conference is created. The stored event remains the same provider resource. | `synchronise` + manual Calendar inspection |
| GW-06 | Recurrence | Dispatch synchronization for `[ACCEPTANCE] Recurring` with `expected_schedule=recurring`. Edit the schedule once and run again. | The remote recurrence matches the canonical Moodle rule and an edit updates the existing event rather than creating another one. | `synchronise` + manual Calendar inspection |
| GW-07 | Async conference | When creation returns pending, immediately retain that evidence and dispatch bounded polling. | Polling issues no second conference request. The state reaches `ready`, or remains safely `pending` when the bounded timeout expires. | `synchronise`, `poll` |
| GW-08 | Attendees | Enable course guests, synchronize with the exact expected count, change one active enrolment and synchronize again. | Only active capable users are managed; owner, suspended users, invalid/duplicate emails are excluded; manual Calendar guests and RSVP state survive; notification email occurs only for a real attendee change. | `synchronise --expected-guests=N` + manual Calendar inspection |
| GW-09 | Cancellation | Dispatch cancellation for the single-use fixture with mutation confirmation. Repeat once after it settles. | The remote event is absent, local state is `cancelled`, the join link is removed and repetition remains idempotent. | `cancel`, then `snapshot` |
| GW-10 | Transient limits | Run the deterministic Calendar client/manager tests for 429, 412 and 5xx. If a natural transient rejection occurs in the tenant, retain sanitized failure evidence and rerun after backoff. | The operation reports a retryable stable code, preserves controlled identity and succeeds later without duplication. | PHPUnit; optional live failure + later `synchronise` |
| GW-11 | Recording discovery | Hold and record a real test meeting, wait for `FILE_GENERATED`, connect the dedicated recording issuer and dispatch discovery with `expected_recordings=1`. | The Meet API finds the exact conference record, stores at least one validated Drive playback reference, requests no Drive scope and changes no Drive permission. | `recordings` + manual Drive permission inspection |
| GW-12 | Recording idempotency | Dispatch recording discovery again. | Existing local recording IDs are updated without duplicate rows; teacher-edited names and visibility remain unchanged. | `recordings` |
| GW-13 | Revocation/reconnect | Revoke each test grant in Google, run its scenario, then reconnect explicitly in Moodle and repeat. | Revocation produces a closed authorization-required state; reconnect restores only the selected integration and does not silently authorize another user. | `preflight` / `recordings` |
| GW-14 | Moodle queue | Create one fixture through the normal activity form with cron active rather than using direct CLI processing. | An owner-scoped ad hoc task processes the activity, duplicate queue requests are suppressed and the final state matches the CLI acceptance result. | Moodle task log + `snapshot` |

### Rate-limit rule

Do not generate artificial traffic against Google to force a quota failure. The
normal CI suite injects deterministic 429, 412 and 5xx responses at the transport
boundary and verifies Moodle retry behavior. A live provider rejection may be used
as additional evidence, but absence of a natural 429 is not an acceptance failure.

The production client deliberately pins Google HTTPS hosts, so acceptance must not
add a configurable API base URL or a transparent credential-capturing proxy merely
to simulate an error.

## Running the protected workflow

After installing the exact release-candidate commit and creating the four
fixtures, dispatch **Google Workspace environment readiness**. Do not start a
campaign until both matrix jobs produce `result=passed`.

Choose one short campaign slug for the complete release candidate, such as
`rc1-202607`. Reuse it for every dispatch and never place a user name,
environment hostname, OAuth label, token or secret in it. Select the exact
runbook case and PHP runtime on every dispatch. The workflow rejects a scenario
that does not belong to the selected case before it reaches Moodle.

Run scenarios in this order:

1. `snapshot` for each fixture, with no mutation confirmation.
2. `preflight` for single and recurring fixtures.
3. `synchronise` for the single fixture with `expected_schedule=single` and
   confirmation checked.
4. `poll` if the returned state is pending.
5. `synchronise` again for the idempotency check.
6. `synchronise` for the recurring fixture with
   `expected_schedule=recurring`.
7. `synchronise` for the guest fixture with the exact expected count.
8. Repeat guest synchronization after an enrolment change.
9. Hold, record and process the recording fixture; dispatch `recordings`.
10. Dispatch `recordings` again.
11. Dispatch `cancel` for resources designated for cleanup.
12. Finish with `snapshot` and inspect Google Calendar/Drive manually.

Repeat the complete campaign on the PHP 8.3 and PHP 8.4 dedicated runners. Use
the same campaign slug, source commit and plugin version. Do not relabel an
artifact from one runtime as another: the CLI fixture derives `php_runtime`,
`moodle_version` and `moodle_branch` from the executing process and site.

GW-03 and GW-13 deliberately include closed failure evidence before recovery.
Those individual Actions runs finish with a failed conclusion after uploading
their sanitized artifact; a red run is expected for that phase and must not be
rerun as though it were an infrastructure flake. The campaign gate accepts it
only alongside the required recovered run and a passed manual review.

For `synchronise` and `cancel`, check **Confirm create/update/cancel on the
dedicated Google tenant**. The workflow converts that explicit boolean into the
fixed CLI acknowledgement only after validating the closed scenario.

The live executor invokes the same production OAuth managers, Calendar catalog,
Calendar adapter, meeting manager, recording client and recording manager used by
ad hoc tasks. It drives those managers directly so the selected scenario remains
bounded and does not leave a duplicate task in Moodle's queue. GW-14 separately
validates the real UI-to-queue path.

## Evidence review

Before accepting a run:

1. Download the seven-day JSON artifact.
2. Confirm `source_commit` is the expected default-branch commit.
3. Confirm `plugin_version` matches the reviewed release candidate.
4. Confirm `result=passed`.
5. Confirm the required checks are `passed`; `pending` is allowed only as the
   intermediate result of asynchronous creation.
6. Confirm state and counts match the selected fixture.
7. Confirm Google Calendar contains no duplicate event.
8. Confirm Google Drive contains no `anyone` permission added by the plugin.
9. Confirm Moodle and GitHub logs contain no OAuth value, email address, Meet URI
   or raw provider response.

### Campaign completion gate

After both runtime passes, download every JSON artifact into one directory. Copy
`tests/fixtures/acceptance/manual-review.example.json` to a separate working
file and replace only:

- `campaign_id`;
- `source_commit`;
- `plugin_version`;
- each closed `pending` review status with `passed` or `failed`.

The review document has no reviewer-name or free-text field. Provider IDs, URLs,
email addresses, log excerpts and explanations belong neither there nor in the
evidence directory. A protected-environment approval and the repository's
normal review history establish who reviewed the campaign.

Run:

```bash
php tests/fixtures/acceptance/verify_campaign.php \
  /absolute/path/to/downloaded-evidence \
  /absolute/path/to/manual-review.json
```

The verifier fails unless:

- all evidence belongs to one campaign, exact source commit and plugin version;
- every document uses schema 2, Moodle branch `502` and its actual PHP 8.3 or
  PHP 8.4 runtime;
- every case/scenario pair is allowed by this matrix;
- duplicate JSON documents and secret-like material are absent;
- repeated synchronization evidence exists for idempotency, recurrence edits
  and attendee changes;
- cancellation is followed by a snapshot;
- the asynchronous case contains successful synchronization and polling;
- Calendar and recording revocation each have failed and recovered evidence;
- every required automated case exists on both PHP runtimes;
- all 14 closed manual-review statuses are `passed` for both runtimes.

GW-10 is satisfied by the deterministic CI tests for 429, 412 and 5xx plus its
manual-review status; the campaign verifier intentionally does not require
traffic that forces a live-provider quota error.

The normal Moodle CI matrix runs
`tests/fixtures/acceptance/selftest_campaign.php` under PHP 8.3 and PHP 8.4. The
self-test creates only synthetic local JSON, proves that a complete campaign is
accepted, removes one required artifact and proves the incomplete set is
rejected. It does not bootstrap Moodle or open a network connection.

If the JSON validator detects a URL, email-like value, bearer token, common Google
access-token prefix, token/secret field name or forbidden remote-identifier key,
the workflow fails before artifact upload.

## Cleanup

After every cycle:

- cancel all dedicated Calendar events through the tested lifecycle;
- remove any remaining test events manually only after recording the discrepancy;
- remove test students and reset fixture courses;
- delete test recordings according to the tenant's retention policy;
- revoke both test OAuth grants;
- clear Moodle's dedicated test-user OAuth sessions through the normal Moodle
  lifecycle;
- archive only sanitized evidence required by the release decision;
- rebuild or clean the self-hosted runner.

Do not delete a failed remote resource before capturing its local closed state and
provider-side manual observation. Never copy tokens, response bodies or provider
URLs into an issue or pull-request comment.
