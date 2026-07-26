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
 * Tests for per-activity locking.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(meeting_lock::class)]
final class meeting_lock_test extends \advanced_testcase {

    /**
     * The resource name is stable and scoped to one meeting.
     */
    public function test_resource_name_contains_meeting_id(): void {
        $this->assertSame('meeting:42', meeting_lock::resource_name(42));
    }

    /**
     * A lock is released when protected work throws.
     */
    public function test_lock_is_released_after_exception(): void {
        $factory = \core\lock\lock_config::get_lock_factory(meeting_lock::TYPE);
        $coordinator = new meeting_lock($factory, 0);

        try {
            $coordinator->with_lock(101, static function (): void {
                throw new \RuntimeException('Expected callback failure.');
            });
            $this->fail('The callback exception was not propagated.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Expected callback failure.', $exception->getMessage());
        }

        $lock = $factory->get_lock(meeting_lock::resource_name(101), 0);
        $this->assertNotFalse($lock);
        $lock->release();
    }

    /**
     * Concurrent work for the same activity is rejected after the timeout.
     */
    public function test_held_lock_prevents_concurrent_work(): void {
        $factory = \core\lock\lock_config::get_lock_factory(meeting_lock::TYPE);
        $heldlock = $factory->get_lock(meeting_lock::resource_name(202), 0);
        $this->assertNotFalse($heldlock);

        try {
            $this->expectException(\moodle_exception::class);
            $this->expectExceptionMessage(get_string('synclocktimeout', 'mod_googlemeet', 202));
            (new meeting_lock($factory, 0))->with_lock(202, static function (): void {
                throw new \coding_exception('This callback must not run.');
            });
        } finally {
            $heldlock->release();
        }
    }
}
