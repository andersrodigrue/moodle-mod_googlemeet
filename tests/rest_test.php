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

/**
 * Tests for the Google REST endpoint definitions.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversDefaultClass \mod_googlemeet\rest
 */
final class rest_test extends \advanced_testcase {

    /**
     * The plugin must not expose an endpoint that changes Drive permissions.
     *
     * @covers ::get_api_functions
     */
    public function test_drive_permission_mutation_is_not_exposed(): void {
        $reflection = new \ReflectionClass(rest::class);
        $client = $reflection->newInstanceWithoutConstructor();
        $functions = $client->get_api_functions();

        $this->assertArrayNotHasKey('create_permission', $functions);

        foreach ($functions as $function) {
            $this->assertStringNotContainsString('/permissions', $function['endpoint']);
        }
    }
}
