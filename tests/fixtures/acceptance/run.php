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
 * Executes one opt-in Google Workspace acceptance scenario.
 *
 * This fixture is intentionally CLI-only. It reads OAuth grants from Moodle's
 * normal owner-scoped storage and prints only closed, sanitized JSON evidence.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_googlemeet\api\google_calendar_client;
use mod_googlemeet\api\moodle_oauth_http_client;
use mod_googlemeet\local\calendar_adapter_factory;
use mod_googlemeet\local\calendar_catalog;
use mod_googlemeet\local\meeting_manager;
use mod_googlemeet\local\oauth_manager;
use mod_googlemeet\local\recording_discovery_factory;
use mod_googlemeet\local\recording_manager;
use mod_googlemeet\local\recording_oauth_manager;
use mod_googlemeet\local\recording_repository;
use mod_googlemeet\local\recording_sync_state;
use mod_googlemeet\local\sync_repository;
use mod_googlemeet\local\sync_state;
use mod_googlemeet\local\workspace_acceptance;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

define('CLI_SCRIPT', true);
define('NO_DEBUG_DISPLAY', true);

$bootstrapfail = static function (string $code): never {
    fwrite(STDERR, 'Google Workspace acceptance bootstrap failed: ' . $code . PHP_EOL);
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

$help = <<<'HELP'
Execute one Google Workspace acceptance scenario against a dedicated Moodle tenant.

Options:
  --campaign=SLUG             Shared campaign slug, for example rc1-202607.
  --case=ID                   Runbook case from GW-01 through GW-14.
  --meetingid=ID              Dedicated [ACCEPTANCE] activity instance ID.
  --scenario=NAME             snapshot, preflight, synchronise, poll, cancel or recordings.
  --expected-schedule=SHAPE   any, single or recurring.
  --expected-guests=COUNT     Expected Calendar guest count, or -1 to skip.
  --expected-recordings=COUNT Minimum local recording count, or -1 to skip.
  --timeout=SECONDS           Pending conference poll limit, from 30 to 300.
  --confirm=TEXT              MUTATE-GOOGLE-WORKSPACE for synchronise or cancel.
  --help                      Show this help.

The site config.php must set:
  $CFG->mod_googlemeet_workspace_acceptance = true;

MOODLE_CONFIG must contain the absolute path to that site's config.php.
HELP;

[$options, $unrecognised] = cli_get_params(
    [
        'meetingid' => null,
        'campaign' => null,
        'case' => null,
        'scenario' => workspace_acceptance::SCENARIO_SNAPSHOT,
        'expected-schedule' => workspace_acceptance::SCHEDULE_ANY,
        'expected-guests' => -1,
        'expected-recordings' => -1,
        'timeout' => 180,
        'confirm' => '',
        'help' => false,
    ],
    [
        'm' => 'meetingid',
        's' => 'scenario',
        'h' => 'help',
    ]
);

if ($options['help']) {
    echo $help . PHP_EOL;
    exit(0);
}
if ($unrecognised !== []) {
    $bootstrapfail('arguments_unrecognised');
}

$meetingid = filter_var($options['meetingid'], FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$campaignid = (string) $options['campaign'];
$caseid = (string) $options['case'];
$scenario = (string) $options['scenario'];
$expectedschedule = (string) $options['expected-schedule'];
$expectedguests = filter_var($options['expected-guests'], FILTER_VALIDATE_INT);
$expectedrecordings = filter_var($options['expected-recordings'], FILTER_VALIDATE_INT);
$timeout = filter_var($options['timeout'], FILTER_VALIDATE_INT);
$confirmation = (string) $options['confirm'];

if ($meetingid === false) {
    $bootstrapfail('meeting_id_invalid');
}
try {
    workspace_acceptance::assert_campaign_case($campaignid, $caseid, $scenario);
} catch (\Throwable) {
    $bootstrapfail('campaign_case_invalid');
}
if (!in_array($expectedschedule, workspace_acceptance::schedules(), true)) {
    $bootstrapfail('schedule_invalid');
}
if ($expectedguests === false || $expectedguests < -1 || $expectedguests > 200) {
    $bootstrapfail('expected_guests_invalid');
}
if ($expectedrecordings === false || $expectedrecordings < -1 || $expectedrecordings > 10000) {
    $bootstrapfail('expected_recordings_invalid');
}
if ($timeout === false || $timeout < 30 || $timeout > 300) {
    $bootstrapfail('timeout_invalid');
}

$repository = new sync_repository();
$recordingrepository = new recording_repository();
$meeting = null;
$recordingcount = null;
$checks = [];
$result = 'failed';
$failurecode = null;
$pluginversion = max(1, (int) get_config('mod_googlemeet', 'version'));
$moodleversion = max(1, (int) get_config('', 'version'));
$moodlebranch = (string) get_config('', 'branch');
$phpruntime = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
$sourcecommit = getenv('GITHUB_SHA');
$sourcecommit = is_string($sourcecommit) ? $sourcecommit : null;

// Production managers use mtrace(). Buffer their output so evidence remains one
// closed JSON document and no provider-derived debugging text can reach logs.
ob_start();
try {
    if (($CFG->mod_googlemeet_workspace_acceptance ?? false) !== true) {
        throw new coding_exception('The dedicated Google Workspace acceptance switch is disabled.');
    }
    $checks['dedicated_tenant'] = 'passed';

    workspace_acceptance::assert_mutation_confirmation($scenario, $confirmation);
    $meeting = $repository->get((int) $meetingid);
    if (!str_starts_with((string) $meeting->name, workspace_acceptance::ACTIVITY_PREFIX)) {
        throw new coding_exception('The selected activity is not marked for acceptance.');
    }
    $checks['activity_marker'] = 'passed';

    $ownerid = (int) ($meeting->owneruserid ?? 0);
    $owner = $ownerid > 0
        ? $DB->get_record('user', [
            'id' => $ownerid,
            'deleted' => 0,
            'suspended' => 0,
        ], '*', IGNORE_MISSING)
        : false;
    if (!$owner) {
        throw new coding_exception('The acceptance owner is unavailable.');
    }
    \core\session\manager::set_user($owner);
    workspace_acceptance::assert_activity($meeting, (int) $USER->id, $expectedschedule);
    $checks['owner_context'] = 'passed';
    $checks['schedule_shape'] = 'passed';

    $calendarpreflight = static function (\stdClass $activity): void {
        $oauthclient = (new oauth_manager())->authenticated_client($activity);
        $catalog = new calendar_catalog(
            new google_calendar_client(new moodle_oauth_http_client($oauthclient))
        );
        $catalog->require_writable((string) $activity->calendarid);
    };
    $meetingprocessor = static function (int $activityid): void {
        (new meeting_manager(
            calendaradapterprovider: new calendar_adapter_factory()
        ))->process($activityid);
    };

    switch ($scenario) {
        case workspace_acceptance::SCENARIO_SNAPSHOT:
            $checks['local_snapshot'] = 'passed';
            break;

        case workspace_acceptance::SCENARIO_PREFLIGHT:
            $calendarpreflight($meeting);
            $checks['calendar_oauth'] = 'passed';
            $checks['writable_calendar'] = 'passed';
            break;

        case workspace_acceptance::SCENARIO_SYNCHRONISE:
            $calendarpreflight($meeting);
            $checks['calendar_oauth'] = 'passed';
            $checks['writable_calendar'] = 'passed';

            if (!in_array($meeting->syncstatus, [
                sync_state::QUEUED,
                sync_state::SYNCING,
                sync_state::PENDING,
            ], true)) {
                if (!in_array($meeting->syncstatus, [
                    sync_state::DRAFT,
                    sync_state::READY,
                    sync_state::FAILED,
                    sync_state::DISCONNECTED,
                ], true)) {
                    throw new coding_exception('The meeting cannot be synchronized from its current state.');
                }
                $repository->transition((int) $meetingid, sync_state::QUEUED);
            }

            $meetingprocessor((int) $meetingid);
            $meeting = $repository->get((int) $meetingid);
            if (!in_array($meeting->syncstatus, [sync_state::PENDING, sync_state::READY], true)) {
                $checks['calendar_operation'] = 'failed';
                $checks['meet_conference'] = 'failed';
                $failurecode = (string) (
                    $meeting->lasterrorcode
                    ?? 'calendar_operation_failed'
                );
                throw new coding_exception('Calendar synchronization did not reach a supported state.');
            }
            $checks['calendar_operation'] = 'passed';
            $checks['meet_conference'] = $meeting->syncstatus === sync_state::PENDING
                ? 'pending'
                : 'passed';
            break;

        case workspace_acceptance::SCENARIO_POLL:
            $calendarpreflight($meeting);
            $checks['calendar_oauth'] = 'passed';
            $checks['writable_calendar'] = 'passed';
            if (!in_array($meeting->syncstatus, [sync_state::PENDING, sync_state::READY], true)) {
                throw new coding_exception('Conference polling requires a pending or ready meeting.');
            }

            $deadline = time() + $timeout;
            while ($meeting->syncstatus === sync_state::PENDING) {
                $meetingprocessor((int) $meetingid);
                $meeting = $repository->get((int) $meetingid);
                if ($meeting->syncstatus !== sync_state::PENDING || time() >= $deadline) {
                    break;
                }
                sleep(min(15, max(1, $deadline - time())));
            }
            if ($meeting->syncstatus !== sync_state::READY) {
                $checks['meet_conference'] = $meeting->syncstatus === sync_state::PENDING
                    ? 'pending'
                    : 'failed';
                $failurecode = $meeting->syncstatus === sync_state::PENDING
                    ? 'conference_pending_timeout'
                    : (string) ($meeting->lasterrorcode ?? 'calendar_poll_failed');
                throw new coding_exception('Conference creation did not complete within the bounded poll.');
            }
            $checks['calendar_operation'] = 'passed';
            $checks['meet_conference'] = 'passed';
            break;

        case workspace_acceptance::SCENARIO_CANCEL:
            $calendarpreflight($meeting);
            $checks['calendar_oauth'] = 'passed';
            $checks['writable_calendar'] = 'passed';
            if (empty($meeting->googleeventid)) {
                throw new coding_exception('Cancellation requires a persisted Calendar event.');
            }
            if ($meeting->syncstatus !== sync_state::CANCELLED) {
                if ($meeting->syncstatus !== sync_state::CANCELLING) {
                    $repository->transition((int) $meetingid, sync_state::CANCELLING);
                }
                $meetingprocessor((int) $meetingid);
                $meeting = $repository->get((int) $meetingid);
            }
            if ($meeting->syncstatus !== sync_state::CANCELLED) {
                $checks['calendar_operation'] = 'failed';
                $failurecode = (string) (
                    $meeting->lasterrorcode
                    ?? 'calendar_cancellation_failed'
                );
                throw new coding_exception('Calendar cancellation did not settle.');
            }
            $checks['calendar_operation'] = 'passed';
            break;

        case workspace_acceptance::SCENARIO_RECORDINGS:
            if (
                $meeting->syncstatus !== sync_state::READY
                || empty($meeting->meetingcode)
                || (int) ($meeting->recordingowneruserid ?? 0) !== (int) $USER->id
                || (int) ($meeting->recordingoauthissuerid ?? 0) <= 0
            ) {
                throw new coding_exception('Recording discovery is not bound to the ready meeting owner.');
            }
            (new recording_oauth_manager())->authenticated_client($meeting);
            $checks['recording_oauth'] = 'passed';

            if (!in_array($meeting->recordingsyncstatus, [
                recording_sync_state::QUEUED,
                recording_sync_state::SYNCING,
            ], true)) {
                $recordingrepository->transition((int) $meetingid, recording_sync_state::QUEUED);
            }
            (new recording_manager(
                provider: new recording_discovery_factory()
            ))->process((int) $meetingid);
            $meeting = $repository->get((int) $meetingid);
            if ($meeting->recordingsyncstatus !== recording_sync_state::READY) {
                $checks['recording_discovery'] = 'failed';
                $failurecode = (string) (
                    $meeting->recordinglasterrorcode
                    ?? 'recording_discovery_failed'
                );
                throw new coding_exception('Recording discovery did not settle.');
            }
            $checks['recording_discovery'] = 'passed';
            break;
    }

    $meeting = $repository->get((int) $meetingid);
    $recordingcount = $DB->count_records('googlemeet_recordings', [
        'googlemeetid' => (int) $meetingid,
    ]);

    if ($expectedguests >= 0) {
        if ((int) $meeting->guestcount !== $expectedguests) {
            $checks['guest_reconciliation'] = 'failed';
            $failurecode = 'guest_count_mismatch';
            throw new coding_exception('The sanitized Calendar guest count did not match.');
        }
        $checks['guest_reconciliation'] = 'passed';
    }
    if ($expectedrecordings >= 0) {
        if ($recordingcount < $expectedrecordings) {
            $checks['recording_discovery'] = 'failed';
            $failurecode = 'recording_count_below_expected';
            throw new coding_exception('The sanitized recording count did not reach the minimum.');
        }
        $checks['recording_discovery'] = 'passed';
    }

    $result = 'passed';
} catch (\Throwable $exception) {
    $failurecode = $failurecode ?? workspace_acceptance::exception_code($exception);
    try {
        $meeting = $repository->get((int) $meetingid);
        $recordingcount = $DB->count_records('googlemeet_recordings', [
            'googlemeetid' => (int) $meetingid,
        ]);
    } catch (\Throwable) {
        $meeting = null;
        $recordingcount = null;
    }
} finally {
    ob_end_clean();
}

$evidence = workspace_acceptance::evidence(
    $campaignid,
    $caseid,
    $scenario,
    $result,
    (int) $meetingid,
    $expectedschedule,
    $checks,
    $meeting,
    $recordingcount,
    $pluginversion,
    $moodleversion,
    $moodlebranch,
    $phpruntime,
    time(),
    $sourcecommit,
    $failurecode
);

echo json_encode(
    $evidence,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
) . PHP_EOL;
exit($result === 'passed' ? 0 : 1);
