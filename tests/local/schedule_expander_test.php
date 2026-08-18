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

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for canonical local schedule expansion.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(schedule_expander::class)]
#[CoversClass(schedule_occurrence::class)]
final class schedule_expander_test extends \advanced_testcase {

    /**
     * A meeting without recurrence produces exactly one occurrence.
     */
    public function test_expands_single_meeting(): void {
        $start = make_timestamp(2026, 8, 3, 10, 15, 0, 'America/Sao_Paulo');
        $occurrences = (new schedule_expander())->expand((object) [
            'timestart' => $start,
            'timeend' => $start + 90 * MINSECS,
            'timezone' => 'America/Sao_Paulo',
            'recurrence' => null,
        ]);

        $this->assertCount(1, $occurrences);
        $this->assertSame($start, $occurrences[0]->timestart);
        $this->assertSame(90 * MINSECS, $occurrences[0]->duration());
        $this->assertSame(hash('sha256', 'v1:' . $start), $occurrences[0]->key());
    }

    /**
     * Weekly interval, BYDAY and inclusive UNTIL are expanded deterministically.
     */
    public function test_expands_bounded_weekly_recurrence(): void {
        $start = make_timestamp(2026, 8, 3, 10, 15, 0, 'America/Sao_Paulo');
        $until = make_timestamp(2026, 8, 31, 23, 59, 59, 'America/Sao_Paulo');
        $occurrences = (new schedule_expander())->expand((object) [
            'timestart' => $start,
            'timeend' => $start + HOURSECS,
            'timezone' => 'America/Sao_Paulo',
            'recurrence' => 'RRULE:FREQ=WEEKLY;INTERVAL=2;UNTIL='
                . gmdate('Ymd\THis\Z', $until)
                . ';BYDAY=MO,WE',
        ]);

        $this->assertSame([
            '2026-08-03 10:15',
            '2026-08-05 10:15',
            '2026-08-17 10:15',
            '2026-08-19 10:15',
            '2026-08-31 10:15',
        ], array_map(
            fn(schedule_occurrence $occurrence): string => $this->local_time(
                $occurrence->timestart,
                'America/Sao_Paulo'
            ),
            $occurrences
        ));
    }

    /**
     * Local wall-clock time remains stable across a daylight-saving change.
     */
    public function test_preserves_local_time_across_dst(): void {
        $start = make_timestamp(2026, 10, 19, 10, 0, 0, 'Europe/Berlin');
        $until = make_timestamp(2026, 11, 3, 23, 59, 59, 'Europe/Berlin');
        $occurrences = (new schedule_expander())->expand((object) [
            'timestart' => $start,
            'timeend' => $start + HOURSECS,
            'timezone' => 'Europe/Berlin',
            'recurrence' => 'RRULE:FREQ=WEEKLY;INTERVAL=1;UNTIL='
                . gmdate('Ymd\THis\Z', $until)
                . ';BYDAY=MO',
        ]);

        $this->assertSame([
            '2026-10-19 10:00',
            '2026-10-26 10:00',
            '2026-11-02 10:00',
        ], array_map(
            fn(schedule_occurrence $occurrence): string => $this->local_time(
                $occurrence->timestart,
                'Europe/Berlin'
            ),
            $occurrences
        ));
        $this->assertSame(7 * DAYSECS + HOURSECS, $occurrences[1]->timestart - $occurrences[0]->timestart);
        $this->assertSame(7 * DAYSECS, $occurrences[2]->timestart - $occurrences[1]->timestart);
    }

    /**
     * Imported RDATE and EXDATE lines are applied after rule expansion.
     */
    public function test_applies_inclusion_and_exclusion_dates(): void {
        $start = make_timestamp(2026, 8, 3, 10, 0, 0, 'UTC');
        $occurrences = (new schedule_expander())->expand((object) [
            'timestart' => $start,
            'timeend' => $start + HOURSECS,
            'timezone' => 'UTC',
            'recurrence' => "RRULE:FREQ=WEEKLY;COUNT=3;BYDAY=MO\n"
                . "RDATE:20260807T100000Z\n"
                . "EXDATE:20260810T100000Z",
        ]);

        $this->assertSame([
            '2026-08-03 10:00',
            '2026-08-07 10:00',
            '2026-08-17 10:00',
        ], array_map(
            fn(schedule_occurrence $occurrence): string => $this->local_time(
                $occurrence->timestart,
                'UTC'
            ),
            $occurrences
        ));
    }

    /**
     * Unsupported frequencies fail before partial local events are stored.
     */
    public function test_rejects_unsupported_frequency(): void {
        $start = make_timestamp(2026, 8, 3, 10, 0, 0, 'UTC');
        $this->expectException(\invalid_parameter_exception::class);

        (new schedule_expander())->expand((object) [
            'timestart' => $start,
            'timeend' => $start + HOURSECS,
            'timezone' => 'UTC',
            'recurrence' => 'RRULE:FREQ=DAILY;COUNT=3',
        ]);
    }

    /**
     * Formats a timestamp in the requested timezone.
     *
     * @param int $timestamp Timestamp.
     * @param string $timezone IANA timezone.
     * @return string
     */
    private function local_time(int $timestamp, string $timezone): string {
        return (new \DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new \DateTimeZone($timezone))
            ->format('Y-m-d H:i');
    }
}
