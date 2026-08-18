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

use mod_googlemeet\local\upgrade\compatibility_contract;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for managed form normalization.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(meeting_form_data::class)]
final class meeting_form_data_test extends \advanced_testcase {

    /**
     * Current controls persist canonical timestamps without legacy duplication.
     */
    public function test_normalizes_canonical_schedule_controls(): void {
        $start = make_timestamp(2026, 8, 3, 10, 15, 0, 'America/Sao_Paulo');
        $data = (object) [
            'name' => ' Writing workshop ',
            'timestart' => $start,
            'timeend' => $start + 90 * MINSECS,
            'timezone' => 'America/Sao_Paulo',
            'recurrenceenabled' => 0,
            // Forged deprecated fields must not cross the persistence boundary.
            'eventdate' => 1,
            'starthour' => 1,
            'days' => ['Sun' => 1],
        ];

        $result = (new meeting_form_data())->normalize($data, 'UTC');

        $this->assertSame($start, $result->timestart);
        $this->assertSame($start + 90 * MINSECS, $result->timeend);
        $this->assertSame('America/Sao_Paulo', $result->timezone);
        $this->assertSame('Writing workshop', $result->originalname);
        $this->assertNull($result->recurrence);
        foreach (compatibility_contract::LEGACY_SCHEDULE_FIELDS as $field) {
            $this->assertFalse(property_exists($result, $field));
        }
    }

    /**
     * Current weekly controls produce the editable canonical RRULE subset.
     */
    public function test_normalizes_canonical_weekly_recurrence(): void {
        $start = make_timestamp(2026, 8, 3, 10, 15, 0, 'America/Sao_Paulo');
        $until = make_timestamp(2026, 8, 31, 10, 15, 0, 'America/Sao_Paulo');
        $data = (object) [
            'name' => 'Writing workshop',
            'timestart' => $start,
            'timeend' => $start + HOURSECS,
            'timezone' => 'America/Sao_Paulo',
            'recurrenceenabled' => 1,
            'recurrenceinterval' => 2,
            'recurrenceweekdays' => ['FR', 'MO', 'WE', 'MO'],
            'recurrenceuntil' => $until,
        ];

        $result = (new meeting_form_data())->normalize($data, 'UTC');

        $this->assertSame(
            'RRULE:FREQ=WEEKLY;INTERVAL=2;UNTIL=20260831T131500Z;BYDAY=MO,WE,FR;WKST=MO',
            $result->recurrence
        );
    }

    /**
     * Legacy date and clock controls become timezone-aware absolute values.
     */
    public function test_normalizes_single_meeting_in_owner_timezone(): void {
        $data = $this->form_data();

        $result = (new meeting_form_data())->normalize_legacy($data, 'America/Sao_Paulo');

        $this->assertSame('Writing workshop', $result->originalname);
        $this->assertSame('2026-08-03T10:15:00-03:00', $this->rfc3339($result->timestart));
        $this->assertSame('2026-08-03T11:45:00-03:00', $this->rfc3339($result->timeend));
        $this->assertSame('America/Sao_Paulo', $result->timezone);
        $this->assertFalse(property_exists($result, 'calendarid'));
        $this->assertSame('none', $result->sendupdates);
        $this->assertNull($result->recurrence);
    }

    /**
     * Recurrence is canonical regardless of submitted checkbox order.
     */
    public function test_normalizes_weekly_recurrence(): void {
        $data = $this->form_data();
        $data->addmultiply = 1;
        $data->period = 2;
        $data->days = [
            'Fri' => 1,
            'Mon' => 1,
            'Wed' => 1,
        ];
        $data->eventenddate = make_timestamp(2026, 8, 31, 0, 0, 0, 'America/Sao_Paulo');

        $result = (new meeting_form_data())->normalize_legacy($data, 'America/Sao_Paulo');

        $this->assertSame(
            'RRULE:FREQ=WEEKLY;INTERVAL=2;UNTIL=20260901T025959Z;BYDAY=MO,WE,FR',
            $result->recurrence
        );
    }

