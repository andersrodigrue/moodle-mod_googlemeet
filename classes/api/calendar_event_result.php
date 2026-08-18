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
 * Validated local representation of a Calendar event response.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class calendar_event_result {

    /** Google is still creating the conference. */
    public const PENDING = 'pending';

    /** Google created the conference and returned a video entry point. */
    public const SUCCESS = 'success';

    /** Google rejected the conference create request. */
    public const FAILURE = 'failure';

    /** @var string Calendar event identifier. */
    private string $eventid;

    /** @var string Conference create request identifier. */
    private string $requestid;

    /** @var string Conference create status. */
    private string $status;

    /** @var string|null Calendar Web UI URL. */
    private ?string $htmlurl;

    /** @var string|null Calendar entity tag. */
    private ?string $etag;

    /** @var string|null Google conference identifier. */
    private ?string $conferenceid;

    /** @var string|null Google Meet code. */
    private ?string $meetingcode;

    /** @var string|null Google Meet video entry point. */
    private ?string $meetinguri;

    /**
     * @param string $eventid Calendar event identifier.
     * @param string $requestid Conference create request identifier.
     * @param string $status Conference create status.
     * @param string|null $htmlurl Calendar Web UI URL.
     * @param string|null $etag Calendar entity tag.
     * @param string|null $conferenceid Google conference identifier.
     * @param string|null $meetingcode Google Meet code.
     * @param string|null $meetinguri Google Meet video entry point.
     */
    private function __construct(
        string $eventid,
        string $requestid,
        string $status,
        ?string $htmlurl,
        ?string $etag,
        ?string $conferenceid,
        ?string $meetingcode,
        ?string $meetinguri
    ) {
        $this->eventid = $eventid;
        $this->requestid = $requestid;
        $this->status = $status;
        $this->htmlurl = $htmlurl;
        $this->etag = $etag;
        $this->conferenceid = $conferenceid;
        $this->meetingcode = $meetingcode;
        $this->meetinguri = $meetinguri;
    }

    /**
     * Validates and maps a Calendar event resource.
     *
     * @param array<string, mixed> $response Calendar event resource.
     * @param string $expectedeventid Event ID sent by the application.
     * @param string $expectedrequestid Conference request ID sent by the application.
     * @return self
     */
    public static function from_response(
        array $response,
        string $expectedeventid,
        string $expectedrequestid
    ): self {
        $eventid = self::required_string($response, 'id', 255);
        if (!hash_equals($expectedeventid, $eventid)) {
            throw new calendar_response_exception('Google Calendar returned a different event ID.');
        }

        $conferencedata = $response['conferenceData'] ?? [];
        if (!is_array($conferencedata)) {
            throw new calendar_response_exception('Google Calendar returned invalid conference data.');
        }

        $createrequest = $conferencedata['createRequest'] ?? [];
        if (!is_array($createrequest)) {
            throw new calendar_response_exception('Google Calendar returned an invalid conference request.');
        }

        $returnedrequestid = self::required_string($createrequest, 'requestId', 64);
        if (!hash_equals($expectedrequestid, $returnedrequestid)) {
            throw new calendar_response_exception('Google Calendar returned a different conference request ID.');
        }

        $videoentrypoint = self::video_entry_point($conferencedata);
        $statusdata = $createrequest['status'] ?? [];
        if (!is_array($statusdata)) {
            throw new calendar_response_exception('Google Calendar returned an invalid conference status.');
        }
        $status = self::optional_string($statusdata, 'statusCode', 32);
        if ($status === null && $videoentrypoint !== null) {
            $status = self::SUCCESS;
        }
        if (!in_array($status, [self::PENDING, self::SUCCESS, self::FAILURE], true)) {
            throw new calendar_response_exception('Google Calendar returned an unknown conference status.');
        }

        $meetinguri = null;
        $meetingcode = null;
        if ($status === self::SUCCESS) {
            if ($videoentrypoint === null) {
                throw new calendar_response_exception('A successful conference has no video entry point.');
            }
            $meetinguri = self::required_https_url($videoentrypoint, 'uri');
            $meetingcode = self::optional_string($videoentrypoint, 'meetingCode', 32);
        }

        $conferenceid = self::optional_string($conferencedata, 'conferenceId', 255);
        if ($meetingcode === null && $status === self::SUCCESS && $conferenceid !== null) {
            $meetingcode = strlen($conferenceid) <= 32 ? $conferenceid : null;
        }

        return new self(
            $eventid,
            $expectedrequestid,
            $status,
            self::optional_https_url($response, 'htmlLink'),
            self::optional_string($response, 'etag', 255),
            $conferenceid,
            $meetingcode,
            $meetinguri
        );
    }

    /**
     * Returns the Calendar event identifier.
     *
     * @return string
     */
    public function event_id(): string {
        return $this->eventid;
    }

    /**
     * Returns the conference request identifier.
     *
     * @return string
     */
    public function request_id(): string {
        return $this->requestid;
    }

    /**
     * Returns the conference create status.
     *
     * @return string
     */
    public function status(): string {
        return $this->status;
    }

    /**
     * Returns fields safe to persist in the activity record.
     *
     * @return array<string, string|null>
     */
    public function database_fields(): array {
        return [
            'googleeventid' => $this->eventid,
            'googleeventhtmlurl' => $this->htmlurl,
            'googleeventetag' => $this->etag,
            'requestid' => $this->requestid,
            'conferenceid' => $this->conferenceid,
            'meetingcode' => $this->meetingcode,
            'meetinguri' => $this->meetinguri,
            'conferencestatus' => $this->status,
        ];
    }

    /**
     * Finds the video entry point in conference data.
     *
     * @param array<string, mixed> $conferencedata Conference data.
     * @return array<string, mixed>|null
     */
    private static function video_entry_point(array $conferencedata): ?array {
        $entrypoints = $conferencedata['entryPoints'] ?? [];
        if (!is_array($entrypoints)) {
            throw new calendar_response_exception('Google Calendar returned invalid conference entry points.');
        }

        foreach ($entrypoints as $entrypoint) {
            if (!is_array($entrypoint)) {
                throw new calendar_response_exception('Google Calendar returned an invalid conference entry point.');
            }
            if (($entrypoint['entryPointType'] ?? null) === 'video') {
                return $entrypoint;
            }
        }

        return null;
    }

    /**
     * Returns a required bounded string.
     *
     * @param array<string, mixed> $data Source data.
     * @param string $key Field name.
     * @param int $maxlength Maximum allowed length.
     * @return string
     */
    private static function required_string(array $data, string $key, int $maxlength): string {
        $value = self::optional_string($data, $key, $maxlength);
        if ($value === null) {
            throw new calendar_response_exception('Google Calendar response is missing ' . $key . '.');
        }

        return $value;
    }

    /**
     * Returns an optional bounded string.
     *
     * @param array<string, mixed> $data Source data.
     * @param string $key Field name.
     * @param int $maxlength Maximum allowed length.
     * @return string|null
     */
    private static function optional_string(array $data, string $key, int $maxlength): ?string {
        if (!array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
            return null;
        }
        if (!is_string($data[$key]) && !is_int($data[$key])) {
            throw new calendar_response_exception('Google Calendar returned an invalid ' . $key . '.');
        }

        $value = trim((string) $data[$key]);
        if ($value === '' || strlen($value) > $maxlength) {
            throw new calendar_response_exception('Google Calendar returned an invalid ' . $key . '.');
        }

        return $value;
    }

    /**
     * Returns an optional absolute HTTPS URL.
     *
     * @param array<string, mixed> $data Source data.
     * @param string $key Field name.
     * @return string|null
     */
    private static function optional_https_url(array $data, string $key): ?string {
        if (!array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
            return null;
        }

        return self::required_https_url($data, $key);
    }

    /**
     * Returns a required absolute HTTPS URL.
     *
     * @param array<string, mixed> $data Source data.
     * @param string $key Field name.
     * @return string
     */
    private static function required_https_url(array $data, string $key): string {
        $url = self::required_string($data, $key, 2048);
        $parts = parse_url($url);
        if (
            $parts === false ||
            ($parts['scheme'] ?? '') !== 'https' ||
            empty($parts['host'])
        ) {
            throw new calendar_response_exception('Google Calendar returned an invalid ' . $key . ' URL.');
        }

        return $url;
    }
}
