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

namespace mod_googlemeet\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use mod_googlemeet\local\integration_mode;
use mod_googlemeet\local\recording_sync_state;
use mod_googlemeet\local\sync_state;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the Google Meet Privacy API provider.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(provider::class)]
final class provider_test extends \core_privacy\tests\provider_testcase {

    /** @var \stdClass Activity record. */
    private \stdClass $meeting;

    /** @var \context_module Activity context. */
    private \context_module $context;

    /** @var \stdClass Calendar owner. */
    private \stdClass $calendarowner;

    /** @var \stdClass Recording owner. */
    private \stdClass $recordingowner;

    /** @var \stdClass Legacy organizer. */
    private \stdClass $legacycreator;

    /** @var \stdClass Reminder recipient. */
    private \stdClass $recipient;

    /** @var \stdClass Managed Calendar attendee. */
    private \stdClass $attendee;

    /** @var int Local event ID. */
    private int $eventid;

    /**
     * Creates all supported personal-data relationships.
     */
    protected function setUp(): void {
        parent::setUp();
        global $DB;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $this->calendarowner = $generator->create_user();
        $this->recordingowner = $generator->create_user();
        $this->legacycreator = $generator->create_user();
        $this->recipient = $generator->create_user();
        $this->attendee = $generator->create_user();

        $this->meeting = $generator->get_plugin_generator('mod_googlemeet')->create_instance([
            'course' => $course->id,
            'integrationmode' => integration_mode::MANAGED,
            'creatoremail' => $this->legacycreator->email,
            'eventid' => 'legacy-event',
            'owneruserid' => $this->calendarowner->id,
            'oauthissuerid' => 11,
            'calendarid' => 'primary',
            'googleeventid' => 'event-123',
            'googleeventhtmlurl' => 'https://calendar.google.com/event?eid=123',
            'googleeventetag' => '"etag"',
            'requestid' => 'request-123',
            'conferenceid' => 'conference-123',
            'meetingcode' => 'abc-defg-hij',
            'meetinguri' => 'https://meet.google.com/abc-defg-hij',
            'url' => 'https://meet.google.com/abc-defg-hij',
            'syncstatus' => sync_state::READY,
            'syncattempts' => 2,
            'lasterrorcode' => 'safe_error',
            'lasterrormessage' => 'Safe diagnostic',
            'timelastattempt' => time() - HOURSECS,
            'guestpolicy' => \mod_googlemeet\local\calendar_guest_policy::COURSE,
            'guesthash' => str_repeat('a', 64),
            'guestcount' => 1,
            'guesttimelastsync' => time() - HOURSECS,
            'guesttimechecked' => time() - HOURSECS,
            'recordingowneruserid' => $this->recordingowner->id,
            'recordingoauthissuerid' => 12,
            'recordingsyncstatus' => recording_sync_state::READY,
            'recordingsyncattempts' => 1,
            'recordingtimelastattempt' => time() - HOURSECS,
        ]);
        $this->context = \context_module::instance($this->meeting->cmid);

        $eventdate = time();
        $this->eventid = $DB->insert_record('googlemeet_events', (object) [
            'googlemeetid' => $this->meeting->id,
            'occurrencekey' => hash('sha256', 'v1:' . $eventdate),
            'eventdate' => $eventdate,
            'duration' => HOURSECS,
            'timemodified' => time(),
        ]);
        $DB->insert_record('googlemeet_notify_done', (object) [
            'eventid' => $this->eventid,
            'userid' => $this->recipient->id,
            'timesent' => time(),
        ]);
        $DB->insert_record('googlemeet_calendar_guests', (object) [
            'googlemeetid' => $this->meeting->id,
            'userid' => $this->attendee->id,
            'emailhash' => hash('sha256', strtolower($this->attendee->email)),
            'timemodified' => time(),
        ]);
        $DB->insert_record('googlemeet_recordings', (object) [
            'googlemeetid' => $this->meeting->id,
            'recordingid' => 'DriveFile_123',
            'name' => 'Shared recording',
            'createdtime' => time(),
            'duration' => '1:00:00',
            'webviewlink' => 'https://drive.google.com/file/d/DriveFile_123/view',
            'visible' => 1,
            'timemodified' => time(),
        ]);
    }

