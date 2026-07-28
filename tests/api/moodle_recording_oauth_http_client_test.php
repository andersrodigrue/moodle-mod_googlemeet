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
 * Tests for the Google Meet OAuth transport boundary.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(moodle_recording_oauth_http_client::class)]
final class moodle_recording_oauth_http_client_test extends \advanced_testcase {

    /**
     * An authorized client performs GET and returns the raw HTTP response.
     */
    public function test_get_uses_authenticated_moodle_client(): void {
        $url = 'https://meet.googleapis.com/v2/conferenceRecords?pageSize=100';
        $client = $this->createMock(\core\oauth2\client::class);
        $client->expects($this->once())->method('is_logged_in')->willReturn(true);
        $client->expects($this->once())->method('setHeader')->with('Accept: application/json');
        $client->expects($this->once())->method('get')->with($url)->willReturn('{"conferenceRecords":[]}');
        $client->method('get_errno')->willReturn(0);
        $client->method('get_info')->willReturn(['http_code' => 200]);

        $response = (new moodle_recording_oauth_http_client($client))->get($url);

        $this->assertSame(200, $response['status']);
        $this->assertSame('{"conferenceRecords":[]}', $response['body']);
    }

    /**
     * Missing authorization is classified before a request is sent.
     */
    public function test_rejects_missing_authorization(): void {
        $client = $this->createMock(\core\oauth2\client::class);
        $client->expects($this->once())->method('is_logged_in')->willReturn(false);
        $client->expects($this->never())->method('get');

        $this->expectException(recording_authorization_exception::class);
        (new moodle_recording_oauth_http_client($client))->get(
            'https://meet.googleapis.com/v2/conferenceRecords?pageSize=100'
        );
    }

    /**
     * Curl failures remain transient so the ad hoc task can retry.
     */
    public function test_converts_curl_failure_to_transport_exception(): void {
        $client = $this->createMock(\core\oauth2\client::class);
        $client->method('is_logged_in')->willReturn(true);
        $client->method('get')->willReturn(false);
        $client->method('get_errno')->willReturn(28);

        $this->expectException(recording_transport_exception::class);
        (new moodle_recording_oauth_http_client($client))->get(
            'https://meet.googleapis.com/v2/conferenceRecords?pageSize=100'
        );
    }

    /**
     * Bearer tokens can never be sent to a user-controlled host.
     */
    public function test_rejects_non_meet_host_before_authorization(): void {
        $client = $this->createMock(\core\oauth2\client::class);
        $client->expects($this->never())->method('is_logged_in');

        $this->expectException(\coding_exception::class);
        (new moodle_recording_oauth_http_client($client))->get(
            'https://example.com/v2/conferenceRecords'
        );
    }

    /**
     * Cleartext requests to the correct host are also rejected.
     */
    public function test_rejects_cleartext_meet_host(): void {
        $client = $this->createMock(\core\oauth2\client::class);
        $client->expects($this->never())->method('is_logged_in');

        $this->expectException(\coding_exception::class);
        (new moodle_recording_oauth_http_client($client))->get(
            'http://meet.googleapis.com/v2/conferenceRecords'
        );
    }
}
