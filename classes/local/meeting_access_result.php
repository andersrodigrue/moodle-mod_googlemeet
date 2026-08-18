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
 * Immutable server-side decision about exposing a meeting join action.
 *
 * Unavailable decisions never retain the Google Meet URI. This makes it harder
 * for a presentation consumer to leak the stored link accidentally.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final readonly class meeting_access_result {

    /** The current occurrence is inside its configured access window. */
    public const AVAILABLE = 'available';

    /** A meeting manager is explicitly exempt from the participant window. */
    public const MANAGER_OVERRIDE = 'manager_override';

    /** The meeting lifecycle has not produced a usable conference yet. */
    public const NOT_READY = 'not_ready';

    /** The stored join URI is absent or not an exact Google Meet URI. */
    public const INVALID_LINK = 'invalid_link';

    /** A later occurrence has an access window that has not opened yet. */
    public const SCHEDULED = 'scheduled';

    /** Every configured occurrence access window has closed. */
    public const CLOSED = 'closed';

    /** The canonical schedule could not be evaluated safely. */
    public const INVALID_SCHEDULE = 'invalid_schedule';

    /** @var string[] Closed set of supported presentation states. */
    private const STATES = [
        self::AVAILABLE,
        self::MANAGER_OVERRIDE,
        self::NOT_READY,
        self::INVALID_LINK,
        self::SCHEDULED,
        self::CLOSED,
        self::INVALID_SCHEDULE,
    ];

    /**
     * @param string $state Closed decision state.
     * @param string|null $joinuri Valid URI retained only for allowed decisions.
     * @param int|null $nextavailable Timestamp at which the next window opens.
     */
    public function __construct(
        public string $state,
        private ?string $joinuri = null,
        public ?int $nextavailable = null
    ) {
        if (!in_array($state, self::STATES, true)) {
            throw new \coding_exception('The meeting access state is invalid.');
        }

        $allowed = in_array($state, [self::AVAILABLE, self::MANAGER_OVERRIDE], true);
        if ($allowed !== ($joinuri !== null)) {
            throw new \coding_exception('The meeting access URI does not match the decision state.');
        }
        if (($state === self::SCHEDULED) !== ($nextavailable !== null)) {
            throw new \coding_exception('The next meeting access time does not match the decision state.');
        }
    }

    /**
     * Whether the caller may proceed through the server-side join gateway.
     *
     * @return bool
     */
    public function can_join(): bool {
        return $this->joinuri !== null;
    }

    /**
     * Returns the validated Meet URI only for an allowed decision.
     *
     * @return string|null
     */
    public function join_uri(): ?string {
        return $this->joinuri;
    }

    /**
     * Returns a localized explanation for an unavailable action.
     *
     * @return string Empty for an available action.
     */
    public function message(): string {
        return match ($this->state) {
            self::AVAILABLE, self::MANAGER_OVERRIDE => '',
            self::NOT_READY => get_string('meetinglinknotready', 'mod_googlemeet'),
            self::INVALID_LINK => get_string('invalidstoredurl', 'mod_googlemeet'),
            self::SCHEDULED => get_string(
                'meetingaccessscheduled',
                'mod_googlemeet',
                userdate((int) $this->nextavailable)
            ),
            self::CLOSED => get_string('meetingaccessclosed', 'mod_googlemeet'),
            self::INVALID_SCHEDULE => get_string('meetingaccessinvalidschedule', 'mod_googlemeet'),
        };
    }

    /**
     * Whether the unavailable state represents invalid persisted data.
     *
     * @return bool
     */
    public function is_error(): bool {
        return in_array($this->state, [self::INVALID_LINK, self::INVALID_SCHEDULE], true);
    }
}
