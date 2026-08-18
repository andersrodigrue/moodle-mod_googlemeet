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
 * Revalidates the current access window before redirecting to Google Meet.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_googlemeet\local\meeting_access_policy;

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id = required_param('id', PARAM_INT);
$cm = get_coursemodule_from_id('googlemeet', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$googlemeet = $DB->get_record('googlemeet', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance((int) $cm->id);
require_capability('mod/googlemeet:view', $context);

$access = (new meeting_access_policy())->evaluate(
    $googlemeet,
    has_capability('mod/googlemeet:managemeeting', $context)
);
if (!$access->can_join()) {
    redirect(
        new moodle_url('/mod/googlemeet/view.php', ['id' => $cm->id]),
        $access->message(),
        null,
        $access->is_error()
            ? \core\output\notification::NOTIFY_ERROR
            : \core\output\notification::NOTIFY_INFO
    );
}

// Direct joins from overview/mobile still count as an activity view.
googlemeet_view($googlemeet, $course, $cm, $context);
redirect((string) $access->join_uri());
