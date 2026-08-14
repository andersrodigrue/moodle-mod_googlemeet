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
 * Tests for durable cleanup locking.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(remote_cleanup_lock::class)]
final class remote_cleanup_lock_test extends \advanced_testcase {

    /**
     * Cleanup lock names are stable and record-scoped.
     */
    public function test_resource_name_contains_cleanup_id(): void {
        $this->assertSame('cleanup:42', remote_cleanup_lock::resource_name(42));
    }

    /**
     * Lock release is guaranteed when protected work fails.
     */
    public function test_lock_is_released_after_exception(): void {
        $factory = \core\lock\lock_config::get_lock_factory(remote_cleanup_lock::TYPE);
        $coordinator = new remote_cleanup_lock($factory, 0);

        try {
            $coordinator->with_lock(101, static function (): void {
                throw new \RuntimeException('Expected cleanup failure.');
            });
            $this->fail('The callback exception was not propagated.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Expected cleanup failure.', $exception->getMessage());
        }

        $lock = $factory->get_lock(remote_cleanup_lock::resource_name(101), 0);
        $this->assertNotFalse($lock);
        $lock->release();
    }
}
