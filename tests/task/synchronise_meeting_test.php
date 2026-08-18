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

use mod_googlemeet\local\integration_mode;
use mod_googlemeet\local\sync_repository;
use mod_googlemeet\local\sync_state;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the synchronization ad hoc task boundary.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(synchronise_meeting::class)]
final class synchronise_meeting_test extends \advanced_testcase {

    /**
     * The factory stores only the activity ID and execution owner.
     */
    public function test_factory_sets_minimal_task_data(): void {
        $task = synchronise_meeting::create(42, 7);

        $this->assertSame(42, (int) $task->get_custom_data()->googlemeetid);
        $this->assertSame(7, $task->get_userid());
        $this->assertSame(5, $task->get_attempts_available());
        $this->assertSame(get_string('synchronisetask', 'mod_googlemeet'), $task->get_name());
    }

    /**
     * Executing the task delegates to the lock-protected manager.
     */
    public function test_execute_processes_manual_meeting(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_googlemeet');
        $meeting = $generator->create_instance([
            'course' => $course->id,
            'integrationmode' => integration_mode::MANUAL,
            'syncstatus' => sync_state::QUEUED,
        ]);

        $this->expectOutputString(
            get_string('syncmanualready', 'mod_googlemeet', $meeting->id) . "\n"
        );
        synchronise_meeting::create($meeting->id)->execute();

        $this->assertSame(sync_state::READY, (new sync_repository())->get($meeting->id)->syncstatus);
    }

    /**
     * Production composition disconnects a managed meeting without valid OAuth.
     */
    public function test_execute_uses_production_calendar_composition(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $this->setUser($owner);
        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_googlemeet');
        $meeting = $generator->create_instance([
            'course' => $course->id,
            'integrationmode' => integration_mode::MANAGED,
            'owneruserid' => $owner->id,
            'oauthissuerid' => 999999,
            'calendarid' => 'primary',
            'syncstatus' => sync_state::QUEUED,
        ]);

        $this->expectOutputString(
            get_string('syncmanageddisconnected', 'mod_googlemeet', $meeting->id) . "\n"
        );
        synchronise_meeting::create((int) $meeting->id, (int) $owner->id)->execute();
        $updated = (new sync_repository())->get((int) $meeting->id);

        $this->assertSame(sync_state::DISCONNECTED, $updated->syncstatus);
        $this->assertSame('authorization_required', $updated->lasterrorcode);
    }

    /**
     * A task for a deleted activity completes without retrying forever.
     */
    public function test_execute_ignores_missing_meeting(): void {
        $this->resetAfterTest();

        $this->expectOutputString(
            get_string('syncactivitymissing', 'mod_googlemeet', 999999) . "\n"
        );
        synchronise_meeting::create(999999)->execute();
        $this->addToAssertionCount(1);
    }

    /**
     * Invalid task custom data is rejected.
     */
    public function test_execute_rejects_invalid_custom_data(): void {
        $task = new synchronise_meeting();
        $task->set_custom_data((object) ['googlemeetid' => 0]);

        $this->expectException(\coding_exception::class);
        $task->execute();
    }
}
