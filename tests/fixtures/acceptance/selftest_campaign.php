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
 * Hermetic positive and negative self-test for the campaign verifier.
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
    fwrite(STDERR, 'Google Workspace campaign self-test failed: ' . $code . PHP_EOL);
    exit(1);
};

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . 'mod_googlemeet_campaign_' . bin2hex(random_bytes(8));
if (!mkdir($directory, 0700)) {
    $fail('directory_create');
}

$files = [];
$write = static function (string $path, array $document) use (&$files, $fail): void {
    try {
        $json = json_encode(
            $document,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    } catch (JsonException) {
        $fail('json_encode');
    }
    if (file_put_contents($path, $json . PHP_EOL, LOCK_EX) === false) {
        $fail('file_write');
    }
    $files[] = $path;
};

$campaignid = 'campaign-selftest';
$sourcecommit = str_repeat('a', 40);
$pluginversion = 2026072623;
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
$checks = [
    'dedicated_tenant' => 'passed',
    'activity_marker' => 'passed',
    'owner_context' => 'passed',
    'schedule_shape' => 'passed',
    'calendar_oauth' => 'skipped',
    'writable_calendar' => 'skipped',
    'calendar_operation' => 'skipped',
    'meet_conference' => 'skipped',
    'guest_reconciliation' => 'skipped',
    'recording_oauth' => 'skipped',
    'recording_discovery' => 'skipped',
    'local_snapshot' => 'skipped',
];
$state = [
    'schedule_type' => 'single',
    'meeting_state' => 'ready',
    'conference_state' => 'success',
    'calendar_event_present' => true,
    'join_link_present' => true,
    'guest_policy' => 'none',
    'guest_count' => 0,
    'recording_state' => 'ready',
    'recording_count' => 0,
    'calendar_owner_bound' => true,
    'calendar_issuer_bound' => true,
    'recording_owner_bound' => true,
    'recording_issuer_bound' => true,
];

$index = 0;
$removable = null;
try {
    foreach ($runtimes as $runtime) {
        foreach ($requirements as $caseid => $caserequirements) {
            foreach ($caserequirements as [$scenario, $result, $minimum]) {
                for ($attempt = 0; $attempt < $minimum; $attempt++) {
                    $index++;
                    $document = [
                        'schema' => 2,
                        'campaign_id' => $campaignid,
                        'case_id' => $caseid,
                        'scenario' => $scenario,
                        'result' => $result,
                        'generated_at' => gmdate('Y-m-d\TH:i:s\Z', 1785369600 + $index),
                        'source_commit' => $sourcecommit,
                        'plugin_version' => $pluginversion,
                        'moodle_version' => 2026042000,
                        'moodle_branch' => '502',
                        'php_runtime' => $runtime,
                        'activity_id' => $index,
                        'expected_schedule' => 'single',
                        'checks' => $checks,
                        'state' => $state,
                        'failure_code' => $result === 'failed'
                            ? 'acceptance_precondition_failed'
                            : null,
                    ];
                    $path = $directory . DIRECTORY_SEPARATOR
                        . sprintf('evidence-%03d.json', $index);
                    $write($path, $document);
                    $removable ??= $path;
                }
            }
        }
    }

    $reviews = [];
    foreach ($runtimes as $runtime) {
        $reviews[$runtime] = array_fill_keys($caseids, 'passed');
    }
    $reviewpath = $directory . DIRECTORY_SEPARATOR . 'manual-review.json';
    $write($reviewpath, [
        'schema' => 1,
        'campaign_id' => $campaignid,
        'source_commit' => $sourcecommit,
        'plugin_version' => $pluginversion,
        'reviews' => $reviews,
    ]);

    $runverifier = static function () use ($directory, $reviewpath): int {
        $command = [
            PHP_BINARY,
            __DIR__ . DIRECTORY_SEPARATOR . 'verify_campaign.php',
            $directory,
            $reviewpath,
        ];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            return 255;
        }
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        return proc_close($process);
    };

    if ($runverifier() !== 0) {
        $fail('complete_campaign_rejected');
    }
    if ($removable === null || !unlink($removable)) {
        $fail('negative_fixture_remove');
    }
    $files = array_values(array_diff($files, [$removable]));
    if ($runverifier() === 0) {
        $fail('incomplete_campaign_accepted');
    }
} finally {
    foreach ($files as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    rmdir($directory);
}

fwrite(STDOUT, 'Google Workspace campaign verifier self-test passed.' . PHP_EOL);
