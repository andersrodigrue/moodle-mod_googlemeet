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

use mod_googlemeet\event\operation_recorded;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for privacy-safe operational recording.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(diagnostic_recorder::class)]
#[CoversClass(operation_recorded::class)]
final class diagnostic_recorder_test extends \advanced_testcase {

    /**
     * A closed transition is stored and emitted without free-form data.
     */
    public function test_record_persists_closed_fields_and_emits_event(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $meeting = $this->getDataGenerator()->get_plugin_generator('mod_googlemeet')->create_instance([
            'course' => $course->id,
        ]);
        $sink = $this->redirectEvents();

        $diagnosticid = (new diagnostic_recorder())->record(
            (int) $meeting->id,
            diagnostic_recorder::OPERATION_MEETING_SYNC,
            diagnostic_recorder::OUTCOME_FAILED,
            diagnostic_recorder::SOURCE_ADHOC,
            'calendar_api_http_400'
        );

        $record = $DB->get_record('googlemeet_diagnostics', ['id' => $diagnosticid], '*', MUST_EXIST);
        $this->assertSame((int) $meeting->id, (int) $record->googlemeetid);
        $this->assertSame('meeting_sync', $record->operation);
        $this->assertSame('failed', $record->outcome);
        $this->assertSame('adhoc', $record->source);
        $this->assertSame('calendar_api_http_400', $record->diagnosticcode);
        $this->assertFalse(property_exists($record, 'userid'));
        $this->assertFalse(property_exists($record, 'message'));

        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertInstanceOf(operation_recorded::class, $event);
        $this->assertSame((int) $meeting->id, (int) $event->objectid);
        $this->assertSame('meeting_sync', $event->other['operation']);
        $this->assertSame('failed', $event->other['outcome']);
        $this->assertSame('adhoc', $event->other['source']);
    }

    /**
     * Free text can never be smuggled through the diagnostic code field.
     */
    public function test_record_replaces_invalid_code_instead_of_cleaning_it(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $meeting = $this->getDataGenerator()->get_plugin_generator('mod_googlemeet')->create_instance([
            'course' => $course->id,
        ]);

        (new diagnostic_recorder())->record(
            (int) $meeting->id,
            diagnostic_recorder::OPERATION_RECORDING_DISCOVERY,
            diagnostic_recorder::OUTCOME_FAILED,
            diagnostic_recorder::SOURCE_ADHOC,
            'Sensitive token and user@example.test'
        );

        $this->assertSame(
            'invalid_diagnostic_code',
            $DB->get_field('googlemeet_diagnostics', 'diagnosticcode', [
                'googlemeetid' => $meeting->id,
            ])
        );
    }

    /**
     * Unknown vocabulary is rejected before a row or event is emitted.
     */
    public function test_record_rejects_unknown_operation(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $meeting = $this->getDataGenerator()->get_plugin_generator('mod_googlemeet')->create_instance([
            'course' => $course->id,
        ]);

        $this->expectException(\coding_exception::class);
        (new diagnostic_recorder())->record(
            (int) $meeting->id,
            'arbitrary_operation',
            diagnostic_recorder::OUTCOME_FAILED,
            diagnostic_recorder::SOURCE_ADHOC
        );
    }

    /**
     * Activity deletion removes all of its operational rows before the parent.
     */
    public function test_activity_deletion_removes_diagnostics(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $meeting = $this->getDataGenerator()->get_plugin_generator('mod_googlemeet')->create_instance([
            'course' => $course->id,
        ]);
        (new diagnostic_recorder())->record(
            (int) $meeting->id,
            diagnostic_recorder::OPERATION_MEETING_SYNC,
            diagnostic_recorder::OUTCOME_SUCCEEDED,
            diagnostic_recorder::SOURCE_ADHOC
        );

        $this->assertTrue(\googlemeet_delete_instance((int) $meeting->id));
        $this->assertFalse($DB->record_exists('googlemeet_diagnostics', [
            'googlemeetid' => $meeting->id,
        ]));
    }
}
