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

use mod_googlemeet\task\synchronise_meeting;

/**
 * Queues and coordinates local meeting synchronization work.
 *
 * This structural slice deliberately performs no Google API calls. Manual
 * meetings settle locally, legacy meetings remain disconnected, and managed
 * meetings fail safely until the Calendar adapter is introduced.
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

    /** @var sync_repository Synchronization repository. */
    private sync_repository $repository;

    /** @var meeting_lock Per-activity lock coordinator. */
    private meeting_lock $lock;

    /**
     * @param sync_repository|null $repository Synchronization repository.
     * @param meeting_lock|null $lock Per-activity lock coordinator.
     */
    public function __construct(
        ?sync_repository $repository = null,
        ?meeting_lock $lock = null
    ) {
        $this->repository = $repository ?? new sync_repository();
        $this->lock = $lock ?? new meeting_lock();
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

            if (!in_array($meeting->syncstatus, [sync_state::QUEUED, sync_state::PENDING], true)) {
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
            $this->repository->mark_failed(
                (int) $meeting->id,
                self::ERROR_ADAPTER_UNAVAILABLE,
                get_string('syncadapterunavailable', 'mod_googlemeet')
            );
            mtrace(get_string('syncmanageddeferred', 'mod_googlemeet', $meeting->id));
            return;
        }

        $this->repository->mark_failed(
            (int) $meeting->id,
            self::ERROR_INVALID_MODE,
            get_string('syncinvalidintegrationmode', 'mod_googlemeet')
        );
    }
}
