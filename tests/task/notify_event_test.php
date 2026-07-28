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

use mod_googlemeet\local\schedule_manager;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/googlemeet/locallib.php');

/**
 * Tests for capability-aware idempotent meeting reminders.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(notify_event::class)]
final class notify_event_test extends \advanced_testcase {

    /**
     * The task sends once to eligible students and stores one stable receipt.
     */
    public function test_sends_once_to_users_with_notification_capability(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $start = time() + 5 * MINSECS;
        $instance = $this->getDataGenerator()->get_plugin_generator('mod_googlemeet')->create_instance([
            'course' => $course->id,
            'name' => 'Reminder workshop',
            'timestart' => $start,
            'timeend' => $start + HOURSECS,
            'timezone' => 'UTC',
            'notify' => 1,
            'minutesbefore' => 10,
        ]);
        $meeting = $DB->get_record('googlemeet', ['id' => $instance->id], '*', MUST_EXIST);
        (new schedule_manager())->synchronise($meeting);
        set_config(
            'emailcontent',
            'Hello %userfirstname%, %googlemeetname% starts at %duration%. %url%',
            'googlemeet'
        );

        $sink = $this->redirectMessages();
        (new notify_event())->execute();
        (new notify_event())->execute();
        $messages = $sink->get_messages();
        $sink->close();

        $this->assertCount(1, $messages);
        $this->assertSame((int) $student->id, (int) $messages[0]->useridto);
        $this->assertSame(1, $DB->count_records('googlemeet_notify_done'));
    }
}
