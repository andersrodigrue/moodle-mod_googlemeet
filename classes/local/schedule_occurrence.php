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

namespace mod_googlemeet\local;

/**
 * Immutable occurrence derived from a meeting's canonical schedule.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final readonly class schedule_occurrence {

    /**
     * @param int $timestart Absolute occurrence start timestamp.
     * @param int $timeend Absolute occurrence end timestamp.
     */
    public function __construct(
        public int $timestart,
        public int $timeend
    ) {
        if ($timestart <= 0) {
            throw new \invalid_parameter_exception('A positive occurrence start time is required.');
        }
        if ($timeend <= $timestart) {
            throw new \invalid_parameter_exception('The occurrence end time must be after its start time.');
        }
    }

    /**
     * Returns the stable key used to reconcile this occurrence.
     *
     * The activity ID is deliberately excluded because the database index is
     * activity-scoped and a restored activity receives a new ID.
     *
     * @return string
     */
    public function key(): string {
        return hash('sha256', 'v1:' . $this->timestart);
    }

    /**
     * Returns the elapsed occurrence duration.
     *
     * @return int
     */
    public function duration(): int {
        return $this->timeend - $this->timestart;
    }
}
