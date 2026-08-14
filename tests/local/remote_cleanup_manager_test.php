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
use mod_googlemeet\api\calendar_authorization_exception;
use mod_googlemeet\api\calendar_client;
use mod_googlemeet\api\calendar_transport_exception;
use mod_googlemeet\task\cancel_deleted_meeting;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');

/**
 * Calendar transport used by durable cleanup manager tests.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class remote_cleanup_manager_client implements calendar_client {

    /** @var array<int, array<string, mixed>> Recorded DELETE calls. */
    public array $deletes = [];

    /** @var \RuntimeException|null Failure emitted by DELETE. */
    public ?\RuntimeException $exception = null;

    /** @inheritDoc */
    public function insert_event(string $calendarid, array $event, array $parameters): array {
        throw new \coding_exception('Unexpected Calendar insert.');
    }

    /** @inheritDoc */
    public function patch_event(
        string $calendarid,
        string $eventid,
        array $event,
        array $parameters
    ): array {
        throw new \coding_exception('Unexpected Calendar patch.');
    }

    /** @inheritDoc */
    public function get_event(string $calendarid, string $eventid): array {
        throw new \coding_exception('Unexpected Calendar get.');
    }

    /** @inheritDoc */
    public function delete_event(string $calendarid, string $eventid, array $parameters): void {
        $this->deletes[] = compact('calendarid', 'eventid', 'parameters');
        if ($this->exception !== null) {
            throw $this->exception;
        }
    }
}

/**
 * Deterministic Calendar adapter provider for cleanup tests.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class remote_cleanup_manager_provider implements calendar_adapter_provider {

    /** @var calendar_adapter Adapter returned to the manager. */
    private calendar_adapter $adapter;

    /**
     * @param calendar_adapter $adapter Adapter returned to the manager.
     */
    public function __construct(calendar_adapter $adapter) {
        $this->adapter = $adapter;
    }

    /** @inheritDoc */
    public function create(\stdClass $meeting): calendar_adapter {
        return $this->adapter;
    }
}

/**
 * Adapter provider that exposes OAuth composition failures.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class remote_cleanup_manager_failing_provider implements calendar_adapter_provider {

    /** @var \RuntimeException Composition failure. */
    private \RuntimeException $exception;

    /**
     * @param \RuntimeException $exception Composition failure.
     */
    public function __construct(\RuntimeException $exception) {
        $this->exception = $exception;
    }

    /** @inheritDoc */
    public function create(\stdClass $meeting): calendar_adapter {
        throw $this->exception;
    }
}

