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
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for meeting synchronization state transitions.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(sync_state::class)]
final class sync_state_test extends \basic_testcase {

    /**
     * Supported state transitions are accepted.
     *
     * @param string $from Current state.
     * @param string $to Requested state.
     */
    #[DataProvider('allowed_transition_provider')]
    public function test_allowed_transitions(string $from, string $to): void {
        $this->assertTrue(sync_state::can_transition($from, $to));
        sync_state::assert_transition($from, $to);
        $this->addToAssertionCount(1);
    }

    /**
     * Repeating the current state is accepted for idempotent workers.
     */
    public function test_idempotent_transitions_are_allowed(): void {
        foreach (sync_state::all() as $state) {
            $this->assertTrue(sync_state::can_transition($state, $state));
            sync_state::assert_transition($state, $state);
        }
    }

    /**
     * A direct ready-to-pending change would bypass a queued synchronization.
     */
    public function test_forbidden_transition_is_rejected(): void {
        $this->assertFalse(sync_state::can_transition(sync_state::READY, sync_state::PENDING));
        $this->expectException(\coding_exception::class);
        sync_state::assert_transition(sync_state::READY, sync_state::PENDING);
    }

    /**
     * Unknown stored values are rejected.
     */
    public function test_unknown_state_is_rejected(): void {
        $this->assertFalse(sync_state::can_transition('unknown', sync_state::READY));
        $this->expectException(\coding_exception::class);
        sync_state::assert_transition('unknown', sync_state::READY);
    }

    /**
     * Settled states do not require an active synchronization worker.
     */
    public function test_settled_states(): void {
        $this->assertTrue(sync_state::is_settled(sync_state::READY));
        $this->assertTrue(sync_state::is_settled(sync_state::CANCELLED));
        $this->assertTrue(sync_state::is_settled(sync_state::DISCONNECTED));
        $this->assertFalse(sync_state::is_settled(sync_state::QUEUED));
        $this->assertFalse(sync_state::is_settled(sync_state::SYNCING));
    }

    /**
     * Provides representative allowed transitions.
     *
     * @return array<string, array{string, string}>
     */
    public static function allowed_transition_provider(): array {
        return [
            'create' => [sync_state::DRAFT, sync_state::QUEUED],
            'start worker' => [sync_state::QUEUED, sync_state::SYNCING],
            'conference pending' => [sync_state::SYNCING, sync_state::PENDING],
            'poll pending conference' => [sync_state::PENDING, sync_state::SYNCING],
            'complete' => [sync_state::SYNCING, sync_state::READY],
            'retry' => [sync_state::FAILED, sync_state::QUEUED],
            'request cancellation' => [sync_state::READY, sync_state::CANCELLING],
            'complete cancellation' => [sync_state::CANCELLING, sync_state::CANCELLED],
            'reconnect' => [sync_state::DISCONNECTED, sync_state::QUEUED],
        ];
    }
}
