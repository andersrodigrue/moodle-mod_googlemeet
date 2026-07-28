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
 * Supported Google Calendar guest policies.
 *
 * The notification parameter is derived from the policy and is never accepted
 * as a separate teacher-controlled value. Course invitations require
 * sendUpdates=all so Calendar can propagate attendee copies reliably.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class calendar_guest_policy {

    /** Do not manage Calendar attendees. */
    public const NONE = 'none';

    /** Reconcile active course participants with Calendar attendees. */
    public const COURSE = 'course';

    /**
     * This class only exposes named policies and validation helpers.
     */
    private function __construct() {
    }

    /**
     * Returns all supported policies.
     *
     * @return string[]
     */
    public static function all(): array {
        return [
            self::NONE,
            self::COURSE,
        ];
    }

    /**
     * Whether a policy is supported.
     *
     * @param string $policy Guest policy.
     * @return bool
     */
    public static function is_valid(string $policy): bool {
        return in_array($policy, self::all(), true);
    }

    /**
     * Rejects an unsupported policy.
     *
     * @param string $policy Guest policy.
     */
    public static function assert_valid(string $policy): void {
        if (!self::is_valid($policy)) {
            throw new \invalid_parameter_exception('The Google Calendar guest policy is invalid.');
        }
    }

    /**
     * Returns the Calendar notification policy required for the guest policy.
     *
     * @param string $policy Guest policy.
     * @return string
     */
    public static function send_updates(string $policy): string {
        self::assert_valid($policy);

        return $policy === self::COURSE ? 'all' : 'none';
    }
}
