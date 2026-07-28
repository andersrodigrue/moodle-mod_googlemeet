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

use mod_googlemeet\api\recording_artifact;

/**
 * Persists recording authorization, discovery state and validated artifacts.
 *
 * Remote absence never deletes a local recording reference. Meet conference
 * records expire, and deletion would therefore be destructive and ambiguous.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class recording_repository {

    /** Maximum stored length for a sanitized error message. */
    private const ERROR_MESSAGE_MAX_LENGTH = 2000;

    /** @var string[] Fields accepted during state changes. */
    private const MUTABLE_STATE_FIELDS = [
        'recordingsyncattempts',
        'recordinglasterrorcode',
        'recordinglasterrormessage',
        'recordingtimelastattempt',
        'lastsync',
    ];

    /** @var \core\clock Moodle clock. */
    private \core\clock $clock;

    /**
     * @param \core\clock|null $clock Moodle clock.
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
        return $DB->get_record('googlemeet', ['id' => $googlemeetid], '*', MUST_EXIST);
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
        $record = $DB->get_record('googlemeet', ['id' => $googlemeetid], '*', IGNORE_MISSING);
        return $record ?: null;
    }

    /**
     * Claims an unowned recording integration for the authorized teacher.
     *
     * @param int $googlemeetid Activity instance ID.
     * @param int $owneruserid Authorized Moodle user.
     * @param int $issuerid Dedicated recording OAuth issuer.
     * @return \stdClass
     */
    public function claim(int $googlemeetid, int $owneruserid, int $issuerid): \stdClass {
        global $DB;

        if ($owneruserid <= 0 || $issuerid <= 0) {
            throw new \coding_exception('Recording authorization requires positive owner and issuer IDs.');
        }

        $meeting = $this->get($googlemeetid);
        $existingowner = (int) ($meeting->recordingowneruserid ?? 0);
        if ($existingowner > 0 && $existingowner !== $owneruserid) {
            throw new \moodle_exception('recordingowneronly', 'mod_googlemeet');
        }

        $DB->update_record('googlemeet', (object) [
            'id' => $googlemeetid,
            'recordingowneruserid' => $owneruserid,
            'recordingoauthissuerid' => $issuerid,
            'timemodified' => $this->clock->time(),
        ]);

        return $this->get($googlemeetid);
    }

    /**
     * Applies a validated recording state transition.
     *
     * @param int $googlemeetid Activity instance ID.
     * @param string $newstate Requested state.
     * @param array<string, mixed> $changes Additional recording fields.
     * @return \stdClass
     */
    public function transition(int $googlemeetid, string $newstate, array $changes = []): \stdClass {
        global $DB;

        $meeting = $this->get($googlemeetid);
        recording_sync_state::assert_transition((string) $meeting->recordingsyncstatus, $newstate);

        $update = (object) [
            'id' => $googlemeetid,
            'recordingsyncstatus' => $newstate,
            'timemodified' => $this->clock->time(),
        ];
        foreach ($changes as $field => $value) {
            if (!in_array($field, self::MUTABLE_STATE_FIELDS, true)) {
                throw new \coding_exception('Unsupported recording synchronization field: ' . $field);
            }
            $update->{$field} = $value;
        }

        $DB->update_record('googlemeet', $update);
        return $this->get($googlemeetid);
    }

    /**
     * Starts a discovery attempt and clears the previous safe error.
     *
     * @param int $googlemeetid Activity instance ID.
     * @return \stdClass
     */
    public function start_attempt(int $googlemeetid): \stdClass {
        $meeting = $this->get($googlemeetid);

        return $this->transition($googlemeetid, recording_sync_state::SYNCING, [
            'recordingsyncattempts' => (int) $meeting->recordingsyncattempts + 1,
            'recordingtimelastattempt' => $this->clock->time(),
            'recordinglasterrorcode' => null,
            'recordinglasterrormessage' => null,
        ]);
    }

    /**
     * Upserts a complete set of generated artifacts without deleting old links.
     *
     * User-edited names and visibility are preserved on existing records.
     *
     * @param int $googlemeetid Activity instance ID.
     * @param recording_artifact[] $artifacts Validated artifacts.
     * @return \stdClass Updated activity.
     */
    public function apply_artifacts(int $googlemeetid, array $artifacts): \stdClass {
        global $DB;

        $this->get($googlemeetid);
        $transaction = $DB->start_delegated_transaction();
        $now = $this->clock->time();

        foreach ($artifacts as $artifact) {
            if (!$artifact instanceof recording_artifact) {
                throw new \coding_exception('Only validated recording artifacts may be persisted.');
            }
            $fields = $artifact->database_fields();
            $existing = $DB->get_record('googlemeet_recordings', [
                'googlemeetid' => $googlemeetid,
                'recordingid' => $fields['recordingid'],
            ]);

            if ($existing) {
                $DB->update_record('googlemeet_recordings', (object) [
                    'id' => $existing->id,
                    'createdtime' => $fields['createdtime'],
                    'duration' => $fields['duration'],
                    'webviewlink' => $fields['webviewlink'],
                    'timemodified' => $now,
                ]);
                continue;
            }

            $DB->insert_record('googlemeet_recordings', (object) [
                'googlemeetid' => $googlemeetid,
                'recordingid' => $fields['recordingid'],
                'name' => $fields['name'],
                'createdtime' => $fields['createdtime'],
                'duration' => $fields['duration'],
                'webviewlink' => $fields['webviewlink'],
                'visible' => 1,
                'timemodified' => $now,
            ]);
        }

        $meeting = $this->transition($googlemeetid, recording_sync_state::READY, [
            'lastsync' => $now,
            'recordinglasterrorcode' => null,
            'recordinglasterrormessage' => null,
        ]);
        $transaction->allow_commit();

        return $meeting;
    }

    /**
     * Records a safe permanent discovery failure.
     *
     * @param int $googlemeetid Activity instance ID.
     * @param string $code Stable error code.
     * @param string $message Safe user-facing message.
     * @return \stdClass
     */
    public function mark_failed(int $googlemeetid, string $code, string $message): \stdClass {
        return $this->transition(
            $googlemeetid,
            recording_sync_state::FAILED,
            $this->normalise_error($code, $message)
        );
    }

    /**
     * Records that the recording owner must reconnect Google.
     *
     * @param int $googlemeetid Activity instance ID.
     * @param string $code Stable error code.
     * @param string $message Safe user-facing message.
     * @return \stdClass
     */
    public function mark_disconnected(int $googlemeetid, string $code, string $message): \stdClass {
        return $this->transition(
            $googlemeetid,
            recording_sync_state::DISCONNECTED,
            $this->normalise_error($code, $message)
        );
    }

    /**
     * Sanitizes error data before persistence.
     *
     * @param string $code Error code.
     * @param string $message Error message.
     * @return array{recordinglasterrorcode: string, recordinglasterrormessage: string}
     */
    private function normalise_error(string $code, string $message): array {
        $code = \core_text::substr(clean_param($code, PARAM_ALPHANUMEXT), 0, 100);
        $message = \core_text::substr(clean_param($message, PARAM_TEXT), 0, self::ERROR_MESSAGE_MAX_LENGTH);

        return [
            'recordinglasterrorcode' => $code !== '' ? $code : 'unknown',
            'recordinglasterrormessage' => $message,
        ];
    }

    /**
     * Rejects invalid activity IDs before database access.
     *
     * @param int $googlemeetid Activity instance ID.
     */
    private function assert_valid_id(int $googlemeetid): void {
        if ($googlemeetid <= 0) {
            throw new \coding_exception('A positive Google Meet activity ID is required.');
        }
    }
}
