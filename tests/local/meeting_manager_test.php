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
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the local synchronization coordinator.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(meeting_manager::class)]
final class meeting_manager_test extends \advanced_testcase {

    /**
     * A manual meeting settles locally without a remote API.
     */
    public function test_manual_meeting_becomes_ready(): void {
        $this->resetAfterTest();

        $meeting = $this->create_meeting([
            'integrationmode' => integration_mode::MANUAL,
            'syncstatus' => sync_state::QUEUED,
        ]);

        $this->expectOutputString(
            get_string('syncmanualready', 'mod_googlemeet', $meeting->id) . "\n"
        );
        (new meeting_manager())->process($meeting->id);
        $updated = (new sync_repository())->get($meeting->id);

        $this->assertSame(sync_state::READY, $updated->syncstatus);
        $this->assertSame(1, (int) $updated->syncattempts);
        $this->assertGreaterThan(0, (int) $updated->timelastattempt);
    }

    /**
     * A legacy meeting remains disconnected until the owner reconnects.
     */
    public function test_legacy_meeting_requires_reconnection(): void {
        $this->resetAfterTest();

        $meeting = $this->create_meeting([
            'integrationmode' => integration_mode::LEGACY,
            'syncstatus' => sync_state::QUEUED,
        ]);

        $this->expectOutputString(
            get_string('synclegacydisconnected', 'mod_googlemeet', $meeting->id) . "\n"
        );
        (new meeting_manager())->process($meeting->id);
        $updated = (new sync_repository())->get($meeting->id);

        $this->assertSame(sync_state::DISCONNECTED, $updated->syncstatus);
        $this->assertSame('reconnect_required', $updated->lasterrorcode);
        $this->assertSame(1, (int) $updated->syncattempts);
    }

    /**
     * Managed synchronization fails safely while the Calendar adapter is absent.
     */
    public function test_managed_meeting_fails_without_calendar_adapter(): void {
        $this->resetAfterTest();

        $meeting = $this->create_meeting([
            'integrationmode' => integration_mode::MANAGED,
            'syncstatus' => sync_state::QUEUED,
        ]);

        $this->expectOutputString(
            get_string('syncmanageddeferred', 'mod_googlemeet', $meeting->id) . "\n"
        );
        (new meeting_manager())->process($meeting->id);
        $updated = (new sync_repository())->get($meeting->id);

        $this->assertSame(sync_state::FAILED, $updated->syncstatus);
        $this->assertSame('adapter_unavailable', $updated->lasterrorcode);
        $this->assertSame(1, (int) $updated->syncattempts);
    }

    /**
     * Duplicate delivery is a no-op after the meeting has settled.
     */
    public function test_settled_meeting_is_not_processed_again(): void {
        $this->resetAfterTest();

        $meeting = $this->create_meeting([
            'integrationmode' => integration_mode::MANUAL,
            'syncstatus' => sync_state::READY,
        ]);

        $this->expectOutputString(
            get_string('syncstateskipped', 'mod_googlemeet', (object) [
                'id' => $meeting->id,
                'state' => sync_state::READY,
            ]) . "\n"
        );
        (new meeting_manager())->process($meeting->id);
        $updated = (new sync_repository())->get($meeting->id);

        $this->assertSame(sync_state::READY, $updated->syncstatus);
        $this->assertSame(0, (int) $updated->syncattempts);
    }

    /**
     * Queueing changes state, runs as the owner, and suppresses a duplicate task.
     */
    public function test_queue_is_owner_scoped_and_deduplicated(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $meeting = $this->create_meeting([
            'integrationmode' => integration_mode::MANUAL,
            'owneruserid' => $owner->id,
            'syncstatus' => sync_state::DRAFT,
        ]);
        $manager = new meeting_manager();

        $this->assertTrue($manager->queue($meeting->id));
        $this->assertFalse($manager->queue($meeting->id));
        $this->assertSame(sync_state::QUEUED, (new sync_repository())->get($meeting->id)->syncstatus);

        $tasks = \core\task\manager::get_adhoc_tasks(synchronise_meeting::class);
        $this->assertCount(1, $tasks);
        $task = reset($tasks);
        $this->assertInstanceOf(synchronise_meeting::class, $task);
        $this->assertSame((int) $owner->id, $task->get_userid());
        $this->assertSame((int) $meeting->id, (int) $task->get_custom_data()->googlemeetid);
    }

    /**
     * A running duplicate cannot leave a settled activity queued without a new task.
     */
    public function test_duplicate_task_does_not_change_settled_state(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $meeting = $this->create_meeting([
            'integrationmode' => integration_mode::MANUAL,
            'owneruserid' => $owner->id,
            'syncstatus' => sync_state::READY,
        ]);

        $this->assertTrue(synchronise_meeting::enqueue($meeting->id, $owner->id));
        $this->assertFalse((new meeting_manager())->queue($meeting->id));
        $this->assertSame(sync_state::READY, (new sync_repository())->get($meeting->id)->syncstatus);
    }

    /**
     * Creates an activity without calling Google.
     *
     * @param array<string, mixed> $fields Activity fields.
     * @return \stdClass
     */
    private function create_meeting(array $fields): \stdClass {
        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_googlemeet');

        return $generator->create_instance(['course' => $course->id] + $fields);
    }
}
