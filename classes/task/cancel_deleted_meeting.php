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

use mod_googlemeet\local\calendar_adapter_factory;
use mod_googlemeet\local\remote_cleanup_manager;

/**
 * Owner-scoped worker for a Calendar event whose Moodle activity was deleted.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class cancel_deleted_meeting extends \core\task\adhoc_task {

    /** Number of immediate attempts retained for a transient provider failure. */
    private const ATTEMPTS_AVAILABLE = 5;

    /**
     * Returns the task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('canceldeletedmeetingtask', 'mod_googlemeet');
    }

    /**
     * Creates one owner-scoped task with minimal custom data.
     *
     * @param int $cleanupid Cleanup ID.
     * @param int $owneruserid Original activity owner.
     * @return self
     */
    public static function create(int $cleanupid, int $owneruserid): self {
        if ($cleanupid <= 0 || $owneruserid <= 0) {
            throw new \coding_exception('Positive cleanup and owner IDs are required.');
        }
        $task = new self();
        $task->set_custom_data((object) ['cleanupid' => $cleanupid]);
        $task->set_userid($owneruserid);
        $task->set_attempts_available(self::ATTEMPTS_AVAILABLE);

        return $task;
    }

    /**
     * Queues work and suppresses an identical pending task.
     *
     * @param int $cleanupid Cleanup ID.
     * @param int $owneruserid Original activity owner.
     * @return bool True when a new task was queued.
     */
    public static function enqueue(int $cleanupid, int $owneruserid): bool {
        return (bool) \core\task\manager::queue_adhoc_task(
            self::create($cleanupid, $owneruserid),
            true
        );
    }

    /**
     * Performs the lock-protected remote cleanup.
     */
    public function execute(): void {
        $data = $this->get_custom_data();
        if (!is_object($data) || empty($data->cleanupid) || (int) $data->cleanupid <= 0) {
            throw new \coding_exception('The remote cleanup task has invalid custom data.');
        }
        $manager = new remote_cleanup_manager(
            adapterprovider: new calendar_adapter_factory()
        );
        $manager->process((int) $data->cleanupid);
    }
}
