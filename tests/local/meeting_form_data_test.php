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
     * Legacy date and clock controls become timezone-aware absolute values.
     */
    public function test_normalizes_single_meeting_in_owner_timezone(): void {
        $data = $this->form_data();

        $result = (new meeting_form_data())->normalize($data, 'America/Sao_Paulo');

        $this->assertSame('Writing workshop', $result->originalname);
        $this->assertSame('2026-08-03T10:15:00-03:00', $this->rfc3339($result->timestart));
        $this->assertSame('2026-08-03T11:45:00-03:00', $this->rfc3339($result->timeend));
        $this->assertSame('America/Sao_Paulo', $result->timezone);
        $this->assertSame('primary', $result->calendarid);
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

        $result = (new meeting_form_data())->normalize($data, 'America/Sao_Paulo');

        $this->assertSame(
            'RRULE:FREQ=WEEKLY;INTERVAL=2;UNTIL=20260901T025959Z;BYDAY=MO,WE,FR',
            $result->recurrence
        );
    }

    /**
     * Equal start and end times are rejected at the persistence boundary.
     */
    public function test_rejects_non_positive_duration(): void {
        $data = $this->form_data();
        $data->endhour = $data->starthour;
        $data->endminute = $data->startminute;

        $this->expectException(\invalid_parameter_exception::class);
        (new meeting_form_data())->normalize($data, 'America/Sao_Paulo');
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
        (new meeting_form_data())->normalize($data, 'America/Sao_Paulo');
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
