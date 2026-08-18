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

The upgrade from the inherited stable `2.1.1` tag is conservative:

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
- the managed integration requests only event management and read-only
  Calendar-list access (`calendar.events` plus
  `calendar.calendarlist.readonly`); Drive access is not added;
- Moodle's user OAuth client is created with automatic refresh enabled, so access
  and refresh tokens remain in Moodle core's storage rather than plugin tables;
- issuer-level account revocation delegates to Moodle core's scoped `log_out()`
  lifecycle, while activity-level detachment never revokes a grant that another
  activity may still use;
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
- the activity form persists absolute `timestart` and `timeend` values in an
  explicit IANA timezone;
- weekly form recurrence becomes one canonical RFC 5545 `RRULE`, with a UTC
  inclusive boundary;
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

## Idempotent cancellation and owner commands

The sixth structural slice completes the managed event cancellation boundary:

- the Calendar transport now supports `DELETE` with the stored event ID and the
  configured `sendUpdates` policy;
- successful empty responses and already absent events (`404` or `410`) settle as
  the same idempotent cancellation result;
- cancellation is queued in the meeting owner's OAuth context and retains the
  remote event ID as an audit and retry boundary;
- the local Meet URI and legacy URL are cleared only after remote cancellation has
  been reconciled;
- transient transport failures remain retryable by Moodle's ad hoc task runner;
- permanent or authorization failures retain the `cancelling` intent, preventing a
  reconnect or retry from accidentally recreating the event;
- retry, reconnect and cancel commands use owner-only POST forms protected by
  Moodle `sesskey` validation and a dedicated capability;
- reconnect resumes the interrupted operation: synchronization for a disconnected
  meeting, or deletion for a cancellation blocked by authorization;
- students and non-owners receive no command URLs or session keys in the rendered
  status model.

Deleting the Moodle activity still removes local data only. Remote cancellation is
an explicit teacher command so ordinary course cleanup cannot silently delete an
external Calendar event.

## Moodle 5.2 overview and portable restore

The seventh structural slice integrates the activity with Moodle's centralized
course overview and defines safe backup semantics:

- `classes/courseformat/overview.php` implements the Moodle 5.2 activity overview
  contract;
- the overview displays the meeting start through Moodle's human-date renderable,
  the normalized synchronization state and a primary action;
- ready meetings expose a validated Google Meet join action, while every other
  state links to the local activity status page;
- the legacy module `index.php` now redirects to the centralized Activities page;
- backup transports schedule, timezone, recurrence, notification policy and
  integration mode;
- backup deliberately excludes OAuth ownership, issuer IDs, Calendar event IDs,
  conference identifiers, request IDs and operational errors;
- restored manual meetings retain their explicit link and settle as `ready`;
- restored managed meetings retain schedule settings but become ownerless and
  `disconnected`, with no join URI or remote identity;
- an authorized teacher can explicitly claim an ownerless restored managed copy,
  which will create a distinct event for the new activity;
- recording references are addressed by the separate recording-discovery boundary
  described below and are not transported by backup.

These rules prevent a duplicated course or imported backup from controlling the
same external event as its source.

## Exact and least-privilege recording discovery

The eighth structural slice replaces the remaining legacy Drive recording client:

- recording discovery uses the Google Meet REST API instead of listing a localized
  `Meet Recordings` folder in Drive;
- conference records are selected through the exact normalized
  `space.meeting_code` filter; activity titles and folder names are never accepted
  as association keys;
- only `FILE_GENERATED` recording resources are persisted;
- the Meet response must contain a valid recording parent, bounded Drive file ID,
  RFC 3339 time range and an HTTPS `drive.google.com` playback URI bound to that
  same file ID;
- conference and recording lists follow opaque page tokens with loop detection and
  a hard 100-page limit;
- the application stores each Drive file ID at most once per activity and preserves
  teacher-edited recording names and visibility on later discoveries;
- remote absence never deletes a local recording reference, because Meet conference
  records can expire and an incomplete remote response is not proof of deletion;
- the browser no longer submits a caller-controlled array of Drive files to an AJAX
  synchronization method;
- the legacy broad Drive client, generic REST wrapper, GET synchronization action
  and Drive-folder template are removed;
- a dedicated recording OAuth issuer requests only
  `https://www.googleapis.com/auth/meetings.space.readonly`;
- the recording issuer must differ from the Calendar/login issuer, preventing
  Moodle's issuer-level token lookup from ambiguously reusing a refresh token with
  a different grant;
