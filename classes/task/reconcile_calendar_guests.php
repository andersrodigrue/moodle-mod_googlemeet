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

use mod_googlemeet\api\calendar_guest_limit_exception;
use mod_googlemeet\local\calendar_guest_repository;
use mod_googlemeet\local\calendar_guest_resolver;
use mod_googlemeet\local\meeting_manager;

/**
 * Detects bounded course-membership changes for opted-in Calendar meetings.
 *
 * The cron task never calls Google. It resolves at most 25 activities and
 * delegates changed meetings to owner-scoped ad hoc tasks.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class reconcile_calendar_guests extends \core\task\scheduled_task {

    /** Recheck one activity at most every six hours. */
    private const CHECK_INTERVAL = 6 * HOURSECS;

    /** Maximum activities inspected by one cron run. */
    private const BATCH_LIMIT = 25;

    /**
     * Returns the administrator-facing task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('reconcilegueststask', 'mod_googlemeet');
    }

    /**
     * Queues owner-scoped reconciliation only when participant data changed.
     */
    public function execute(): void {
        $repository = new calendar_guest_repository();
        $resolver = new calendar_guest_resolver();
        $manager = new meeting_manager();
        $now = \core\di::get(\core\clock::class)->time();
        $candidates = $repository->reconciliation_candidates(
            $now - self::CHECK_INTERVAL,
            self::BATCH_LIMIT
        );

        $queued = 0;
        $unchanged = 0;
        foreach ($candidates as $meeting) {
            try {
                $snapshot = $resolver->resolve($meeting);
                $changed = $snapshot->hash() !== ($meeting->guesthash ?? null);
            } catch (calendar_guest_limit_exception) {
                // Queue once so the owner sees a safe synchronization failure.
                $changed = true;
            }

            if ($changed) {
                if ($manager->queue((int) $meeting->id, (int) $meeting->owneruserid)) {
                    $queued++;
                }
            } else {
                $unchanged++;
            }
            $repository->touch_checked((int) $meeting->id);
        }

        mtrace(get_string('reconcileguestsresult', 'mod_googlemeet', (object) [
            'found' => count($candidates),
            'queued' => $queued,
            'unchanged' => $unchanged,
        ]));
    }
}
