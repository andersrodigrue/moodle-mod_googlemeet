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

use mod_googlemeet\local\calendar_guest_policy;
use mod_googlemeet\local\integration_mode;
use mod_googlemeet\local\sync_state;
use mod_googlemeet\local\upgrade\compatibility_contract;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversFunction;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/upgradelib.php');
require_once($CFG->dirroot . '/mod/googlemeet/db/upgrade.php');

/**
 * Exercises the complete in-place upgrade contract.
 *
 * The test database begins with the current install.xml schema. Re-running the
 * guarded upgrade chain over representative legacy rows verifies all data
 * transformations and detects upgrade-only fields missing from install.xml:
 * such fields would become unexpected columns in the exact schema comparison.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversFunction('xmldb_googlemeet_upgrade')]
#[CoversClass(compatibility_contract::class)]
final class upgrade_test extends \advanced_testcase {

    /**
     * The tagged stable release acquires canonical state without data loss.
     */
    public function test_upgrades_v211_records_through_every_savepoint(): void {
        global $DB;

        $this->resetAfterTest();
        $fixture = $this->legacy_fixture();

        $this->run_upgrade(compatibility_contract::STABLE_VERSION);

        $linked = $DB->get_record(
            'googlemeet',
            ['id' => $fixture['linkedmeetingid']],
            '*',
            MUST_EXIST
        );
        $manual = $DB->get_record(
            'googlemeet',
            ['id' => $fixture['manualmeetingid']],
            '*',
            MUST_EXIST
        );

        $this->assertSame(integration_mode::LEGACY, $linked->integrationmode);
        $this->assertSame(sync_state::DISCONNECTED, $linked->syncstatus);
        $this->assertSame('legacy-calendar-link', $linked->eventid);
        $this->assertSame('legacy-owner@example.com', $linked->creatoremail);
        $this->assertSame($fixture['linkedurl'], $linked->meetinguri);
        $this->assertSame($fixture['timemodified'], (int) $linked->timecreated);
        $this->assertSame($fixture['firststart'], (int) $linked->timestart);
        $this->assertSame($fixture['firststart'] + 75 * MINSECS, (int) $linked->timeend);
        $this->assertSame(\core_date::get_server_timezone(), $linked->timezone);
        $this->assertSame(
            "RRULE:FREQ=WEEKLY;COUNT=1\nRDATE:" . gmdate('Ymd\THis\Z', $fixture['secondstart']),
            $linked->recurrence
        );
        $this->assertSame('none', $linked->sendupdates);
        $this->assertSame(calendar_guest_policy::NONE, $linked->guestpolicy);

        $this->assertSame(integration_mode::MANUAL, $manual->integrationmode);
        $this->assertSame(sync_state::READY, $manual->syncstatus);
        $this->assertNull($manual->eventid);
        $this->assertSame($fixture['manualurl'], $manual->meetinguri);
        $this->assertSame($fixture['manualstart'], (int) $manual->timestart);
        $this->assertSame($fixture['manualstart'] + 75 * MINSECS, (int) $manual->timeend);

        $events = array_values($DB->get_records(
            'googlemeet_events',
            ['googlemeetid' => $linked->id],
            'eventdate ASC, id ASC'
        ));
        $this->assertCount(2, $events);
        $this->assertSame(
            [$fixture['firststart'], $fixture['secondstart']],
            array_map(static fn(\stdClass $event): int => (int) $event->eventdate, $events)
        );
        foreach ($events as $event) {
            $this->assertSame(
                hash('sha256', 'v1:' . (int) $event->eventdate),
                $event->occurrencekey
            );
        }

        $this->assertSame(2, $DB->count_records('googlemeet_notify_done', [
            'eventid' => $events[0]->id,
        ]));
        $this->assertFalse($DB->record_exists('googlemeet_events', [
            'id' => $fixture['duplicateeventid'],
        ]));
        $this->assertTrue($DB->record_exists('googlemeet_recordings', [
            'id' => $fixture['recordingid'],
            'googlemeetid' => $linked->id,
        ]));
        $this->assertTrue(
            $DB->get_manager()->table_exists(new \xmldb_table('googlemeet_calendar_guests'))
        );
        $this->assertTrue(
            $DB->get_manager()->table_exists(new \xmldb_table('googlemeet_diagnostics'))
        );
        $this->assertSame(
            compatibility_contract::CURRENT_VERSION,
            (int) get_config('mod_googlemeet', 'version')
        );
    }

    /**
     * A retried upgrade does not duplicate occurrences or receipts.
     */
    public function test_complete_upgrade_chain_is_idempotent(): void {
        global $DB;

        $this->resetAfterTest();
        $fixture = $this->legacy_fixture();
        $this->run_upgrade(compatibility_contract::STABLE_VERSION);

        $beforeevents = array_keys($DB->get_records(
            'googlemeet_events',
            ['googlemeetid' => $fixture['linkedmeetingid']],
            'id ASC',
            'id'
        ));
        $beforereceipts = array_keys($DB->get_records('googlemeet_notify_done', null, 'id ASC', 'id'));
        $this->run_upgrade(compatibility_contract::STABLE_VERSION);

        $this->assertSame($beforeevents, array_keys($DB->get_records(
            'googlemeet_events',
            ['googlemeetid' => $fixture['linkedmeetingid']],
            'id ASC',
            'id'
        )));
        $this->assertSame(
            $beforereceipts,
            array_keys($DB->get_records('googlemeet_notify_done', null, 'id ASC', 'id'))
        );
        $this->assertSame(2, $DB->count_records('googlemeet_events', [
            'googlemeetid' => $fixture['linkedmeetingid'],
        ]));
        $this->assertSame(2, $DB->count_records('googlemeet_notify_done'));
    }

    /**
     * Sites predating eventid can still traverse the entire guarded chain.
     */
    public function test_pre_eventid_upgrade_entry_point_reaches_current_version(): void {
        $this->resetAfterTest();

        $this->run_upgrade(compatibility_contract::PRE_EVENTID_VERSION);

        $this->assertSame(
            compatibility_contract::CURRENT_VERSION,
            (int) get_config('mod_googlemeet', 'version')
        );
    }

    /**
     * An upgraded database satisfies the complete fresh-install field contract.
     */
    public function test_upgraded_schema_matches_fresh_install_fields_and_indexes(): void {
        global $CFG, $DB;

        $this->resetAfterTest();
        $this->run_upgrade(compatibility_contract::STABLE_VERSION);

        $xml = simplexml_load_file($CFG->dirroot . '/mod/googlemeet/db/install.xml');
        $this->assertInstanceOf(\SimpleXMLElement::class, $xml);
        $dbman = $DB->get_manager();

        foreach ($xml->TABLES->TABLE as $tablexml) {
            $tablename = (string) $tablexml['NAME'];
            $table = new \xmldb_table($tablename);
            $this->assertTrue($dbman->table_exists($table), 'Missing table ' . $tablename);

            $expectedfields = [];
            foreach ($tablexml->FIELDS->FIELD as $fieldxml) {
                $expectedfields[] = (string) $fieldxml['NAME'];
            }
            $actualfields = array_keys($DB->get_columns($tablename));
            sort($expectedfields);
            sort($actualfields);
            $this->assertSame($expectedfields, $actualfields, 'Field drift in ' . $tablename);

            foreach ($tablexml->INDEXES->INDEX ?? [] as $indexxml) {
                $fields = preg_split('/,\s*/', (string) $indexxml['FIELDS']);
                $index = new \xmldb_index(
                    (string) $indexxml['NAME'],
                    (string) $indexxml['UNIQUE'] === 'true'
                        ? XMLDB_INDEX_UNIQUE
                        : XMLDB_INDEX_NOTUNIQUE,
                    $fields
                );
                $this->assertTrue(
                    $dbman->index_exists($table, $index),
                    'Missing index ' . $tablename . '.' . (string) $indexxml['NAME']
                );
            }
        }

        $eventcolumns = $DB->get_columns('googlemeet_events');
        $this->assertNotEmpty($eventcolumns['occurrencekey']->not_null);
        $this->assertEmpty($eventcolumns['occurrencekey']->has_default);

        $activitycolumns = $DB->get_columns('googlemeet');
        foreach (compatibility_contract::retained_activity_fields() as $field => $policy) {
            $this->assertArrayHasKey($field, $activitycolumns);
            $this->assertNotSame('', $policy['removalcondition']);
        }
    }

    /**
     * Creates representative records in the stable schema shape.
     *
     * @return array<string, int|string>
     */
    private function legacy_fixture(): array {
        global $DB;

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $firstuser = $generator->create_user();
        $seconduser = $generator->create_user();
        $day = make_timestamp(2026, 8, 3, 0, 0, 0, 'UTC');
        $firststart = $day + 10 * HOURSECS + 15 * MINSECS;
        $secondstart = $firststart + 7 * DAYSECS;
        $timemodified = $day - DAYSECS;
        $plugingenerator = $generator->get_plugin_generator('mod_googlemeet');

        $linked = $plugingenerator->create_instance([
            'course' => $course->id,
            'name' => 'Legacy linked meeting',
            'url' => 'https://meet.google.com/abc-defg-hij',
            'creatoremail' => 'legacy-owner@example.com',
            'eventid' => 'legacy-calendar-link',
            'eventdate' => $day,
            'starthour' => 10,
            'startminute' => 15,
            'endhour' => 11,
            'endminute' => 30,
            'addmultiply' => 1,
            'days' => json_encode(['Mon' => 1]),
            'period' => 1,
            'eventenddate' => $day + 14 * DAYSECS,
            'integrationmode' => integration_mode::MANUAL,
            'meetinguri' => null,
            'timestart' => 0,
            'timeend' => 0,
            'timezone' => null,
            'recurrence' => null,
            'sendupdates' => 'all',
            'guestpolicy' => calendar_guest_policy::COURSE,
            'syncstatus' => sync_state::DRAFT,
            'timecreated' => 0,
            'timemodified' => $timemodified,
        ]);
        $manual = $plugingenerator->create_instance([
            'course' => $course->id,
            'name' => 'Legacy manual meeting',
            'url' => 'https://meet.google.com/xyz-abcd-efg',
            'eventid' => null,
            'eventdate' => $day,
            'starthour' => 14,
            'startminute' => 30,
            'endhour' => 15,
            'endminute' => 45,
            'integrationmode' => integration_mode::LEGACY,
            'meetinguri' => null,
            'timestart' => 0,
            'timeend' => 0,
            'timezone' => null,
            'syncstatus' => sync_state::DISCONNECTED,
            'timecreated' => 0,
            'timemodified' => $timemodified,
        ]);

        $firsteventid = $this->insert_legacy_event($linked->id, $firststart, 75 * MINSECS, 'legacy-a');
        $duplicateeventid = $this->insert_legacy_event(
            $linked->id,
            $firststart,
            75 * MINSECS,
            'legacy-duplicate'
        );
        $this->insert_legacy_event($linked->id, $secondstart, 75 * MINSECS, 'legacy-b');

        $DB->insert_record('googlemeet_notify_done', (object) [
            'eventid' => $firsteventid,
            'userid' => $firstuser->id,
            'timesent' => $firststart - HOURSECS,
        ]);
        $DB->insert_record('googlemeet_notify_done', (object) [
            'eventid' => $duplicateeventid,
            'userid' => $firstuser->id,
            'timesent' => $firststart - HOURSECS,
        ]);
        $DB->insert_record('googlemeet_notify_done', (object) [
            'eventid' => $duplicateeventid,
            'userid' => $seconduser->id,
            'timesent' => $firststart - HOURSECS,
        ]);
        $recordingid = $DB->insert_record('googlemeet_recordings', (object) [
            'googlemeetid' => $linked->id,
            'recordingid' => 'DriveFile_legacy123',
            'name' => 'Legacy recording',
            'createdtime' => $firststart,
            'duration' => '1:15:00',
            'webviewlink' => 'https://drive.google.com/file/d/DriveFile_legacy123/view',
            'visible' => 1,
            'timemodified' => $timemodified,
        ]);

        return [
            'linkedmeetingid' => (int) $linked->id,
            'manualmeetingid' => (int) $manual->id,
            'linkedurl' => 'https://meet.google.com/abc-defg-hij',
            'manualurl' => 'https://meet.google.com/xyz-abcd-efg',
            'timemodified' => $timemodified,
            'firststart' => $firststart,
            'secondstart' => $secondstart,
            'manualstart' => $day + 14 * HOURSECS + 30 * MINSECS,
            'duplicateeventid' => $duplicateeventid,
            'recordingid' => $recordingid,
        ];
    }

    /**
     * Inserts one pre-canonical occurrence row.
     *
     * @param int $googlemeetid Activity ID.
     * @param int $eventdate Absolute start time.
     * @param int $duration Duration in seconds.
     * @param string $placeholderkey Distinct placeholder required by the current test schema.
     * @return int
     */
    private function insert_legacy_event(
        int $googlemeetid,
        int $eventdate,
        int $duration,
        string $placeholderkey
    ): int {
        global $DB;

        return $DB->insert_record('googlemeet_events', (object) [
            'googlemeetid' => $googlemeetid,
            'occurrencekey' => $placeholderkey,
            'eventdate' => $eventdate,
            'duration' => $duration,
            'timemodified' => $eventdate,
        ]);
    }

    /**
     * Runs the real plugin upgrade function from a controlled version.
     *
     * @param int $oldversion Starting version.
     */
    private function run_upgrade(int $oldversion): void {
        set_config('version', $oldversion, 'mod_googlemeet');
        $this->assertTrue(\xmldb_googlemeet_upgrade($oldversion));
    }
}