- bearer requests are restricted to HTTPS on `meet.googleapis.com`;
- authorization is explicit, per teacher and protected by capability and session
  key checks;
- discovery runs as an owner-scoped ad hoc task with five attempts; authorization
  failures become `disconnected`, permanent or malformed responses become `failed`,
  and transient transport failures remain retryable;
- backup excludes recording references, recording ownership, issuer IDs and
  operational state; restored activities always require a fresh recording
  connection;
- the obsolete organizer-email form field is no longer collected.

This boundary intentionally does not request a Drive API scope and never mutates
Drive permissions. The Drive file ID and playback URI are metadata returned by the
Meet API for a generated recording.

## Privacy API and explicit activity detachment

The ninth structural slice closes the local personal-data lifecycle:

- the provider now declares the activity-owner, recording-owner, legacy organizer
  and reminder-recipient relationships instead of reporting only reminder receipts;
- metadata covers the three local data tables, the Google Calendar and Google Meet
  external locations, and Moodle's OAuth 2, Calendar and messaging subsystems;
- context discovery and user discovery include both OAuth owners, the legacy email
  relationship and notification recipients;
- the former `choice` module-name error in user discovery is removed;
- exports use separate subcontexts for Calendar authorization, recording
  authorization, shared recording references and notification receipts, avoiding
  one receipt overwriting another at the activity root;
- privacy deletion clears only matching owner links, issuer references, remote
  Calendar identity and sanitized operational diagnostics;
- legacy organizer email and its obsolete external event identifier are removed
  when the Moodle user's email matches case-insensitively;
- recording IDs, names, visibility and playback links remain shared course content
  when the teacher who authorized discovery is removed;
- context-wide deletion preserves the activity schedule and manual Meet links, and
  never deletes shared recording references;
- all lifecycle mutations run under the same activity-scoped lock used by
  synchronization workers;
- activity-level deletion and detachment never call Google or log out an OAuth
  issuer, because the grant belongs to the Moodle user and may serve other
  activities;
- recording owners receive a session-protected POST command that detaches discovery
  while preserving already published references;
- Calendar owners can remove the local authorization and remote identifiers only
  after the remote cancellation has settled as `cancelled`, preventing an active
  external event from being silently orphaned;
- automated Privacy API tests extend Moodle's dedicated
  `core_privacy\tests\provider_testcase` and exercise metadata, discovery, export,
  single-user deletion, approved-user batches and context-wide deletion.

External copies remain governed by the connected Google account. A Moodle privacy
request removes the plugin's local personal-data relationship; it does not claim to
erase a Calendar event or Drive recording from Google. Remote Calendar deletion is
the explicit cancellation command, and remote recording retention is managed in
Google Drive.

## Canonical local schedule and reminders

The tenth structural slice removes the legacy schedule calculation from runtime
and makes the normalized fields authoritative:

- `schedule_expander` derives a bounded list of local occurrences from
  `timestart`, `timeend`, the IANA timezone and the normalized recurrence;
- weekly interval and weekday expansion preserves local wall-clock time across
  daylight-saving transitions;
- the supported local recurrence boundary includes the form-generated `UNTIL`
  rules and bounded imported `COUNT`, `RDATE` and `EXDATE` values;
- every occurrence receives a stable activity-scoped key based on its canonical
  start timestamp;
- `schedule_manager` reconciles rows and Moodle Calendar events, updating
  unchanged occurrences in place instead of deleting and recreating the series;
- reminder receipts survive name, description and duration edits when the
  occurrence start is unchanged;
- removed occurrences delete their linked Calendar event and reminder receipts
  through the Moodle Calendar API;
- the standard `googlemeet_refresh_events()` hook can rebuild Calendar events
  from the canonical schedule;
- action events provide a Moodle dashboard action while the occurrence remains
  current, and managed meetings become actionable only in the `ready` state;
- the existing completion-by-view contract remains the appropriate Moodle 5.2
  completion behavior and continues to be marked from the activity view;
- the reminder query is parameterized and bounded, and no longer assumes that
  Moodle's student role has database ID `5`;
- a dedicated `mod/googlemeet:receivenotification` capability selects active
  enrolled recipients, with the student archetype enabled by default;
- a database uniqueness boundary prevents two receipts for the same user and
  occurrence, and the scheduled task stores a receipt only after Message API
  delivery succeeds;
- upgrade fills missing canonical timestamps from existing expanded events,
  consolidates duplicates without losing receipts, links matching Calendar
  events and adds the reconciliation indexes;
