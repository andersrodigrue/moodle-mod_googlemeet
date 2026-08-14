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
 * Tests for durable remote cleanup storage.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(remote_cleanup_repository::class)]
final class remote_cleanup_repository_test extends \advanced_testcase {

    /**
     * Only an existing, non-cancelled managed Calendar event creates an obligation.
     */
    public function test_capture_requires_actionable_remote_event(): void {
        $this->resetAfterTest();
        $repository = $this->repository(1000);

        $this->assertNull($repository->capture($this->meeting([
            'integrationmode' => integration_mode::MANUAL,
        ])));
        $this->assertNull($repository->capture($this->meeting([
            'googleeventid' => null,
        ])));
        $this->assertNull($repository->capture($this->meeting([
            'syncstatus' => sync_state::CANCELLED,
        ])));
    }

    /**
     * A lost insert response is recoverable through the controlled event identity.
     */
    public function test_capture_derives_event_id_after_ambiguous_insert(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $meeting = $this->meeting([
            'id' => 47,
            'owneruserid' => $owner->id,
            'googleeventid' => null,
            'syncstatus' => sync_state::SYNCING,
        ]);

        $cleanup = $this->repository(1000)->capture($meeting);

        $this->assertNotNull($cleanup);
        $this->assertSame(
            (new \mod_googlemeet\api\calendar_identity())->event_id(47),
            $cleanup->googleeventid
        );
    }

    /**
     * Capture is idempotent and retains only the minimum cancellation metadata.
     */
    public function test_capture_is_idempotent(): void {
        global $DB;

        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $repository = $this->repository(1234);
        $meeting = $this->meeting([
            'owneruserid' => $owner->id,
            'guestcount' => 3,
        ]);

        $first = $repository->capture($meeting);
        $second = $repository->capture($meeting);
        $otherowner = $this->getDataGenerator()->create_user();
        $other = $repository->capture($this->meeting([
            'owneruserid' => $otherowner->id,
            'googleeventid' => $meeting->googleeventid,
        ]));

        $this->assertNotNull($first);
        $this->assertSame((int) $first->id, (int) $second->id);
        $this->assertNotSame((int) $first->id, (int) $other->id);
        $this->assertSame(remote_cleanup_repository::PENDING, $first->status);
        $this->assertSame((int) $owner->id, (int) $first->owneruserid);
        $this->assertSame('primary', $first->calendarid);
        $this->assertSame('event123', $first->googleeventid);
        $this->assertSame(3, (int) $first->guestcount);
        $this->assertSame(1234, (int) $first->timecreated);
        $this->assertSame(2, $DB->count_records('googlemeet_remote_cleanup'));
    }

    /**
     * Missing ownership metadata is retained for intervention instead of discarded.
     */
    public function test_capture_preserves_unactionable_obligation_as_blocked(): void {
        $this->resetAfterTest();

        $cleanup = $this->repository(1000)->capture($this->meeting([
            'owneruserid' => null,
            'oauthissuerid' => null,
            'calendarid' => null,
        ]));

        $this->assertNotNull($cleanup);
        $this->assertSame(remote_cleanup_repository::BLOCKED, $cleanup->status);
        $this->assertSame('cleanup_metadata_missing', $cleanup->lasterrorcode);
        $this->assertNull($cleanup->timenextattempt);
    }

    /**
     * Reconciliation returns pending, abandoned and retry-due records only.
     */
    public function test_due_candidates_are_bounded_and_owner_safe(): void {
        global $DB;

        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $deletedowner = $this->getDataGenerator()->create_user(['deleted' => 1]);
        $repository = $this->repository(2000);

        $pending = $repository->capture($this->meeting([
            'owneruserid' => $owner->id,
            'googleeventid' => 'event101',
        ]));
        $stale = $repository->capture($this->meeting([
            'owneruserid' => $owner->id,
            'googleeventid' => 'event102',
        ]));
        $recent = $repository->capture($this->meeting([
            'owneruserid' => $owner->id,
            'googleeventid' => 'event103',
        ]));
        $due = $repository->capture($this->meeting([
            'owneruserid' => $owner->id,
            'googleeventid' => 'event104',
        ]));
        $future = $repository->capture($this->meeting([
            'owneruserid' => $owner->id,
            'googleeventid' => 'event105',
        ]));
        $deleted = $repository->capture($this->meeting([
            'owneruserid' => $deletedowner->id,
            'googleeventid' => 'event106',
        ]));

        $repository->start_attempt((int) $stale->id);
        $repository->start_attempt((int) $recent->id);
        $repository->mark_blocked((int) $due->id, 'authorization_required', 1);
        $repository->mark_blocked((int) $future->id, 'authorization_required', 1000);
        $DB->set_field('googlemeet_remote_cleanup', 'timelastattempt', 1000, ['id' => $stale->id]);
        $DB->set_field('googlemeet_remote_cleanup', 'timelastattempt', 1950, ['id' => $recent->id]);
        $DB->set_field('googlemeet_remote_cleanup', 'timenextattempt', 1900, ['id' => $due->id]);

        $ids = array_map(
            static fn(\stdClass $record): int => (int) $record->id,
            $repository->get_due_candidates(2000, 1500)
        );

        $this->assertEqualsCanonicalizing([(int) $pending->id, (int) $stale->id, (int) $due->id], $ids);
        $this->assertNotContains((int) $recent->id, $ids);
        $this->assertNotContains((int) $future->id, $ids);
        $this->assertNotContains((int) $deleted->id, $ids);
    }

    /**
     * Completion removes the tombstone only after the remote contract settles.
     */
    public function test_complete_removes_obligation(): void {
        global $DB;

        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $repository = $this->repository(1000);
        $cleanup = $repository->capture($this->meeting(['owneruserid' => $owner->id]));

        $repository->complete((int) $cleanup->id);

        $this->assertFalse($DB->record_exists('googlemeet_remote_cleanup', ['id' => $cleanup->id]));
    }

    /**
     * Creates a repository with a deterministic clock.
     *
     * @param int $now Timestamp returned by the clock.
     * @return remote_cleanup_repository
     */
    private function repository(int $now): remote_cleanup_repository {
        $clock = $this->createMock(\core\clock::class);
        $clock->method('time')->willReturn($now);
        return new remote_cleanup_repository($clock);
    }

    /**
     * Returns a minimal activity-shaped record.
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
