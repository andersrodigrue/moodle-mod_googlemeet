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

use mod_googlemeet\local\calendar_guest_policy;
use mod_googlemeet\local\calendar_guest_resolver;
use mod_googlemeet\local\calendar_guest_snapshot;
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
     * @param calendar_guest_snapshot|null $guests Desired Moodle-managed guests.
     * @param array<string, bool> $previousguesthashes Previously managed email hashes.
     * @return calendar_event_result
     */
    public function synchronise(
        \stdClass $meeting,
        ?calendar_guest_snapshot $guests = null,
        array $previousguesthashes = []
    ): calendar_event_result {
        $this->validate_meeting($meeting);
        $guestpolicy = (string) ($meeting->guestpolicy ?? calendar_guest_policy::NONE);
        $guests ??= $guestpolicy === calendar_guest_policy::NONE
            ? calendar_guest_snapshot::none()
            : throw new calendar_configuration_exception(
                'A managed Calendar guest snapshot is required.'
            );
        if (
            ($guestpolicy === calendar_guest_policy::COURSE) !== $guests->is_managed()
        ) {
            throw new calendar_configuration_exception(
                'The Calendar guest snapshot does not match the stored policy.'
            );
        }

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
        $guestschanged = $guests->hash() !== ($meeting->guesthash ?? null);

        if ($persistedeventid === '') {
            $event = $this->event_body(
                $meeting,
                $guests->is_managed() ? $guests->event_attendees() : null
            );
            $event['id'] = $eventid;
            $event['conferenceData'] = $this->conference_create_request($requestid);

            try {
                $response = $this->client->insert_event(
                    $calendarid,
                    $event,
                    $this->mutation_parameters(
                        $this->send_updates(
                            $meeting,
                            $guests,
                            $previousguesthashes,
                            $guests->count() > 0
                        )
                    )
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

        // Existing Calendar events use PATCH semantics: omitting an array field
        // preserves its remote value. Include recurrence even when it is empty
        // so changing a recurring Moodle meeting to a single meeting removes the
        // previously stored Google Calendar series.
        $event = $this->event_body($meeting, null, true);
        if ($guestschanged) {
            $currentevent = $this->client->get_event($calendarid, $eventid);
            $event['attendees'] = $this->reconcile_attendees(
                $currentevent,
                $guests,
                $previousguesthashes
            );
            $this->add_guest_permissions($event);
        }
        if (empty($meeting->meetinguri)) {
            $event['conferenceData'] = $this->conference_create_request($requestid);
        }
        $response = $this->client->patch_event(
            $calendarid,
            $eventid,
            $event,
            $this->mutation_parameters(
                $this->send_updates(
                    $meeting,
                    $guests,
                    $previousguesthashes,
                    $guestschanged
                )
            )
        );

        return calendar_event_result::from_response($response, $eventid, $requestid);
    }

    /**
     * Deletes the managed Calendar event, if it has been created.
     *
     * A missing local event ID is already cancelled from the remote
     * perspective. The transport also treats Google 404 and 410 responses as
     * success, making retries safe after ambiguous network failures.
     *
     * @param \stdClass $meeting Google Meet activity record.
     */
    public function cancel(\stdClass $meeting): void {
        $this->validate_meeting($meeting, false);

        $eventid = trim((string) ($meeting->googleeventid ?? ''));
        if ($eventid === '') {
            return;
        }

        $this->validate_event_id($eventid);
        $this->client->delete_event(
            trim((string) $meeting->calendarid),
            $eventid,
            [
                'sendUpdates' => (int) ($meeting->guestcount ?? 0) > 0
                    ? 'all'
                    : 'none',
            ]
        );
    }

    /**
     * Builds the mutable event fields.
     *
     * @param \stdClass $meeting Google Meet activity record.
     * @param array<int, array{email: string}>|null $attendees Managed attendees, or null to omit the field.
     * @param bool $includerecurrence Whether an empty recurrence must be sent to clear a remote series.
     * @return array<string, mixed>
     */
    private function event_body(
        \stdClass $meeting,
        ?array $attendees = null,
        bool $includerecurrence = false
    ): array {
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
        if ($recurrence !== [] || $includerecurrence) {
            $event['recurrence'] = $recurrence;
        }
        if ($attendees !== null) {
            $event['attendees'] = $attendees;
            $this->add_guest_permissions($event);
        }

        return $event;
    }

    /**
     * Applies conservative attendee permissions to an event payload.
     *
     * @param array<string, mixed> $event Event payload.
     */
    private function add_guest_permissions(array &$event): void {
        $event['guestsCanInviteOthers'] = false;
        $event['guestsCanModify'] = false;
        $event['guestsCanSeeOtherGuests'] = false;
    }

    /**
     * Reconciles Moodle-managed attendees while preserving manual Calendar guests.
     *
     * Array fields replace the complete remote array. The previous local hashes
     * therefore identify only attendees Moodle is allowed to remove. Other
     * attendees and existing RSVP state are retained.
     *
     * @param array<string, mixed> $currentevent Current Calendar event resource.
     * @param calendar_guest_snapshot $desired Desired managed snapshot.
     * @param array<string, bool> $previousguesthashes Previously managed email hashes.
     * @return array<int, array<string, mixed>>
     */
    private function reconcile_attendees(
        array $currentevent,
        calendar_guest_snapshot $desired,
        array $previousguesthashes
    ): array {
        if (!empty($currentevent['attendeesOmitted'])) {
            throw new calendar_response_exception(
                'Google Calendar omitted attendees required for safe reconciliation.'
            );
        }
        $currentattendees = $currentevent['attendees'] ?? [];
        if (!is_array($currentattendees)) {
            throw new calendar_response_exception('Google Calendar returned invalid attendees.');
        }

        $desiredguests = $desired->guests();
        $reconciled = [];
        foreach ($currentattendees as $attendee) {
            if (!is_array($attendee)) {
                throw new calendar_response_exception('Google Calendar returned an invalid attendee.');
            }
            if (!empty($attendee['organizer']) || !empty($attendee['self'])) {
                continue;
            }

            $email = calendar_guest_snapshot::normalize_email((string) ($attendee['email'] ?? ''));
            if ($email === '' || !validate_email($email)) {
                throw new calendar_response_exception('Google Calendar returned an invalid attendee email.');
            }
            $emailhash = calendar_guest_snapshot::email_hash($email);
            if (isset($previousguesthashes[$emailhash]) && !isset($desiredguests[$email])) {
                continue;
            }
            $reconciled[$email] = $this->preserved_attendee($attendee, $email);
        }

        foreach ($desiredguests as $email => $guest) {
            if (!isset($reconciled[$email])) {
                $reconciled[$email] = ['email' => $guest['email']];
            }
        }
        ksort($reconciled, SORT_STRING);
        if (count($reconciled) > calendar_guest_resolver::MAX_ATTENDEES) {
            throw new calendar_guest_limit_exception(
                'The Calendar event contains too many attendees for safe reconciliation.'
            );
        }

        return array_values($reconciled);
    }

    /**
     * Preserves writable attendee state without echoing read-only or sensitive fields.
     *
     * @param array<string, mixed> $attendee Calendar attendee.
     * @param string $email Normalized email.
     * @return array<string, mixed>
     */
    private function preserved_attendee(array $attendee, string $email): array {
        $preserved = ['email' => $email];
        if (in_array(
            ($attendee['responseStatus'] ?? null),
            ['needsAction', 'declined', 'tentative', 'accepted'],
            true
        )) {
            $preserved['responseStatus'] = $attendee['responseStatus'];
        }
        foreach (['optional', 'resource'] as $booleanfield) {
            if (isset($attendee[$booleanfield]) && is_bool($attendee[$booleanfield])) {
                $preserved[$booleanfield] = $attendee[$booleanfield];
            }
        }
        if (
            isset($attendee['additionalGuests'])
            && is_int($attendee['additionalGuests'])
            && $attendee['additionalGuests'] >= 0
        ) {
            $preserved['additionalGuests'] = $attendee['additionalGuests'];
        }

        return $preserved;
    }

    /**
     * Selects a notification policy from guest state, never from raw form data.
     *
     * Removing the last managed attendees still uses `all` so removed guests
     * receive the Calendar cancellation/update for their attendee copy.
     *
     * @param \stdClass $meeting Activity record.
     * @param calendar_guest_snapshot $guests Desired snapshot.
     * @param array<string, bool> $previousguesthashes Previously managed hashes.
     * @param bool $guestschanged Whether this mutation changes managed attendees.
     * @return string
     */
    private function send_updates(
        \stdClass $meeting,
        calendar_guest_snapshot $guests,
        array $previousguesthashes,
        bool $guestschanged
    ): string {
        if (
            $guestschanged
            && (
                $guests->count() > 0
                || $previousguesthashes !== []
                || (int) ($meeting->guestcount ?? 0) > 0
            )
        ) {
            return 'all';
        }

        return 'none';
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
    private function validate_meeting(\stdClass $meeting, bool $requireeventdata = true): void {
        if (empty($meeting->id) || (int) $meeting->id <= 0) {
            throw new calendar_configuration_exception('A positive Google Meet activity ID is required.');
        }
        if (($meeting->integrationmode ?? null) !== integration_mode::MANAGED) {
            throw new calendar_configuration_exception('Only managed meetings can use the Calendar adapter.');
        }
        if (trim((string) ($meeting->calendarid ?? '')) === '') {
            throw new calendar_configuration_exception('A Google Calendar identifier is required.');
        }
        if ($requireeventdata && trim((string) ($meeting->name ?? $meeting->originalname ?? '')) === '') {
            throw new calendar_configuration_exception('A meeting name is required.');
        }
        if ($requireeventdata && (int) ($meeting->timestart ?? 0) <= 0) {
            throw new calendar_configuration_exception('A positive meeting start time is required.');
        }
        if ($requireeventdata && (int) ($meeting->timeend ?? 0) <= (int) $meeting->timestart) {
            throw new calendar_configuration_exception('The meeting end time must be after its start time.');
        }
        $guestpolicy = (string) ($meeting->guestpolicy ?? calendar_guest_policy::NONE);
        if (!calendar_guest_policy::is_valid($guestpolicy)) {
            throw new calendar_configuration_exception('The Calendar guest policy is invalid.');
        }

        if ($requireeventdata) {
            try {
                new \DateTimeZone((string) ($meeting->timezone ?? ''));
            } catch (\Exception $e) {
                throw new calendar_configuration_exception('The meeting timezone is invalid.', 0, $e);
            }
        }
    }

    /**
     * Validates persisted or generated remote identifiers.
     *
     * @param string $eventid Calendar event ID.
     * @param string $requestid Conference request ID.
     */
    private function validate_identifiers(string $eventid, string $requestid): void {
        $this->validate_event_id($eventid);
        if ($requestid === '' || strlen($requestid) > 64) {
            throw new calendar_configuration_exception('The Google conference request ID is invalid.');
        }
    }

    /**
     * Validates a persisted or generated Calendar event identifier.
     *
     * @param string $eventid Calendar event ID.
     */
    private function validate_event_id(string $eventid): void {
        if (
            strlen($eventid) < 5 ||
            strlen($eventid) > 255 ||
            !preg_match('/^[0-9a-v]+$/', $eventid)
        ) {
            throw new calendar_configuration_exception('The Google Calendar event ID is invalid.');
        }
    }
}
