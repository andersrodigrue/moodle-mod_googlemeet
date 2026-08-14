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

use mod_googlemeet\api\calendar_api_exception;
use mod_googlemeet\api\calendar_authorization_exception;
use mod_googlemeet\api\calendar_configuration_exception;
use mod_googlemeet\api\calendar_response_exception;
use mod_googlemeet\api\calendar_transport_exception;
use mod_googlemeet\api\recording_api_exception;
use mod_googlemeet\api\recording_authorization_exception;
use mod_googlemeet\api\recording_response_exception;
use mod_googlemeet\api\recording_transport_exception;

/**
 * Closed contract for opt-in Google Workspace acceptance evidence.
 *
 * This class never calls Google. The CLI-only acceptance fixture uses it to
 * validate a dedicated activity and to construct an allow-listed report after
 * exercising the production Calendar and Meet boundaries. Remote identifiers,
 * provider URIs, OAuth data, email addresses and response bodies are never
 * accepted into the report.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class workspace_acceptance {

    /** Activity-name marker required in the dedicated tenant. */
    public const ACTIVITY_PREFIX = '[ACCEPTANCE]';

    /** Read-only local state capture. */
    public const SCENARIO_SNAPSHOT = 'snapshot';

    /** OAuth refresh and writable Calendar preflight. */
    public const SCENARIO_PREFLIGHT = 'preflight';

    /** Create or reconcile a Calendar event. */
    public const SCENARIO_SYNCHRONISE = 'synchronise';

    /** Poll an asynchronously pending conference. */
    public const SCENARIO_POLL = 'poll';

    /** Cancel the persisted Calendar event. */
    public const SCENARIO_CANCEL = 'cancel';

    /** Discover generated recording metadata through the Meet API. */
    public const SCENARIO_RECORDINGS = 'recordings';

    /** No schedule shape is required. */
    public const SCHEDULE_ANY = 'any';

    /** A one-off meeting is required. */
    public const SCHEDULE_SINGLE = 'single';

    /** A recurring meeting is required. */
    public const SCHEDULE_RECURRING = 'recurring';

    /** Explicit acknowledgement required before a remote Calendar mutation. */
    public const MUTATION_CONFIRMATION = 'MUTATE-GOOGLE-WORKSPACE';

    /** Current evidence format. */
    public const EVIDENCE_SCHEMA = 2;

    /** Moodle branch accepted by this modernization line. */
    public const MOODLE_BRANCH = '502';

    /** @var string[] PHP series accepted by this modernization line. */
    private const PHP_RUNTIMES = [
        '8.3',
        '8.4',
    ];

    /** @var string[] Closed scenario set exposed by the workflow. */
    private const SCENARIOS = [
        self::SCENARIO_SNAPSHOT,
        self::SCENARIO_PREFLIGHT,
        self::SCENARIO_SYNCHRONISE,
        self::SCENARIO_POLL,
        self::SCENARIO_CANCEL,
        self::SCENARIO_RECORDINGS,
    ];

    /** @var string[] Schedule expectations accepted by the fixture. */
    private const SCHEDULES = [
        self::SCHEDULE_ANY,
        self::SCHEDULE_SINGLE,
        self::SCHEDULE_RECURRING,
    ];

    /** @var array<string, string[]> Scenarios that can produce evidence for each runbook case. */
    private const CASE_SCENARIOS = [
        'GW-01' => [self::SCENARIO_PREFLIGHT],
        'GW-02' => [self::SCENARIO_PREFLIGHT],
        'GW-03' => [self::SCENARIO_PREFLIGHT],
        'GW-04' => [self::SCENARIO_SYNCHRONISE, self::SCENARIO_POLL],
        'GW-05' => [self::SCENARIO_SYNCHRONISE],
        'GW-06' => [self::SCENARIO_SYNCHRONISE],
        'GW-07' => [self::SCENARIO_SYNCHRONISE, self::SCENARIO_POLL],
        'GW-08' => [self::SCENARIO_SYNCHRONISE],
        'GW-09' => [self::SCENARIO_CANCEL, self::SCENARIO_SNAPSHOT],
        'GW-10' => [self::SCENARIO_SYNCHRONISE],
        'GW-11' => [self::SCENARIO_RECORDINGS],
        'GW-12' => [self::SCENARIO_RECORDINGS],
        'GW-13' => [self::SCENARIO_PREFLIGHT, self::SCENARIO_RECORDINGS],
        'GW-14' => [self::SCENARIO_SNAPSHOT],
    ];

    /** @var string[] Report result values. */
    private const RESULTS = [
        'passed',
        'failed',
    ];

    /** @var string[] Result of one bounded acceptance check. */
    private const CHECK_RESULTS = [
        'passed',
        'failed',
        'pending',
        'skipped',
    ];

    /** @var string[] Allow-listed check names. */
    private const CHECKS = [
        'dedicated_tenant',
        'activity_marker',
        'owner_context',
        'schedule_shape',
        'calendar_oauth',
        'writable_calendar',
        'calendar_operation',
        'meet_conference',
        'guest_reconciliation',
        'recording_oauth',
        'recording_discovery',
        'local_snapshot',
    ];

    /** @var string[] Allow-listed top-level evidence keys. */
    private const EVIDENCE_KEYS = [
        'schema',
        'campaign_id',
        'case_id',
        'scenario',
        'result',
        'generated_at',
        'source_commit',
        'plugin_version',
        'moodle_version',
        'moodle_branch',
        'php_runtime',
        'activity_id',
        'expected_schedule',
        'checks',
        'state',
        'failure_code',
    ];

    /** @var string[] Allow-listed state keys. */
    private const STATE_KEYS = [
        'schedule_type',
        'meeting_state',
        'conference_state',
        'calendar_event_present',
        'join_link_present',
        'guest_policy',
        'guest_count',
        'recording_state',
        'recording_count',
        'calendar_owner_bound',
        'calendar_issuer_bound',
        'recording_owner_bound',
        'recording_issuer_bound',
    ];

    /**
     * This class only exposes validation and evidence helpers.
     */
    private function __construct() {
    }

    /**
     * Returns every supported acceptance scenario.
     *
     * @return string[]
     */
    public static function scenarios(): array {
        return self::SCENARIOS;
    }

    /**
     * Returns every supported schedule expectation.
     *
     * @return string[]
     */
    public static function schedules(): array {
        return self::SCHEDULES;
    }

    /**
     * Returns the runbook cases and the scenarios accepted for each case.
     *
     * @return array<string, string[]>
     */
    public static function case_scenarios(): array {
        return self::CASE_SCENARIOS;
    }

    /**
     * Validates the bounded campaign identity and case/scenario relationship.
     *
     * Campaign IDs are intentionally short slugs. They must not contain
     * credential-related words because they are retained in sanitized evidence.
     *
     * @param string $campaignid Acceptance campaign slug.
     * @param string $caseid Runbook case identifier.
     * @param string $scenario Scenario identifier.
     */
    public static function assert_campaign_case(
        string $campaignid,
        string $caseid,
        string $scenario
    ): void {
        if (
            !preg_match('/^[a-z0-9][a-z0-9-]{2,31}$/', $campaignid)
            || str_contains($campaignid, '--')
            || preg_match('/(?:credential|oauth|secret|token)/', $campaignid)
        ) {
            throw new \coding_exception('The Google Workspace acceptance campaign is invalid.');
        }
        self::assert_scenario($scenario);
        if (
            !array_key_exists($caseid, self::CASE_SCENARIOS)
            || !in_array($scenario, self::CASE_SCENARIOS[$caseid], true)
        ) {
            throw new \coding_exception('The acceptance case does not support the selected scenario.');
        }
    }

    /**
     * Whether a scenario can mutate a remote Calendar resource.
     *
     * @param string $scenario Scenario identifier.
     * @return bool
     */
    public static function requires_mutation_confirmation(string $scenario): bool {
        self::assert_scenario($scenario);
        return in_array($scenario, [
            self::SCENARIO_SYNCHRONISE,
            self::SCENARIO_CANCEL,
        ], true);
    }

    /**
     * Rejects a missing acknowledgement before a remote Calendar mutation.
     *
     * @param string $scenario Scenario identifier.
     * @param string $confirmation Submitted acknowledgement.
     */
    public static function assert_mutation_confirmation(string $scenario, string $confirmation): void {
        if (
            self::requires_mutation_confirmation($scenario)
            && !hash_equals(self::MUTATION_CONFIRMATION, $confirmation)
        ) {
            throw new \coding_exception(
                'Explicit confirmation is required before the Google Workspace mutation.'
            );
        }
    }

    /**
     * Validates the local activity selected for a dedicated acceptance run.
     *
     * @param \stdClass $meeting Activity record.
     * @param int $currentuserid User context that will own the API request.
     * @param string $expectedschedule Required schedule shape.
     */
    public static function assert_activity(
        \stdClass $meeting,
        int $currentuserid,
        string $expectedschedule = self::SCHEDULE_ANY
    ): void {
        self::assert_schedule($expectedschedule);

        if (!str_starts_with((string) ($meeting->name ?? ''), self::ACTIVITY_PREFIX)) {
            throw new \coding_exception('Acceptance activities require the dedicated name marker.');
        }
        if (($meeting->integrationmode ?? null) !== integration_mode::MANAGED) {
            throw new \coding_exception('Acceptance requires a managed Calendar activity.');
        }

        $owneruserid = (int) ($meeting->owneruserid ?? 0);
        if ($owneruserid <= 0 || $currentuserid <= 0 || $owneruserid !== $currentuserid) {
            throw new \coding_exception('Acceptance must run as the persisted meeting owner.');
        }
        if ((int) ($meeting->oauthissuerid ?? 0) <= 0) {
            throw new \coding_exception('Acceptance requires a persisted Calendar OAuth issuer.');
        }

        $calendarid = trim((string) ($meeting->calendarid ?? ''));
        if (
            $calendarid === ''
            || strlen($calendarid) > 255
            || preg_match('/[\x00-\x1F\x7F]/', $calendarid)
        ) {
            throw new \coding_exception('Acceptance requires a valid persisted Calendar identifier.');
        }

        $timestart = (int) ($meeting->timestart ?? 0);
        $timeend = (int) ($meeting->timeend ?? 0);
        if ($timestart <= 0 || $timeend <= $timestart) {
            throw new \coding_exception('Acceptance requires a valid canonical schedule.');
        }

        $recurrence = trim((string) ($meeting->recurrence ?? ''));
        if ($expectedschedule === self::SCHEDULE_SINGLE && $recurrence !== '') {
            throw new \coding_exception('Acceptance expected a single meeting.');
        }
        if ($expectedschedule === self::SCHEDULE_RECURRING && $recurrence === '') {
            throw new \coding_exception('Acceptance expected a recurring meeting.');
        }

        if (!sync_state::is_valid((string) ($meeting->syncstatus ?? ''))) {
            throw new \coding_exception('Acceptance found an invalid meeting synchronization state.');
        }
        if (!recording_sync_state::is_valid((string) ($meeting->recordingsyncstatus ?? ''))) {
            throw new \coding_exception('Acceptance found an invalid recording synchronization state.');
        }
        if (!calendar_guest_policy::is_valid((string) ($meeting->guestpolicy ?? ''))) {
            throw new \coding_exception('Acceptance found an invalid Calendar guest policy.');
        }

        $guestcount = (int) ($meeting->guestcount ?? -1);
        if ($guestcount < 0 || $guestcount > calendar_guest_resolver::MAX_ATTENDEES) {
            throw new \coding_exception('Acceptance found an invalid Calendar guest count.');
        }
    }

    /**
     * Constructs a closed, privacy-safe acceptance report.
     *
     * @param string $campaignid Acceptance campaign slug.
     * @param string $caseid Runbook case identifier.
     * @param string $scenario Scenario identifier.
     * @param string $result Overall result.
     * @param int $activityid Local activity ID.
     * @param string $expectedschedule Required schedule shape.
     * @param array<string, string> $checks Bounded check results.
     * @param \stdClass|null $meeting Latest activity record.
     * @param int|null $recordingcount Number of local recording references.
     * @param int $pluginversion Installed plugin version.
     * @param int $moodleversion Installed Moodle version.
     * @param string $moodlebranch Installed Moodle branch.
     * @param string $phpruntime Active PHP major/minor series.
     * @param int $generatedat Evidence generation time.
     * @param string|null $sourcecommit Optional exact source commit.
     * @param string|null $failurecode Stable failure code.
     * @return array<string, mixed>
     */
    public static function evidence(
        string $campaignid,
        string $caseid,
        string $scenario,
        string $result,
        int $activityid,
        string $expectedschedule,
        array $checks,
        ?\stdClass $meeting,
        ?int $recordingcount,
        int $pluginversion,
        int $moodleversion,
        string $moodlebranch,
        string $phpruntime,
        int $generatedat,
        ?string $sourcecommit = null,
        ?string $failurecode = null
    ): array {
        self::assert_campaign_case($campaignid, $caseid, $scenario);
        self::assert_schedule($expectedschedule);
        if (!in_array($result, self::RESULTS, true)) {
            throw new \coding_exception('The acceptance result is invalid.');
        }
        if (
            $activityid <= 0
            || $pluginversion <= 0
            || $moodleversion <= 0
            || $generatedat <= 0
        ) {
            throw new \coding_exception('Acceptance evidence requires positive local identifiers and time.');
        }
        if (
            $moodlebranch !== self::MOODLE_BRANCH
            || !in_array($phpruntime, self::PHP_RUNTIMES, true)
        ) {
            throw new \coding_exception('Acceptance evidence requires the supported Moodle and PHP runtime.');
        }
        if ($recordingcount !== null && $recordingcount < 0) {
            throw new \coding_exception('The acceptance recording count cannot be negative.');
        }

        $normalisedchecks = array_fill_keys(self::CHECKS, 'skipped');
        foreach ($checks as $check => $checkresult) {
            if (
                !in_array($check, self::CHECKS, true)
                || !in_array($checkresult, self::CHECK_RESULTS, true)
            ) {
                throw new \coding_exception('The acceptance check result is invalid.');
            }
            $normalisedchecks[$check] = $checkresult;
        }

        $sourcecommit = self::source_commit($sourcecommit);
        $failurecode = self::failure_code($failurecode);
        if ($result === 'passed' && $failurecode !== null) {
            throw new \coding_exception('Successful acceptance evidence cannot include a failure code.');
        }
        if ($result === 'failed' && $failurecode === null) {
            $failurecode = 'acceptance_failed';
        }

        $evidence = [
            'schema' => self::EVIDENCE_SCHEMA,
            'campaign_id' => $campaignid,
            'case_id' => $caseid,
            'scenario' => $scenario,
            'result' => $result,
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z', $generatedat),
            'source_commit' => $sourcecommit,
            'plugin_version' => $pluginversion,
            'moodle_version' => $moodleversion,
            'moodle_branch' => $moodlebranch,
            'php_runtime' => $phpruntime,
            'activity_id' => $activityid,
            'expected_schedule' => $expectedschedule,
            'checks' => $normalisedchecks,
            'state' => self::state($meeting, $recordingcount),
            'failure_code' => $failurecode,
        ];
        self::assert_sanitized_evidence($evidence);

        return $evidence;
    }

    /**
     * Maps an exception to one stable code without exposing its message.
     *
     * @param \Throwable $exception Failure raised by the live boundary.
     * @return string
     */
    public static function exception_code(\Throwable $exception): string {
        return match (true) {
            $exception instanceof calendar_authorization_exception => 'calendar_authorization_required',
            $exception instanceof calendar_configuration_exception => 'calendar_configuration_invalid',
            $exception instanceof calendar_response_exception => 'calendar_response_invalid',
            $exception instanceof calendar_transport_exception => 'calendar_transport_retryable',
            $exception instanceof calendar_api_exception => 'calendar_api_rejected',
            $exception instanceof recording_authorization_exception => 'recording_authorization_required',
            $exception instanceof recording_response_exception => 'recording_response_invalid',
            $exception instanceof recording_transport_exception => 'recording_transport_retryable',
            $exception instanceof recording_api_exception => 'recording_api_rejected',
            $exception instanceof \coding_exception,
            $exception instanceof \invalid_parameter_exception,
            $exception instanceof \moodle_exception => 'acceptance_precondition_failed',
            default => 'acceptance_internal_error',
        };
    }

    /**
     * Enforces the closed evidence schema and rejects likely secret material.
     *
     * @param array<string, mixed> $evidence Candidate report.
     */
    public static function assert_sanitized_evidence(array $evidence): void {
        if (array_keys($evidence) !== self::EVIDENCE_KEYS) {
            throw new \coding_exception('Acceptance evidence has unexpected top-level fields.');
        }
        if (
            $evidence['schema'] !== self::EVIDENCE_SCHEMA
            || !is_string($evidence['campaign_id'])
            || !is_string($evidence['case_id'])
            || !is_string($evidence['scenario'])
            || !in_array($evidence['result'], self::RESULTS, true)
            || !in_array($evidence['expected_schedule'], self::SCHEDULES, true)
            || !is_int($evidence['plugin_version'])
            || $evidence['plugin_version'] <= 0
            || !is_int($evidence['moodle_version'])
            || $evidence['moodle_version'] <= 0
            || $evidence['moodle_branch'] !== self::MOODLE_BRANCH
            || !is_string($evidence['php_runtime'])
            || !in_array($evidence['php_runtime'], self::PHP_RUNTIMES, true)
            || !is_int($evidence['activity_id'])
            || $evidence['activity_id'] <= 0
        ) {
            throw new \coding_exception('Acceptance evidence metadata is invalid.');
        }
        self::assert_campaign_case(
            $evidence['campaign_id'],
            $evidence['case_id'],
            $evidence['scenario']
        );
        if (
            !is_string($evidence['generated_at'])
            || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $evidence['generated_at'])
        ) {
            throw new \coding_exception('Acceptance evidence time is invalid.');
        }
        if (
            $evidence['source_commit'] !== null
            && (!is_string($evidence['source_commit'])
                || !preg_match('/^[a-f0-9]{40}$/', $evidence['source_commit']))
        ) {
            throw new \coding_exception('Acceptance evidence source commit is invalid.');
        }
        if (
            $evidence['failure_code'] !== null
            && (!is_string($evidence['failure_code'])
                || !preg_match('/^[a-z0-9][a-z0-9_.-]{0,99}$/', $evidence['failure_code']))
        ) {
            throw new \coding_exception('Acceptance evidence failure code is invalid.');
        }

        if (!is_array($evidence['checks']) || array_keys($evidence['checks']) !== self::CHECKS) {
            throw new \coding_exception('Acceptance evidence checks are invalid.');
        }
        foreach ($evidence['checks'] as $result) {
            if (!is_string($result) || !in_array($result, self::CHECK_RESULTS, true)) {
                throw new \coding_exception('Acceptance evidence contains an invalid check result.');
            }
        }

        if (!is_array($evidence['state']) || array_keys($evidence['state']) !== self::STATE_KEYS) {
            throw new \coding_exception('Acceptance evidence state is invalid.');
        }
        self::assert_state($evidence['state']);

        $json = json_encode($evidence, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new \coding_exception('Acceptance evidence could not be encoded.');
        }
        if (
            preg_match('/https?:\/\//i', $json)
            || preg_match('/\bBearer\s+/i', $json)
            || preg_match('/\bya29\.[A-Za-z0-9._-]+/', $json)
            || preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $json)
            || preg_match('/(?:access|refresh|id|client)[_-]?token|client[_-]?secret/i', $json)
        ) {
            throw new \coding_exception('Acceptance evidence contains forbidden secret or provider material.');
        }
    }

    /**
     * Returns a state object containing presence flags, never remote values.
     *
     * @param \stdClass|null $meeting Latest activity record.
     * @param int|null $recordingcount Local recording count.
     * @return array<string, mixed>
     */
    private static function state(?\stdClass $meeting, ?int $recordingcount): array {
        $recurrence = trim((string) ($meeting->recurrence ?? ''));
        $meetingstate = (string) ($meeting->syncstatus ?? '');
        $recordingstate = (string) ($meeting->recordingsyncstatus ?? '');
        $conferencestate = (string) ($meeting->conferencestatus ?? '');
        $guestpolicy = (string) ($meeting->guestpolicy ?? '');
        $guestcount = isset($meeting->guestcount) ? (int) $meeting->guestcount : null;

        return [
            'schedule_type' => $meeting === null
                ? 'unknown'
                : ($recurrence === '' ? self::SCHEDULE_SINGLE : self::SCHEDULE_RECURRING),
            'meeting_state' => sync_state::is_valid($meetingstate) ? $meetingstate : 'unknown',
            'conference_state' => $conferencestate === ''
                ? 'absent'
                : (in_array($conferencestate, ['pending', 'success', 'failure'], true)
                    ? $conferencestate
                    : 'unknown'),
            'calendar_event_present' => !empty($meeting->googleeventid),
            'join_link_present' => self::valid_meet_uri((string) ($meeting->meetinguri ?? '')),
            'guest_policy' => calendar_guest_policy::is_valid($guestpolicy) ? $guestpolicy : 'unknown',
            'guest_count' => $guestcount !== null
                && $guestcount >= 0
                && $guestcount <= calendar_guest_resolver::MAX_ATTENDEES
                    ? $guestcount
                    : null,
            'recording_state' => recording_sync_state::is_valid($recordingstate)
                ? $recordingstate
                : 'unknown',
            'recording_count' => $recordingcount,
            'calendar_owner_bound' => (int) ($meeting->owneruserid ?? 0) > 0,
            'calendar_issuer_bound' => (int) ($meeting->oauthissuerid ?? 0) > 0,
            'recording_owner_bound' => (int) ($meeting->recordingowneruserid ?? 0) > 0,
            'recording_issuer_bound' => (int) ($meeting->recordingoauthissuerid ?? 0) > 0,
        ];
    }

    /**
     * Validates the state section independently of the source activity.
     *
     * @param array<string, mixed> $state State report.
     */
    private static function assert_state(array $state): void {
        if (!in_array($state['schedule_type'], ['single', 'recurring', 'unknown'], true)) {
            throw new \coding_exception('Acceptance evidence schedule state is invalid.');
        }
        if (!in_array($state['meeting_state'], array_merge(sync_state::all(), ['unknown']), true)) {
            throw new \coding_exception('Acceptance evidence meeting state is invalid.');
        }
        if (!in_array($state['conference_state'], ['absent', 'pending', 'success', 'failure', 'unknown'], true)) {
            throw new \coding_exception('Acceptance evidence conference state is invalid.');
        }
        if (!in_array($state['guest_policy'], array_merge(calendar_guest_policy::all(), ['unknown']), true)) {
            throw new \coding_exception('Acceptance evidence guest policy is invalid.');
        }
        if (
            !in_array($state['recording_state'], [
                recording_sync_state::DISCONNECTED,
                recording_sync_state::QUEUED,
                recording_sync_state::SYNCING,
                recording_sync_state::READY,
                recording_sync_state::FAILED,
                'unknown',
            ], true)
        ) {
            throw new \coding_exception('Acceptance evidence recording state is invalid.');
        }

        foreach ([
            'calendar_event_present',
            'join_link_present',
            'calendar_owner_bound',
            'calendar_issuer_bound',
            'recording_owner_bound',
            'recording_issuer_bound',
        ] as $field) {
            if (!is_bool($state[$field])) {
                throw new \coding_exception('Acceptance evidence presence state is invalid.');
            }
        }
        foreach (['guest_count', 'recording_count'] as $field) {
            if ($state[$field] !== null && (!is_int($state[$field]) || $state[$field] < 0)) {
                throw new \coding_exception('Acceptance evidence count is invalid.');
            }
        }
    }

    /**
     * Accepts only an exact Google Meet join URI without extra components.
     *
     * @param string $uri Candidate provider URI.
     * @return bool
     */
    private static function valid_meet_uri(string $uri): bool {
        if ($uri === '' || strlen($uri) > 2048) {
            return false;
        }
        $parts = parse_url($uri);
        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && ($parts['host'] ?? null) === 'meet.google.com'
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['port'])
            && !isset($parts['query'])
            && !isset($parts['fragment'])
            && preg_match('#^/[a-z]{3}-[a-z]{4}-[a-z]{3}/?$#', (string) ($parts['path'] ?? '')) === 1;
    }

    /**
     * Normalizes an optional source commit.
     *
     * @param string|null $sourcecommit Candidate commit.
     * @return string|null
     */
    private static function source_commit(?string $sourcecommit): ?string {
        if ($sourcecommit === null || $sourcecommit === '') {
            return null;
        }
        $sourcecommit = strtolower(trim($sourcecommit));
        return preg_match('/^[a-f0-9]{40}$/', $sourcecommit) ? $sourcecommit : null;
    }

    /**
     * Normalizes an optional stable failure code.
     *
     * @param string|null $failurecode Candidate code.
     * @return string|null
     */
    private static function failure_code(?string $failurecode): ?string {
        if ($failurecode === null || $failurecode === '') {
            return null;
        }
        return preg_match('/^[a-z0-9][a-z0-9_.-]{0,99}$/', $failurecode)
            ? $failurecode
            : 'acceptance_failed';
    }

    /**
     * Rejects an unknown scenario.
     *
     * @param string $scenario Candidate.
     */
    private static function assert_scenario(string $scenario): void {
        if (!in_array($scenario, self::SCENARIOS, true)) {
            throw new \coding_exception('The Google Workspace acceptance scenario is invalid.');
        }
    }

    /**
     * Rejects an unknown schedule expectation.
     *
     * @param string $schedule Candidate.
     */
    private static function assert_schedule(string $schedule): void {
        if (!in_array($schedule, self::SCHEDULES, true)) {
            throw new \coding_exception('The Google Workspace acceptance schedule is invalid.');
        }
    }
}
