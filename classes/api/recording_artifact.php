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
 * Validated recording artifact safe for local persistence.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class recording_artifact {

    /** Recording file is ready in Drive. */
    public const FILE_GENERATED = 'FILE_GENERATED';

    /** @var string Opaque Drive file identifier. */
    private string $fileid;

    /** @var string Local display name. */
    private string $name;

    /** @var int Recording start timestamp. */
    private int $createdtime;

    /** @var string Human-readable duration. */
    private string $duration;

    /** @var string Validated Drive playback URL. */
    private string $webviewlink;

    /**
     * @param string $fileid Opaque Drive file identifier.
     * @param string $name Local display name.
     * @param int $createdtime Recording start timestamp.
     * @param string $duration Human-readable duration.
     * @param string $webviewlink Validated Drive playback URL.
     */
    private function __construct(
        string $fileid,
        string $name,
        int $createdtime,
        string $duration,
        string $webviewlink
    ) {
        $this->fileid = $fileid;
        $this->name = $name;
        $this->createdtime = $createdtime;
        $this->duration = $duration;
        $this->webviewlink = $webviewlink;
    }

    /**
     * Parses a FILE_GENERATED artifact returned under the expected conference.
     *
     * @param array<string, mixed> $response Google Meet recording resource.
     * @param string $conferencerecord Expected parent conference resource.
     * @param string $meetingname Activity name used for new local records.
     * @return self
     */
    public static function from_response(
        array $response,
        string $conferencerecord,
        string $meetingname
    ): self {
        $resource = self::required_string($response, 'name', 512);
        $expectedprefix = $conferencerecord . '/recordings/';
        if (
            !str_starts_with($resource, $expectedprefix) ||
            !preg_match(
                '#^conferenceRecords/[A-Za-z0-9_-]{10,255}/recordings/[A-Za-z0-9_-]{1,255}$#',
                $resource
            )
        ) {
            throw new recording_response_exception('A recording was returned for an unexpected conference.');
        }

        if (self::required_string($response, 'state', 32) !== self::FILE_GENERATED) {
            throw new recording_response_exception('A recording artifact is not ready.');
        }

        $destination = $response['driveDestination'] ?? null;
        if (!is_array($destination)) {
            throw new recording_response_exception('A generated recording has no Drive destination.');
        }

        $fileid = self::required_string($destination, 'file', 255);
        if (!preg_match('/^[A-Za-z0-9_-]{10,255}$/', $fileid)) {
            throw new recording_response_exception('A recording has an invalid Drive file identifier.');
        }

        $webviewlink = self::required_string($destination, 'exportUri', 2048);
        if (!self::is_valid_playback_uri($webviewlink, $fileid)) {
            throw new recording_response_exception('A recording has an invalid Drive playback URI.');
        }

        $start = self::timestamp($response, 'startTime');
        $end = self::timestamp($response, 'endTime');
        if ($end < $start) {
            throw new recording_response_exception('A recording has an invalid time range.');
        }

        $name = \core_text::substr(clean_param($meetingname, PARAM_TEXT), 0, 255);
        if ($name === '') {
            $name = get_string('recording', 'mod_googlemeet');
        }

        return new self(
            $fileid,
            $name,
            $start,
            self::format_duration($end - $start),
            $webviewlink
        );
    }

    /**
     * Returns fields accepted by the local recording repository.
     *
     * @return array{recordingid: string, name: string, createdtime: int, duration: string, webviewlink: string}
     */
    public function database_fields(): array {
        return [
            'recordingid' => $this->fileid,
            'name' => $this->name,
            'createdtime' => $this->createdtime,
            'duration' => $this->duration,
            'webviewlink' => $this->webviewlink,
        ];
    }

    /**
     * Returns the opaque Drive file identifier.
     *
     * @return string
     */
    public function file_id(): string {
        return $this->fileid;
    }

    /**
     * Reads a required bounded string.
     *
     * @param array<string, mixed> $data Source object.
     * @param string $field Field name.
     * @param int $maxlength Maximum bytes.
     * @return string
     */
    private static function required_string(array $data, string $field, int $maxlength): string {
        $value = $data[$field] ?? null;
        if (!is_string($value) || $value === '' || strlen($value) > $maxlength) {
            throw new recording_response_exception('The recording API omitted a required field.');
        }

        return $value;
    }

    /**
     * Parses a bounded RFC3339 timestamp.
     *
     * @param array<string, mixed> $data Source object.
     * @param string $field Field name.
     * @return int
     */
    private static function timestamp(array $data, string $field): int {
        $value = self::required_string($data, $field, 64);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-]\d{2}:\d{2})$/', $value)) {
            throw new recording_response_exception('A recording has an invalid timestamp.');
        }

        try {
            $timestamp = (new \DateTimeImmutable($value))->getTimestamp();
        } catch (\Exception $e) {
            throw new recording_response_exception('A recording has an invalid timestamp.', 0, $e);
        }
        if ($timestamp <= 0) {
            throw new recording_response_exception('A recording has an invalid timestamp.');
        }

        return $timestamp;
    }

    /**
     * Validates a browser playback URL and binds it to its Drive file ID.
     *
     * This public predicate is also used when presenting historical database
     * rows that may predate the strict API response boundary.
     *
     * @param string $uri Drive playback URI.
     * @param string $fileid Expected Drive file ID.
     * @return bool Whether the URI is safe to expose to a browser.
     */
    public static function is_valid_playback_uri(string $uri, string $fileid): bool {
        if (!preg_match('/^[A-Za-z0-9_-]{10,255}$/', $fileid)) {
            return false;
        }

        $parts = parse_url($uri);
        return !(
            !is_array($parts) ||
            ($parts['scheme'] ?? null) !== 'https' ||
            ($parts['host'] ?? null) !== 'drive.google.com' ||
            ($parts['path'] ?? null) !== '/file/d/' . $fileid . '/view' ||
            isset($parts['user']) ||
            isset($parts['pass']) ||
            isset($parts['port']) ||
            isset($parts['fragment'])
        );
    }

    /**
     * Formats a duration without relying on locale-specific text.
     *
     * @param int $seconds Duration in seconds.
     * @return string
     */
    private static function format_duration(int $seconds): string {
        $hours = intdiv($seconds, HOURSECS);
        $minutes = intdiv($seconds % HOURSECS, MINSECS);
        $remaining = $seconds % MINSECS;

        return $hours > 0
            ? sprintf('%d:%02d:%02d', $hours, $minutes, $remaining)
            : sprintf('%d:%02d', $minutes, $remaining);
    }
}
