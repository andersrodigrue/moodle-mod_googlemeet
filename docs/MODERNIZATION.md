# Moodle 5.2 modernization

The `3.0.0-dev` line targets Moodle 5.2 with PHP 8.3 and 8.4. It preserves the
`mod_googlemeet` component name so existing sites can upgrade in place.

This development line is alpha software and is not ready for production use.

## Foundation scope

The first structural slice establishes the data and state contracts required by the
future asynchronous Google integration. It does not yet replace the legacy Calendar
or OAuth clients.

The activity now distinguishes three integration modes:

| Mode | Meaning |
|---|---|
| `manual` | The teacher supplied a link; Moodle does not manage a remote event. |
| `legacy` | The old client created or linked the meeting; explicit reconnection is required. |
| `managed` | The modern integration owns and reconciles the remote lifecycle. |

The synchronization lifecycle uses these states:

| State | Meaning |
|---|---|
| `draft` | Stored locally; synchronization has not been requested. |
| `queued` | Waiting for a synchronization worker. |
| `syncing` | A worker is reconciling the remote event. |
| `pending` | The event exists; conference creation is still pending. |
| `ready` | Local and remote state are reconciled. |
| `failed` | The previous attempt failed. |
| `cancelling` | Remote cancellation has been requested. |
| `cancelled` | Cancellation has been reconciled. |
| `disconnected` | The owner must reconnect before synchronization can continue. |

Repeating the current state is valid. This makes worker transitions idempotent after
timeouts or duplicate task delivery.

## Upgrade policy

The upgrade from `2.1.2` is conservative:

- old fields and records are not removed;
- the existing `eventid` is retained but is explicitly treated as a legacy Calendar
  link identifier, not as a Calendar API event ID;
- the existing meeting URL is copied to `meetinguri`;
- activities without a legacy event identifier are classified as `manual` and
  `ready`;
- activities with a legacy event identifier are classified as `legacy` and
  `disconnected`;
- no remote Google request and no permission change is made during the upgrade;
- the new `googleeventid` remains empty until a future explicit reconciliation.

## Synchronization boundary

The second structural slice adds:

- `sync_repository`, which validates transitions and restricts synchronization
  fields before persistence;
- one Moodle Lock API resource per activity, using the
  `mod_googlemeet_meeting_sync` namespace;
- `meeting_manager`, which queues and processes local state under that lock;
- the `synchronise_meeting` ad hoc task, with only the activity ID in custom data;
- duplicate task suppression through Moodle's task manager;
- owner-scoped task execution when `owneruserid` is available;
- bounded, sanitized synchronization error storage.

The task is intentionally not listed in `db/tasks.php`: ad hoc tasks are queued
programmatically and are not scheduled tasks.

Manual links settle as `ready` and legacy meetings return to `disconnected`. No form
or legacy client queues this task yet.

## Managed Calendar adapter

The third structural slice adds an isolated managed Calendar adapter:

- `calendar_client` defines the future OAuth-aware transport without selecting a
  Google SDK;
- event IDs are derived deterministically from the site namespace and activity ID
  using only Calendar-compatible base32hex characters;
- conference request IDs are deterministic for the same logical event and are
  reused across transport retries;
- after Google explicitly reports `failure`, an intentional retry advances the
  request generation so Google receives a new create request instead of ignoring
  the previous ID;
- new events use `conferenceData.createRequest`, the `hangoutsMeet` solution and
  `conferenceDataVersion=1`;
- an insert conflict for the controlled event ID falls back to `events.get`, which
  reconciles a response lost after a successful remote insert;
- pending conference creation is polled without sending another mutation;
- transient transport exceptions remain retryable, and a task can resume safely
  from `syncing` with the same controlled identifiers;
- existing events are patched, and a ready event does not request a second
  conference;
- `pending`, `success` and `failure` responses are validated explicitly before any
  remote identifiers or join URI are persisted;
- only an absolute HTTPS video entry point is accepted as the meeting URI;
- configuration and response failures are persisted with stable, non-secret error
  codes.

