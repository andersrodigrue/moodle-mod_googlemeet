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
 * Verifies a complete, sanitized Google Workspace acceptance campaign.
 *
 * The verifier is dependency-free and CLI-only. It consumes downloaded
 * evidence plus a closed manual-review document; it never contacts Moodle,
 * GitHub or Google.
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
    fwrite(STDERR, 'Google Workspace campaign rejected: ' . $code . PHP_EOL);
    exit(1);
};

if (count($argv) !== 3) {
    $fail('arguments_required');
}
$evidencedirectory = realpath($argv[1]);
$reviewpath = realpath($argv[2]);
if (
    $evidencedirectory === false
    || !is_dir($evidencedirectory)
    || $reviewpath === false
    || !is_file($reviewpath)
    || filesize($reviewpath) > 65536
) {
    $fail('input_invalid');
}

$decode = static function (string $path, int $limit, string $code) use ($fail): array {
    $size = filesize($path);
    if ($size === false || $size <= 0 || $size > $limit) {
        $fail($code . '_size');
    }
    $json = file_get_contents($path);
    if (!is_string($json) || $json === '') {
        $fail($code . '_empty');
    }
    try {
        $document = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        $fail($code . '_json');
    }
    if (!is_array($document)) {
        $fail($code . '_document');
    }

    return [$document, $json];
};

