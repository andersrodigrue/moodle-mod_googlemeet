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

namespace mod_googlemeet;

require_once(__DIR__ . '/../lib.php');

use PHPUnit\Framework\Attributes\CoversFunction;

/**
 * Tests for activity module callbacks.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversFunction('googlemeet_supports')]
final class lib_test extends \basic_testcase {

    /**
     * Google Meet is presented as a communication activity in Moodle 5.2.
     */
    public function test_activity_purpose_is_communication(): void {
        $this->assertSame(
            MOD_PURPOSE_COMMUNICATION,
            \googlemeet_supports(FEATURE_MOD_PURPOSE)
        );
    }

    /**
     * Moodle can use the standard completion-on-view rule.
     */
    public function test_tracks_completion_by_view(): void {
        $this->assertTrue(\googlemeet_supports(FEATURE_COMPLETION_TRACKS_VIEWS));
    }
}
