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
 * Sets one recording reference's participant visibility explicitly.
 *
 * @package     mod_googlemeet
 * @category    external
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class set_recording_visibility extends recording_action {

    /**
     * Describes the endpoint parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'recordingid' => new external_value(PARAM_INT, 'Local recording identifier'),
            'visible' => new external_value(PARAM_BOOL, 'Requested participant visibility'),
            'coursemoduleid' => new external_value(PARAM_INT, 'Google Meet course module identifier'),
        ]);
    }

    /**
     * Persists an explicit visibility value idempotently.
     *
     * @param int $recordingid Local recording identifier.
     * @param bool $visible Requested participant visibility.
     * @param int $coursemoduleid Google Meet course module identifier.
     * @return array{recordingid: int, visible: bool, timemodified: int}
     */
    public static function execute(
        int $recordingid,
        bool $visible,
        int $coursemoduleid
    ): array {
        global $DB;

        [
            'recordingid' => $recordingid,
            'visible' => $visible,
            'coursemoduleid' => $coursemoduleid,
        ] = self::validate_parameters(self::execute_parameters(), [
            'recordingid' => $recordingid,
            'visible' => $visible,
            'coursemoduleid' => $coursemoduleid,
        ]);
        $visible = (bool) $visible;

        $recording = self::recording_for_context(
            $recordingid,
            $coursemoduleid,
            'mod/googlemeet:editrecording'
        );
        if ((bool) $recording->visible !== $visible) {
            $recording->visible = (int) $visible;
            $recording->timemodified = self::now();
            $DB->update_record('googlemeet_recordings', $recording);
        }

        return [
            'recordingid' => (int) $recording->id,
            'visible' => (bool) $recording->visible,
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
            'visible' => new external_value(PARAM_BOOL, 'Persisted participant visibility'),
            'timemodified' => new external_value(PARAM_INT, 'Last local mutation timestamp'),
        ]);
    }
}
