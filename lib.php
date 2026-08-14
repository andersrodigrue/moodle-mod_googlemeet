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
 * Library of interface functions and constants.
 *
 * @package     mod_googlemeet
 * @copyright   2020 Rone Santos <ronefel@hotmail.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_googlemeet\api\calendar_api_exception;
use mod_googlemeet\api\calendar_authorization_exception;
use mod_googlemeet\api\calendar_configuration_exception;
use mod_googlemeet\api\calendar_response_exception;
use mod_googlemeet\api\calendar_transport_exception;
use mod_googlemeet\api\google_calendar_client;
use mod_googlemeet\api\moodle_oauth_http_client;
use mod_googlemeet\api\recording_artifact;
use mod_googlemeet\local\calendar_catalog;
use mod_googlemeet\local\calendar_guest_policy;
use mod_googlemeet\local\integration_mode;
use mod_googlemeet\local\meeting_form_data;
use mod_googlemeet\local\meeting_lock;
use mod_googlemeet\local\meeting_manager;
use mod_googlemeet\local\oauth_manager;
use mod_googlemeet\local\remote_cleanup_manager;
use mod_googlemeet\local\schedule_manager;
use mod_googlemeet\local\sync_state;

/**
 * Return if the plugin supports $feature.
 *
 * @param string $feature Constant representing the feature.
 * @return true | null True if the feature is supported, null otherwise.
 */
function googlemeet_supports($feature) {
    switch ($feature) {
        case FEATURE_MOD_ARCHETYPE:
            return MOD_ARCHETYPE_RESOURCE;
        case FEATURE_GROUPS:
            return false;
        case FEATURE_GROUPINGS:
            return false;
        case FEATURE_MOD_INTRO:
            return true;
        case FEATURE_MOD_PURPOSE:
            return MOD_PURPOSE_COMMUNICATION;
        case FEATURE_COMPLETION_TRACKS_VIEWS:
            return true;
        case FEATURE_GRADE_HAS_GRADE:
            return false;
        case FEATURE_GRADE_OUTCOMES:
            return false;
        case FEATURE_BACKUP_MOODLE2:
            return true;
        case FEATURE_SHOW_DESCRIPTION:
            return true;
        default:
            return null;
    }
}

/**
 * Saves a new instance of the mod_googlemeet into the database.
 *
 * Given an object containing all the necessary data, (defined by the form
 * in mod_form.php) this function will create a new instance and return the id
 * number of the instance.
 *
 * @param object $googlemeet An object from the form.
 * @param mod_googlemeet_mod_form $mform The form.
 * @return int The id of the newly inserted record.
 */
function googlemeet_add_instance($googlemeet, $mform = null) {
    global $DB, $CFG, $USER;
    require_once($CFG->dirroot . '/mod/googlemeet/locallib.php');

    $googlemeet = (new meeting_form_data())->normalize(
        $googlemeet,
        get_user_timezone($USER->timezone)
    );
    $googlemeet = googlemeet_prepare_integration($googlemeet);

    $googlemeet->meetinguri = $googlemeet->url;
    $googlemeet->timecreated = time();
    $googlemeet->timemodified = $googlemeet->timecreated;

    if (!$googlemeet->id = $DB->insert_record('googlemeet', $googlemeet)) {
        return false;
    }

    (new schedule_manager())->synchronise($googlemeet);
    if ($googlemeet->integrationmode === integration_mode::MANAGED) {
        (new meeting_manager())->queue((int) $googlemeet->id, (int) $googlemeet->owneruserid);
    }

    return $googlemeet->id;
}

/**
 * Updates an instance of the mod_googlemeet in the database.
 *
 * Given an object containing all the necessary data (defined in mod_form.php),
 * this function will update an existing instance with new data.
 *
 * @param object $googlemeet An object from the form in mod_form.php.
 * @param mod_googlemeet_mod_form $mform The form.
 * @return bool True if successful, false otherwise.
 */
function googlemeet_update_instance($googlemeet, $mform = null) {
    global $DB, $CFG, $USER;
    require_once($CFG->dirroot . '/mod/googlemeet/locallib.php');

    $googlemeet->id = $googlemeet->instance;
    $existing = $DB->get_record('googlemeet', ['id' => $googlemeet->id], '*', MUST_EXIST);
    if (empty($googlemeet->integrationmode)) {
        $googlemeet->integrationmode = $existing->integrationmode;
    }

    $googlemeet = (new meeting_form_data())->normalize(
        $googlemeet,
        get_user_timezone($USER->timezone),
        $existing
    );
    $googlemeet = googlemeet_prepare_integration($googlemeet, $existing);

    $googlemeet->timemodified = time();

    $googlemeetupdated = $DB->update_record('googlemeet', $googlemeet);

    (new schedule_manager())->synchronise($googlemeet);
    if ($googlemeet->integrationmode === integration_mode::MANAGED) {
        (new meeting_manager())->queue((int) $googlemeet->id, (int) $googlemeet->owneruserid);
    }

    return $googlemeetupdated;
}

