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
 * Transport boundary for the Google Calendar Events API.
 *
 * A future OAuth-aware implementation will translate Google responses to arrays
 * and remote transport failures to exceptions. Keeping this contract free of a
 * particular Google SDK makes the synchronization adapter deterministic in tests.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface calendar_client {

    /**
     * Inserts one Calendar event.
     *
     * @param string $calendarid Google Calendar identifier.
     * @param array<string, mixed> $event Event resource.
     * @param array<string, mixed> $parameters Request parameters.
     * @return array<string, mixed> Calendar event resource.
     * @throws calendar_event_exists_exception When the controlled event ID already exists.
     */
    public function insert_event(string $calendarid, array $event, array $parameters): array;

    /**
     * Patches one Calendar event.
     *
     * @param string $calendarid Google Calendar identifier.
     * @param string $eventid Google Calendar event identifier.
     * @param array<string, mixed> $event Event patch.
     * @param array<string, mixed> $parameters Request parameters.
     * @return array<string, mixed> Calendar event resource.
     */
    public function patch_event(
        string $calendarid,
        string $eventid,
        array $event,
        array $parameters
    ): array;

    /**
     * Gets one Calendar event.
     *
     * @param string $calendarid Google Calendar identifier.
     * @param string $eventid Google Calendar event identifier.
     * @return array<string, mixed> Calendar event resource.
     */
    public function get_event(string $calendarid, string $eventid): array;
}
