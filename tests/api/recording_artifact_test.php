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

namespace mod_googlemeet\api;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for validated recording artifacts.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(recording_artifact::class)]
final class recording_artifact_test extends \basic_testcase {

    /**
     * A generated artifact yields only bounded persistence fields.
     */
    public function test_parses_generated_recording(): void {
        $artifact = recording_artifact::from_response(
            $this->response(),
            'conferenceRecords/conference_12345',
            '<b>Weekly class</b>'
        );

        $this->assertSame('DriveFile_12345', $artifact->file_id());
        $this->assertSame([
            'recordingid' => 'DriveFile_12345',
            'name' => 'Weekly class',
            'createdtime' => 1767268800,
            'duration' => '1:02:03',
            'webviewlink' => 'https://drive.google.com/file/d/DriveFile_12345/view',
        ], $artifact->database_fields());
    }

    /**
     * The resource must belong to the conference being traversed.
     */
    public function test_rejects_recording_from_another_conference(): void {
        $this->expectException(recording_response_exception::class);
        recording_artifact::from_response(
            $this->response(),
            'conferenceRecords/another_conference',
            'Weekly class'
        );
    }

    /**
     * The playback URI must use Drive HTTPS and contain the same file ID.
     */
    public function test_rejects_unbound_playback_uri(): void {
        $response = $this->response();
        $response['driveDestination']['exportUri'] =
            'https://drive.google.com/file/d/AnotherFile_123/view';

        $this->expectException(recording_response_exception::class);
        recording_artifact::from_response(
            $response,
            'conferenceRecords/conference_12345',
            'Weekly class'
        );
    }

    /**
     * Inverted timestamps are rejected.
     */
    public function test_rejects_invalid_time_range(): void {
        $response = $this->response();
        $response['endTime'] = '2026-01-01T11:59:59Z';

        $this->expectException(recording_response_exception::class);
        recording_artifact::from_response(
            $response,
            'conferenceRecords/conference_12345',
            'Weekly class'
        );
    }

    /**
     * Builds one valid Meet recording resource.
     *
     * @return array<string, mixed>
     */
    private function response(): array {
        return [
            'name' => 'conferenceRecords/conference_12345/recordings/recording_1',
            'state' => recording_artifact::FILE_GENERATED,
            'startTime' => '2026-01-01T12:00:00Z',
            'endTime' => '2026-01-01T13:02:03Z',
            'driveDestination' => [
                'file' => 'DriveFile_12345',
                'exportUri' => 'https://drive.google.com/file/d/DriveFile_12345/view',
            ],
        ];
    }
}
