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

use mod_googlemeet\local\sync_state;

/**
 * Boost-compatible synchronization status card.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class sync_status implements \renderable, \templatable {

    /** @var \stdClass Activity record. */
    private \stdClass $meeting;

    /** @var bool Whether administrator-facing diagnostic details may be shown. */
    private bool $showdetails;

    /** @var int Course module ID used by secure action forms. */
    private int $cmid;

    /** @var bool Whether owner-scoped management actions may be shown. */
    private bool $showactions;

    /**
     * @param \stdClass $meeting Activity record.
     * @param bool $showdetails Whether to include sanitized diagnostic details.
     * @param int $cmid Course module ID used by secure action forms.
     * @param bool $showactions Whether owner-scoped management actions may be shown.
     */
    public function __construct(
        \stdClass $meeting,
        bool $showdetails = false,
        int $cmid = 0,
        bool $showactions = false
    ) {
        $this->meeting = $meeting;
        $this->showdetails = $showdetails;
        $this->cmid = $cmid;
        $this->showactions = $showactions;
    }

    /**
     * Exports a small, escaped-by-Mustache status model.
     *
     * @param \renderer_base $output Moodle renderer.
     * @return array<string, mixed>
     */
    public function export_for_template(\renderer_base $output): array {
        $state = (string) ($this->meeting->syncstatus ?? '');
        if (!sync_state::is_valid($state)) {
            $state = sync_state::FAILED;
        }

        $inprogress = in_array($state, [
            sync_state::DRAFT,
            sync_state::QUEUED,
            sync_state::SYNCING,
            sync_state::PENDING,
            sync_state::CANCELLING,
        ], true) && !(
            $state === sync_state::CANCELLING &&
            !empty($this->meeting->lasterrorcode)
        );
        $badgeclass = match ($state) {
            sync_state::READY => 'text-bg-success',
            sync_state::FAILED, sync_state::DISCONNECTED => 'text-bg-danger',
            sync_state::CANCELLED => 'text-bg-secondary',
            default => 'text-bg-info',
        };

        $data = [
            'state' => $state,
            'label' => get_string('syncstatus' . $state, 'mod_googlemeet'),
            'message' => get_string('syncstatus' . $state . '_desc', 'mod_googlemeet'),
            'badgeclass' => $badgeclass,
            'inprogress' => $inprogress,
            'lastattempttext' => empty($this->meeting->timelastattempt)
                ? null
                : get_string(
                    'synclastattempt',
                    'mod_googlemeet',
                    userdate((int) $this->meeting->timelastattempt)
                ),
        ];

        if ($this->showdetails && !empty($this->meeting->lasterrorcode)) {
            $data['haserror'] = true;
            $data['errorcode'] = (string) $this->meeting->lasterrorcode;
            $data['errormessage'] = (string) ($this->meeting->lasterrormessage ?? '');
        }

        if ($this->showactions && $this->cmid > 0) {
            $data['actionurl'] = (new \moodle_url('/mod/googlemeet/action.php'))->out(false);
            $data['cmid'] = $this->cmid;
            $data['sesskey'] = sesskey();
            $cancellationblocked = $state === sync_state::CANCELLING
                && !empty($this->meeting->lasterrorcode);
            $data['canretry'] = $state === sync_state::FAILED
                || (
                    $cancellationblocked &&
                    $this->meeting->lasterrorcode !== 'authorization_required'
                );
            $data['canreconnect'] = $state === sync_state::DISCONNECTED
                || (
                    $cancellationblocked &&
                    $this->meeting->lasterrorcode === 'authorization_required'
                );
            $data['cancancel'] = in_array($state, [
                sync_state::DRAFT,
                sync_state::QUEUED,
                sync_state::SYNCING,
                sync_state::PENDING,
                sync_state::READY,
                sync_state::FAILED,
            ], true);
            $data['hasactions'] = $data['canretry'] || $data['canreconnect'] || $data['cancancel'];
        }

        return $data;
    }
}
