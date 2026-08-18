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
 * Authenticated recording synchronization commands.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_googlemeet\local\recording_manager;
use mod_googlemeet\local\recording_oauth_manager;
use mod_googlemeet\local\privacy_lifecycle;

require(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);
$action = required_param('action', PARAM_ALPHA);
$cm = get_coursemodule_from_id('googlemeet', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$googlemeet = $DB->get_record('googlemeet', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
require_sesskey();
$context = context_module::instance($cm->id);
require_capability('mod/googlemeet:syncgoogledrive', $context);

$returnurl = new moodle_url('/mod/googlemeet/view.php', ['id' => $cm->id]);
if (!in_array($action, ['sync', 'disconnect'], true)) {
    throw new invalid_parameter_exception(get_string('recordingsinvalidaction', 'mod_googlemeet'));
}

$existingowner = (int) ($googlemeet->recordingowneruserid ?? 0);
if ($existingowner > 0 && $existingowner !== (int) $USER->id) {
    throw new moodle_exception('recordingowneronly', 'mod_googlemeet');
}

if ($action === 'disconnect') {
    (new privacy_lifecycle())->disconnect_recordings((int) $googlemeet->id, (int) $USER->id);
    redirect(
        $returnurl,
        get_string('recordingsdisconnected', 'mod_googlemeet'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$issuerid = recording_oauth_manager::configured_issuer_id();
if ($issuerid <= 0) {
    throw new moodle_exception('recordingsoauthunavailable', 'mod_googlemeet');
}

$oauthmanager = new recording_oauth_manager();
$oauthclient = $oauthmanager->authorization_client($issuerid, (int) $USER->id, (int) $googlemeet->id);
if (!$oauthclient->is_logged_in()) {
    redirect($oauthclient->get_login_url());
}

(new recording_manager())->claim_and_queue((int) $googlemeet->id, (int) $USER->id, $issuerid);
redirect($returnurl, get_string('recordingsqueued', 'mod_googlemeet'), null, \core\output\notification::NOTIFY_SUCCESS);
