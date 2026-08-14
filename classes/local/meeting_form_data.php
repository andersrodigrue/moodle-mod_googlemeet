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
 * Maps the activity form to the canonical schedule.
 *
 * New forms submit absolute start/end timestamps, an IANA timezone and
 * recurrence controls. The legacy mapper remains intentionally available for
 * old backups and records which have not acquired canonical timestamps yet.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class meeting_form_data {

    /** @var array<string, string> Legacy form weekday keys mapped to RFC 5545 values. */
    private const LEGACY_WEEKDAYS = [
        'Sun' => 'SU',
        'Mon' => 'MO',
        'Tue' => 'TU',
        'Wed' => 'WE',
        'Thu' => 'TH',
        'Fri' => 'FR',
        'Sat' => 'SA',
    ];

    /** @var array<string, int> RFC 5545 weekdays mapped to ISO-8601 weekday numbers. */
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
     * Adds canonical Calendar fields to a copy of submitted form data.
     *
     * @param \stdClass $data Submitted activity data.
     * @param string $defaulttimezone Fallback IANA timezone for old data.
     * @param \stdClass|null $existing Existing activity during update.
     * @return \stdClass Normalized copy without submitted legacy schedule fields.
     */
    public function normalize(
        \stdClass $data,
        string $defaulttimezone,
        ?\stdClass $existing = null
    ): \stdClass {
        $normalized = clone $data;

        // Imported enumerated series cannot be represented by the weekly editor.
        // Preserve their complete schedule until a dedicated exception editor is
        // available instead of silently dropping RDATE, EXDATE or COUNT values.
        if (
            $existing !== null
            && trim((string) ($existing->recurrence ?? '')) !== ''
            && !$this->is_recurrence_editable((string) $existing->recurrence)
        ) {
            $normalized->timestart = (int) $existing->timestart;
            $normalized->timeend = (int) $existing->timeend;
            $normalized->timezone = $this->timezone(
                (string) ($existing->timezone ?? $defaulttimezone)
            )->getName();
            $normalized->recurrence = (string) $existing->recurrence;
        } else {
            $timezone = $this->timezone((string) ($data->timezone ?? $defaulttimezone));
            $normalized->timestart = $this->preserve_existing_seconds(
                (int) ($data->timestart ?? 0),
                (int) ($existing->timestart ?? 0)
            );
            $normalized->timeend = $this->preserve_existing_seconds(
                (int) ($data->timeend ?? 0),
                (int) ($existing->timeend ?? 0)
            );
            $normalized->timezone = $timezone->getName();
            $this->validate_duration($normalized->timestart, $normalized->timeend);
            $normalized->recurrence = $this->recurrence_from_controls(
                $data,
                $normalized->timestart,
                $timezone,
                $existing
            );
        }

        return $this->finalize($normalized, $data);
    }

    /**
     * Converts deprecated scheduling fields to the canonical representation.
     *
     * This method is deliberately separate from current form normalization so
     * forged legacy fields cannot become an alternate persistence path.
     *
     * @param \stdClass $data Legacy activity or backup data.
     * @param string $defaulttimezone Fallback IANA timezone.
     * @return \stdClass Canonical copy without deprecated schedule fields.
     */
    public function normalize_legacy(\stdClass $data, string $defaulttimezone): \stdClass {
        $normalized = clone $data;
        $this->normalize_legacy_schedule($normalized, $data, $defaulttimezone);

        return $this->finalize($normalized, $data);
    }

    /**
     * Converts stored canonical values to weekly form controls.
     *
     * Legacy fields are consulted only when canonical timestamps are missing.
     * Unsupported imported recurrence remains marked as locked and is preserved
     * server-side by {@see self::normalize()}.
     *
     * @param array $defaultvalues Activity defaults supplied by Moodle.
     * @param string $defaulttimezone Fallback IANA timezone.
     * @return array Prepared defaults.
     */
    public function prepare_form_defaults(array $defaultvalues, string $defaulttimezone): array {
        $data = (object) $defaultvalues;
        if (!$this->has_valid_canonical_schedule($data) && (int) ($data->eventdate ?? 0) > 0) {
            $legacy = $this->normalize_legacy($data, $defaulttimezone);
            $data->timestart = $legacy->timestart;
            $data->timeend = $legacy->timeend;
            $data->timezone = $legacy->timezone;
            $data->recurrence = $legacy->recurrence;
        }

        $timezone = $this->timezone((string) ($data->timezone ?? $defaulttimezone));
        $timestart = (int) ($data->timestart ?? 0);
        $defaultvalues['timestart'] = $timestart;
        $defaultvalues['timeend'] = (int) ($data->timeend ?? 0);
        $defaultvalues['timezone'] = $timezone->getName();
        $defaultvalues['recurrenceenabled'] = 0;
        $defaultvalues['recurrenceinterval'] = 1;
        $defaultvalues['recurrenceweekdays'] = $timestart > 0
            ? [$this->weekday($timestart, $timezone)]
            : [];
        $defaultvalues['recurrenceuntil'] = $timestart > 0
            ? $this->default_recurrence_until($timestart, $timezone)
            : 0;
        $defaultvalues['recurrencecompatibilitylocked'] = 0;

        $recurrence = trim((string) ($data->recurrence ?? ''));
        if ($recurrence === '') {
            return $defaultvalues;
        }

        $parsed = $this->parse_editable_recurrence($recurrence);
        if ($parsed === null) {
            $defaultvalues['recurrenceenabled'] = 1;
            $defaultvalues['recurrencecompatibilitylocked'] = 1;
            return $defaultvalues;
        }

        $defaultvalues['recurrenceenabled'] = 1;
        $defaultvalues['recurrenceinterval'] = $parsed['interval'];
        $defaultvalues['recurrenceweekdays'] = $parsed['weekdays'];
        $defaultvalues['recurrenceuntil'] = $parsed['until'];

        return $defaultvalues;
    }

    /**
     * Whether a recurrence can be edited without losing information.
     *
     * @param string|null $recurrence Persisted canonical recurrence.
     * @return bool
     */
    public function is_recurrence_editable(?string $recurrence): bool {
        $recurrence = trim((string) $recurrence);
        return $recurrence === '' || $this->parse_editable_recurrence($recurrence) !== null;
    }

    /**
     * Normalizes the deprecated date, clock and recurrence fields.
     *
     * @param \stdClass $normalized Record being normalized.
     * @param \stdClass $data Legacy source data.
     * @param string $defaulttimezone Fallback IANA timezone.
     */
    private function normalize_legacy_schedule(
        \stdClass $normalized,
        \stdClass $data,
        string $defaulttimezone
    ): void {
        $timezone = $this->timezone($defaulttimezone);
        $eventdate = (int) ($data->eventdate ?? 0);
        if ($eventdate <= 0) {
            throw new \invalid_parameter_exception('A positive meeting date is required.');
        }

        $normalized->timestart = $this->legacy_timestamp(
            $eventdate,
            (int) ($data->starthour ?? -1),
            (int) ($data->startminute ?? -1),
            $timezone
        );
        $normalized->timeend = $this->legacy_timestamp(
            $eventdate,
            (int) ($data->endhour ?? -1),
            (int) ($data->endminute ?? -1),
            $timezone
        );
        $this->validate_duration($normalized->timestart, $normalized->timeend);
        $normalized->timezone = $timezone->getName();
        $normalized->recurrence = $this->recurrence_from_legacy_fields($data, $timezone);
    }

    /**
     * Applies shared server-owned fields and strips deprecated input.
     *
     * @param \stdClass $normalized Normalized data.
     * @param \stdClass $source Submitted source data.
     * @return \stdClass Final normalized copy.
     */
    private function finalize(\stdClass $normalized, \stdClass $source): \stdClass {
        $normalized->originalname = trim((string) ($source->name ?? ''));
        $normalized->sendupdates = 'none';
        foreach (upgrade\compatibility_contract::LEGACY_SCHEDULE_FIELDS as $field) {
            unset($normalized->{$field});
        }

        return $normalized;
    }

    /**
     * Whether stored timestamps form a valid canonical schedule.
     *
     * @param \stdClass $data Stored activity data.
     * @return bool
     */
    private function has_valid_canonical_schedule(\stdClass $data): bool {
        return (int) ($data->timestart ?? 0) > 0
            && (int) ($data->timeend ?? 0) > (int) $data->timestart;
    }

    /**
     * Builds the canonical rule from current form controls.
     *
     * @param \stdClass $data Submitted form data.
     * @param int $timestart Canonical meeting start.
     * @param \DateTimeZone $timezone Meeting timezone.
     * @param \stdClass|null $existing Existing activity during update.
     * @return string|null
     */
    private function recurrence_from_controls(
        \stdClass $data,
        int $timestart,
        \DateTimeZone $timezone,
        ?\stdClass $existing = null
    ): ?string {
        if (empty($data->recurrenceenabled)) {
            return null;
        }

        $interval = (int) ($data->recurrenceinterval ?? 0);
        if ($interval < 1 || $interval > 36) {
            throw new \invalid_parameter_exception('The recurrence interval must be between 1 and 36 weeks.');
        }

        $weekdays = $this->normalize_weekdays((array) ($data->recurrenceweekdays ?? []));
        if ($weekdays === []) {
            throw new \invalid_parameter_exception('At least one recurrence weekday is required.');
        }

        $until = (int) ($data->recurrenceuntil ?? 0);
        if ($existing !== null) {
            $parsed = $this->parse_editable_recurrence((string) ($existing->recurrence ?? ''));
            if ($parsed !== null) {
                $until = $this->preserve_existing_seconds($until, $parsed['until']);
            }
        }
        $this->validate_recurrence_until($timestart, $until, $timezone);

        return 'RRULE:FREQ=WEEKLY;INTERVAL=' . $interval
            . ';UNTIL=' . gmdate('Ymd\THis\Z', $until)
            . ';BYDAY=' . implode(',', $weekdays)
            . ';WKST=MO';
    }

    /**
     * Builds a canonical weekly rule from deprecated fields.
     *
     * @param \stdClass $data Legacy form data.
     * @param \DateTimeZone $timezone Meeting timezone.
     * @return string|null
     */
    private function recurrence_from_legacy_fields(
        \stdClass $data,
        \DateTimeZone $timezone
    ): ?string {
        if (empty($data->addmultiply)) {
            return null;
        }

        $interval = (int) ($data->period ?? 0);
        if ($interval < 1 || $interval > 36) {
            throw new \invalid_parameter_exception('The recurrence interval must be between 1 and 36 weeks.');
        }

        $submitted = (array) ($data->days ?? []);
        $weekdays = [];
        foreach (self::LEGACY_WEEKDAYS as $formkey => $rulevalue) {
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
        $until = $this->legacy_timestamp($eventenddate, 23, 59, $timezone, 59);
        $this->validate_recurrence_until(
            $this->legacy_timestamp(
                (int) $data->eventdate,
                (int) ($data->starthour ?? -1),
                (int) ($data->startminute ?? -1),
                $timezone
            ),
            $until,
            $timezone
        );

        return 'RRULE:FREQ=WEEKLY;INTERVAL=' . $interval
            . ';UNTIL=' . gmdate('Ymd\THis\Z', $until)
            . ';BYDAY=' . implode(',', $weekdays);
    }

    /**
     * Parses the weekly subset represented by the form.
     *
     * @param string $recurrence Canonical recurrence.
     * @return array{interval: int, weekdays: string[], until: int}|null
     */
    private function parse_editable_recurrence(string $recurrence): ?array {
        if (str_contains($recurrence, "\n") || str_contains($recurrence, "\r")) {
            return null;
        }
        if (!str_starts_with($recurrence, 'RRULE:')) {
            return null;
        }

        $fields = [];
        foreach (explode(';', substr($recurrence, 6)) as $part) {
            [$name, $value] = array_pad(explode('=', $part, 2), 2, null);
            if ($name === '' || $value === null || isset($fields[$name])) {
                return null;
            }
            $fields[$name] = $value;
        }

        $allowed = ['FREQ', 'INTERVAL', 'UNTIL', 'BYDAY', 'WKST'];
        if (array_diff(array_keys($fields), $allowed) !== []) {
            return null;
        }
        if (
            ($fields['FREQ'] ?? null) !== 'WEEKLY'
            || !isset($fields['UNTIL'], $fields['BYDAY'])
            || (isset($fields['WKST']) && $fields['WKST'] !== 'MO')
        ) {
            return null;
        }

        $intervalvalue = $fields['INTERVAL'] ?? '1';
        if (!ctype_digit($intervalvalue)) {
            return null;
        }
        $interval = (int) $intervalvalue;
        if ($interval < 1 || $interval > 36) {
            return null;
        }

        try {
            $weekdays = $this->normalize_weekdays(explode(',', $fields['BYDAY']));
        } catch (\invalid_parameter_exception) {
            return null;
        }
        if ($weekdays === []) {
            return null;
        }

        $until = \DateTimeImmutable::createFromFormat(
            '!Ymd\THis\Z',
            $fields['UNTIL'],
            new \DateTimeZone('UTC')
        );
        $errors = \DateTimeImmutable::getLastErrors();
        if (
            $until === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $until->format('Ymd\THis\Z') !== $fields['UNTIL']
        ) {
            return null;
        }

        return [
            'interval' => $interval,
            'weekdays' => $weekdays,
            'until' => $until->getTimestamp(),
        ];
    }

    /**
     * Validates duration.
     *
     * @param int $timestart Meeting start.
     * @param int $timeend Meeting end.
     */
    private function validate_duration(int $timestart, int $timeend): void {
        if ($timestart <= 0) {
            throw new \invalid_parameter_exception('A positive meeting start is required.');
        }
        if ($timeend <= $timestart) {
            throw new \invalid_parameter_exception('The meeting end time must be after its start time.');
        }
    }

    /**
     * Keeps sub-minute precision when a form edit did not change the minute.
     *
     * Moodle date/time selectors do not expose seconds. Imported schedules may
     * contain them, so an unrelated form edit must not silently truncate them.
     *
     * @param int $submitted Submitted minute-precision timestamp.
     * @param int $existing Existing canonical timestamp.
     * @return int
     */
    private function preserve_existing_seconds(int $submitted, int $existing): int {
        if (
            $submitted > 0
            && $existing > 0
            && intdiv($submitted, MINSECS) === intdiv($existing, MINSECS)
        ) {
            return $existing;
        }

        return $submitted;
    }

    /**
     * Validates a bounded recurrence end.
     *
     * @param int $timestart Meeting start.
     * @param int $until Inclusive recurrence boundary.
     * @param \DateTimeZone $timezone Meeting timezone.
     */
    private function validate_recurrence_until(
        int $timestart,
        int $until,
        \DateTimeZone $timezone
    ): void {
        if ($until < $timestart) {
            throw new \invalid_parameter_exception('The recurrence end cannot precede the meeting start.');
        }

        $startday = (new \DateTimeImmutable('@' . $timestart))
            ->setTimezone($timezone)
            ->setTime(0, 0);
        $untilday = (new \DateTimeImmutable('@' . $until))
            ->setTimezone($timezone)
            ->setTime(0, 0);
        if ($untilday > $startday->modify('+1 year')) {
            throw new \invalid_parameter_exception('The recurrence cannot exceed one year.');
        }
    }

    /**
     * Normalizes RFC weekday values in calendar order.
     *
     * @param array $submitted Submitted weekday values.
     * @return string[]
     */
    private function normalize_weekdays(array $submitted): array {
        $selected = [];
        foreach ($submitted as $weekday) {
            $weekday = strtoupper((string) $weekday);
            if (!isset(self::WEEKDAYS[$weekday])) {
                throw new \invalid_parameter_exception('The recurrence contains an invalid weekday.');
            }
            $selected[$weekday] = true;
        }

        return array_values(array_filter(
            array_keys(self::WEEKDAYS),
            static fn(string $weekday): bool => isset($selected[$weekday])
        ));
    }

    /**
     * Converts a deprecated local date and clock to one absolute timestamp.
     *
     * @param int $date Selected date timestamp.
     * @param int $hour Hour from 0 to 23.
     * @param int $minute Minute from 0 to 59.
     * @param \DateTimeZone $timezone Meeting timezone.
     * @param int $second Second from 0 to 59.
     * @return int
     */
    private function legacy_timestamp(
        int $date,
        int $hour,
        int $minute,
        \DateTimeZone $timezone,
        int $second = 0
    ): int {
        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59 || $second < 0 || $second > 59) {
            throw new \invalid_parameter_exception('The meeting clock fields are invalid.');
        }

        $localdate = (new \DateTimeImmutable('@' . $date))->setTimezone($timezone);
        $timestamp = $localdate->setTime($hour, $minute, $second);
        if (
            (int) $timestamp->format('G') !== $hour
            || (int) $timestamp->format('i') !== $minute
            || (int) $timestamp->format('s') !== $second
        ) {
            throw new \invalid_parameter_exception('The meeting time does not exist in the selected timezone.');
        }

        return $timestamp->getTimestamp();
    }

    /**
     * Returns the RFC weekday for a timestamp.
     *
     * @param int $timestamp Timestamp.
     * @param \DateTimeZone $timezone Meeting timezone.
     * @return string
     */
    private function weekday(int $timestamp, \DateTimeZone $timezone): string {
        $number = (int) (new \DateTimeImmutable('@' . $timestamp))
            ->setTimezone($timezone)
            ->format('N');

        return (string) array_search($number, self::WEEKDAYS, true);
    }

    /**
     * Provides a four-week default boundary in local wall-clock time.
     *
     * @param int $timestart Meeting start.
     * @param \DateTimeZone $timezone Meeting timezone.
     * @return int
     */
    private function default_recurrence_until(int $timestart, \DateTimeZone $timezone): int {
        return (new \DateTimeImmutable('@' . $timestart))
            ->setTimezone($timezone)
            ->modify('+4 weeks')
            ->getTimestamp();
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
