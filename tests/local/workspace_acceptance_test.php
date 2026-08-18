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

use mod_googlemeet\api\calendar_authorization_exception;
use mod_googlemeet\api\calendar_transport_exception;
use mod_googlemeet\api\recording_response_exception;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests the closed Google Workspace acceptance and evidence contract.
 *
 * These tests never compose OAuth clients or call Google. Live credentials are
 * reserved for the explicitly dispatched dedicated-tenant workflow.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(workspace_acceptance::class)]
final class workspace_acceptance_test extends \advanced_testcase {

    /**
     * Only remote Calendar mutations require the exact acknowledgement.
     */
    public function test_mutation_confirmation_is_scenario_specific(): void {
        $this->assertSame(
            [
                'snapshot',
                'preflight',
                'synchronise',
                'poll',
                'cancel',
                'recordings',
            ],
            workspace_acceptance::scenarios()
        );
        $this->assertFalse(
            workspace_acceptance::requires_mutation_confirmation(
                workspace_acceptance::SCENARIO_PREFLIGHT
            )
        );
        $this->assertFalse(
            workspace_acceptance::requires_mutation_confirmation(
                workspace_acceptance::SCENARIO_RECORDINGS
            )
        );
        $this->assertTrue(
            workspace_acceptance::requires_mutation_confirmation(
                workspace_acceptance::SCENARIO_SYNCHRONISE
            )
        );
        $this->assertTrue(
            workspace_acceptance::requires_mutation_confirmation(
                workspace_acceptance::SCENARIO_CANCEL
            )
        );

        workspace_acceptance::assert_mutation_confirmation(
            workspace_acceptance::SCENARIO_SYNCHRONISE,
            workspace_acceptance::MUTATION_CONFIRMATION
        );
        $this->expectException(\coding_exception::class);
        workspace_acceptance::assert_mutation_confirmation(
            workspace_acceptance::SCENARIO_CANCEL,
            ''
        );
    }

    /**
     * Campaign cases accept only their documented scenarios.
     */
    public function test_campaign_case_is_closed_and_scenario_specific(): void {
        $this->assertSame(
            [
                'GW-01',
                'GW-02',
                'GW-03',
                'GW-04',
                'GW-05',
                'GW-06',
                'GW-07',
                'GW-08',
                'GW-09',
                'GW-10',
                'GW-11',
                'GW-12',
                'GW-13',
                'GW-14',
            ],
            array_keys(workspace_acceptance::case_scenarios())
        );
        $this->assertSame(
            ['synchronise', 'poll'],
            workspace_acceptance::case_scenarios()['GW-07']
        );
        workspace_acceptance::assert_campaign_case('rc1-202607', 'GW-07', 'poll');

        $this->expectException(\coding_exception::class);
        workspace_acceptance::assert_campaign_case('rc1-202607', 'GW-11', 'synchronise');
    }

    /**
     * Campaign identifiers cannot carry credential-related labels.
     */
    public function test_campaign_id_rejects_sensitive_or_unbounded_values(): void {
        $this->expectException(\coding_exception::class);
        workspace_acceptance::assert_campaign_case(
            'oauth-token-cycle',
            'GW-01',
            'preflight'
        );
    }

    /**
     * Evidence cannot claim a runtime outside the supported release matrix.
     */
    public function test_evidence_rejects_unsupported_runtime(): void {
        $this->expectException(\coding_exception::class);
        workspace_acceptance::evidence(
            'rc1-202607',
            'GW-14',
            workspace_acceptance::SCENARIO_SNAPSHOT,
            'passed',
            7,
            workspace_acceptance::SCHEDULE_ANY,
            ['local_snapshot' => 'passed'],
            $this->meeting(),
            0,
            2026072623,
            2026042000,
            '502',
            '8.2',
            1785369600
        );
    }

    /**
     * The runner accepts only a marked, managed, owner-scoped activity.
     */
    public function test_activity_boundary_requires_dedicated_owner_and_schedule(): void {
        $meeting = $this->meeting();

        workspace_acceptance::assert_activity(
            $meeting,
            42,
            workspace_acceptance::SCHEDULE_SINGLE
        );

        $meeting->recurrence = 'RRULE:FREQ=WEEKLY;COUNT=2';
        workspace_acceptance::assert_activity(
            $meeting,
            42,
            workspace_acceptance::SCHEDULE_RECURRING
        );

        $meeting->name = 'Ordinary course meeting';
        $this->expectException(\coding_exception::class);
        workspace_acceptance::assert_activity(
            $meeting,
            42,
            workspace_acceptance::SCHEDULE_RECURRING
        );
    }

    /**
     * A different user cannot use the fixture to exercise an owner's grant.
     */
    public function test_activity_boundary_rejects_wrong_owner_context(): void {
        $this->expectException(\coding_exception::class);
        workspace_acceptance::assert_activity(
            $this->meeting(),
            99,
            workspace_acceptance::SCHEDULE_SINGLE
        );
    }

