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
 * Tests for Moodle's authenticated HTTP boundary.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(moodle_oauth_http_client::class)]
final class moodle_oauth_http_client_test extends \advanced_testcase {

    /**
     * An authorized client sends JSON and returns the raw HTTP response.
     */
    public function test_post_uses_authenticated_moodle_client(): void {
        $client = $this->createMock(\core\oauth2\client::class);
        $client->expects($this->once())->method('is_logged_in')->willReturn(true);
        $client->expects($this->exactly(2))->method('setHeader');
        $client->expects($this->once())
            ->method('post')
            ->with(
                'https://www.googleapis.com/calendar/v3/calendars/primary/events',
                '{"summary":"Test"}'
            )
            ->willReturn('{"id":"event1"}');
        $client->method('get_errno')->willReturn(0);
        $client->method('get_info')->willReturn(['http_code' => 200]);

        $response = (new moodle_oauth_http_client($client))->request(
            'POST',
            'https://www.googleapis.com/calendar/v3/calendars/primary/events',
            ['summary' => 'Test']
        );

        $this->assertSame(200, $response['status']);
        $this->assertSame('{"id":"event1"}', $response['body']);
    }

    /**
     * Moodle returning false after refresh invalidation requires reconnection.
     */
    public function test_rejects_missing_authorization(): void {
        $client = $this->createMock(\core\oauth2\client::class);
        $client->expects($this->once())->method('is_logged_in')->willReturn(false);
        $client->expects($this->never())->method('get');

        $this->expectException(calendar_authorization_exception::class);
        (new moodle_oauth_http_client($client))->request(
            'GET',
            'https://www.googleapis.com/calendar/v3/calendars/primary/events/event1'
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

        $this->expectException(calendar_transport_exception::class);
        (new moodle_oauth_http_client($client))->request(
            'GET',
            'https://www.googleapis.com/calendar/v3/calendars/primary/events/event1'
        );
    }

    /**
     * The OAuth bearer token can never be sent to a user-controlled host.
     */
    public function test_rejects_non_google_host_before_authorization(): void {
        $client = $this->createMock(\core\oauth2\client::class);
        $client->expects($this->never())->method('is_logged_in');

        $this->expectException(\coding_exception::class);
        (new moodle_oauth_http_client($client))->request(
            'GET',
            'https://example.com/calendar'
        );
    }
}
