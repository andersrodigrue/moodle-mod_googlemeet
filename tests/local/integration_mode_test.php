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
 * Tests for meeting integration modes.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(integration_mode::class)]
final class integration_mode_test extends \basic_testcase {

    /**
     * Every supported mode is exposed and accepted.
     */
    public function test_supported_modes_are_valid(): void {
        $this->assertSame([
            integration_mode::MANUAL,
            integration_mode::LEGACY,
            integration_mode::MANAGED,
        ], integration_mode::all());

        foreach (integration_mode::all() as $mode) {
            $this->assertTrue(integration_mode::is_valid($mode));
        }

        $this->assertFalse(integration_mode::is_valid('automatic'));
    }
}