/**
 * Applies server-owned integration fields to normalized form data.
 *
 * OAuth owner, issuer and remote identifiers never come from submitted form
 * fields. Existing managed meetings cannot be silently transferred to another
 * Moodle user or downgraded to a manual link.
 *
 * @param stdClass $googlemeet Normalized form data.
 * @param stdClass|null $existing Existing activity during update.
 * @param oauth_manager|null $oauthmanager OAuth manager override for tests.
 * @param calendar_catalog|null $calendarcatalog Calendar catalog override for tests.
 * @return stdClass Prepared record.
 */
function googlemeet_prepare_integration(
    stdClass $googlemeet,
    ?stdClass $existing = null,
    ?oauth_manager $oauthmanager = null,
    ?calendar_catalog $calendarcatalog = null
): stdClass {
    global $CFG, $USER;

    require_once($CFG->dirroot . '/mod/googlemeet/locallib.php');

    $mode = (string) ($googlemeet->integrationmode ?? '');
    if (
        $existing !== null &&
        $existing->integrationmode === integration_mode::MANAGED &&
        $mode !== integration_mode::MANAGED
    ) {
        throw new moodle_exception('managedmodecannotchange', 'mod_googlemeet');
    }
    if ($mode === integration_mode::MANUAL) {
        $url = googlemeet_clear_url((string) ($googlemeet->url ?? ''));
        if ($url === null) {
            throw new moodle_exception('url_failed', 'mod_googlemeet');
        }

        $googlemeet->url = $url;
        $googlemeet->meetinguri = $url;
        $googlemeet->integrationmode = integration_mode::MANUAL;
        $googlemeet->creatoremail = null;
        $googlemeet->owneruserid = null;
        $googlemeet->oauthissuerid = null;
        $googlemeet->calendarid = null;
        $googlemeet->eventid = null;
        $googlemeet->googleeventid = null;
        $googlemeet->googleeventhtmlurl = null;
        $googlemeet->googleeventetag = null;
        $googlemeet->requestid = null;
        $googlemeet->conferenceid = null;
        $googlemeet->meetingcode = null;
        $googlemeet->guestpolicy = calendar_guest_policy::NONE;
        $googlemeet->guesthash = null;
        $googlemeet->guestcount = 0;
        $googlemeet->guesttimelastsync = null;
        $googlemeet->guesttimechecked = null;
        $googlemeet->sendupdates = 'none';
        $googlemeet->syncstatus = sync_state::READY;
        $googlemeet->conferencestatus = null;
        $googlemeet->syncattempts = 0;
        $googlemeet->lasterrorcode = null;
        $googlemeet->lasterrormessage = null;
        $googlemeet->timelastattempt = null;

        return $googlemeet;
    }

    if ($mode !== integration_mode::MANAGED) {
        throw new moodle_exception('invalidintegrationmode', 'mod_googlemeet');
    }

    if (
        $existing !== null &&
        $existing->integrationmode === integration_mode::MANAGED &&
        (int) ($existing->owneruserid ?? 0) > 0 &&
        (int) $existing->owneruserid !== (int) $USER->id
    ) {
        throw new moodle_exception('managedowneronly', 'mod_googlemeet');
    }

    $issuerid = (int) get_config('googlemeet', 'issuerid');
    if ($issuerid <= 0) {
        throw new moodle_exception('managedoauthunavailable', 'mod_googlemeet');
    }
    try {
        $client = ($oauthmanager ?? new oauth_manager())->authorization_client($issuerid, (int) $USER->id);
        $authorized = $client->is_logged_in();
    } catch (calendar_authorization_exception | moodle_exception) {
        throw new moodle_exception('managedoauthunavailable', 'mod_googlemeet');
    }
    if (!$authorized) {
        throw new moodle_exception('managedoauthrequired', 'mod_googlemeet');
    }

    $calendarid = trim((string) ($googlemeet->calendarid ?? ''));
    if (
        $existing !== null
        && ($existing->integrationmode ?? null) === integration_mode::MANAGED
        && trim((string) ($existing->calendarid ?? '')) !== ''
    ) {
        // Once attached, a managed activity keeps its exact Calendar. A forged
        // form value cannot redirect an existing Google event to another one.
        $calendarid = trim((string) $existing->calendarid);
    }
    if ($calendarid === '') {
        throw new moodle_exception('managedcalendarrequired', 'mod_googlemeet');
    }

    try {
        $catalog = $calendarcatalog ?? new calendar_catalog(
            new google_calendar_client(new moodle_oauth_http_client($client))
        );
        $calendar = $catalog->require_writable($calendarid);
    } catch (calendar_authorization_exception) {
        // A valid old token may not include the new read-only CalendarList
        // scope. Reauthorization is explicit and never widens Moodle login.
        throw new moodle_exception('managedoauthrequired', 'mod_googlemeet');
    } catch (calendar_configuration_exception) {
        throw new moodle_exception('managedcalendarunavailable', 'mod_googlemeet');
    } catch (calendar_api_exception | calendar_response_exception | calendar_transport_exception) {
        throw new moodle_exception('managedcalendarpreflightunavailable', 'mod_googlemeet');
    }

    $googlemeet->integrationmode = integration_mode::MANAGED;
    $googlemeet->owneruserid = (int) $USER->id;
    $googlemeet->oauthissuerid = $issuerid;
    $googlemeet->calendarid = $calendar['id'];
    $googlemeet->creatoremail = null;
    $guestpolicy = (string) (
        $googlemeet->guestpolicy
        ?? $existing?->guestpolicy
        ?? calendar_guest_policy::NONE
    );
    if (!calendar_guest_policy::is_valid($guestpolicy)) {
        throw new moodle_exception('invalidguestpolicy', 'mod_googlemeet');
    }
    $googlemeet->guestpolicy = $guestpolicy;
    $googlemeet->sendupdates = calendar_guest_policy::send_updates($guestpolicy);

    if ($existing !== null && $existing->integrationmode === integration_mode::MANAGED) {
        $googlemeet->url = (string) $existing->url;
        $googlemeet->meetinguri = $existing->meetinguri;
        $googlemeet->guesthash = $existing->guesthash ?? null;
        $googlemeet->guestcount = (int) ($existing->guestcount ?? 0);
        $googlemeet->guesttimelastsync = $existing->guesttimelastsync ?? null;
        $googlemeet->guesttimechecked = $existing->guesttimechecked ?? null;
        return $googlemeet;
    }

    $googlemeet->url = '';
    $googlemeet->meetinguri = null;
    $googlemeet->eventid = null;
    $googlemeet->googleeventid = null;
    $googlemeet->googleeventhtmlurl = null;
    $googlemeet->googleeventetag = null;
    $googlemeet->requestid = null;
    $googlemeet->conferenceid = null;
    $googlemeet->meetingcode = null;
    $googlemeet->guesthash = null;
    $googlemeet->guestcount = 0;
    $googlemeet->guesttimelastsync = null;
    $googlemeet->guesttimechecked = null;
    $googlemeet->conferencestatus = null;
    $googlemeet->syncattempts = 0;
    $googlemeet->lasterrorcode = null;
    $googlemeet->lasterrormessage = null;
    $googlemeet->timelastattempt = null;
    $googlemeet->syncstatus = sync_state::DRAFT;

    return $googlemeet;
}

