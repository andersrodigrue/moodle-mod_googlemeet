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
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Recording Calendar transport for adapter tests.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class calendar_adapter_test_client implements calendar_client {

    /** @var array<int, array<string, mixed>> Recorded calls. */
    public array $calls = [];

    /** @var array<string, mixed> Insert response. */
    public array $insertresponse = [];

    /** @var array<string, mixed> Patch response. */
    public array $patchresponse = [];

    /** @var array<string, mixed> Get response. */
    public array $getresponse = [];

    /** @var bool Whether insert should report an existing event. */
    public bool $insertduplicate = false;

    /**
     * Records an insert.
     *
     * @param string $calendarid Calendar ID.
     * @param array<string, mixed> $event Event resource.
     * @param array<string, mixed> $parameters Request parameters.
     * @return array<string, mixed>
     */
    public function insert_event(string $calendarid, array $event, array $parameters): array {
        $this->calls[] = [
            'method' => 'insert',
            'calendarid' => $calendarid,
            'event' => $event,
            'parameters' => $parameters,
        ];
        if ($this->insertduplicate) {
            throw new calendar_event_exists_exception();
        }

        return $this->insertresponse;
    }

    /**
     * Records a patch.
     *
     * @param string $calendarid Calendar ID.
     * @param string $eventid Event ID.
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
        $this->calls[] = [
            'method' => 'patch',
            'calendarid' => $calendarid,
            'eventid' => $eventid,
            'event' => $event,
            'parameters' => $parameters,
        ];

        return $this->patchresponse;
    }

    /**
     * Records a get.
     *
     * @param string $calendarid Calendar ID.
     * @param string $eventid Event ID.
     * @return array<string, mixed>
     */
    public function get_event(string $calendarid, string $eventid): array {
        $this->calls[] = [
            'method' => 'get',
            'calendarid' => $calendarid,
            'eventid' => $eventid,
        ];

        return $this->getresponse;
    }
}

