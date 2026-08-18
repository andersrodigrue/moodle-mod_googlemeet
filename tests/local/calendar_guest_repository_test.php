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
 * Tests for managed Calendar guest persistence.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(calendar_guest_repository::class)]
final class calendar_guest_repository_test extends \advanced_testcase {

    /**
     * Synchronization stores only user IDs and normalized email hashes.
     */
    public function test_record_synchronised_replaces_snapshot_without_raw_email(): void {
        global $DB;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $first = $generator->create_user(['email' => 'first@example.test']);
        $second = $generator->create_user(['email' => 'second@example.test']);
        $meeting = $generator->get_plugin_generator('mod_googlemeet')->create_instance([
            'course' => $course->id,
            'guestpolicy' => calendar_guest_policy::COURSE,
        ]);
        $snapshot = calendar_guest_snapshot::course([$first, $second]);
        $repository = new calendar_guest_repository();

        $repository->record_synchronised((int) $meeting->id, $snapshot);

        $rows = array_values($DB->get_records(
            'googlemeet_calendar_guests',
            ['googlemeetid' => $meeting->id],
            'userid ASC'
        ));
        $this->assertCount(2, $rows);
        $this->assertSame(64, strlen($rows[0]->emailhash));
        $this->assertFalse(property_exists($rows[0], 'email'));
        $updated = $DB->get_record('googlemeet', ['id' => $meeting->id], '*', MUST_EXIST);
        $this->assertSame($snapshot->hash(), $updated->guesthash);
        $this->assertSame(2, (int) $updated->guestcount);
        $this->assertSame('all', $updated->sendupdates);
        $this->assertCount(2, $repository->previous_hashes((int) $meeting->id));

        $repository->record_synchronised((int) $meeting->id, calendar_guest_snapshot::none());
        $updated = $DB->get_record('googlemeet', ['id' => $meeting->id], '*', MUST_EXIST);
        $this->assertFalse($DB->record_exists('googlemeet_calendar_guests', [
            'googlemeetid' => $meeting->id,
        ]));
        $this->assertNull($updated->guesthash);
        $this->assertSame(0, (int) $updated->guestcount);
        $this->assertSame('none', $updated->sendupdates);
    }

    /**
     * Privacy deletion removes only approved guest receipts.
     */
    public function test_delete_users_data_is_scoped_and_invalidates_aggregate_hash(): void {
        global $DB;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $first = $generator->create_user();
        $second = $generator->create_user();
        $meeting = $generator->get_plugin_generator('mod_googlemeet')->create_instance([
            'course' => $course->id,
            'guestpolicy' => calendar_guest_policy::COURSE,
        ]);
        $repository = new calendar_guest_repository();
        $repository->record_synchronised(
            (int) $meeting->id,
            calendar_guest_snapshot::course([$first, $second])
        );

        $repository->delete_users_data((int) $meeting->id, [(int) $first->id]);

        $this->assertFalse($DB->record_exists('googlemeet_calendar_guests', [
            'googlemeetid' => $meeting->id,
            'userid' => $first->id,
        ]));
        $this->assertTrue($DB->record_exists('googlemeet_calendar_guests', [
            'googlemeetid' => $meeting->id,
            'userid' => $second->id,
        ]));
        $updated = $DB->get_record('googlemeet', ['id' => $meeting->id], '*', MUST_EXIST);
        $this->assertNull($updated->guesthash);
        $this->assertSame(1, (int) $updated->guestcount);
    }
}
