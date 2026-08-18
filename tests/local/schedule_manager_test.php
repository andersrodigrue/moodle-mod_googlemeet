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

use mod_googlemeet\helper;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for local occurrence and Moodle Calendar reconciliation.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(schedule_manager::class)]
final class schedule_manager_test extends \advanced_testcase {

    /**
     * Synchronization creates linked local and Calendar events.
     */
    public function test_creates_occurrences_from_canonical_schedule(): void {
        global $DB;

        $this->resetAfterTest();
        [$meeting, $start] = $this->meeting();

        (new schedule_manager())->synchronise($meeting);

        $events = $DB->get_records(
            'googlemeet_events',
            ['googlemeetid' => $meeting->id],
            'eventdate ASC'
        );
        $this->assertCount(3, $events);
        $this->assertSame([
            $start,
            $start + 7 * DAYSECS,
            $start + 14 * DAYSECS,
        ], array_map(fn(\stdClass $event): int => (int) $event->eventdate, array_values($events)));
        foreach ($events as $event) {
            $this->assertSame(hash('sha256', 'v1:' . $event->eventdate), $event->occurrencekey);
            $this->assertGreaterThan(0, (int) $event->calendareventid);
            $this->assertTrue($DB->record_exists('event', [
                'id' => (int) $event->calendareventid,
                'component' => 'mod_googlemeet',
                'modulename' => 'googlemeet',
                'instance' => (int) $meeting->id,
                'eventtype' => helper::GOOGLEMEET_EVENT_START,
            ]));
        }
    }

    /**
     * Unchanged occurrence IDs and reminder receipts survive an activity edit.
     */
    public function test_reconciles_without_losing_existing_receipts(): void {
        global $DB;

        $this->resetAfterTest();
        [$meeting] = $this->meeting();
        $manager = new schedule_manager();
        $manager->synchronise($meeting);
        $existing = $DB->get_records(
            'googlemeet_events',
            ['googlemeetid' => $meeting->id],
            'eventdate ASC',
            '*',
            0,
            1
        );
        $first = reset($existing);
        $this->assertNotFalse($first);
        $user = $this->getDataGenerator()->create_user();
        $receiptid = $DB->insert_record('googlemeet_notify_done', (object) [
            'eventid' => $first->id,
            'userid' => $user->id,
            'timesent' => time(),
        ]);

        $meeting->name = 'Updated workshop';
        $meeting->timeend = (int) $meeting->timeend + 30 * MINSECS;
        $manager->synchronise($meeting);

        $updated = $DB->get_record('googlemeet_events', ['id' => $first->id], '*', MUST_EXIST);
        $this->assertSame(90 * MINSECS, (int) $updated->duration);
        $this->assertTrue($DB->record_exists('googlemeet_notify_done', ['id' => $receiptid]));
        $calendar = $DB->get_record('event', ['id' => $updated->calendareventid], '*', MUST_EXIST);
        $this->assertSame(90 * MINSECS, (int) $calendar->timeduration);
        $this->assertStringContainsString('Updated workshop', $calendar->name);
    }

    /**
     * Removed canonical occurrences also remove their receipts and Calendar rows.
     */
    public function test_removes_obsolete_occurrences_and_calendar_events(): void {
        global $DB;

        $this->resetAfterTest();
        [$meeting] = $this->meeting();
        $manager = new schedule_manager();
        $manager->synchronise($meeting);
        $events = array_values($DB->get_records(
            'googlemeet_events',
            ['googlemeetid' => $meeting->id],
            'eventdate ASC'
        ));
        $user = $this->getDataGenerator()->create_user();
        $DB->insert_record('googlemeet_notify_done', (object) [
            'eventid' => $events[1]->id,
            'userid' => $user->id,
            'timesent' => time(),
        ]);

        $meeting->recurrence = null;
        $manager->synchronise($meeting);

        $this->assertSame(1, $DB->count_records('googlemeet_events', [
            'googlemeetid' => $meeting->id,
        ]));
        $this->assertFalse($DB->record_exists('googlemeet_notify_done', [
            'eventid' => $events[1]->id,
        ]));
        $this->assertFalse($DB->record_exists('event', [
            'id' => $events[1]->calendareventid,
        ]));

        $manager->delete((int) $meeting->id);
        $this->assertSame(0, $DB->count_records('googlemeet_events', [
            'googlemeetid' => $meeting->id,
        ]));
        $this->assertSame(0, $DB->count_records('event', [
            'modulename' => 'googlemeet',
            'instance' => $meeting->id,
        ]));
    }

    /**
     * Creates a recurring manual meeting fixture.
     *
     * @return array{0: \stdClass, 1: int}
     */
    private function meeting(): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $start = make_timestamp(2026, 8, 3, 10, 0, 0, 'UTC');
        $instance = $this->getDataGenerator()->get_plugin_generator('mod_googlemeet')->create_instance([
            'course' => $course->id,
            'name' => 'Canonical workshop',
            'timestart' => $start,
            'timeend' => $start + HOURSECS,
            'timezone' => 'UTC',
            'recurrence' => 'RRULE:FREQ=WEEKLY;COUNT=3;BYDAY=MO',
        ]);
        $meeting = $DB->get_record('googlemeet', ['id' => $instance->id], '*', MUST_EXIST);

        return [$meeting, $start];
    }
}
