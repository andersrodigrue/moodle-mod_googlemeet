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

namespace mod_googlemeet\task;

use mod_googlemeet\local\diagnostic_recorder;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for operational diagnostic retention.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(purge_diagnostics::class)]
final class purge_diagnostics_test extends \advanced_testcase {

    /**
     * The scheduled task removes expired rows and preserves the boundary.
     */
    public function test_execute_applies_configured_retention(): void {
        global $DB;

        $this->resetAfterTest();
        set_config('diagnosticretentiondays', 7, 'googlemeet');
        $course = $this->getDataGenerator()->create_course();
        $meeting = $this->getDataGenerator()->get_plugin_generator('mod_googlemeet')->create_instance([
            'course' => $course->id,
        ]);
        $now = time();
        foreach ([$now - 8 * DAYSECS, $now - 6 * DAYSECS] as $timecreated) {
            $DB->insert_record('googlemeet_diagnostics', (object) [
                'googlemeetid' => $meeting->id,
                'operation' => diagnostic_recorder::OPERATION_MEETING_SYNC,
                'outcome' => diagnostic_recorder::OUTCOME_SUCCEEDED,
                'source' => diagnostic_recorder::SOURCE_ADHOC,
                'diagnosticcode' => null,
                'timecreated' => $timecreated,
            ]);
        }

        $this->expectOutputString(get_string('diagnosticspurgeresult', 'mod_googlemeet', (object) [
            'days' => 7,
            'deleted' => 1,
        ]) . "\n");
        $task = new purge_diagnostics();
        $task->execute();

        $this->assertSame(1, $DB->count_records('googlemeet_diagnostics', [
            'googlemeetid' => $meeting->id,
        ]));
        $this->assertSame(get_string('diagnosticspurgetask', 'mod_googlemeet'), $task->get_name());
    }
}
