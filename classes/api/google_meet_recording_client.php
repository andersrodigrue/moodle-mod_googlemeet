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
 * Defensive Google Meet v2 recording client.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class google_meet_recording_client implements recording_client {

    /** Fixed Google Meet API root. */
    private const API_ROOT = 'https://meet.googleapis.com/v2';

    /** Maximum accepted continuation-token length. */
    private const TOKEN_MAX_LENGTH = 2048;

    /** @var recording_http_client Authenticated HTTP transport. */
    private recording_http_client $httpclient;

    /**
     * @param recording_http_client $httpclient Authenticated HTTP transport.
     */
    public function __construct(recording_http_client $httpclient) {
        $this->httpclient = $httpclient;
    }

    /**
     * Lists conference records for one exact Meet code.
     *
     * @param string $meetingcode Normalized Meet code.
     * @param string|null $pagetoken Continuation token.
     * @return array<string, mixed>
     */
    public function list_conference_records(string $meetingcode, ?string $pagetoken = null): array {
        if (!preg_match('/^[a-z]{3}-[a-z]{4}-[a-z]{3}$/', $meetingcode)) {
            throw new \coding_exception('A normalized Google Meet code is required for recording discovery.');
        }

        $parameters = [
            'filter' => 'space.meeting_code = "' . $meetingcode . '"',
            'pageSize' => 100,
        ];
        $this->add_page_token($parameters, $pagetoken);

        return $this->send(self::API_ROOT . '/conferenceRecords?' . http_build_query(
            $parameters,
            '',
            '&',
            PHP_QUERY_RFC3986
        ));
    }

    /**
     * Lists recording artifacts for one conference record.
     *
     * @param string $conferencerecord Resource name.
     * @param string|null $pagetoken Continuation token.
     * @return array<string, mixed>
     */
    public function list_recordings(string $conferencerecord, ?string $pagetoken = null): array {
        if (!preg_match('#^conferenceRecords/[A-Za-z0-9_-]{10,255}$#', $conferencerecord)) {
            throw new \coding_exception('An invalid Google Meet conference record was supplied.');
        }

        $parameters = ['pageSize' => 100];
        $this->add_page_token($parameters, $pagetoken);

        return $this->send(
            self::API_ROOT . '/' . $conferencerecord . '/recordings?' . http_build_query(
                $parameters,
                '',
                '&',
                PHP_QUERY_RFC3986
            )
        );
    }

    /**
     * Adds a validated opaque continuation token.
     *
     * @param array<string, mixed> $parameters Query parameters.
     * @param string|null $pagetoken Continuation token.
     */
    private function add_page_token(array &$parameters, ?string $pagetoken): void {
        if ($pagetoken === null) {
            return;
        }
        if (
            $pagetoken === '' ||
            strlen($pagetoken) > self::TOKEN_MAX_LENGTH ||
            preg_match('/[\x00-\x1F\x7F]/', $pagetoken)
        ) {
            throw new recording_response_exception('The recording API returned an invalid page token.');
        }
        $parameters['pageToken'] = $pagetoken;
    }

    /**
     * Sends a request and returns a decoded response without exposing Google details.
     *
     * @param string $url Fixed Google Meet API URL.
     * @return array<string, mixed>
     */
    private function send(string $url): array {
        $response = $this->httpclient->get($url);
        $status = (int) ($response['status'] ?? 0);

        if ($status === 401 || $status === 403) {
            throw new recording_authorization_exception('Google Meet recording authorization was rejected.');
        }
        if ($status <= 0 || $status === 429 || $status >= 500) {
            throw new recording_transport_exception('The Google Meet recording service is temporarily unavailable.');
        }
        if ($status < 200 || $status >= 300) {
            throw new recording_api_exception('recording_api_http_' . max(0, min(999, $status)));
        }

        try {
            $decoded = json_decode((string) ($response['body'] ?? ''), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new recording_response_exception('The recording API returned invalid JSON.', 0, $e);
        }
        if (!is_array($decoded)) {
            throw new recording_response_exception('The recording API returned an invalid response object.');
        }

        return $decoded;
    }
}
