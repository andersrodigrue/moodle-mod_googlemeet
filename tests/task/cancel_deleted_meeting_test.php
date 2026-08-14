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

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the deleted-meeting Calendar cancellation task boundary.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(cancel_deleted_meeting::class)]
final class cancel_deleted_meeting_test extends \advanced_testcase {

    /**
     * The factory stores only the tombstone ID and execution owner.
     */
    public function test_factory_sets_minimal_task_data(): void {
        $task = cancel_deleted_meeting::create(42, 7);

        $this->assertSame(42, (int) $task->get_custom_data()->cleanupid);
        $this->assertSame(7, $task->get_userid());
        $this->assertSame(5, $task->get_attempts_available());
        $this->assertSame(get_string('canceldeletedmeetingtask', 'mod_googlemeet'), $task->get_name());
    }

    /**
     * Duplicate delivery after completion is an idempotent no-op.
     */
    public function test_execute_ignores_missing_cleanup(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();

        $this->expectOutputString(
            get_string('remotecleanupmissing', 'mod_googlemeet', 999999) . "\n"
        );
        cancel_deleted_meeting::create(999999, (int) $owner->id)->execute();
        $this->addToAssertionCount(1);
    }

    /**
     * Invalid task data never reaches the remote boundary.
     */
    public function test_execute_rejects_invalid_custom_data(): void {
        $task = new cancel_deleted_meeting();
        $task->set_custom_data((object) ['cleanupid' => 0]);

        $this->expectException(\coding_exception::class);
        $task->execute();
    }
}
