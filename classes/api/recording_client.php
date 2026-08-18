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

namespace mod_googlemeet\api;

/**
 * Google Meet recording API boundary.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface recording_client {

    /**
     * Lists conference records for one exact Meet code.
     *
     * @param string $meetingcode Normalized Meet code.
     * @param string|null $pagetoken Continuation token.
     * @return array<string, mixed>
     */
    public function list_conference_records(string $meetingcode, ?string $pagetoken = null): array;

    /**
     * Lists recording artifacts for one conference record.
     *
     * @param string $conferencerecord Resource name.
     * @param string|null $pagetoken Continuation token.
     * @return array<string, mixed>
     */
    public function list_recordings(string $conferencerecord, ?string $pagetoken = null): array;
}
