# Google Meet™ for Moodle

The Google Meet™ for Moodle plugin lets a teacher create and manage a Google
Meet room without leaving Moodle and publish links to generated meeting
recordings.

This fork preserves the original GPL history and is being modernized for Moodle
5.2. See [the modernization foundation](docs/MODERNIZATION.md) for the current
migration status and compatibility boundaries.

The supported in-place source baseline is the preserved `v2.1.1` tag
(`2023050101`). The [3.0 upgrade-readiness report](docs/UPGRADE-3.0.md) documents
every savepoint, retained legacy field, data invariant, rollback boundary and
production rehearsal required before a stable release.

CI now gates the normal fresh-install suite behind a real upgrade rehearsal on
PHP 8.3 and 8.4: it installs the preserved `v2.1.1` code and schema, seeds legacy
records, overlays the development version, runs Moodle's CLI upgrader and
verifies the migrated schema and data before PHPUnit.

Real-provider release acceptance is defined separately in the
[Google Workspace acceptance runbook](docs/GOOGLE-WORKSPACE-ACCEPTANCE.md).
Its workflow is manual-only, restricted to the protected default branch and a
dedicated self-hosted test tenant. OAuth grants remain in Moodle, Calendar
mutations require explicit confirmation, and only schema-validated sanitized
evidence may be uploaded. Each artifact is bound to a closed `GW-01` through
`GW-14` case, campaign, source commit, Moodle 5.2 site and actual PHP 8.3/8.4
runtime. An offline completion gate checks both runtime passes and the closed
manual review without retaining provider identifiers or free-text observations.
It never runs for `push` or `pull_request`.

A separate protected readiness workflow checks both disposable Moodle sites
without contacting Google. It requires independent database profiles, disabled
outbound email, the exact dispatched plugin tree, distinct configured Google
issuers and the four dedicated acceptance fixtures before any provider scenario
can start.

<div>
<img src="https://ronefel.nimbusweb.me/box/attachment/8669013/93arpv0xye1v1fuw44bs/RFOdRT6UcpaK9F8a/screen1.png" alt="screen1.png" width="270" />
<img src="https://ronefel.nimbusweb.me/box/attachment/8669016/no8bamexlmcaw3cbk22g/a4Fo3nWopQTWd7PJ/screen2.png" alt="screen2.png" width="270" />
<img src="https://ronefel.nimbusweb.me/box/attachment/8669017/wlb0oosfs8pgpll1o18l/EHMyOvHzWaQMnWHL/screen3.png" alt="screen3.png" width="270" />
<img src="https://ronefel.nimbusweb.me/box/attachment/8669019/tf0y4bgv6rgvay0hc54b/MWncdrQf0Xx67Zas/screen4.png" alt="screen4.png" width="270" />
<img src="https://ronefel.nimbusweb.me/box/attachment/8669020/zoxcrmbwgty0qd989g8z/SQwhOZtDqpMCiPiY/screen5.png" alt="screen5.png" width="270" />
<img src="https://ronefel.nimbusweb.me/box/attachment/8703578/il2crnahoh0spr56owby/c2rpQQ0Eb5Z9co6F/screen6.png" alt="screen6.png" width="170" />
</div>

## Requirements

Moodle 5.2.x

PHP 8.3 or 8.4

The `3.0.0-alpha.1` release is intended only for controlled testing and is not
ready for production use. Start with the focused [alpha testing guide](TESTING.md);
the protected dual-runtime Google Workspace campaign remains a later beta/stable
release gate.

## Installation

1.  Copy this plugin to the `mod\googlemeet` folder on the server
2.  Login as administrator
3.  Go to Site Administrator > Notification
4.  Install the plugin

## Google OAuth configuration

The development version separates the two per-teacher Google grants:

- The Calendar issuer selects a writable calendar with the read-only
  `calendar.calendarlist.readonly` scope and creates, updates and cancels the
  meeting event with the `calendar.events` scope.
- A second, dedicated recording issuer discovers generated recording metadata
  with only the `meetings.space.readonly` scope.

Create two Google OAuth 2 services in Moodle and select them under
**Site administration > Plugins > Activity modules > Google Meet**. The
recording issuer must be different from the Calendar issuer. It must use the
Google authorization endpoint and must not be shared with Google login.

The recording flow uses the Google Meet REST API. It finds conference records
with an exact normalized meeting-code filter, follows bounded pagination, and
accepts only `FILE_GENERATED` artifacts. It stores the validated Drive file ID
and playback URI returned by Meet; it does not scan a localized Drive folder,
request a broad Drive scope, change Drive permissions, or associate files by
activity title.

Conference records can expire remotely. Consequently, a discovery response
that omits an existing artifact never deletes the local recording reference.
Recording references and recording OAuth ownership are also excluded from
activity backup and restore.

Authorization is explicit and per teacher. A user with the recording
synchronization capability connects Google from the activity page and starts
discovery through a session-protected POST action. Discovery then runs in an
owner-scoped ad hoc task with retry handling for transient API failures.

