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
use mod_googlemeet\api\recording_client;
use mod_googlemeet\api\recording_response_exception;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Deterministic Google Meet API fake for recording discovery.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class recording_discovery_client implements recording_client {

    /** @var array<int, array<string, mixed>> Queued conference responses. */
    public array $conferenceresponses = [];

    /** @var array<int, array<string, mixed>> Queued recording responses. */
    public array $recordingresponses = [];

    /** @var array<int, array{string, string|null}> Captured conference calls. */
    public array $conferencecalls = [];

    /** @var array<int, array{string, string|null}> Captured recording calls. */
    public array $recordingcalls = [];

    /**
     * @param string $meetingcode Exact normalized meeting code.
     * @param string|null $pagetoken Continuation token.
     * @return array<string, mixed>
     */
    public function list_conference_records(string $meetingcode, ?string $pagetoken = null): array {
        $this->conferencecalls[] = [$meetingcode, $pagetoken];
        return array_shift($this->conferenceresponses) ?? [];
    }

    /**
     * @param string $conferencerecord Conference record resource.
     * @param string|null $pagetoken Continuation token.
     * @return array<string, mixed>
     */
    public function list_recordings(string $conferencerecord, ?string $pagetoken = null): array {
        $this->recordingcalls[] = [$conferencerecord, $pagetoken];
        return array_shift($this->recordingresponses) ?? [];
    }
}

/**
 * Tests for exact, paginated recording discovery.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(recording_discovery::class)]
final class recording_discovery_test extends \basic_testcase {

    /**
     * Managed and manual activity data yield the same normalized Meet code.
     */
    public function test_extracts_code_from_stored_field_or_exact_uri(): void {
        $this->assertSame('abc-defg-hij', recording_discovery::meeting_code((object) [
            'meetingcode' => 'ABC-DEFG-HIJ',
        ]));
        $this->assertSame('xyz-abcd-efg', recording_discovery::meeting_code((object) [
            'meetinguri' => 'https://meet.google.com/XYZ-ABCD-EFG',
        ]));
        $this->assertSame('xyz-abcd-efg', recording_discovery::meeting_code((object) [
            'url' => 'https://meet.google.com/xyz-abcd-efg',
        ]));
    }

    /**
     * Pages are traversed, unfinished recordings ignored and file IDs deduplicated.
     */
    public function test_discovers_generated_artifacts_across_pages(): void {
        $client = new recording_discovery_client();
        $firstconference = 'conferenceRecords/conference_12345';
        $secondconference = 'conferenceRecords/conference_67890';
        $client->conferenceresponses = [
            [
                'conferenceRecords' => [['name' => $firstconference]],
                'nextPageToken' => 'conference-page-2',
            ],
            ['conferenceRecords' => [['name' => $secondconference]]],
        ];
        $client->recordingresponses = [
            [
                'recordings' => [
                    $this->recording($firstconference, 'started_recording', 'STARTED', 'StartedFile_1'),
                    $this->recording(
                        $firstconference,
                        'generated_recording',
                        recording_artifact::FILE_GENERATED,
                        'DriveFile_12345'
                    ),
                ],
                'nextPageToken' => 'recording-page-2',
            ],
            [
                'recordings' => [
                    $this->recording($firstconference, 'ended_recording', 'ENDED', 'EndedFile_123'),
                ],
            ],
            [
                'recordings' => [
                    $this->recording(
                        $secondconference,
                        'duplicate_recording',
                        recording_artifact::FILE_GENERATED,
                        'DriveFile_12345'
                    ),
                ],
            ],
        ];

        $artifacts = (new recording_discovery($client))->discover((object) [
            'name' => 'Weekly class',
            'meetingcode' => 'abc-defg-hij',
        ]);

        $this->assertCount(1, $artifacts);
        $this->assertSame('DriveFile_12345', $artifacts[0]->file_id());
        $this->assertSame([
            ['abc-defg-hij', null],
            ['abc-defg-hij', 'conference-page-2'],
        ], $client->conferencecalls);
        $this->assertSame([
            [$firstconference, null],
            [$firstconference, 'recording-page-2'],
            [$secondconference, null],
        ], $client->recordingcalls);
    }

    /**
     * A repeated token cannot make the worker loop indefinitely.
     */
    public function test_rejects_repeated_pagination_token(): void {
        $client = new recording_discovery_client();
        $client->conferenceresponses = [
            ['conferenceRecords' => [], 'nextPageToken' => 'same-token'],
            ['conferenceRecords' => [], 'nextPageToken' => 'same-token'],
        ];

        $this->expectException(recording_response_exception::class);
        (new recording_discovery($client))->discover((object) [
            'meetingcode' => 'abc-defg-hij',
        ]);
    }

    /**
     * Lists must be JSON-style arrays rather than associative objects.
     */
    public function test_rejects_invalid_conference_list(): void {
        $client = new recording_discovery_client();
        $client->conferenceresponses[] = [
            'conferenceRecords' => ['name' => 'conferenceRecords/conference_12345'],
        ];

        $this->expectException(recording_response_exception::class);
        (new recording_discovery($client))->discover((object) [
            'meetingcode' => 'abc-defg-hij',
        ]);
    }

    /**
     * Builds one recording resource.
     *
     * @param string $conference Parent conference.
     * @param string $recordingid Recording resource ID.
     * @param string $state Processing state.
     * @param string $fileid Drive file ID.
     * @return array<string, mixed>
     */
    private function recording(
        string $conference,
        string $recordingid,
        string $state,
        string $fileid
    ): array {
        return [
            'name' => $conference . '/recordings/' . $recordingid,
            'state' => $state,
            'startTime' => '2026-01-01T12:00:00Z',
            'endTime' => '2026-01-01T13:00:00Z',
            'driveDestination' => [
                'file' => $fileid,
                'exportUri' => 'https://drive.google.com/file/d/' . $fileid . '/view',
            ],
        ];
    }
}
