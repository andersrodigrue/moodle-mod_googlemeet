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
 * Normalizes legacy activity form fields for the managed Calendar boundary.
 *
 * The browser submits the existing date selector and hour/minute controls.
 * This value mapper converts them to absolute timestamps and one canonical
 * recurrence rule before any asynchronous task reads the activity.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class meeting_form_data {

    /** @var array<string, string> Legacy form weekday keys mapped to RFC 5545 values. */
    private const WEEKDAYS = [
        'Sun' => 'SU',
        'Mon' => 'MO',
        'Tue' => 'TU',
        'Wed' => 'WE',
        'Thu' => 'TH',
        'Fri' => 'FR',
        'Sat' => 'SA',
    ];

    /**
     * Adds normalized Calendar fields to a copy of submitted form data.
     *
     * @param \stdClass $data Submitted activity data.
     * @param string $timezone IANA timezone selected for the meeting owner.
     * @return \stdClass Normalized copy.
     */
    public function normalize(\stdClass $data, string $timezone): \stdClass {
        $normalized = clone $data;
        $timezoneobject = $this->timezone($timezone);
        $eventdate = (int) ($data->eventdate ?? 0);
        if ($eventdate <= 0) {
            throw new \invalid_parameter_exception('A positive meeting date is required.');
        }

        $start = $this->timestamp(
            $eventdate,
            (int) ($data->starthour ?? -1),
            (int) ($data->startminute ?? -1),
            $timezoneobject
        );
        $end = $this->timestamp(
            $eventdate,
            (int) ($data->endhour ?? -1),
            (int) ($data->endminute ?? -1),
            $timezoneobject
        );
        if ($end <= $start) {
            throw new \invalid_parameter_exception('The meeting end time must be after its start time.');
        }

        $normalized->originalname = trim((string) ($data->name ?? ''));
        $normalized->timestart = $start;
        $normalized->timeend = $end;
        $normalized->timezone = $timezoneobject->getName();
        $normalized->calendarid = 'primary';
        $normalized->sendupdates = 'none';
        $normalized->recurrence = $this->recurrence($data, $timezoneobject);

        return $normalized;
    }

    /**
     * Builds one canonical weekly recurrence rule.
     *
     * @param \stdClass $data Submitted activity data.
     * @param \DateTimeZone $timezone Meeting timezone.
     * @return string|null
     */
    private function recurrence(\stdClass $data, \DateTimeZone $timezone): ?string {
        if (empty($data->addmultiply)) {
            return null;
        }

        $interval = (int) ($data->period ?? 0);
        if ($interval < 1 || $interval > 36) {
            throw new \invalid_parameter_exception('The recurrence interval must be between 1 and 36 weeks.');
        }

        $submitted = (array) ($data->days ?? []);
        $weekdays = [];
        foreach (self::WEEKDAYS as $formkey => $rulevalue) {
            if (!empty($submitted[$formkey])) {
                $weekdays[] = $rulevalue;
            }
        }
        if ($weekdays === []) {
            throw new \invalid_parameter_exception('At least one recurrence weekday is required.');
        }

        $eventenddate = (int) ($data->eventenddate ?? 0);
        if ($eventenddate < (int) $data->eventdate) {
            throw new \invalid_parameter_exception('The recurrence end date cannot precede the meeting date.');
        }
        $until = $this->timestamp($eventenddate, 23, 59, $timezone, 59);

        return 'RRULE:FREQ=WEEKLY;INTERVAL=' . $interval
            . ';UNTIL=' . gmdate('Ymd\THis\Z', $until)
            . ';BYDAY=' . implode(',', $weekdays);
    }

    /**
     * Converts the selected local calendar day and clock fields to a timestamp.
     *
     * @param int $date Selected date timestamp.
     * @param int $hour Hour from 0 to 23.
     * @param int $minute Minute from 0 to 59.
     * @param \DateTimeZone $timezone Meeting timezone.
     * @param int $second Second from 0 to 59.
     * @return int
     */
    private function timestamp(
        int $date,
        int $hour,
        int $minute,
        \DateTimeZone $timezone,
        int $second = 0
    ): int {
        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59 || $second < 0 || $second > 59) {
            throw new \invalid_parameter_exception('The meeting clock fields are invalid.');
        }

        $parts = usergetdate($date, $timezone->getName());

        return make_timestamp(
            (int) $parts['year'],
            (int) $parts['mon'],
            (int) $parts['mday'],
            $hour,
            $minute,
            $second,
            $timezone->getName()
        );
    }

    /**
     * Validates and returns the requested timezone.
     *
     * @param string $timezone IANA timezone.
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
