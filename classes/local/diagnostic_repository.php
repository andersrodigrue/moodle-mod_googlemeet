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
 * Queries and purges bounded privacy-safe operational diagnostics.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class diagnostic_repository {

    /** Diagnostic table. */
    private const TABLE = 'googlemeet_diagnostics';

    /** Maximum entries shown on one administrator page. */
    public const VIEW_LIMIT = 200;

    /** Maximum rows deleted in one scheduled run. */
    public const PURGE_LIMIT = 5000;

    /** Default retention in days. */
    public const DEFAULT_RETENTION_DAYS = 30;

    /**
     * Returns the newest filtered diagnostic rows.
     *
     * @param string $operation Optional operation.
     * @param string $outcome Optional outcome.
     * @param int $googlemeetid Optional activity ID.
     * @param int $limit Result limit.
     * @return \stdClass[]
     */
    public function latest(
        string $operation = '',
        string $outcome = '',
        int $googlemeetid = 0,
        int $limit = self::VIEW_LIMIT
    ): array {
        global $DB;

        if ($operation !== '' && !in_array($operation, diagnostic_recorder::operations(), true)) {
            throw new \coding_exception('Unsupported diagnostic operation filter.');
        }
        if ($outcome !== '' && !in_array($outcome, diagnostic_recorder::outcomes(), true)) {
            throw new \coding_exception('Unsupported diagnostic outcome filter.');
        }
        if ($googlemeetid < 0) {
            throw new \coding_exception('The diagnostic activity filter cannot be negative.');
        }
        if ($limit < 1 || $limit > self::VIEW_LIMIT) {
            throw new \coding_exception('The diagnostic view limit is invalid.');
        }

        $conditions = [];
        $params = ['modulename' => 'googlemeet'];
        if ($operation !== '') {
            $conditions[] = 'd.operation = :operation';
            $params['operation'] = $operation;
        }
        if ($outcome !== '') {
            $conditions[] = 'd.outcome = :outcome';
            $params['outcome'] = $outcome;
        }
        if ($googlemeetid > 0) {
            $conditions[] = 'd.googlemeetid = :googlemeetid';
            $params['googlemeetid'] = $googlemeetid;
        }
        $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);

        $records = $DB->get_records_sql(
            "SELECT d.*, g.name AS activityname, g.course AS courseid,
                    c.fullname AS coursename, cm.id AS cmid
               FROM {" . self::TABLE . "} d
               JOIN {googlemeet} g ON g.id = d.googlemeetid
               JOIN {course} c ON c.id = g.course
          LEFT JOIN {modules} m ON m.name = :modulename
          LEFT JOIN {course_modules} cm
                 ON cm.module = m.id
                AND cm.instance = g.id
                AND cm.course = g.course
                    {$where}
           ORDER BY d.timecreated DESC, d.id DESC",
            $params,
            0,
            $limit
        );

        return array_values($records);
    }

    /**
     * Counts outcomes since one timestamp.
     *
     * @param int $since Inclusive lower timestamp.
     * @return array<string, int>
     */
    public function outcome_counts_since(int $since): array {
        global $DB;

        if ($since < 0) {
            throw new \coding_exception('The diagnostic summary boundary cannot be negative.');
        }

        $records = $DB->get_records_sql(
            'SELECT outcome, COUNT(1) AS total
               FROM {' . self::TABLE . '}
              WHERE timecreated >= :since
           GROUP BY outcome',
            ['since' => $since]
        );
        $counts = array_fill_keys(diagnostic_recorder::outcomes(), 0);
        foreach ($records as $record) {
            if (isset($counts[$record->outcome])) {
                $counts[$record->outcome] = (int) $record->total;
            }
        }

        return $counts;
    }

    /**
     * Deletes at most one bounded batch older than the retention boundary.
     *
     * @param int $before Exclusive upper timestamp.
     * @param int $limit Maximum rows.
     * @return int Number of rows removed.
     */
    public function purge_before(int $before, int $limit = self::PURGE_LIMIT): int {
        global $DB;

        if ($before < 0) {
            throw new \coding_exception('The diagnostic purge boundary cannot be negative.');
        }
        if ($limit < 1 || $limit > self::PURGE_LIMIT) {
            throw new \coding_exception('The diagnostic purge limit is invalid.');
        }

        $records = $DB->get_records_select(
            self::TABLE,
            'timecreated < :before',
            ['before' => $before],
            'timecreated ASC, id ASC',
            'id',
            0,
            $limit
        );
        $ids = array_map('intval', array_keys($records));
        if ($ids === []) {
            return 0;
        }
        $DB->delete_records_list(self::TABLE, 'id', $ids);

        return count($ids);
    }

    /**
     * Returns the configured and bounded retention period.
     *
     * @return int Days.
     */
    public static function retention_days(): int {
        $days = (int) get_config('googlemeet', 'diagnosticretentiondays');
        if (!in_array($days, [7, 14, 30, 60, 90, 180], true)) {
            return self::DEFAULT_RETENTION_DAYS;
        }

        return $days;
    }
}
