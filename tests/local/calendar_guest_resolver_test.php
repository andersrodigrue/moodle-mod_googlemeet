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

use mod_googlemeet\api\calendar_guest_limit_exception;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for capability-based Calendar guest resolution.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(calendar_guest_resolver::class)]
#[CoversClass(calendar_guest_snapshot::class)]
final class calendar_guest_resolver_test extends \advanced_testcase {

    /**
     * Only active capable participants with valid email are included.
     */
    public function test_resolve_uses_active_capability_and_excludes_owner(): void {
        global $DB;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $owner = $generator->create_and_enrol($course, 'editingteacher');
        $active = $generator->create_and_enrol($course, 'student', [
            'email' => 'Active.Student@example.test',
        ]);
        $suspendeduser = $generator->create_and_enrol($course, 'student');
        $DB->set_field('user', 'suspended', 1, ['id' => $suspendeduser->id]);
        $suspendedenrolment = $generator->create_and_enrol($course, 'student');
        $DB->set_field('user_enrolments', 'status', ENROL_USER_SUSPENDED, [
            'userid' => $suspendedenrolment->id,
        ]);
        $generator->create_and_enrol($course, 'editingteacher');

        $meeting = $generator->get_plugin_generator('mod_googlemeet')->create_instance([
            'course' => $course->id,
            'integrationmode' => integration_mode::MANAGED,
            'owneruserid' => $owner->id,
            'guestpolicy' => calendar_guest_policy::COURSE,
        ]);
        $snapshot = (new calendar_guest_resolver())->resolve($meeting);

        $this->assertSame(1, $snapshot->count());
        $this->assertSame([
            ['email' => 'active.student@example.test'],
        ], $snapshot->event_attendees());
        $this->assertNotNull($snapshot->hash());
        $this->assertSame((int) $active->id, $snapshot->database_rows()[0]['userid']);
        $this->assertArrayNotHasKey('email', $snapshot->database_rows()[0]);
    }

    /**
     * Disabled policy never queries or exposes course participants.
     */
    public function test_none_policy_returns_disabled_snapshot(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $meeting = $this->getDataGenerator()->get_plugin_generator('mod_googlemeet')->create_instance([
            'course' => $course->id,
            'guestpolicy' => calendar_guest_policy::NONE,
        ]);

        $snapshot = (new calendar_guest_resolver())->resolve($meeting);

        $this->assertFalse($snapshot->is_managed());
        $this->assertNull($snapshot->hash());
        $this->assertSame(0, $snapshot->count());
    }

    /**
     * The resolver refuses a partial list above the hard attendee boundary.
     */
    public function test_resolve_rejects_more_than_two_hundred_eligible_users(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        for ($index = 0; $index <= calendar_guest_resolver::MAX_ATTENDEES; $index++) {
            $generator->create_and_enrol($course, 'student');
        }

        $this->expectException(calendar_guest_limit_exception::class);
        (new calendar_guest_resolver())->resolve_context(
            \context_course::instance((int) $course->id)
        );
    }
}
