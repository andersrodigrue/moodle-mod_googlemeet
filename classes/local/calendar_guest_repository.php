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
 * Persists bounded snapshots of attendees managed by Moodle.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class calendar_guest_repository {

    /** Activity table. */
    private const ACTIVITY_TABLE = 'googlemeet';

    /** Managed guest snapshot table. */
    private const GUEST_TABLE = 'googlemeet_calendar_guests';

    /** @var \core\clock Moodle clock. */
    private \core\clock $clock;

    /**
     * @param \core\clock|null $clock Moodle clock.
     */
    public function __construct(?\core\clock $clock = null) {
        $this->clock = $clock ?? \core\di::get(\core\clock::class);
    }

    /**
     * Returns hashes identifying the guests managed by the previous sync.
     *
     * @param int $googlemeetid Activity instance ID.
     * @return array<string, bool>
     */
    public function previous_hashes(int $googlemeetid): array {
        global $DB;

        $hashes = $DB->get_fieldset_select(
            self::GUEST_TABLE,
            'emailhash',
            'googlemeetid = :googlemeetid',
            ['googlemeetid' => $googlemeetid]
        );

        return array_fill_keys(array_map('strval', $hashes), true);
    }

    /**
     * Atomically replaces the managed snapshot after a Calendar mutation.
     *
     * @param int $googlemeetid Activity instance ID.
     * @param calendar_guest_snapshot $snapshot Snapshot sent to Calendar.
     */
    public function record_synchronised(int $googlemeetid, calendar_guest_snapshot $snapshot): void {
        global $DB;

        $now = $this->clock->time();
        $DB->delete_records(self::GUEST_TABLE, ['googlemeetid' => $googlemeetid]);
        foreach ($snapshot->database_rows() as $row) {
            $DB->insert_record(self::GUEST_TABLE, (object) [
                'googlemeetid' => $googlemeetid,
                'userid' => $row['userid'],
                'emailhash' => $row['emailhash'],
                'timemodified' => $now,
            ]);
        }
        $DB->update_record(self::ACTIVITY_TABLE, (object) [
            'id' => $googlemeetid,
            'guesthash' => $snapshot->hash(),
            'guestcount' => $snapshot->count(),
            'guesttimelastsync' => $now,
            'guesttimechecked' => $now,
            'sendupdates' => $snapshot->is_managed() ? 'all' : 'none',
        ]);
    }

    /**
     * Records a bounded membership check that required no remote mutation.
     *
     * @param int $googlemeetid Activity instance ID.
     */
    public function touch_checked(int $googlemeetid): void {
        global $DB;

        $DB->set_field(
            self::ACTIVITY_TABLE,
            'guesttimechecked',
            $this->clock->time(),
            ['id' => $googlemeetid]
        );
    }

    /**
     * Returns ready, opted-in activities due for a membership check.
     *
     * @param int $checkedbefore Latest accepted check time.
     * @param int $limit Maximum records.
     * @return \stdClass[]
     */
    public function reconciliation_candidates(int $checkedbefore, int $limit): array {
        global $DB;

        if ($checkedbefore < 0) {
            throw new \coding_exception('The guest reconciliation boundary cannot be negative.');
        }
        if ($limit < 1 || $limit > 100) {
            throw new \coding_exception('The guest reconciliation batch limit must be between 1 and 100.');
        }

        $records = $DB->get_records_sql(
            'SELECT gm.*
               FROM {' . self::ACTIVITY_TABLE . '} gm
               JOIN {user} u ON u.id = gm.owneruserid
              WHERE gm.guestpolicy = :guestpolicy
                AND gm.syncstatus = :syncstatus
                AND gm.owneruserid IS NOT NULL
                AND gm.owneruserid > :zero
                AND u.deleted = :notdeleted
                AND (gm.guesttimechecked IS NULL OR gm.guesttimechecked <= :checkedbefore)
           ORDER BY gm.guesttimechecked ASC, gm.id ASC',
            [
                'guestpolicy' => calendar_guest_policy::COURSE,
                'syncstatus' => sync_state::READY,
                'zero' => 0,
                'notdeleted' => 0,
                'checkedbefore' => $checkedbefore,
            ],
            0,
            $limit
        );

        return array_values($records);
    }

    /**
     * Removes all local guest snapshot data for an activity.
     *
     * @param int $googlemeetid Activity instance ID.
     */
    public function delete_activity_data(int $googlemeetid): void {
        global $DB;

        $DB->delete_records(self::GUEST_TABLE, ['googlemeetid' => $googlemeetid]);
        $DB->update_record(self::ACTIVITY_TABLE, (object) [
            'id' => $googlemeetid,
            'guesthash' => null,
            'guestcount' => 0,
            'guesttimelastsync' => null,
            'guesttimechecked' => null,
            'guestpolicy' => calendar_guest_policy::NONE,
            'sendupdates' => 'none',
        ]);
    }

    /**
     * Deletes approved users from one local managed snapshot.
     *
     * The aggregate hash is cleared because it can no longer prove equality
     * with the remote attendee set. Privacy deletion intentionally performs no
     * external API call.
     *
     * @param int $googlemeetid Activity instance ID.
     * @param int[] $userids Approved user IDs.
     */
    public function delete_users_data(int $googlemeetid, array $userids): void {
        global $DB;

        $userids = array_values(array_unique(array_filter(
            array_map('intval', $userids),
            static fn(int $userid): bool => $userid > 0
        )));
        if ($userids === []) {
            return;
        }

        [$usersql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'guestprivacy');
        $DB->delete_records_select(
            self::GUEST_TABLE,
            'googlemeetid = :googlemeetid AND userid ' . $usersql,
            ['googlemeetid' => $googlemeetid] + $params
        );
        $DB->update_record(self::ACTIVITY_TABLE, (object) [
            'id' => $googlemeetid,
            'guesthash' => null,
            'guestcount' => $DB->count_records(self::GUEST_TABLE, ['googlemeetid' => $googlemeetid]),
            'guesttimelastsync' => null,
            'guesttimechecked' => null,
        ]);
    }
}
