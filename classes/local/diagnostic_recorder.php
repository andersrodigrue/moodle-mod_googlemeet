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

use mod_googlemeet\event\operation_recorded;

/**
 * Records privacy-safe operational transitions for administrators.
 *
 * Diagnostic rows deliberately exclude user IDs, OAuth identifiers, email
 * addresses, remote response bodies and free-form messages. Callers can only
 * select bounded operation, outcome and source values plus a stable code.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class diagnostic_recorder {

    /** Calendar meeting synchronization. */
    public const OPERATION_MEETING_SYNC = 'meeting_sync';

    /** Remote Calendar cancellation. */
    public const OPERATION_MEETING_CANCEL = 'meeting_cancel';

    /** Course enrolment to Calendar guest reconciliation. */
    public const OPERATION_GUEST_RECONCILE = 'guest_reconcile';

    /** Google Meet recording discovery. */
    public const OPERATION_RECORDING_DISCOVERY = 'recording_discovery';

    /** A task was accepted by Moodle's queue. */
    public const OUTCOME_QUEUED = 'queued';

    /** A worker began one bounded attempt. */
    public const OUTCOME_STARTED = 'started';

    /** The operation completed successfully. */
    public const OUTCOME_SUCCEEDED = 'succeeded';

    /** The operation remains asynchronously pending. */
    public const OUTCOME_PENDING = 'pending';

    /** The operation ended in a safe permanent failure. */
    public const OUTCOME_FAILED = 'failed';

    /** The operation requires an explicit owner action. */
    public const OUTCOME_BLOCKED = 'blocked';

    /** A transient failure escaped for Moodle to retry. */
    public const OUTCOME_RETRYING = 'retrying';

    /** Requested by an authenticated user action or form submission. */
    public const SOURCE_USER = 'user';

    /** Produced by an owner-scoped ad hoc task. */
    public const SOURCE_ADHOC = 'adhoc';

    /** Produced by a scheduled task that does not access OAuth. */
    public const SOURCE_CRON = 'cron';

    /** Stable replacement for a rejected diagnostic code. */
    private const INVALID_CODE = 'invalid_diagnostic_code';

    /** @var \core\clock Moodle clock. */
    private \core\clock $clock;

    /**
     * @param \core\clock|null $clock Moodle clock.
     */
    public function __construct(?\core\clock $clock = null) {
        $this->clock = $clock ?? \core\di::get(\core\clock::class);
    }

    /**
     * Returns supported operation identifiers.
     *
     * @return string[]
     */
    public static function operations(): array {
        return [
            self::OPERATION_MEETING_SYNC,
            self::OPERATION_MEETING_CANCEL,
            self::OPERATION_GUEST_RECONCILE,
            self::OPERATION_RECORDING_DISCOVERY,
        ];
    }

    /**
     * Returns supported outcome identifiers.
     *
     * @return string[]
     */
    public static function outcomes(): array {
        return [
            self::OUTCOME_QUEUED,
            self::OUTCOME_STARTED,
            self::OUTCOME_SUCCEEDED,
            self::OUTCOME_PENDING,
            self::OUTCOME_FAILED,
            self::OUTCOME_BLOCKED,
            self::OUTCOME_RETRYING,
        ];
    }

    /**
     * Returns supported source identifiers.
     *
     * @return string[]
     */
    public static function sources(): array {
        return [
            self::SOURCE_USER,
            self::SOURCE_ADHOC,
            self::SOURCE_CRON,
        ];
    }

    /**
     * Persists one transition and emits its structured Moodle event.
     *
     * @param int $googlemeetid Activity instance ID.
     * @param string $operation Bounded operation.
     * @param string $outcome Bounded outcome.
     * @param string $source Bounded execution source.
     * @param string|null $code Optional stable non-secret code.
     * @return int Diagnostic row ID.
     */
    public function record(
        int $googlemeetid,
        string $operation,
        string $outcome,
        string $source,
        ?string $code = null
    ): int {
        global $DB;

        if ($googlemeetid <= 0) {
            throw new \coding_exception('A positive activity ID is required for diagnostics.');
        }
        $this->assert_supported($operation, self::operations(), 'operation');
        $this->assert_supported($outcome, self::outcomes(), 'outcome');
        $this->assert_supported($source, self::sources(), 'source');
        $code = $this->normalise_code($code);

        $meeting = $DB->get_record('googlemeet', ['id' => $googlemeetid], '*', MUST_EXIST);
        $diagnosticid = (int) $DB->insert_record('googlemeet_diagnostics', (object) [
            'googlemeetid' => $googlemeetid,
            'operation' => $operation,
            'outcome' => $outcome,
            'source' => $source,
            'diagnosticcode' => $code,
            'timecreated' => $this->clock->time(),
        ]);

        $cm = get_coursemodule_from_instance(
            'googlemeet',
            $googlemeetid,
            (int) $meeting->course,
            false,
            IGNORE_MISSING
        );
        $context = $cm
            ? \context_module::instance((int) $cm->id)
            : \context_course::instance((int) $meeting->course);
        $event = operation_recorded::create([
            'objectid' => $googlemeetid,
            'context' => $context,
            'other' => [
                'operation' => $operation,
                'outcome' => $outcome,
                'source' => $source,
                'diagnosticcode' => $code,
            ],
        ]);
        $event->add_record_snapshot('googlemeet', $meeting);
        $event->trigger();

        return $diagnosticid;
    }

    /**
     * Validates a value against a closed set.
     *
     * @param string $value Candidate.
     * @param string[] $supported Supported values.
     * @param string $field Field label for the exception.
     */
    private function assert_supported(string $value, array $supported, string $field): void {
        if (!in_array($value, $supported, true)) {
            throw new \coding_exception('Unsupported diagnostic ' . $field . '.');
        }
    }

    /**
     * Accepts only a compact stable code and never attempts to clean free text.
     *
     * @param string|null $code Candidate code.
     * @return string|null
     */
    private function normalise_code(?string $code): ?string {
        if ($code === null || $code === '') {
            return null;
        }
        if (!preg_match('/^[a-z0-9][a-z0-9_.-]{0,99}$/', $code)) {
            return self::INVALID_CODE;
        }

        return $code;
    }
}
