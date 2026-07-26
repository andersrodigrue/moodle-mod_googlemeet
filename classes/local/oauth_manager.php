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

use mod_googlemeet\api\calendar_authorization_exception;

/**
 * Creates per-teacher OAuth clients using Moodle's native token storage.
 *
 * The activity stores only the Moodle owner and issuer identifiers. Access and
 * refresh tokens stay in Moodle core's OAuth tables and session handling.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class oauth_manager {

    /** Least-privilege scope required to manage Calendar events. */
    public const CALENDAR_SCOPE = 'https://www.googleapis.com/auth/calendar.events';

    /** Google authorization endpoint host. */
    private const GOOGLE_AUTH_HOST = 'accounts.google.com';

    /** @var \Closure Loads an OAuth issuer by ID. */
    private \Closure $issuerloader;

    /** @var \Closure Creates a Moodle user OAuth client. */
    private \Closure $clientfactory;

    /**
     * @param callable|null $issuerloader Optional issuer loader for tests.
     * @param callable|null $clientfactory Optional OAuth client factory for tests.
     */
    public function __construct(?callable $issuerloader = null, ?callable $clientfactory = null) {
        $this->issuerloader = \Closure::fromCallable($issuerloader ?? static function (int $issuerid) {
            return \core\oauth2\api::get_issuer($issuerid);
        });
        $this->clientfactory = \Closure::fromCallable(
            $clientfactory ?? static function (
                \core\oauth2\issuer $issuer,
                \moodle_url $returnurl,
                string $scopes,
                bool $autorefresh
            ) {
                return \core\oauth2\api::get_user_oauth_client(
                    $issuer,
                    $returnurl,
                    $scopes,
                    $autorefresh
                );
            }
        );
    }

    /**
     * Returns an authenticated client for the activity owner.
     *
     * Moodle's is_logged_in() exchanges a stored refresh token when necessary
     * and removes a rejected refresh token before returning false.
     *
     * @param \stdClass $meeting Managed activity record.
     * @return \core\oauth2\client
     */
    public function authenticated_client(\stdClass $meeting): \core\oauth2\client {
        $client = $this->build_client($meeting);

        try {
            if (!$client->is_logged_in()) {
                throw new calendar_authorization_exception('Google Calendar authorization is required.');
            }
        } catch (\moodle_exception $e) {
            throw new calendar_authorization_exception('Google Calendar authorization could not be refreshed.', 0, $e);
        }

        return $client;
    }

    /**
     * Removes the plugin-scoped local access and refresh token through Moodle.
     *
     * This deliberately does not call Google's global revocation endpoint:
     * Google revokes every scope granted to the OAuth project, which could also
     * disconnect the same issuer from Moodle login or other integrations.
     *
     * @param \stdClass $meeting Managed activity record.
     */
    public function revoke(\stdClass $meeting): void {
        $this->build_client($meeting)->log_out();
    }

    /**
     * Builds a Moodle OAuth client after validating owner and issuer.
     *
     * @param \stdClass $meeting Managed activity record.
     * @return \core\oauth2\client
     */
    private function build_client(\stdClass $meeting): \core\oauth2\client {
        global $USER;

        $owneruserid = (int) ($meeting->owneruserid ?? 0);
        if ($owneruserid <= 0 || (int) ($USER->id ?? 0) !== $owneruserid) {
            throw new calendar_authorization_exception('The synchronization task is not running as the meeting owner.');
        }

        $issuerid = (int) ($meeting->oauthissuerid ?? 0);
        if ($issuerid <= 0) {
            throw new calendar_authorization_exception('A Google OAuth issuer is required.');
        }

        try {
            $issuer = ($this->issuerloader)($issuerid);
        } catch (\dml_missing_record_exception $e) {
            throw new calendar_authorization_exception('The Google OAuth issuer no longer exists.', 0, $e);
        }

        if (
            !is_object($issuer) ||
            !$issuer->get('enabled') ||
            !$issuer->is_configured()
        ) {
            throw new calendar_authorization_exception('The Google OAuth issuer is unavailable.');
        }

        $authorizationurl = $issuer->get_endpoint_url('authorization');
        $parts = is_string($authorizationurl) ? parse_url($authorizationurl) : false;
        if (
            !is_array($parts) ||
            ($parts['scheme'] ?? null) !== 'https' ||
            ($parts['host'] ?? null) !== self::GOOGLE_AUTH_HOST
        ) {
            throw new calendar_authorization_exception('The selected OAuth issuer is not Google.');
        }

        $returnurl = new \moodle_url('/mod/googlemeet/callback.php', [
            'managed' => 1,
            'issuerid' => $issuerid,
        ]);
        $client = ($this->clientfactory)(
            $issuer,
            $returnurl,
            self::CALENDAR_SCOPE,
            true
        );
        if (!$client instanceof \core\oauth2\client) {
            throw new \coding_exception('The Google Meet OAuth factory returned an invalid client.');
        }

        return $client;
    }
}
