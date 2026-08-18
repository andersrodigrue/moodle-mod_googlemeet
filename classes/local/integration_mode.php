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
 * Supported meeting integration modes.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class integration_mode {

    /** The teacher supplied the meeting link and no remote lifecycle is managed. */
    public const MANUAL = 'manual';

    /** The activity was linked by the legacy client and must be reconnected safely. */
    public const LEGACY = 'legacy';

    /** The modern integration owns and reconciles the remote lifecycle. */
    public const MANAGED = 'managed';

    /**
     * This class only exposes named values.
     */
    private function __construct() {
    }

    /**
     * Returns every supported integration mode.
     *
     * @return string[]
     */
    public static function all(): array {
        return [
            self::MANUAL,
            self::LEGACY,
            self::MANAGED,
        ];
    }

    /**
     * Checks whether a stored mode is supported.
     *
     * @param string $mode Integration mode.
     * @return bool
     */
    public static function is_valid(string $mode): bool {
        return in_array($mode, self::all(), true);
    }
}
