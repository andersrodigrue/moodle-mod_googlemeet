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

namespace mod_googlemeet\api;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for deterministic Calendar identifiers.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(calendar_identity::class)]
final class calendar_identity_test extends \advanced_testcase {

    /**
     * Event IDs are deterministic and satisfy Calendar's base32hex constraints.
     */
    public function test_event_id_is_stable_and_calendar_safe(): void {
        $identity = new calendar_identity('https://moodle.example.test');

        $eventid = $identity->event_id(42);

        $this->assertSame($eventid, $identity->event_id(42));
        $this->assertMatchesRegularExpression('/^[0-9a-v]{5,1024}$/', $eventid);
        $this->assertNotSame($eventid, $identity->event_id(43));
        $this->assertNotSame(
            $eventid,
            (new calendar_identity('https://other.example.test'))->event_id(42)
        );
    }

    /**
     * One logical conference request keeps the same bounded identifier.
     */
    public function test_request_id_is_stable_and_bounded(): void {
        $identity = new calendar_identity('https://moodle.example.test');
        $eventid = $identity->event_id(42);

        $requestid = $identity->request_id(42, $eventid);

        $this->assertSame(64, strlen($requestid));
        $this->assertSame($requestid, $identity->request_id(42, $eventid));
        $this->assertNotSame($requestid, $identity->request_id(43, $eventid));
        $this->assertNotSame($requestid, $identity->request_id(42, $eventid, 2));
    }
}
