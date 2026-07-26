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

This slice still performs no Google API calls. Manual links settle as `ready`, legacy
meetings return to `disconnected`, and managed meetings fail safely with
`adapter_unavailable` until the Calendar adapter is implemented. No form or legacy
client queues this task yet.

## Next structural slice

The next slice will add the managed Google Calendar adapter behind
`meeting_manager`, including deterministic event identity, conference request
idempotency and explicit handling of `pending`, `success` and failure outcomes.
