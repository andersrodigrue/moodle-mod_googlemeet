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
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Deterministic transport for Google Meet recording client tests.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class google_meet_recording_http_client implements recording_http_client {

    /** @var string[] Captured URLs. */
    public array $urls = [];

    /** @var array<int, array{status: int, body: string}> Queued responses. */
    public array $responses = [];

    /**
     * Returns the next response.
     *
     * @param string $url Absolute request URL.
     * @return array{status: int, body: string}
     */
    public function get(string $url): array {
        $this->urls[] = $url;
        return array_shift($this->responses) ?? ['status' => 200, 'body' => '{}'];
    }
}

/**
 * Tests for the defensive Google Meet v2 client.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(google_meet_recording_client::class)]
final class google_meet_recording_client_test extends \basic_testcase {

    /**
     * Conference discovery uses an exact code filter and bounded page size.
     */
    public function test_lists_conferences_with_exact_meeting_code_filter(): void {
        $http = new google_meet_recording_http_client();
        $client = new google_meet_recording_client($http);

        $this->assertSame([], $client->list_conference_records('abc-defg-hij'));
        $this->assertSame(
            'https://meet.googleapis.com/v2/conferenceRecords?'
                . 'filter=space.meeting_code%20%3D%20%22abc-defg-hij%22&pageSize=100',
            $http->urls[0]
        );
    }

    /**
     * Recording discovery uses the validated parent and opaque page token.
     */
    public function test_lists_recordings_with_encoded_page_token(): void {
        $http = new google_meet_recording_http_client();
        $client = new google_meet_recording_client($http);

        $client->list_recordings('conferenceRecords/conference_12345', 'next/token+one');

        $this->assertSame(
            'https://meet.googleapis.com/v2/conferenceRecords/conference_12345/recordings?'
                . 'pageSize=100&pageToken=next%2Ftoken%2Bone',
            $http->urls[0]
        );
    }

    /**
     * Invalid credentials and insufficient scope require explicit authorization.
     *
     * @param int $status HTTP status.
     */
    #[DataProvider('authorization_status_provider')]
    public function test_authorization_failures_are_classified(int $status): void {
        $http = $this->response($status, '{"error":"sensitive"}');

        $this->expectException(recording_authorization_exception::class);
        (new google_meet_recording_client($http))->list_conference_records('abc-defg-hij');
    }

    /**
     * Rate limits and server failures remain retryable.
     *
     * @param int $status HTTP status.
     */
    #[DataProvider('transient_status_provider')]
    public function test_transient_failures_are_classified(int $status): void {
        $http = $this->response($status, '{"error":"sensitive"}');

        $this->expectException(recording_transport_exception::class);
        (new google_meet_recording_client($http))->list_conference_records('abc-defg-hij');
    }

    /**
     * Permanent HTTP failures expose only a stable local code.
     */
    public function test_permanent_failure_exposes_safe_code(): void {
        $http = $this->response(400, '{"error":"sensitive remote detail"}');

        try {
            (new google_meet_recording_client($http))->list_conference_records('abc-defg-hij');
            $this->fail('A permanent Google Meet rejection was accepted.');
        } catch (recording_api_exception $e) {
            $this->assertSame('recording_api_http_400', $e->error_code());
            $this->assertStringNotContainsString('sensitive', $e->getMessage());
        }
    }

    /**
     * Successful HTTP responses still require a JSON object.
     */
    public function test_malformed_success_is_rejected(): void {
        $http = $this->response(200, 'not-json');

        $this->expectException(recording_response_exception::class);
        (new google_meet_recording_client($http))->list_conference_records('abc-defg-hij');
    }

    /**
     * Invalid continuation tokens are rejected before transport.
     */
    public function test_invalid_page_token_is_rejected(): void {
        $http = new google_meet_recording_http_client();

        $this->expectException(recording_response_exception::class);
        (new google_meet_recording_client($http))->list_conference_records(
            'abc-defg-hij',
            "unsafe\nvalue"
        );
    }

    /**
     * @return array<string, array{int}>
     */
    public static function authorization_status_provider(): array {
        return [
            'unauthorized' => [401],
            'forbidden' => [403],
        ];
    }

    /**
     * @return array<string, array{int}>
     */
    public static function transient_status_provider(): array {
        return [
            'rate limit' => [429],
            'server error' => [500],
            'gateway error' => [503],
        ];
    }

    /**
     * Creates a transport with one response.
     *
     * @param int $status HTTP status.
     * @param string $body Response body.
     * @return google_meet_recording_http_client
     */
    private function response(int $status, string $body): google_meet_recording_http_client {
        $http = new google_meet_recording_http_client();
        $http->responses[] = ['status' => $status, 'body' => $body];
        return $http;
    }
}
