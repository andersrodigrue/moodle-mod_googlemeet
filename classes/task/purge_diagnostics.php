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

namespace mod_googlemeet\task;

use mod_googlemeet\local\diagnostic_repository;

/**
 * Purges one bounded batch of expired operational diagnostics.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class purge_diagnostics extends \core\task\scheduled_task {

    /**
     * Returns the administrator-facing task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('diagnosticspurgetask', 'mod_googlemeet');
    }

    /**
     * Applies configured retention without touching external services.
     */
    public function execute(): void {
        $days = diagnostic_repository::retention_days();
        $before = \core\di::get(\core\clock::class)->time() - ($days * DAYSECS);
        $deleted = (new diagnostic_repository())->purge_before($before);

        mtrace(get_string('diagnosticspurgeresult', 'mod_googlemeet', (object) [
            'days' => $days,
            'deleted' => $deleted,
        ]));
    }
}
