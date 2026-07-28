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

use mod_googlemeet\local\recording_discovery_factory;
use mod_googlemeet\local\recording_manager;

/**
 * Ad hoc boundary for discovering one activity's recording artifacts.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class discover_recordings extends \core\task\adhoc_task {

    /** Number of attempts retained for a transient service failure. */
    private const ATTEMPTS_AVAILABLE = 5;

    /**
     * Returns the administrator-facing task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('recordingsdiscovertask', 'mod_googlemeet');
    }

    /**
     * Creates a task with minimal stable custom data.
     *
     * @param int $googlemeetid Activity instance ID.
     * @param int $userid Recording authorization owner.
     * @return self
     */
    public static function create(int $googlemeetid, int $userid): self {
        if ($googlemeetid <= 0 || $userid <= 0) {
            throw new \coding_exception('Recording discovery requires positive activity and user IDs.');
        }

        $task = new self();
        $task->set_custom_data((object) ['googlemeetid' => $googlemeetid]);
        $task->set_userid($userid);
        $task->set_attempts_available(self::ATTEMPTS_AVAILABLE);
        return $task;
    }

    /**
     * Queues the task while suppressing an identical pending task.
     *
     * @param int $googlemeetid Activity instance ID.
     * @param int $userid Recording authorization owner.
     * @return bool
     */
    public static function enqueue(int $googlemeetid, int $userid): bool {
        return (bool) \core\task\manager::queue_adhoc_task(self::create($googlemeetid, $userid), true);
    }

    /**
     * Processes the activity through the lock-protected manager.
     */
    public function execute(): void {
        $data = $this->get_custom_data();
        if (!is_object($data) || empty($data->googlemeetid) || (int) $data->googlemeetid <= 0) {
            throw new \coding_exception('The recording discovery task has invalid custom data.');
        }

        $manager = new recording_manager(provider: new recording_discovery_factory());
        $manager->process((int) $data->googlemeetid);
    }
}
