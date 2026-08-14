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

use mod_googlemeet\api\calendar_configuration_exception;
use mod_googlemeet\api\calendar_list_client;
use mod_googlemeet\api\calendar_response_exception;

/**
 * Validates and selects writable calendars from the owner's Calendar list.
 *
 * The catalog is deliberately owner-scoped and fail-closed. Remote labels are
 * used for the form only; persistence receives the exact validated Calendar ID.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class calendar_catalog {

    /** Maximum CalendarList pages accepted in one form/preflight operation. */
    private const MAX_PAGES = 10;

    /** Maximum length supported by the persisted calendarid field. */
    private const MAX_CALENDAR_ID_LENGTH = 255;

    /** Maximum normalized display-label length retained in memory. */
    private const MAX_SUMMARY_LENGTH = 255;

    /** Roles that can create or change Calendar events. */
    private const WRITABLE_ROLES = [
        'writer',
        'writerWithoutPrivateAccess',
        'owner',
    ];

    /** @var calendar_list_client Owner-authenticated Calendar list transport. */
    private calendar_list_client $client;

    /**
     * Cached catalog keyed by exact Calendar ID.
     *
     * @var array<string, array{id: string, summary: string, primary: bool, accessrole: string}>|null
     */
    private ?array $calendars = null;

    /**
     * @param calendar_list_client $client Owner-authenticated Calendar list transport.
     */
    public function __construct(calendar_list_client $client) {
        $this->client = $client;
    }

    /**
     * Returns every validated writable calendar in stable display order.
     *
     * @return array<int, array{id: string, summary: string, primary: bool, accessrole: string}>
     */
    public function writable_calendars(): array {
        if ($this->calendars === null) {
            $this->calendars = $this->load();
        }

        $calendars = array_values($this->calendars);
        usort($calendars, static function (array $left, array $right): int {
            if ($left['primary'] !== $right['primary']) {
                return $left['primary'] ? -1 : 1;
            }

            $summarycomparison = strcasecmp($left['summary'], $right['summary']);
            return $summarycomparison !== 0
                ? $summarycomparison
                : strcmp($left['id'], $right['id']);
        });

        return $calendars;
    }

    /**
     * Resolves an exact writable Calendar ID.
     *
     * The historic `primary` alias is canonicalized to the authenticated user's
     * actual primary Calendar ID. It never falls back to another calendar.
     *
     * @param string $calendarid Submitted or previously persisted Calendar ID.
     * @return array{id: string, summary: string, primary: bool, accessrole: string}
     */
    public function require_writable(string $calendarid): array {
        $calendarid = $this->calendar_id($calendarid, false);
        $calendars = $this->writable_calendars();

        if ($calendarid === 'primary') {
            foreach ($calendars as $calendar) {
                if ($calendar['primary']) {
                    return $calendar;
                }
            }
            throw new calendar_configuration_exception(
                'The authenticated account has no writable primary calendar.'
            );
        }

        foreach ($calendars as $calendar) {
            if (hash_equals($calendar['id'], $calendarid)) {
                return $calendar;
            }
        }

        throw new calendar_configuration_exception(
            'The selected Google Calendar is unavailable or not writable.'
        );
    }

    /**
     * Returns the preferred ID for a new activity.
     *
     * @return string|null Primary Calendar ID, first writable ID, or null.
     */
    public function default_calendar_id(): ?string {
        $calendars = $this->writable_calendars();
        if ($calendars === []) {
            return null;
        }
        foreach ($calendars as $calendar) {
            if ($calendar['primary']) {
                return $calendar['id'];
            }
        }

        return $calendars[0]['id'];
    }

    /**
     * Loads bounded CalendarList pages and validates every accepted entry.
     *
     * @return array<string, array{id: string, summary: string, primary: bool, accessrole: string}>
     */
    private function load(): array {
        $calendars = [];
        $pagetoken = null;
        $seentokens = [];

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $response = $this->client->list_writable_calendars($pagetoken);
            $items = $response['items'] ?? [];
            if (!is_array($items)) {
                throw new calendar_response_exception(
                    'Google Calendar returned an invalid CalendarList items collection.'
                );
            }

            foreach ($items as $item) {
                if (!is_array($item)) {
                    throw new calendar_response_exception(
                        'Google Calendar returned an invalid CalendarList entry.'
                    );
                }
                if (!empty($item['deleted'])) {
                    continue;
                }

                $accessrole = (string) ($item['accessRole'] ?? '');
                if (!in_array($accessrole, self::WRITABLE_ROLES, true)) {
                    continue;
                }
                if (!$this->supports_google_meet($item)) {
                    continue;
                }

                $id = $this->calendar_id((string) ($item['id'] ?? ''), true);
                if (isset($calendars[$id])) {
                    throw new calendar_response_exception(
                        'Google Calendar returned a duplicate CalendarList identifier.'
                    );
                }

                $summary = $this->summary((string) (
                    $item['summaryOverride']
                    ?? $item['summary']
                    ?? $id
                ));
                $calendars[$id] = [
                    'id' => $id,
                    'summary' => $summary !== '' ? $summary : $id,
                    'primary' => !empty($item['primary']),
                    'accessrole' => $accessrole,
                ];
            }

            $nexttoken = $response['nextPageToken'] ?? null;
            if ($nexttoken === null || $nexttoken === '') {
                return $calendars;
            }
            if (!is_string($nexttoken) || !$this->valid_page_token($nexttoken)) {
                throw new calendar_response_exception(
                    'Google Calendar returned an invalid CalendarList page token.'
                );
            }
            if (isset($seentokens[$nexttoken])) {
                throw new calendar_response_exception(
                    'Google Calendar repeated a CalendarList page token.'
                );
            }

            $seentokens[$nexttoken] = true;
            $pagetoken = $nexttoken;
        }

        throw new calendar_response_exception(
            'Google Calendar exceeded the bounded CalendarList page limit.'
        );
    }

    /**
     * Validates one Calendar ID.
     *
     * @param string $calendarid Calendar identifier.
     * @param bool $remoteresponse Whether invalid data came from Google.
     * @return string Normalized identifier.
     */
    private function calendar_id(string $calendarid, bool $remoteresponse): string {
        $calendarid = trim($calendarid);
        if (
            $calendarid === ''
            || strlen($calendarid) > self::MAX_CALENDAR_ID_LENGTH
            || preg_match('/[\x00-\x1F\x7F]/', $calendarid)
        ) {
            if ($remoteresponse) {
                throw new calendar_response_exception(
                    'Google Calendar returned an invalid CalendarList identifier.'
                );
            }
            throw new calendar_configuration_exception(
                'A valid Google Calendar identifier is required.'
            );
        }

        return $calendarid;
    }

    /**
     * Normalizes an untrusted remote Calendar label for display.
     *
     * Moodle's select renderer still performs the final HTML escaping.
     *
     * @param string $summary Remote display label.
     * @return string
     */
    private function summary(string $summary): string {
        $summary = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', $summary));
        return \core_text::substr($summary, 0, self::MAX_SUMMARY_LENGTH);
    }

    /**
     * Excludes a calendar only when Google explicitly says Meet is unsupported.
     *
     * Older or restricted responses may omit conferenceProperties entirely, so
     * absence remains compatible and event creation performs the final check.
     *
     * @param array<string, mixed> $item CalendarList entry.
     * @return bool
     */
    private function supports_google_meet(array $item): bool {
        if (!array_key_exists('conferenceProperties', $item)) {
            return true;
        }
        $properties = $item['conferenceProperties'];
        if (!is_array($properties)) {
            throw new calendar_response_exception(
                'Google Calendar returned invalid conference properties.'
            );
        }
        if (!array_key_exists('allowedConferenceSolutionTypes', $properties)) {
            return true;
        }
        $types = $properties['allowedConferenceSolutionTypes'];
        if (!is_array($types)) {
            throw new calendar_response_exception(
                'Google Calendar returned invalid conference solution types.'
            );
        }

        return in_array('hangoutsMeet', $types, true);
    }

    /**
     * Validates a server-supplied opaque pagination token.
     *
     * @param string $pagetoken Opaque page token.
     * @return bool
     */
    private function valid_page_token(string $pagetoken): bool {
        return strlen($pagetoken) <= 2048
            && !preg_match('/[\x00-\x1F\x7F]/', $pagetoken);
    }
}
