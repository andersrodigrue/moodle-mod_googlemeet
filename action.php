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
 * Handles owner-scoped managed meeting commands.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_googlemeet\api\calendar_authorization_exception;
use mod_googlemeet\local\integration_mode;
use mod_googlemeet\local\meeting_manager;
use mod_googlemeet\local\oauth_manager;
use mod_googlemeet\local\privacy_lifecycle;
use mod_googlemeet\local\sync_state;

require(__DIR__ . '/../../config.php');

$cmid = required_param('id', PARAM_INT);
$action = required_param('action', PARAM_ALPHA);

$cm = get_coursemodule_from_id('googlemeet', $cmid, 0, false, MUST_EXIST);
$course = get_course($cm->course);
$meeting = $DB->get_record('googlemeet', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
require_sesskey();
$context = context_module::instance($cm->id);
require_capability('mod/googlemeet:managemeeting', $context);

$returnurl = new moodle_url('/mod/googlemeet/view.php', ['id' => $cm->id]);
if (
    $meeting->integrationmode !== integration_mode::MANAGED ||
    (int) ($meeting->owneruserid ?? 0) !== (int) $USER->id
) {
    throw new required_capability_exception(
        $context,
        'mod/googlemeet:managemeeting',
        'nopermissions',
        ''
    );
}

$manager = new meeting_manager();
switch ($action) {
    case 'retry':
        if (
            $meeting->syncstatus !== sync_state::FAILED &&
            !(
                $meeting->syncstatus === sync_state::CANCELLING &&
                !empty($meeting->lasterrorcode)
            )
        ) {
            throw new moodle_exception('invalidsyncaction', 'mod_googlemeet');
        }
        if ($meeting->syncstatus === sync_state::CANCELLING) {
            $manager->cancel((int) $meeting->id, (int) $USER->id);
        } else {
            $manager->queue((int) $meeting->id, (int) $USER->id);
        }
        redirect($returnurl, get_string('syncactionqueued', 'mod_googlemeet'), null, 'success');
        break;

    case 'cancel':
        if (!in_array($meeting->syncstatus, [
            sync_state::DRAFT,
            sync_state::QUEUED,
            sync_state::SYNCING,
            sync_state::PENDING,
            sync_state::READY,
            sync_state::FAILED,
            sync_state::CANCELLING,
        ], true)) {
            throw new moodle_exception('invalidsyncaction', 'mod_googlemeet');
        }
        $manager->cancel((int) $meeting->id, (int) $USER->id);
        redirect($returnurl, get_string('synccancelqueued', 'mod_googlemeet'), null, 'success');
        break;

    case 'reconnect':
        $iscancellationauthfailure = $meeting->syncstatus === sync_state::CANCELLING
            && ($meeting->lasterrorcode ?? null) === 'authorization_required';
        if ($meeting->syncstatus !== sync_state::DISCONNECTED && !$iscancellationauthfailure) {
            throw new moodle_exception('invalidsyncaction', 'mod_googlemeet');
        }
        try {
            $client = (new oauth_manager())->authorization_client(
                (int) $meeting->oauthissuerid,
                (int) $USER->id
            );
        } catch (calendar_authorization_exception $e) {
            throw new moodle_exception('managedoauthunavailable', 'mod_googlemeet', '', null, $e);
        }
        if (!$client->is_logged_in()) {
            redirect(new moodle_url($client->get_login_url()));
        }
        if ($iscancellationauthfailure) {
            $manager->cancel((int) $meeting->id, (int) $USER->id);
        } else {
            $manager->queue((int) $meeting->id, (int) $USER->id);
        }
        redirect($returnurl, get_string('syncactionqueued', 'mod_googlemeet'), null, 'success');
        break;

    case 'disconnect':
        (new privacy_lifecycle())->disconnect_calendar((int) $meeting->id, (int) $USER->id);
        redirect($returnurl, get_string('syncdisconnected', 'mod_googlemeet'), null, 'success');
        break;

    default:
        throw new moodle_exception('invalidsyncaction', 'mod_googlemeet');
}
