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
 * Deletes all local recording references from one authorised activity.
 *
 * This endpoint never deletes the provider's files.
 *
 * @package     mod_googlemeet
 * @category    external
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class delete_recordings extends recording_action {

    /**
     * Describes the endpoint parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'coursemoduleid' => new external_value(PARAM_INT, 'Google Meet course module identifier'),
        ]);
    }

    /**
     * Deletes local references in a single delegated transaction.
     *
     * @param int $coursemoduleid Google Meet course module identifier.
     * @return array{googlemeetid: int, deletedcount: int, timemodified: int}
     */
    public static function execute(int $coursemoduleid): array {
        global $DB;

        ['coursemoduleid' => $coursemoduleid] = self::validate_parameters(
            self::execute_parameters(),
            ['coursemoduleid' => $coursemoduleid]
        );
        $meeting = self::meeting_for_context(
            $coursemoduleid,
            'mod/googlemeet:removerecording'
        );
        $deletedcount = $DB->count_records(
            'googlemeet_recordings',
            ['googlemeetid' => (int) $meeting->id]
        );
        $timemodified = (int) ($meeting->lastsync ?? 0);
        if ($deletedcount > 0) {
            $transaction = $DB->start_delegated_transaction();
            $DB->delete_records(
                'googlemeet_recordings',
                ['googlemeetid' => (int) $meeting->id]
            );
            $timemodified = self::now();
            $DB->set_field(
                'googlemeet',
                'lastsync',
                $timemodified,
                ['id' => (int) $meeting->id]
            );
            $transaction->allow_commit();
        }

        return [
            'googlemeetid' => (int) $meeting->id,
            'deletedcount' => $deletedcount,
            'timemodified' => $timemodified,
        ];
    }

    /**
     * Describes the endpoint result.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'googlemeetid' => new external_value(PARAM_INT, 'Google Meet activity identifier'),
            'deletedcount' => new external_value(PARAM_INT, 'Number of deleted local references'),
            'timemodified' => new external_value(PARAM_INT, 'Last local mutation timestamp'),
        ]);
    }
}
