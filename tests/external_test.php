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

namespace mod_googlemeet;

use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests for the legacy external functions.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversMethod(\mod_googlemeet_external::class, 'recording_edit_name')]
#[CoversMethod(\mod_googlemeet_external::class, 'showhide_recording')]
#[CoversMethod(\mod_googlemeet_external::class, 'sync_recordings')]
#[CoversMethod(\mod_googlemeet_external::class, 'delete_all_recordings')]
#[RunTestsInSeparateProcesses]
final class external_test extends \advanced_testcase {

    /**
     * Loads the legacy external function class.
     */
    protected function setUp(): void {
        global $CFG;

        parent::setUp();
        require_once($CFG->dirroot . '/mod/googlemeet/classes/external.php');
    }

    /**
     * Editing a recording through its own activity remains supported.
     */
    public function test_recording_actions_accept_their_own_activity(): void {
        $fixture = $this->create_security_fixture();

        $renamed = \mod_googlemeet_external::recording_edit_name(
            $fixture['recordinga']->id,
            'Renamed recording',
            $fixture['meetinga']->cmid
        );
        $this->assertSame('Renamed recording', $renamed->name);

        $visibility = \mod_googlemeet_external::showhide_recording(
            $fixture['recordinga']->id,
            $fixture['meetinga']->cmid
        );
        $this->assertFalse((bool)$visibility->visible);
    }

    /**
     * A recording cannot be renamed through a different activity context.
     */
    public function test_recording_edit_name_rejects_another_activity(): void {
        global $DB;

        $fixture = $this->create_security_fixture();

        $this->assert_cross_activity_call_rejected(
            function() use ($fixture) {
                \mod_googlemeet_external::recording_edit_name(
                    $fixture['recordingb']->id,
                    'Unauthorised name',
                    $fixture['meetinga']->cmid
                );
            },
            \dml_missing_record_exception::class
        );

        $recording = $DB->get_record('googlemeet_recordings', ['id' => $fixture['recordingb']->id], '*', MUST_EXIST);
        $this->assertSame('Recording B', $recording->name);
    }

    /**
     * A recording cannot be shown or hidden through a different activity context.
     */
    public function test_showhide_recording_rejects_another_activity(): void {
        global $DB;

        $fixture = $this->create_security_fixture();

        $this->assert_cross_activity_call_rejected(
            function() use ($fixture) {
                \mod_googlemeet_external::showhide_recording(
                    $fixture['recordingb']->id,
                    $fixture['meetinga']->cmid
                );
            },
            \dml_missing_record_exception::class
        );

        $visible = $DB->get_field('googlemeet_recordings', 'visible', ['id' => $fixture['recordingb']->id], MUST_EXIST);
        $this->assertEquals(1, $visible);
    }

    /**
     * Recording synchronization cannot target another activity.
     */
    public function test_sync_recordings_rejects_another_activity(): void {
        global $DB;

        $fixture = $this->create_security_fixture();

        $this->assert_cross_activity_call_rejected(
            function() use ($fixture) {
                \mod_googlemeet_external::sync_recordings(
                    $fixture['meetingb']->id,
                    'teacher@example.com',
                    [],
                    $fixture['meetinga']->cmid
                );
            },
            \invalid_parameter_exception::class
        );

        $this->assertTrue($DB->record_exists('googlemeet_recordings', ['id' => $fixture['recordingb']->id]));
    }

    /**
     * Bulk deletion cannot target another activity.
     */
    public function test_delete_all_recordings_rejects_another_activity(): void {
        global $DB;

        $fixture = $this->create_security_fixture();

        $this->assert_cross_activity_call_rejected(
            function() use ($fixture) {
                \mod_googlemeet_external::delete_all_recordings(
                    $fixture['meetingb']->id,
                    $fixture['meetinga']->cmid
                );
            },
            \invalid_parameter_exception::class
        );

        $this->assertTrue($DB->record_exists('googlemeet_recordings', ['id' => $fixture['recordingb']->id]));
    }

    /**
     * Creates two activities in different courses and a teacher who can edit only the first.
     *
     * @return array Test records.
     */
    private function create_security_fixture(): array {
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();

        $coursea = $this->getDataGenerator()->create_course();
        $courseb = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_googlemeet');

        $meetinga = $generator->create_instance([
            'course' => $coursea->id,
            'name' => 'Meeting A',
        ]);
        $meetingb = $generator->create_instance([
            'course' => $courseb->id,
            'name' => 'Meeting B',
        ]);

        $recordinga = $this->create_recording($meetinga->id, 'recording-a', 'Recording A');
        $recordingb = $this->create_recording($meetingb->id, 'recording-b', 'Recording B');

        $teacher = $this->getDataGenerator()->create_user();
        $teacherrole = $DB->get_record('role', ['shortname' => 'editingteacher'], '*', MUST_EXIST);
        $this->getDataGenerator()->enrol_user($teacher->id, $coursea->id, $teacherrole->id);
        $this->setUser($teacher);

        return [
            'meetinga' => $meetinga,
            'meetingb' => $meetingb,
            'recordinga' => $recordinga,
            'recordingb' => $recordingb,
        ];
    }

    /**
     * Creates a recording record.
     *
     * @param int $googlemeetid Activity instance ID.
     * @param string $recordingid External recording ID.
     * @param string $name Recording name.
     * @return stdClass The recording record.
     */
    private function create_recording(int $googlemeetid, string $recordingid, string $name): \stdClass {
        global $DB;

        $recording = (object)[
            'googlemeetid' => $googlemeetid,
            'recordingid' => $recordingid,
            'name' => $name,
            'createdtime' => time(),
            'duration' => '10:00',
            'webviewlink' => 'https://drive.google.com/file/d/' . $recordingid . '/view',
            'visible' => 1,
            'timemodified' => time(),
        ];
        $recording->id = $DB->insert_record('googlemeet_recordings', $recording);

        return $recording;
    }

    /**
     * Asserts that a cross-activity call is rejected with the expected exception.
     *
     * @param callable $callback The external call.
     * @param string $expectedexception Expected exception class.
     */
    private function assert_cross_activity_call_rejected(callable $callback, string $expectedexception): void {
        try {
            $callback();
            $this->fail('The cross-activity call was not rejected.');
        } catch (\Throwable $exception) {
            $this->assertInstanceOf($expectedexception, $exception);
        }
    }
}
