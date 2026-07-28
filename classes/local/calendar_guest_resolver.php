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

use mod_googlemeet\api\calendar_configuration_exception;
use mod_googlemeet\api\calendar_guest_limit_exception;

/**
 * Resolves active course participants eligible for Calendar invitations.
 *
 * Resolution is capability-based, excludes the organizer, ignores invalid
 * addresses, and refuses to produce a partial list above the hard safety cap.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class calendar_guest_resolver {

    /** Maximum number of Moodle-managed attendees in one Calendar event. */
    public const MAX_ATTENDEES = 200;

    /** Capability selecting participants eligible for Calendar invitations. */
    public const CAPABILITY = 'mod/googlemeet:receivecalendarinvite';

    /**
     * Resolves one stored activity.
     *
     * @param \stdClass $meeting Google Meet activity record.
     * @return calendar_guest_snapshot
     */
    public function resolve(\stdClass $meeting): calendar_guest_snapshot {
        $policy = (string) ($meeting->guestpolicy ?? calendar_guest_policy::NONE);
        if (!calendar_guest_policy::is_valid($policy)) {
            throw new calendar_configuration_exception('The stored Calendar guest policy is invalid.');
        }
        if ($policy === calendar_guest_policy::NONE) {
            return calendar_guest_snapshot::none();
        }

        $cm = get_coursemodule_from_instance(
            'googlemeet',
            (int) ($meeting->id ?? 0),
            (int) ($meeting->course ?? 0),
            false,
            MUST_EXIST
        );

        return $this->resolve_context(
            \context_module::instance((int) $cm->id),
            (int) ($meeting->owneruserid ?? 0)
        );
    }

    /**
     * Resolves eligible users in a course or module context.
     *
     * Course context is used while validating a new activity before a module
     * context exists. Runtime reconciliation always uses the module context so
     * local capability overrides are honored.
     *
     * @param \context $context Course or module context.
     * @param int $owneruserid Organizer to exclude.
     * @return calendar_guest_snapshot
     */
    public function resolve_context(\context $context, int $owneruserid = 0): calendar_guest_snapshot {
        $users = get_enrolled_users(
            $context,
            self::CAPABILITY,
            0,
            'u.id, u.email, u.deleted, u.suspended',
            'u.id ASC',
            0,
            0,
            true
        );

        $eligible = [];
        foreach ($users as $user) {
            $email = calendar_guest_snapshot::normalize_email((string) $user->email);
            if (
                (int) $user->id === $owneruserid
                || !empty($user->deleted)
                || !empty($user->suspended)
                || $email === ''
                || !validate_email($email)
            ) {
                continue;
            }
            $candidate = (object) [
                'id' => (int) $user->id,
                'email' => $email,
            ];
            if (!isset($eligible[$email]) || $candidate->id < $eligible[$email]->id) {
                $eligible[$email] = $candidate;
            }
            if (count($eligible) > self::MAX_ATTENDEES) {
                throw new calendar_guest_limit_exception('The Calendar guest safety limit was exceeded.');
            }
        }

        return calendar_guest_snapshot::course(array_values($eligible));
    }
}
