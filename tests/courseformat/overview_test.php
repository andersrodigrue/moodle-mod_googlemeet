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

namespace mod_googlemeet\courseformat;

use core\output\action_link;
use core_calendar\output\humandate;
use mod_googlemeet\local\integration_mode;
use mod_googlemeet\local\meeting_access_policy;
use mod_googlemeet\local\sync_state;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the Moodle 5.2 course overview integration.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(overview::class)]
final class overview_test extends \advanced_testcase {

    /**
     * The overview exposes a human date and the managed lifecycle state.
     */
    public function test_extra_items_expose_time_and_state(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $meeting = $this->getDataGenerator()->get_plugin_generator('mod_googlemeet')->create_instance([
            'course' => $course->id,
            'integrationmode' => integration_mode::MANAGED,
            'syncstatus' => sync_state::PENDING,
            'timestart' => 1785060000,
        ]);
        $cm = get_fast_modinfo($course)->get_cm($meeting->cmid);

        $items = (new overview($cm, $DB))->get_extra_overview_items();

        $this->assertSame(1785060000, $items['meetingtime']->get_value());
        $this->assertInstanceOf(humandate::class, $items['meetingtime']->get_content());
        $this->assertSame(sync_state::PENDING, $items['syncstatus']->get_value());
        $this->assertSame(
            get_string('syncstatuspending', 'mod_googlemeet'),
            $items['syncstatus']->get_content()
        );
    }

    /**
     * Ready meetings expose only the local revalidating join action.
     */
    public function test_ready_meeting_exposes_join_action(): void {
        global $DB, $OUTPUT;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);
        $start = 1785405600;
        $meeting = $this->getDataGenerator()->get_plugin_generator('mod_googlemeet')->create_instance([
            'course' => $course->id,
            'integrationmode' => integration_mode::MANAGED,
            'syncstatus' => sync_state::READY,
            'meetinguri' => 'https://meet.google.com/abc-defg-hij',
            'timestart' => $start,
            'timeend' => $start + HOURSECS,
        ]);
        $cm = get_fast_modinfo($course)->get_cm($meeting->cmid);

        $action = (new overview($cm, $DB, $this->policy_at($start)))->get_actions_overview();

        $this->assertSame(get_string('overviewjoinmeeting', 'mod_googlemeet'), $action->get_value());
        $this->assertInstanceOf(action_link::class, $action->get_content());
        $html = $OUTPUT->render($action->get_content());
        $this->assertStringContainsString('/mod/googlemeet/join.php', $html);
        $this->assertStringNotContainsString('meet.google.com', $html);
    }

    /**
     * Participants outside the access window receive only the local activity view.
     */
    public function test_future_meeting_does_not_expose_join_action_to_participant(): void {
        global $DB, $OUTPUT;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);
        $start = 1785405600;
        $meeting = $this->getDataGenerator()->get_plugin_generator('mod_googlemeet')->create_instance([
            'course' => $course->id,
            'syncstatus' => sync_state::READY,
            'meetinguri' => 'https://meet.google.com/abc-defg-hij',
            'timestart' => $start,
            'timeend' => $start + HOURSECS,
        ]);
        $cm = get_fast_modinfo($course)->get_cm($meeting->cmid);

        $action = (new overview(
            $cm,
            $DB,
            $this->policy_at($start - HOURSECS)
        ))->get_actions_overview();

        $this->assertSame(get_string('view'), $action->get_value());
        $html = $OUTPUT->render($action->get_content());
        $this->assertStringContainsString('/mod/googlemeet/view.php', $html);
        $this->assertStringNotContainsString('/mod/googlemeet/join.php', $html);
        $this->assertStringNotContainsString('meet.google.com', $html);
    }

    /**
     * Explicit meeting managers may use the gateway outside the participant window.
     */
    public function test_manager_exposes_gateway_outside_participant_window(): void {
        global $DB, $OUTPUT;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);
        $start = 1785405600;
        $meeting = $this->getDataGenerator()->get_plugin_generator('mod_googlemeet')->create_instance([
            'course' => $course->id,
            'syncstatus' => sync_state::READY,
            'meetinguri' => 'https://meet.google.com/abc-defg-hij',
            'timestart' => $start,
            'timeend' => $start + HOURSECS,
        ]);
        $cm = get_fast_modinfo($course)->get_cm($meeting->cmid);

        $action = (new overview(
            $cm,
            $DB,
            $this->policy_at($start - DAYSECS)
        ))->get_actions_overview();

        $this->assertSame(get_string('overviewjoinmeeting', 'mod_googlemeet'), $action->get_value());
        $html = $OUTPUT->render($action->get_content());
        $this->assertStringContainsString('/mod/googlemeet/join.php', $html);
        $this->assertStringNotContainsString('meet.google.com', $html);
    }

    /**
     * Unready or invalid meetings link to the local status page instead.
     */
    public function test_unready_meeting_exposes_local_view_action(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);
        $meeting = $this->getDataGenerator()->get_plugin_generator('mod_googlemeet')->create_instance([
            'course' => $course->id,
            'integrationmode' => integration_mode::MANAGED,
            'syncstatus' => sync_state::DISCONNECTED,
            'meetinguri' => 'https://example.com/not-meet',
        ]);
        $cm = get_fast_modinfo($course)->get_cm($meeting->cmid);

        $action = (new overview($cm, $DB))->get_actions_overview();

        $this->assertSame(get_string('view'), $action->get_value());
        $this->assertInstanceOf(action_link::class, $action->get_content());
    }

    /**
     * Builds a deterministic server-side policy.
     *
     * @param int $now Server timestamp.
     * @return meeting_access_policy
     */
    private function policy_at(int $now): meeting_access_policy {
        $clock = $this->createMock(\core\clock::class);
        $clock->method('time')->willReturn($now);

        return new meeting_access_policy($clock);
    }
}