- new backups omit the derived occurrence mirror, while restore still accepts
  older backups containing expanded rows;
- restore normalizes old backups and rebuilds the local Calendar mirror from
  portable canonical fields.

## Canonical schedule editor

The eleventh structural slice removes duplicated scheduling controls from the
teacher workflow:

- the activity editor now uses Moodle `date_time_selector` controls directly for
  `timestart`, `timeend` and the inclusive recurrence boundary;
- the meeting timezone is selected explicitly from localized IANA identifiers;
- weekly recurrence uses canonical weekday identifiers, an interval from one to
  thirty-six weeks and a Monday week start;
- form submission no longer persists `eventdate`, separate hour/minute values,
  `addmultiply`, JSON weekdays, `period` or `eventenddate`;
- the persistence mapper strips forged deprecated scheduling fields before
  insertion or update;
- preprocessing decodes the editable weekly `RRULE` subset back into teacher
  controls without deriving values from stale legacy columns;
- imported `COUNT`, `RDATE`, `EXDATE` or otherwise non-representable schedules
  remain canonical and read-only in the editor, so an ordinary title or URL edit
  cannot silently discard occurrence exceptions;
- old backups and records without valid canonical timestamps continue through a
  dedicated legacy conversion path;
- new backups contain only the portable canonical schedule, while restore remains
  able to consume legacy date and recurrence elements;
- the deprecated columns remain physically present with documented compatibility
  defaults. Removing them requires a later deprecation window and a separate
  upgrade decision.

## Explicit Calendar guest policy

The twelfth structural slice makes Calendar invitations opt-in and bounded:

- a managed activity defaults to `none`; the teacher must explicitly choose
  whether Moodle manages active course participants as Google Calendar
  attendees;
- eligibility is controlled by
  `mod/googlemeet:receivecalendarinvite`, assigned to the student archetype by
  default, and evaluated in the activity context so local overrides apply;
- the organizer, deleted or suspended accounts, invalid addresses and duplicate
  normalized addresses are excluded;
- resolution stops with a visible synchronization failure when more than 200
  unique attendees would be managed. The plugin never sends a truncated guest
  list;
- new attendees receive a minimal payload and conservative permissions: they
  cannot modify the event, invite others or see the complete guest list;
- attendee-array updates first read the current Calendar event because Google
  replaces the complete array. The plugin removes only addresses proven by the
  preceding local receipt to be Moodle-managed, preserving manually added
  Calendar guests and writable RSVP state;
- `sendUpdates=all` is derived server-side only for initial invitations,
  membership changes and cancellation. Schedule or title changes with the same
  guest set use `sendUpdates=none`, preventing repeated mass email;
- raw email addresses exist only inside the owner-scoped synchronization task.
  Persistent receipts contain a Moodle user ID and a normalized SHA-256 email
  hash used to distinguish managed and manual attendees;
- an hourly scheduled task inspects at most 25 ready activities, and each
  activity is checked no more than once every six hours. It queues an
  owner-scoped ad hoc task only when the bounded participant hash changes; cron
  itself never uses a teacher's OAuth token;
- backup excludes guest receipts and restores the policy as disabled. Privacy
  metadata, discovery, export and deletion cover the local receipts and Google
  Calendar attendee processing without causing a remote mutation from a
  privacy callback.

## Privacy-safe operational observability

The thirteenth structural slice makes the asynchronous integration diagnosable
without turning the diagnostic store into a copy of Google or OAuth data:

- queueing, worker start and terminal outcomes are recorded for Calendar
  synchronization, cancellation and recording discovery;
- pending-conference reconciliation and stale-worker recovery are identified by
  stable diagnostic codes, while guest reconciliation has its own operation;
- operations, outcomes and execution sources use a closed vocabulary; diagnostic
  codes accept only a compact lowercase identifier and reject free-form input;
- the dedicated table contains no Moodle user ID, OAuth issuer, access or refresh
  token, attendee email, remote response body or free-form exception message;
- every stored transition emits a standard Moodle event whose `other` payload
  contains the same bounded vocabulary. Moodle's normal event actor and logging
  metadata remain governed by the site's logging and privacy policies;
- a system-context capability, granted to the manager archetype by default,
  protects the administrator view;
- the view provides a 24-hour outcome summary plus filters by operation, outcome
  and activity, and returns at most 200 recent rows;
- diagnostics are never included in activity backup and are not cloned on
  restore;
