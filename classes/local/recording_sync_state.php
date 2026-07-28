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
 * State machine for recording discovery.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class recording_sync_state {

    public const DISCONNECTED = 'disconnected';
    public const QUEUED = 'queued';
    public const SYNCING = 'syncing';
    public const READY = 'ready';
    public const FAILED = 'failed';

    /** @var array<string, string[]> Allowed next states. */
    private const TRANSITIONS = [
        self::DISCONNECTED => [self::DISCONNECTED, self::QUEUED],
        self::QUEUED => [self::QUEUED, self::SYNCING, self::DISCONNECTED, self::FAILED],
        self::SYNCING => [self::SYNCING, self::READY, self::DISCONNECTED, self::FAILED, self::QUEUED],
        self::READY => [self::READY, self::QUEUED, self::DISCONNECTED],
        self::FAILED => [self::FAILED, self::QUEUED, self::DISCONNECTED],
    ];

    /**
     * Rejects an invalid recording state transition.
     *
     * @param string $current Current state.
     * @param string $next Requested state.
     */
    public static function assert_transition(string $current, string $next): void {
        if (!isset(self::TRANSITIONS[$current]) || !in_array($next, self::TRANSITIONS[$current], true)) {
            throw new \coding_exception('Invalid recording synchronization transition: ' . $current . ' -> ' . $next);
        }
    }
}
