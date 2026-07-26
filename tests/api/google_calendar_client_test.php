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
 * Deterministic HTTP fake for Calendar client tests.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class google_calendar_http_client implements calendar_http_client {

    /** @var array<int, array{method: string, url: string, body: array|null}> Captured requests. */
    public array $requests = [];

    /** @var array<int, array{status: int, body: string}> Queued responses. */
    public array $responses = [];

    /**
     * @param string $method HTTP method.
     * @param string $url Request URL.
     * @param array<string, mixed>|null $body JSON body.
     * @return array{status: int, body: string}
     */
    public function request(string $method, string $url, ?array $body = null): array {
        $this->requests[] = [
            'method' => $method,
            'url' => $url,
            'body' => $body,
        ];

        return array_shift($this->responses) ?? [
            'status' => 200,
            'body' => '{"id":"event1"}',
        ];
    }
}

/**
 * Tests for the concrete Google Calendar client.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(google_calendar_client::class)]
final class google_calendar_client_test extends \advanced_testcase {

    /**
     * Insert encodes path/query values and forwards the JSON resource.
     */
    public function test_insert_builds_safe_calendar_url(): void {
        $http = new google_calendar_http_client();
        $client = new google_calendar_client($http);
        $event = ['id' => 'event1', 'summary' => 'Test'];

        $result = $client->insert_event('teacher@example.com', $event, [
            'conferenceDataVersion' => 1,
            'sendUpdates' => 'none',
        ]);

        $this->assertSame('event1', $result['id']);
        $this->assertSame('POST', $http->requests[0]['method']);
        $this->assertSame(
            'https://www.googleapis.com/calendar/v3/calendars/teacher%40example.com/events'
                . '?conferenceDataVersion=1&sendUpdates=none',
            $http->requests[0]['url']
        );
        $this->assertSame($event, $http->requests[0]['body']);
    }

    /**
     * Patch and get use the encoded controlled event path.
     */
    public function test_patch_and_get_use_event_resource_url(): void {
        $http = new google_calendar_http_client();
        $client = new google_calendar_client($http);

        $client->patch_event('primary', 'event/one', ['summary' => 'Changed'], [
            'conferenceDataVersion' => 1,
            'sendUpdates' => 'all',
        ]);
        $client->get_event('primary', 'event/one');

        $this->assertSame('PATCH', $http->requests[0]['method']);
        $this->assertSame(
            'https://www.googleapis.com/calendar/v3/calendars/primary/events/event%2Fone'
                . '?conferenceDataVersion=1&sendUpdates=all',
            $http->requests[0]['url']
        );
        $this->assertSame('GET', $http->requests[1]['method']);
        $this->assertSame(
            'https://www.googleapis.com/calendar/v3/calendars/primary/events/event%2Fone',
            $http->requests[1]['url']
        );
        $this->assertNull($http->requests[1]['body']);
    }

    /**
     * Delete accepts an empty 204 response and sends guest notifications safely.
     */
    public function test_delete_accepts_empty_success(): void {
        $http = new google_calendar_http_client();
        $http->responses[] = ['status' => 204, 'body' => ''];

        (new google_calendar_client($http))->delete_event('primary', 'event1', [
            'sendUpdates' => 'all',
        ]);

        $this->assertSame('DELETE', $http->requests[0]['method']);
        $this->assertSame(
            'https://www.googleapis.com/calendar/v3/calendars/primary/events/event1?sendUpdates=all',
            $http->requests[0]['url']
        );
        $this->assertNull($http->requests[0]['body']);
    }

    /**
     * Missing and gone events make delete retries idempotent.
     */
    public function test_delete_accepts_absent_event(): void {
        foreach ([404, 410] as $status) {
            $http = $this->http_error($status, 'notFound');
            (new google_calendar_client($http))->delete_event('primary', 'event1', [
                'sendUpdates' => 'none',
            ]);
            $this->assertSame('DELETE', $http->requests[0]['method']);
        }
    }

    /**
     * A controlled ID conflict is converted to the adapter's idempotency signal.
     */
    public function test_insert_duplicate_throws_event_exists_exception(): void {
        $http = $this->http_error(409, 'duplicate');

        $this->expectException(calendar_event_exists_exception::class);
        (new google_calendar_client($http))->insert_event('primary', ['id' => 'event1'], []);
    }

    /**
     * Invalid credentials require an explicit reconnect.
     */
    public function test_invalid_credentials_throw_authorization_exception(): void {
        $http = $this->http_error(401, 'authError');

        $this->expectException(calendar_authorization_exception::class);
        (new google_calendar_client($http))->get_event('primary', 'event1');
    }

    /**
     * Missing Calendar scope also requires fresh authorization.
     */
    public function test_insufficient_permissions_throw_authorization_exception(): void {
        $http = $this->http_error(403, 'insufficientPermissions');

        $this->expectException(calendar_authorization_exception::class);
        (new google_calendar_client($http))->get_event('primary', 'event1');
    }

    /**
     * Rate limits and backend errors remain retryable by the ad hoc task.
     */
    public function test_rate_limit_throws_transport_exception(): void {
        $http = $this->http_error(403, 'rateLimitExceeded');

        $this->expectException(calendar_transport_exception::class);
        (new google_calendar_client($http))->get_event('primary', 'event1');
    }

    /**
     * A Calendar usage quota exhaustion is not retried indefinitely.
     */
    public function test_quota_exhaustion_is_permanent_safe_error(): void {
        $http = $this->http_error(403, 'quotaExceeded');

        try {
            (new google_calendar_client($http))->get_event('primary', 'event1');
            $this->fail('A Calendar quota exhaustion was accepted.');
        } catch (calendar_api_exception $e) {
            $this->assertSame('calendar_quota_exceeded', $e->error_code());
        }
    }

    /**
     * Organizer-only failures receive their own stable support code.
     */
    public function test_non_organizer_failure_has_stable_code(): void {
        $http = $this->http_error(403, 'forbiddenForNonOrganizer');

        try {
            (new google_calendar_client($http))->get_event('primary', 'event1');
            $this->fail('A non-organizer Calendar update was accepted.');
        } catch (calendar_api_exception $e) {
            $this->assertSame('calendar_not_organizer', $e->error_code());
        }
    }

    /**
     * A permanent request rejection exposes only a stable local code.
     */
    public function test_bad_request_throws_safe_api_exception(): void {
        $http = $this->http_error(400, 'timeRangeEmpty', 'Sensitive remote detail');

        try {
            (new google_calendar_client($http))->get_event('primary', 'event1');
            $this->fail('A permanent Calendar rejection was accepted.');
        } catch (calendar_api_exception $e) {
            $this->assertSame('calendar_invalid_request', $e->error_code());
            $this->assertStringNotContainsString('Sensitive remote detail', $e->getMessage());
        }
    }

    /**
     * Successful HTTP responses still require valid JSON resources.
     */
    public function test_malformed_success_throws_response_exception(): void {
        $http = new google_calendar_http_client();
        $http->responses[] = [
            'status' => 200,
            'body' => 'not-json',
        ];

        $this->expectException(calendar_response_exception::class);
        (new google_calendar_client($http))->get_event('primary', 'event1');
    }

    /**
     * Unsupported query fields are rejected before transport.
     */
    public function test_rejects_unsupported_query_parameter(): void {
        $http = new google_calendar_http_client();

        $this->expectException(calendar_configuration_exception::class);
        (new google_calendar_client($http))->insert_event('primary', ['id' => 'event1'], [
            'access_token' => 'must-not-be-accepted',
        ]);
    }

    /**
     * Creates a Calendar JSON error response.
     *
     * @param int $status HTTP status.
     * @param string $reason Machine-readable reason.
     * @param string $message Remote message.
     * @return google_calendar_http_client
     */
    private function http_error(
        int $status,
        string $reason,
        string $message = 'Remote error'
    ): google_calendar_http_client {
        $http = new google_calendar_http_client();
        $http->responses[] = [
            'status' => $status,
            'body' => json_encode([
                'error' => [
                    'code' => $status,
                    'message' => $message,
                    'errors' => [
                        ['reason' => $reason],
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        ];

        return $http;
    }
}
