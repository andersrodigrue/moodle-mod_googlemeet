<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace mod_googlemeet\local;

use mod_googlemeet\api\calendar_adapter;
use mod_googlemeet\api\calendar_api_exception;
use mod_googlemeet\api\calendar_authorization_exception;
use mod_googlemeet\api\calendar_configuration_exception;
use mod_googlemeet\api\calendar_event_result;
use mod_googlemeet\api\calendar_guest_limit_exception;
use mod_googlemeet\api\calendar_response_exception;
use mod_googlemeet\api\calendar_transport_exception;
use mod_googlemeet\task\synchronise_meeting;

/**
 * Queues and coordinates local meeting synchronization work.
 *
 * Manual meetings settle locally and legacy meetings remain disconnected. A
 * managed Calendar adapter can be injected or created through the production
 * provider without coupling this coordinator to OAuth or a particular Google SDK.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class meeting_manager {

    /** Stable error code used until the managed Calendar adapter is available. */
    private const ERROR_ADAPTER_UNAVAILABLE = 'adapter_unavailable';

    /** Stable error code for legacy meetings requiring a new authorization. */
    private const ERROR_RECONNECT_REQUIRED = 'reconnect_required';

    /** Stable error code for an invalid persisted integration mode. */
    private const ERROR_INVALID_MODE = 'invalid_integration_mode';

    /** Stable error code for invalid managed Calendar configuration. */
    private const ERROR_CALENDAR_CONFIGURATION = 'calendar_configuration_invalid';

    /** Stable error code for an inconsistent Calendar response. */
    private const ERROR_CALENDAR_RESPONSE = 'calendar_response_invalid';

    /** Stable error code for authorization requiring user action. */
    private const ERROR_AUTHORIZATION_REQUIRED = 'authorization_required';

    /** Stable error code for the hard Calendar guest safety boundary. */
    private const ERROR_GUEST_LIMIT = 'calendar_guest_limit_exceeded';

    /** @var sync_repository Synchronization repository. */
    private sync_repository $repository;

    /** @var meeting_lock Per-activity lock coordinator. */
    private meeting_lock $lock;

    /** @var calendar_adapter|null Managed Calendar adapter. */
    private ?calendar_adapter $calendaradapter;

    /** @var calendar_adapter_provider|null Production adapter provider. */
    private ?calendar_adapter_provider $calendaradapterprovider;

    /** @var calendar_guest_resolver Course participant resolver. */
    private calendar_guest_resolver $guestresolver;

    /** @var calendar_guest_repository Managed guest snapshot repository. */
    private calendar_guest_repository $guestrepository;

    /** @var diagnostic_recorder Privacy-safe operational recorder. */
    private diagnostic_recorder $diagnostics;

    /**
     * @param sync_repository|null $repository Synchronization repository.
     * @param meeting_lock|null $lock Per-activity lock coordinator.
     * @param calendar_adapter|null $calendaradapter Managed Calendar adapter.
     * @param calendar_adapter_provider|null $calendaradapterprovider Production adapter provider.
     * @param calendar_guest_resolver|null $guestresolver Course participant resolver.
     * @param calendar_guest_repository|null $guestrepository Managed guest snapshot repository.
     * @param diagnostic_recorder|null $diagnostics Operational recorder.
     */
    public function __construct(
        ?sync_repository $repository = null,
        ?meeting_lock $lock = null,
        ?calendar_adapter $calendaradapter = null,
        ?calendar_adapter_provider $calendaradapterprovider = null,
        ?calendar_guest_resolver $guestresolver = null,
        ?calendar_guest_repository $guestrepository = null,
        ?diagnostic_recorder $diagnostics = null
    ) {
        $this->repository = $repository ?? new sync_repository();
        $this->lock = $lock ?? new meeting_lock();
        $this->calendaradapter = $calendaradapter;
        $this->calendaradapterprovider = $calendaradapterprovider;
        $this->guestresolver = $guestresolver ?? new calendar_guest_resolver();
        $this->guestrepository = $guestrepository ?? new calendar_guest_repository();
        $this->diagnostics = $diagnostics ?? new diagnostic_recorder();
    }

    /**
     * Moves an activity to the queue and suppresses an identical pending task.
     *
     * @param int $googlemeetid Activity instance ID.
     * @param int|null $userid User the task should run as, or null to use the stored owner.
     * @param string $source Diagnostic execution source.
     * @return bool True when a new task was queued, false when an identical task already exists.
     */
    public function queue(
        int $googlemeetid,
        ?int $userid = null,
        string $source = diagnostic_recorder::SOURCE_USER
    ): bool {
        return $this->lock->with_lock($googlemeetid, function () use ($googlemeetid, $userid, $source): bool {
            $meeting = $this->repository->get($googlemeetid);
            $transitionrequired = !in_array(
                $meeting->syncstatus,
                [sync_state::QUEUED, sync_state::SYNCING, sync_state::PENDING],
                true
            );
            if ($transitionrequired) {
                sync_state::assert_transition($meeting->syncstatus, sync_state::QUEUED);
            }

            $taskuserid = $userid;
            if ($taskuserid === null && !empty($meeting->owneruserid)) {
                $taskuserid = (int) $meeting->owneruserid;
            }

            $queued = synchronise_meeting::enqueue($googlemeetid, $taskuserid);
            if ($queued && $transitionrequired) {
                $this->repository->transition($googlemeetid, sync_state::QUEUED);
            }
            if ($queued) {
                $this->diagnostics->record(
                    $googlemeetid,
                    diagnostic_recorder::OPERATION_MEETING_SYNC,
                    diagnostic_recorder::OUTCOME_QUEUED,
                    $source
                );
            }

            return $queued;
        });
    }

    /**
     * Requests idempotent cancellation of a managed meeting.
     *
     * @param int $googlemeetid Activity instance ID.
     * @param int|null $userid User the task should run as, or null to use the stored owner.
     * @param string $source Diagnostic execution source.
     * @return bool True when a new task was queued.
     */
    public function cancel(
        int $googlemeetid,
        ?int $userid = null,
        string $source = diagnostic_recorder::SOURCE_USER
    ): bool {
        return $this->lock->with_lock($googlemeetid, function () use ($googlemeetid, $userid, $source): bool {
            $meeting = $this->repository->get($googlemeetid);
            if ($meeting->integrationmode !== integration_mode::MANAGED) {
                throw new \coding_exception('Only managed meetings can be cancelled remotely.');
            }
            if ($meeting->syncstatus === sync_state::CANCELLED) {
                return false;
            }
            if ($meeting->syncstatus === sync_state::DRAFT && empty($meeting->googleeventid)) {
                $this->repository->transition($googlemeetid, sync_state::CANCELLED);
                $this->diagnostics->record(
                    $googlemeetid,
                    diagnostic_recorder::OPERATION_MEETING_CANCEL,
                    diagnostic_recorder::OUTCOME_SUCCEEDED,
                    $source,
                    'remote_event_absent'
                );
                return false;
            }

            if ($meeting->syncstatus !== sync_state::CANCELLING) {
                sync_state::assert_transition($meeting->syncstatus, sync_state::CANCELLING);
                $meeting = $this->repository->transition($googlemeetid, sync_state::CANCELLING);
            }

            $taskuserid = $userid ?? (int) ($meeting->owneruserid ?? 0);
            if ($taskuserid <= 0) {
                throw new \coding_exception('A managed meeting owner is required for cancellation.');
            }

            $queued = synchronise_meeting::enqueue($googlemeetid, $taskuserid);
            if ($queued) {
                $this->diagnostics->record(
                    $googlemeetid,
                    diagnostic_recorder::OPERATION_MEETING_CANCEL,
                    diagnostic_recorder::OUTCOME_QUEUED,
                    $source
                );
            }

            return $queued;
        });
    }

    /**
     * Processes one queued synchronization under an exclusive activity lock.
     *
     * @param int $googlemeetid Activity instance ID.
     */
    public function process(int $googlemeetid): void {
        $this->lock->with_lock($googlemeetid, function () use ($googlemeetid): void {
            $meeting = $this->repository->get_optional($googlemeetid);
            if ($meeting === null) {
                mtrace(get_string('syncactivitymissing', 'mod_googlemeet', $googlemeetid));
                return;
            }

            if (!in_array(
                $meeting->syncstatus,
                [sync_state::QUEUED, sync_state::SYNCING, sync_state::PENDING, sync_state::CANCELLING],
                true
            )) {
                mtrace(get_string('syncstateskipped', 'mod_googlemeet', (object) [
                    'id' => $googlemeetid,
                    'state' => $meeting->syncstatus,
                ]));
                return;
            }

            if ($meeting->syncstatus === sync_state::CANCELLING) {
                $meeting = $this->repository->start_cancellation_attempt($googlemeetid);
                $this->diagnostics->record(
                    $googlemeetid,
                    diagnostic_recorder::OPERATION_MEETING_CANCEL,
                    diagnostic_recorder::OUTCOME_STARTED,
                    diagnostic_recorder::SOURCE_ADHOC
                );
                $this->process_cancellation($meeting);
                return;
            }

            $meeting = $this->repository->start_attempt($googlemeetid);
            $this->diagnostics->record(
                $googlemeetid,
                diagnostic_recorder::OPERATION_MEETING_SYNC,
                diagnostic_recorder::OUTCOME_STARTED,
                diagnostic_recorder::SOURCE_ADHOC
            );
            $this->process_attempt($meeting);
        });
    }

    /**
     * Deletes a managed event and settles the local cancellation state.
     *
     * Transient transport failures intentionally escape so Moodle retries the
     * same ad hoc task. Authorization and permanent failures remain observable.
     *
     * @param \stdClass $meeting Activity record in the cancelling state.
     */
    private function process_cancellation(\stdClass $meeting): void {
        if ($meeting->integrationmode !== integration_mode::MANAGED) {
            $this->repository->mark_failed(
                (int) $meeting->id,
                self::ERROR_INVALID_MODE,
                get_string('syncinvalidintegrationmode', 'mod_googlemeet')
            );
            $this->record_cancellation_outcome(
                $meeting,
                diagnostic_recorder::OUTCOME_FAILED,
                self::ERROR_INVALID_MODE
            );
            return;
        }
        if ($this->calendaradapter === null && $this->calendaradapterprovider === null) {
            $this->repository->mark_failed(
                (int) $meeting->id,
                self::ERROR_ADAPTER_UNAVAILABLE,
                get_string('syncadapterunavailable', 'mod_googlemeet')
            );
            $this->record_cancellation_outcome(
                $meeting,
                diagnostic_recorder::OUTCOME_FAILED,
                self::ERROR_ADAPTER_UNAVAILABLE
            );
            return;
        }

        try {
            $adapter = $this->calendaradapter
                ?? $this->calendaradapterprovider->create($meeting);
            $adapter->cancel($meeting);
        } catch (calendar_authorization_exception $e) {
            $this->repository->mark_cancellation_blocked(
                (int) $meeting->id,
                self::ERROR_AUTHORIZATION_REQUIRED,
                get_string('syncoauthrequired', 'mod_googlemeet')
            );
            $this->record_cancellation_outcome(
                $meeting,
                diagnostic_recorder::OUTCOME_BLOCKED,
                self::ERROR_AUTHORIZATION_REQUIRED
            );
            return;
        } catch (calendar_api_exception $e) {
            $this->repository->mark_cancellation_blocked(
                (int) $meeting->id,
                $e->error_code(),
                get_string('synccalendarcancelapifailed', 'mod_googlemeet')
            );
            $this->record_cancellation_outcome(
                $meeting,
                diagnostic_recorder::OUTCOME_BLOCKED,
                $e->error_code()
            );
            return;
        } catch (calendar_configuration_exception $e) {
            $this->repository->mark_cancellation_blocked(
                (int) $meeting->id,
                self::ERROR_CALENDAR_CONFIGURATION,
                get_string('synccalendarconfigurationinvalid', 'mod_googlemeet')
            );
            $this->record_cancellation_outcome(
                $meeting,
                diagnostic_recorder::OUTCOME_BLOCKED,
                self::ERROR_CALENDAR_CONFIGURATION
            );
            return;
        } catch (calendar_response_exception $e) {
            $this->repository->mark_cancellation_blocked(
                (int) $meeting->id,
                self::ERROR_CALENDAR_RESPONSE,
                get_string('synccalendarresponseinvalid', 'mod_googlemeet')
            );
            $this->record_cancellation_outcome(
                $meeting,
                diagnostic_recorder::OUTCOME_BLOCKED,
                self::ERROR_CALENDAR_RESPONSE
            );
            return;
        } catch (calendar_transport_exception $e) {
            $this->record_cancellation_outcome(
                $meeting,
                diagnostic_recorder::OUTCOME_RETRYING,
                'calendar_transport_failure'
            );
            throw $e;
        }

        $this->repository->mark_cancelled((int) $meeting->id);
        $this->record_cancellation_outcome($meeting, diagnostic_recorder::OUTCOME_SUCCEEDED);
        mtrace(get_string('syncmanagedcancelled', 'mod_googlemeet', $meeting->id));
    }

    /**
     * Applies the safe local outcome for the stored integration mode.
     *
     * @param \stdClass $meeting Activity record in the syncing state.
     */
    private function process_attempt(\stdClass $meeting): void {
        if ($meeting->integrationmode === integration_mode::MANUAL) {
            $this->repository->transition((int) $meeting->id, sync_state::READY);
            $this->record_sync_outcome($meeting, diagnostic_recorder::OUTCOME_SUCCEEDED, 'manual_mode');
            mtrace(get_string('syncmanualready', 'mod_googlemeet', $meeting->id));
            return;
        }

        if ($meeting->integrationmode === integration_mode::LEGACY) {
            $this->repository->mark_disconnected(
                (int) $meeting->id,
                self::ERROR_RECONNECT_REQUIRED,
                get_string('syncreconnectrequired', 'mod_googlemeet')
            );
            $this->record_sync_outcome(
                $meeting,
                diagnostic_recorder::OUTCOME_BLOCKED,
                self::ERROR_RECONNECT_REQUIRED
            );
            mtrace(get_string('synclegacydisconnected', 'mod_googlemeet', $meeting->id));
            return;
        }

        if ($meeting->integrationmode === integration_mode::MANAGED) {
            $this->process_managed($meeting);
            return;
        }

        $this->repository->mark_failed(
            (int) $meeting->id,
            self::ERROR_INVALID_MODE,
            get_string('syncinvalidintegrationmode', 'mod_googlemeet')
        );
        $this->record_sync_outcome($meeting, diagnostic_recorder::OUTCOME_FAILED, self::ERROR_INVALID_MODE);
    }

    /**
     * Reconciles a managed meeting when an adapter is available.
     *
     * @param \stdClass $meeting Activity record in the syncing state.
     */
    private function process_managed(\stdClass $meeting): void {
        global $DB;

        if ($this->calendaradapter === null && $this->calendaradapterprovider === null) {
            $this->repository->mark_failed(
                (int) $meeting->id,
                self::ERROR_ADAPTER_UNAVAILABLE,
                get_string('syncadapterunavailable', 'mod_googlemeet')
            );
            $this->record_sync_outcome(
                $meeting,
                diagnostic_recorder::OUTCOME_FAILED,
                self::ERROR_ADAPTER_UNAVAILABLE
            );
            mtrace(get_string('syncmanageddeferred', 'mod_googlemeet', $meeting->id));
            return;
        }

        $calendarismutated = empty($meeting->googleeventid)
            || ($meeting->conferencestatus ?? null) !== calendar_event_result::PENDING;
        try {
            $guests = $this->guestresolver->resolve($meeting);
            $previousguesthashes = $this->guestrepository->previous_hashes((int) $meeting->id);
            $adapter = $this->calendaradapter
                ?? $this->calendaradapterprovider->create($meeting);
            $result = $adapter->synchronise($meeting, $guests, $previousguesthashes);
        } catch (calendar_authorization_exception $e) {
            $this->repository->mark_disconnected(
                (int) $meeting->id,
                self::ERROR_AUTHORIZATION_REQUIRED,
                get_string('syncoauthrequired', 'mod_googlemeet')
            );
            $this->record_sync_outcome(
                $meeting,
                diagnostic_recorder::OUTCOME_BLOCKED,
                self::ERROR_AUTHORIZATION_REQUIRED
            );
            mtrace(get_string('syncmanageddisconnected', 'mod_googlemeet', $meeting->id));
            return;
        } catch (calendar_api_exception $e) {
            $this->repository->mark_failed(
                (int) $meeting->id,
                $e->error_code(),
                get_string('synccalendarapifailed', 'mod_googlemeet')
            );
            $this->record_sync_outcome($meeting, diagnostic_recorder::OUTCOME_FAILED, $e->error_code());
            mtrace(get_string('syncmanagedapifailed', 'mod_googlemeet', $meeting->id));
            return;
        } catch (calendar_guest_limit_exception $e) {
            $this->repository->mark_failed(
                (int) $meeting->id,
                self::ERROR_GUEST_LIMIT,
                get_string('syncguestlimitexceeded', 'mod_googlemeet', calendar_guest_resolver::MAX_ATTENDEES)
            );
            $this->record_sync_outcome(
                $meeting,
                diagnostic_recorder::OUTCOME_FAILED,
                self::ERROR_GUEST_LIMIT
            );
            mtrace(get_string('syncmanagedguestlimitfailed', 'mod_googlemeet', $meeting->id));
            return;
        } catch (calendar_configuration_exception $e) {
            $this->repository->mark_failed(
                (int) $meeting->id,
                self::ERROR_CALENDAR_CONFIGURATION,
                get_string('synccalendarconfigurationinvalid', 'mod_googlemeet')
            );
            $this->record_sync_outcome(
                $meeting,
                diagnostic_recorder::OUTCOME_FAILED,
                self::ERROR_CALENDAR_CONFIGURATION
            );
            mtrace(get_string('syncmanagedconfigurationfailed', 'mod_googlemeet', $meeting->id));
            return;
        } catch (calendar_response_exception $e) {
            $this->repository->mark_failed(
                (int) $meeting->id,
                self::ERROR_CALENDAR_RESPONSE,
                get_string('synccalendarresponseinvalid', 'mod_googlemeet')
            );
            $this->record_sync_outcome(
                $meeting,
                diagnostic_recorder::OUTCOME_FAILED,
                self::ERROR_CALENDAR_RESPONSE
            );
            mtrace(get_string('syncmanagedresponsefailed', 'mod_googlemeet', $meeting->id));
            return;
        } catch (calendar_transport_exception $e) {
            $this->record_sync_outcome(
                $meeting,
                diagnostic_recorder::OUTCOME_RETRYING,
                'calendar_transport_failure'
            );
            throw $e;
        }

        $transaction = $DB->start_delegated_transaction();
        $this->repository->apply_calendar_result((int) $meeting->id, $result);
        if ($calendarismutated) {
            $this->guestrepository->record_synchronised((int) $meeting->id, $guests);
        }
        $transaction->allow_commit();
        $outcome = match ($result->status()) {
            calendar_event_result::PENDING => diagnostic_recorder::OUTCOME_PENDING,
            calendar_event_result::SUCCESS => diagnostic_recorder::OUTCOME_SUCCEEDED,
            calendar_event_result::FAILURE => diagnostic_recorder::OUTCOME_FAILED,
        };
        $code = $result->status() === calendar_event_result::FAILURE
            ? 'conference_creation_failed'
            : null;
        $this->record_sync_outcome($meeting, $outcome, $code);
        $messagekey = match ($result->status()) {
            calendar_event_result::PENDING => 'syncmanagedpending',
            calendar_event_result::SUCCESS => 'syncmanagedready',
            calendar_event_result::FAILURE => 'syncmanagedfailed',
        };
        mtrace(get_string($messagekey, 'mod_googlemeet', $meeting->id));
    }

    /**
     * Records one meeting synchronization outcome.
     *
     * @param \stdClass $meeting Activity.
     * @param string $outcome Closed outcome.
     * @param string|null $code Stable code.
     */
    private function record_sync_outcome(\stdClass $meeting, string $outcome, ?string $code = null): void {
        $this->diagnostics->record(
            (int) $meeting->id,
            diagnostic_recorder::OPERATION_MEETING_SYNC,
            $outcome,
            diagnostic_recorder::SOURCE_ADHOC,
            $code
        );
    }

    /**
     * Records one Calendar cancellation outcome.
     *
     * @param \stdClass $meeting Activity.
     * @param string $outcome Closed outcome.
     * @param string|null $code Stable code.
     */
    private function record_cancellation_outcome(
        \stdClass $meeting,
        string $outcome,
        ?string $code = null
    ): void {
        $this->diagnostics->record(
            (int) $meeting->id,
            diagnostic_recorder::OPERATION_MEETING_CANCEL,
            $outcome,
            diagnostic_recorder::SOURCE_ADHOC,
            $code
        );
    }
}
