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

namespace mod_googlemeet\output;

use mod_googlemeet\local\meeting_access_policy;
use mod_googlemeet\local\sync_state;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the Moodle app meeting presentation.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(mobile::class)]
final class mobile_test extends \advanced_testcase {

    /**
     * The app receives neither a join action nor the provider URI before opening.
     */
    public function test_participant_before_window_receives_no_provider_uri(): void {
        $this->resetAfterTest();
        [$course, $meeting, $student] = $this->fixture('student');
        $this->setUser($student);

        $result = mobile::mobile_course_view(
            $this->args($course, $meeting),
            $this->policy_at(1785405600 - HOURSECS)
        );
        $html = $result['templates'][0]['html'];

        $this->assertStringNotContainsString('meet.google.com', $html);
        $this->assertStringNotContainsString('/mod/googlemeet/join.php', $html);
        $this->assertStringNotContainsString('googlemeet-mobile-join', $html);
        $this->assertStringContainsString(
            get_string(
                'meetingaccessscheduled',
                'mod_googlemeet',
                userdate(1785405600 - (15 * MINSECS))
            ),
            $html
        );
    }

    /**
     * An open app action contains only the local revalidating gateway.
     */
    public function test_participant_inside_window_receives_local_gateway(): void {
        $this->resetAfterTest();
        [$course, $meeting, $student] = $this->fixture('student');
        $this->setUser($student);

        $result = mobile::mobile_course_view(
            $this->args($course, $meeting),
            $this->policy_at(1785405600)
        );
        $html = $result['templates'][0]['html'];

        $this->assertStringContainsString('googlemeet-mobile-join', $html);
        $this->assertStringContainsString('/mod/googlemeet/join.php', $html);
        $this->assertStringNotContainsString('meet.google.com', $html);
        $this->assertStringNotContainsString('onclick=', $html);
    }

    /**
     * A teacher override still traverses the local revalidating gateway.
     */
    public function test_manager_outside_window_receives_local_gateway(): void {
        $this->resetAfterTest();
        [$course, $meeting, $teacher] = $this->fixture('editingteacher');
        $this->setUser($teacher);

        $result = mobile::mobile_course_view(
            $this->args($course, $meeting),
            $this->policy_at(1785405600 - DAYSECS)
        );
        $html = $result['templates'][0]['html'];

        $this->assertStringContainsString('/mod/googlemeet/join.php', $html);
        $this->assertStringNotContainsString('meet.google.com', $html);
    }

    /**
     * Mobile recording links are revalidated and rendered without inline handlers.
     */
    public function test_recording_links_fail_closed_in_mobile_output(): void {
        global $DB;

        $this->resetAfterTest();
        [$course, $meeting, $student] = $this->fixture('student');
        $this->setUser($student);
        foreach ([
            [
                'recordingid' => 'DriveFile_12345',
                'webviewlink' => 'https://drive.google.com/file/d/DriveFile_12345/view',
            ],
            [
                'recordingid' => 'UnsafeFile_12345',
                'webviewlink' => 'javascript:alert(1)',
            ],
        ] as $index => $recording) {
            $DB->insert_record('googlemeet_recordings', (object) [
                'googlemeetid' => $meeting->id,
                'recordingid' => $recording['recordingid'],
                'name' => 'Recording ' . $index,
                'createdtime' => 1785405600 + $index,
                'duration' => '10:00',
                'webviewlink' => $recording['webviewlink'],
                'visible' => 1,
                'timemodified' => 1785405600 + $index,
            ]);
        }

        $result = mobile::mobile_course_view(
            $this->args($course, $meeting),
            $this->policy_at(1785405600)
        );
        $html = $result['templates'][0]['html'];

        $this->assertStringContainsString(
            'https://drive.google.com/file/d/DriveFile_12345/view',
            $html
        );
        $this->assertStringContainsString(
            get_string('recordingplaybackunavailable', 'mod_googlemeet'),
            $html
        );
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('onclick=', $html);
        $this->assertStringContainsString('rel="noopener"', $html);
    }

    /**
     * Creates a course, user and ready meeting.
     *
     * @param string $role User role shortname.
     * @return array{\stdClass, \stdClass, \stdClass}
     */
    private function fixture(string $role): array {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, $role);
        $meeting = $this->getDataGenerator()->get_plugin_generator('mod_googlemeet')->create_instance([
            'course' => $course->id,
            'syncstatus' => sync_state::READY,
            'url' => 'https://meet.google.com/abc-defg-hij',
            'meetinguri' => 'https://meet.google.com/abc-defg-hij',
            'timestart' => 1785405600,
            'timeend' => 1785405600 + HOURSECS,
        ]);

        return [$course, $meeting, $user];
    }

    /**
     * Builds Moodle app callback arguments.
     *
     * @param \stdClass $course Course record.
     * @param \stdClass $meeting Generated activity.
     * @return array<string, int>
     */
    private function args(\stdClass $course, \stdClass $meeting): array {
        return [
            'appversioncode' => 5000,
            'cmid' => (int) $meeting->cmid,
            'courseid' => (int) $course->id,
        ];
    }

    /**
     * Builds a deterministic server-side access policy.
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