/**
 * Tests for post-deletion remote Calendar cancellation.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(remote_cleanup_manager::class)]
final class remote_cleanup_manager_test extends \advanced_testcase {

    /**
     * Capture queues one owner-scoped idempotent worker.
     */
    public function test_capture_queues_owner_scoped_worker_once(): void {
        global $DB;

        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $manager = new remote_cleanup_manager();
        $meeting = $this->meeting(['owneruserid' => $owner->id]);

        $firstid = $manager->capture_and_queue($meeting);
        $secondid = $manager->capture_and_queue($meeting);

        $this->assertSame($firstid, $secondid);
        $this->assertSame(1, $DB->count_records('googlemeet_remote_cleanup'));
        $tasks = \core\task\manager::get_adhoc_tasks(cancel_deleted_meeting::class);
        $this->assertCount(1, $tasks);
        $this->assertSame((int) $owner->id, (int) $tasks[0]->get_userid());
        $this->assertSame($firstid, (int) $tasks[0]->get_custom_data()->cleanupid);
    }

    /**
     * Successful remote deletion removes the obligation and notifies known guests.
     */
    public function test_process_completes_idempotent_remote_deletion(): void {
        global $DB;

        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $repository = new remote_cleanup_repository();
        $cleanup = $repository->capture($this->meeting([
            'owneruserid' => $owner->id,
            'guestcount' => 2,
        ]));
        $client = new remote_cleanup_manager_client();
        $manager = new remote_cleanup_manager(
            repository: $repository,
            adapterprovider: new remote_cleanup_manager_provider(new calendar_adapter($client))
        );

        $this->expectOutputString(
            get_string('remotecleanupcompleted', 'mod_googlemeet', $cleanup->id) . "\n"
        );
        $manager->process((int) $cleanup->id);

        $this->assertFalse($DB->record_exists('googlemeet_remote_cleanup', ['id' => $cleanup->id]));
        $this->assertSame([[
            'calendarid' => 'primary',
            'eventid' => 'event123',
            'parameters' => ['sendUpdates' => 'all'],
        ]], $client->deletes);
    }

    /**
     * Transport ambiguity is retained in processing state and propagated to Moodle retry handling.
     */
    public function test_transport_failure_is_durable_and_rethrown(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $repository = new remote_cleanup_repository();
        $cleanup = $repository->capture($this->meeting(['owneruserid' => $owner->id]));
        $client = new remote_cleanup_manager_client();
        $client->exception = new calendar_transport_exception('Temporary transport failure.');
        $manager = new remote_cleanup_manager(
            repository: $repository,
            adapterprovider: new remote_cleanup_manager_provider(new calendar_adapter($client))
        );

        try {
            $manager->process((int) $cleanup->id);
            $this->fail('The transport exception was not propagated.');
        } catch (calendar_transport_exception $exception) {
            $this->assertSame('Temporary transport failure.', $exception->getMessage());
        }

        $updated = $repository->get((int) $cleanup->id);
        $this->assertSame(remote_cleanup_repository::PROCESSING, $updated->status);
        $this->assertSame(1, (int) $updated->attempts);
        $this->assertSame('calendar_transport_failure', $updated->lasterrorcode);
    }

    /**
     * Missing authorization blocks but preserves the identifiers for daily recovery.
     */
    public function test_authorization_failure_is_retained_for_recovery(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $repository = new remote_cleanup_repository();
        $cleanup = $repository->capture($this->meeting(['owneruserid' => $owner->id]));
        $manager = new remote_cleanup_manager(
            repository: $repository,
            adapterprovider: new remote_cleanup_manager_failing_provider(
                new calendar_authorization_exception('Reconnect Google Calendar.')
            )
        );
        $before = time();

        $manager->process((int) $cleanup->id);

        $updated = $repository->get((int) $cleanup->id);
        $this->assertSame(remote_cleanup_repository::BLOCKED, $updated->status);
        $this->assertSame('authorization_required', $updated->lasterrorcode);
        $this->assertGreaterThanOrEqual($before + DAYSECS, (int) $updated->timenextattempt);
    }

    /**
     * Deleting an activity commits its tombstone and owner task after local data disappears.
     */
    public function test_activity_deletion_preserves_remote_cleanup(): void {
        global $DB;

        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $meeting = $this->create_activity($course, $owner, 'event201');

        course_delete_module((int) $meeting->cmid);

        $this->assertFalse($DB->record_exists('googlemeet', ['id' => $meeting->id]));
        $cleanup = $DB->get_record('googlemeet_remote_cleanup', [
            'googleeventid' => 'event201',
        ], '*', MUST_EXIST);
        $tasks = \core\task\manager::get_adhoc_tasks(cancel_deleted_meeting::class);
        $this->assertCount(1, $tasks);
        $this->assertSame((int) $cleanup->id, (int) $tasks[0]->get_custom_data()->cleanupid);
    }

    /**
     * Course deletion uses the same durable activity deletion boundary.
     */
    public function test_course_deletion_preserves_every_remote_cleanup(): void {
        global $DB;

        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $this->create_activity($course, $owner, 'event301');
        $this->create_activity($course, $owner, 'event302');

        delete_course($course, false);

        $this->assertFalse($DB->record_exists('googlemeet', ['course' => $course->id]));
        $this->assertSame(2, $DB->count_records('googlemeet_remote_cleanup'));
        $this->assertCount(2, \core\task\manager::get_adhoc_tasks(cancel_deleted_meeting::class));
    }

    /**
     * Creates a durable activity record.
     *
     * @param \stdClass $course Course.
     * @param \stdClass $owner Calendar owner.
     * @param string $eventid Remote event ID.
     * @return \stdClass
     */
    private function create_activity(\stdClass $course, \stdClass $owner, string $eventid): \stdClass {
        return $this->getDataGenerator()
            ->get_plugin_generator('mod_googlemeet')
            ->create_instance([
                'course' => $course->id,
                'integrationmode' => integration_mode::MANAGED,
                'owneruserid' => $owner->id,
                'oauthissuerid' => 11,
                'calendarid' => 'primary',
                'googleeventid' => $eventid,
                'syncstatus' => sync_state::READY,
            ]);
    }

    /**
     * Returns a minimal managed meeting record.
     *
     * @param array<string, mixed> $overrides Field overrides.
     * @return \stdClass
     */
    private function meeting(array $overrides = []): \stdClass {
        return (object) ($overrides + [
            'integrationmode' => integration_mode::MANAGED,
            'owneruserid' => 1,
            'oauthissuerid' => 11,
            'calendarid' => 'primary',
            'googleeventid' => 'event123',
            'guestcount' => 0,
            'syncstatus' => sync_state::READY,
        ]);
    }
}
