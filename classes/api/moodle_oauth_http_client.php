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
 * Authenticated HTTP transport backed by Moodle's per-user OAuth client.
 *
 * Calling is_logged_in() before every request lets Moodle exchange a stored
 * refresh token when the short-lived access token has expired.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class moodle_oauth_http_client implements calendar_http_client {

    /** Google API host accepted by this transport. */
    private const API_HOST = 'www.googleapis.com';

    /** @var \core\oauth2\client Moodle OAuth client. */
    private \core\oauth2\client $client;

    /**
     * @param \core\oauth2\client $client Moodle OAuth client.
     */
    public function __construct(\core\oauth2\client $client) {
        $this->client = $client;
    }

    /**
     * Sends one authenticated JSON request to the fixed Google API host.
     *
     * @param string $method HTTP method.
     * @param string $url Absolute Google API URL.
     * @param array<string, mixed>|null $body Optional JSON body.
     * @return array{status: int, body: string}
     */
    public function request(string $method, string $url, ?array $body = null): array {
        $method = strtoupper($method);
        if (!in_array($method, ['GET', 'POST', 'PATCH'], true)) {
            throw new \coding_exception('Unsupported Google Calendar HTTP method.');
        }

        $parts = parse_url($url);
        if (
            !is_array($parts) ||
            ($parts['scheme'] ?? null) !== 'https' ||
            ($parts['host'] ?? null) !== self::API_HOST
        ) {
            throw new \coding_exception('Google Calendar requests must use the fixed HTTPS API host.');
        }

        try {
            if (!$this->client->is_logged_in()) {
                throw new calendar_authorization_exception('Google Calendar authorization is required.');
            }
        } catch (\moodle_exception $e) {
            throw new calendar_authorization_exception('Google Calendar authorization could not be refreshed.', 0, $e);
        }

        $this->client->setHeader('Accept: application/json');
        $this->client->setHeader('Content-Type: application/json');
        $payload = $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR);

        $response = match ($method) {
            'GET' => $this->client->get($url),
            'POST' => $this->client->post($url, $payload),
            'PATCH' => $this->client->patch($url, $payload),
        };

        if ($this->client->get_errno() !== 0) {
            throw new calendar_transport_exception('The Google Calendar transport failed.');
        }

        $info = $this->client->get_info();
        $status = (int) ($info['http_code'] ?? 0);
        if ($status <= 0 || !is_string($response)) {
            throw new calendar_transport_exception('The Google Calendar transport returned no HTTP response.');
        }

        return [
            'status' => $status,
            'body' => $response,
        ];
    }
}