- deleting an activity removes its diagnostic rows before the parent activity;
- retention is restricted to 7, 14, 30, 60, 90 or 180 days, defaults to 30 days
  and is enforced daily in batches of at most 5,000 rows;
- automated tests cover the closed vocabulary, privacy boundary, Moodle event,
  manager outcomes, cron origins, bounded queries, purge behavior, activity
  deletion and backup/restore exclusion.

This operational table is intentionally not declared as a user-data table by the
plugin Privacy API because it has no user relationship. The Moodle event log is a
core subsystem and continues to follow the site's configured log retention and
privacy lifecycle.

## Explicit writable Calendar selection

The fourteenth structural slice removes the hard-coded `primary` Calendar
assumption from new activities:

- the owner-scoped OAuth grant combines event management with the narrow,
  read-only `calendar.calendarlist.readonly` scope; it never requests the broad
  `calendar` scope or changes the user's Calendar subscriptions;
- `CalendarList.list` uses `minAccessRole=writer`, a maximum page size of 250,
  opaque page-token encoding, loop detection and a hard ten-page boundary;
- deleted, read-only and explicitly non-Meet-compatible calendars are excluded;
- remote IDs and display labels are length- and control-character-validated, and
  duplicate IDs or malformed responses fail closed;
- a new managed activity presents the teacher's writable calendars, prefers the
  actual primary entry only as a form default and persists the exact selected ID;
- the selected ID is verified again during server-side persistence, closing the
  gap between rendered form options and a forged or stale submission;
- an attached managed activity retains its stored calendar even if another ID is
  submitted. Calendar changes require a separate lifecycle operation so an
  existing remote event cannot be silently orphaned;
- non-owner coeditors neither load their own Google Calendar list nor receive the
  stored Calendar ID in the form; they see only a generic attached state and the
  existing owner-only validation remains authoritative;
- pre-release records containing the API alias `primary` remain operable and are
  canonicalized to the exact primary Calendar ID during the next authorized edit;
- ownerless restored managed activities contain no guessed Calendar ID and must
  select a writable destination when explicitly claimed;
- if access is removed, the calendar disappears, or Google cannot verify the
  selection, saving and synchronization retain the original ID and expose a safe
  failure instead of switching to another calendar;
- older grants without Calendar-list access require one explicit reconnect. The
  new scope belongs only to the per-teacher Calendar integration and is not added
  silently to Moodle's Google login;
- Privacy API metadata now describes the Calendar identifiers, labels, access
  roles and Meet support read temporarily for this selection;
- automated tests cover pagination, access-role and Meet filtering, exact and
  legacy-primary resolution, immutable existing selections, restore behavior,
  least-privilege scopes and fail-closed malformed responses.

## Server-side meeting availability

The fifteenth structural slice makes meeting entry a server decision instead of
a presentation convention:

- one `meeting_access_policy` evaluates lifecycle readiness, exact Google Meet
  URI syntax, the canonical schedule, the caller's management capability and the
  Moodle server clock;
- participant access defaults to 15 minutes before an occurrence through 60
  minutes after its end. Administrators choose only from bounded early and late
  values, and invalid stored settings fall back to those documented defaults;
- the interval is half-open: its exact opening instant is allowed and its exact
  closing instant is denied, eliminating ambiguous one-second behavior;
- the canonical recurrence expander applies the window to every supported
  occurrence. Between occurrences, the participant sees when the next window
  opens; after the last occurrence, the participant receives a closed state;
- malformed recurrence fails closed instead of falling back to the first event
  or exposing the link. A meeting manager may bypass the time window, but cannot
  bypass an unready lifecycle or an invalid stored URI;
- unavailable decision objects never retain the provider URI, reducing the
  chance that a later renderer accidentally leaks it;
- a local `join.php` gateway requires login and `mod/googlemeet:view`, evaluates
  the complete policy again at click time and redirects only when the current
  decision permits entry;
- the activity page, Moodle 5.2 Activities overview and both Moodle app template
  generations point to that local gateway. None receives or renders the raw Meet
  URI, including while access is open;
- reminder messages continue to link to the local activity page and are tested
  not to contain a Google Meet URI;
- the Moodle app join action uses a normal link rather than interpolating the
  provider URI into inline JavaScript;
- entering through overview or mobile still records the standard activity-view
  event and completion before leaving Moodle;
- recordings are rendered independently from the live meeting window, so a
  closed occurrence does not make already published course content disappear;
- the access decision is transient and stores no new personal data, access log
  or provider metadata. Existing Moodle logging remains governed by the site's
  configured retention;
