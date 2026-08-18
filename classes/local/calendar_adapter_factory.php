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

use mod_googlemeet\api\calendar_adapter;
use mod_googlemeet\api\google_calendar_client;
use mod_googlemeet\api\moodle_oauth_http_client;

/**
 * Production composition for the managed Calendar adapter.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class calendar_adapter_factory implements calendar_adapter_provider {

    /** @var oauth_manager Per-user OAuth coordinator. */
    private oauth_manager $oauthmanager;

    /**
     * @param oauth_manager|null $oauthmanager Per-user OAuth coordinator.
     */
    public function __construct(?oauth_manager $oauthmanager = null) {
        $this->oauthmanager = $oauthmanager ?? new oauth_manager();
    }

    /**
     * Builds the production adapter for one activity owner.
     *
     * @param \stdClass $meeting Managed activity record.
     * @return calendar_adapter
     */
    public function create(\stdClass $meeting): calendar_adapter {
        $oauthclient = $this->oauthmanager->authenticated_client($meeting);
        $httpclient = new moodle_oauth_http_client($oauthclient);

        return new calendar_adapter(new google_calendar_client($httpclient));
    }
}