    /**
     * Metadata declares local tables, external processors and core subsystems.
     */
    public function test_get_metadata_declares_complete_data_flow(): void {
        $items = provider::get_metadata(new collection('mod_googlemeet'))->get_collection();
        $names = array_map(static fn($item): string => $item->get_name(), $items);

        $this->assertCount(9, $names);
        $this->assertContains('googlemeet', $names);
        $this->assertContains('googlemeet_calendar_guests', $names);
        $this->assertContains('googlemeet_recordings', $names);
        $this->assertContains('googlemeet_notify_done', $names);
        $this->assertContains('google_calendar', $names);
        $this->assertContains('google_meet', $names);
        $this->assertContains('core_oauth2', $names);
        $this->assertContains('core_calendar', $names);
        $this->assertContains('core_message', $names);
    }

    /**
     * Every supported relationship is discoverable for user requests.
     */
    public function test_get_contexts_for_every_personal_data_relationship(): void {
        foreach ([
            $this->calendarowner,
            $this->recordingowner,
            $this->legacycreator,
            $this->recipient,
            $this->attendee,
        ] as $user) {
            $contextlist = provider::get_contexts_for_userid((int) $user->id);
            $this->assertCount(1, $contextlist);
            $this->assertSame((int) $this->context->id, (int) $contextlist->current()->id);
        }

        $unrelated = $this->getDataGenerator()->create_user();
        $this->assertCount(0, provider::get_contexts_for_userid((int) $unrelated->id));
    }

    /**
     * User discovery includes owners, legacy organizer and reminder recipient.
     */
    public function test_get_users_in_context_returns_all_relationships(): void {
        $userlist = new userlist($this->context, 'mod_googlemeet');
        provider::get_users_in_context($userlist);
        $userids = $userlist->get_userids();
        sort($userids);

        $expected = [
            (int) $this->calendarowner->id,
            (int) $this->recordingowner->id,
            (int) $this->legacycreator->id,
            (int) $this->recipient->id,
            (int) $this->attendee->id,
        ];
        sort($expected);
        $this->assertSame($expected, $userids);

        $invalid = new userlist(\context_system::instance(), 'mod_googlemeet');
        provider::get_users_in_context($invalid);
        $this->assertSame([], $invalid->get_userids());
    }

    /**
     * Each relationship produces an export under the activity context.
     */
    public function test_export_user_data_for_owners_and_recipient(): void {
        foreach ([
            $this->calendarowner,
            $this->recordingowner,
            $this->recipient,
            $this->attendee,
        ] as $user) {
            writer::reset();
            $this->export_context_data_for_user((int) $user->id, $this->context, 'mod_googlemeet');
            $this->assertTrue(writer::with_context($this->context)->has_any_data());
        }
    }

    /**
     * Legacy exports do not expose a different modern owner's remote identifiers.
     */
    public function test_legacy_export_is_bounded_to_legacy_identity(): void {
        writer::reset();
        $this->export_context_data_for_user(
            (int) $this->legacycreator->id,
            $this->context,
            'mod_googlemeet'
        );
        $data = writer::with_context($this->context)->get_data([
            get_string('privacy:path:calendar', 'mod_googlemeet'),
        ]);

        $this->assertSame((string) $this->legacycreator->email, $data->creatoremail);
        $this->assertSame('legacy-event', $data->legacyeventid);
        $this->assertFalse(property_exists($data, 'owneruserid'));
        $this->assertFalse(property_exists($data, 'googleeventid'));
        $this->assertFalse(property_exists($data, 'meetinguri'));
    }

