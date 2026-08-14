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

use mod_googlemeet\local\integration_mode;
use mod_googlemeet\local\sync_state;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for scheduled reconciliation dispatch.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(reconcile_pending_meetings::class)]
final class reconcile_pending_meetings_test extends \advanced_testcase {

    /**
     * Cron queues separate owner-scoped ad hoc work without calling Google.
     */
    public function test_execute_queues_old_pending_and_stale_syncing_meetings(): void {
        global $DB;

        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_googlemeet');
        $oldpending = $generator->create_instance([
            'course' => $course->id,
            'integrationmode' => integration_mode::MANAGED,
            'owneruserid' => $owner->id,
            'syncstatus' => sync_state::PENDING,
            'timelastattempt' => time() - 600,
        ]);
        $stalesyncing = $generator->create_instance([
            'course' => $course->id,
            'integrationmode' => integration_mode::MANAGED,
            'owneruserid' => $owner->id,
            'syncstatus' => sync_state::SYNCING,
            'timelastattempt' => time() - 3600,
        ]);
        $generator->create_instance([
            'course' => $course->id,
            'integrationmode' => integration_mode::MANAGED,
            'owneruserid' => $owner->id,
            'syncstatus' => sync_state::PENDING,
            'timelastattempt' => time(),
        ]);

        $this->expectOutputString(
            get_string('reconcilependingresult', 'mod_googlemeet', (object) [
                'found' => 2,
                'queued' => 2,
            ]) . "\n"
        );
        $task = new reconcile_pending_meetings();
        $task->execute();

        $tasks = \core\task\manager::get_adhoc_tasks(synchronise_meeting::class);
        $this->assertCount(2, $tasks);
        $meetingids = [];
        foreach ($tasks as $queuedtask) {
            $this->assertSame((int) $owner->id, (int) $queuedtask->get_userid());
            $meetingids[] = (int) $queuedtask->get_custom_data()->googlemeetid;
        }
        $this->assertEqualsCanonicalizing(
            [(int) $oldpending->id, (int) $stalesyncing->id],
            $meetingids
        );
        $diagnostics = $DB->get_records('googlemeet_diagnostics', [
            'operation' => \mod_googlemeet\local\diagnostic_recorder::OPERATION_MEETING_SYNC,
            'outcome' => \mod_googlemeet\local\diagnostic_recorder::OUTCOME_QUEUED,
            'source' => \mod_googlemeet\local\diagnostic_recorder::SOURCE_CRON,
        ]);
        $this->assertCount(2, $diagnostics);
        $this->assertEqualsCanonicalizing(
            ['pending_reconciliation', 'stale_syncing_recovery'],
            array_column($diagnostics, 'diagnosticcode')
        );
        $this->assertSame(get_string('reconcilependingtask', 'mod_googlemeet'), $task->get_name());
    }

    /**
     * Duplicate ad hoc tasks are counted as found but not queued again.
     */
    public function test_execute_suppresses_duplicate_reconciliation_task(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $meeting = $this->getDataGenerator()
            ->get_plugin_generator('mod_googlemeet')
            ->create_instance([
                'course' => $course->id,
                'integrationmode' => integration_mode::MANAGED,
                'owneruserid' => $owner->id,
                'syncstatus' => sync_state::PENDING,
                'timelastattempt' => time() - 600,
            ]);
        synchronise_meeting::enqueue((int) $meeting->id, (int) $owner->id);

        $this->expectOutputString(
            get_string('reconcilependingresult', 'mod_googlemeet', (object) [
                'found' => 1,
                'queued' => 0,
            ]) . "\n"
        );
        (new reconcile_pending_meetings())->execute();

        $this->assertCount(
            1,
            \core\task\manager::get_adhoc_tasks(synchronise_meeting::class)
        );
    }
}
