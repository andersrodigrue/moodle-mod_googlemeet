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

use mod_googlemeet\api\calendar_authorization_exception;
use mod_googlemeet\local\integration_mode;
use mod_googlemeet\local\meeting_form_data;
use mod_googlemeet\local\meeting_manager;
use mod_googlemeet\local\oauth_manager;
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

    if (isset($googlemeet->days)) {
        $googlemeet->days = json_encode($googlemeet->days);
    }

    $googlemeet->meetinguri = $googlemeet->url;
    $googlemeet->timecreated = time();
    $googlemeet->timemodified = $googlemeet->timecreated;

    if (!$googlemeet->id = $DB->insert_record('googlemeet', $googlemeet)) {
        return false;
    }

    if (isset($googlemeet->days)) {
        $googlemeet->days = json_decode($googlemeet->days, true);
    }

    $events = googlemeet_construct_events_data_for_add($googlemeet);

    googlemeet_set_events($googlemeet, $events);
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

    if (!isset($googlemeet->addmultiply)) {
        $googlemeet->addmultiply = 0;
        $googlemeet->days = null;
        $googlemeet->eventenddate = $googlemeet->eventdate;
        $googlemeet->period = null;
    }

    $googlemeet = (new meeting_form_data())->normalize(
        $googlemeet,
        get_user_timezone($USER->timezone)
    );
    $googlemeet = googlemeet_prepare_integration($googlemeet, $existing);

    if (isset($googlemeet->days)) {
        $googlemeet->days = json_encode($googlemeet->days);
    }
    $googlemeet->timemodified = time();

    $googlemeetupdated = $DB->update_record('googlemeet', $googlemeet);

    if (isset($googlemeet->days)) {
        $googlemeet->days = json_decode($googlemeet->days, true);
    }
    $events = googlemeet_construct_events_data_for_add($googlemeet);

    googlemeet_set_events($googlemeet, $events);
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
 * @return stdClass Prepared record.
 */
function googlemeet_prepare_integration(
    stdClass $googlemeet,
    ?stdClass $existing = null,
    ?oauth_manager $oauthmanager = null
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

    $googlemeet->integrationmode = integration_mode::MANAGED;
    $googlemeet->owneruserid = (int) $USER->id;
    $googlemeet->oauthissuerid = $issuerid;
    $googlemeet->calendarid = 'primary';
    $googlemeet->creatoremail = null;

    if ($existing !== null && $existing->integrationmode === integration_mode::MANAGED) {
        $googlemeet->url = (string) $existing->url;
        $googlemeet->meetinguri = $existing->meetinguri;
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

    $exists = $DB->get_record('googlemeet', array('id' => $id));
    if (!$exists) {
        return false;
    }

    googlemeet_delete_events($id);

    $DB->delete_records('googlemeet_recordings', ['googlemeetid' => $id]);

    $DB->delete_records('googlemeet', array('id' => $id));

    return true;
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
 * Returns a list of recordings from Google Meet
 *
 * @param array $params Array of parameters to a query.
 * @return stdClass $formattedrecordings    List of recordings
 */
function googlemeet_list_recordings($params) {
    global $DB;

    $recordings = $DB->get_records(
        'googlemeet_recordings',
        $params,
        'createdtime DESC',
        'id,googlemeetid,name,createdtime,duration,webviewlink,visible'
    );

    $formattedrecordings = [];
    foreach ($recordings as $recording) {
        $recording->createdtimeformatted = userdate($recording->createdtime);

        array_push($formattedrecordings, $recording);
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

/**
 * Synchronizes Google Drive recordings with the database.
 *
 * @param int $googlemeetid the googlemeet ID
 * @param array $files the array of recordings
 * @return array of recordings
 */
function sync_recordings($googlemeetid, $files) {
    global $DB;

    $cm = get_coursemodule_from_instance('googlemeet', $googlemeetid, 0, false, MUST_EXIST);
    $context = context_module::instance($cm->id);
    require_capability('mod/googlemeet:syncgoogledrive', $context);

    $googlemeetrecordings = $DB->get_records('googlemeet_recordings', ['googlemeetid' => $googlemeetid]);

    $recordingids = array_column($googlemeetrecordings, 'recordingid');
    $fileids = array_column($files, 'recordingId');

    $updaterecordings = [];
    $insertrecordings = [];
    $deleterecordings = [];

    foreach ($files as $file) {
        if (!isset($file->unprocessed)) {
            if (in_array($file->recordingId, $recordingids, true)) {
                array_push($updaterecordings, $file);
            } else {
                array_push($insertrecordings, $file);
            }
        }
    }

    foreach ($googlemeetrecordings as $googlemeetrecording) {
        if (!in_array($googlemeetrecording->recordingid, $fileids)) {
            $deleterecordings['id'] = $googlemeetrecording->id;
        }
    }

    if ($deleterecordings) {
        $DB->delete_records('googlemeet_recordings', $deleterecordings);
    }

    if ($updaterecordings) {
        foreach ($updaterecordings as $updaterecording) {
            $recording = $DB->get_record('googlemeet_recordings', [
                'googlemeetid' => $googlemeetid,
                'recordingid' => $updaterecording->recordingId
            ]);

            $recording->createdtime = $updaterecording->createdTime;
            $recording->duration = $updaterecording->duration;
            $recording->webviewlink = $updaterecording->webViewLink;
            $recording->timemodified = time();

            $DB->update_record('googlemeet_recordings', $recording);
        }

        $googlemeetrecord = $DB->get_record('googlemeet', ['id' => $googlemeetid]);
        $googlemeetrecord->lastsync = time();
        $DB->update_record('googlemeet', $googlemeetrecord);
    }

    if ($insertrecordings) {
        $recordings = [];

        foreach ($insertrecordings as $insertrecording) {
            $recording = new stdClass();
            $recording->googlemeetid = $googlemeetid;
            $recording->recordingid = $insertrecording->recordingId;
            $recording->name = $insertrecording->name;
            $recording->createdtime = $insertrecording->createdTime;
            $recording->duration = $insertrecording->duration;
            $recording->webviewlink = $insertrecording->webViewLink;
            $recording->timemodified = time();

            array_push($recordings, $recording);
        }

        $DB->insert_records('googlemeet_recordings', $recordings);

        $googlemeetrecord = $DB->get_record('googlemeet', ['id' => $googlemeetid]);
        $googlemeetrecord->lastsync = time();

        $DB->update_record('googlemeet', $googlemeetrecord);
    }

    return googlemeet_list_recordings(['googlemeetid' => $googlemeetid]);
}