/**
 * Removes an instance of the mod_googlemeet from the database.
 *
 * @param int $id Id of the module instance.
 * @return bool True if successful, false on failure.
 */
function googlemeet_delete_instance($id) {
    global $DB, $CFG;
    require_once($CFG->dirroot . '/mod/googlemeet/locallib.php');

    $id = (int) $id;
    if ($id <= 0) {
        return false;
    }

    return (new meeting_lock())->with_lock($id, function () use ($DB, $id): bool {
        $exists = $DB->get_record('googlemeet', ['id' => $id]);
        if (!$exists) {
            return false;
        }

        $transaction = $DB->start_delegated_transaction();
        (new remote_cleanup_manager())->capture_and_queue($exists);
        (new schedule_manager())->delete($id);

        $DB->delete_records('googlemeet_diagnostics', ['googlemeetid' => $id]);
        $DB->delete_records('googlemeet_calendar_guests', ['googlemeetid' => $id]);
        $DB->delete_records('googlemeet_recordings', ['googlemeetid' => $id]);

        $DB->delete_records('googlemeet', ['id' => $id]);
        $transaction->allow_commit();

        return true;
    });
}

/**
 * Add a get_coursemodule_info function in case any feedback type wants to add 'extra' information
 * for the course (see resource).
 *
 * Given a course_module object, this function returns any "extra" information that may be needed
 * when printing this activity in a course listing.  See get_array_of_activities() in course/lib.php.
 *
 * @param stdClass $coursemodule The coursemodule object (record).
 * @return cached_cm_info An object on information that the courses
 *                        will know about (most noticeably, an icon).
 */
