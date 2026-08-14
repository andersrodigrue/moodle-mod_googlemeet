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

namespace mod_googlemeet\task;

use mod_googlemeet\local\diagnostic_recorder;
use mod_googlemeet\local\sync_repository;
use mod_googlemeet\local\sync_state;

/**
 * Queues owner-scoped reconciliation for pending or stale managed meetings.
 *
 * This scheduled task never calls Google itself. It creates per-user ad hoc
 * tasks so Moodle restores the correct owner before touching OAuth tokens.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class reconcile_pending_meetings extends \core\task\scheduled_task {

    /** Wait before polling an asynchronous conference result. */
    private const PENDING_DELAY = 5 * MINSECS;

    /** Recover a worker state left behind after all ad hoc retries were lost. */
    private const STALE_SYNCING_DELAY = 30 * MINSECS;

    /** Maximum number of meetings queued by one scheduled run. */
    private const BATCH_LIMIT = 100;

    /**
     * Returns the administrator-facing task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('reconcilependingtask', 'mod_googlemeet');
    }

    /**
     * Queues reconciliation without accessing OAuth as the cron user.
     */
    public function execute(): void {
        $now = \core\di::get(\core\clock::class)->time();
        $candidates = (new sync_repository())->get_reconciliation_candidates(
            $now - self::PENDING_DELAY,
            $now - self::STALE_SYNCING_DELAY,
            self::BATCH_LIMIT
        );

        $queued = 0;
        $diagnostics = new diagnostic_recorder();
        foreach ($candidates as $meeting) {
            if (synchronise_meeting::enqueue((int) $meeting->id, (int) $meeting->owneruserid)) {
                $queued++;
                $diagnostics->record(
                    (int) $meeting->id,
                    diagnostic_recorder::OPERATION_MEETING_SYNC,
                    diagnostic_recorder::OUTCOME_QUEUED,
                    diagnostic_recorder::SOURCE_CRON,
                    $meeting->syncstatus === sync_state::PENDING
                        ? 'pending_reconciliation'
                        : 'stale_syncing_recovery'
                );
            }
        }

        mtrace(get_string('reconcilependingresult', 'mod_googlemeet', (object) [
            'found' => count($candidates),
            'queued' => $queued,
        ]));
    }
}
