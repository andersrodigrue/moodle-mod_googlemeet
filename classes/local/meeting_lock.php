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
 * Coordinates exclusive work for one Google Meet activity.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class meeting_lock {

    /** Component-prefixed lock namespace. */
    public const TYPE = 'mod_googlemeet_meeting_sync';

    /** Default number of seconds to wait for the activity lock. */
    private const DEFAULT_TIMEOUT = 5;

    /** @var \core\lock\lock_factory Configured lock factory. */
    private \core\lock\lock_factory $factory;

    /** @var int Number of seconds to wait for a lock. */
    private int $timeout;

    /**
     * @param \core\lock\lock_factory|null $factory Lock factory, or null to use site configuration.
     * @param int $timeout Number of seconds to wait for a lock.
     */
    public function __construct(
        ?\core\lock\lock_factory $factory = null,
        int $timeout = self::DEFAULT_TIMEOUT
    ) {
        if ($timeout < 0) {
            throw new \coding_exception('The Google Meet lock timeout cannot be negative.');
        }

        $this->factory = $factory ?? \core\lock\lock_config::get_lock_factory(self::TYPE);
        $this->timeout = $timeout;
    }

    /**
     * Runs a callback while holding the lock for one activity.
     *
     * @param int $googlemeetid Activity instance ID.
     * @param callable $callback Work that requires exclusive access.
     * @return mixed Callback result.
     */
    public function with_lock(int $googlemeetid, callable $callback): mixed {
        if ($googlemeetid <= 0) {
            throw new \coding_exception('A positive Google Meet activity ID is required.');
        }

        $lock = $this->factory->get_lock(self::resource_name($googlemeetid), $this->timeout);
        if (!$lock) {
            throw new \moodle_exception('synclocktimeout', 'mod_googlemeet', '', $googlemeetid);
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    /**
     * Returns the stable resource name used for one activity.
     *
     * @param int $googlemeetid Activity instance ID.
     * @return string
     */
    public static function resource_name(int $googlemeetid): string {
        return 'meeting:' . $googlemeetid;
    }
}
