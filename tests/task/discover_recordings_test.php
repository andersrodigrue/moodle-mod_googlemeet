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

use mod_googlemeet\local\recording_repository;
use mod_googlemeet\local\recording_sync_state;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the recording discovery ad hoc boundary.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(discover_recordings::class)]
final class discover_recordings_test extends \advanced_testcase {

    /**
     * The factory stores only the activity ID and execution owner.
     */
    public function test_factory_sets_minimal_task_data(): void {
        $task = discover_recordings::create(42, 7);

        $this->assertSame(42, (int) $task->get_custom_data()->googlemeetid);
        $this->assertSame(7, $task->get_userid());
        $this->assertSame(5, $task->get_attempts_available());
        $this->assertSame(get_string('recordingsdiscovertask', 'mod_googlemeet'), $task->get_name());
    }

    /**
     * Production composition disconnects safely when its issuer disappeared.
     */
    public function test_execute_requires_persisted_owner_authorization(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $this->setUser($owner);
        set_config('issuerid', 11, 'googlemeet');
        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_googlemeet');
        $meeting = $generator->create_instance([
            'course' => $course->id,
            'recordingowneruserid' => $owner->id,
            'recordingoauthissuerid' => 999999,
            'recordingsyncstatus' => recording_sync_state::QUEUED,
        ]);

        discover_recordings::create((int) $meeting->id, (int) $owner->id)->execute();
        $updated = (new recording_repository())->get((int) $meeting->id);

        $this->assertSame(recording_sync_state::DISCONNECTED, $updated->recordingsyncstatus);
        $this->assertSame('recording_authorization_required', $updated->recordinglasterrorcode);
    }

    /**
     * A task for a deleted activity completes without retrying forever.
     */
    public function test_execute_ignores_missing_meeting(): void {
        $this->resetAfterTest();

        $this->expectOutputString(
            get_string('recordingsactivitymissing', 'mod_googlemeet', 999999) . "\n"
        );
        discover_recordings::create(999999, 7)->execute();
        $this->addToAssertionCount(1);
    }

    /**
     * Invalid custom data is rejected.
     */
    public function test_execute_rejects_invalid_custom_data(): void {
        $task = new discover_recordings();
        $task->set_custom_data((object) ['googlemeetid' => 0]);

        $this->expectException(\coding_exception::class);
        $task->execute();
    }
}