    /**
     * Editable recurrence is decoded back to modern form controls.
     */
    public function test_prepares_canonical_form_defaults(): void {
        $start = make_timestamp(2026, 8, 3, 10, 15, 0, 'America/Sao_Paulo');
        $until = make_timestamp(2026, 8, 31, 10, 15, 0, 'America/Sao_Paulo');

        $defaults = (new meeting_form_data())->prepare_form_defaults([
            'timestart' => $start,
            'timeend' => $start + HOURSECS,
            'timezone' => 'America/Sao_Paulo',
            'recurrence' => 'RRULE:FREQ=WEEKLY;INTERVAL=2;UNTIL='
                . gmdate('Ymd\THis\Z', $until)
                . ';BYDAY=MO,WE,FR;WKST=MO',
        ], 'UTC');

        $this->assertSame($start, $defaults['timestart']);
        $this->assertSame($start + HOURSECS, $defaults['timeend']);
        $this->assertSame('America/Sao_Paulo', $defaults['timezone']);
        $this->assertSame(1, $defaults['recurrenceenabled']);
        $this->assertSame(2, $defaults['recurrenceinterval']);
        $this->assertSame(['MO', 'WE', 'FR'], $defaults['recurrenceweekdays']);
        $this->assertSame($until, $defaults['recurrenceuntil']);
        $this->assertSame(0, $defaults['recurrencecompatibilitylocked']);
    }

    /**
     * Imported enumerated recurrence cannot be overwritten by weekly controls.
     */
    public function test_preserves_unsupported_existing_recurrence(): void {
        $start = make_timestamp(2026, 8, 3, 10, 15, 0, 'America/Sao_Paulo');
        $recurrence = "RRULE:FREQ=WEEKLY;COUNT=1\nRDATE:20260810T131500Z,20260824T131500Z";
        $existing = (object) [
            'timestart' => $start,
            'timeend' => $start + HOURSECS,
            'timezone' => 'America/Sao_Paulo',
            'recurrence' => $recurrence,
        ];
        $submitted = (object) [
            'name' => 'Updated title',
            'timestart' => $start + DAYSECS,
            'timeend' => $start + DAYSECS + 2 * HOURSECS,
            'timezone' => 'UTC',
            'recurrenceenabled' => 0,
        ];

        $mapper = new meeting_form_data();
        $defaults = $mapper->prepare_form_defaults((array) $existing, 'UTC');
        $result = $mapper->normalize($submitted, 'UTC', $existing);

        $this->assertFalse($mapper->is_recurrence_editable($recurrence));
        $this->assertSame(1, $defaults['recurrencecompatibilitylocked']);
        $this->assertSame($start, $result->timestart);
        $this->assertSame($start + HOURSECS, $result->timeend);
        $this->assertSame('America/Sao_Paulo', $result->timezone);
        $this->assertSame($recurrence, $result->recurrence);
        $this->assertSame('Updated title', $result->originalname);
    }

    /**
     * Minute-precision controls preserve imported seconds on unchanged values.
     */
    public function test_preserves_existing_sub_minute_precision(): void {
        $minute = make_timestamp(2026, 8, 3, 10, 15, 0, 'America/Sao_Paulo');
        $untilminute = make_timestamp(2026, 8, 31, 10, 15, 0, 'America/Sao_Paulo');
        $existing = (object) [
            'timestart' => $minute + 27,
            'timeend' => $minute + HOURSECS + 42,
            'timezone' => 'America/Sao_Paulo',
            'recurrence' => 'RRULE:FREQ=WEEKLY;INTERVAL=1;UNTIL='
                . gmdate('Ymd\THis\Z', $untilminute + 59)
                . ';BYDAY=MO;WKST=MO',
        ];
        $submitted = (object) [
            'name' => 'Updated title',
            'timestart' => $minute,
            'timeend' => $minute + HOURSECS,
            'timezone' => 'America/Sao_Paulo',
            'recurrenceenabled' => 1,
            'recurrenceinterval' => 1,
            'recurrenceweekdays' => ['MO'],
            'recurrenceuntil' => $untilminute,
        ];

        $result = (new meeting_form_data())->normalize($submitted, 'UTC', $existing);

        $this->assertSame($existing->timestart, $result->timestart);
        $this->assertSame($existing->timeend, $result->timeend);
        $this->assertSame($existing->recurrence, $result->recurrence);
    }