- tests cover exact opening and closing boundaries, pre-window URI non-disclosure,
  recurrence transitions, invalid schedule failure, bounded configuration,
  explicit manager access, overview output, Moodle app output and reminder
  content using a deterministic `core\clock`.

## Activity-scoped recording presentation

The sixteenth structural slice replaces the last legacy recording mutation and
presentation boundary:

- the global `mod_googlemeet_external` class and its old toggle-oriented
  functions are removed from the alpha development line;
- three Moodle 5.2 namespaced external classes expose explicit parameter and
  return structures for rename, visibility and local-reference removal;
- every endpoint resolves the activity from one course-module ID, validates the
  module context and requires its dedicated write capability before reading a
  recording;
- a recording is selected by both local ID and authorized activity ID. Missing
  and cross-activity values produce the same public error, preventing the API
  from becoming an identifier-existence oracle;
- visibility uses the requested boolean instead of toggling stored state, and
  repeated rename, visibility and deletion requests are idempotent;
- removing recordings receives no client-supplied activity instance ID. It
  deletes only local `googlemeet_recordings` references in the authorized
  activity and never calls Google Drive or deletes provider files;
- the dedicated removal capability is marked with Moodle's `RISK_DATALOSS`
  classification;
- the confirmation text explains that files remain in Drive and that a later
  discovery can restore their local references;
- `core/ajax` supplies the session key for the AJAX service calls, while server
  context and capability checks remain authoritative;
- the recording table uses semantic buttons, accessible labels and a normal
  `https` playback link with `target="_blank"` and `rel="noopener"`;
- the embedded jQuery script, `javascript:void`, inline event handlers, mutable
  HTML name insertion and loading overlay are replaced by one vanilla ES6 AMD
  module;
- recording names are written with `textContent`, server-trimmed, required and
  limited to 255 characters;
- historical database rows are revalidated before rendering against the exact
  `https://drive.google.com/file/d/{fileid}/view` boundary. Unsafe, mismatched,
  port-qualified or fragmented values fail closed and expose no raw URI;
- both Moodle app template generations use the same validated data and normal
  safe links rather than interpolating a URL into `window.open`;
- automated tests cover authorized mutations, idempotency, capability failure,
  cross-activity isolation, generic missing-item errors, local-only deletion,
  unsafe historical links, escaped names, mutation-control visibility and
  Moodle app output without inline handlers.

This is an intentional pre-release external-function break in `3.0.0-dev`.
No database schema change is required, but the plugin version advances so Moodle
refreshes the service declarations and JavaScript caches.

## Structured activity-page output

The seventeenth structural slice separates activity presentation from
authorization and command execution:

- the recording section is a Moodle renderable whose template context contains
  the validated recording rows, capability-derived controls, synchronization
  state and owner-scoped OAuth presentation;
- `locallib.php` now performs only the data and capability boundary, renders the
  model and registers the JavaScript resources requested by that model. It no
  longer constructs OAuth notices, status paragraphs, buttons or forms;
- the five recording discovery states have deterministic labels, Boost badges
  and progress behavior. Invalid stored values fail closed as `failed`;
- issuer missing, another owner, connected grant, disconnected grant and OAuth
  composition failure are distinct, testable states. Exception details never
  enter the presentation model;
- synchronization and disconnection remain session-protected POST forms aimed
  at the existing `recordings.php` command boundary. The renderable does not
  mutate OAuth, queue tasks or change ownership;
- the owner may disconnect even when the dedicated issuer is no longer
  configured, preserving a safe local recovery path;
- read-only users receive the client-side table search enhancement, while any
  role with edit or removal controls uses the stable, unpaginated mutation DOM;
- the existing managed-Calendar `sync_status` renderable is now tested across
  all nine lifecycle states, including invalid-state fallback and
  cancellation-specific actions;
- a separate activity-actions renderable exports only the local `join.php`
  gateway. The provider Meet URI held by an allowed access decision never
  reaches its template context;
- managed Calendar detail links accept only trusted HTTPS origins without user
  information, explicit ports or fragments. Legacy event identifiers are
  encoded, and both link types remain capability-scoped;
- normal links, semantic buttons, POST forms, heading hierarchy, visible focus
  controls, status roles, alert roles, hidden loading text and `rel="noopener"`
  provide a consistent Boost and assistive-technology boundary;
- the command endpoints in `action.php` and `recordings.php`, server-side
  access policy, external functions and OAuth storage are unchanged;
