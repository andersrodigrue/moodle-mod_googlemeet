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
use mod_googlemeet\api\calendar_configuration_exception;
use mod_googlemeet\api\calendar_event_result;
use mod_googlemeet\api\calendar_response_exception;
use mod_googlemeet\task\synchronise_meeting;

/**
 * Queues and coordinates local meeting synchronization work.
 *
 * Manual meetings settle locally and legacy meetings remain disconnected. A
 * managed Calendar adapter can be injected without coupling this coordinator to
 * OAuth or a particular Google SDK.
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

    /** @var sync_repository Synchronization repository. */
    private sync_repository $repository;

    /** @var meeting_lock Per-activity lock coordinator. */
    private meeting_lock $lock;

    /** @var calendar_adapter|null Managed Calendar adapter. */
    private ?calendar_adapter $calendaradapter;

    /**
     * @param sync_repository|null $repository Synchronization repository.
     * @param meeting_lock|null $lock Per-activity lock coordinator.
     * @param calendar_adapter|null $calendaradapter Managed Calendar adapter.
     */
    public function __construct(
        ?sync_repository $repository = null,
        ?meeting_lock $lock = null,
        ?calendar_adapter $calendaradapter = null
    ) {
        $this->repository = $repository ?? new sync_repository();
        $this->lock = $lock ?? new meeting_lock();
        $this->calendaradapter = $calendaradapter;
    }

    /**
     * Moves an activity to the queue and suppresses an identical pending task.
     *
     * @param int $googlemeetid Activity instance ID.
     * @param int|null $userid User the task should run as, or null to use the stored owner.
     * @return bool True when a new task was queued, false when an identical task already exists.
     */
    public function queue(int $googlemeetid, ?int $userid = null): bool {
        return $this->lock->with_lock($googlemeetid, function () use ($googlemeetid, $userid): bool {
            $meeting = $this->repository->get($googlemeetid);
            $transitionrequired = !in_array(
                $meeting->syncstatus,
                [sync_state::QUEUED, sync_state::PENDING],
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
                [sync_state::QUEUED, sync_state::SYNCING, sync_state::PENDING],
                true
            )) {
                mtrace(get_string('syncstateskipped', 'mod_googlemeet', (object) [
                    'id' => $googlemeetid,
                    'state' => $meeting->syncstatus,
                ]));
                return;
            }

            $meeting = $this->repository->start_attempt($googlemeetid);
            $this->process_attempt($meeting);
        });
    }

    /**
     * Applies the safe local outcome for the stored integration mode.
     *
     * @param \stdClass $meeting Activity record in the syncing state.
     */
    private function process_attempt(\stdClass $meeting): void {
        if ($meeting->integrationmode === integration_mode::MANUAL) {
            $this->repository->transition((int) $meeting->id, sync_state::READY);
            mtrace(get_string('syncmanualready', 'mod_googlemeet', $meeting->id));
            return;
        }

        if ($meeting->integrationmode === integration_mode::LEGACY) {
            $this->repository->mark_disconnected(
                (int) $meeting->id,
                self::ERROR_RECONNECT_REQUIRED,
                get_string('syncreconnectrequired', 'mod_googlemeet')
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
    }

    /**
     * Reconciles a managed meeting when an adapter is available.
     *
     * @param \stdClass $meeting Activity record in the syncing state.
     */
    private function process_managed(\stdClass $meeting): void {
        if ($this->calendaradapter === null) {
            $this->repository->mark_failed(
                (int) $meeting->id,
                self::ERROR_ADAPTER_UNAVAILABLE,
                get_string('syncadapterunavailable', 'mod_googlemeet')
            );
            mtrace(get_string('syncmanageddeferred', 'mod_googlemeet', $meeting->id));
            return;
        }

        try {
            $result = $this->calendaradapter->synchronise($meeting);
        } catch (calendar_configuration_exception $e) {
            $this->repository->mark_failed(
                (int) $meeting->id,
                self::ERROR_CALENDAR_CONFIGURATION,
                get_string('synccalendarconfigurationinvalid', 'mod_googlemeet')
            );
            mtrace(get_string('syncmanagedconfigurationfailed', 'mod_googlemeet', $meeting->id));
            return;
        } catch (calendar_response_exception $e) {
            $this->repository->mark_failed(
                (int) $meeting->id,
                self::ERROR_CALENDAR_RESPONSE,
                get_string('synccalendarresponseinvalid', 'mod_googlemeet')
            );
            mtrace(get_string('syncmanagedresponsefailed', 'mod_googlemeet', $meeting->id));
            return;
        }

        $this->repository->apply_calendar_result((int) $meeting->id, $result);
        $messagekey = match ($result->status()) {
            calendar_event_result::PENDING => 'syncmanagedpending',
            calendar_event_result::SUCCESS => 'syncmanagedready',
            calendar_event_result::FAILURE => 'syncmanagedfailed',
        };
        mtrace(get_string($messagekey, 'mod_googlemeet', $meeting->id));
    }
}