Managed meetings now support asynchronous creation, reconciliation and explicit
owner-only cancellation. Retry, reconnect and cancel commands are submitted through
session-protected POST actions; remote Calendar deletion is never triggered merely
by deleting the Moodle activity.

When creating a managed activity, the teacher explicitly selects from calendars
where Google reports at least writer access. The selected Calendar ID is verified
again while saving and becomes immutable once attached to the activity. Existing
pre-release `primary` aliases are canonicalized to the exact primary Calendar ID
on edit; a removed or downgraded calendar causes a visible failure and is never
silently replaced by another destination. Teachers with an older Calendar grant
must reconnect once to approve read-only access to their calendar list.

The Moodle Privacy API declares Calendar ownership, recording authorization,
legacy organizer data, reminder receipts, external Google processing and the
Moodle subsystems involved. A teacher can detach recording discovery while
preserving recordings already published to the course. A managed Calendar
integration can be detached only after its remote event has been successfully
cancelled. Privacy deletion clears local owner links and diagnostics but does not
revoke an OAuth grant shared by other activities or delete shared recording
references.

Moodle 5.2's centralized Activities page shows the meeting time, synchronization
state and a safe primary action. Backups preserve portable scheduling settings but
never clone OAuth ownership or remote Calendar identity into a restored activity.

The canonical `timestart`, `timeend`, timezone and recurrence fields now drive both
Moodle Calendar and reminders. Local occurrence IDs are reconciled instead of
blindly recreated, preserving reminder receipts across ordinary edits. Reminder
recipients are selected through a dedicated capability rather than a hard-coded
role ID.

The activity editor writes that canonical schedule directly with Moodle date/time,
timezone and weekly recurrence controls. Deprecated date, clock and recurrence
columns are no longer submitted by new forms or emitted by new backups; they remain
temporarily available only so older installations and backups can be converted.
Imported recurrence exceptions that cannot be represented by the weekly editor are
preserved read-only rather than silently simplified.

Calendar invitations are disabled by default. A teacher can explicitly choose to
invite active course participants who have the
`mod/googlemeet:receivecalendarinvite` capability. The plugin applies a hard
limit of 200 unique attendees and aborts instead of sending a partial list. It
preserves guests added directly in Google Calendar and their RSVP status, stores
only Moodle user IDs and normalized one-way email hashes, and reconciles
enrolment changes in bounded background batches. Google sends attendee updates
only for an initial invitation, an actual membership change, or cancellation;
ordinary meeting edits do not repeat invitation email.

Guest receipts and the invitation policy are excluded from backup and reset on
restore. They are declared to Moodle's Privacy API and can be exported or
deleted without making an unexpected remote Calendar request.

Administrators with the dedicated diagnostic capability can inspect bounded
operational transitions for Calendar synchronization, cancellation, guest
reconciliation and recording discovery. This view stores only closed state and
source values plus stable diagnostic codes: it excludes user IDs, OAuth data,
attendee addresses, Google response bodies and free-form errors. Retention
defaults to 30 days, is configurable within a fixed 7–180 day range and is
enforced by a daily bounded purge task. Diagnostic rows are not copied by
activity backup and restore.

Participant entry is controlled by one server-side policy across the activity
page, Moodle 5.2 Activities overview and Moodle app output. By default, the
local join gateway opens 15 minutes before each occurrence and closes 60 minutes
after it ends; administrators can select bounded alternatives in the activity
module settings. Meeting managers may enter outside the participant window, but
readiness and an exact Google Meet URI are still required. Presentation surfaces
never receive the provider URI: they link to the local gateway, which evaluates
the current server time again before redirecting. Recurring meetings apply the
same interval to every occurrence, and recording visibility remains independent
from live-meeting entry.

Recording presentation now uses Moodle 5.2 namespaced external functions and a
vanilla ES6 AMD module. Rename and visibility requests are explicit and
idempotent, and all mutations derive their target from the authorized course
module. Removing recordings deletes only Moodle's local references after a
specific confirmation; it never deletes files from Google Drive. Stored
playback links, including historical rows, are revalidated against their exact
Drive file ID before reaching either the web page or Moodle app, and unsafe
values fail closed.

The activity page now composes Calendar state, recording discovery, OAuth
notices and safe navigation through Moodle renderables and Mustache templates.
All Calendar and recording lifecycle states have deterministic Boost
presentation, command controls remain session-protected POST forms, and the
meeting provider URI never enters the activity-actions template context.

## Security

If you discover any security related issues, please email [ronefel@hotmail.com](mailto:ronefel@hotmail.com) instead of using the issue tracker.

## License

2020 Rone Santos <ronefel@hotmail.com>

The GNU GENERAL PUBLIC LICENSE. Please see [License File](LICENSE.md) for more information.

> ©2018 Google LLC All rights reserved.<br/>
> Google Meet and the Google Meet logo are registered trademarks of Google LLC.
