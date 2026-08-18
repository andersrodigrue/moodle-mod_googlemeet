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
 * Tests for the canonical server-side meeting access policy.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(meeting_access_policy::class)]
#[CoversClass(meeting_access_result::class)]
final class meeting_access_policy_test extends \advanced_testcase {

    /** Exact Google Meet URI used only in allowed test decisions. */
    private const MEET_URI = 'https://meet.google.com/abc-defg-hij';

    /**
     * The participant window includes its opening boundary.
     */
    public function test_exact_opening_boundary_is_available(): void {
        $start = 1785405600;
        $policy = $this->policy_at($start - (15 * MINSECS));

        $result = $policy->evaluate($this->meeting($start));

        $this->assertSame(meeting_access_result::AVAILABLE, $result->state);
        $this->assertTrue($result->can_join());
        $this->assertSame(self::MEET_URI, $result->join_uri());
    }

    /**
     * A decision before the opening boundary discloses no provider URI.
     */
    public function test_before_opening_boundary_is_scheduled_without_uri(): void {
        $start = 1785405600;
        $opening = $start - (15 * MINSECS);
        $policy = $this->policy_at($opening - 1);

        $result = $policy->evaluate($this->meeting($start));

        $this->assertSame(meeting_access_result::SCHEDULED, $result->state);
        $this->assertSame($opening, $result->nextavailable);
        $this->assertFalse($result->can_join());
        $this->assertNull($result->join_uri());
    }

    /**
     * The participant window excludes its closing boundary.
     */
    public function test_exact_closing_boundary_is_closed_without_uri(): void {
        $start = 1785405600;
        $end = $start + HOURSECS;
        $policy = $this->policy_at($end + (60 * MINSECS));

        $result = $policy->evaluate($this->meeting($start));

        $this->assertSame(meeting_access_result::CLOSED, $result->state);
        $this->assertFalse($result->can_join());
        $this->assertNull($result->join_uri());
    }

    /**
     * Meeting managers bypass the time window but not lifecycle or URI validation.
     */
    public function test_manager_override_preserves_readiness_and_uri_checks(): void {
        $start = 1785405600;
        $policy = $this->policy_at($start - DAYSECS);

        $allowed = $policy->evaluate($this->meeting($start), true);
        $this->assertSame(meeting_access_result::MANAGER_OVERRIDE, $allowed->state);
        $this->assertSame(self::MEET_URI, $allowed->join_uri());

        $notready = $this->meeting($start);
        $notready->syncstatus = sync_state::PENDING;
        $result = $policy->evaluate($notready, true);
        $this->assertSame(meeting_access_result::NOT_READY, $result->state);
        $this->assertNull($result->join_uri());

        $invalid = $this->meeting($start);
        $invalid->meetinguri = 'https://example.com/not-a-meeting';
        $result = $policy->evaluate($invalid, true);
        $this->assertSame(meeting_access_result::INVALID_LINK, $result->state);
        $this->assertNull($result->join_uri());
    }

    /**
     * Recurring meetings use each expanded occurrence rather than only the first.
     */
    public function test_recurrence_selects_next_and_current_windows(): void {
        $start = gmmktime(10, 0, 0, 8, 6, 2026);
        $secondstart = $start + WEEKSECS;
        $meeting = $this->meeting($start);
        $meeting->recurrence = 'RRULE:FREQ=WEEKLY;COUNT=2;BYDAY=TH';

        $between = $this->policy_at($start + (2 * DAYSECS))->evaluate($meeting);
        $this->assertSame(meeting_access_result::SCHEDULED, $between->state);
        $this->assertSame($secondstart - (15 * MINSECS), $between->nextavailable);
        $this->assertNull($between->join_uri());

        $current = $this->policy_at($secondstart)->evaluate($meeting);
        $this->assertSame(meeting_access_result::AVAILABLE, $current->state);
        $this->assertSame(self::MEET_URI, $current->join_uri());
    }

    /**
     * Invalid recurrence fails closed for participants.
     */
    public function test_invalid_recurrence_fails_closed_for_participant(): void {
        $meeting = $this->meeting(1785405600);
        $meeting->recurrence = 'RRULE:FREQ=DAILY;COUNT=2';
        $policy = $this->policy_at($meeting->timestart);

        $result = $policy->evaluate($meeting);

        $this->assertSame(meeting_access_result::INVALID_SCHEDULE, $result->state);
        $this->assertTrue($result->is_error());
        $this->assertNull($result->join_uri());

        $managerresult = $policy->evaluate($meeting, true);
        $this->assertSame(meeting_access_result::MANAGER_OVERRIDE, $managerresult->state);
        $this->assertSame(self::MEET_URI, $managerresult->join_uri());
    }

    /**
     * Explicit window overrides accept only the bounded administration options.
     */
    public function test_invalid_explicit_window_is_rejected(): void {
        $clock = $this->createMock(\core\clock::class);

        $this->expectException(\invalid_parameter_exception::class);
        new meeting_access_policy($clock, null, 14, 60);
    }

    /**
     * Corrupt persisted settings safely fall back to the documented defaults.
     */
    public function test_invalid_stored_window_falls_back_to_default(): void {
        $this->resetAfterTest();
        set_config('joinbeforeminutes', '60minutes', 'googlemeet');
        set_config('joinafterminutes', '120minutes', 'googlemeet');
        $start = 1785405600;

        $beforedefaultopening = $this->policy_at(
            $start - (15 * MINSECS) - 1,
            null,
            null
        );
        $result = $beforedefaultopening->evaluate($this->meeting($start));
        $this->assertSame(meeting_access_result::SCHEDULED, $result->state);
        $this->assertNull($result->join_uri());

        $atdefaultopening = $this->policy_at($start - (15 * MINSECS), null, null);
        $this->assertTrue($atdefaultopening->evaluate($this->meeting($start))->can_join());

        $atdefaultclosing = $this->policy_at($start + HOURSECS + (60 * MINSECS), null, null);
        $result = $atdefaultclosing->evaluate($this->meeting($start));
        $this->assertSame(meeting_access_result::CLOSED, $result->state);
        $this->assertNull($result->join_uri());
    }

    /**
     * Result objects reject URI/state combinations that could leak a link.
     */
    public function test_result_rejects_uri_for_unavailable_state(): void {
        $this->expectException(\coding_exception::class);
        new meeting_access_result(meeting_access_result::CLOSED, self::MEET_URI);
    }

    /**
     * Builds the policy around a deterministic Moodle clock.
     *
     * @param int $now Server timestamp.
     * @param int|null $beforeminutes Explicit early-entry window.
     * @param int|null $afterminutes Explicit post-meeting window.
     * @return meeting_access_policy
     */
    private function policy_at(
        int $now,
        ?int $beforeminutes = 15,
        ?int $afterminutes = 60
    ): meeting_access_policy {
        $clock = $this->createMock(\core\clock::class);
        $clock->method('time')->willReturn($now);

        return new meeting_access_policy($clock, null, $beforeminutes, $afterminutes);
    }

    /**
     * Builds one deterministic ready activity.
     *
     * @param int $start Occurrence start.
     * @return \stdClass
     */
    private function meeting(int $start): \stdClass {
        return (object) [
            'syncstatus' => sync_state::READY,
            'meetinguri' => self::MEET_URI,
            'url' => self::MEET_URI,
            'timestart' => $start,
            'timeend' => $start + HOURSECS,
            'timezone' => 'UTC',
            'recurrence' => null,
        ];
    }
}
