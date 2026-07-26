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
 * Generates stable identifiers for one managed Calendar event.
 *
 * The event ID uses only characters accepted by Calendar's base32hex rule.
 * The conference request ID remains stable across transport retries so Google
 * can ignore a duplicate request for the same logical conference.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class calendar_identity {

    /** @var string Stable site namespace. */
    private string $namespace;

    /**
     * @param string|null $namespace Stable site namespace, or null to use the Moodle site URL.
     */
    public function __construct(?string $namespace = null) {
        global $CFG;

        $namespace = trim($namespace ?? (string) ($CFG->wwwroot ?? ''));
        if ($namespace === '') {
            throw new \coding_exception('A stable namespace is required for Google Calendar identifiers.');
        }

        $this->namespace = $namespace;
    }

    /**
     * Returns a deterministic Calendar event ID.
     *
     * @param int $googlemeetid Activity instance ID.
     * @return string
     */
    public function event_id(int $googlemeetid): string {
        $this->assert_valid_id($googlemeetid);

        return 'gm' . hash('sha256', $this->namespace . "\0event\0" . $googlemeetid);
    }

    /**
     * Returns a deterministic conference create request ID.
     *
     * Generation zero identifies the initial logical request. A later generation
     * is used only after Google explicitly reports failure; transport retries keep
     * the same generation and therefore the same request ID.
     *
     * @param int $googlemeetid Activity instance ID.
     * @param string $eventid Controlled Calendar event ID.
     * @param int $generation Logical conference request generation.
     * @return string
     */
    public function request_id(int $googlemeetid, string $eventid, int $generation = 0): string {
        $this->assert_valid_id($googlemeetid);
        if ($eventid === '') {
            throw new \coding_exception('A Google Calendar event ID is required.');
        }
        if ($generation < 0) {
            throw new \coding_exception('The Google conference request generation cannot be negative.');
        }

        return hash(
            'sha256',
            $this->namespace . "\0conference\0" . $googlemeetid . "\0" . $eventid . "\0" . $generation
        );
    }

    /**
     * Rejects invalid activity identifiers.
     *
     * @param int $googlemeetid Activity instance ID.
     */
    private function assert_valid_id(int $googlemeetid): void {
        if ($googlemeetid <= 0) {
            throw new \coding_exception('A positive Google Meet activity ID is required.');
        }
    }
}
