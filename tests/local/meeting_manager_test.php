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

use mod_googlemeet\api\calendar_adapter;
use mod_googlemeet\api\calendar_api_exception;
use mod_googlemeet\api\calendar_authorization_exception;
use mod_googlemeet\api\calendar_client;
use mod_googlemeet\api\calendar_event_result;
use mod_googlemeet\api\calendar_identity;
use mod_googlemeet\task\synchronise_meeting;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Calendar transport used by meeting manager tests.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class meeting_manager_calendar_client implements calendar_client {

    /** @var array<string, mixed> Response returned by every operation. */
    public array $response = [];

    /** @var string[] Recorded operation names. */
    public array $operations = [];

    /** @var \RuntimeException|null Transport failure to throw. */
    public ?\RuntimeException $exception = null;

    /**
     * Returns the configured insert response.
     *
     * @param string $calendarid Calendar ID.
     * @param array<string, mixed> $event Event resource.
     * @param array<string, mixed> $parameters Request parameters.
     * @return array<string, mixed>
     */
    public function insert_event(string $calendarid, array $event, array $parameters): array {
        $this->operations[] = 'insert';
        if ($this->exception !== null) {
            throw $this->exception;
        }

        return $this->response;
    }

    /**
     * Returns the configured patch response.
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
        $this->operations[] = 'patch';
        if ($this->exception !== null) {
            throw $this->exception;
        }

        return $this->response;
    }

    /**
     * Returns the configured get response.
     *
     * @param string $calendarid Calendar ID.
     * @param string $eventid Event ID.
     * @return array<string, mixed>
     */
    public function get_event(string $calendarid, string $eventid): array {
        $this->operations[] = 'get';
        if ($this->exception !== null) {
            throw $this->exception;
        }

        return $this->response;
    }

    /**
     * Deletes the configured event.
     *
     * @param string $calendarid Calendar ID.
     * @param string $eventid Event ID.
     * @param array<string, mixed> $parameters Request parameters.
     */
    public function delete_event(string $calendarid, string $eventid, array $parameters): void {
        $this->operations[] = 'delete';
        if ($this->exception !== null) {
            throw $this->exception;
        }
    }
}

/**
 * Adapter provider that exposes composition failures to manager tests.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class meeting_manager_failing_provider implements calendar_adapter_provider {

    /** @var \RuntimeException Exception thrown during production composition. */
    private \RuntimeException $exception;

    /**
     * @param \RuntimeException $exception Exception to throw.
     */
    public function __construct(\RuntimeException $exception) {
        $this->exception = $exception;
    }

    /**
     * @param \stdClass $meeting Managed activity record.
     * @return calendar_adapter
     */
    public function create(\stdClass $meeting): calendar_adapter {
        throw $this->exception;
    }
}

