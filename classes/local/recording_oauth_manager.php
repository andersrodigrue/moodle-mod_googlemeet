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

use mod_googlemeet\api\recording_authorization_exception;

/**
 * Creates per-teacher OAuth clients dedicated to Meet recording metadata.
 *
 * A separate Moodle issuer is required so recording grants never replace or
 * ambiguously reuse refresh tokens belonging to login or Calendar integration.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class recording_oauth_manager {

    /** Read-only access to conference metadata and generated artifacts. */
    public const RECORDING_SCOPE = 'https://www.googleapis.com/auth/meetings.space.readonly';

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
                return \core\oauth2\api::get_user_oauth_client($issuer, $returnurl, $scopes, $autorefresh);
            }
        );
    }

    /**
     * Returns the configured dedicated recording issuer, or zero.
     *
     * The Calendar and recording issuers must differ because Moodle stores
     * user refresh tokens under an issuer-scoped namespace.
     *
     * @return int
     */
    public static function configured_issuer_id(): int {
        $issuerid = (int) get_config('googlemeet', 'recordingissuerid');
        $calendarissuerid = (int) get_config('googlemeet', 'issuerid');

        return $issuerid > 0 && $issuerid !== $calendarissuerid ? $issuerid : 0;
    }

    /**
     * Returns an authenticated client for the persisted recording owner.
     *
     * @param \stdClass $meeting Activity record.
     * @return \core\oauth2\client
     */
    public function authenticated_client(\stdClass $meeting): \core\oauth2\client {
        $owneruserid = (int) ($meeting->recordingowneruserid ?? 0);
        $issuerid = (int) ($meeting->recordingoauthissuerid ?? 0);
        $client = $this->build_client($issuerid, $owneruserid, (int) ($meeting->id ?? 0));

        try {
            if (!$client->is_logged_in()) {
                throw new recording_authorization_exception('Google Meet recording authorization is required.');
            }
        } catch (\moodle_exception $e) {
            throw new recording_authorization_exception(
                'Google Meet recording authorization could not be refreshed.',
                0,
                $e
            );
        }

        return $client;
    }

    /**
     * Returns a client used by the explicit teacher authorization flow.
     *
     * @param int $issuerid Dedicated recording issuer.
     * @param int $owneruserid Current Moodle user.
     * @param int $googlemeetid Activity instance ID.
     * @return \core\oauth2\client
     */
    public function authorization_client(
        int $issuerid,
        int $owneruserid,
        int $googlemeetid
    ): \core\oauth2\client {
        return $this->build_client($issuerid, $owneruserid, $googlemeetid);
    }

    /**
     * Builds a validated, owner-scoped Moodle OAuth client.
     *
     * @param int $issuerid Dedicated recording issuer.
     * @param int $owneruserid Moodle user owning the authorization.
     * @param int $googlemeetid Activity instance ID.
     * @return \core\oauth2\client
     */
    private function build_client(int $issuerid, int $owneruserid, int $googlemeetid): \core\oauth2\client {
        global $USER;

        if (
            $owneruserid <= 0 ||
            (int) ($USER->id ?? 0) !== $owneruserid ||
            $googlemeetid <= 0
        ) {
            throw new recording_authorization_exception(
                'Recording synchronization is not running as its authorized owner.'
            );
        }
        if ($issuerid <= 0 || $issuerid === (int) get_config('googlemeet', 'issuerid')) {
            throw new recording_authorization_exception('A dedicated Google Meet recording issuer is required.');
        }

        try {
            $issuer = ($this->issuerloader)($issuerid);
        } catch (\dml_missing_record_exception $e) {
            throw new recording_authorization_exception('The recording OAuth issuer no longer exists.', 0, $e);
        }

        if (!is_object($issuer) || !$issuer->get('enabled') || !$issuer->is_configured()) {
            throw new recording_authorization_exception('The recording OAuth issuer is unavailable.');
        }

        $authorizationurl = $issuer->get_endpoint_url('authorization');
        $parts = is_string($authorizationurl) ? parse_url($authorizationurl) : false;
        if (
            !is_array($parts) ||
            ($parts['scheme'] ?? null) !== 'https' ||
            ($parts['host'] ?? null) !== self::GOOGLE_AUTH_HOST
        ) {
            throw new recording_authorization_exception('The recording OAuth issuer is not Google.');
        }

        $returnurl = new \moodle_url('/mod/googlemeet/callback.php', [
            'recordings' => 1,
            'googlemeetid' => $googlemeetid,
            'issuerid' => $issuerid,
            'sesskey' => sesskey(),
        ]);
        $client = ($this->clientfactory)($issuer, $returnurl, self::RECORDING_SCOPE, true);
        if (!$client instanceof \core\oauth2\client) {
            throw new \coding_exception('The recording OAuth factory returned an invalid client.');
        }

        return $client;
    }
}
