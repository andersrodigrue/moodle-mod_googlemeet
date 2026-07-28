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

use mod_googlemeet\api\google_meet_recording_client;
use mod_googlemeet\api\moodle_recording_oauth_http_client;

/**
 * Production composition for Google Meet recording discovery.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class recording_discovery_factory implements recording_discovery_provider {

    /** @var recording_oauth_manager Recording OAuth coordinator. */
    private recording_oauth_manager $oauthmanager;

    /**
     * @param recording_oauth_manager|null $oauthmanager Recording OAuth coordinator.
     */
    public function __construct(?recording_oauth_manager $oauthmanager = null) {
        $this->oauthmanager = $oauthmanager ?? new recording_oauth_manager();
    }

    /**
     * Creates a production discovery service for the recording owner.
     *
     * @param \stdClass $meeting Activity record.
     * @return recording_discovery
     */
    public function create(\stdClass $meeting): recording_discovery {
        $oauthclient = $this->oauthmanager->authenticated_client($meeting);
        $httpclient = new moodle_recording_oauth_http_client($oauthclient);

        return new recording_discovery(new google_meet_recording_client($httpclient));
    }
}
