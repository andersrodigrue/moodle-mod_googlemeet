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

use mod_googlemeet\api\calendar_identity;

/**
 * Persists remote Calendar cleanup obligations after an activity is deleted.
 *
 * The table deliberately has no foreign key to the activity. A Moodle module
 * or course deletion can therefore commit without losing the identifiers that
 * an owner-scoped task still needs to cancel the Google Calendar event.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class remote_cleanup_repository {

    /** Cleanup outbox table. */
    private const TABLE = 'googlemeet_remote_cleanup';

    /** A cleanup is ready to be queued. */
    public const PENDING = 'pending';

    /** An owner-scoped worker has started the remote deletion. */
    public const PROCESSING = 'processing';

    /** Provider access or configuration currently prevents deletion. */
    public const BLOCKED = 'blocked';

    /** Maximum rows returned to one scheduled reconciliation run. */
    public const CANDIDATE_LIMIT = 100;

    /** @var \core\clock Moodle clock. */
    private \core\clock $clock;

    /**
     * @param \core\clock|null $clock Moodle clock.
     */
    public function __construct(?\core\clock $clock = null) {
        $this->clock = $clock ?? \core\di::get(\core\clock::class);
    }

    /**
     * Captures an activity-owned event before the activity record is removed.
     *
     * Cancelled meetings and activities without a remote event have no cleanup
     * obligation. Missing owner metadata is retained as a blocked record rather
     * than silently discarding the only remaining remote identifiers.
     *
     * @param \stdClass $meeting Activity record being deleted.
     * @return \stdClass|null Cleanup record, or null when no remote deletion is required.
     */
    public function capture(\stdClass $meeting): ?\stdClass {
        global $DB;

        if (
            ($meeting->integrationmode ?? null) !== integration_mode::MANAGED
            || ($meeting->syncstatus ?? null) === sync_state::CANCELLED
        ) {
            return null;
        }

        $eventid = trim((string) ($meeting->googleeventid ?? ''));
        if ($eventid === '' && (int) ($meeting->id ?? 0) > 0) {
            // Calendar inserts use this controlled identity before Google is
            // contacted. It covers an insert that succeeded remotely but whose
            // response was lost before the activity could persist the ID.
            $eventid = (new calendar_identity())->event_id((int) $meeting->id);
        }
        if ($eventid === '') {
            return null;
        }

        $calendarid = trim((string) ($meeting->calendarid ?? ''));
        $owneruserid = (int) ($meeting->owneruserid ?? 0);
        $oauthissuerid = (int) ($meeting->oauthissuerid ?? 0);
        $cleanupkey = hash(
            'sha256',
            "v1\0{$owneruserid}\0{$oauthissuerid}\0{$calendarid}\0{$eventid}"
        );
        $existing = $DB->get_record(self::TABLE, ['cleanupkey' => $cleanupkey], '*', IGNORE_MISSING);
        if ($existing) {
            return $existing;
        }

        $actionable = $owneruserid > 0 && $oauthissuerid > 0 && $calendarid !== '';
        $id = (int) $DB->insert_record(self::TABLE, (object) [
            'cleanupkey' => $cleanupkey,
            'owneruserid' => $owneruserid > 0 ? $owneruserid : null,
            'oauthissuerid' => $oauthissuerid > 0 ? $oauthissuerid : null,
            'calendarid' => $calendarid !== '' ? $calendarid : null,
            'googleeventid' => $eventid,
            'guestcount' => max(0, (int) ($meeting->guestcount ?? 0)),
            'status' => $actionable ? self::PENDING : self::BLOCKED,
            'attempts' => 0,
            'lasterrorcode' => $actionable ? null : 'cleanup_metadata_missing',
            'timecreated' => $this->clock->time(),
            'timelastattempt' => null,
            'timenextattempt' => null,
        ]);

        return $this->get($id);
    }

    /**
     * Returns a cleanup record.
     *
     * @param int $id Cleanup ID.
     * @return \stdClass
     */
    public function get(int $id): \stdClass {
        global $DB;

        $this->assert_valid_id($id);
        return $DB->get_record(self::TABLE, ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Returns a cleanup record when it still exists.
     *
     * @param int $id Cleanup ID.
     * @return \stdClass|null
     */
    public function get_optional(int $id): ?\stdClass {
        global $DB;

        $this->assert_valid_id($id);
        $record = $DB->get_record(self::TABLE, ['id' => $id], '*', IGNORE_MISSING);
        return $record ?: null;
    }

    /**
     * Starts an owner-scoped deletion attempt.
     *
     * @param int $id Cleanup ID.
     * @return \stdClass Updated record.
     */
    public function start_attempt(int $id): \stdClass {
        global $DB;

        $record = $this->get($id);
        $DB->update_record(self::TABLE, (object) [
            'id' => $id,
            'status' => self::PROCESSING,
            'attempts' => (int) $record->attempts + 1,
            'lasterrorcode' => null,
            'timelastattempt' => $this->clock->time(),
            'timenextattempt' => null,
        ]);

        return $this->get($id);
    }

    /**
     * Retains a transient failure for Moodle task retry and stale recovery.
     *
     * @param int $id Cleanup ID.
     * @param string $code Stable diagnostic code.
     * @return \stdClass Updated record.
     */
    public function mark_retrying(int $id, string $code): \stdClass {
        return $this->update_failure($id, self::PROCESSING, $code, null);
    }

    /**
     * Retains a blocked cleanup and optionally schedules a bounded later retry.
     *
     * @param int $id Cleanup ID.
     * @param string $code Stable diagnostic code.
     * @param int|null $retryafter Delay in seconds, or null for manual intervention.
     * @return \stdClass Updated record.
     */
    public function mark_blocked(int $id, string $code, ?int $retryafter): \stdClass {
        if ($retryafter !== null && $retryafter < 1) {
            throw new \coding_exception('The remote cleanup retry delay must be positive.');
        }
        $nextattempt = $retryafter === null ? null : $this->clock->time() + $retryafter;

        return $this->update_failure($id, self::BLOCKED, $code, $nextattempt);
    }

    /**
     * Removes a fulfilled cleanup obligation.
     *
     * @param int $id Cleanup ID.
     */
    public function complete(int $id): void {
        global $DB;

        $this->assert_valid_id($id);
        $DB->delete_records(self::TABLE, ['id' => $id]);
    }

    /**
     * Returns pending, stale or retry-due cleanup obligations.
     *
     * @param int $now Current timestamp.
     * @param int $stalebefore Latest processing timestamp considered abandoned.
     * @param int $limit Maximum rows.
     * @return \stdClass[]
     */
    public function get_due_candidates(int $now, int $stalebefore, int $limit = self::CANDIDATE_LIMIT): array {
        global $DB;

        if ($now < 0 || $stalebefore < 0) {
            throw new \coding_exception('Remote cleanup reconciliation timestamps cannot be negative.');
        }
        if ($limit < 1 || $limit > self::CANDIDATE_LIMIT) {
            throw new \coding_exception('The remote cleanup reconciliation limit is invalid.');
        }

        $sql = 'SELECT rc.id, rc.owneruserid, rc.status, rc.timelastattempt, rc.timenextattempt
                  FROM {' . self::TABLE . '} rc
                  JOIN {user} u ON u.id = rc.owneruserid
                 WHERE u.deleted = :notdeleted
                   AND (
                        rc.status = :pending
                        OR (
                            rc.status = :processing
                            AND (
                                rc.timelastattempt IS NULL
                                OR rc.timelastattempt <= :stalebefore
                            )
                        )
                        OR (
                            rc.status = :blocked
                            AND rc.timenextattempt IS NOT NULL
                            AND rc.timenextattempt <= :now
                        )
                   )
              ORDER BY rc.timenextattempt ASC, rc.timelastattempt ASC, rc.id ASC';
        $records = $DB->get_records_sql($sql, [
            'notdeleted' => 0,
            'pending' => self::PENDING,
            'processing' => self::PROCESSING,
            'stalebefore' => $stalebefore,
            'blocked' => self::BLOCKED,
            'now' => $now,
        ], 0, $limit);

        return array_values($records);
    }

    /**
     * Deletes cleanup records belonging to one user after an approved privacy request.
     *
     * @param int $userid User ID.
     */
    public function delete_user_data(int $userid): void {
        global $DB;

        if ($userid <= 0) {
            throw new \coding_exception('A positive remote cleanup owner ID is required.');
        }
        $DB->delete_records(self::TABLE, ['owneruserid' => $userid]);
    }

    /**
     * Deletes every cleanup record after approved system-context erasure.
     */
    public function delete_all_user_data(): void {
        global $DB;

        $DB->delete_records(self::TABLE);
    }

    /**
     * Applies a closed, bounded failure state.
     *
     * @param int $id Cleanup ID.
     * @param string $status New status.
     * @param string $code Stable diagnostic code.
     * @param int|null $nextattempt Next retry timestamp.
     * @return \stdClass Updated record.
     */
    private function update_failure(int $id, string $status, string $code, ?int $nextattempt): \stdClass {
        global $DB;

        $this->get($id);
        $code = clean_param($code, PARAM_ALPHANUMEXT);
        $code = \core_text::substr($code, 0, 100);
        $DB->update_record(self::TABLE, (object) [
            'id' => $id,
            'status' => $status,
            'lasterrorcode' => $code !== '' ? $code : 'unknown',
            'timenextattempt' => $nextattempt,
        ]);

        return $this->get($id);
    }

    /**
     * Validates a cleanup identifier.
     *
     * @param int $id Cleanup ID.
     */
    private function assert_valid_id(int $id): void {
        if ($id <= 0) {
            throw new \coding_exception('A positive remote cleanup ID is required.');
        }
    }
}