/**
 * Tests for the managed Google Calendar adapter.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(calendar_adapter::class)]
final class calendar_adapter_test extends \advanced_testcase {

    /**
     * A new meeting uses controlled IDs and conferenceDataVersion 1.
     */
    public function test_new_meeting_inserts_deterministic_event(): void {
        $identity = new calendar_identity('https://moodle.example.test');
        $eventid = $identity->event_id(42);
        $requestid = $identity->request_id(42, $eventid);
        $client = new calendar_adapter_test_client();
        $client->insertresponse = $this->response(
            $eventid,
            $requestid,
            calendar_event_result::PENDING
        );
        $meeting = $this->meeting();
        $meeting->recurrence = "RRULE:FREQ=WEEKLY;COUNT=4\nEXDATE:20260815T130000Z";

        $result = (new calendar_adapter($client, $identity))->synchronise($meeting);

        $this->assertSame(calendar_event_result::PENDING, $result->status());
        $this->assertSame(['insert'], array_column($client->calls, 'method'));
        $call = $client->calls[0];
        $this->assertSame('primary', $call['calendarid']);
        $this->assertSame($eventid, $call['event']['id']);
        $this->assertSame($requestid, $call['event']['conferenceData']['createRequest']['requestId']);
        $this->assertSame('hangoutsMeet', $call['event']['conferenceData']['createRequest']['conferenceSolutionKey']['type']);
        $this->assertSame('2026-08-01T10:00:00-03:00', $call['event']['start']['dateTime']);
        $this->assertSame('America/Sao_Paulo', $call['event']['start']['timeZone']);
        $this->assertSame([
            'RRULE:FREQ=WEEKLY;COUNT=4',
            'EXDATE:20260815T130000Z',
        ], $call['event']['recurrence']);
        $this->assertSame(1, $call['parameters']['conferenceDataVersion']);
        $this->assertSame('all', $call['parameters']['sendUpdates']);
    }

    /**
     * A duplicate controlled event falls back to reconciliation by ID.
     */
    public function test_duplicate_insert_fetches_existing_event(): void {
        $identity = new calendar_identity('https://moodle.example.test');
        $eventid = $identity->event_id(42);
        $requestid = $identity->request_id(42, $eventid);
        $client = new calendar_adapter_test_client();
        $client->insertduplicate = true;
        $client->getresponse = $this->response(
            $eventid,
            $requestid,
            calendar_event_result::PENDING
        );

        $result = (new calendar_adapter($client, $identity))->synchronise($this->meeting());

        $this->assertSame(calendar_event_result::PENDING, $result->status());
        $this->assertSame(['insert', 'get'], array_column($client->calls, 'method'));
        $this->assertSame($eventid, $client->calls[1]['eventid']);
    }

    /**
     * A pending conference is polled without another mutation.
     */
    public function test_pending_meeting_polls_existing_event(): void {
        $requestid = str_repeat('a', 64);
        $client = new calendar_adapter_test_client();
        $client->getresponse = $this->success_response('persistedevent1', $requestid);
        $meeting = $this->meeting([
            'googleeventid' => 'persistedevent1',
            'requestid' => $requestid,
            'conferencestatus' => calendar_event_result::PENDING,
        ]);

        $result = (new calendar_adapter(
            $client,
            new calendar_identity('https://moodle.example.test')
        ))->synchronise($meeting);

        $this->assertSame(calendar_event_result::SUCCESS, $result->status());
        $this->assertSame(['get'], array_column($client->calls, 'method'));
    }

    /**
     * An existing ready meeting is patched without requesting a second conference.
     */
    public function test_ready_meeting_patches_event_without_new_conference(): void {
        $requestid = str_repeat('b', 64);
        $client = new calendar_adapter_test_client();
        $client->patchresponse = $this->success_response('persistedevent1', $requestid);
        $meeting = $this->meeting([
            'googleeventid' => 'persistedevent1',
            'requestid' => $requestid,
            'conferencestatus' => calendar_event_result::SUCCESS,
            'meetinguri' => 'https://meet.google.com/abc-defg-hij',
        ]);

        $result = (new calendar_adapter(
            $client,
            new calendar_identity('https://moodle.example.test')
        ))->synchronise($meeting);

        $this->assertSame(calendar_event_result::SUCCESS, $result->status());
        $this->assertSame(['patch'], array_column($client->calls, 'method'));
        $this->assertArrayNotHasKey('conferenceData', $client->calls[0]['event']);
        $this->assertSame(1, $client->calls[0]['parameters']['conferenceDataVersion']);
    }

    /**
     * An explicit retry after failure uses a new logical conference request ID.
     */
    public function test_failed_conference_uses_new_request_generation(): void {
        $identity = new calendar_identity('https://moodle.example.test');
        $oldrequestid = $identity->request_id(42, 'persistedevent1');
        $newrequestid = $identity->request_id(42, 'persistedevent1', 2);
        $client = new calendar_adapter_test_client();
        $client->patchresponse = $this->response(
            'persistedevent1',
            $newrequestid,
            calendar_event_result::PENDING
        );
        $meeting = $this->meeting([
            'googleeventid' => 'persistedevent1',
            'requestid' => $oldrequestid,
            'conferencestatus' => calendar_event_result::FAILURE,
            'syncattempts' => 2,
        ]);

        $result = (new calendar_adapter($client, $identity))->synchronise($meeting);

        $this->assertSame(calendar_event_result::PENDING, $result->status());
        $this->assertSame($newrequestid, $result->request_id());
        $this->assertNotSame($oldrequestid, $result->request_id());
        $this->assertSame(
            $newrequestid,
            $client->calls[0]['event']['conferenceData']['createRequest']['requestId']
        );
    }

    /**
     * Invalid local times are rejected before reaching Google.
     */
    public function test_invalid_times_are_rejected_before_transport(): void {
        $client = new calendar_adapter_test_client();
        $meeting = $this->meeting([
            'timeend' => 1,
        ]);

        $this->expectException(calendar_configuration_exception::class);
        try {
            (new calendar_adapter(
                $client,
                new calendar_identity('https://moodle.example.test')
            ))->synchronise($meeting);
        } finally {
            $this->assertSame([], $client->calls);
        }
    }

    /**
     * Builds a valid managed meeting.
     *
     * @param array<string, mixed> $overrides Meeting field overrides.
     * @return \stdClass
     */
    private function meeting(array $overrides = []): \stdClass {
        $timezone = new \DateTimeZone('America/Sao_Paulo');
        $start = new \DateTimeImmutable('2026-08-01 10:00:00', $timezone);

        return (object) ($overrides + [
            'id' => 42,
            'name' => 'Managed Google Meet',
            'integrationmode' => integration_mode::MANAGED,
            'calendarid' => 'primary',
            'googleeventid' => null,
            'requestid' => null,
            'meetinguri' => null,
            'conferencestatus' => null,
            'timestart' => $start->getTimestamp(),
            'timeend' => $start->modify('+1 hour')->getTimestamp(),
            'timezone' => 'America/Sao_Paulo',
            'recurrence' => null,
            'sendupdates' => 'all',
        ]);
    }

    /**
     * Builds a Calendar response for one status.
     *
     * @param string $eventid Event ID.
     * @param string $requestid Conference request ID.
     * @param string $status Conference status.
     * @return array<string, mixed>
     */
    private function response(string $eventid, string $requestid, string $status): array {
        return [
            'id' => $eventid,
            'htmlLink' => 'https://calendar.google.com/event?eid=one',
            'etag' => '"etag-one"',
            'conferenceData' => [
                'createRequest' => [
                    'requestId' => $requestid,
                    'status' => [
                        'statusCode' => $status,
                    ],
                ],
            ],
        ];
    }

    /**
     * Builds a successful Google Meet response.
     *
     * @param string $eventid Event ID.
     * @param string $requestid Conference request ID.
     * @return array<string, mixed>
     */
    private function success_response(string $eventid, string $requestid): array {
        $response = $this->response($eventid, $requestid, calendar_event_result::SUCCESS);
        $response['conferenceData']['conferenceId'] = 'abc-defg-hij';
        $response['conferenceData']['entryPoints'] = [[
            'entryPointType' => 'video',
            'uri' => 'https://meet.google.com/abc-defg-hij',
            'meetingCode' => 'abc-defg-hij',
        ]];

        return $response;
    }
}