[$review, $reviewjson] = $decode($reviewpath, 65536, 'review');
$runtimes = ['8.3', '8.4'];
$caseids = [
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
];
$reviewkeys = ['schema', 'campaign_id', 'source_commit', 'plugin_version', 'reviews'];
if (
    array_keys($review) !== $reviewkeys
    || $review['schema'] !== 1
    || !is_string($review['campaign_id'])
    || !preg_match('/^[a-z0-9][a-z0-9-]{2,31}$/', $review['campaign_id'])
    || str_contains($review['campaign_id'], '--')
    || preg_match('/(?:credential|oauth|secret|token)/', $review['campaign_id'])
    || !is_string($review['source_commit'])
    || !preg_match('/^[a-f0-9]{40}$/', $review['source_commit'])
    || !is_int($review['plugin_version'])
    || $review['plugin_version'] <= 0
    || !is_array($review['reviews'])
    || array_keys($review['reviews']) !== $runtimes
) {
    $fail('review_schema');
}
foreach ($runtimes as $runtime) {
    if (
        !is_array($review['reviews'][$runtime])
        || array_keys($review['reviews'][$runtime]) !== $caseids
    ) {
        $fail('review_cases');
    }
    foreach ($review['reviews'][$runtime] as $status) {
        if (!is_string($status) || !in_array($status, ['passed', 'failed', 'pending'], true)) {
            $fail('review_status');
        }
        if ($status !== 'passed') {
            $fail('manual_review_incomplete');
        }
    }
}
if (
    preg_match('/https?:\/\//i', $reviewjson)
    || preg_match('/\bBearer\s+/i', $reviewjson)
    || preg_match('/\bya29\.[A-Za-z0-9._-]+/', $reviewjson)
    || preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $reviewjson)
    || preg_match('/(?:access|refresh|id|client)[_-]?token|client[_-]?secret/i', $reviewjson)
) {
    $fail('review_sensitive_material');
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
$evidencekeys = [
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
$checkkeys = [
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
$statekeys = [
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

$requirements = [
    'GW-01' => [['preflight', 'passed', 1]],
    'GW-02' => [['preflight', 'passed', 1]],
    'GW-03' => [['preflight', 'passed', 1], ['preflight', 'failed', 1]],
    'GW-04' => [['synchronise', 'passed', 1]],
    'GW-05' => [['synchronise', 'passed', 2]],
    'GW-06' => [['synchronise', 'passed', 2]],
    'GW-07' => [['synchronise', 'passed', 1], ['poll', 'passed', 1]],
    'GW-08' => [['synchronise', 'passed', 2]],
    'GW-09' => [['cancel', 'passed', 1], ['snapshot', 'passed', 1]],
    // GW-10 is deterministic CI evidence plus the closed manual review.
    'GW-11' => [['recordings', 'passed', 1]],
    'GW-12' => [['recordings', 'passed', 1]],
    'GW-13' => [
        ['preflight', 'failed', 1],
        ['preflight', 'passed', 1],
        ['recordings', 'failed', 1],
        ['recordings', 'passed', 1],
    ],
    'GW-14' => [['snapshot', 'passed', 1]],
];
$counts = [];
$hashes = [];
$files = glob($evidencedirectory . DIRECTORY_SEPARATOR . '*.json');
if (!is_array($files) || $files === []) {
    $fail('evidence_missing');
}

foreach ($files as $path) {
    $realpath = realpath($path);
    if ($realpath === false || $realpath === $reviewpath) {
        continue;
    }
    [$evidence, $json] = $decode($realpath, 65536, 'evidence');
    $hash = hash('sha256', $json);
    if (isset($hashes[$hash])) {
        $fail('evidence_duplicate');
    }
    $hashes[$hash] = true;

    if (
        array_keys($evidence) !== $evidencekeys
        || $evidence['schema'] !== 2
        || $evidence['campaign_id'] !== $review['campaign_id']
        || !is_string($evidence['case_id'])
        || !array_key_exists($evidence['case_id'], $casescenarios)
        || !is_string($evidence['scenario'])
        || !in_array($evidence['scenario'], $casescenarios[$evidence['case_id']], true)
        || !in_array($evidence['result'], ['passed', 'failed'], true)
        || $evidence['source_commit'] !== $review['source_commit']
        || $evidence['plugin_version'] !== $review['plugin_version']
        || !is_int($evidence['moodle_version'])
        || $evidence['moodle_version'] <= 0
        || $evidence['moodle_branch'] !== '502'
        || !in_array($evidence['php_runtime'], $runtimes, true)
        || !is_int($evidence['activity_id'])
        || $evidence['activity_id'] <= 0
        || !in_array($evidence['expected_schedule'], ['any', 'single', 'recurring'], true)
        || !is_array($evidence['checks'])
        || array_keys($evidence['checks']) !== $checkkeys
        || !is_array($evidence['state'])
        || array_keys($evidence['state']) !== $statekeys
    ) {
        $fail('evidence_schema');
    }
    if (
        !is_string($evidence['generated_at'])
        || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $evidence['generated_at'])
        || ($evidence['result'] === 'passed' && $evidence['failure_code'] !== null)
        || ($evidence['result'] === 'failed'
            && (!is_string($evidence['failure_code'])
                || !preg_match('/^[a-z0-9][a-z0-9_.-]{0,99}$/', $evidence['failure_code'])))
    ) {
        $fail('evidence_result');
    }
    foreach ($evidence['checks'] as $status) {
        if (!is_string($status) || !in_array($status, ['passed', 'failed', 'pending', 'skipped'], true)) {
            $fail('evidence_checks');
        }
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
        $fail('evidence_state');
    }
    foreach ([
        'calendar_event_present',
        'join_link_present',
        'calendar_owner_bound',
        'calendar_issuer_bound',
        'recording_owner_bound',
        'recording_issuer_bound',
    ] as $field) {
        if (!is_bool($evidence['state'][$field])) {
            $fail('evidence_state_boolean');
        }
    }
    foreach (['guest_count', 'recording_count'] as $field) {
        if (
            $evidence['state'][$field] !== null
            && (!is_int($evidence['state'][$field]) || $evidence['state'][$field] < 0)
        ) {
            $fail('evidence_state_count');
        }
    }
    if (
        (is_int($evidence['state']['guest_count']) && $evidence['state']['guest_count'] > 200)
        || (is_int($evidence['state']['recording_count'])
            && $evidence['state']['recording_count'] > 10000)
    ) {
        $fail('evidence_state_limit');
    }
    if (
        preg_match('/https?:\/\//i', $json)
        || preg_match('/\bBearer\s+/i', $json)
        || preg_match('/\bya29\.[A-Za-z0-9._-]+/', $json)
        || preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $json)
        || preg_match('/(?:access|refresh|id|client)[_-]?token|client[_-]?secret/i', $json)
        || preg_match('/\"(?:calendarid|googleeventid|meetinguri|webviewlink|requestid)\"/i', $json)
    ) {
        $fail('evidence_sensitive_material');
    }

    $key = implode(':', [
        $evidence['php_runtime'],
        $evidence['case_id'],
        $evidence['scenario'],
        $evidence['result'],
    ]);
    $counts[$key] = ($counts[$key] ?? 0) + 1;
}

foreach ($runtimes as $runtime) {
    foreach ($requirements as $caseid => $caserequirements) {
        foreach ($caserequirements as [$scenario, $result, $minimum]) {
            $key = implode(':', [$runtime, $caseid, $scenario, $result]);
            if (($counts[$key] ?? 0) < $minimum) {
                $fail('campaign_incomplete_' . strtolower(str_replace('-', '_', $caseid)));
            }
        }
    }
}

fwrite(
    STDOUT,
    'Google Workspace acceptance campaign is complete for PHP 8.3 and 8.4.' . PHP_EOL
);