- no new personal data or schema is introduced. The version increase refreshes
  templates and cached presentation metadata.

## Complete upgrade contract

The eighteenth structural slice formalizes the complete upgrade path:

- repository history identifies `v2.1.1` / `2023050101` as the last inherited
  stable baseline, while retaining the guarded pre-`eventid` entry point;
- one machine-readable compatibility contract lists every savepoint and every
  legacy activity field that remains in the current schema;
- current form persistence consumes that contract and strips all deprecated
  schedule inputs before any insert or update;
- the real `xmldb_googlemeet_upgrade()` function is exercised from the stable
  baseline and from immediately before the oldest retained savepoint;
- representative linked and manual activities, expanded occurrences, duplicate
  occurrences, reminder receipts and a recording reference cross the complete
  migration chain;
- event-backed and field-only schedules acquire canonical timestamps, timezone
  and recurrence without promoting the ambiguous old `eventid` into the modern
  Calendar API identity;
- duplicate legacy occurrence timestamps are removed before canonical `RDATE`
  generation as well as before stable occurrence-key persistence;
- a second upgrade replay must preserve occurrence and reminder receipt IDs;
- every upgraded plugin table must have exactly the field set declared by the
  fresh-install XMLDB schema, and every declared index must exist;
- legacy schedule columns remain conversion-only, `creatoremail` remains bounded
  to privacy lifecycle compatibility, and `eventid` remains a read-only legacy
  link until explicit reconnection;
- no physical legacy-column removal, Google request, OAuth mutation or task
  execution occurs during this savepoint;
- `docs/UPGRADE-3.0.md` records the data invariants, schema comparison, removal
  gates, rehearsal procedure, rollback boundary and remaining release risks.

## Real CLI migration rehearsal

The nineteenth structural slice turns the migration contract into an
environmental CI gate:

- the checkout uses complete Git history and materializes the exact commit behind
  the preserved `v2.1.1` tag in a separate worktree;
- Moodle Plugin CI installs that unmodified legacy plugin into Moodle 5.2 on
  PostgreSQL 16 under both PHP 8.3 and PHP 8.4;
- a CLI-only seed script first proves that modern fields and tables do not exist,
  then inserts two activities, three occurrence rows, two matching Moodle
  Calendar events, three reminder receipts and three recording references
  directly into the real four-table legacy schema;
- the fixtures deliberately include duplicate occurrence timestamps, a
  duplicate reminder recipient and a duplicate activity-scoped Drive file ID;
- the current plugin tree is overlaid only after the old schema and data exist;
- Moodle's normal non-interactive CLI upgrader executes the complete plugin
  migration with alpha software explicitly allowed;
- a CLI-only verifier confirms version, classification, canonical schedule,
  recurrence, ownership, identifiers, deduplication, safe invitation defaults
  and preservation of distinct records;
- the verifier compares the upgraded database with every table, field and index
  declared for a fresh installation, including rejection of extra plugin
  tables;
- neither fixture script is web-accessible, and neither composes an OAuth client,
  sends mail, queues remote synchronization or calls Google;
- the existing fresh-install and PHPUnit matrix now depends on both migration
  jobs, so an upgrade regression blocks all later test jobs.

No database column is added or removed in this recorte. Savepoint `2026072619`
records the new release gate and keeps the compatibility contract aligned with
`version.php`.

## Protected Google Workspace acceptance

The twentieth structural slice turns real-provider verification into a bounded,
reviewable release gate:

- `.github/workflows/google-workspace-acceptance.yml` has only a
  `workflow_dispatch` trigger and refuses to run outside the repository's default
  branch;
- the job requires the protected `google-workspace-acceptance` Environment and a
  dedicated self-hosted Linux runner carrying the same label;
- the two referenced GitHub-maintained actions are pinned to reviewed immutable
  commit hashes rather than mutable major-version tags;
- no OAuth grant, Google password, Moodle credential or client secret is accepted
  as an input or stored as a GitHub secret. Owner grants remain in Moodle core's
  OAuth storage;
- the Environment supplies only the absolute `MOODLE_CONFIG` path for the
  disposable tenant, after required reviewer approval;
- the workflow validates every interpolated input as a closed identifier or
  bounded integer before using it in the shell;
- the exact protected-branch source is installed while the disposable Moodle site
  is in maintenance mode and Moodle's normal CLI upgrader runs before acceptance;
- the CLI fixture requires an explicit config switch, an `[ACCEPTANCE]` activity
  marker, managed mode, a canonical schedule, exact owner context, a persisted
  issuer and a selected Calendar ID;
