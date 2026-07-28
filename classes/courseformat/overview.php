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

namespace mod_googlemeet\courseformat;

use cm_info;
use core\output\action_link;
use core\output\local\properties\button;
use core\output\local\properties\text_align;
use core\url;
use core_calendar\output\humandate;
use core_courseformat\activityoverviewbase;
use core_courseformat\local\overview\overviewitem;
use mod_googlemeet\local\sync_state;

/**
 * Moodle 5.2 course activity overview integration.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class overview extends activityoverviewbase {

    /** @var \stdClass Activity record. */
    private \stdClass $meeting;

    /**
     * @param cm_info $cm Course module information.
     * @param \moodle_database $db Moodle database dependency.
     */
    public function __construct(
        cm_info $cm,
        \moodle_database $db
    ) {
        parent::__construct($cm);
        $this->meeting = $db->get_record('googlemeet', ['id' => $cm->instance], '*', MUST_EXIST);
    }

    /**
     * Provides the meeting time and synchronization state columns.
     *
     * @return overviewitem[]
     */
    #[\Override]
    public function get_extra_overview_items(): array {
        return [
            'meetingtime' => $this->get_meeting_time_overview(),
            'syncstatus' => $this->get_sync_status_overview(),
        ];
    }

    /**
     * Provides the primary join or view action.
     *
     * @return overviewitem
     */
    #[\Override]
    public function get_actions_overview(): ?overviewitem {
        $meetinguri = trim((string) ($this->meeting->meetinguri ?? ''));
        $canjoin = $this->meeting->syncstatus === sync_state::READY
            && $this->is_valid_meet_uri($meetinguri);
        $text = $canjoin
            ? get_string('overviewjoinmeeting', 'mod_googlemeet')
            : get_string('view');
        $target = $canjoin
            ? new url($meetinguri)
            : new url('/mod/googlemeet/view.php', ['id' => $this->cm->id]);
        $attributes = ['class' => button::BODY_OUTLINE->classes()];
        if ($canjoin) {
            $attributes += [
                'target' => '_blank',
                'rel' => 'noopener',
            ];
        }

        return new overviewitem(
            name: get_string('actions'),
            value: $text,
            content: new action_link(
                url: $target,
                text: $text,
                attributes: $attributes,
            ),
            textalign: text_align::CENTER,
        );
    }

    /**
     * Builds the meeting time overview item.
     *
     * @return overviewitem
     */
    private function get_meeting_time_overview(): overviewitem {
        $timestart = (int) ($this->meeting->timestart ?? 0);

        return new overviewitem(
            name: get_string('overviewmeetingtime', 'mod_googlemeet'),
            value: $timestart > 0 ? $timestart : null,
            content: $timestart > 0 ? humandate::create_from_timestamp($timestart) : '-',
        );
    }

    /**
     * Builds the synchronization state overview item.
     *
     * @return overviewitem
     */
    private function get_sync_status_overview(): overviewitem {
        $state = (string) ($this->meeting->syncstatus ?? '');
        if (!sync_state::is_valid($state)) {
            $state = sync_state::FAILED;
        }

        return new overviewitem(
            name: get_string('overviewsyncstatus', 'mod_googlemeet'),
            value: $state,
            content: get_string('syncstatus' . $state, 'mod_googlemeet'),
        );
    }

    /**
     * Validates the join URI before exposing it as an external action.
     *
     * @param string $uri Candidate URI.
     * @return bool
     */
    private function is_valid_meet_uri(string $uri): bool {
        return (bool) preg_match(
            '/^https:\/\/meet\.google\.com\/[-a-zA-Z0-9@:%._+~#=]{3}'
                . '-[-a-zA-Z0-9@:%._+~#=]{4}-[-a-zA-Z0-9@:%._+~#=]{3}$/',
            $uri
        );
    }
}
