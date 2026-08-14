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

use mod_googlemeet\external\delete_recordings;
use mod_googlemeet\external\rename_recording;
use mod_googlemeet\external\set_recording_visibility;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for activity-scoped recording external functions.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(rename_recording::class)]
#[CoversClass(set_recording_visibility::class)]
#[CoversClass(delete_recordings::class)]
final class external_test extends \advanced_testcase {

    /**
     * Rename and visibility actions accept their own authorised activity.
     */
    public function test_recording_actions_accept_their_own_activity(): void {
        $fixture = $this->create_security_fixture();

        $renamed = rename_recording::execute(
            $fixture['recordinga']->id,
            'Renamed recording',
            $fixture['meetinga']->cmid
        );
        $this->assertSame($fixture['recordinga']->id, $renamed['recordingid']);
        $this->assertSame('Renamed recording', $renamed['name']);

        $visibility = set_recording_visibility::execute(
            $fixture['recordinga']->id,
            false,
            $fixture['meetinga']->cmid
        );
        $this->assertSame($fixture['recordinga']->id, $visibility['recordingid']);
        $this->assertFalse($visibility['visible']);
    }

    /**
     * Repeating the same mutations does not change their modification time.
     */
    public function test_recording_mutations_are_idempotent(): void {
        global $DB;

        $fixture = $this->create_security_fixture();
        $DB->set_field(
            'googlemeet_recordings',
            'timemodified',
            10,
            ['id' => $fixture['recordinga']->id]
        );

        $firstrename = rename_recording::execute(
            $fixture['recordinga']->id,
            'Idempotent name',
            $fixture['meetinga']->cmid
        );
        $secondrename = rename_recording::execute(
            $fixture['recordinga']->id,
            'Idempotent name',
            $fixture['meetinga']->cmid
        );
        $this->assertGreaterThan(10, $firstrename['timemodified']);
        $this->assertSame($firstrename, $secondrename);

        $firstvisibility = set_recording_visibility::execute(
            $fixture['recordinga']->id,
            false,
            $fixture['meetinga']->cmid
        );
        $secondvisibility = set_recording_visibility::execute(
            $fixture['recordinga']->id,
            false,
            $fixture['meetinga']->cmid
        );
        $this->assertSame($firstvisibility, $secondvisibility);
    }

    /**
     * Missing and cross-activity recordings expose one generic public error.
     */
    public function test_recording_actions_do_not_expose_an_identifier_oracle(): void {
        global $DB;

        $fixture = $this->create_security_fixture();
        $messages = [];
        foreach ([$fixture['recordingb']->id, 99999999] as $recordingid) {
            try {
                rename_recording::execute(
                    $recordingid,
                    'Unauthorised name',
                    $fixture['meetinga']->cmid
                );
                $this->fail('The activity boundary did not reject the recording.');
            } catch (\invalid_parameter_exception $exception) {
                $messages[] = $exception->getMessage();
            }
        }

        $this->assertCount(2, $messages);
        $this->assertSame($messages[0], $messages[1]);
        $this->assertSame(
            'Recording B',
            $DB->get_field(
                'googlemeet_recordings',
                'name',
                ['id' => $fixture['recordingb']->id],
                MUST_EXIST
            )
        );
    }

    /**
     * Explicit visibility cannot be changed through another activity.
     */
    public function test_visibility_rejects_another_activity(): void {
        global $DB;

        $fixture = $this->create_security_fixture();

        $this->expectException(\invalid_parameter_exception::class);
        try {
            set_recording_visibility::execute(
                $fixture['recordingb']->id,
                false,
                $fixture['meetinga']->cmid
            );
        } finally {
            $this->assertEquals(
                1,
                $DB->get_field(
                    'googlemeet_recordings',
                    'visible',
                    ['id' => $fixture['recordingb']->id],
                    MUST_EXIST
                )
            );
        }
    }

    /**
     * Bulk deletion derives its target from the authorised course module only.
     */
    public function test_delete_recordings_is_scoped_and_idempotent(): void {
        global $DB;

        $fixture = $this->create_security_fixture();
        $this->create_recording($fixture['meetinga']->id, 'recording-a-2', 'Recording A2');

        $first = delete_recordings::execute($fixture['meetinga']->cmid);
        $this->assertSame((int) $fixture['meetinga']->id, $first['googlemeetid']);
        $this->assertSame(2, $first['deletedcount']);
        $this->assertFalse($DB->record_exists(
            'googlemeet_recordings',
            ['googlemeetid' => $fixture['meetinga']->id]
        ));
        $this->assertTrue($DB->record_exists(
            'googlemeet_recordings',
            ['id' => $fixture['recordingb']->id]
        ));

        $second = delete_recordings::execute($fixture['meetinga']->cmid);
        $this->assertSame(0, $second['deletedcount']);
        $this->assertSame($first['timemodified'], $second['timemodified']);
    }

    /**
     * Empty and overlong recording names are rejected.
     */
    public function test_rename_rejects_invalid_names(): void {
        $fixture = $this->create_security_fixture();

        foreach (['   ', str_repeat('a', 256)] as $name) {
            try {
                rename_recording::execute(
                    $fixture['recordinga']->id,
                    $name,
                    $fixture['meetinga']->cmid
                );
                $this->fail('An invalid recording name was accepted.');
            } catch (\invalid_parameter_exception $exception) {
                $this->assertStringContainsString(
                    get_string('invalidrecordingname', 'mod_googlemeet'),
                    $exception->getMessage()
                );
            }
        }
    }

    /**
     * A participant cannot invoke recording mutations directly.
     */
    public function test_recording_actions_require_the_write_capability(): void {
        $fixture = $this->create_security_fixture();
        $student = $this->getDataGenerator()->create_and_enrol(
            $fixture['coursea'],
            'student'
        );
        $this->setUser($student);

        $this->expectException(\required_capability_exception::class);
        rename_recording::execute(
            $fixture['recordinga']->id,
            'Forbidden name',
            $fixture['meetinga']->cmid
        );
    }

    /**
     * Creates two activities in different courses and an authorised teacher.
     *
     * @return array<string, \stdClass> Test records.
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
        $teacherrole = $DB->get_record(
            'role',
            ['shortname' => 'editingteacher'],
            '*',
            MUST_EXIST
        );
        $this->getDataGenerator()->enrol_user(
            $teacher->id,
            $coursea->id,
            $teacherrole->id
        );
        $this->setUser($teacher);

        return [
            'coursea' => $coursea,
            'courseb' => $courseb,
            'meetinga' => $meetinga,
            'meetingb' => $meetingb,
            'recordinga' => $recordinga,
            'recordingb' => $recordingb,
            'teacher' => $teacher,
        ];
    }

    /**
     * Creates one local recording reference.
     *
     * @param int $googlemeetid Activity instance ID.
     * @param string $recordingid External recording ID.
     * @param string $name Recording name.
     * @return \stdClass The recording record.
     */
    private function create_recording(
        int $googlemeetid,
        string $recordingid,
        string $name
    ): \stdClass {
        global $DB;

        $recording = (object) [
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
}