- `snapshot` reads local state only, while `preflight` exercises owner OAuth
  refresh, Calendar-list access and exact writable-Calendar resolution;
- `synchronise` and `cancel` require an additional fixed acknowledgement before
  they can create, update or delete a real Calendar resource;
- bounded polling reconciles a pending Meet conference without issuing a second
  conference request;
- recording discovery uses the dedicated owner issuer and production Meet REST
  composition only after the meeting is ready;
- expected guest and minimum recording counts are optional bounded assertions;
- the executor invokes the same production managers used by owner-scoped ad hoc
  tasks but processes directly so the acceptance run does not leave duplicate
  queued work;
- a separate matrix case still validates the normal form-to-ad-hoc-task path with
  cron and Moodle task logs;
- all manager output is buffered and discarded; exceptions become closed local
  codes rather than copying messages or response data;
- the JSON evidence schema contains only local state, counts, presence booleans,
  version, commit, scenario, time and closed check results;
- a second dependency-free validator rejects unexpected fields, URLs, email-like
  strings, bearer tokens, common Google access-token prefixes, token/secret names
  and forbidden remote identifier keys before artifact upload;
- sanitized artifacts expire after seven days, and a failed scenario is uploaded
  only after its evidence passes the same secret boundary;
- the runbook covers consent, refresh, writable calendars, one-off and recurring
  creation, idempotency, asynchronous completion, attendee changes, cancellation,
  recording discovery, revocation and the normal Moodle queue;
- 429, 412 and 5xx behavior remains deterministically injected in PHPUnit. The
  runbook explicitly forbids forcing quota exhaustion against Google's real API;
- the tenant, Google Cloud project, users, calendars, recordings and runner are
  dedicated and disposable, with documented cleanup after every cycle.

No live credential is available to normal CI, and this recorte does not claim a
successful Google Workspace run. It provides the controlled mechanism and
evidence contract required to perform that release gate. No database schema is
changed; savepoint `2026072620` records the new operational contract.

## Dual-runtime acceptance campaigns

The twenty-first structural slice closes the accounting gap between isolated
provider runs and a complete release decision:

- each dispatch carries a short sanitized campaign slug, a closed runbook case
  from `GW-01` through `GW-14`, and a selected PHP 8.3 or PHP 8.4 runtime;
- the case/scenario relationship is enforced independently by the workflow, the
  Moodle acceptance contract and the dependency-free evidence validator;
- dedicated runners have both the common `google-workspace-acceptance` label and
  a runtime-specific `php-8.3` or `php-8.4` label;
- the workflow compares the selected PHP series with the executing binary before
  installing or upgrading the disposable site;
- evidence schema 2 derives and retains the actual PHP major/minor series,
  Moodle branch and Moodle version alongside campaign and case identity;
- campaign IDs are bounded lowercase slugs and reject credential-related words,
  consecutive separators, URLs, email addresses and secret-like material;
- artifact names include campaign, runtime, case and scenario, while the JSON
  continues to omit Calendar IDs, event IDs, Meet URIs, recording links, OAuth
  values and provider response bodies;
- `verify_campaign.php` consumes downloaded evidence without network access,
  rejects duplicate documents and requires one source commit, plugin version,
  campaign and Moodle 5.2 branch;
- the gate requires both PHP runtimes, repeated evidence for idempotency,
  recurrence edits and attendee changes, cancellation followed by snapshot,
  asynchronous polling, and failed/recovered Calendar and recording revocation;
- deterministic CI remains the authoritative GW-10 rate-limit evidence so the
  live campaign never manufactures quota exhaustion;
- a closed manual-review document records only `passed`, `failed` or `pending`
  for every case/runtime. It has no free-text or reviewer-identity field and
  cannot carry provider observations;
- the completion command fails until all automated requirements and all manual
  reviews pass on both runtimes;
- the normal PHP 8.3/8.4 CI matrix runs a hermetic positive/negative self-test
  that accepts a complete synthetic campaign and rejects the same campaign after
  one required artifact is removed.

No Google or GitHub API is called by the campaign verifier. This recorte still
does not claim a successful provider run: it makes that future claim
machine-checkable and prevents a partial, wrong-runtime or mixed-commit set of
artifacts from being treated as a completed campaign. No database schema is
changed; savepoint `2026072621` records the updated operational contract.

## Protected dual-environment readiness

