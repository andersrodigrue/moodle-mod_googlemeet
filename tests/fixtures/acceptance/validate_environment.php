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
 * Performs a dependency-free secret scan of readiness evidence.
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
    fwrite(STDERR, 'Google Workspace readiness evidence rejected: ' . $code . PHP_EOL);
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
    'result',
    'generated_at',
    'source_commit',
    'plugin_version',
    'moodle_version',
    'moodle_branch',
    'php_runtime',
    'site_profile',
    'checks',
    'failure_code',
];
$checkkeys = [
    'cli_runtime',
    'acceptance_switch',
    'config_profile',
    'database_profile',
    'outbound_mail_disabled',
    'https_site',
    'moodle_52',
    'source_installed',
    'calendar_issuer',
    'recording_issuer',
    'issuer_separation',
    'single_fixture',
    'recurring_fixture',
    'guest_fixture',
    'recording_fixture',
];
if (
    array_keys($evidence) !== $expectedkeys
    || $evidence['schema'] !== 1
    || !in_array($evidence['result'], ['passed', 'failed'], true)
    || !is_string($evidence['generated_at'])
    || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $evidence['generated_at'])
    || !is_string($evidence['source_commit'])
    || !preg_match('/^[a-f0-9]{40}$/', $evidence['source_commit'])
    || !is_int($evidence['plugin_version'])
    || $evidence['plugin_version'] <= 0
    || !is_int($evidence['moodle_version'])
    || $evidence['moodle_version'] <= 0
    || $evidence['moodle_branch'] !== '502'
    || !in_array($evidence['php_runtime'], ['8.3', '8.4'], true)
    || !in_array($evidence['site_profile'], ['php83', 'php84'], true)
    || ($evidence['php_runtime'] === '8.3' && $evidence['site_profile'] !== 'php83')
    || ($evidence['php_runtime'] === '8.4' && $evidence['site_profile'] !== 'php84')
    || !is_array($evidence['checks'])
    || array_keys($evidence['checks']) !== $checkkeys
) {
    $fail('schema_invalid');
}
foreach ($evidence['checks'] as $status) {
    if (!is_string($status) || !in_array($status, ['passed', 'failed'], true)) {
        $fail('check_invalid');
    }
}
if (
    ($evidence['result'] === 'passed'
        && ($evidence['failure_code'] !== null
            || in_array('failed', $evidence['checks'], true)))
    || ($evidence['result'] === 'failed'
        && (!is_string($evidence['failure_code'])
            || !preg_match('/^readiness_[a-z0-9_]{1,80}$/', $evidence['failure_code'])
            || !in_array('failed', $evidence['checks'], true)))
) {
    $fail('result_invalid');
}
if (
    preg_match('/https?:\/\//i', $json)
    || preg_match('/\bBearer\s+/i', $json)
    || preg_match('/\bya29\.[A-Za-z0-9._-]+/', $json)
    || preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $json)
    || preg_match('/(?:access|refresh|id|client)[_-]?token|client[_-]?secret/i', $json)
    || preg_match('/\"(?:wwwroot|dbhost|dbname|calendarid|issuerid|userid)\"/i', $json)
) {
    $fail('sensitive_material_detected');
}

fwrite(STDOUT, 'Google Workspace readiness evidence is sanitized.' . PHP_EOL);
