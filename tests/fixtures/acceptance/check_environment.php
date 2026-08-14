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
 * Produces closed readiness evidence for a disposable acceptance site.
 *
 * The fixture reads only local Moodle configuration and database state. It
 * never composes an OAuth client, calls Google, sends mail or changes data.
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

define('CLI_SCRIPT', true);
define('NO_DEBUG_DISPLAY', true);

$bootstrapfail = static function (string $code): never {
    fwrite(STDERR, 'Google Workspace readiness bootstrap failed: ' . $code . PHP_EOL);
    exit(1);
};

$configpath = getenv('MOODLE_CONFIG');
if (!is_string($configpath) || trim($configpath) === '') {
    $bootstrapfail('moodle_config_missing');
}
$configpath = realpath($configpath);
if ($configpath === false || !is_file($configpath) || basename($configpath) !== 'config.php') {
    $bootstrapfail('moodle_config_invalid');
}

require($configpath);
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognised] = cli_get_params(
    [
        'runtime' => null,
        'help' => false,
    ],
    [
        'r' => 'runtime',
        'h' => 'help',
    ]
);
if ($options['help']) {
    echo 'Usage: php check_environment.php --runtime=8.3|8.4' . PHP_EOL;
    exit(0);
}
if ($unrecognised !== []) {
    $bootstrapfail('arguments_unrecognised');
}

$expectedruntime = (string) $options['runtime'];
if (!in_array($expectedruntime, ['8.3', '8.4'], true)) {
    $bootstrapfail('runtime_invalid');
}
$actualruntime = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
$expectedprofile = $expectedruntime === '8.3' ? 'php83' : 'php84';
$sourcecommit = getenv('GITHUB_SHA');
$sourcecommit = is_string($sourcecommit) && preg_match('/^[a-f0-9]{40}$/', $sourcecommit)
    ? $sourcecommit
    : null;

$checknames = [
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
$checks = array_fill_keys($checknames, 'failed');
$failurecode = null;
$pluginversion = 0;
$moodleversion = max(1, (int) get_config('', 'version'));
$moodlebranch = (string) get_config('', 'branch');

$mark = static function (string $name, bool $passed) use (&$checks, &$failurecode): void {
    $checks[$name] = $passed ? 'passed' : 'failed';
    if (!$passed && $failurecode === null) {
        $failurecode = 'readiness_' . $name;
    }
};
$validmeeting = static function (?object $meeting): bool {
    return $meeting !== null
        && ($meeting->integrationmode ?? null) === 'managed'
        && (int) ($meeting->owneruserid ?? 0) > 0
        && (int) ($meeting->oauthissuerid ?? 0) > 0
        && trim((string) ($meeting->calendarid ?? '')) !== ''
        && (int) ($meeting->timestart ?? 0) > 0
        && (int) ($meeting->timeend ?? 0) > (int) ($meeting->timestart ?? 0);
};
$issuerready = static function (int $issuerid): bool {
    if ($issuerid <= 0) {
        return false;
    }
    try {
        $issuer = \core\oauth2\api::get_issuer($issuerid);
        if (!$issuer->get('enabled') || !$issuer->is_configured()) {
            return false;
        }
        $authorizationurl = $issuer->get_endpoint_url('authorization');
        $parts = is_string($authorizationurl) ? parse_url($authorizationurl) : false;

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && ($parts['host'] ?? null) === 'accounts.google.com';
    } catch (\Throwable) {
        return false;
    }
};
$treefingerprint = static function (string $root): ?string {
    $root = realpath($root);
    if ($root === false || !is_dir($root)) {
        return null;
    }
    $files = [];
    $walk = static function (string $directory) use (&$walk, &$files, $root): bool {
        $entries = scandir($directory);
        if ($entries === false) {
            return false;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '.git') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_link($path)) {
                return false;
            }
            if (is_dir($path)) {
                if (!$walk($path)) {
                    return false;
                }
                continue;
            }
            if (!is_file($path) || !is_readable($path)) {
                return false;
            }
            $relative = substr($path, strlen($root) + 1);
            $hash = hash_file('sha256', $path);
            if ($relative === false || $hash === false) {
                return false;
            }
            $files[$relative] = $hash;
        }

        return true;
    };
    if (!$walk($root)) {
        return null;
    }
    ksort($files);
    $manifest = json_encode($files, JSON_UNESCAPED_SLASHES);

    return is_string($manifest) ? hash('sha256', $manifest) : null;
};

