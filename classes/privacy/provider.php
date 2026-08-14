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

/**
 * Privacy Subsystem implementation for mod_googlemeet.
 *
 * @package     mod_googlemeet
 * @copyright   2020 Rone Santos <ronefel@hotmail.com>
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_googlemeet\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\helper;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use mod_googlemeet\local\privacy_lifecycle;

/**
 * Declares, exports and deletes personal data stored by mod_googlemeet.
 *
 * Shared activity configuration and recording references are course content,
 * not owner-specific records. User deletion detaches authorization ownership
 * and owner-scoped synchronization fields without deleting those shared
 * references. The separate operational diagnostic table contains no user
 * identifier or free-form content and is governed by bounded site retention.
 */
class provider implements
        \core_privacy\local\metadata\provider,
        \core_privacy\local\request\plugin\provider,
        \core_privacy\local\request\core_userlist_provider {

    /**
     * Returns the plugin's local and external personal-data metadata.
     *
     * @param collection $collection Metadata collection.
     * @return collection Updated metadata collection.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'googlemeet',
            [
                'creatoremail' => 'privacy:metadata:googlemeet:creatoremail',
                'eventid' => 'privacy:metadata:googlemeet:eventid',
                'owneruserid' => 'privacy:metadata:googlemeet:owneruserid',
                'oauthissuerid' => 'privacy:metadata:googlemeet:oauthissuerid',
                'calendarid' => 'privacy:metadata:googlemeet:calendarid',
                'googleeventid' => 'privacy:metadata:googlemeet:googleeventid',
                'googleeventhtmlurl' => 'privacy:metadata:googlemeet:googleeventhtmlurl',
                'googleeventetag' => 'privacy:metadata:googlemeet:googleeventetag',
                'requestid' => 'privacy:metadata:googlemeet:requestid',
                'conferenceid' => 'privacy:metadata:googlemeet:conferenceid',
                'meetingcode' => 'privacy:metadata:googlemeet:meetingcode',
                'meetinguri' => 'privacy:metadata:googlemeet:meetinguri',
                'conferencestatus' => 'privacy:metadata:googlemeet:conferencestatus',
                'syncstatus' => 'privacy:metadata:googlemeet:syncstatus',
                'syncattempts' => 'privacy:metadata:googlemeet:syncattempts',
                'lasterrorcode' => 'privacy:metadata:googlemeet:lasterrorcode',
                'lasterrormessage' => 'privacy:metadata:googlemeet:lasterrormessage',
                'timelastattempt' => 'privacy:metadata:googlemeet:timelastattempt',
                'guesthash' => 'privacy:metadata:googlemeet:guesthash',
                'guestcount' => 'privacy:metadata:googlemeet:guestcount',
                'guesttimelastsync' => 'privacy:metadata:googlemeet:guesttimelastsync',
                'guesttimechecked' => 'privacy:metadata:googlemeet:guesttimechecked',
                'recordingowneruserid' => 'privacy:metadata:googlemeet:recordingowneruserid',
                'recordingoauthissuerid' => 'privacy:metadata:googlemeet:recordingoauthissuerid',
                'recordingsyncstatus' => 'privacy:metadata:googlemeet:recordingsyncstatus',
                'recordingsyncattempts' => 'privacy:metadata:googlemeet:recordingsyncattempts',
                'recordinglasterrorcode' => 'privacy:metadata:googlemeet:recordinglasterrorcode',
                'recordinglasterrormessage' => 'privacy:metadata:googlemeet:recordinglasterrormessage',
                'recordingtimelastattempt' => 'privacy:metadata:googlemeet:recordingtimelastattempt',
            ],
            'privacy:metadata:googlemeet'
        );
        $collection->add_database_table(
            'googlemeet_calendar_guests',
            [
                'userid' => 'privacy:metadata:googlemeet_calendar_guests:userid',
                'emailhash' => 'privacy:metadata:googlemeet_calendar_guests:emailhash',
                'timemodified' => 'privacy:metadata:googlemeet_calendar_guests:timemodified',
            ],
            'privacy:metadata:googlemeet_calendar_guests'
        );
        $collection->add_database_table(
            'googlemeet_recordings',
            [
                'recordingid' => 'privacy:metadata:googlemeet_recordings:recordingid',
                'name' => 'privacy:metadata:googlemeet_recordings:name',
                'createdtime' => 'privacy:metadata:googlemeet_recordings:createdtime',
                'duration' => 'privacy:metadata:googlemeet_recordings:duration',
                'webviewlink' => 'privacy:metadata:googlemeet_recordings:webviewlink',
                'visible' => 'privacy:metadata:googlemeet_recordings:visible',
            ],
            'privacy:metadata:googlemeet_recordings'
        );
        $collection->add_database_table(
            'googlemeet_notify_done',
            [
                'eventid' => 'privacy:metadata:googlemeet_notify_done:eventid',
                'userid' => 'privacy:metadata:googlemeet_notify_done:userid',
                'timesent' => 'privacy:metadata:googlemeet_notify_done:timesent',
            ],
            'privacy:metadata:googlemeet_notify_done'
        );
        $collection->add_external_location_link(
            'google_calendar',
            [
                'authorizedaccount' => 'privacy:metadata:google_calendar:authorizedaccount',
                'calendarlist' => 'privacy:metadata:google_calendar:calendarlist',
                'summary' => 'privacy:metadata:google_calendar:summary',
                'schedule' => 'privacy:metadata:google_calendar:schedule',
                'timezone' => 'privacy:metadata:google_calendar:timezone',
                'recurrence' => 'privacy:metadata:google_calendar:recurrence',
                'conference' => 'privacy:metadata:google_calendar:conference',
                'attendees' => 'privacy:metadata:google_calendar:attendees',
            ],
            'privacy:metadata:google_calendar'
        );
        $collection->add_external_location_link(
            'google_meet',
            [
                'authorizedaccount' => 'privacy:metadata:google_meet:authorizedaccount',
                'meetingcode' => 'privacy:metadata:google_meet:meetingcode',
                'recordings' => 'privacy:metadata:google_meet:recordings',
            ],
            'privacy:metadata:google_meet'
        );
        $collection->add_subsystem_link('core_oauth2', [], 'privacy:metadata:core_oauth2');
        $collection->add_subsystem_link('core_calendar', [], 'privacy:metadata:core_calendar');
        $collection->add_subsystem_link('core_message', [], 'privacy:metadata:core_message');

        return $collection;
    }

    /**
     * Gets contexts containing data for one user.
     *
     * @param int $userid User ID.
     * @return contextlist Context list.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;

        $email = (string) $DB->get_field('user', 'email', ['id' => $userid]);
        $emailcondition = '1 = 0';
        $params = [
            'modname' => 'googlemeet',
            'contextlevel' => CONTEXT_MODULE,
            'calendarowner' => $userid,
            'recordingowner' => $userid,
            'notificationuser' => $userid,
            'calendarattendee' => $userid,
        ];
        if ($email !== '') {
            $emailcondition = $DB->sql_equal('g.creatoremail', ':legacyemail', false);
            $params['legacyemail'] = $email;
        }

        $sql = "SELECT DISTINCT c.id
                  FROM {context} c
                  JOIN {course_modules} cm
                    ON cm.id = c.instanceid
                   AND c.contextlevel = :contextlevel
                  JOIN {modules} m
                    ON m.id = cm.module
                   AND m.name = :modname
                  JOIN {googlemeet} g
                    ON g.id = cm.instance
                 WHERE g.owneruserid = :calendarowner
                    OR g.recordingowneruserid = :recordingowner
                    OR {$emailcondition}
                    OR EXISTS (
                           SELECT 1
                             FROM {googlemeet_calendar_guests} gcg
                            WHERE gcg.googlemeetid = g.id
                              AND gcg.userid = :calendarattendee
                       )
                    OR EXISTS (
                           SELECT 1
                             FROM {googlemeet_events} ge
                             JOIN {googlemeet_notify_done} gnd ON gnd.eventid = ge.id
                            WHERE ge.googlemeetid = g.id
                              AND gnd.userid = :notificationuser
                       )";

        $contextlist = new contextlist();
        $contextlist->add_from_sql($sql, $params);
        return $contextlist;
    }

    /**
     * Adds users with personal data in one module context.
     *
     * @param userlist $userlist Context-bound user list.
     */
    public static function get_users_in_context(userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }

        $baseparams = [
            'cmid' => $context->instanceid,
            'modulename' => 'googlemeet',
        ];
        $basejoin = ' FROM {course_modules} cm
                       JOIN {modules} m
                         ON m.id = cm.module
                        AND m.name = :modulename
                       JOIN {googlemeet} g ON g.id = cm.instance';

        $userlist->add_from_sql(
            'userid',
            'SELECT g.owneruserid AS userid' . $basejoin
                . ' WHERE cm.id = :cmid AND g.owneruserid IS NOT NULL',
            $baseparams
        );
        $guestparams = [
            'guestcmid' => $context->instanceid,
            'guestmodule' => 'googlemeet',
        ];
        $guestsql = "SELECT gcg.userid
                       FROM {course_modules} cm
                       JOIN {modules} m
                         ON m.id = cm.module
                        AND m.name = :guestmodule
                       JOIN {googlemeet} g ON g.id = cm.instance
                       JOIN {googlemeet_calendar_guests} gcg ON gcg.googlemeetid = g.id
                      WHERE cm.id = :guestcmid";
        $userlist->add_from_sql('userid', $guestsql, $guestparams);
        $userlist->add_from_sql(
            'userid',
            'SELECT g.recordingowneruserid AS userid' . $basejoin
                . ' WHERE cm.id = :cmid AND g.recordingowneruserid IS NOT NULL',
            $baseparams
        );

        $notificationparams = [
            'notificationcmid' => $context->instanceid,
            'notificationmodule' => 'googlemeet',
        ];
        $notificationsql = "SELECT gnd.userid
                              FROM {course_modules} cm
                              JOIN {modules} m
                                ON m.id = cm.module
                               AND m.name = :notificationmodule
                              JOIN {googlemeet} g ON g.id = cm.instance
                              JOIN {googlemeet_events} ge ON ge.googlemeetid = g.id
                              JOIN {googlemeet_notify_done} gnd ON gnd.eventid = ge.id
                             WHERE cm.id = :notificationcmid";
        $userlist->add_from_sql('userid', $notificationsql, $notificationparams);

        $legacyparams = [
            'legacycmid' => $context->instanceid,
            'legacymodule' => 'googlemeet',
        ];
        $legacyemailcondition = $DB->sql_equal('u.email', 'g.creatoremail', false);
        $legacysql = "SELECT u.id AS userid
                        FROM {course_modules} cm
                        JOIN {modules} m
                          ON m.id = cm.module
                         AND m.name = :legacymodule
                        JOIN {googlemeet} g ON g.id = cm.instance
                        JOIN {user} u ON {$legacyemailcondition}
                       WHERE cm.id = :legacycmid
                         AND g.creatoremail IS NOT NULL";
        $userlist->add_from_sql('userid', $legacysql, $legacyparams);
    }

    /**
     * Exports a user's personal data in approved contexts.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        if (!count($contextlist)) {
            return;
        }

        $user = $contextlist->get_user();
        foreach ($contextlist->get_contexts() as $context) {
            $googlemeetid = self::googlemeetid_from_context($context);
            if ($googlemeetid === null) {
                continue;
            }

            $meeting = $DB->get_record('googlemeet', ['id' => $googlemeetid], '*', MUST_EXIST);
            $iscalendarowner = (int) ($meeting->owneruserid ?? 0) === (int) $user->id;
            $isrecordingowner = (int) ($meeting->recordingowneruserid ?? 0) === (int) $user->id;
            $islegacycreator = self::same_email((string) ($meeting->creatoremail ?? ''), (string) $user->email);
            $notifications = self::notification_export_data($googlemeetid, (int) $user->id);
            $guestdata = self::guest_export_data($googlemeetid, (int) $user->id);

            if (
                !$iscalendarowner
                && !$isrecordingowner
                && !$islegacycreator
                && !$notifications
                && $guestdata === null
            ) {
                continue;
            }

            writer::with_context($context)->export_data([], helper::get_context_data($context, $user));

            if ($iscalendarowner || $islegacycreator) {
                $calendardata = [];
                if ($islegacycreator) {
                    $calendardata['creatoremail'] = (string) $meeting->creatoremail;
                    $calendardata['legacyeventid'] = $meeting->eventid;
                }
                if ($iscalendarowner) {
                    $calendardata += [
                        'owneruserid' => (int) $meeting->owneruserid,
                        'oauthissuerid' => $meeting->oauthissuerid,
                        'calendarid' => $meeting->calendarid,
                        'googleeventid' => $meeting->googleeventid,
                        'googleeventhtmlurl' => $meeting->googleeventhtmlurl,
                        'googleeventetag' => $meeting->googleeventetag,
                        'requestid' => $meeting->requestid,
                        'conferenceid' => $meeting->conferenceid,
                        'meetingcode' => $meeting->meetingcode,
                        'meetinguri' => $meeting->meetinguri,
                        'conferencestatus' => $meeting->conferencestatus,
                        'syncstatus' => $meeting->syncstatus,
                        'syncattempts' => (int) $meeting->syncattempts,
                        'lasterrorcode' => $meeting->lasterrorcode,
                        'lasterrormessage' => $meeting->lasterrormessage,
                        'timelastattempt' => self::export_datetime($meeting->timelastattempt),
                        'guestpolicy' => $meeting->guestpolicy,
                        'guestcount' => (int) $meeting->guestcount,
                        'guesttimelastsync' => self::export_datetime($meeting->guesttimelastsync),
                        'guesttimechecked' => self::export_datetime($meeting->guesttimechecked),
                    ];
                }
                writer::with_context($context)->export_data(
                    [get_string('privacy:path:calendar', 'mod_googlemeet')],
                    (object) $calendardata
                );
            }

            if ($isrecordingowner) {
                $recordingdata = (object) [
                    'owneruserid' => (int) $meeting->recordingowneruserid,
                    'oauthissuerid' => $meeting->recordingoauthissuerid,
                    'syncstatus' => $meeting->recordingsyncstatus,
                    'syncattempts' => (int) $meeting->recordingsyncattempts,
                    'lasterrorcode' => $meeting->recordinglasterrorcode,
                    'lasterrormessage' => $meeting->recordinglasterrormessage,
                    'timelastattempt' => self::export_datetime($meeting->recordingtimelastattempt),
                ];
                writer::with_context($context)->export_data(
                    [get_string('privacy:path:recordingauthorization', 'mod_googlemeet')],
                    $recordingdata
                );

                $recordings = array_values($DB->get_records(
                    'googlemeet_recordings',
                    ['googlemeetid' => $googlemeetid],
                    'createdtime ASC, id ASC',
                    'recordingid, name, createdtime, duration, webviewlink, visible, timemodified'
                ));
                if ($recordings) {
                    foreach ($recordings as $recording) {
                        $recording->createdtime = transform::datetime((int) $recording->createdtime);
                        $recording->timemodified = transform::datetime((int) $recording->timemodified);
                    }
                    writer::with_context($context)->export_data(
                        [get_string('privacy:path:recordings', 'mod_googlemeet')],
                        (object) ['recordings' => $recordings]
                    );
                }
            }

            if ($notifications) {
                writer::with_context($context)->export_data(
                    [get_string('privacy:path:notifications', 'mod_googlemeet')],
                    (object) ['notifications' => $notifications]
                );
            }
            if ($guestdata !== null) {
                writer::with_context($context)->export_data(
                    [get_string('privacy:path:calendarattendee', 'mod_googlemeet')],
                    $guestdata
                );
            }
        }
    }

    /**
     * Deletes personal data for all users in one context.
     *
     * @param \context $context Context to erase.
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        $googlemeetid = self::googlemeetid_from_context($context);
        if ($googlemeetid !== null) {
            (new privacy_lifecycle())->delete_all_user_data($googlemeetid);
        }
    }

    /**
     * Deletes one user's data in approved contexts.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        if (!count($contextlist)) {
            return;
        }

        $user = $contextlist->get_user();
        foreach ($contextlist->get_contexts() as $context) {
            $googlemeetid = self::googlemeetid_from_context($context);
            if ($googlemeetid !== null) {
                (new privacy_lifecycle())->delete_user_data(
                    $googlemeetid,
                    (int) $user->id,
                    (string) $user->email
                );
            }
        }
    }

    /**
     * Deletes several approved users' data from one context.
     *
     * @param approved_userlist $userlist Approved context and user IDs.
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $googlemeetid = self::googlemeetid_from_context($userlist->get_context());
        $userids = $userlist->get_userids();
        if ($googlemeetid === null || !$userids) {
            return;
        }

        $users = $DB->get_records_list('user', 'id', $userids, '', 'id, email');
        $emails = array_map(static fn(\stdClass $user): string => (string) $user->email, $users);
        (new privacy_lifecycle())->delete_users_data($googlemeetid, $userids, $emails);
    }

    /**
     * Resolves a Google Meet instance only for a valid module context.
     *
     * @param \context $context Context.
     * @return int|null Activity instance ID.
     */
    private static function googlemeetid_from_context(\context $context): ?int {
        if (!$context instanceof \context_module) {
            return null;
        }

        $cm = get_coursemodule_from_id('googlemeet', $context->instanceid, 0, false, IGNORE_MISSING);
        return $cm ? (int) $cm->instance : null;
    }

    /**
     * Returns exportable notification receipts.
     *
     * @param int $googlemeetid Activity instance ID.
     * @param int $userid User ID.
     * @return \stdClass[]
     */
    private static function notification_export_data(int $googlemeetid, int $userid): array {
        global $DB;

        $sql = "SELECT gnd.id, gnd.eventid, gnd.timesent
                  FROM {googlemeet_events} ge
                  JOIN {googlemeet_notify_done} gnd ON gnd.eventid = ge.id
                 WHERE ge.googlemeetid = :googlemeetid
                   AND gnd.userid = :userid
              ORDER BY gnd.timesent ASC, gnd.id ASC";
        $records = array_values($DB->get_records_sql($sql, [
            'googlemeetid' => $googlemeetid,
            'userid' => $userid,
        ]));
        foreach ($records as $record) {
            unset($record->id);
            $record->timesent = transform::datetime((int) $record->timesent);
        }
        return $records;
    }

    /**
     * Returns one user's local managed-attendee receipt.
     *
     * @param int $googlemeetid Activity instance ID.
     * @param int $userid User ID.
     * @return \stdClass|null
     */
    private static function guest_export_data(int $googlemeetid, int $userid): ?\stdClass {
        global $DB;

        $record = $DB->get_record('googlemeet_calendar_guests', [
            'googlemeetid' => $googlemeetid,
            'userid' => $userid,
        ], 'userid, emailhash, timemodified', IGNORE_MISSING);
        if (!$record) {
            return null;
        }
        $record->timemodified = transform::datetime((int) $record->timemodified);

        return $record;
    }

    /**
     * Compares a legacy organizer email with a Moodle user's email.
     *
     * @param string $first First email.
     * @param string $second Second email.
     * @return bool
     */
    private static function same_email(string $first, string $second): bool {
        return $first !== ''
            && $second !== ''
            && \core_text::strtolower(trim($first)) === \core_text::strtolower(trim($second));
    }

    /**
     * Exports an optional timestamp.
     *
     * @param mixed $value Timestamp or null.
     * @return string|null
     */
    private static function export_datetime(mixed $value): ?string {
        return empty($value) ? null : transform::datetime((int) $value);
    }
}