    /**
     * Legacy-only records acquire defaults without exposing legacy controls.
     */
    public function test_prepares_legacy_record_for_canonical_form(): void {
        $data = (array) $this->form_data();

        $defaults = (new meeting_form_data())->prepare_form_defaults(
            $data,
            'America/Sao_Paulo'
        );

        $this->assertSame('2026-08-03T10:15:00-03:00', $this->rfc3339($defaults['timestart']));
        $this->assertSame('2026-08-03T11:45:00-03:00', $this->rfc3339($defaults['timeend']));
        $this->assertSame('America/Sao_Paulo', $defaults['timezone']);
        $this->assertSame(0, $defaults['recurrenceenabled']);
        $this->assertSame(['MO'], $defaults['recurrenceweekdays']);
    }

    /**
     * Equal start and end times are rejected at the persistence boundary.
     */
    public function test_rejects_non_positive_duration(): void {
        $data = $this->form_data();
        $data->endhour = $data->starthour;
        $data->endminute = $data->startminute;

        $this->expectException(\invalid_parameter_exception::class);
        (new meeting_form_data())->normalize_legacy($data, 'America/Sao_Paulo');
    }

    /**
     * Recurrence cannot be stored without at least one weekday.
     */
    public function test_rejects_recurrence_without_weekday(): void {
        $data = $this->form_data();
        $data->addmultiply = 1;
        $data->period = 1;
        $data->days = [];
        $data->eventenddate = $data->eventdate;

        $this->expectException(\invalid_parameter_exception::class);
        (new meeting_form_data())->normalize_legacy($data, 'America/Sao_Paulo');
    }

    /**
     * The persistence boundary enforces the form's one-year recurrence limit.
     */
    public function test_rejects_recurrence_longer_than_one_year(): void {
        $data = $this->form_data();
        $data->addmultiply = 1;
        $data->period = 1;
        $data->days = ['Mon' => 1];
        $data->eventenddate = $data->eventdate + YEARSECS + DAYSECS;

        $this->expectException(\invalid_parameter_exception::class);
        (new meeting_form_data())->normalize_legacy($data, 'America/Sao_Paulo');
    }

    /**
     * Canonical schedule rejects an invalid timezone.
     */
    public function test_rejects_invalid_canonical_timezone(): void {
        $data = (object) [
            'name' => 'Writing workshop',
            'timestart' => 1785762900,
            'timeend' => 1785766500,
            'timezone' => 'Invalid/Timezone',
            'recurrenceenabled' => 0,
        ];

        $this->expectException(\invalid_parameter_exception::class);
        (new meeting_form_data())->normalize($data, 'UTC');
    }

    /**
     * Deprecated fields cannot replace an invalid current form schedule.
     */
    public function test_canonical_boundary_does_not_fall_back_to_forged_legacy_fields(): void {
        $data = $this->form_data();
        $data->timestart = 0;
        $data->timeend = 0;
        $data->timezone = 'America/Sao_Paulo';
        $data->recurrenceenabled = 0;

        $this->expectException(\invalid_parameter_exception::class);
        (new meeting_form_data())->normalize($data, 'America/Sao_Paulo');
    }

    /**
     * Returns representative form data.
     *
     * @return \stdClass
     */
    private function form_data(): \stdClass {
        return (object) [
            'name' => ' Writing workshop ',
            'eventdate' => make_timestamp(2026, 8, 3, 0, 0, 0, 'America/Sao_Paulo'),
            'starthour' => 10,
            'startminute' => 15,
            'endhour' => 11,
            'endminute' => 45,
            'addmultiply' => 0,
        ];
    }

    /**
     * Formats a timestamp in the test meeting timezone.
     *
     * @param int $timestamp Timestamp.
     * @return string
     */
    private function rfc3339(int $timestamp): string {
        return (new \DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new \DateTimeZone('America/Sao_Paulo'))
            ->format(\DateTimeInterface::RFC3339);
    }
}
