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

namespace mod_googlemeet\external;

use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Renames one recording reference in its authorised activity context.
 *
 * @package     mod_googlemeet
 * @category    external
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class rename_recording extends recording_action {

    /**
     * Describes the endpoint parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'recordingid' => new external_value(PARAM_INT, 'Local recording identifier'),
            'name' => new external_value(PARAM_TEXT, 'New recording display name'),
            'coursemoduleid' => new external_value(PARAM_INT, 'Google Meet course module identifier'),
        ]);
    }

    /**
     * Renames a recording idempotently.
     *
     * @param int $recordingid Local recording identifier.
     * @param string $name New display name.
     * @param int $coursemoduleid Google Meet course module identifier.
     * @return array{recordingid: int, name: string, timemodified: int}
     */
    public static function execute(
        int $recordingid,
        string $name,
        int $coursemoduleid
    ): array {
        global $DB;

        [
            'recordingid' => $recordingid,
            'name' => $name,
            'coursemoduleid' => $coursemoduleid,
        ] = self::validate_parameters(self::execute_parameters(), [
            'recordingid' => $recordingid,
            'name' => $name,
            'coursemoduleid' => $coursemoduleid,
        ]);

        $name = trim($name);
        if ($name === '' || \core_text::strlen($name) > 255) {
            throw new \invalid_parameter_exception(
                get_string('invalidrecordingname', 'mod_googlemeet')
            );
        }

        $recording = self::recording_for_context(
            $recordingid,
            $coursemoduleid,
            'mod/googlemeet:editrecording'
        );
        if ((string) $recording->name !== $name) {
            $recording->name = $name;
            $recording->timemodified = self::now();
            $DB->update_record('googlemeet_recordings', $recording);
        }

        return [
            'recordingid' => (int) $recording->id,
            'name' => (string) $recording->name,
            'timemodified' => (int) $recording->timemodified,
        ];
    }

    /**
     * Describes the endpoint result.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'recordingid' => new external_value(PARAM_INT, 'Local recording identifier'),
            'name' => new external_value(PARAM_TEXT, 'Persisted recording display name'),
            'timemodified' => new external_value(PARAM_INT, 'Last local mutation timestamp'),
        ]);
    }
}
