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

/**
 * Concrete Google Calendar Events API client.
 *
 * This class owns URL construction, JSON decoding and safe error
 * classification. OAuth token storage and refresh remain with Moodle core.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class google_calendar_client implements calendar_client {

    /** Calendar Events API base URL. */
    private const API_BASE = 'https://www.googleapis.com/calendar/v3/calendars/';

    /** Error reasons that must be retried with backoff by the task runner. */
    private const RETRYABLE_REASONS = [
        'backendError',
        'internalError',
        'rateLimitExceeded',
        'userRateLimitExceeded',
    ];

    /** Error reasons that require fresh user authorization. */
    private const AUTHORIZATION_REASONS = [
        'authError',
        'insufficientPermissions',
    ];

    /** Guest notification policies accepted by Calendar event mutations. */
    private const SEND_UPDATES = [
        'all',
        'externalOnly',
        'none',
    ];

    /** @var calendar_http_client Authenticated HTTP boundary. */
    private calendar_http_client $httpclient;

    /**
     * @param calendar_http_client $httpclient Authenticated HTTP boundary.
     */
    public function __construct(calendar_http_client $httpclient) {
        $this->httpclient = $httpclient;
    }

    /**
     * Inserts one Calendar event.
     *
     * @param string $calendarid Google Calendar identifier.
     * @param array<string, mixed> $event Event resource.
     * @param array<string, mixed> $parameters Request parameters.
     * @return array<string, mixed>
     */
    public function insert_event(string $calendarid, array $event, array $parameters): array {
        return $this->send(
            'POST',
            $this->event_collection_url($calendarid, $parameters),
            $event,
            true
        );
    }

    /**
     * Patches one Calendar event.
     *
     * @param string $calendarid Google Calendar identifier.
     * @param string $eventid Google Calendar event identifier.
     * @param array<string, mixed> $event Event patch.
     * @param array<string, mixed> $parameters Request parameters.
     * @return array<string, mixed>
     */
    public function patch_event(
        string $calendarid,
        string $eventid,
        array $event,
        array $parameters
    ): array {
        return $this->send(
            'PATCH',
            $this->event_url($calendarid, $eventid, $parameters),
            $event
        );
    }

    /**
     * Gets one Calendar event.
     *
     * @param string $calendarid Google Calendar identifier.
     * @param string $eventid Google Calendar event identifier.
     * @return array<string, mixed>
     */
    public function get_event(string $calendarid, string $eventid): array {
        return $this->send('GET', $this->event_url($calendarid, $eventid), null);
    }

    /**
     * Deletes one Calendar event idempotently.
     *
     * @param string $calendarid Google Calendar identifier.
     * @param string $eventid Google Calendar event identifier.
     * @param array<string, mixed> $parameters Request parameters.
     */
    public function delete_event(string $calendarid, string $eventid, array $parameters): void {
        $this->send(
            'DELETE',
            $this->event_url($calendarid, $eventid, $parameters),
            null,
            false,
            true
        );
    }

    /**
     * Builds an event collection URL.
     *
     * @param string $calendarid Google Calendar identifier.
     * @param array<string, mixed> $parameters Query parameters.
     * @return string
     */
    private function event_collection_url(string $calendarid, array $parameters = []): string {
        $calendarid = trim($calendarid);
        if ($calendarid === '') {
            throw new calendar_configuration_exception('A Google Calendar identifier is required.');
        }

        return self::API_BASE . rawurlencode($calendarid) . '/events' . $this->query_string($parameters);
    }

    /**
     * Builds a single event URL.
     *
     * @param string $calendarid Google Calendar identifier.
     * @param string $eventid Google Calendar event identifier.
     * @param array<string, mixed> $parameters Query parameters.
     * @return string
     */
    private function event_url(string $calendarid, string $eventid, array $parameters = []): string {
        $eventid = trim($eventid);
        if ($eventid === '') {
            throw new calendar_configuration_exception('A Google Calendar event identifier is required.');
        }

        return $this->event_collection_url($calendarid) . '/' . rawurlencode($eventid)
            . $this->query_string($parameters);
    }

    /**
     * Validates and encodes Calendar mutation parameters.
     *
     * @param array<string, mixed> $parameters Query parameters.
     * @return string
     */
    private function query_string(array $parameters): string {
        if ($parameters === []) {
            return '';
        }

        $allowed = ['conferenceDataVersion', 'sendUpdates'];
        foreach ($parameters as $name => $value) {
            if (!in_array($name, $allowed, true) || !is_scalar($value)) {
                throw new calendar_configuration_exception('An unsupported Calendar query parameter was provided.');
            }
            if ($name === 'conferenceDataVersion' && (int) $value !== 1) {
                throw new calendar_configuration_exception('The Calendar conference data version is invalid.');
            }
            if ($name === 'sendUpdates' && !in_array((string) $value, self::SEND_UPDATES, true)) {
                throw new calendar_configuration_exception('The Calendar sendUpdates policy is invalid.');
            }
        }

        return '?' . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Sends and classifies one Calendar API request.
     *
     * @param string $method HTTP method.
     * @param string $url API URL.
     * @param array<string, mixed>|null $body Optional JSON body.
     * @param bool $insert Whether a duplicate means the controlled event already exists.
     * @param bool $delete Whether an absent resource is an idempotent success.
     * @return array<string, mixed>
     */
    private function send(
        string $method,
        string $url,
        ?array $body,
        bool $insert = false,
        bool $delete = false
    ): array {
        $response = $this->httpclient->request($method, $url, $body);
        $status = (int) ($response['status'] ?? 0);
        $rawbody = (string) ($response['body'] ?? '');
        $decoded = json_decode($rawbody, true);

        if ($status >= 200 && $status < 300) {
            if ($delete && ($rawbody === '' || $status === 204)) {
                return [];
            }
            if (!is_array($decoded)) {
                throw new calendar_response_exception('Google Calendar returned malformed JSON.');
            }
            return $decoded;
        }

        $reason = $this->error_reason($decoded);
        if ($delete && in_array($status, [404, 410], true)) {
            return [];
        }
        if ($insert && $status === 409) {
            throw new calendar_event_exists_exception('The controlled Calendar event already exists.');
        }
        if ($status === 401 || in_array($reason, self::AUTHORIZATION_REASONS, true)) {
            throw new calendar_authorization_exception('Google Calendar authorization is invalid.');
        }
        if (
            $status === 429 ||
            $status === 412 ||
            $status >= 500 ||
            in_array($reason, self::RETRYABLE_REASONS, true)
        ) {
            throw new calendar_transport_exception('Google Calendar temporarily rejected the request.');
        }

        throw new calendar_api_exception($this->permanent_error_code($status, $reason));
    }

    /**
     * Extracts the machine-readable Calendar error reason.
     *
     * @param mixed $decoded Decoded response.
     * @return string
     */
    private function error_reason(mixed $decoded): string {
        if (!is_array($decoded) || !isset($decoded['error']) || !is_array($decoded['error'])) {
            return '';
        }

        $errors = $decoded['error']['errors'] ?? [];
        if (is_array($errors) && isset($errors[0]) && is_array($errors[0])) {
            return (string) ($errors[0]['reason'] ?? '');
        }

        return (string) ($decoded['error']['status'] ?? '');
    }

    /**
     * Maps a permanent remote rejection to a stable local code.
     *
     * @param int $status HTTP status.
     * @param string $reason Google error reason.
     * @return string
     */
    private function permanent_error_code(int $status, string $reason): string {
        if ($reason === 'forbiddenForNonOrganizer') {
            return 'calendar_not_organizer';
        }
        if ($reason === 'quotaExceeded') {
            return 'calendar_quota_exceeded';
        }

        return match ($status) {
            400 => 'calendar_invalid_request',
            403 => 'calendar_permission_denied',
            404 => 'calendar_resource_not_found',
            410 => 'calendar_resource_gone',
            default => 'calendar_request_rejected',
        };
    }
}
