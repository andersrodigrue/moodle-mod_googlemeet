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

use mod_googlemeet\local\calendar_guest_policy;
use mod_googlemeet\local\calendar_guest_repository;
use mod_googlemeet\local\integration_mode;
use mod_googlemeet\local\sync_state;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for bounded Calendar guest reconciliation dispatch.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(reconcile_calendar_guests::class)]
final class reconcile_calendar_guests_test extends \advanced_testcase {

    /**
     * Cron queues only changed membership and never calls Google as cron.
     */
    public function test_execute_queues_changed_guest_snapshot_only(): void {
        global $DB;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $owner = $generator->create_user();
        $changedcourse = $generator->create_course();
        $unchangedcourse = $generator->create_course();
        $generator->create_and_enrol($changedcourse, 'student');
        $unchangedstudent = $generator->create_and_enrol($unchangedcourse, 'student');
        $plugingenerator = $generator->get_plugin_generator('mod_googlemeet');
        $changed = $plugingenerator->create_instance([
            'course' => $changedcourse->id,
            'integrationmode' => integration_mode::MANAGED,
            'owneruserid' => $owner->id,
            'guestpolicy' => calendar_guest_policy::COURSE,
            'syncstatus' => sync_state::READY,
        ]);
        $unchanged = $plugingenerator->create_instance([
            'course' => $unchangedcourse->id,
            'integrationmode' => integration_mode::MANAGED,
            'owneruserid' => $owner->id,
            'guestpolicy' => calendar_guest_policy::COURSE,
            'syncstatus' => sync_state::READY,
        ]);
        $repository = new calendar_guest_repository();
        $repository->record_synchronised(
            (int) $unchanged->id,
            \mod_googlemeet\local\calendar_guest_snapshot::course([$unchangedstudent])
        );
        $old = time() - 7 * HOURSECS;
        $DB->set_field('googlemeet', 'guesttimechecked', $old, ['id' => $changed->id]);
        $DB->set_field('googlemeet', 'guesttimechecked', $old, ['id' => $unchanged->id]);

        $this->expectOutputString(get_string('reconcileguestsresult', 'mod_googlemeet', (object) [
            'found' => 2,
            'queued' => 1,
            'unchanged' => 1,
        ]) . "\n");
        $task = new reconcile_calendar_guests();
        $task->execute();

        $tasks = \core\task\manager::get_adhoc_tasks(synchronise_meeting::class);
        $this->assertCount(1, $tasks);
        $queued = reset($tasks);
        $this->assertSame((int) $owner->id, (int) $queued->get_userid());
        $this->assertSame((int) $changed->id, (int) $queued->get_custom_data()->googlemeetid);
        $this->assertSame(sync_state::QUEUED, $DB->get_field('googlemeet', 'syncstatus', [
            'id' => $changed->id,
        ]));
        $this->assertSame(1, $DB->count_records('googlemeet_diagnostics', [
            'googlemeetid' => $changed->id,
            'operation' => \mod_googlemeet\local\diagnostic_recorder::OPERATION_GUEST_RECONCILE,
            'outcome' => \mod_googlemeet\local\diagnostic_recorder::OUTCOME_QUEUED,
            'source' => \mod_googlemeet\local\diagnostic_recorder::SOURCE_CRON,
        ]));
        $this->assertSame(get_string('reconcilegueststask', 'mod_googlemeet'), $task->get_name());
    }
}
