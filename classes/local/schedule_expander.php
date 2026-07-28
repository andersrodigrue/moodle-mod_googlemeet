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
 * Expands the canonical meeting schedule into bounded local occurrences.
 *
 * The activity form persists a normalized weekly RFC 5545 rule. This class
 * intentionally supports that bounded subset, plus COUNT, RDATE and EXDATE
 * values already accepted at the Google Calendar boundary.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class schedule_expander {

    /** Maximum number of local occurrences produced for one activity. */
    private const MAX_OCCURRENCES = 500;

    /** Default expansion horizon for an imported recurrence without UNTIL or COUNT. */
    private const DEFAULT_HORIZON = 366 * DAYSECS;

    /** RFC weekday values mapped to ISO-8601 weekday numbers. */
    private const WEEKDAYS = [
        'MO' => 1,
        'TU' => 2,
        'WE' => 3,
        'TH' => 4,
        'FR' => 5,
        'SA' => 6,
        'SU' => 7,
    ];

    /**
     * Expands one activity from timestart, timeend, timezone and recurrence.
     *
     * @param \stdClass $meeting Activity record.
     * @return schedule_occurrence[]
     */
    public function expand(\stdClass $meeting): array {
        $timestart = (int) ($meeting->timestart ?? 0);
        $timeend = (int) ($meeting->timeend ?? 0);
        $first = new schedule_occurrence($timestart, $timeend);
        $recurrence = trim((string) ($meeting->recurrence ?? ''));
        if ($recurrence === '') {
            return [$first];
        }

        $timezone = $this->timezone((string) ($meeting->timezone ?? ''));
        [$rule, $rdates, $exdates] = $this->parse_recurrence($recurrence);
        if ($rule === null) {
            throw new \invalid_parameter_exception('A recurrence must contain one RRULE.');
        }

        $timestamps = $this->expand_weekly($timestart, $timezone, $rule);
        foreach ($rdates as $rdate) {
            $timestamps[$rdate] = true;
        }
        foreach ($exdates as $exdate) {
            unset($timestamps[$exdate]);
        }
        ksort($timestamps, SORT_NUMERIC);

        $duration = $first->duration();
        $occurrences = [];
        foreach (array_keys($timestamps) as $timestamp) {
            $occurrences[] = new schedule_occurrence((int) $timestamp, (int) $timestamp + $duration);
            if (count($occurrences) >= self::MAX_OCCURRENCES) {
                break;
            }
        }

        return $occurrences;
    }

    /**
     * Expands the supported weekly RRULE.
     *
     * @param int $timestart Canonical start.
     * @param \DateTimeZone $timezone Meeting timezone.
     * @param array<string, string> $rule Parsed RRULE fields.
     * @return array<int, bool>
     */
    private function expand_weekly(int $timestart, \DateTimeZone $timezone, array $rule): array {
        $unsupported = array_diff(array_keys($rule), [
            'FREQ',
            'INTERVAL',
            'UNTIL',
            'COUNT',
            'BYDAY',
            'WKST',
        ]);
        if ($unsupported !== []) {
            throw new \invalid_parameter_exception('The recurrence rule contains unsupported fields.');
        }
        if (strtoupper($rule['FREQ'] ?? '') !== 'WEEKLY') {
            throw new \invalid_parameter_exception('Only weekly meeting recurrence is supported.');
        }
        if (isset($rule['UNTIL'], $rule['COUNT'])) {
            throw new \invalid_parameter_exception('The recurrence cannot combine UNTIL and COUNT.');
        }
        if (isset($rule['WKST']) && strtoupper($rule['WKST']) !== 'MO') {
            throw new \invalid_parameter_exception('Only Monday-based recurrence weeks are supported.');
        }

        $interval = $this->positive_integer($rule['INTERVAL'] ?? '1', 'recurrence interval');
        if ($interval > 36) {
            throw new \invalid_parameter_exception('The recurrence interval cannot exceed 36 weeks.');
        }
        $count = isset($rule['COUNT'])
            ? $this->positive_integer($rule['COUNT'], 'recurrence count')
            : null;
        if ($count !== null && $count > self::MAX_OCCURRENCES) {
            throw new \invalid_parameter_exception('The recurrence count is too large.');
        }

        $until = isset($rule['UNTIL'])
            ? $this->parse_datetime($rule['UNTIL'])
            : $timestart + self::DEFAULT_HORIZON;
        if ($until < $timestart) {
            throw new \invalid_parameter_exception('The recurrence end precedes its start.');
        }

        $anchor = (new \DateTimeImmutable('@' . $timestart))->setTimezone($timezone);
        $defaultweekday = array_search((int) $anchor->format('N'), self::WEEKDAYS, true);
        $weekdays = $this->weekdays($rule['BYDAY'] ?? (string) $defaultweekday);
        $dayoffset = (int) $anchor->format('N') - 1;
        $weekstart = $anchor->modify('-' . $dayoffset . ' days')->setTime(
            (int) $anchor->format('H'),
            (int) $anchor->format('i'),
            (int) $anchor->format('s')
        );

        $timestamps = [$timestart => true];
        $generated = 1;
        $week = 0;
        while (count($timestamps) < self::MAX_OCCURRENCES) {
            $base = $weekstart->modify('+' . ($week * $interval) . ' weeks');
            if ($base->getTimestamp() > $until + WEEKSECS) {
                break;
            }
            foreach ($weekdays as $weekday) {
                $candidate = $base->modify('+' . ($weekday - 1) . ' days');
                $timestamp = $candidate->getTimestamp();
                if ($timestamp < $timestart || $timestamp > $until) {
                    continue;
                }
                if (!isset($timestamps[$timestamp])) {
                    if ($count !== null && $generated >= $count) {
                        break 2;
                    }
                    $timestamps[$timestamp] = true;
                    $generated++;
                }
            }
            $week++;
        }

        return $timestamps;
    }

    /**
     * Parses normalized recurrence lines.
     *
     * @param string $recurrence Stored recurrence.
     * @return array{0: array<string, string>|null, 1: int[], 2: int[]}
     */
    private function parse_recurrence(string $recurrence): array {
        $rule = null;
        $rdates = [];
        $exdates = [];
        $lines = preg_split('/\R+/', $recurrence);
        if ($lines === false) {
            throw new \invalid_parameter_exception('The meeting recurrence could not be parsed.');
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            [$property, $value] = array_pad(explode(':', $line, 2), 2, null);
            $property = strtoupper((string) explode(';', (string) $property, 2)[0]);
            if ($value === null || $value === '') {
                throw new \invalid_parameter_exception('The meeting recurrence contains an empty property.');
            }
            if ($property === 'RRULE') {
                if ($rule !== null) {
                    throw new \invalid_parameter_exception('Only one RRULE is supported.');
                }
                $rule = [];
                foreach (explode(';', $value) as $part) {
                    [$key, $partvalue] = array_pad(explode('=', $part, 2), 2, null);
                    if ($partvalue === null || $partvalue === '') {
                        throw new \invalid_parameter_exception('The recurrence rule contains an invalid field.');
                    }
                    $rule[strtoupper($key)] = strtoupper($partvalue);
                }
            } else if ($property === 'RDATE' || $property === 'EXDATE') {
                foreach (explode(',', $value) as $datevalue) {
                    $timestamp = $this->parse_datetime(trim($datevalue));
                    if ($property === 'RDATE') {
                        $rdates[] = $timestamp;
                    } else {
                        $exdates[] = $timestamp;
                    }
                }
            } else {
                throw new \invalid_parameter_exception('The recurrence property is not supported locally.');
            }
        }

        return [$rule, $rdates, $exdates];
    }

    /**
     * Parses a normalized UTC recurrence timestamp.
     *
     * @param string $value Timestamp in RFC 5545 basic UTC form.
     * @return int
     */
    private function parse_datetime(string $value): int {
        $date = \DateTimeImmutable::createFromFormat(
            '!Ymd\THis\Z',
            strtoupper($value),
            new \DateTimeZone('UTC')
        );
        $errors = \DateTimeImmutable::getLastErrors();
        if (
            $date === false ||
            (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        ) {
            throw new \invalid_parameter_exception('The recurrence timestamp is invalid.');
        }

        return $date->getTimestamp();
    }

    /**
     * Parses and validates BYDAY.
     *
     * @param string $value Comma-separated RFC weekday list.
     * @return int[]
     */
    private function weekdays(string $value): array {
        $weekdays = [];
        foreach (explode(',', strtoupper($value)) as $weekday) {
            if (!isset(self::WEEKDAYS[$weekday])) {
                throw new \invalid_parameter_exception('The recurrence contains an invalid weekday.');
            }
            $weekdays[self::WEEKDAYS[$weekday]] = self::WEEKDAYS[$weekday];
        }
        ksort($weekdays);

        return array_values($weekdays);
    }

    /**
     * Parses a positive integer recurrence value.
     *
     * @param string $value Submitted value.
     * @param string $label Diagnostic label.
     * @return int
     */
    private function positive_integer(string $value, string $label): int {
        if (!preg_match('/^[1-9][0-9]*$/', $value)) {
            throw new \invalid_parameter_exception('The ' . $label . ' must be a positive integer.');
        }

        return (int) $value;
    }

    /**
     * Validates the persisted IANA timezone.
     *
     * @param string $timezone Persisted timezone.
     * @return \DateTimeZone
     */
    private function timezone(string $timezone): \DateTimeZone {
        try {
            return new \DateTimeZone($timezone);
        } catch (\Exception) {
            throw new \invalid_parameter_exception('The meeting timezone is invalid.');
        }
    }
}
