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
 * Tests for owner data detachment and privacy deletion.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(privacy_lifecycle::class)]
final class privacy_lifecycle_test extends \advanced_testcase {

    /**
     * Calendar detachment is owner-scoped and requires remote cancellation.
     */
    public function test_calendar_disconnect_requires_owner_and_cancelled_state(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $meeting = $this->create_meeting([
            'integrationmode' => integration_mode::MANAGED,
            'owneruserid' => $owner->id,
            'syncstatus' => sync_state::READY,
        ]);
        $lifecycle = new privacy_lifecycle();

        try {
            $lifecycle->disconnect_calendar($meeting->id, $other->id);
            $this->fail('A non-owner disconnected the Calendar integration.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('managedowneronly', $exception->errorcode);
        }

        try {
            $lifecycle->disconnect_calendar($meeting->id, $owner->id);
            $this->fail('An active Calendar event was detached before cancellation.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('syncdisconnectrequirescancel', $exception->errorcode);
        }
    }

    /**
     * A settled Calendar integration can be detached without affecting recordings.
     */
    public function test_calendar_disconnect_clears_remote_identity_only(): void {
        global $DB;

        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $recordingowner = $this->getDataGenerator()->create_user();
        $meeting = $this->create_meeting([
            'integrationmode' => integration_mode::MANAGED,
            'owneruserid' => $owner->id,
            'oauthissuerid' => 11,
            'calendarid' => 'primary',
            'googleeventid' => 'event-123',
            'googleeventhtmlurl' => 'https://calendar.google.com/event?eid=123',
            'googleeventetag' => '"etag"',
            'requestid' => 'request-123',
            'conferenceid' => 'conference-123',
            'meetingcode' => 'abc-defg-hij',
            'meetinguri' => null,
            'url' => '',
            'syncstatus' => sync_state::CANCELLED,
            'syncattempts' => 3,
            'lasterrorcode' => 'old',
            'lasterrormessage' => 'Old safe error',
            'timelastattempt' => time() - HOURSECS,
            'recordingowneruserid' => $recordingowner->id,
            'recordingoauthissuerid' => 12,
            'recordingsyncstatus' => recording_sync_state::READY,
        ]);
        $recordingid = $this->insert_recording($meeting->id);

        $updated = (new privacy_lifecycle())->disconnect_calendar($meeting->id, $owner->id);

        $this->assertNull($updated->owneruserid);
        $this->assertNull($updated->oauthissuerid);
        $this->assertNull($updated->googleeventid);
        $this->assertNull($updated->requestid);
        $this->assertSame(sync_state::DISCONNECTED, $updated->syncstatus);
        $this->assertSame(0, (int) $updated->syncattempts);
        $this->assertNull($updated->lasterrormessage);
        $this->assertSame((int) $recordingowner->id, (int) $updated->recordingowneruserid);
        $this->assertSame(recording_sync_state::READY, $updated->recordingsyncstatus);
        $this->assertTrue($DB->record_exists('googlemeet_recordings', ['id' => $recordingid]));
    }

    /**
     * Recording detachment preserves shared references and Calendar ownership.
     */
    public function test_recording_disconnect_preserves_shared_references(): void {
        global $DB;

        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $meeting = $this->create_meeting([
            'integrationmode' => integration_mode::MANAGED,
            'owneruserid' => $owner->id,
            'oauthissuerid' => 11,
            'syncstatus' => sync_state::READY,
            'recordingowneruserid' => $owner->id,
            'recordingoauthissuerid' => 12,
            'recordingsyncstatus' => recording_sync_state::FAILED,
            'recordingsyncattempts' => 4,
            'recordinglasterrorcode' => 'api_rejected',
            'recordinglasterrormessage' => 'Safe failure',
            'recordingtimelastattempt' => time() - HOURSECS,
        ]);
        $recordingid = $this->insert_recording($meeting->id);

        $updated = (new privacy_lifecycle())->disconnect_recordings($meeting->id, $owner->id);

        $this->assertNull($updated->recordingowneruserid);
        $this->assertNull($updated->recordingoauthissuerid);
        $this->assertSame(recording_sync_state::DISCONNECTED, $updated->recordingsyncstatus);
        $this->assertSame(0, (int) $updated->recordingsyncattempts);
        $this->assertNull($updated->recordinglasterrorcode);
        $this->assertSame((int) $owner->id, (int) $updated->owneruserid);
        $this->assertSame(sync_state::READY, $updated->syncstatus);
        $this->assertTrue($DB->record_exists('googlemeet_recordings', ['id' => $recordingid]));
    }

    /**
     * User deletion removes only matching owner links and reminder receipts.
     */
    public function test_user_deletion_is_scoped_to_matching_user(): void {
        global $DB;

        $this->resetAfterTest();
        $calendarowner = $this->getDataGenerator()->create_user();
        $recordingowner = $this->getDataGenerator()->create_user();
        $meeting = $this->create_meeting([
            'integrationmode' => integration_mode::MANAGED,
            'owneruserid' => $calendarowner->id,
            'oauthissuerid' => 11,
            'calendarid' => 'primary',
            'googleeventid' => 'event-123',
            'meetinguri' => 'https://meet.google.com/abc-defg-hij',
            'url' => 'https://meet.google.com/abc-defg-hij',
            'syncstatus' => sync_state::READY,
            'recordingowneruserid' => $recordingowner->id,
            'recordingoauthissuerid' => 12,
            'recordingsyncstatus' => recording_sync_state::READY,
        ]);
        $eventid = $this->insert_event($meeting->id);
        $DB->insert_record('googlemeet_notify_done', (object) [
            'eventid' => $eventid,
            'userid' => $calendarowner->id,
            'timesent' => time(),
        ]);
        $DB->insert_record('googlemeet_notify_done', (object) [
            'eventid' => $eventid,
            'userid' => $recordingowner->id,
            'timesent' => time(),
        ]);
        $recordingid = $this->insert_recording($meeting->id);

        (new privacy_lifecycle())->delete_user_data(
            $meeting->id,
            (int) $calendarowner->id,
            (string) $calendarowner->email
        );
        $updated = $DB->get_record('googlemeet', ['id' => $meeting->id], '*', MUST_EXIST);

        $this->assertNull($updated->owneruserid);
        $this->assertSame('', $updated->url);
        $this->assertSame((int) $recordingowner->id, (int) $updated->recordingowneruserid);
        $this->assertFalse($DB->record_exists('googlemeet_notify_done', [
            'eventid' => $eventid,
            'userid' => $calendarowner->id,
        ]));
        $this->assertTrue($DB->record_exists('googlemeet_notify_done', [
            'eventid' => $eventid,
            'userid' => $recordingowner->id,
        ]));
        $this->assertTrue($DB->record_exists('googlemeet_recordings', ['id' => $recordingid]));
    }

    /**
     * Context-wide deletion preserves manual links and shared recordings.
     */
    public function test_delete_all_preserves_shared_manual_content(): void {
        global $DB;

        $this->resetAfterTest();
        $creator = $this->getDataGenerator()->create_user();
        $meeting = $this->create_meeting([
            'integrationmode' => integration_mode::MANUAL,
            'creatoremail' => $creator->email,
            'eventid' => 'legacy-event',
            'url' => 'https://meet.google.com/abc-defg-hij',
            'meetinguri' => 'https://meet.google.com/abc-defg-hij',
            'syncstatus' => sync_state::READY,
        ]);
        $eventid = $this->insert_event($meeting->id);
        $DB->insert_record('googlemeet_notify_done', (object) [
            'eventid' => $eventid,
            'userid' => $creator->id,
            'timesent' => time(),
        ]);
        $recordingid = $this->insert_recording($meeting->id);

        (new privacy_lifecycle())->delete_all_user_data($meeting->id);
        $updated = $DB->get_record('googlemeet', ['id' => $meeting->id], '*', MUST_EXIST);

        $this->assertNull($updated->creatoremail);
        $this->assertNull($updated->eventid);
        $this->assertSame('https://meet.google.com/abc-defg-hij', $updated->url);
        $this->assertSame('https://meet.google.com/abc-defg-hij', $updated->meetinguri);
        $this->assertSame(sync_state::READY, $updated->syncstatus);
        $this->assertFalse($DB->record_exists('googlemeet_notify_done', ['eventid' => $eventid]));
        $this->assertTrue($DB->record_exists('googlemeet_recordings', ['id' => $recordingid]));
    }

    /**
     * Creates an activity without contacting Google.
     *
     * @param array<string, mixed> $fields Activity fields.
     * @return \stdClass
     */
    private function create_meeting(array $fields): \stdClass {
        $course = $this->getDataGenerator()->create_course();
        return $this->getDataGenerator()->get_plugin_generator('mod_googlemeet')->create_instance(
            ['course' => $course->id] + $fields
        );
    }

    /**
     * Inserts one local event.
     *
     * @param int $googlemeetid Activity instance ID.
     * @return int Event ID.
     */
    private function insert_event(int $googlemeetid): int {
        global $DB;

        return $DB->insert_record('googlemeet_events', (object) [
            'googlemeetid' => $googlemeetid,
            'eventdate' => time(),
            'duration' => HOURSECS,
            'timemodified' => time(),
        ]);
    }

    /**
     * Inserts one shared recording reference.
     *
     * @param int $googlemeetid Activity instance ID.
     * @return int Recording ID.
     */
    private function insert_recording(int $googlemeetid): int {
        global $DB;

        return $DB->insert_record('googlemeet_recordings', (object) [
            'googlemeetid' => $googlemeetid,
            'recordingid' => 'DriveFile_' . $googlemeetid,
            'name' => 'Shared recording',
            'createdtime' => time(),
            'duration' => '1:00:00',
            'webviewlink' => 'https://drive.google.com/file/d/DriveFile_' . $googlemeetid . '/view',
            'visible' => 1,
            'timemodified' => time(),
        ]);
    }
}
