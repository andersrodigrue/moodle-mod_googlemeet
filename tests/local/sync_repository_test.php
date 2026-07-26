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

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for synchronization persistence.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(sync_repository::class)]
final class sync_repository_test extends \advanced_testcase {

    /**
     * A valid transition is persisted.
     */
    public function test_transition_updates_sync_state(): void {
        $this->resetAfterTest();

        $meeting = $this->create_meeting([
            'syncstatus' => sync_state::DRAFT,
        ]);

        $updated = (new sync_repository())->transition($meeting->id, sync_state::QUEUED);

        $this->assertSame(sync_state::QUEUED, $updated->syncstatus);
        $this->assertSame($meeting->id, $updated->id);
    }

    /**
     * An invalid transition does not alter the stored state.
     */
    public function test_invalid_transition_is_not_persisted(): void {
        $this->resetAfterTest();

        $meeting = $this->create_meeting([
            'syncstatus' => sync_state::READY,
        ]);
        $repository = new sync_repository();

        try {
            $repository->transition($meeting->id, sync_state::PENDING);
            $this->fail('An invalid synchronization transition was accepted.');
        } catch (\coding_exception $exception) {
            $this->assertStringContainsString('Invalid Google Meet synchronization transition', $exception->getMessage());
        }

        $this->assertSame(sync_state::READY, $repository->get($meeting->id)->syncstatus);
    }

    /**
     * Starting an attempt increments the counter and clears the old error.
     */
    public function test_start_attempt_updates_observability_fields(): void {
        global $DB;

        $this->resetAfterTest();

        $meeting = $this->create_meeting([
            'syncstatus' => sync_state::QUEUED,
            'syncattempts' => 2,
            'lasterrorcode' => 'old_error',
            'lasterrormessage' => 'Old safe error',
        ]);
        $before = time();

        $updated = (new sync_repository())->start_attempt($meeting->id);

        $this->assertSame(sync_state::SYNCING, $updated->syncstatus);
        $this->assertSame(3, (int) $updated->syncattempts);
        $this->assertGreaterThanOrEqual($before, (int) $updated->timelastattempt);
        $this->assertNull($updated->lasterrorcode);
        $this->assertNull($updated->lasterrormessage);
        $this->assertSame(
            sync_state::SYNCING,
            $DB->get_field('googlemeet', 'syncstatus', ['id' => $meeting->id])
        );
    }

    /**
     * Cancellation attempts remain cancelling and clear join metadata on success.
     */
    public function test_cancellation_attempt_and_completion_are_observable(): void {
        $this->resetAfterTest();

        $meeting = $this->create_meeting([
            'syncstatus' => sync_state::CANCELLING,
            'syncattempts' => 2,
            'meetinguri' => 'https://meet.google.com/abc-defg-hij',
            'url' => 'https://meet.google.com/abc-defg-hij',
            'googleeventid' => 'event123',
        ]);
        $repository = new sync_repository();

        $attempt = $repository->start_cancellation_attempt($meeting->id);
        $this->assertSame(sync_state::CANCELLING, $attempt->syncstatus);
        $this->assertSame(3, (int) $attempt->syncattempts);

        $cancelled = $repository->mark_cancelled($meeting->id);
        $this->assertSame(sync_state::CANCELLED, $cancelled->syncstatus);
        $this->assertNull($cancelled->meetinguri);
        $this->assertSame('', $cancelled->url);
        $this->assertSame('event123', $cancelled->googleeventid);
    }

    /**
     * A cancellation failure retains its operation intent for a safe retry.
     */
    public function test_cancellation_failure_keeps_cancelling_state(): void {
        $this->resetAfterTest();

        $meeting = $this->create_meeting([
            'syncstatus' => sync_state::CANCELLING,
        ]);

        $blocked = (new sync_repository())->mark_cancellation_blocked(
            $meeting->id,
            'authorization_required',
            'Reconnect safely'
        );

        $this->assertSame(sync_state::CANCELLING, $blocked->syncstatus);
        $this->assertSame('authorization_required', $blocked->lasterrorcode);
        $this->assertSame('Reconnect safely', $blocked->lasterrormessage);
    }

    /**
     * Failure details are sanitized and bounded before persistence.
     */
    public function test_failure_details_are_sanitized(): void {
        $this->resetAfterTest();

        $meeting = $this->create_meeting([
            'syncstatus' => sync_state::QUEUED,
        ]);
        $repository = new sync_repository();
        $repository->start_attempt($meeting->id);

        $updated = $repository->mark_failed(
            $meeting->id,
            'adapter unavailable!',
            '<b>Calendar adapter unavailable</b>' . str_repeat('x', 2500)
        );

        $this->assertSame(sync_state::FAILED, $updated->syncstatus);
        $this->assertNotEmpty($updated->lasterrorcode);
        $this->assertLessThanOrEqual(100, \core_text::strlen($updated->lasterrorcode));
        $this->assertLessThanOrEqual(2000, \core_text::strlen($updated->lasterrormessage));
        $this->assertStringNotContainsString('<b>', $updated->lasterrormessage);
    }

    /**
     * Only old owner-scoped pending and syncing meetings are reconciled.
     */
    public function test_get_reconciliation_candidates_filters_state_age_and_owner(): void {
        global $DB;

        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $now = time();
        $oldpending = $this->create_meeting([
            'owneruserid' => $owner->id,
            'syncstatus' => sync_state::PENDING,
            'timelastattempt' => $now - 600,
        ]);
        $oldsyncing = $this->create_meeting([
            'owneruserid' => $owner->id,
            'syncstatus' => sync_state::SYNCING,
            'timelastattempt' => $now - 3600,
        ]);
        $recentpending = $this->create_meeting([
            'owneruserid' => $owner->id,
            'syncstatus' => sync_state::PENDING,
            'timelastattempt' => $now,
        ]);
        $noowner = $this->create_meeting([
            'syncstatus' => sync_state::PENDING,
            'timelastattempt' => $now - 600,
        ]);
        $deletedowner = $this->getDataGenerator()->create_user();
        $deletedownermeeting = $this->create_meeting([
            'owneruserid' => $deletedowner->id,
            'syncstatus' => sync_state::PENDING,
            'timelastattempt' => $now - 600,
        ]);
        $DB->set_field('user', 'deleted', 1, ['id' => $deletedowner->id]);
        $ready = $this->create_meeting([
            'owneruserid' => $owner->id,
            'syncstatus' => sync_state::READY,
            'timelastattempt' => $now - 3600,
        ]);

        $candidates = (new sync_repository())->get_reconciliation_candidates(
            $now - 300,
            $now - 1800
        );

        $this->assertEqualsCanonicalizing(
            [$oldpending->id, $oldsyncing->id],
            array_map(static fn(\stdClass $meeting): int => (int) $meeting->id, $candidates)
        );
        $this->assertNotContains($recentpending->id, array_column($candidates, 'id'));
        $this->assertNotContains($noowner->id, array_column($candidates, 'id'));
        $this->assertNotContains($deletedownermeeting->id, array_column($candidates, 'id'));
        $this->assertNotContains($ready->id, array_column($candidates, 'id'));
        $this->assertTrue($DB->record_exists('googlemeet', ['id' => $ready->id]));
    }

    /**
     * Reconciliation batches are explicitly bounded.
     */
    public function test_reconciliation_candidates_validate_batch_limit(): void {
        $this->expectException(\coding_exception::class);
        (new sync_repository())->get_reconciliation_candidates(time(), time(), 501);
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
}
