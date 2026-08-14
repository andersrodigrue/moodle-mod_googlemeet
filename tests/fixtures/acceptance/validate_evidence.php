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

/**
 * Performs a dependency-free final secret scan before evidence upload.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$fail = static function (string $code): never {
    fwrite(STDERR, 'Google Workspace evidence rejected: ' . $code . PHP_EOL);
    exit(1);
};

if (count($argv) !== 2) {
    $fail('file_argument_required');
}
$path = realpath($argv[1]);
if ($path === false || !is_file($path) || filesize($path) > 65536) {
    $fail('file_invalid');
}
$json = file_get_contents($path);
if (!is_string($json) || $json === '') {
    $fail('file_empty');
}
try {
    $evidence = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    $fail('json_invalid');
}
if (!is_array($evidence)) {
    $fail('document_invalid');
}

$expectedkeys = [
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
if (array_keys($evidence) !== $expectedkeys || $evidence['schema'] !== 2) {
    $fail('schema_invalid');
}
$casescenarios = [
    'GW-01' => ['preflight'],
    'GW-02' => ['preflight'],
    'GW-03' => ['preflight'],
    'GW-04' => ['synchronise', 'poll'],
    'GW-05' => ['synchronise'],
    'GW-06' => ['synchronise'],
    'GW-07' => ['synchronise', 'poll'],
    'GW-08' => ['synchronise'],
    'GW-09' => ['cancel', 'snapshot'],
    'GW-10' => ['synchronise'],
    'GW-11' => ['recordings'],
    'GW-12' => ['recordings'],
    'GW-13' => ['preflight', 'recordings'],
    'GW-14' => ['snapshot'],
];
if (
    !is_string($evidence['campaign_id'])
    || !preg_match('/^[a-z0-9][a-z0-9-]{2,31}$/', $evidence['campaign_id'])
    || str_contains($evidence['campaign_id'], '--')
    || preg_match('/(?:credential|oauth|secret|token)/', $evidence['campaign_id'])
    || !is_string($evidence['case_id'])
    || !array_key_exists($evidence['case_id'], $casescenarios)
    || !is_string($evidence['scenario'])
    || !in_array($evidence['scenario'], $casescenarios[$evidence['case_id']], true)
    || !in_array($evidence['result'], ['passed', 'failed'], true)
    || !in_array($evidence['expected_schedule'], ['any', 'single', 'recurring'], true)
    || !is_int($evidence['plugin_version'])
    || $evidence['plugin_version'] <= 0
    || !is_int($evidence['moodle_version'])
    || $evidence['moodle_version'] <= 0
    || $evidence['moodle_branch'] !== '502'
    || !in_array($evidence['php_runtime'], ['8.3', '8.4'], true)
    || !is_int($evidence['activity_id'])
    || $evidence['activity_id'] <= 0
) {
    $fail('result_invalid');
}
if (
    !is_string($evidence['generated_at'])
    || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $evidence['generated_at'])
    || ($evidence['source_commit'] !== null
        && (!is_string($evidence['source_commit'])
            || !preg_match('/^[a-f0-9]{40}$/', $evidence['source_commit'])))
    || ($evidence['failure_code'] !== null
        && (!is_string($evidence['failure_code'])
            || !preg_match('/^[a-z0-9][a-z0-9_.-]{0,99}$/', $evidence['failure_code'])))
) {
    $fail('metadata_invalid');
}
if (
    ($evidence['result'] === 'passed' && $evidence['failure_code'] !== null)
    || ($evidence['result'] === 'failed' && $evidence['failure_code'] === null)
) {
    $fail('failure_code_invalid');
}

$expectedcheckkeys = [
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
if (!is_array($evidence['checks']) || array_keys($evidence['checks']) !== $expectedcheckkeys) {
    $fail('checks_invalid');
}
foreach ($evidence['checks'] as $checkresult) {
    if (!is_string($checkresult) || !in_array($checkresult, [
        'passed',
        'failed',
        'pending',
        'skipped',
    ], true)) {
        $fail('check_result_invalid');
    }
}

$expectedstatekeys = [
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
if (!is_array($evidence['state']) || array_keys($evidence['state']) !== $expectedstatekeys) {
    $fail('state_invalid');
}
if (
    !in_array($evidence['state']['schedule_type'], ['single', 'recurring', 'unknown'], true)
    || !in_array($evidence['state']['meeting_state'], [
        'draft',
        'queued',
        'syncing',
        'pending',
        'ready',
        'failed',
        'cancelling',
        'cancelled',
        'disconnected',
        'unknown',
    ], true)
    || !in_array($evidence['state']['conference_state'], [
        'absent',
        'pending',
        'success',
        'failure',
        'unknown',
    ], true)
    || !in_array($evidence['state']['guest_policy'], ['none', 'course', 'unknown'], true)
    || !in_array($evidence['state']['recording_state'], [
        'disconnected',
        'queued',
        'syncing',
        'ready',
        'failed',
        'unknown',
    ], true)
) {
    $fail('state_value_invalid');
}
foreach ([
    'calendar_event_present',
    'join_link_present',
    'calendar_owner_bound',
    'calendar_issuer_bound',
    'recording_owner_bound',
    'recording_issuer_bound',
] as $booleanfield) {
    if (!is_bool($evidence['state'][$booleanfield])) {
        $fail('state_boolean_invalid');
    }
}
foreach (['guest_count', 'recording_count'] as $countfield) {
    if (
        $evidence['state'][$countfield] !== null
        && (!is_int($evidence['state'][$countfield]) || $evidence['state'][$countfield] < 0)
    ) {
        $fail('state_count_invalid');
    }
}

if (
    preg_match('/https?:\/\//i', $json)
    || preg_match('/\bBearer\s+/i', $json)
    || preg_match('/\bya29\.[A-Za-z0-9._-]+/', $json)
    || preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $json)
    || preg_match('/(?:access|refresh|id|client)[_-]?token|client[_-]?secret/i', $json)
    || preg_match('/\"(?:calendarid|googleeventid|meetinguri|webviewlink|requestid)\"/i', $json)
) {
    $fail('sensitive_material_detected');
}

fwrite(STDOUT, 'Google Workspace acceptance evidence is sanitized.' . PHP_EOL);
