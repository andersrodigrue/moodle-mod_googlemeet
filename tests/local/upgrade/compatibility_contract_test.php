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

namespace mod_googlemeet\local\upgrade;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests the machine-readable legacy compatibility inventory.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(compatibility_contract::class)]
final class compatibility_contract_test extends \advanced_testcase {

    /**
     * The fork baseline and every savepoint remain explicit and ordered.
     */
    public function test_declares_stable_baseline_and_ordered_savepoints(): void {
        $this->assertSame('2.1.1', compatibility_contract::STABLE_RELEASE);
        $this->assertSame(2023050101, compatibility_contract::STABLE_VERSION);
        $this->assertSame(2023042199, compatibility_contract::PRE_EVENTID_VERSION);
        $this->assertSame(
            compatibility_contract::CURRENT_VERSION,
            compatibility_contract::SAVEPOINTS[array_key_last(compatibility_contract::SAVEPOINTS)]
        );
        $this->assertSame(
            compatibility_contract::SAVEPOINTS,
            array_values(array_unique(compatibility_contract::SAVEPOINTS))
        );

        $previous = 0;
        foreach (compatibility_contract::SAVEPOINTS as $savepoint) {
            $this->assertGreaterThan($previous, $savepoint);
            $previous = $savepoint;
        }
    }

    /**
     * Retained columns have a bounded purpose and removal condition.
     */
    public function test_inventory_covers_every_retained_legacy_field(): void {
        $fields = compatibility_contract::retained_activity_fields();
        $expected = array_merge(
            compatibility_contract::LEGACY_SCHEDULE_FIELDS,
            compatibility_contract::LEGACY_IDENTITY_FIELDS
        );

        $this->assertSame($expected, array_keys($fields));
        foreach (compatibility_contract::LEGACY_SCHEDULE_FIELDS as $field) {
            $this->assertTrue(compatibility_contract::is_legacy_schedule_field($field));
            $this->assertSame('schedule', $fields[$field]['category']);
            $this->assertSame('legacy_conversion_only', $fields[$field]['writepolicy']);
            $this->assertNotSame('', $fields[$field]['removalcondition']);
        }
        foreach (compatibility_contract::LEGACY_IDENTITY_FIELDS as $field) {
            $this->assertFalse(compatibility_contract::is_legacy_schedule_field($field));
            $this->assertNotSame('', $fields[$field]['category']);
            $this->assertNotSame('', $fields[$field]['writepolicy']);
            $this->assertNotSame('', $fields[$field]['removalcondition']);
        }
    }

    /**
     * The latest savepoint must match the installed plugin version.
     */
    public function test_current_contract_matches_installed_plugin_version(): void {
        $this->assertSame(
            compatibility_contract::CURRENT_VERSION,
            (int) get_config('mod_googlemeet', 'version')
        );
    }
}
