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

namespace mod_googlemeet\output;

use mod_googlemeet\api\recording_authorization_exception;
use mod_googlemeet\local\recording_oauth_manager;
use mod_googlemeet\local\recording_sync_state;

/**
 * Boost-compatible recording list and OAuth synchronization panel.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class recording_panel implements \renderable, \templatable {

    /** @var \Closure Creates an owner-scoped recording OAuth client. */
    private \Closure $oauthclientfactory;

    /** @var int Configured dedicated recording issuer. */
    private int $issuerid;

    /**
     * @param \stdClass $meeting Activity record.
     * @param int $cmid Course module ID.
     * @param array<int, \stdClass> $recordings Presentation-safe recording rows.
     * @param bool $canedit Whether recording metadata may be edited.
     * @param bool $canremove Whether local recording references may be removed.
     * @param bool $cansync Whether recording discovery may be managed.
     * @param int $userid Current Moodle user ID.
     * @param int|null $issuerid Optional configured issuer override for tests.
     * @param callable|null $oauthclientfactory Optional OAuth client factory for tests.
     */
    public function __construct(
        private readonly \stdClass $meeting,
        private readonly int $cmid,
        private readonly array $recordings,
        private readonly bool $canedit,
        private readonly bool $canremove,
        private readonly bool $cansync,
        private readonly int $userid,
        ?int $issuerid = null,
        ?callable $oauthclientfactory = null
    ) {
        $this->issuerid = $issuerid ?? recording_oauth_manager::configured_issuer_id();
        $this->oauthclientfactory = \Closure::fromCallable(
            $oauthclientfactory ?? static fn(
                int $configuredissuerid,
                int $owneruserid,
                int $googlemeetid
            ) => (new recording_oauth_manager())->authorization_client(
                $configuredissuerid,
                $owneruserid,
                $googlemeetid
            )
        );
    }

    /**
     * Exports the complete recording section as escaped Mustache data.
     *
     * @param \renderer_base $output Moodle renderer.
     * @return array<string, mixed>
     */
    public function export_for_template(\renderer_base $output): array {
        $data = [
            'recordings' => $this->recordings,
            'hasrecordings' => $this->has_recordings(),
            'coursemoduleid' => $this->cmid,
            'canedit' => $this->canedit,
            'canremove' => $this->canremove,
            'showsync' => $this->cansync,
        ];

        if ($this->cansync) {
            $data += $this->synchronization_data();
        }

        return $data;
    }

    /**
     * Whether the panel contains recording rows.
     *
     * @return bool
     */
    public function has_recordings(): bool {
        return $this->recordings !== [];
    }

    /**
     * Whether the read-only client-side search enhancement is safe to enable.
     *
     * @return bool
     */
    public function requires_jstable(): bool {
        return $this->has_recordings() && !$this->canedit && !$this->canremove;
    }

    /**
     * Returns the AMD module configuration for this panel.
     *
     * @return array<string, mixed>
     */
    public function javascript_config(): array {
        return [
            'rootId' => 'googlemeet-recordings-' . $this->cmid,
            'coursemoduleid' => $this->cmid,
            'searchable' => $this->requires_jstable(),
            'strings' => [
                'hide' => get_string('hide', 'mod_googlemeet'),
                'show' => get_string('show', 'mod_googlemeet'),
                'invalidRecordingName' => get_string('invalidrecordingname', 'mod_googlemeet'),
                'deleteTitle' => get_string('deleterecordingreferencestitle', 'mod_googlemeet'),
                'deleteQuestion' => get_string('deleterecordingreferencesconfirm', 'mod_googlemeet'),
                'deleteButton' => get_string('deleterecordingreferences', 'mod_googlemeet'),
                'jstablePlaceholder' => get_string('jstablesearch', 'mod_googlemeet'),
                'jstablePerPage' => get_string('jstableperpage', 'mod_googlemeet'),
                'jstableNoRows' => get_string('jstablenorows', 'mod_googlemeet'),
                'jstableInfo' => get_string('jstableinfo', 'mod_googlemeet'),
                'jstableLoading' => get_string('jstableloading', 'mod_googlemeet'),
                'jstableInfoFiltered' => get_string('jstableinfofiltered', 'mod_googlemeet'),
            ],
        ];
    }

    /**
     * Builds recording lifecycle and OAuth presentation state.
     *
     * @return array<string, mixed>
     */
    private function synchronization_data(): array {
        $state = (string) ($this->meeting->recordingsyncstatus ?? '');
        if (!recording_sync_state::is_valid($state)) {
            $state = recording_sync_state::FAILED;
        }

        $data = [
            'recordingsyncstate' => $state,
            'recordingsynclabel' => get_string(
                'recordingsyncstatus' . $state,
                'mod_googlemeet'
            ),
            'recordingsyncbadgeclass' => match ($state) {
                recording_sync_state::READY => 'text-bg-success',
                recording_sync_state::FAILED, recording_sync_state::DISCONNECTED => 'text-bg-danger',
                default => 'text-bg-info',
            },
            'recordingsyncinprogress' => in_array($state, [
                recording_sync_state::QUEUED,
                recording_sync_state::SYNCING,
            ], true),
            'lastsyncvalue' => empty($this->meeting->lastsync)
                ? get_string('never', 'mod_googlemeet')
                : userdate(
                    (int) $this->meeting->lastsync,
                    get_string('timedate', 'mod_googlemeet')
                ),
        ];

        if (!empty($this->meeting->recordinglasterrormessage)) {
            $data['hasrecordingerror'] = true;
            $data['recordingerrormessage'] =
                (string) $this->meeting->recordinglasterrormessage;
        }

        $owneruserid = (int) ($this->meeting->recordingowneruserid ?? 0);
        $data['candisconnect'] = $owneruserid > 0 && $owneruserid === $this->userid;
        if ($data['candisconnect']) {
            $data += $this->command_data() + ['hasrecordingactions' => true];
        }

        if ($this->issuerid <= 0) {
            return $data + $this->notice_data(
                'warning',
                get_string('recordingsoauthunavailable', 'mod_googlemeet')
            );
        }
        if ($owneruserid > 0 && $owneruserid !== $this->userid) {
            return $data + $this->notice_data(
                'info',
                get_string('recordingowneronly', 'mod_googlemeet')
            );
        }

        try {
            $client = ($this->oauthclientfactory)(
                $this->issuerid,
                $this->userid,
                (int) $this->meeting->id
            );
            if ($client->is_logged_in()) {
                return $data + $this->command_data() + [
                    'hasrecordingactions' => true,
                    'cansync' => true,
                    'oauthconnected' => true,
                    'oauthconnectedmessage' => get_string(
                        'recordingsoauthconnected',
                        'mod_googlemeet'
                    ),
                ];
            }

            $loginurl = $client->get_login_url();
            if (!$loginurl instanceof \moodle_url) {
                throw new \coding_exception('The recording OAuth login URL is invalid.');
            }

            return $data + [
                'hasrecordingactions' => true,
                'canconnect' => true,
                'connecturl' => $loginurl->out(false),
            ];
        } catch (recording_authorization_exception | \moodle_exception) {
            return $data + $this->notice_data(
                'warning',
                get_string('recordingsoauthunavailable', 'mod_googlemeet')
            );
        }
    }

    /**
     * Returns session-protected command form data.
     *
     * @return array{commandurl: string, cmid: int, sesskey: string}
     */
    private function command_data(): array {
        return [
            'commandurl' => (new \moodle_url('/mod/googlemeet/recordings.php'))->out(false),
            'cmid' => $this->cmid,
            'sesskey' => sesskey(),
        ];
    }

    /**
     * Returns an accessible OAuth notice model.
     *
     * @param string $type Bootstrap notice type.
     * @param string $message Localized notice.
     * @return array<string, mixed>
     */
    private function notice_data(string $type, string $message): array {
        return [
            'hasoauthnotice' => true,
            'oauthnoticeclass' => 'alert-' . $type,
            'oauthnoticerole' => $type === 'warning' ? 'alert' : 'status',
            'oauthnoticemessage' => $message,
        ];
    }
}