ob_start();
try {
    $mark('cli_runtime', $actualruntime === $expectedruntime);
    $mark(
        'acceptance_switch',
        ($CFG->mod_googlemeet_workspace_acceptance ?? false) === true
    );
    $mark(
        'config_profile',
        ($CFG->mod_googlemeet_workspace_acceptance_profile ?? null) === $expectedprofile
    );
    $mark(
        'database_profile',
        get_config('googlemeet', 'workspaceacceptanceprofile') === $expectedprofile
    );
    $mark('outbound_mail_disabled', ($CFG->noemailever ?? false) === true);

    $siteparts = parse_url((string) ($CFG->wwwroot ?? ''));
    $mark(
        'https_site',
        is_array($siteparts) && ($siteparts['scheme'] ?? null) === 'https'
    );
    $mark('moodle_52', $moodlebranch === '502');

    $plugin = new \stdClass();
    require(__DIR__ . '/../../../version.php');
    $pluginversion = max(1, (int) ($plugin->version ?? 0));
    $sourceroot = realpath(__DIR__ . '/../../..');
    $installedroot = realpath($CFG->dirroot . '/mod/googlemeet');
    $sourcefingerprint = is_string($sourceroot) ? $treefingerprint($sourceroot) : null;
    $installedfingerprint = is_string($installedroot) ? $treefingerprint($installedroot) : null;
    $mark(
        'source_installed',
        (int) get_config('mod_googlemeet', 'version') === $pluginversion
            && $sourcefingerprint !== null
            && hash_equals($sourcefingerprint, (string) $installedfingerprint)
    );

    $calendarissuerid = (int) get_config('googlemeet', 'issuerid');
    $recordingissuerid = (int) get_config('googlemeet', 'recordingissuerid');
    $mark('calendar_issuer', $issuerready($calendarissuerid));
    $mark('recording_issuer', $issuerready($recordingissuerid));
    $mark(
        'issuer_separation',
        $calendarissuerid > 0
            && $recordingissuerid > 0
            && $calendarissuerid !== $recordingissuerid
    );

    $table = new xmldb_table('googlemeet');
    $fixtures = [];
    if ($DB->get_manager()->table_exists($table)) {
        foreach (['Single', 'Recurring', 'Guests', 'Recording'] as $suffix) {
            $fixtures[$suffix] = $DB->get_record(
                'googlemeet',
                ['name' => '[ACCEPTANCE] ' . $suffix],
                '*',
                IGNORE_MISSING
            ) ?: null;
        }
    }

    $single = $fixtures['Single'] ?? null;
    $recurring = $fixtures['Recurring'] ?? null;
    $guests = $fixtures['Guests'] ?? null;
    $recording = $fixtures['Recording'] ?? null;
    $fixtureownerid = (int) ($single->owneruserid ?? 0);
    $mark(
        'single_fixture',
        $validmeeting($single)
            && (int) ($single->oauthissuerid ?? 0) === $calendarissuerid
            && trim((string) ($single->recurrence ?? '')) === ''
    );
    $mark(
        'recurring_fixture',
        $validmeeting($recurring)
            && (int) ($recurring->owneruserid ?? 0) === $fixtureownerid
            && (int) ($recurring->oauthissuerid ?? 0) === $calendarissuerid
            && trim((string) ($recurring->recurrence ?? '')) !== ''
    );
    $mark(
        'guest_fixture',
        $validmeeting($guests)
            && (int) ($guests->owneruserid ?? 0) === $fixtureownerid
            && (int) ($guests->oauthissuerid ?? 0) === $calendarissuerid
            && ($guests->guestpolicy ?? null) === 'course'
    );
    $mark(
        'recording_fixture',
        $validmeeting($recording)
            && (int) ($recording->owneruserid ?? 0) === $fixtureownerid
            && (int) ($recording->oauthissuerid ?? 0) === $calendarissuerid
            && (int) ($recording->recordingowneruserid ?? 0) === $fixtureownerid
            && (int) ($recording->recordingoauthissuerid ?? 0) === $recordingissuerid
    );
} catch (\Throwable) {
    $failurecode ??= 'readiness_internal_error';
} finally {
    ob_end_clean();
}

$result = in_array('failed', $checks, true) ? 'failed' : 'passed';
if ($result === 'passed') {
    $failurecode = null;
}
$evidence = [
    'schema' => 1,
    'result' => $result,
    'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
    'source_commit' => $sourcecommit,
    'plugin_version' => $pluginversion,
    'moodle_version' => $moodleversion,
    'moodle_branch' => $moodlebranch,
    'php_runtime' => $actualruntime,
    'site_profile' => $expectedprofile,
    'checks' => $checks,
    'failure_code' => $failurecode,
];

echo json_encode(
    $evidence,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
) . PHP_EOL;
exit($result === 'passed' ? 0 : 1);
