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

use mod_googlemeet\local\integration_mode;
use mod_googlemeet\local\recording_sync_state;
use mod_googlemeet\local\sync_state;
use PHPUnit\Framework\Attributes\CoversClass;
use restore_date_testcase;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/phpunit/classes/restore_date_testcase.php');

/**
 * Tests for portable and safe activity backup/restore.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\backup_googlemeet_activity_task::class)]
#[CoversClass(\restore_googlemeet_activity_task::class)]
#[CoversClass(\backup_googlemeet_activity_structure_step::class)]
#[CoversClass(\restore_googlemeet_activity_structure_step::class)]
final class backup_restore_test extends restore_date_testcase {

    /**
     * Manual meetings retain their explicit link and safe ready state.
     */
    public function test_manual_meeting_round_trip(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['startdate' => $this->startdate]);
        $this->getDataGenerator()->get_plugin_generator('mod_googlemeet')->create_instance([
            'course' => $course->id,
            'name' => 'Manual backup',
            'integrationmode' => integration_mode::MANUAL,
            'url' => 'https://meet.google.com/abc-defg-hij',
            'meetinguri' => 'https://meet.google.com/abc-defg-hij',
            'syncstatus' => sync_state::READY,
        ]);

        $newcourseid = $this->backup_and_restore($course);
        $restored = $DB->get_record(
            'googlemeet',
            ['course' => $newcourseid, 'name' => 'Manual backup'],
            '*',
            MUST_EXIST
        );

        $this->assertSame(integration_mode::MANUAL, $restored->integrationmode);
        $this->assertSame(sync_state::READY, $restored->syncstatus);
        $this->assertSame('https://meet.google.com/abc-defg-hij', $restored->meetinguri);
        $this->assertNull($restored->owneruserid);
        $this->assertNull($restored->googleeventid);
        $this->assertSame(0, (int) $restored->eventdate);
        $this->assertSame(0, (int) $restored->starthour);
        $this->assertSame(0, (int) $restored->startminute);
        $this->assertSame(0, (int) $restored->endhour);
        $this->assertSame(0, (int) $restored->endminute);
        $this->assertSame(0, (int) $restored->addmultiply);
        $this->assertNull($restored->days);
        $this->assertNull($restored->period);
        $this->assertNull($restored->eventenddate);
    }

    /**
     * Managed copies retain scheduling but never clone remote ownership.
     */
    public function test_managed_meeting_restore_requires_explicit_claim(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['startdate' => $this->startdate]);
        $owner = $this->getDataGenerator()->create_user();
        $start = $course->startdate + DAYSECS;
        $source = $this->getDataGenerator()->get_plugin_generator('mod_googlemeet')->create_instance([
            'course' => $course->id,
            'name' => 'Managed backup',
            'integrationmode' => integration_mode::MANAGED,
            'owneruserid' => $owner->id,
            'oauthissuerid' => 42,
            'calendarid' => 'teacher@example.com',
            'googleeventid' => 'event123',
            'googleeventhtmlurl' => 'https://calendar.google.com/event?eid=one',
            'googleeventetag' => '"etag-one"',
            'requestid' => 'request-one',
            'conferenceid' => 'conference-one',
            'meetingcode' => 'abc-defg-hij',
            'meetinguri' => 'https://meet.google.com/abc-defg-hij',
            'url' => 'https://meet.google.com/abc-defg-hij',
            'timestart' => $start,
            'timeend' => $start + HOURSECS,
            'timezone' => 'America/Sao_Paulo',
            'recurrence' => 'RRULE:FREQ=WEEKLY;BYDAY=MO',
            'sendupdates' => 'all',
            'syncstatus' => sync_state::READY,
            'conferencestatus' => 'success',
            'syncattempts' => 3,
            'lasterrorcode' => 'old_error',
            'lasterrormessage' => 'Old error',
            'timelastattempt' => $start - HOURSECS,
            'recordingowneruserid' => $owner->id,
            'recordingoauthissuerid' => 43,
            'recordingsyncstatus' => recording_sync_state::READY,
            'recordingsyncattempts' => 2,
            'recordinglasterrorcode' => 'old_recording_error',
            'recordinglasterrormessage' => 'Old recording error',
            'recordingtimelastattempt' => $start - HOURSECS,
            'lastsync' => $start,
        ]);
        $DB->insert_record('googlemeet_recordings', (object) [
            'googlemeetid' => $source->id,
            'recordingid' => 'DriveFile_12345',
            'name' => 'Private recording reference',
            'createdtime' => $start,
            'duration' => '1:00:00',
            'webviewlink' => 'https://drive.google.com/file/d/DriveFile_12345/view',
            'visible' => 1,
            'timemodified' => $start,
        ]);

        $newcourseid = $this->backup_and_restore($course);
        $restored = $DB->get_record(
            'googlemeet',
            ['course' => $newcourseid, 'name' => 'Managed backup'],
            '*',
            MUST_EXIST
        );

        $this->assertSame(integration_mode::MANAGED, $restored->integrationmode);
        $this->assertSame(sync_state::DISCONNECTED, $restored->syncstatus);
        $this->assertSame('restored_reconnect_required', $restored->lasterrorcode);
        $this->assertSame('primary', $restored->calendarid);
        $this->assertSame('America/Sao_Paulo', $restored->timezone);
        $this->assertSame('RRULE:FREQ=WEEKLY;BYDAY=MO', $restored->recurrence);
        $this->assertSame('all', $restored->sendupdates);
        $this->assertSame(HOURSECS, (int) $restored->timeend - (int) $restored->timestart);

        $this->assertNull($restored->owneruserid);
        $this->assertNull($restored->oauthissuerid);
        $this->assertNull($restored->googleeventid);
        $this->assertNull($restored->googleeventhtmlurl);
        $this->assertNull($restored->googleeventetag);
        $this->assertNull($restored->requestid);
        $this->assertNull($restored->conferenceid);
        $this->assertNull($restored->meetingcode);
        $this->assertNull($restored->meetinguri);
        $this->assertSame('', $restored->url);
        $this->assertNull($restored->conferencestatus);
        $this->assertSame(0, (int) $restored->syncattempts);
        $this->assertNull($restored->timelastattempt);
        $this->assertNull($restored->recordingowneruserid);
        $this->assertNull($restored->recordingoauthissuerid);
        $this->assertSame(
            recording_sync_state::DISCONNECTED,
            $restored->recordingsyncstatus
        );
        $this->assertSame(0, (int) $restored->recordingsyncattempts);
        $this->assertNull($restored->recordinglasterrorcode);
        $this->assertNull($restored->recordinglasterrormessage);
        $this->assertNull($restored->recordingtimelastattempt);
        $this->assertNull($restored->lastsync);
        $this->assertSame(0, $DB->count_records('googlemeet_recordings', [
            'googlemeetid' => $restored->id,
        ]));
    }
}