function googlemeet_get_coursemodule_info($coursemodule) {
    global $CFG, $DB;

    if (!$googlemeet = $DB->get_record(
        'googlemeet',
        ['id' => $coursemodule->instance],
        'id, name, url, intro, introformat'
    )) {
        return null;
    }

    $info = new cached_cm_info();
    $info->name = $googlemeet->name;

    if ($coursemodule->showdescription) {
        // Convert intro to html. Do not filter cached version, filters run at display time.
        $info->content = format_module_intro('googlemeet', $googlemeet, $coursemodule->id, false);
    }

    return $info;
}

/**
 * Mark the activity completed (if required) and trigger the course_module_viewed event.
 *
 * @param  stdClass $googlemeet googlemeet object
 * @param  stdClass $course     course object
 * @param  stdClass $cm         course module object
 * @param  stdClass $context    context object
 * @since Moodle 3.0
 */
function googlemeet_view($googlemeet, $course, $cm, $context) {

    // Trigger course_module_viewed event.
    $params = array(
        'context' => $context,
        'objectid' => $googlemeet->id
    );

    $event = \mod_googlemeet\event\course_module_viewed::create($params);
    $event->add_record_snapshot('course_modules', $cm);
    $event->add_record_snapshot('course', $course);
    $event->add_record_snapshot('googlemeet', $googlemeet);
    $event->trigger();

    // Completion.
    $completion = new completion_info($course);
    $completion->set_module_viewed($cm);
}

/**
 * Rebuilds Moodle Calendar events from the canonical local schedule.
 *
 * @param int $courseid Optional course restriction used by Moodle's refresh task.
 * @return bool
 */
function googlemeet_refresh_events($courseid = 0): bool {
    global $DB;

    $conditions = [];
    if ((int) $courseid > 0) {
        $conditions['course'] = (int) $courseid;
    }

    $manager = new schedule_manager();
    $meetings = $DB->get_recordset('googlemeet', $conditions);
    foreach ($meetings as $meeting) {
        $manager->synchronise($meeting);
    }
    $meetings->close();

    return true;
}

/**
 * Provides the dashboard action for a meeting occurrence.
 *
 * @param calendar_event $event Calendar event.
 * @param \core_calendar\action_factory $factory Action factory.
 * @return \core_calendar\local\event\entities\action|null
 */
function mod_googlemeet_core_calendar_provide_event_action(
    calendar_event $event,
    \core_calendar\action_factory $factory
) {
    global $DB;

    if (
        $event->eventtype !== \mod_googlemeet\helper::GOOGLEMEET_EVENT_START ||
        (int) $event->instance <= 0
    ) {
        return null;
    }
    if ((int) $event->timestart + max(0, (int) $event->timeduration) < time()) {
        return null;
    }
    $meeting = $DB->get_record(
        'googlemeet',
        ['id' => (int) $event->instance],
        'id, course, syncstatus',
        IGNORE_MISSING
    );
    if ($meeting === false) {
        return null;
    }
    $cm = get_coursemodule_from_instance(
        'googlemeet',
        (int) $meeting->id,
        (int) $meeting->course,
        false,
        IGNORE_MISSING
    );
    if ($cm === false) {
        return null;
    }

    return $factory->create_instance(
        get_string('entertheroom', 'mod_googlemeet'),
        new moodle_url('/mod/googlemeet/view.php', ['id' => (int) $cm->id]),
        1,
        $meeting->syncstatus === sync_state::READY
    );
}

/**
 * Meeting actions do not need a numeric item badge.
 *
 * @param calendar_event $event Calendar event.
 * @param int $itemcount Action item count.
 * @return bool
 */
function mod_googlemeet_core_calendar_event_action_shows_item_count(
    calendar_event $event,
    $itemcount = 0
): bool {
    return false;
}

/**
 * Returns a list of recordings from Google Meet
 *
 * @param array $params Array of parameters to a query.
 * @return array<int, stdClass> List of recordings.
 */
function googlemeet_list_recordings(array $params): array {
    global $DB;

    $recordings = $DB->get_records(
        'googlemeet_recordings',
        $params,
        'createdtime DESC',
        'id,googlemeetid,recordingid,name,createdtime,duration,webviewlink,visible,timemodified'
    );

    $formattedrecordings = [];
    foreach ($recordings as $recording) {
        $recording->createdtimeformatted = userdate($recording->createdtime);
        $recording->hasplayback = recording_artifact::is_valid_playback_uri(
            (string) $recording->webviewlink,
            (string) $recording->recordingid
        );
        if (!$recording->hasplayback) {
            $recording->webviewlink = null;
        }
        $formattedrecordings[] = $recording;
    }

    return $formattedrecordings;
}

/**
 * Get icon mapping for font-awesome.
 */
function mod_googlemeet_get_fontawesome_icon_map() {
    return [
        'mod_googlemeet:logout' => 'fa-sign-out',
        'mod_googlemeet:play' => 'fa-play'
    ];
}