The adapter remains available to `meeting_manager` through dependency injection, so
its request and reconciliation rules can be tested independently of credentials and
network behavior.

## OAuth transport and pending reconciliation

The fourth structural slice provides the first production composition:

- `oauth_manager` loads the issuer stored on the activity and refuses to use a token
  unless the ad hoc task is running as the stored meeting owner;
- the issuer authorization endpoint must be HTTPS on `accounts.google.com`;
- the managed integration requests only
  `https://www.googleapis.com/auth/calendar.events`; Drive access is not added;
- Moodle's user OAuth client is created with automatic refresh enabled, so access
  and refresh tokens remain in Moodle core's storage rather than plugin tables;
- explicit plugin disconnect delegates to Moodle core's scoped `log_out()` lifecycle;
- the plugin does not silently call Google's project-wide revocation endpoint,
  because that operation invalidates all scopes granted to the OAuth project and
  could also disrupt Google login or another integration using the same issuer;
- `moodle_oauth_http_client` checks authorization before each request and restricts
  bearer-token requests to `https://www.googleapis.com`;
- `google_calendar_client` percent-encodes path values, whitelists query
  parameters and decodes the complete Calendar error response;
- invalid or insufficient authorization moves the meeting to `disconnected`;
- permanent API rejections store only stable local codes, never Google's remote
  message;
- rate limits, precondition failures and 5xx responses remain exceptions so
  Moodle's ad hoc retry policy can apply backoff;
- an insert conflict remains the idempotent signal used to read the controlled
  event;
- `reconcile_pending_meetings` runs every five minutes and only queues owner-scoped
  ad hoc tasks; cron never reads or uses a teacher's OAuth token itself;
- pending conferences older than five minutes and `syncing` records stale for
  thirty minutes are reconciled in bounded batches of 100.

The production task now composes this OAuth-aware client for records already in
`managed` mode. Existing `manual` and `legacy` records do not begin making Google
requests as a side effect of upgrade.

## Managed activity form and Boost status

The fifth structural slice connects teacher actions to the managed boundary:

- the activity form now offers an explicit choice between a Calendar-managed
  meeting and an existing manual Meet link;
- managed mode uses the Calendar-only OAuth client; the legacy Drive + Calendar
  client no longer creates events during activity form submission;
- the teacher authorizes Google Calendar before saving a managed activity;
- the callback validates the Moodle session key, configured issuer and current user;
- owner and issuer identifiers are always derived on the server and cannot be
  supplied or transferred through submitted form fields;
- existing managed meetings cannot be silently downgraded to manual links or taken
  over by a different editor;
- the legacy date controls are normalized to absolute `timestart` and `timeend`
  values in the owner's IANA timezone;
- weekly form recurrence becomes one canonical RFC 5545 `RRULE`, with a UTC
  inclusive end-of-day boundary;
- new managed activities are inserted as `draft` and immediately queued in the
  owner's context;
- managed edits preserve remote identity and queue reconciliation instead of
  making a synchronous Google request;
- a concurrent edit can queue follow-up reconciliation without rewinding an active
  `syncing` state;
- successful synchronization mirrors the validated `meetinguri` into the legacy
  `url` field while older consumers are being migrated;
- the activity page renders a Boost-compatible status card for every managed or
  unsettled meeting;
- students see a join button only after synchronization reaches `ready` with a
  valid `https://meet.google.com/...` URI;
- sanitized diagnostics are visible only to users with the existing editing
  capability;
- manual mode remains deliberate and never receives an OAuth owner, issuer or
  remote Calendar identity.

Legacy activities remain classified as `legacy` until a teacher edits and explicitly
chooses managed Calendar ownership or confirms the preserved link as manual.

## Next structural slice

The next slice should complete remote deletion/cancellation, add an owner-only
reconnect/retry action using POST plus `sesskey`, and migrate remaining legacy Drive
recording flows away from the broad combined OAuth client. It should also implement
the Moodle 5.2 activity overview integration and extend backup/restore tests for the
new managed lifecycle.
