# Testing Google Meet for Moodle 3.0.0-alpha.1

This document defines the deliberately small first testing boundary for the
modernized plugin. It is not a production release checklist and does not replace
the complete Google Workspace acceptance runbook.

## Safety boundary

Use a disposable or staging Moodle site. Do not install this alpha directly on a
production Moodle database, do not use staff calendars, and do not authorize a
Google account that owns operational meetings.

Before installation:

- back up the Moodle database and the existing `mod/googlemeet` directory;
- confirm the site is Moodle 5.2 and PHP 8.3;
- disable outbound email if the site contains copied user accounts;
- create a dedicated course, teacher, student, Google account and writable test
  calendar;
- ensure cron can run at least once per minute during the test;
- record the exact plugin version and commit being installed.

The first hands-on pass targets PHP 8.3 because it matches the maintainer's
current Moodle environment. PHP 8.4 remains covered by CI but is not required to
start this controlled alpha evaluation.

## Supported first-pass scope

The alpha smoke test covers only:

- fresh installation or a staged upgrade from the preserved 2.1.1 baseline;
- a manually supplied canonical Google Meet link;
- one owner-authorized managed meeting without recurrence or course guests;
- Calendar creation, polling, ordinary schedule editing and idempotent retry;
- participant entry through the local Moodle gateway;
- explicit cancellation and deletion cleanup;
- a visible, recoverable authorization failure.

The following implemented features are experimental and do not block this first
pass:

- complex recurrence and imported recurrence exceptions;
- Calendar invitations and enrolment reconciliation;
- Google Meet recording discovery;
- reminder delivery;
- cross-site backup and restore;
- full course-deletion campaigns;
- dual-runtime protected Google Workspace acceptance.

Do not report an untested experimental feature as approved merely because the
basic smoke test passed.

## Installation

1. Put the plugin source in a directory named `googlemeet` under Moodle's `mod`
   directory.
2. Verify that `mod/googlemeet/version.php` reports `3.0.0-alpha.1` and version
   `2026081900`.
3. Open **Site administration > Notifications** or run Moodle's normal CLI
   upgrade command.
4. Allow the unstable alpha upgrade only on the disposable site.
5. Purge Moodle caches.
6. Run cron once.
7. Confirm there is no failed Google Meet ad hoc task before configuring OAuth.

Rolling the plugin files back after Moodle advances the database version is not
supported. Restore the pre-test database and plugin-directory backups together
when rollback is necessary.

## Minimal Calendar OAuth setup

Create a Google OAuth service dedicated to this test and select it under:

**Site administration > Plugins > Activity modules > Google Meet**

The Calendar grant must be limited to:

- `https://www.googleapis.com/auth/calendar.events`;
- `https://www.googleapis.com/auth/calendar.calendarlist.readonly`.

Use Google's HTTPS authorization endpoint and a redirect URI belonging to the
staging Moodle site. Do not reuse the site's Google login issuer and do not paste
tokens or client secrets into an issue, test report or GitHub Actions variable.

The separate recording issuer is not required for the first smoke pass unless
recording discovery is tested deliberately.

## Smoke-test accounts

Prepare:

- one administrator who configures the plugin;
- one teacher who owns the Google authorization and Calendar event;
- one actively enrolled student;
- one dedicated Google test account;
- one writable Calendar that supports Google Meet conferencing.

Use a meeting time near the test window. Keep invitation policy disabled and do
not add recurrence during the first managed-meeting pass.

## Test 1: installation and local health

- [ ] The upgrade completes without a database or XMLDB error.
- [ ] The installed release is `3.0.0-alpha.1` / `2026081900`.
- [ ] Cron completes without an unhandled plugin exception.
- [ ] The plugin settings page opens in the Boost theme.
- [ ] A teacher can add the Google Meet activity to the test course.

## Test 2: manual meeting

