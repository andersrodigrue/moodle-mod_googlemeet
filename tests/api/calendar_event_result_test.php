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
 * Tests for validated Calendar event results.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(calendar_event_result::class)]
final class calendar_event_result_test extends \advanced_testcase {

    /**
     * Pending responses preserve identity without exposing an early join URI.
     */
    public function test_pending_response_is_mapped(): void {
        $result = calendar_event_result::from_response(
            $this->response(calendar_event_result::PENDING),
            'gmevent1',
            'request1'
        );

        $this->assertSame(calendar_event_result::PENDING, $result->status());
        $this->assertSame('gmevent1', $result->event_id());
        $this->assertSame('request1', $result->request_id());
        $this->assertNull($result->database_fields()['meetinguri']);
    }

    /**
     * Successful responses use the video entry point as the join URI.
     */
    public function test_success_response_maps_conference_fields(): void {
        $response = $this->response(calendar_event_result::SUCCESS);
        $response['conferenceData']['conferenceId'] = 'abc-defg-hij';
        $response['conferenceData']['entryPoints'] = [[
            'entryPointType' => 'video',
            'uri' => 'https://meet.google.com/abc-defg-hij',
            'meetingCode' => 'abc-defg-hij',
        ]];

        $fields = calendar_event_result::from_response(
            $response,
            'gmevent1',
            'request1'
        )->database_fields();

        $this->assertSame('success', $fields['conferencestatus']);
        $this->assertSame('abc-defg-hij', $fields['conferenceid']);
        $this->assertSame('abc-defg-hij', $fields['meetingcode']);
        $this->assertSame('https://meet.google.com/abc-defg-hij', $fields['meetinguri']);
        $this->assertSame('https://calendar.google.com/event?eid=one', $fields['googleeventhtmlurl']);
        $this->assertSame('"etag-one"', $fields['googleeventetag']);
    }

    /**
     * A failed create request is represented explicitly.
     */
    public function test_failure_response_is_mapped(): void {
        $result = calendar_event_result::from_response(
            $this->response(calendar_event_result::FAILURE),
            'gmevent1',
            'request1'
        );

        $this->assertSame(calendar_event_result::FAILURE, $result->status());
        $this->assertNull($result->database_fields()['meetinguri']);
    }

    /**
     * A response for another controlled event is rejected.
     */
    public function test_mismatched_event_id_is_rejected(): void {
        $this->expectException(calendar_response_exception::class);

        calendar_event_result::from_response(
            $this->response(calendar_event_result::PENDING),
            'another1',
            'request1'
        );
    }

    /**
     * A response for another conference request is rejected.
     */
    public function test_mismatched_request_id_is_rejected(): void {
        $this->expectException(calendar_response_exception::class);

        calendar_event_result::from_response(
            $this->response(calendar_event_result::PENDING),
            'gmevent1',
            'anotherrequest'
        );
    }

    /**
     * Success without a video entry point is incomplete.
     */
    public function test_success_without_video_entry_point_is_rejected(): void {
        $this->expectException(calendar_response_exception::class);

        calendar_event_result::from_response(
            $this->response(calendar_event_result::SUCCESS),
            'gmevent1',
            'request1'
        );
    }

    /**
     * Builds a minimal Calendar event response.
     *
     * @param string $status Conference create status.
     * @return array<string, mixed>
     */
    private function response(string $status): array {
        return [
            'id' => 'gmevent1',
            'htmlLink' => 'https://calendar.google.com/event?eid=one',
            'etag' => '"etag-one"',
            'conferenceData' => [
                'createRequest' => [
                    'requestId' => 'request1',
                    'status' => [
                        'statusCode' => $status,
                    ],
                ],
            ],
        ];
    }
}