/**
 * Tests for the local synchronization coordinator.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(meeting_manager::class)]
final class meeting_manager_test extends \advanced_testcase {

    /**
     * A managed cancellation is queued as the owner and deletes the remote event.
     */
    public function test_managed_cancellation_is_owner_scoped_and_settles(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $meeting = $this->create_meeting([
            'integrationmode' => integration_mode::MANAGED,
            'owneruserid' => $owner->id,
            'calendarid' => 'primary',
            'googleeventid' => 'event123',
            'syncstatus' => sync_state::READY,
            'meetinguri' => 'https://meet.google.com/abc-defg-hij',
            'url' => 'https://meet.google.com/abc-defg-hij',
        ]);
        $client = new meeting_manager_calendar_client();
        $manager = new meeting_manager(
            calendaradapter: new calendar_adapter($client)
        );

        $this->assertTrue($manager->cancel($meeting->id));
        $this->assertSame(sync_state::CANCELLING, (new sync_repository())->get($meeting->id)->syncstatus);

        $this->expectOutputString(
            get_string('syncmanagedcancelled', 'mod_googlemeet', $meeting->id) . "\n"
        );
        $manager->process($meeting->id);
        $updated = (new sync_repository())->get($meeting->id);

        $this->assertSame(sync_state::CANCELLED, $updated->syncstatus);
        $this->assertSame(['delete'], $client->operations);
        $this->assertNull($updated->meetinguri);
        $this->assertSame('', $updated->url);
        $this->assertSame('event123', $updated->googleeventid);

        $tasks = \core\task\manager::get_adhoc_tasks(synchronise_meeting::class);
        $this->assertCount(1, $tasks);
        $this->assertSame((int) $owner->id, (int) reset($tasks)->get_userid());
    }

    /**
     * Repeating a settled cancellation cannot create another task.
     */
    public function test_cancelled_meeting_cancellation_is_idempotent(): void {
        $this->resetAfterTest();

        $meeting = $this->create_meeting([
            'integrationmode' => integration_mode::MANAGED,
            'syncstatus' => sync_state::CANCELLED,
        ]);

        $this->assertFalse((new meeting_manager())->cancel($meeting->id));
        $this->assertSame([], \core\task\manager::get_adhoc_tasks(synchronise_meeting::class));
    }

    /**
     * A manual meeting settles locally without a remote API.
     */
    public function test_manual_meeting_becomes_ready(): void {
        $this->resetAfterTest();

        $meeting = $this->create_meeting([
            'integrationmode' => integration_mode::MANUAL,
            'syncstatus' => sync_state::QUEUED,
        ]);

        $this->expectOutputString(
            get_string('syncmanualready', 'mod_googlemeet', $meeting->id) . "\n"
        );
        (new meeting_manager())->process($meeting->id);
        $updated = (new sync_repository())->get($meeting->id);

        $this->assertSame(sync_state::READY, $updated->syncstatus);
        $this->assertSame(1, (int) $updated->syncattempts);
        $this->assertGreaterThan(0, (int) $updated->timelastattempt);
    }

    /**
     * A legacy meeting remains disconnected until the owner reconnects.
     */
    public function test_legacy_meeting_requires_reconnection(): void {
        $this->resetAfterTest();

        $meeting = $this->create_meeting([
            'integrationmode' => integration_mode::LEGACY,
            'syncstatus' => sync_state::QUEUED,
        ]);

        $this->expectOutputString(
            get_string('synclegacydisconnected', 'mod_googlemeet', $meeting->id) . "\n"
        );
        (new meeting_manager())->process($meeting->id);
        $updated = (new sync_repository())->get($meeting->id);

        $this->assertSame(sync_state::DISCONNECTED, $updated->syncstatus);
        $this->assertSame('reconnect_required', $updated->lasterrorcode);
        $this->assertSame(1, (int) $updated->syncattempts);
    }

    /**
     * Managed synchronization fails safely while the Calendar adapter is absent.
     */
    public function test_managed_meeting_fails_without_calendar_adapter(): void {
        $this->resetAfterTest();

        $meeting = $this->create_meeting([
            'integrationmode' => integration_mode::MANAGED,
            'syncstatus' => sync_state::QUEUED,
        ]);

        $this->expectOutputString(
            get_string('syncmanageddeferred', 'mod_googlemeet', $meeting->id) . "\n"
        );
        (new meeting_manager())->process($meeting->id);
        $updated = (new sync_repository())->get($meeting->id);

        $this->assertSame(sync_state::FAILED, $updated->syncstatus);
        $this->assertSame('adapter_unavailable', $updated->lasterrorcode);
        $this->assertSame(1, (int) $updated->syncattempts);
    }

    /**
     * Missing or revoked OAuth authorization moves the meeting to disconnected.
     */
    public function test_managed_authorization_failure_requires_reconnect(): void {
        $this->resetAfterTest();

        $meeting = $this->create_meeting([
            'integrationmode' => integration_mode::MANAGED,
            'syncstatus' => sync_state::QUEUED,
        ]);
        $provider = new meeting_manager_failing_provider(
            new calendar_authorization_exception('Sensitive OAuth detail')
        );

        $this->expectOutputString(
            get_string('syncmanageddisconnected', 'mod_googlemeet', $meeting->id) . "\n"
        );
        (new meeting_manager(
            calendaradapterprovider: $provider
        ))->process($meeting->id);
        $updated = (new sync_repository())->get($meeting->id);

        $this->assertSame(sync_state::DISCONNECTED, $updated->syncstatus);
        $this->assertSame('authorization_required', $updated->lasterrorcode);
        $this->assertStringNotContainsString('Sensitive OAuth detail', $updated->lasterrormessage);
    }

    /**
     * A permanent Calendar rejection stores only the stable classified code.
     */
    public function test_managed_api_failure_is_safely_recorded(): void {
        $this->resetAfterTest();

        $meeting = $this->create_meeting([
            'integrationmode' => integration_mode::MANAGED,
            'syncstatus' => sync_state::QUEUED,
        ]);
        $provider = new meeting_manager_failing_provider(
            new calendar_api_exception('calendar_permission_denied')
        );

        $this->expectOutputString(
            get_string('syncmanagedapifailed', 'mod_googlemeet', $meeting->id) . "\n"
        );
        (new meeting_manager(
            calendaradapterprovider: $provider
        ))->process($meeting->id);
        $updated = (new sync_repository())->get($meeting->id);

        $this->assertSame(sync_state::FAILED, $updated->syncstatus);
        $this->assertSame('calendar_permission_denied', $updated->lasterrorcode);
    }

    /**
     * A managed pending response persists controlled identity and state.
     */
    public function test_managed_meeting_persists_pending_result(): void {
        $this->resetAfterTest();

        $meeting = $this->create_meeting([
            'integrationmode' => integration_mode::MANAGED,
            'calendarid' => 'primary',
            'meetinguri' => null,
            'timezone' => 'America/Sao_Paulo',
            'sendupdates' => 'all',
            'syncstatus' => sync_state::QUEUED,
        ]);
        $identity = new calendar_identity('https://moodle.example.test');
        $eventid = $identity->event_id((int) $meeting->id);
        $requestid = $identity->request_id((int) $meeting->id, $eventid);
        $client = new meeting_manager_calendar_client();
        $client->response = $this->calendar_response(
            $eventid,
            $requestid,
            calendar_event_result::PENDING
        );
        $manager = new meeting_manager(
            null,
            null,
            new calendar_adapter($client, $identity)
        );

        $this->expectOutputString(
            get_string('syncmanagedpending', 'mod_googlemeet', $meeting->id) . "\n"
        );
        $manager->process($meeting->id);
        $updated = (new sync_repository())->get($meeting->id);

        $this->assertSame(sync_state::PENDING, $updated->syncstatus);
        $this->assertSame('pending', $updated->conferencestatus);
        $this->assertSame($eventid, $updated->googleeventid);
        $this->assertSame($requestid, $updated->requestid);
        $this->assertSame(['insert'], $client->operations);
    }

    /**
     * Polling a managed pending event can complete the conference.
     */
    public function test_managed_pending_meeting_becomes_ready(): void {
        $this->resetAfterTest();

        $requestid = str_repeat('a', 64);
        $meeting = $this->create_meeting([
            'integrationmode' => integration_mode::MANAGED,
            'calendarid' => 'primary',
            'googleeventid' => 'persistedevent1',
            'requestid' => $requestid,
            'meetinguri' => null,
            'timezone' => 'America/Sao_Paulo',
            'sendupdates' => 'all',
            'syncstatus' => sync_state::PENDING,
            'conferencestatus' => calendar_event_result::PENDING,
        ]);
        $client = new meeting_manager_calendar_client();
        $client->response = $this->calendar_success_response('persistedevent1', $requestid);
        $manager = new meeting_manager(
            null,
            null,
            new calendar_adapter(
                $client,
                new calendar_identity('https://moodle.example.test')
            )
        );

        $this->expectOutputString(
            get_string('syncmanagedready', 'mod_googlemeet', $meeting->id) . "\n"
        );
        $manager->process($meeting->id);
        $updated = (new sync_repository())->get($meeting->id);

        $this->assertSame(sync_state::READY, $updated->syncstatus);
        $this->assertSame('success', $updated->conferencestatus);
        $this->assertSame('https://meet.google.com/abc-defg-hij', $updated->meetinguri);
        $this->assertSame('https://meet.google.com/abc-defg-hij', $updated->url);
        $this->assertSame('abc-defg-hij', $updated->meetingcode);
        $this->assertSame(['get'], $client->operations);
    }

    /**
     * Google conference failure is persisted with a stable safe error.
     */
    public function test_managed_conference_failure_is_recorded(): void {
        $this->resetAfterTest();

        $meeting = $this->create_meeting([
            'integrationmode' => integration_mode::MANAGED,
            'calendarid' => 'primary',
            'meetinguri' => null,
            'timezone' => 'America/Sao_Paulo',
            'sendupdates' => 'none',
            'syncstatus' => sync_state::QUEUED,
        ]);
        $identity = new calendar_identity('https://moodle.example.test');
        $eventid = $identity->event_id((int) $meeting->id);
        $requestid = $identity->request_id((int) $meeting->id, $eventid);
        $client = new meeting_manager_calendar_client();
        $client->response = $this->calendar_response(
            $eventid,
            $requestid,
            calendar_event_result::FAILURE
        );

        $this->expectOutputString(
            get_string('syncmanagedfailed', 'mod_googlemeet', $meeting->id) . "\n"
        );
        (new meeting_manager(
            null,
            null,
            new calendar_adapter($client, $identity)
        ))->process($meeting->id);
        $updated = (new sync_repository())->get($meeting->id);

        $this->assertSame(sync_state::FAILED, $updated->syncstatus);
        $this->assertSame('failure', $updated->conferencestatus);
        $this->assertSame('conference_creation_failed', $updated->lasterrorcode);
    }

    /**
     * Invalid managed configuration is converted to a safe local failure.
     */
    public function test_managed_configuration_failure_is_recorded(): void {
        $this->resetAfterTest();

        $meeting = $this->create_meeting([
            'integrationmode' => integration_mode::MANAGED,
            'calendarid' => null,
            'meetinguri' => null,
            'timezone' => 'America/Sao_Paulo',
            'sendupdates' => 'none',
            'syncstatus' => sync_state::QUEUED,
        ]);
        $client = new meeting_manager_calendar_client();

        $this->expectOutputString(
            get_string('syncmanagedconfigurationfailed', 'mod_googlemeet', $meeting->id) . "\n"
        );
        (new meeting_manager(
            null,
            null,
            new calendar_adapter(
                $client,
                new calendar_identity('https://moodle.example.test')
            )
        ))->process($meeting->id);
        $updated = (new sync_repository())->get($meeting->id);

        $this->assertSame(sync_state::FAILED, $updated->syncstatus);
        $this->assertSame('calendar_configuration_invalid', $updated->lasterrorcode);
        $this->assertSame([], $client->operations);
    }

    /**
     * An inconsistent Calendar response is converted to a safe local failure.
     */
    public function test_managed_response_failure_is_recorded(): void {
        $this->resetAfterTest();

        $meeting = $this->create_meeting([
            'integrationmode' => integration_mode::MANAGED,
            'calendarid' => 'primary',
            'meetinguri' => null,
            'timezone' => 'America/Sao_Paulo',
            'sendupdates' => 'none',
            'syncstatus' => sync_state::QUEUED,
        ]);
        $identity = new calendar_identity('https://moodle.example.test');
        $eventid = $identity->event_id((int) $meeting->id);
        $requestid = $identity->request_id((int) $meeting->id, $eventid);
        $client = new meeting_manager_calendar_client();
        $client->response = $this->calendar_response(
            'anothercontroll1',
            $requestid,
            calendar_event_result::PENDING
        );

        $this->expectOutputString(
            get_string('syncmanagedresponsefailed', 'mod_googlemeet', $meeting->id) . "\n"
        );
        (new meeting_manager(
            null,
            null,
            new calendar_adapter($client, $identity)
        ))->process($meeting->id);
        $updated = (new sync_repository())->get($meeting->id);

        $this->assertSame(sync_state::FAILED, $updated->syncstatus);
        $this->assertSame('calendar_response_invalid', $updated->lasterrorcode);
    }

    /**
     * A transient transport failure can resume from the syncing state.
     */
    public function test_managed_transport_retry_resumes_syncing_state(): void {
        $this->resetAfterTest();

        $meeting = $this->create_meeting([
            'integrationmode' => integration_mode::MANAGED,
            'calendarid' => 'primary',
            'meetinguri' => null,
            'timezone' => 'America/Sao_Paulo',
            'sendupdates' => 'none',
            'syncstatus' => sync_state::QUEUED,
        ]);
        $identity = new calendar_identity('https://moodle.example.test');
        $client = new meeting_manager_calendar_client();
        $client->exception = new \RuntimeException('Transient transport failure');
        $manager = new meeting_manager(
            null,
            null,
            new calendar_adapter($client, $identity)
        );

        try {
            $manager->process($meeting->id);
            $this->fail('The transport failure must leave the task available for retry.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Transient transport failure', $e->getMessage());
        }
        $this->assertSame(sync_state::SYNCING, (new sync_repository())->get($meeting->id)->syncstatus);

        $eventid = $identity->event_id((int) $meeting->id);
        $requestid = $identity->request_id((int) $meeting->id, $eventid);
        $client->exception = null;
        $client->response = $this->calendar_response(
            $eventid,
            $requestid,
            calendar_event_result::PENDING
        );

        $this->expectOutputString(
            get_string('syncmanagedpending', 'mod_googlemeet', $meeting->id) . "\n"
        );
        $manager->process($meeting->id);
        $updated = (new sync_repository())->get($meeting->id);

        $this->assertSame(sync_state::PENDING, $updated->syncstatus);
        $this->assertSame(2, (int) $updated->syncattempts);
        $this->assertSame(['insert', 'insert'], $client->operations);
    }

    /**
     * Duplicate delivery is a no-op after the meeting has settled.
     */
    public function test_settled_meeting_is_not_processed_again(): void {
        $this->resetAfterTest();

        $meeting = $this->create_meeting([
            'integrationmode' => integration_mode::MANUAL,
            'syncstatus' => sync_state::READY,
        ]);

        $this->expectOutputString(
            get_string('syncstateskipped', 'mod_googlemeet', (object) [
                'id' => $meeting->id,
                'state' => sync_state::READY,
            ]) . "\n"
        );
        (new meeting_manager())->process($meeting->id);
        $updated = (new sync_repository())->get($meeting->id);

        $this->assertSame(sync_state::READY, $updated->syncstatus);
        $this->assertSame(0, (int) $updated->syncattempts);
    }

    /**
     * Queueing changes state, runs as the owner, and suppresses a duplicate task.
     */
    public function test_queue_is_owner_scoped_and_deduplicated(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $meeting = $this->create_meeting([
            'integrationmode' => integration_mode::MANUAL,
            'owneruserid' => $owner->id,
            'syncstatus' => sync_state::DRAFT,
        ]);
        $manager = new meeting_manager();

        $this->assertTrue($manager->queue($meeting->id));
        $this->assertFalse($manager->queue($meeting->id));
        $this->assertSame(sync_state::QUEUED, (new sync_repository())->get($meeting->id)->syncstatus);

        $tasks = \core\task\manager::get_adhoc_tasks(synchronise_meeting::class);
        $this->assertCount(1, $tasks);
        $task = reset($tasks);
        $this->assertInstanceOf(synchronise_meeting::class, $task);
        $this->assertSame((int) $owner->id, (int) $task->get_userid());
        $this->assertSame((int) $meeting->id, (int) $task->get_custom_data()->googlemeetid);
    }

    /**
     * A running duplicate cannot leave a settled activity queued without a new task.
     */
    public function test_duplicate_task_does_not_change_settled_state(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $meeting = $this->create_meeting([
            'integrationmode' => integration_mode::MANUAL,
            'owneruserid' => $owner->id,
            'syncstatus' => sync_state::READY,
        ]);

        $this->assertTrue(synchronise_meeting::enqueue($meeting->id, $owner->id));
        $this->assertFalse((new meeting_manager())->queue($meeting->id));
        $this->assertSame(sync_state::READY, (new sync_repository())->get($meeting->id)->syncstatus);
    }

    /**
     * An edit during a running attempt schedules reconciliation without rewinding state.
     */
    public function test_queue_from_syncing_keeps_running_state(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $meeting = $this->create_meeting([
            'integrationmode' => integration_mode::MANAGED,
            'owneruserid' => $owner->id,
            'syncstatus' => sync_state::SYNCING,
        ]);

        $this->assertTrue((new meeting_manager())->queue($meeting->id));
        $this->assertSame(sync_state::SYNCING, (new sync_repository())->get($meeting->id)->syncstatus);
    }

    /**
     * Creates an activity without calling Google.
     *
     * @param array<string, mixed> $fields Activity fields.
     * @return \stdClass
     */
    private function create_meeting(array $fields): \stdClass {
        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_googlemeet');

        return $generator->create_instance(['course' => $course->id] + $fields);
    }

    /**
     * Builds a Calendar response for one conference status.
     *
     * @param string $eventid Event ID.
     * @param string $requestid Conference request ID.
     * @param string $status Conference status.
     * @return array<string, mixed>
     */
    private function calendar_response(string $eventid, string $requestid, string $status): array {
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
    private function calendar_success_response(string $eventid, string $requestid): array {
        $response = $this->calendar_response($eventid, $requestid, calendar_event_result::SUCCESS);
        $response['conferenceData']['conferenceId'] = 'abc-defg-hij';
        $response['conferenceData']['entryPoints'] = [[
            'entryPointType' => 'video',
            'uri' => 'https://meet.google.com/abc-defg-hij',
            'meetingCode' => 'abc-defg-hij',
        ]];

        return $response;
    }
}
