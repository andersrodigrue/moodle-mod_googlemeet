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

/**
 * Persists the local state of a Google Meet synchronization.
 *
 * Concurrency is coordinated by {@see meeting_lock}. This repository validates
 * every state transition and restricts the fields that can change with it.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class sync_repository {

    /** Database table containing activity instances. */
    private const TABLE = 'googlemeet';

    /** Maximum stored length for a sanitized synchronization error message. */
    private const ERROR_MESSAGE_MAX_LENGTH = 2000;

    /** @var string[] Fields that may be updated together with a synchronization state. */
    private const MUTABLE_SYNC_FIELDS = [
        'syncattempts',
        'lasterrorcode',
        'lasterrormessage',
        'timelastattempt',
    ];

    /** @var \core\clock Moodle clock. */
    private \core\clock $clock;

    /**
     * @param \core\clock|null $clock Moodle clock, or null to use the configured clock.
     */
    public function __construct(?\core\clock $clock = null) {
        $this->clock = $clock ?? \core\di::get(\core\clock::class);
    }

    /**
     * Returns an activity or throws when it does not exist.
     *
     * @param int $googlemeetid Activity instance ID.
     * @return \stdClass
     */
    public function get(int $googlemeetid): \stdClass {
        global $DB;

        $this->assert_valid_id($googlemeetid);

        return $DB->get_record(self::TABLE, ['id' => $googlemeetid], '*', MUST_EXIST);
    }

    /**
     * Returns an activity when it still exists.
     *
     * @param int $googlemeetid Activity instance ID.
     * @return \stdClass|null
     */
    public function get_optional(int $googlemeetid): ?\stdClass {
        global $DB;

        $this->assert_valid_id($googlemeetid);

        $record = $DB->get_record(self::TABLE, ['id' => $googlemeetid], '*', IGNORE_MISSING);

        return $record ?: null;
    }

    /**
     * Applies a validated synchronization transition.
     *
     * @param int $googlemeetid Activity instance ID.
     * @param string $newstate Requested synchronization state.
     * @param array<string, mixed> $changes Additional synchronization fields.
     * @return \stdClass Updated activity.
     */
    public function transition(int $googlemeetid, string $newstate, array $changes = []): \stdClass {
        global $DB;

        $meeting = $this->get($googlemeetid);
        sync_state::assert_transition((string) $meeting->syncstatus, $newstate);

        $update = (object) [
            'id' => $googlemeetid,
            'syncstatus' => $newstate,
            'timemodified' => $this->clock->time(),
        ];

        foreach ($changes as $field => $value) {
            if (!in_array($field, self::MUTABLE_SYNC_FIELDS, true)) {
                throw new \coding_exception('Unsupported Google Meet synchronization field: ' . $field);
            }
            $update->{$field} = $value;
        }

        $DB->update_record(self::TABLE, $update);

        return $this->get($googlemeetid);
    }

    /**
     * Starts an attempt and clears the previous sanitized error.
     *
     * @param int $googlemeetid Activity instance ID.
     * @return \stdClass Updated activity.
     */
    public function start_attempt(int $googlemeetid): \stdClass {
        $meeting = $this->get($googlemeetid);
        $now = $this->clock->time();

        return $this->transition($googlemeetid, sync_state::SYNCING, [
            'syncattempts' => (int) $meeting->syncattempts + 1,
            'timelastattempt' => $now,
            'lasterrorcode' => null,
            'lasterrormessage' => null,
        ]);
    }

    /**
     * Records a safe synchronization failure.
     *
     * @param int $googlemeetid Activity instance ID.
     * @param string $code Stable, non-secret error code.
     * @param string $message Safe administrator-facing message.
     * @return \stdClass Updated activity.
     */
    public function mark_failed(int $googlemeetid, string $code, string $message): \stdClass {
        return $this->transition($googlemeetid, sync_state::FAILED, $this->normalise_error($code, $message));
    }

    /**
     * Records that the meeting owner must reconnect Google.
     *
     * @param int $googlemeetid Activity instance ID.
     * @param string $code Stable, non-secret error code.
     * @param string $message Safe administrator-facing message.
     * @return \stdClass Updated activity.
     */
    public function mark_disconnected(int $googlemeetid, string $code, string $message): \stdClass {
        return $this->transition($googlemeetid, sync_state::DISCONNECTED, $this->normalise_error($code, $message));
    }

    /**
     * Sanitizes error data before persistence.
     *
     * @param string $code Error code.
     * @param string $message Error message.
     * @return array{lasterrorcode: string, lasterrormessage: string}
     */
    private function normalise_error(string $code, string $message): array {
        $code = \core_text::substr(clean_param($code, PARAM_ALPHANUMEXT), 0, 100);
        $message = \core_text::substr(clean_param($message, PARAM_TEXT), 0, self::ERROR_MESSAGE_MAX_LENGTH);

        return [
            'lasterrorcode' => $code !== '' ? $code : 'unknown',
            'lasterrormessage' => $message,
        ];
    }

    /**
     * Rejects invalid resource identifiers before accessing the database.
     *
     * @param int $googlemeetid Activity instance ID.
     */
    private function assert_valid_id(int $googlemeetid): void {
        if ($googlemeetid <= 0) {
            throw new \coding_exception('A positive Google Meet activity ID is required.');
        }
    }
}