1. Create a manual activity with a dedicated valid Meet URL.
2. Save and reopen the activity.
3. Confirm that the stored link uses the canonical form
   `https://meet.google.com/abc-defg-hij`.
4. Confirm that the student reaches the local join gateway before Google.
5. Try each invalid example and confirm that saving fails closed:

   - `http://meet.google.com/abc-defg-hij`;
   - `https://meet.google.com.evil.example/abc-defg-hij`;
   - `https://user@meet.google.com/abc-defg-hij`;
   - `https://meet.google.com/abc-defg-hij/extra`.

## Test 3: managed single meeting

1. As the teacher, choose the managed mode.
2. Connect the dedicated Calendar issuer.
3. Inspect the Google consent page before accepting it.
4. Select the dedicated writable calendar.
5. Create one meeting without recurrence or guests.
6. Run cron until the activity reaches `ready`, respecting normal retry timing.
7. Confirm in Google Calendar that exactly one event and one Meet conference
   exist.

Passing result:

- [ ] the activity stores a valid Meet link;
- [ ] the Calendar event belongs to the selected calendar;
- [ ] retrying cron does not create a duplicate event or conference;
- [ ] no token, email address or raw Google response appears in Moodle logs.

## Test 4: edit and join

1. Change the managed meeting start and end time.
2. Save and run cron.
3. Confirm that the original Calendar event was updated rather than replaced.
4. As the student, try joining outside and inside the configured access window.
5. As the teacher, confirm the documented manager override behavior.

Passing result:

- [ ] only one remote event remains;
- [ ] the Moodle and Google schedules agree;
- [ ] the student is denied outside the permitted window;
- [ ] the gateway redirects only to an exact HTTPS Google Meet URI.

## Test 5: cancel and delete

1. Cancel the managed meeting from Moodle.
2. Run cron and confirm that the Calendar event is absent.
3. Repeat the cancellation and confirm that it remains successful.
4. Create a second managed single meeting.
5. Delete its Moodle activity.
6. Run cron and inspect the dedicated Calendar.

Passing result:

- [ ] cancellation removes the join link only after reconciliation;
- [ ] repeated cancellation is idempotent;
- [ ] deletion leaves no duplicate or abandoned test event;
- [ ] a transient Google failure leaves recoverable cleanup state rather than
  blocking the Moodle deletion.

## Test 6: revoked authorization

1. Revoke the dedicated Calendar grant in the Google test account.
2. Edit or resynchronize the managed activity.
3. Confirm that Moodle reports an authorization-required or disconnected state
   without exposing provider details.
4. Reconnect the same teacher explicitly and retry.

Passing result:

- [ ] another teacher cannot take over the stored grant;
- [ ] the existing Calendar identity is not silently redirected;
- [ ] reconnection resumes safely without creating a second event.

## Evidence and issue reports

For each failure, record only:

- the plugin release and commit;
- Moodle and PHP versions;
- the numbered test step;
- the local lifecycle state;
- the sanitized error code;
- whether the problem is reproducible.

Never include:

- access or refresh tokens;
- OAuth client secrets;
- email addresses;
- Calendar IDs or event IDs;
- Meet URLs or meeting codes;
- database connection details;
- complete provider responses or unredacted logs.

Classify findings as:

- **BLOCKER** — security, data loss, failed upgrade, duplicate remote event or
  broken authorization boundary;
- **REQUIRED** — functional failure inside the supported first-pass scope;
- **FOLLOW-UP** — experimental feature or non-blocking improvement;
- **QUESTION** — behavior requiring clarification before classification.

## Alpha completion criterion

The first pass is sufficient when all six smoke-test sections pass on one
disposable Moodle 5.2/PHP 8.3 environment and every blocker or required finding
is corrected and retested.

This result allows continued alpha testing only. Promotion to beta or stable
still requires the complete upgrade rehearsal, protected dual-runtime readiness,
the Google Workspace GW-01 through GW-14 campaign, manual provider inspection and
an independent security/code review.
