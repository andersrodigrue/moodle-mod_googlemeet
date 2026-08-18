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

use mod_googlemeet\api\recording_artifact;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for recording authorization, state and artifact persistence.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(recording_repository::class)]
final class recording_repository_test extends \advanced_testcase {

    /**
     * An unowned integration can be claimed, but not taken over.
     */
    public function test_claim_is_owner_scoped(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $meeting = $this->create_meeting();
        $repository = new recording_repository();

        $claimed = $repository->claim($meeting->id, $owner->id, 17);
        $this->assertSame((int) $owner->id, (int) $claimed->recordingowneruserid);
        $this->assertSame(17, (int) $claimed->recordingoauthissuerid);

        try {
            $repository->claim($meeting->id, $other->id, 18);
            $this->fail('A second teacher took over the recording authorization.');
        } catch (\moodle_exception $e) {
            $this->assertSame('recordingowneronly', $e->errorcode);
        }

        $stored = $repository->get($meeting->id);
        $this->assertSame((int) $owner->id, (int) $stored->recordingowneruserid);
        $this->assertSame(17, (int) $stored->recordingoauthissuerid);
    }

    /**
     * Upsert refreshes remote metadata while preserving local editorial fields.
     */
    public function test_upsert_preserves_name_and_visibility(): void {
        global $DB;

        $this->resetAfterTest();
        $meeting = $this->create_meeting([
            'recordingsyncstatus' => recording_sync_state::SYNCING,
        ]);
        $existingid = $DB->insert_record('googlemeet_recordings', (object) [
            'googlemeetid' => $meeting->id,
            'recordingid' => 'DriveFile_12345',
            'name' => 'Teacher-edited name',
            'createdtime' => 1,
            'duration' => '0:01',
            'webviewlink' => 'https://drive.google.com/file/d/DriveFile_12345/view',
            'visible' => 0,
            'timemodified' => 1,
        ]);

        $updated = (new recording_repository())->apply_artifacts(
            $meeting->id,
            [$this->artifact('2026-01-01T13:00:00Z')]
        );
        $recording = $DB->get_record('googlemeet_recordings', ['id' => $existingid], '*', MUST_EXIST);

        $this->assertSame(recording_sync_state::READY, $updated->recordingsyncstatus);
        $this->assertSame('Teacher-edited name', $recording->name);
        $this->assertSame(0, (int) $recording->visible);
        $this->assertSame('1:00:00', $recording->duration);
        $this->assertGreaterThan(1, (int) $recording->createdtime);
        $this->assertSame(1, $DB->count_records('googlemeet_recordings', [
            'googlemeetid' => $meeting->id,
            'recordingid' => 'DriveFile_12345',
        ]));
    }

    /**
     * Remote absence never deletes a locally known recording.
     */
    public function test_empty_snapshot_does_not_delete_local_reference(): void {
        global $DB;

        $this->resetAfterTest();
        $meeting = $this->create_meeting([
            'recordingsyncstatus' => recording_sync_state::READY,
        ]);
        $DB->insert_record('googlemeet_recordings', (object) [
            'googlemeetid' => $meeting->id,
            'recordingid' => 'DriveFile_12345',
            'name' => 'Preserved recording',
            'createdtime' => 1,
            'duration' => '0:01',
            'webviewlink' => 'https://drive.google.com/file/d/DriveFile_12345/view',
            'visible' => 1,
            'timemodified' => 1,
        ]);

        (new recording_repository())->apply_artifacts($meeting->id, []);

        $this->assertTrue($DB->record_exists('googlemeet_recordings', [
            'googlemeetid' => $meeting->id,
            'recordingid' => 'DriveFile_12345',
        ]));
    }

    /**
     * Failure details are sanitized and bounded before persistence.
     */
    public function test_failure_details_are_sanitized(): void {
        $this->resetAfterTest();
        $meeting = $this->create_meeting([
            'recordingsyncstatus' => recording_sync_state::QUEUED,
        ]);
        $repository = new recording_repository();
        $repository->start_attempt($meeting->id);

        $updated = $repository->mark_failed(
            $meeting->id,
            'unsafe recording code!',
            '<b>Sensitive response</b>' . str_repeat('x', 2500)
        );

        $this->assertSame(recording_sync_state::FAILED, $updated->recordingsyncstatus);
        $this->assertSame('unsaferecordingcode', $updated->recordinglasterrorcode);
        $this->assertLessThanOrEqual(
            2000,
            \core_text::strlen($updated->recordinglasterrormessage)
        );
        $this->assertStringNotContainsString('<b>', $updated->recordinglasterrormessage);
    }

    /**
     * Creates an activity without contacting Google.
     *
     * @param array<string, mixed> $fields Activity fields.
     * @return \stdClass
     */
    private function create_meeting(array $fields = []): \stdClass {
        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_googlemeet');
        return $generator->create_instance(['course' => $course->id] + $fields);
    }

    /**
     * Creates one validated artifact.
     *
     * @param string $endtime Recording end time.
     * @return recording_artifact
     */
    private function artifact(string $endtime): recording_artifact {
        return recording_artifact::from_response([
            'name' => 'conferenceRecords/conference_12345/recordings/recording_1',
            'state' => recording_artifact::FILE_GENERATED,
            'startTime' => '2026-01-01T12:00:00Z',
            'endTime' => $endtime,
            'driveDestination' => [
                'file' => 'DriveFile_12345',
                'exportUri' => 'https://drive.google.com/file/d/DriveFile_12345/view',
            ],
        ], 'conferenceRecords/conference_12345', 'Weekly class');
    }
}
