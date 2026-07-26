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

use mod_googlemeet\local\integration_mode;

/**
 * Reconciles one managed activity with the Google Calendar Events API.
 *
 * The adapter is independent of OAuth and a particular Google SDK. It creates a
 * controlled event ID, reuses the same conference request ID across transport
 * retries, and maps asynchronous conference results to a validated value object.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class calendar_adapter {

    /** Calendar API conference data version required for Google Meet. */
    private const CONFERENCE_DATA_VERSION = 1;

    /** Google Calendar conference solution type for Meet. */
    private const CONFERENCE_TYPE = 'hangoutsMeet';

    /** Accepted Calendar guest notification policies. */
    private const SEND_UPDATES = [
        'all',
        'externalOnly',
        'none',
    ];

    /** @var calendar_client Calendar transport. */
    private calendar_client $client;

    /** @var calendar_identity Deterministic identity generator. */
    private calendar_identity $identity;

    /**
     * @param calendar_client $client Calendar transport.
     * @param calendar_identity|null $identity Deterministic identity generator.
     */
    public function __construct(calendar_client $client, ?calendar_identity $identity = null) {
        $this->client = $client;
        $this->identity = $identity ?? new calendar_identity();
    }

    /**
     * Creates, updates, or polls one managed Calendar event.
     *
     * @param \stdClass $meeting Google Meet activity record.
     * @return calendar_event_result
     */
    public function synchronise(\stdClass $meeting): calendar_event_result {
        $this->validate_meeting($meeting);

        $googlemeetid = (int) $meeting->id;
        $calendarid = trim((string) $meeting->calendarid);
        $persistedeventid = trim((string) ($meeting->googleeventid ?? ''));
        $eventid = $persistedeventid !== ''
            ? $persistedeventid
            : $this->identity->event_id($googlemeetid);
        $persistedrequestid = trim((string) ($meeting->requestid ?? ''));
        if (($meeting->conferencestatus ?? null) === calendar_event_result::FAILURE) {
            $requestid = $this->identity->request_id(
                $googlemeetid,
                $eventid,
                max(1, (int) ($meeting->syncattempts ?? 1))
            );
        } else {
            $requestid = $persistedrequestid !== ''
                ? $persistedrequestid
                : $this->identity->request_id($googlemeetid, $eventid);
        }

        $this->validate_identifiers($eventid, $requestid);

        if ($persistedeventid === '') {
            $event = $this->event_body($meeting);
            $event['id'] = $eventid;
            $event['conferenceData'] = $this->conference_create_request($requestid);

            try {
                $response = $this->client->insert_event(
                    $calendarid,
                    $event,
                    $this->mutation_parameters((string) $meeting->sendupdates)
                );
            } catch (calendar_event_exists_exception $e) {
                $response = $this->client->get_event($calendarid, $eventid);
            }

            return calendar_event_result::from_response($response, $eventid, $requestid);
        }

        if (($meeting->conferencestatus ?? null) === calendar_event_result::PENDING) {
            $response = $this->client->get_event($calendarid, $eventid);

            return calendar_event_result::from_response($response, $eventid, $requestid);
        }

        $event = $this->event_body($meeting);
        if (empty($meeting->meetinguri)) {
            $event['conferenceData'] = $this->conference_create_request($requestid);
        }
        $response = $this->client->patch_event(
            $calendarid,
            $eventid,
            $event,
            $this->mutation_parameters((string) $meeting->sendupdates)
        );

        return calendar_event_result::from_response($response, $eventid, $requestid);
    }

    /**
     * Builds the mutable event fields.
     *
     * @param \stdClass $meeting Google Meet activity record.
     * @return array<string, mixed>
     */
    private function event_body(\stdClass $meeting): array {
        $timezone = new \DateTimeZone((string) $meeting->timezone);
        $start = (new \DateTimeImmutable('@' . (int) $meeting->timestart))
            ->setTimezone($timezone)
            ->format(\DateTimeInterface::RFC3339);
        $end = (new \DateTimeImmutable('@' . (int) $meeting->timeend))
            ->setTimezone($timezone)
            ->format(\DateTimeInterface::RFC3339);

        $event = [
            'summary' => trim((string) ($meeting->name ?? $meeting->originalname)),
            'start' => [
                'dateTime' => $start,
                'timeZone' => (string) $meeting->timezone,
            ],
            'end' => [
                'dateTime' => $end,
                'timeZone' => (string) $meeting->timezone,
            ],
        ];

        $recurrence = $this->normalise_recurrence((string) ($meeting->recurrence ?? ''));
        if ($recurrence !== []) {
            $event['recurrence'] = $recurrence;
        }

        return $event;
    }

    /**
     * Builds a Google Meet create request.
     *
     * @param string $requestid Idempotency identifier.
     * @return array<string, mixed>
     */
    private function conference_create_request(string $requestid): array {
        return [
            'createRequest' => [
                'requestId' => $requestid,
                'conferenceSolutionKey' => [
                    'type' => self::CONFERENCE_TYPE,
                ],
            ],
        ];
    }

    /**
     * Builds Calendar mutation parameters.
     *
     * @param string $sendupdates Guest notification policy.
     * @return array{conferenceDataVersion: int, sendUpdates: string}
     */
    private function mutation_parameters(string $sendupdates): array {
        return [
            'conferenceDataVersion' => self::CONFERENCE_DATA_VERSION,
            'sendUpdates' => $sendupdates,
        ];
    }

    /**
     * Splits a normalized recurrence field into Calendar recurrence lines.
     *
     * @param string $recurrence Stored recurrence value.
     * @return string[]
     */
    private function normalise_recurrence(string $recurrence): array {
        $recurrence = trim($recurrence);
        if ($recurrence === '') {
            return [];
        }

        $lines = preg_split('/\R+/', $recurrence);
        if ($lines === false) {
            throw new calendar_configuration_exception('The meeting recurrence could not be parsed.');
        }

        $rules = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (!preg_match('/^(?:RRULE|RDATE|EXRULE|EXDATE):/i', $line)) {
                throw new calendar_configuration_exception('The meeting recurrence is not normalized.');
            }
            $rules[] = $line;
        }

        return $rules;
    }

    /**
     * Validates local fields required by Calendar.
     *
     * @param \stdClass $meeting Google Meet activity record.
     */
    private function validate_meeting(\stdClass $meeting): void {
        if (empty($meeting->id) || (int) $meeting->id <= 0) {
            throw new calendar_configuration_exception('A positive Google Meet activity ID is required.');
        }
        if (($meeting->integrationmode ?? null) !== integration_mode::MANAGED) {
            throw new calendar_configuration_exception('Only managed meetings can use the Calendar adapter.');
        }
        if (trim((string) ($meeting->calendarid ?? '')) === '') {
            throw new calendar_configuration_exception('A Google Calendar identifier is required.');
        }
        if (trim((string) ($meeting->name ?? $meeting->originalname ?? '')) === '') {
            throw new calendar_configuration_exception('A meeting name is required.');
        }
        if ((int) ($meeting->timestart ?? 0) <= 0) {
            throw new calendar_configuration_exception('A positive meeting start time is required.');
        }
        if ((int) ($meeting->timeend ?? 0) <= (int) $meeting->timestart) {
            throw new calendar_configuration_exception('The meeting end time must be after its start time.');
        }
        if (!in_array((string) ($meeting->sendupdates ?? ''), self::SEND_UPDATES, true)) {
            throw new calendar_configuration_exception('The Calendar sendUpdates policy is invalid.');
        }

        try {
            new \DateTimeZone((string) ($meeting->timezone ?? ''));
        } catch (\Exception $e) {
            throw new calendar_configuration_exception('The meeting timezone is invalid.', 0, $e);
        }
    }

    /**
     * Validates persisted or generated remote identifiers.
     *
     * @param string $eventid Calendar event ID.
     * @param string $requestid Conference request ID.
     */
    private function validate_identifiers(string $eventid, string $requestid): void {
        if (
            strlen($eventid) < 5 ||
            strlen($eventid) > 255 ||
            !preg_match('/^[0-9a-v]+$/', $eventid)
        ) {
            throw new calendar_configuration_exception('The Google Calendar event ID is invalid.');
        }
        if ($requestid === '' || strlen($requestid) > 64) {
            throw new calendar_configuration_exception('The Google conference request ID is invalid.');
        }
    }
}
