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
 * Prints an instance of mod_googlemeet.
 *
 * @package     mod_googlemeet
 * @copyright   2020 Rone Santos <ronefel@hotmail.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_googlemeet\client;
use mod_googlemeet\local\integration_mode;
use mod_googlemeet\local\sync_state;
use mod_googlemeet\output\sync_status;

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/locallib.php');

$config = get_config('googlemeet');

$id = optional_param('id', 0, PARAM_INT);
$g = optional_param('g', 0, PARAM_INT);

if ($id) {
    $cm = get_coursemodule_from_id('googlemeet', $id, 0, false, MUST_EXIST);
    $course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
    $googlemeet = $DB->get_record('googlemeet', array('id' => $cm->instance), '*', MUST_EXIST);
} else if ($g) {
    $googlemeet = $DB->get_record('googlemeet', array('id' => $g), '*', MUST_EXIST);
    $course = $DB->get_record('course', array('id' => $googlemeet->course), '*', MUST_EXIST);
    $cm = get_coursemodule_from_instance('googlemeet', $googlemeet->id, $course->id, false, MUST_EXIST);
} else {
    throw new moodle_exception('missingidandcmid', 'mod_googlemeet');
}

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/googlemeet:view', $context);

$PAGE->set_url('/mod/googlemeet/view.php', array('id' => $cm->id));
$PAGE->set_context($context);

if (has_capability('mod/googlemeet:editrecording', $context)) {
    $client = new client();
    $logout = optional_param('logout', 0, PARAM_BOOL);
    if ($logout) {
        $client->logout();
    }
    $sync = optional_param('sync', 0, PARAM_BOOL);
    if ($sync) {
        $client->syncrecordings($googlemeet);
    }
}

// Prefer the validated managed URI while retaining the legacy URL fallback.
$url = trim((string) ($googlemeet->meetinguri ?: $googlemeet->url));
$pattern = "/^https:\/\/meet.google.com\/[-a-zA-Z0-9@:%._\+~#=]{3}-[-a-zA-Z0-9@:%._\+~#=]{4}-[-a-zA-Z0-9@:%._\+~#=]{3}$/";
$hasvalidurl = (bool) preg_match($pattern, $url);

// Completion and trigger events.
googlemeet_view($googlemeet, $course, $cm, $context);

googlemeet_print_header($googlemeet, $cm, $course);
googlemeet_print_heading($googlemeet, $cm, $course, true);
googlemeet_print_intro($googlemeet, $cm, $course, true);

if (
    $googlemeet->integrationmode !== integration_mode::MANUAL ||
    $googlemeet->syncstatus !== sync_state::READY
) {
    echo $OUTPUT->render(new sync_status(
        $googlemeet,
        has_capability('mod/googlemeet:editrecording', $context)
    ));
}

if ($googlemeet->syncstatus === sync_state::READY && $hasvalidurl) {
    echo html_writer::link(
        $url,
        get_string('entertheroom', 'googlemeet'),
        [
            'class' => 'btn btn-primary',
            'target' => '_blank',
            'rel' => 'noopener',
            'title' => get_string('entertheroom', 'googlemeet'),
        ]
    );
} else if ($googlemeet->syncstatus === sync_state::READY) {
    echo $OUTPUT->notification(get_string('invalidstoredurl', 'googlemeet'), 'error');
} else {
    echo $OUTPUT->notification(get_string('meetinglinknotready', 'googlemeet'), 'info');
}

if (has_capability('mod/googlemeet:editrecording', $context)) {
    $eventdetailsurl = null;
    if ($googlemeet->integrationmode === integration_mode::MANAGED) {
        $candidate = trim((string) ($googlemeet->googleeventhtmlurl ?? ''));
        $parts = $candidate === '' ? false : parse_url($candidate);
        if (
            is_array($parts) &&
            ($parts['scheme'] ?? null) === 'https' &&
            in_array(($parts['host'] ?? null), ['calendar.google.com', 'www.google.com'], true)
        ) {
            $eventdetailsurl = $candidate;
        }
    } else if (!empty($googlemeet->eventid)) {
        $eventdetailsurl = 'https://calendar.google.com/calendar/u/0/r/eventedit/'
            . rawurlencode((string) $googlemeet->eventid);
    }

    if ($eventdetailsurl !== null) {
        echo html_writer::link(
            $eventdetailsurl,
            get_string('eventdetails', 'googlemeet'),
            [
                'class' => 'btn btn-outline-primary ms-2',
                'target' => '_blank',
                'rel' => 'noopener',
                'title' => get_string('eventdetails', 'googlemeet'),
            ]
        );
    }
}

echo $OUTPUT->render_from_template('mod_googlemeet/upcomingevents', googlemeet_get_upcoming_events($googlemeet->id));

if ($hasvalidurl) {
    googlemeet_print_recordings($googlemeet, $cm, $context);
}

echo $OUTPUT->footer();
