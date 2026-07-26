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

## Next structural slice

The next slice will add the synchronization repository, Lock API coordination and an
ad hoc task boundary. Google Calendar calls will only move behind that boundary after
the local transition rules and locking behavior are covered by tests.
