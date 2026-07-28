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

use mod_googlemeet\api\recording_api_exception;
use mod_googlemeet\api\recording_authorization_exception;
use mod_googlemeet\api\recording_client;
use mod_googlemeet\api\recording_transport_exception;
use mod_googlemeet\task\discover_recordings;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Configurable recording client used by manager tests.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class recording_manager_client implements recording_client {

    /** @var \Throwable|null Failure thrown by conference discovery. */
    public ?\Throwable $exception = null;

    /** @var array<string, mixed> Conference response. */
    public array $response = ['conferenceRecords' => []];

    /**
     * @param string $meetingcode Exact normalized meeting code.
     * @param string|null $pagetoken Continuation token.
     * @return array<string, mixed>
     */
    public function list_conference_records(string $meetingcode, ?string $pagetoken = null): array {
        if ($this->exception !== null) {
            throw $this->exception;
        }
        return $this->response;
    }

    /**
     * @param string $conferencerecord Conference resource.
     * @param string|null $pagetoken Continuation token.
     * @return array<string, mixed>
     */
    public function list_recordings(string $conferencerecord, ?string $pagetoken = null): array {
        return ['recordings' => []];
    }
}

/**
 * Tests for owner-scoped recording discovery coordination.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(recording_manager::class)]
final class recording_manager_test extends \advanced_testcase {

    /**
     * Claiming queues one owner-scoped task and suppresses its duplicate.
     */
    public function test_claim_and_queue_is_owner_scoped_and_deduplicated(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $meeting = $this->create_meeting();
        $manager = new recording_manager();

        $this->assertTrue($manager->claim_and_queue($meeting->id, $owner->id, 17));
        $this->assertFalse($manager->claim_and_queue($meeting->id, $owner->id, 17));

        $stored = (new recording_repository())->get($meeting->id);
        $this->assertSame(recording_sync_state::QUEUED, $stored->recordingsyncstatus);
        $this->assertSame((int) $owner->id, (int) $stored->recordingowneruserid);
        $this->assertSame(17, (int) $stored->recordingoauthissuerid);

        $tasks = \core\task\manager::get_adhoc_tasks(discover_recordings::class);
        $this->assertCount(1, $tasks);
        $task = reset($tasks);
        $this->assertSame((int) $owner->id, (int) $task->get_userid());
        $this->assertSame((int) $meeting->id, (int) $task->get_custom_data()->googlemeetid);
    }

    /**
     * A pending duplicate cannot leave a settled activity queued without a task.
     */
    public function test_duplicate_task_does_not_rewind_settled_state(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $meeting = $this->create_meeting([
            'recordingowneruserid' => $owner->id,
            'recordingoauthissuerid' => 17,
            'recordingsyncstatus' => recording_sync_state::READY,
        ]);

        $this->assertTrue(discover_recordings::enqueue($meeting->id, $owner->id));
        $this->assertFalse((new recording_manager())->queue($meeting->id, $owner->id));
        $this->assertSame(
            recording_sync_state::READY,
            (new recording_repository())->get($meeting->id)->recordingsyncstatus
        );
    }

    /**
     * A complete empty snapshot is successful and does not imply deletion.
     */
    public function test_process_marks_complete_snapshot_ready(): void {
        $this->resetAfterTest();
        $meeting = $this->create_meeting([
            'recordingsyncstatus' => recording_sync_state::QUEUED,
        ]);
        $client = new recording_manager_client();
        $manager = new recording_manager(
            discovery: new recording_discovery($client)
        );

        $this->expectOutputString(
            get_string('recordingssynced', 'mod_googlemeet', (object) [
                'id' => $meeting->id,
                'count' => 0,
            ]) . "\n"
        );
        $manager->process($meeting->id);

        $updated = (new recording_repository())->get($meeting->id);
        $this->assertSame(recording_sync_state::READY, $updated->recordingsyncstatus);
        $this->assertSame(1, (int) $updated->recordingsyncattempts);
        $this->assertGreaterThan(0, (int) $updated->lastsync);
    }

    /**
     * Revoked authorization moves discovery back to an explicit reconnect state.
     */
    public function test_authorization_failure_disconnects_safely(): void {
        $this->resetAfterTest();
        $meeting = $this->create_meeting([
            'recordingsyncstatus' => recording_sync_state::QUEUED,
        ]);
        $client = new recording_manager_client();
        $client->exception = new recording_authorization_exception('Sensitive OAuth detail');

        (new recording_manager(
            discovery: new recording_discovery($client)
        ))->process($meeting->id);

        $updated = (new recording_repository())->get($meeting->id);
        $this->assertSame(recording_sync_state::DISCONNECTED, $updated->recordingsyncstatus);
        $this->assertSame('recording_authorization_required', $updated->recordinglasterrorcode);
        $this->assertStringNotContainsString(
            'Sensitive OAuth detail',
            $updated->recordinglasterrormessage
        );
    }

    /**
     * A permanent API rejection is stored under its non-secret code.
     */
    public function test_permanent_api_failure_is_safely_recorded(): void {
        $this->resetAfterTest();
        $meeting = $this->create_meeting([
            'recordingsyncstatus' => recording_sync_state::QUEUED,
        ]);
        $client = new recording_manager_client();
        $client->exception = new recording_api_exception('recording_api_http_400');

        (new recording_manager(
            discovery: new recording_discovery($client)
        ))->process($meeting->id);

        $updated = (new recording_repository())->get($meeting->id);
        $this->assertSame(recording_sync_state::FAILED, $updated->recordingsyncstatus);
        $this->assertSame('recording_api_http_400', $updated->recordinglasterrorcode);
    }

    /**
     * A transient transport failure escapes so Moodle can retry the same task.
     */
    public function test_transient_failure_remains_retryable(): void {
        $this->resetAfterTest();
        $meeting = $this->create_meeting([
            'recordingsyncstatus' => recording_sync_state::QUEUED,
        ]);
        $client = new recording_manager_client();
        $client->exception = new recording_transport_exception('Temporary outage');
        $manager = new recording_manager(
            discovery: new recording_discovery($client)
        );

        try {
            $manager->process($meeting->id);
            $this->fail('A transient recording outage was swallowed.');
        } catch (recording_transport_exception $e) {
            $this->assertSame('Temporary outage', $e->getMessage());
        }

        $updated = (new recording_repository())->get($meeting->id);
        $this->assertSame(recording_sync_state::SYNCING, $updated->recordingsyncstatus);
        $this->assertSame(1, (int) $updated->recordingsyncattempts);
    }

    /**
     * Creates an activity without contacting Google.
     *
     * @param array<string, mixed> $fields Activity fields.
     * @return \stdClass
     */
    private function create_meeting(array $fields = []): \stdClass {
        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_googlemeet');
        return $generator->create_instance(['course' => $course->id] + $fields);
    }
}
