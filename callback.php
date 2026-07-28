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
 * Oauth client instance callback script
 *
 * @package    mod_googlemeet
 * @copyright  2023 Rone Santos <ronefel@hotmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_googlemeet\local\oauth_manager;
use mod_googlemeet\local\recording_manager;
use mod_googlemeet\local\recording_oauth_manager;

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();

// Headers to make it not cacheable.
header('Cache-Control: no-cache, must-revalidate');
header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');

// Wait as long as it takes for this script to finish.
core_php_time_limit::raise();

if (optional_param('managed', 0, PARAM_BOOL)) {
    require_sesskey();

    $issuerid = required_param('issuerid', PARAM_INT);
    if ($issuerid <= 0 || $issuerid !== (int) get_config('googlemeet', 'issuerid')) {
        throw new moodle_exception('managedoauthunavailable', 'mod_googlemeet');
    }

    $oauthclient = (new oauth_manager())->authorization_client($issuerid, (int) $USER->id);
    if (!$oauthclient->is_logged_in()) {
        throw new moodle_exception('managedoauthfailed', 'mod_googlemeet');
    }

    $PAGE->set_url('/mod/googlemeet/callback.php', [
        'managed' => 1,
        'issuerid' => $issuerid,
    ]);
    $PAGE->set_context(context_system::instance());
    $PAGE->set_pagelayout('popup');
    $PAGE->set_title(get_string('managedoauthconnected', 'mod_googlemeet'));
    $PAGE->requires->js_init_code(
        'if (window.opener) { window.opener.location.reload(); window.close(); }'
    );

    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('managedoauthconnected', 'mod_googlemeet'), 'success');
    echo html_writer::tag('p', get_string('managedoauthclose', 'mod_googlemeet'));
    echo $OUTPUT->footer();
    exit;
}

if (optional_param('recordings', 0, PARAM_BOOL)) {
    require_sesskey();

    $googlemeetid = required_param('googlemeetid', PARAM_INT);
    $issuerid = required_param('issuerid', PARAM_INT);
    $configuredissuerid = recording_oauth_manager::configured_issuer_id();
    if ($issuerid <= 0 || $issuerid !== $configuredissuerid) {
        throw new moodle_exception('recordingsoauthunavailable', 'mod_googlemeet');
    }

    $googlemeet = $DB->get_record('googlemeet', ['id' => $googlemeetid], '*', MUST_EXIST);
    $course = $DB->get_record('course', ['id' => $googlemeet->course], '*', MUST_EXIST);
    $cm = get_coursemodule_from_instance('googlemeet', $googlemeetid, $course->id, false, MUST_EXIST);
    require_login($course, true, $cm);
    $context = context_module::instance($cm->id);
    require_capability('mod/googlemeet:syncgoogledrive', $context);

    $existingowner = (int) ($googlemeet->recordingowneruserid ?? 0);
    if ($existingowner > 0 && $existingowner !== (int) $USER->id) {
        throw new moodle_exception('recordingowneronly', 'mod_googlemeet');
    }

    $oauthclient = (new recording_oauth_manager())->authorization_client(
        $issuerid,
        (int) $USER->id,
        $googlemeetid
    );
    if (!$oauthclient->is_logged_in()) {
        throw new moodle_exception('recordingsoauthfailed', 'mod_googlemeet');
    }

    (new recording_manager())->claim_and_queue($googlemeetid, (int) $USER->id, $issuerid);

    $PAGE->set_url('/mod/googlemeet/callback.php', [
        'recordings' => 1,
        'googlemeetid' => $googlemeetid,
        'issuerid' => $issuerid,
    ]);
    $PAGE->set_context($context);
    $PAGE->set_pagelayout('popup');
    $PAGE->set_title(get_string('recordingsoauthconnected', 'mod_googlemeet'));
    $PAGE->requires->js_init_code(
        'if (window.opener) { window.opener.location.reload(); window.close(); }'
    );

    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('recordingsoauthconnected', 'mod_googlemeet'), 'success');
    echo html_writer::tag('p', get_string('managedoauthclose', 'mod_googlemeet'));
    echo $OUTPUT->footer();
    exit;
}

throw new moodle_exception('invalidrequest', 'error');
