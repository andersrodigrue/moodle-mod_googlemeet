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

namespace mod_googlemeet\output;

use mod_googlemeet\local\integration_mode;
use mod_googlemeet\local\meeting_access_result;

/**
 * Safe activity actions for meeting entry and Calendar event details.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class activity_actions implements \renderable, \templatable {

    /**
     * @param \stdClass $meeting Activity record.
     * @param meeting_access_result $access Server-side meeting access decision.
     * @param int $cmid Course module ID.
     * @param bool $showeventdetails Whether the caller may inspect Calendar details.
     */
    public function __construct(
        private readonly \stdClass $meeting,
        private readonly meeting_access_result $access,
        private readonly int $cmid,
        private readonly bool $showeventdetails
    ) {
    }

    /**
     * Exports actions without ever exposing the provider meeting URI.
     *
     * @param \renderer_base $output Moodle renderer.
     * @return array<string, mixed>
     */
    public function export_for_template(\renderer_base $output): array {
        $data = [
            'accessstate' => $this->access->state,
            'canjoin' => $this->access->can_join(),
        ];

        if ($this->access->can_join()) {
            $data['joinurl'] = (new \moodle_url(
                '/mod/googlemeet/join.php',
                ['id' => $this->cmid]
            ))->out(false);
        } else {
            $data['unavailable'] = true;
            $data['availabilitymessage'] = $this->access->message();
            $data['availabilityclass'] = $this->access->is_error()
                ? 'alert-danger'
                : 'alert-info';
            $data['availabilityrole'] = $this->access->is_error()
                ? 'alert'
                : 'status';
        }

        if ($this->showeventdetails) {
            $eventdetailsurl = $this->event_details_url();
            if ($eventdetailsurl !== null) {
                $data['haseventdetails'] = true;
                $data['eventdetailsurl'] = $eventdetailsurl;
            }
        }

        return $data;
    }

    /**
     * Returns a fail-closed Calendar details URL.
     *
     * @return string|null
     */
    private function event_details_url(): ?string {
        if (($this->meeting->integrationmode ?? null) === integration_mode::MANAGED) {
            $candidate = trim((string) ($this->meeting->googleeventhtmlurl ?? ''));
            $parts = $candidate === '' || strlen($candidate) > 2048
                ? false
                : parse_url($candidate);
            if (
                !is_array($parts) ||
                ($parts['scheme'] ?? null) !== 'https' ||
                !in_array(($parts['host'] ?? null), ['calendar.google.com', 'www.google.com'], true) ||
                isset($parts['user']) ||
                isset($parts['pass']) ||
                isset($parts['port']) ||
                isset($parts['fragment'])
            ) {
                return null;
            }

            return $candidate;
        }

        $legacyeventid = trim((string) ($this->meeting->eventid ?? ''));
        if ($legacyeventid === '') {
            return null;
        }

        return 'https://calendar.google.com/calendar/u/0/r/eventedit/'
            . rawurlencode($legacyeventid);
    }
}
