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
 * Google Meet external API
 *
 * @package     mod_googlemeet
 * @category    external
 * @copyright   2020 Rone Santos <ronefel@hotmail.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once("$CFG->libdir/externallib.php");
require_once("$CFG->dirroot/mod/googlemeet/lib.php");

/**
 * Google Meet module external functions.
 *
 * @package     mod_googlemeet
 * @category    external
 * @copyright   2020 Rone Santos <ronefel@hotmail.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_googlemeet_external extends external_api {

    /**
     * Validates a module context and returns the Google Meet instance linked to it.
     *
     * @param int $coursemoduleid The course module ID.
     * @param string $capability The capability required in the module context.
     * @param int|null $googlemeetid Optional activity instance ID supplied by the caller.
     * @return stdClass The activity instance linked to the course module.
     */
    private static function get_googlemeet_for_context($coursemoduleid, $capability, $googlemeetid = null) {
        global $DB;

        $cm = get_coursemodule_from_id('googlemeet', $coursemoduleid, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);

        self::validate_context($context);
        require_capability($capability, $context);

        if ($googlemeetid !== null && (int) $googlemeetid !== (int) $cm->instance) {
            throw new invalid_parameter_exception(get_string('invalidactivitycontext', 'mod_googlemeet'));
        }

        return $DB->get_record('googlemeet', ['id' => $cm->instance], '*', MUST_EXIST);
    }

    /**
     * Describes the parameters for recording_edit_name.
     *
     * @return external_function_parameters
     */
    public static function recording_edit_name_parameters() {
        return new external_function_parameters(
            [
                'recordingid' => new external_value(PARAM_INT, ''),
                'name' => new external_value(PARAM_TEXT, ''),
                'coursemoduleid' => new external_value(PARAM_INT, ''),
            ]
        );
    }

    /**
     * Edit the name of the recording
     *
     * @param int $recordingid the recording ID
     * @param string $name the new name of recording
     * @param int $coursemoduleid the course module ID
     * @return object containing the new name of the recording
     */
    public static function recording_edit_name($recordingid, $name, $coursemoduleid) {
        global $DB;

        // Parameter validation.
        // REQUIRED.
        $params = self::validate_parameters(
            self::recording_edit_name_parameters(),
            [
                'recordingid' => $recordingid,
                'name' => $name,
                'coursemoduleid' => $coursemoduleid
            ]
        );

        $googlemeet = self::get_googlemeet_for_context(
            $params['coursemoduleid'],
            'mod/googlemeet:editrecording'
        );
        $recording = $DB->get_record(
            'googlemeet_recordings',
            [
                'id' => $params['recordingid'],
                'googlemeetid' => $googlemeet->id,
            ],
            '*',
            MUST_EXIST
        );

        $recording->name = $params['name'];
        $recording->timemodified = time();

        $DB->update_record('googlemeet_recordings', $recording);

        return (object)[
            'name' => $recording->name
        ];
    }

    /**
     * Describes the recording_edit_name return value.
     *
     * @return external_single_structure
     */
    public static function recording_edit_name_returns() {
        return new external_single_structure(
            [
                'name' => new external_value(PARAM_RAW, 'New recording name'),
            ]
        );
    }

    /**
     * Describes the parameters for showhide_recording.
     *
     * @return external_function_parameters
     */
    public static function showhide_recording_parameters() {
        return new external_function_parameters(
            [
                'recordingid' => new external_value(PARAM_INT, ''),
                'coursemoduleid' => new external_value(PARAM_INT, ''),
            ]
        );
    }

    /**
     * Toggle recording visibility.
     *
     * @param int $recordingid the recording ID
     * @param int $coursemoduleid the course module ID
     * @return object containing the visibility of the recording
     */
    public static function showhide_recording($recordingid, $coursemoduleid) {
        global $DB;

        // Parameter validation.
        // REQUIRED.
        $params = self::validate_parameters(
            self::showhide_recording_parameters(),
            [
                'recordingid' => $recordingid,
                'coursemoduleid' => $coursemoduleid
            ]
        );

        $googlemeet = self::get_googlemeet_for_context(
            $params['coursemoduleid'],
            'mod/googlemeet:editrecording'
        );
        $recording = $DB->get_record(
            'googlemeet_recordings',
            [
                'id' => $params['recordingid'],
                'googlemeetid' => $googlemeet->id,
            ],
            '*',
            MUST_EXIST
        );

        if ($recording->visible) {
            $recording->visible = false;
        } else {
            $recording->visible = true;
        }

        $recording->timemodified = time();

        $DB->update_record('googlemeet_recordings', $recording);

        return (object)[
            'visible' => $recording->visible
        ];
    }

    /**
     * Describes the showhide_recording return value.
     *
     * @return external_single_structure
     */
    public static function showhide_recording_returns() {
        return new external_single_structure(
            [
                'visible' => new external_value(PARAM_RAW, 'Visible or hidden recording'),
            ]
        );
    }

    /**
     * Describes the parameters for delete_all_recordings.
     *
     * @return external_function_parameters
     */
    public static function delete_all_recordings_parameters() {
        return new external_function_parameters(
            [
                'googlemeetid' => new external_value(PARAM_INT, ''),
                'coursemoduleid' => new external_value(PARAM_INT, ''),
            ]
        );
    }

    /**
     * Removes all recordings from Google Meet.
     *
     * @param int $googlemeetid the googlemeet ID
     * @param int $coursemoduleid the course module ID
     * @return array empty
     */
    public static function delete_all_recordings($googlemeetid, $coursemoduleid) {
        global $DB;

        // Parameter validation.
        // REQUIRED.
        $params = self::validate_parameters(
            self::delete_all_recordings_parameters(),
            [
                'googlemeetid' => $googlemeetid,
                'coursemoduleid' => $coursemoduleid
            ]
        );

        $googlemeetrecord = self::get_googlemeet_for_context(
            $params['coursemoduleid'],
            'mod/googlemeet:removerecording',
            $params['googlemeetid']
        );
        $googlemeetid = (int) $googlemeetrecord->id;

        $DB->delete_records('googlemeet_recordings', ['googlemeetid' => $googlemeetid]);

        $googlemeetrecord->lastsync = time();
        $DB->update_record('googlemeet', $googlemeetrecord);

        return [];
    }

    /**
     * Describes the delete_all_recordings return value.
     *
     * @return external_single_structure
     */
    public static function delete_all_recordings_returns() {
        return new external_single_structure([]);
    }
}