    /**
     * Reports expose only presence, state and counts from sensitive records.
     */
    public function test_evidence_does_not_export_remote_or_personal_values(): void {
        $meeting = $this->meeting();
        $meeting->calendarid = 'teacher@example.com';
        $meeting->googleeventid = 'opaque-google-event-123';
        $meeting->googleeventhtmlurl = 'https://calendar.google.com/event?eid=secret';
        $meeting->requestid = 'request-secret';
        $meeting->meetinguri = 'https://meet.google.com/abc-defg-hij';
        $meeting->meetingcode = 'abc-defg-hij';
        $meeting->recordingowneruserid = 42;
        $meeting->recordingoauthissuerid = 19;
        $meeting->recordingsyncstatus = recording_sync_state::READY;

        $evidence = workspace_acceptance::evidence(
            'rc1-202607',
            'GW-01',
            workspace_acceptance::SCENARIO_PREFLIGHT,
            'passed',
            7,
            workspace_acceptance::SCHEDULE_SINGLE,
            [
                'dedicated_tenant' => 'passed',
                'activity_marker' => 'passed',
                'owner_context' => 'passed',
                'schedule_shape' => 'passed',
                'calendar_oauth' => 'passed',
                'writable_calendar' => 'passed',
            ],
            $meeting,
            2,
            2026072623,
            2026042000,
            '502',
            '8.3',
            1785369600,
            'abcdef0123456789abcdef0123456789abcdef01'
        );

        $json = json_encode($evidence, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('teacher@example.com', $json);
        $this->assertStringNotContainsString('opaque-google-event-123', $json);
        $this->assertStringNotContainsString('calendar.google.com', $json);
        $this->assertStringNotContainsString('request-secret', $json);
        $this->assertStringNotContainsString('meet.google.com', $json);
        $this->assertStringNotContainsString('abc-defg-hij', $json);
        $this->assertTrue($evidence['state']['calendar_event_present']);
        $this->assertTrue($evidence['state']['join_link_present']);
        $this->assertTrue($evidence['state']['recording_owner_bound']);
        $this->assertSame(2, $evidence['state']['recording_count']);
        $this->assertSame('skipped', $evidence['checks']['recording_discovery']);
        $this->assertSame('rc1-202607', $evidence['campaign_id']);
        $this->assertSame('GW-01', $evidence['case_id']);
        $this->assertSame('8.3', $evidence['php_runtime']);
        $this->assertSame('502', $evidence['moodle_branch']);

        workspace_acceptance::assert_sanitized_evidence($evidence);
    }

    /**
     * An invalid URI is represented only as an unavailable join link.
     */
    public function test_evidence_fails_closed_for_provider_uri(): void {
        $meeting = $this->meeting();
        $meeting->meetinguri = 'https://meet.google.com/abc-defg-hij?authuser=teacher@example.com';

        $evidence = workspace_acceptance::evidence(
            'rc1-202607',
            'GW-14',
            workspace_acceptance::SCENARIO_SNAPSHOT,
            'passed',
            7,
            workspace_acceptance::SCHEDULE_ANY,
            ['local_snapshot' => 'passed'],
            $meeting,
            0,
            2026072623,
            2026042000,
            '502',
            '8.4',
            1785369600
        );

        $this->assertFalse($evidence['state']['join_link_present']);
        $this->assertStringNotContainsString(
            'teacher@example.com',
            json_encode($evidence, JSON_THROW_ON_ERROR)
        );
    }

    /**
     * The final schema rejects additional fields before artifact upload.
     */
    public function test_evidence_schema_rejects_additional_fields(): void {
        $evidence = workspace_acceptance::evidence(
            'rc1-202607',
            'GW-14',
            workspace_acceptance::SCENARIO_SNAPSHOT,
            'passed',
            7,
            workspace_acceptance::SCHEDULE_ANY,
            ['local_snapshot' => 'passed'],
            $this->meeting(),
            0,
            2026072623,
            2026042000,
            '502',
            '8.3',
            1785369600
        );
        $evidence['meetinguri'] = 'https://meet.google.com/abc-defg-hij';

        $this->expectException(\coding_exception::class);
        workspace_acceptance::assert_sanitized_evidence($evidence);
    }

    /**
     * Provider exceptions become stable codes without reusing their messages.
     */
    public function test_provider_exceptions_map_to_closed_failure_codes(): void {
        $this->assertSame(
            'calendar_authorization_required',
            workspace_acceptance::exception_code(
                new calendar_authorization_exception('token=secret')
            )
        );
        $this->assertSame(
            'calendar_transport_retryable',
            workspace_acceptance::exception_code(
                new calendar_transport_exception('raw provider response')
            )
        );
        $this->assertSame(
            'recording_response_invalid',
            workspace_acceptance::exception_code(
                new recording_response_exception('https://meet.googleapis.com/private')
            )
        );
        $this->assertSame(
            'acceptance_internal_error',
            workspace_acceptance::exception_code(
                new \RuntimeException('teacher@example.com')
            )
        );
    }

    /**
     * Returns a complete local record with deliberately sensitive identifiers.
     *
     * @return \stdClass
     */
    private function meeting(): \stdClass {
        return (object) [
            'id' => 7,
            'name' => '[ACCEPTANCE] Single meeting',
            'integrationmode' => integration_mode::MANAGED,
            'owneruserid' => 42,
            'oauthissuerid' => 17,
            'calendarid' => 'primary',
            'timestart' => 1785369600,
            'timeend' => 1785373200,
            'recurrence' => null,
            'syncstatus' => sync_state::READY,
            'conferencestatus' => 'success',
            'googleeventid' => null,
            'meetinguri' => null,
            'guestpolicy' => calendar_guest_policy::NONE,
            'guestcount' => 0,
            'recordingowneruserid' => null,
            'recordingoauthissuerid' => null,
            'recordingsyncstatus' => recording_sync_state::DISCONNECTED,
        ];
    }
}
