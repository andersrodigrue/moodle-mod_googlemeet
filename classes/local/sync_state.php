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
 * Meeting synchronization states and their allowed transitions.
 *
 * Repeating the current state is always accepted so workers can apply a
 * transition idempotently after a timeout.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class sync_state {

    /** The activity exists locally but no synchronization has been requested. */
    public const DRAFT = 'draft';

    /** A synchronization task is waiting to run. */
    public const QUEUED = 'queued';

    /** A worker is currently reconciling the remote event. */
    public const SYNCING = 'syncing';

    /** The event exists, but Google is still creating the conference. */
    public const PENDING = 'pending';

    /** The local activity and remote event are reconciled. */
    public const READY = 'ready';

    /** The last synchronization attempt failed. */
    public const FAILED = 'failed';

    /** Remote cancellation has been requested. */
    public const CANCELLING = 'cancelling';

    /** Remote cancellation has been reconciled. */
    public const CANCELLED = 'cancelled';

    /** The owner must reconnect an account before synchronization can continue. */
    public const DISCONNECTED = 'disconnected';

    /** @var array<string, string[]> Allowed state changes, excluding idempotent repeats. */
    private const TRANSITIONS = [
        self::DRAFT => [
            self::QUEUED,
            self::CANCELLED,
            self::DISCONNECTED,
        ],
        self::QUEUED => [
            self::SYNCING,
            self::FAILED,
            self::CANCELLING,
            self::DISCONNECTED,
        ],
        self::SYNCING => [
            self::PENDING,
            self::READY,
            self::FAILED,
            self::CANCELLING,
            self::DISCONNECTED,
        ],
        self::PENDING => [
            self::SYNCING,
            self::FAILED,
            self::CANCELLING,
            self::DISCONNECTED,
        ],
        self::READY => [
            self::QUEUED,
            self::CANCELLING,
            self::DISCONNECTED,
        ],
        self::FAILED => [
            self::QUEUED,
            self::CANCELLING,
            self::DISCONNECTED,
        ],
        self::CANCELLING => [
            self::CANCELLED,
            self::FAILED,
            self::DISCONNECTED,
        ],
        self::CANCELLED => [
            self::DRAFT,
        ],
        self::DISCONNECTED => [
            self::QUEUED,
            self::CANCELLED,
        ],
    ];

    /**
     * This class only exposes named states and transition rules.
     */
    private function __construct() {
    }

    /**
     * Returns every supported state.
     *
     * @return string[]
     */
    public static function all(): array {
        return array_keys(self::TRANSITIONS);
    }

    /**
     * Checks whether a stored state is supported.
     *
     * @param string $state Synchronization state.
     * @return bool
     */
    public static function is_valid(string $state): bool {
        return array_key_exists($state, self::TRANSITIONS);
    }

    /**
     * Checks whether a transition is allowed.
     *
     * @param string $from Current state.
     * @param string $to Requested state.
     * @return bool
     */
    public static function can_transition(string $from, string $to): bool {
        if (!self::is_valid($from) || !self::is_valid($to)) {
            return false;
        }

        if ($from === $to) {
            return true;
        }

        return in_array($to, self::TRANSITIONS[$from], true);
    }

    /**
     * Rejects an unknown or forbidden transition.
     *
     * @param string $from Current state.
     * @param string $to Requested state.
     */
    public static function assert_transition(string $from, string $to): void {
        if (!self::is_valid($from)) {
            throw new \coding_exception('Unknown Google Meet synchronization state: ' . $from);
        }

        if (!self::is_valid($to)) {
            throw new \coding_exception('Unknown Google Meet synchronization state: ' . $to);
        }

        if (!self::can_transition($from, $to)) {
            throw new \coding_exception(
                'Invalid Google Meet synchronization transition: ' . $from . ' -> ' . $to
            );
        }
    }

    /**
     * Checks whether no background synchronization is currently required.
     *
     * @param string $state Synchronization state.
     * @return bool
     */
    public static function is_settled(string $state): bool {
        return in_array($state, [
            self::READY,
            self::CANCELLED,
            self::DISCONNECTED,
        ], true);
    }
}
