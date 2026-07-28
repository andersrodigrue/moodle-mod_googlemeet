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
 * Removes owner-scoped integration data without deleting shared activity data.
 *
 * OAuth tokens belong to Moodle core and can be shared by more than one
 * activity. This lifecycle therefore detaches only this activity and never
 * revokes an issuer-level token or calls a remote deletion API.
 *
 * Recording references are shared course content. They remain available after
 * the teacher who authorized discovery disconnects or exercises a privacy
 * deletion request.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class privacy_lifecycle {

    /** @var meeting_lock Activity-scoped concurrency boundary. */
    private meeting_lock $lock;

    /** @var \core\clock Moodle clock. */
    private \core\clock $clock;

    /**
     * @param meeting_lock|null $lock Activity lock.
     * @param \core\clock|null $clock Moodle clock.
     */
    public function __construct(
        ?meeting_lock $lock = null,
        ?\core\clock $clock = null
    ) {
        $this->lock = $lock ?? new meeting_lock();
        $this->clock = $clock ?? \core\di::get(\core\clock::class);
    }

    /**
     * Detaches a cancelled managed Calendar integration from its owner.
     *
     * Requiring a settled cancellation prevents a local disconnect from
     * silently orphaning an active remote Calendar event.
     *
     * @param int $googlemeetid Activity instance ID.
     * @param int $owneruserid Expected current owner.
     * @return \stdClass Updated activity.
     */
    public function disconnect_calendar(int $googlemeetid, int $owneruserid): \stdClass {
        return $this->lock->with_lock($googlemeetid, function () use ($googlemeetid, $owneruserid) {
            global $DB;

            $meeting = $DB->get_record('googlemeet', ['id' => $googlemeetid], '*', MUST_EXIST);
            if (
                $meeting->integrationmode !== integration_mode::MANAGED ||
                (int) ($meeting->owneruserid ?? 0) !== $owneruserid
            ) {
                throw new \moodle_exception('managedowneronly', 'mod_googlemeet');
            }
            if ($meeting->syncstatus !== sync_state::CANCELLED) {
                throw new \moodle_exception('syncdisconnectrequirescancel', 'mod_googlemeet');
            }

            $this->update_meeting($meeting, $this->calendar_fields(true));

            return $DB->get_record('googlemeet', ['id' => $googlemeetid], '*', MUST_EXIST);
        });
    }

    /**
     * Detaches recording discovery while preserving discovered references.
     *
     * @param int $googlemeetid Activity instance ID.
     * @param int $owneruserid Expected current recording owner.
     * @return \stdClass Updated activity.
     */
    public function disconnect_recordings(int $googlemeetid, int $owneruserid): \stdClass {
        return $this->lock->with_lock($googlemeetid, function () use ($googlemeetid, $owneruserid) {
            global $DB;

            $meeting = $DB->get_record('googlemeet', ['id' => $googlemeetid], '*', MUST_EXIST);
            if ((int) ($meeting->recordingowneruserid ?? 0) !== $owneruserid) {
                throw new \moodle_exception('recordingowneronly', 'mod_googlemeet');
            }

            $this->update_meeting($meeting, $this->recording_fields());

            return $DB->get_record('googlemeet', ['id' => $googlemeetid], '*', MUST_EXIST);
        });
    }

    /**
     * Deletes one user's local personal data from an activity.
     *
     * @param int $googlemeetid Activity instance ID.
     * @param int $userid Moodle user ID.
     * @param string $email Current user email used to identify legacy data.
     */
    public function delete_user_data(int $googlemeetid, int $userid, string $email = ''): void {
        $emails = $email === '' ? [] : [$userid => $email];
        $this->delete_users_data($googlemeetid, [$userid], $emails);
    }

    /**
     * Deletes several users' local personal data from an activity.
     *
     * @param int $googlemeetid Activity instance ID.
     * @param int[] $userids Moodle user IDs.
     * @param array<int, string> $emails User emails keyed by user ID.
     */
    public function delete_users_data(int $googlemeetid, array $userids, array $emails = []): void {
        $userids = array_values(array_unique(array_filter(
            array_map('intval', $userids),
            static fn(int $userid): bool => $userid > 0
        )));
        if (!$userids) {
            return;
        }

        $this->lock->with_lock($googlemeetid, function () use ($googlemeetid, $userids, $emails): void {
            global $DB;

            $meeting = $DB->get_record('googlemeet', ['id' => $googlemeetid], '*', IGNORE_MISSING);
            if (!$meeting) {
                return;
            }

            $changes = [];
            if (in_array((int) ($meeting->owneruserid ?? 0), $userids, true)) {
                $changes += $this->calendar_fields(
                    $meeting->integrationmode === integration_mode::MANAGED
                );
            }
            if (in_array((int) ($meeting->recordingowneruserid ?? 0), $userids, true)) {
                $changes += $this->recording_fields();
            }

            $legacyemails = array_map(
                static fn(string $email): string => \core_text::strtolower(trim($email)),
                array_filter($emails, 'is_string')
            );
            $storedemail = \core_text::strtolower(trim((string) ($meeting->creatoremail ?? '')));
            if ($storedemail !== '' && in_array($storedemail, $legacyemails, true)) {
                $changes += $this->legacy_creator_fields();
            }

            if ($changes) {
                $this->update_meeting($meeting, $changes);
            }
            $this->delete_notification_receipts($googlemeetid, $userids);
        });
    }

    /**
     * Deletes all local personal data from one activity context.
     *
     * Shared schedule settings and recording references remain intact.
     *
     * @param int $googlemeetid Activity instance ID.
     */
    public function delete_all_user_data(int $googlemeetid): void {
        $this->lock->with_lock($googlemeetid, function () use ($googlemeetid): void {
            global $DB;

            $meeting = $DB->get_record('googlemeet', ['id' => $googlemeetid], '*', IGNORE_MISSING);
            if (!$meeting) {
                return;
            }

            $changes = $this->legacy_creator_fields();
            if (
                (int) ($meeting->owneruserid ?? 0) > 0 ||
                $meeting->integrationmode === integration_mode::MANAGED
            ) {
                $changes += $this->calendar_fields(
                    $meeting->integrationmode === integration_mode::MANAGED
                );
            }
            if (
                (int) ($meeting->recordingowneruserid ?? 0) > 0 ||
                (int) ($meeting->recordingoauthissuerid ?? 0) > 0
            ) {
                $changes += $this->recording_fields();
            }
            $this->update_meeting($meeting, $changes);
            $DB->delete_records_select(
                'googlemeet_notify_done',
                'eventid IN (SELECT id FROM {googlemeet_events} WHERE googlemeetid = :googlemeetid)',
                ['googlemeetid' => $googlemeetid]
            );
        });
    }

    /**
     * Fields removed when Calendar ownership is detached.
     *
     * @param bool $clearjoinlink Whether the managed remote join link must be removed.
     * @return array<string, mixed>
     */
    private function calendar_fields(bool $clearjoinlink): array {
        $fields = [
            'owneruserid' => null,
            'oauthissuerid' => null,
            'calendarid' => null,
            'googleeventid' => null,
            'googleeventhtmlurl' => null,
            'googleeventetag' => null,
            'requestid' => null,
            'conferenceid' => null,
            'meetingcode' => null,
            'conferencestatus' => null,
            'syncstatus' => sync_state::DISCONNECTED,
            'syncattempts' => 0,
            'lasterrorcode' => null,
            'lasterrormessage' => null,
            'timelastattempt' => null,
        ];
        if ($clearjoinlink) {
            $fields['meetinguri'] = null;
            $fields['url'] = '';
        }
        return $fields;
    }

    /**
     * Fields removed when recording authorization is detached.
     *
     * @return array<string, mixed>
     */
    private function recording_fields(): array {
        return [
            'recordingowneruserid' => null,
            'recordingoauthissuerid' => null,
            'recordingsyncstatus' => recording_sync_state::DISCONNECTED,
            'recordingsyncattempts' => 0,
            'recordinglasterrorcode' => null,
            'recordinglasterrormessage' => null,
            'recordingtimelastattempt' => null,
        ];
    }

    /**
     * Legacy organizer identity fields.
     *
     * @return array<string, mixed>
     */
    private function legacy_creator_fields(): array {
        return [
            'creatoremail' => null,
            'eventid' => null,
        ];
    }

    /**
     * Applies a bounded activity update.
     *
     * @param \stdClass $meeting Current activity.
     * @param array<string, mixed> $changes Sanitized fields.
     */
    private function update_meeting(\stdClass $meeting, array $changes): void {
        global $DB;

        $update = (object) [
            'id' => $meeting->id,
            'timemodified' => $this->clock->time(),
        ];
        foreach ($changes as $field => $value) {
            $update->{$field} = $value;
        }
        $DB->update_record('googlemeet', $update);
    }

    /**
     * Deletes notification receipts for specified users.
     *
     * @param int $googlemeetid Activity instance ID.
     * @param int[] $userids Moodle user IDs.
     */
    private function delete_notification_receipts(int $googlemeetid, array $userids): void {
        global $DB;

        [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'privacyuser');
        $select = 'userid ' . $usersql
            . ' AND eventid IN (SELECT id FROM {googlemeet_events} WHERE googlemeetid = :googlemeetid)';
        $DB->delete_records_select(
            'googlemeet_notify_done',
            $select,
            ['googlemeetid' => $googlemeetid] + $userparams
        );
    }
}
