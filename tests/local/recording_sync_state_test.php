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
 * Tests for recording discovery state transitions.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(recording_sync_state::class)]
final class recording_sync_state_test extends \basic_testcase {

    /**
     * Normal queue, worker and completion transitions are accepted.
     */
    public function test_discovery_lifecycle_is_allowed(): void {
        recording_sync_state::assert_transition(
            recording_sync_state::DISCONNECTED,
            recording_sync_state::QUEUED
        );
        recording_sync_state::assert_transition(
            recording_sync_state::QUEUED,
            recording_sync_state::SYNCING
        );
        recording_sync_state::assert_transition(
            recording_sync_state::SYNCING,
            recording_sync_state::READY
        );
        recording_sync_state::assert_transition(
            recording_sync_state::READY,
            recording_sync_state::QUEUED
        );
        $this->addToAssertionCount(4);
    }

    /**
     * Direct completion without a worker is rejected.
     */
    public function test_invalid_transition_is_rejected(): void {
        $this->expectException(\coding_exception::class);
        recording_sync_state::assert_transition(
            recording_sync_state::DISCONNECTED,
            recording_sync_state::READY
        );
    }
}
