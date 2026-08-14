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

use context_module;
use core_external\external_api;

/**
 * Shared context boundary for recording mutation endpoints.
 *
 * @package     mod_googlemeet
 * @category    external
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class recording_action extends external_api {

    /**
     * Resolves an activity exclusively from its course module and checks access.
     *
     * @param int $coursemoduleid Course module ID.
     * @param string $capability Required module capability.
     * @return \stdClass Activity record.
     */
    final protected static function meeting_for_context(
        int $coursemoduleid,
        string $capability
    ): \stdClass {
        global $DB;

        $cm = get_coursemodule_from_id(
            'googlemeet',
            $coursemoduleid,
            0,
            false,
            MUST_EXIST
        );
        $context = context_module::instance((int) $cm->id);
        self::validate_context($context);
        require_capability($capability, $context);

        return $DB->get_record(
            'googlemeet',
            ['id' => (int) $cm->instance],
            '*',
            MUST_EXIST
        );
    }

    /**
     * Resolves a recording only after its activity context has been authorised.
     *
     * A missing and a cross-activity identifier deliberately produce the same
     * public error so the endpoint cannot be used as a recording oracle.
     *
     * @param int $recordingid Local recording ID.
     * @param int $coursemoduleid Course module ID.
     * @param string $capability Required module capability.
     * @return \stdClass Recording record.
     */
    final protected static function recording_for_context(
        int $recordingid,
        int $coursemoduleid,
        string $capability
    ): \stdClass {
        global $DB;

        $meeting = self::meeting_for_context($coursemoduleid, $capability);
        $recording = $DB->get_record(
            'googlemeet_recordings',
            [
                'id' => $recordingid,
                'googlemeetid' => (int) $meeting->id,
            ],
            '*',
            IGNORE_MISSING
        );
        if ($recording === false) {
            throw new \invalid_parameter_exception(
                get_string('invalidactivitycontext', 'mod_googlemeet')
            );
        }

        return $recording;
    }

    /**
     * Returns the canonical server timestamp for local mutations.
     *
     * @return int
     */
    final protected static function now(): int {
        return \core\di::get(\core\clock::class)->time();
    }
}
