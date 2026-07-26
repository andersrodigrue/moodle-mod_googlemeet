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

use mod_googlemeet\local\meeting_manager;

/**
 * Ad hoc boundary for synchronizing one Google Meet activity.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class synchronise_meeting extends \core\task\adhoc_task {

    /** Number of attempts retained for a transient task failure. */
    private const ATTEMPTS_AVAILABLE = 5;

    /**
     * Returns the administrator-facing task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('synchronisetask', 'mod_googlemeet');
    }

    /**
     * Creates a task with the minimal stable custom data.
     *
     * @param int $googlemeetid Activity instance ID.
     * @param int|null $userid User the task should run as.
     * @return self
     */
    public static function create(int $googlemeetid, ?int $userid = null): self {
        if ($googlemeetid <= 0) {
            throw new \coding_exception('A positive Google Meet activity ID is required.');
        }
        if ($userid !== null && $userid <= 0) {
            throw new \coding_exception('A positive Google Meet task user ID is required.');
        }

        $task = new self();
        $task->set_custom_data((object) ['googlemeetid' => $googlemeetid]);
        $task->set_attempts_available(self::ATTEMPTS_AVAILABLE);

        if ($userid !== null) {
            $task->set_userid($userid);
        }

        return $task;
    }

    /**
     * Queues the task and suppresses an identical pending task.
     *
     * @param int $googlemeetid Activity instance ID.
     * @param int|null $userid User the task should run as.
     * @return bool True when a new task was queued.
     */
    public static function enqueue(int $googlemeetid, ?int $userid = null): bool {
        return (bool) \core\task\manager::queue_adhoc_task(self::create($googlemeetid, $userid), true);
    }

    /**
     * Processes the activity through the lock-protected meeting manager.
     */
    public function execute(): void {
        $data = $this->get_custom_data();
        if (!is_object($data) || empty($data->googlemeetid) || (int) $data->googlemeetid <= 0) {
            throw new \coding_exception('The Google Meet synchronization task has invalid custom data.');
        }

        (new meeting_manager())->process((int) $data->googlemeetid);
    }
}