The twenty-second structural slice makes the infrastructure prerequisite
machine-checkable before any provider operation:

- `.github/workflows/google-workspace-readiness.yml` is manual-only, restricted
  to the protected default branch and the reviewed
  `google-workspace-acceptance` Environment;
- its matrix requires one dedicated runner labelled `php-8.3` and another
  labelled `php-8.4`, then verifies each label against the executing binary;
- checkout is pinned to the dispatch event's exact `GITHUB_SHA`, and both GitHub
  actions remain pinned to reviewed immutable commit hashes;
- `check_environment.php` reads local Moodle configuration and database state
  without composing OAuth clients, sending mail, changing data or calling
  Google;
- each site must declare a closed `php83` or `php84` profile in `config.php` and
  store the same profile in its own Moodle database;
- because the database profiles must differ, two runtime frontends backed by one
  shared database cannot both pass;
- outbound Moodle email must be disabled and the disposable site must use HTTPS
  and Moodle branch `502`;
- a recursive SHA-256 manifest verifies that the installed plugin tree is
  byte-for-byte equivalent to the dispatched source, excluding only `.git`;
- Calendar and recording issuers must both be enabled, configured for Google's
  HTTPS authorization host and use different issuer identities;
- exact `[ACCEPTANCE] Single`, `Recurring`, `Guests` and `Recording` fixtures
  must satisfy their closed ownership, schedule, guest and recording
  prerequisites;
- readiness evidence contains only versions, runtime, commit, a closed profile,
  check results and a stable failure code;
- a dependency-free validator rejects unexpected fields, URLs, email addresses,
  token-like material and local or provider identifier fields;
- the ordinary PHP 8.3/8.4 CI validates a synthetic readiness document;
- the live acceptance workflow repeats the readiness check after installing and
  upgrading the exact dispatched source, so no scenario can bypass it.

This recorte does not provision a cloud tenant or claim a successful provider
run. It converts the previously external readiness assumptions into a failing
gate that can be satisfied only by the two dedicated environments. No database
schema is changed; savepoint `2026072622` records the operational contract.

## Durable remote cancellation after local deletion

Deleting a managed Google Meet activity now captures its Google Calendar event
identity in `googlemeet_remote_cleanup` before any activity-owned row is removed.
The capture, owner-scoped ad hoc task and local deletion share one delegated
database transaction. A failure to preserve the remote obligation therefore
rolls back the Moodle deletion instead of orphaning the Google event.
Deletion also acquires the activity synchronization lock, preventing a worker
from creating or updating the event while its local source is being removed.
When a Calendar insert succeeded but its response was lost, the outbox derives
the same controlled event ID used by the adapter and safely deletes it (or
accepts the provider's idempotent not-found response).

The cleanup row intentionally has no foreign key to the activity, course or
user. It retains only the original OAuth owner and issuer, Calendar/event IDs,
last managed guest count and bounded lifecycle diagnostics. The same module
deletion callback is used when a whole course is deleted, so both paths receive
the durable behavior without a separate course observer.

`cancel_deleted_meeting` reconstructs the minimum activity-shaped request under
the original owner's task identity. Calendar DELETE remains idempotent because
the transport treats Google responses 404 and 410 as success. A successful
request removes the tombstone. Transport ambiguity remains in `processing` so
Moodle retries it; authorization, provider and configuration failures remain in
`blocked` with a daily recovery time. The hourly
`reconcile_remote_cleanups` task only restores owner-scoped ad hoc work for
pending, retry-due or abandoned records and never accesses OAuth as the cron
user.

Because the original module context no longer exists, the privacy provider
maps retained cleanup ownership to the system context for discovery, export and
approved erasure. Savepoint `2026072623` installs the durable cleanup table and
records this lifecycle contract.

## Next structural slice

The next slice now has an explicit external entry condition: provision the
independent Moodle 5.2/PHP 8.3 and PHP 8.4 sites, assign their config/database
profiles, install the exact protected commit, create the four dedicated
fixtures, configure the two Google issuers and make both readiness matrix jobs
green. Then execute one campaign against the dedicated Google Workspace tenant.
Every case should retain only the sanitized evidence defined by the runbook;
provider-side observations must be reduced to the closed manual-review status
without copying identifiers, URLs or log excerpts. Any discrepancy should
become a focused code/test fix and require a fresh campaign for the affected
commit. After the dual-runtime campaign gate is green, the development line can
enter API freeze and beta release preparation; physical removal of retained
legacy columns remains deferred until its documented compatibility windows
close.
