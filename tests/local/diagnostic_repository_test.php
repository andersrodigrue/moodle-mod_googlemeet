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
 * Tests for bounded administrator diagnostic queries and retention.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(diagnostic_repository::class)]
final class diagnostic_repository_test extends \advanced_testcase {

    /**
     * Latest rows can be filtered without exposing owner data.
     */
    public function test_latest_filters_operation_outcome_and_activity(): void {
        $this->resetAfterTest();
        $first = $this->create_meeting();
        $second = $this->create_meeting();
        $now = time();
        $this->insert_diagnostic($first->id, 'meeting_sync', 'queued', $now - 2);
        $this->insert_diagnostic($first->id, 'meeting_sync', 'failed', $now - 1);
        $this->insert_diagnostic($second->id, 'recording_discovery', 'succeeded', $now);

        $records = (new diagnostic_repository())->latest(
            diagnostic_recorder::OPERATION_MEETING_SYNC,
            diagnostic_recorder::OUTCOME_FAILED,
            (int) $first->id
        );

        $this->assertCount(1, $records);
        $this->assertSame((int) $first->id, (int) $records[0]->googlemeetid);
        $this->assertSame('failed', $records[0]->outcome);
        $this->assertNotEmpty($records[0]->activityname);
        $this->assertNotEmpty($records[0]->coursename);
        $this->assertFalse(property_exists($records[0], 'owneruserid'));
    }

    /**
     * Summary counts and purge both operate on bounded timestamps.
     */
    public function test_summary_and_purge_are_bounded(): void {
        global $DB;

        $this->resetAfterTest();
        $meeting = $this->create_meeting();
        $now = time();
        $this->insert_diagnostic($meeting->id, 'meeting_sync', 'failed', $now - 100);
        $this->insert_diagnostic($meeting->id, 'meeting_sync', 'failed', $now - 90);
        $this->insert_diagnostic($meeting->id, 'meeting_sync', 'succeeded', $now);

        $repository = new diagnostic_repository();
        $counts = $repository->outcome_counts_since($now - 95);
        $this->assertSame(1, $counts[diagnostic_recorder::OUTCOME_FAILED]);
        $this->assertSame(1, $counts[diagnostic_recorder::OUTCOME_SUCCEEDED]);

        $this->assertSame(1, $repository->purge_before($now - 10, 1));
        $this->assertSame(2, $DB->count_records('googlemeet_diagnostics', [
            'googlemeetid' => $meeting->id,
        ]));
    }

    /**
     * Retention is restricted to administrator-selectable values.
     */
    public function test_retention_days_rejects_unbounded_configuration(): void {
        $this->resetAfterTest();

        set_config('diagnosticretentiondays', 90, 'googlemeet');
        $this->assertSame(90, diagnostic_repository::retention_days());

        set_config('diagnosticretentiondays', 9999, 'googlemeet');
        $this->assertSame(
            diagnostic_repository::DEFAULT_RETENTION_DAYS,
            diagnostic_repository::retention_days()
        );
    }

    /**
     * Creates an activity.
     *
     * @return \stdClass
     */
    private function create_meeting(): \stdClass {
        $course = $this->getDataGenerator()->create_course();
        return $this->getDataGenerator()->get_plugin_generator('mod_googlemeet')->create_instance([
            'course' => $course->id,
        ]);
    }

    /**
     * Inserts one row without emitting an event.
     *
     * @param int $googlemeetid Activity ID.
     * @param string $operation Operation.
     * @param string $outcome Outcome.
     * @param int $timecreated Timestamp.
     */
    private function insert_diagnostic(
        int $googlemeetid,
        string $operation,
        string $outcome,
        int $timecreated
    ): void {
        global $DB;

        $DB->insert_record('googlemeet_diagnostics', (object) [
            'googlemeetid' => $googlemeetid,
            'operation' => $operation,
            'outcome' => $outcome,
            'source' => diagnostic_recorder::SOURCE_ADHOC,
            'diagnosticcode' => null,
            'timecreated' => $timecreated,
        ]);
    }
}
