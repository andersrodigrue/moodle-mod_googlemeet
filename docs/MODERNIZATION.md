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

The adapter is available to `meeting_manager` through dependency injection. There is
deliberately no concrete OAuth/HTTP implementation yet, so normal production
execution continues to fail safely with `adapter_unavailable` and performs no Google
request. This lets the request and reconciliation rules be tested independently of
credentials and network behavior.

## Next structural slice

The next slice will implement the OAuth-aware `calendar_client`, token refresh and
revocation behavior, safe translation of Google API errors, pending reconciliation
scheduling, and the first production composition point. Only after that boundary is
covered will activity create/update flows queue managed synchronization.
