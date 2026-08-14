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

namespace mod_googlemeet\task;

use mod_googlemeet\local\remote_cleanup_repository;

/**
 * Recovers due or abandoned remote Calendar cleanup obligations.
 *
 * This scheduled task never accesses OAuth. It only restores owner-scoped ad
 * hoc work, including after every immediate task retry was exhausted.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class reconcile_remote_cleanups extends \core\task\scheduled_task {

    /** Processing state considered abandoned after this delay. */
    private const STALE_DELAY = 30 * MINSECS;

    /**
     * Returns the task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('reconcileremotecleanupstask', 'mod_googlemeet');
    }

    /**
     * Queues bounded owner-scoped cleanup work.
     */
    public function execute(): void {
        $now = \core\di::get(\core\clock::class)->time();
        $candidates = (new remote_cleanup_repository())->get_due_candidates(
            $now,
            $now - self::STALE_DELAY
        );
        $queued = 0;
        foreach ($candidates as $cleanup) {
            if (cancel_deleted_meeting::enqueue((int) $cleanup->id, (int) $cleanup->owneruserid)) {
                $queued++;
            }
        }

        mtrace(get_string('reconcileremotecleanupsresult', 'mod_googlemeet', (object) [
            'found' => count($candidates),
            'queued' => $queued,
        ]));
    }
}