    /**
     * Deleting one user detaches only that user's relationship.
     */
    public function test_delete_data_for_user_is_owner_scoped(): void {
        global $DB;

        $contextlist = new approved_contextlist(
            $this->calendarowner,
            'mod_googlemeet',
            [$this->context->id]
        );
        provider::delete_data_for_user($contextlist);
        $meeting = $DB->get_record('googlemeet', ['id' => $this->meeting->id], '*', MUST_EXIST);

        $this->assertNull($meeting->owneruserid);
        $this->assertNull($meeting->googleeventid);
        $this->assertSame((int) $this->recordingowner->id, (int) $meeting->recordingowneruserid);
        $this->assertSame((string) $this->legacycreator->email, $meeting->creatoremail);
        $this->assertTrue($DB->record_exists('googlemeet_recordings', [
            'googlemeetid' => $this->meeting->id,
        ]));
    }

    /**
     * Legacy organizer deletion does not detach a different Calendar owner.
     */
    public function test_delete_data_for_legacy_creator_is_email_scoped(): void {
        global $DB;

        $contextlist = new approved_contextlist(
            $this->legacycreator,
            'mod_googlemeet',
            [$this->context->id]
        );
        provider::delete_data_for_user($contextlist);
        $meeting = $DB->get_record('googlemeet', ['id' => $this->meeting->id], '*', MUST_EXIST);

        $this->assertNull($meeting->creatoremail);
        $this->assertNull($meeting->eventid);
        $this->assertSame((int) $this->calendarowner->id, (int) $meeting->owneruserid);
        $this->assertSame('event-123', $meeting->googleeventid);
    }

    /**
     * Managed attendee deletion removes only the approved local receipt.
     */
    public function test_delete_data_for_calendar_attendee_is_scoped(): void {
        global $DB;

        $contextlist = new approved_contextlist(
            $this->attendee,
            'mod_googlemeet',
            [$this->context->id]
        );
        provider::delete_data_for_user($contextlist);

        $this->assertFalse($DB->record_exists('googlemeet_calendar_guests', [
            'googlemeetid' => $this->meeting->id,
            'userid' => $this->attendee->id,
        ]));
        $meeting = $DB->get_record('googlemeet', ['id' => $this->meeting->id], '*', MUST_EXIST);
        $this->assertSame((int) $this->calendarowner->id, (int) $meeting->owneruserid);
        $this->assertNull($meeting->guesthash);
    }

    /**
     * Batch deletion removes approved users and leaves other relationships.
     */
    public function test_delete_data_for_users_is_bounded_to_approved_ids(): void {
        global $DB;

        $approved = new approved_userlist($this->context, 'mod_googlemeet', [
            $this->recordingowner->id,
            $this->recipient->id,
        ]);
        provider::delete_data_for_users($approved);
        $meeting = $DB->get_record('googlemeet', ['id' => $this->meeting->id], '*', MUST_EXIST);

        $this->assertNull($meeting->recordingowneruserid);
        $this->assertSame((int) $this->calendarowner->id, (int) $meeting->owneruserid);
        $this->assertFalse($DB->record_exists('googlemeet_notify_done', [
            'eventid' => $this->eventid,
            'userid' => $this->recipient->id,
        ]));
        $this->assertTrue($DB->record_exists('googlemeet_recordings', [
            'googlemeetid' => $this->meeting->id,
        ]));
    }

    /**
     * Context-wide deletion removes personal links but keeps shared recordings.
     */
    public function test_delete_data_for_all_users_preserves_shared_recordings(): void {
        global $DB;

        provider::delete_data_for_all_users_in_context($this->context);
        $meeting = $DB->get_record('googlemeet', ['id' => $this->meeting->id], '*', MUST_EXIST);

        $this->assertNull($meeting->owneruserid);
        $this->assertNull($meeting->recordingowneruserid);
        $this->assertNull($meeting->creatoremail);
        $this->assertSame('', $meeting->url);
        $this->assertFalse($DB->record_exists('googlemeet_notify_done', [
            'eventid' => $this->eventid,
        ]));
        $this->assertFalse($DB->record_exists('googlemeet_calendar_guests', [
            'googlemeetid' => $this->meeting->id,
        ]));
        $this->assertTrue($DB->record_exists('googlemeet_recordings', [
            'googlemeetid' => $this->meeting->id,
        ]));
    }
}
