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

/**
 * Discovers Drive-backed artifacts through the Google Meet API.
 *
 * The Meet code is the association boundary. Activity titles, folder names and
 * caller-supplied file lists are never used to associate a remote artifact.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class recording_discovery {

    /** Maximum pages accepted for any list operation. */
    private const MAX_PAGES = 100;

    /** @var recording_client Google Meet recording API client. */
    private recording_client $client;

    /**
     * @param recording_client $client Google Meet recording API client.
     */
    public function __construct(recording_client $client) {
        $this->client = $client;
    }

    /**
     * Returns a complete, validated, de-duplicated artifact snapshot.
     *
     * @param \stdClass $meeting Activity record.
     * @return recording_artifact[]
     */
    public function discover(\stdClass $meeting): array {
        $meetingcode = self::meeting_code($meeting);
        $conferences = $this->conference_records($meetingcode);
        $artifacts = [];

        foreach ($conferences as $conferencerecord) {
            foreach ($this->recordings($conferencerecord, (string) ($meeting->name ?? '')) as $artifact) {
                $artifacts[$artifact->file_id()] = $artifact;
            }
        }

        return array_values($artifacts);
    }

    /**
     * Extracts a normalized Meet code from managed or manual activity data.
     *
     * @param \stdClass $meeting Activity record.
     * @return string
     */
    public static function meeting_code(\stdClass $meeting): string {
        $stored = strtolower(trim((string) ($meeting->meetingcode ?? '')));
        if (preg_match('/^[a-z]{3}-[a-z]{4}-[a-z]{3}$/', $stored)) {
            return $stored;
        }

        foreach ([$meeting->meetinguri ?? null, $meeting->url ?? null] as $candidate) {
            if (
                is_string($candidate) &&
                preg_match(
                    '#^https://meet\.google\.com/([a-z]{3}-[a-z]{4}-[a-z]{3})$#i',
                    trim($candidate),
                    $matches
                )
            ) {
                return strtolower($matches[1]);
            }
        }

        throw new recording_response_exception('Recording discovery requires a valid Google Meet code.');
    }

    /**
     * Lists every conference record for an exact Meet code.
     *
     * @param string $meetingcode Normalized Meet code.
     * @return string[]
     */
    private function conference_records(string $meetingcode): array {
        $records = [];
        $token = null;
        $seentokens = [];

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $response = $this->client->list_conference_records($meetingcode, $token);
            $items = $response['conferenceRecords'] ?? [];
            if (!is_array($items) || !array_is_list($items)) {
                throw new recording_response_exception('The recording API returned an invalid conference list.');
            }

            foreach ($items as $item) {
                if (!is_array($item)) {
                    throw new recording_response_exception('The recording API returned an invalid conference.');
                }
                $name = $item['name'] ?? null;
                if (
                    !is_string($name) ||
                    !preg_match('#^conferenceRecords/[A-Za-z0-9_-]{10,255}$#', $name)
                ) {
                    throw new recording_response_exception('The recording API returned an invalid conference name.');
                }
                $records[$name] = $name;
            }

            $token = $this->next_token($response, $seentokens);
            if ($token === null) {
                return array_values($records);
            }
        }

        throw new recording_response_exception('The conference list exceeded the safe pagination limit.');
    }

    /**
     * Lists generated artifacts for one conference record.
     *
     * @param string $conferencerecord Conference record resource name.
     * @param string $meetingname Activity name.
     * @return recording_artifact[]
     */
    private function recordings(string $conferencerecord, string $meetingname): array {
        $artifacts = [];
        $token = null;
        $seentokens = [];

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $response = $this->client->list_recordings($conferencerecord, $token);
            $items = $response['recordings'] ?? [];
            if (!is_array($items) || !array_is_list($items)) {
                throw new recording_response_exception('The recording API returned an invalid recording list.');
            }

            foreach ($items as $item) {
                if (!is_array($item)) {
                    throw new recording_response_exception('The recording API returned an invalid recording.');
                }
                $state = $item['state'] ?? null;
                if (!is_string($state)) {
                    throw new recording_response_exception('A recording has no valid processing state.');
                }
                if ($state !== recording_artifact::FILE_GENERATED) {
                    continue;
                }

                $artifact = recording_artifact::from_response($item, $conferencerecord, $meetingname);
                $artifacts[$artifact->file_id()] = $artifact;
            }

            $token = $this->next_token($response, $seentokens);
            if ($token === null) {
                return array_values($artifacts);
            }
        }

        throw new recording_response_exception('The recording list exceeded the safe pagination limit.');
    }

    /**
     * Returns a validated unseen continuation token.
     *
     * @param array<string, mixed> $response List response.
     * @param array<string, bool> $seentokens Tokens already followed.
     * @return string|null
     */
    private function next_token(array $response, array &$seentokens): ?string {
        if (!array_key_exists('nextPageToken', $response) || $response['nextPageToken'] === '') {
            return null;
        }

        $token = $response['nextPageToken'];
        if (
            !is_string($token) ||
            strlen($token) > 2048 ||
            preg_match('/[\x00-\x1F\x7F]/', $token) ||
            isset($seentokens[$token])
        ) {
            throw new recording_response_exception('The recording API returned an invalid pagination sequence.');
        }
        $seentokens[$token] = true;

        return $token;
    }
}
