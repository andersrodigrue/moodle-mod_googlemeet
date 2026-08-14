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
 * Private googlemeet module utility functions
 *
 * @package     mod_googlemeet
 * @copyright   2020 Rone Santos <ronefel@hotmail.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use mod_googlemeet\output\recording_panel;

require_once("$CFG->dirroot/mod/googlemeet/lib.php");

/**
 * Print googlemeet header.
 * @param object $googlemeet
 * @param object $cm
 * @param object $course
 * @return void
 */
function googlemeet_print_header($googlemeet, $cm, $course) {
    global $PAGE, $OUTPUT;

    $PAGE->set_title($course->shortname . ': ' . $googlemeet->name);
    $PAGE->set_heading($course->fullname);
    $PAGE->set_activity_record($googlemeet);
    echo $OUTPUT->header();
}

/**
 * Print googlemeet heading.
 * @param object $googlemeet
 * @param object $cm
 * @param object $course
 * @param bool $notused This variable is no longer used.
 * @return void
 */
function googlemeet_print_heading($googlemeet, $cm, $course, $notused = false) {
    global $OUTPUT;
    echo $OUTPUT->heading(format_string($googlemeet->name), 2);
}

/**
 * Print googlemeet introduction.
 * @param object $googlemeet
 * @param object $cm
 * @param object $course
 * @param bool $ignoresettings print even if not specified in modedit
 * @return void
 */
function googlemeet_print_intro($googlemeet, $cm, $course, $ignoresettings = false) {
    global $OUTPUT;

    $options = empty($googlemeet->displayoptions) ? array() : unserialize($googlemeet->displayoptions);
    if ($ignoresettings || !empty($options['printintro'])) {
        if (trim(strip_tags($googlemeet->intro))) {
            echo $OUTPUT->box_start('mod_introbox', 'googlemeetintro');
            echo format_module_intro('googlemeet', $googlemeet, $cm->id);
            echo $OUTPUT->box_end();
        }
    }
}

/**
 * Renders recording references and owner-scoped discovery controls.
 *
 * @param \stdClass $googlemeet Activity record.
 * @param \stdClass $cm Course module record.
 * @param \context_module $context Activity context.
 * @return void
 */
function googlemeet_print_recordings(
    \stdClass $googlemeet,
    \stdClass $cm,
    \context_module $context
): void {
    global $CFG, $OUTPUT, $PAGE, $USER;

    $params = ['googlemeetid' => $googlemeet->id];
    $caneditrecordings = has_capability('mod/googlemeet:editrecording', $context);
    $canremoverecordings = has_capability('mod/googlemeet:removerecording', $context);
    $cansyncrecordings = has_capability('mod/googlemeet:syncgoogledrive', $context);
    if (!$caneditrecordings) {
        $params['visible'] = true;
    }

    $recordings = googlemeet_list_recordings($params);
    $panel = new recording_panel(
        $googlemeet,
        (int) $cm->id,
        $recordings,
        $caneditrecordings,
        $canremoverecordings,
        $cansyncrecordings,
        (int) $USER->id
    );

    echo $OUTPUT->render($panel);

    if ($panel->has_recordings()) {
        if ($panel->requires_jstable()) {
            $PAGE->requires->js(
                new moodle_url($CFG->wwwroot . '/mod/googlemeet/assets/js/build/jstable.min.js')
            );
        }
        $PAGE->requires->js_call_amd(
            'mod_googlemeet/recordings',
            'init',
            [$panel->javascript_config()]
        );
    }
}

/**
 * This clears the url.
 *
 * @param string $url
 * @return mixed The url if valid or false if invalid
 */
function googlemeet_clear_url($url) {
    $pattern = "/meet.google.com\/[a-zA-Z0-9]{3}-[a-zA-Z0-9]{4}-[a-zA-Z0-9]{3}/";
    preg_match($pattern, $url, $matches, PREG_OFFSET_CAPTURE);

    if ($matches) {
        return 'https://' . $matches[0][0];
    }

    return null;
}

/**
 * This checks if have recordings from the googlemeet.
 *
 * @param int $googlemeetid
 * @return boolean
 */
function googlemeet_has_recording($googlemeetid) {
    global $DB;

    $recordings = $DB->get_records('googlemeet_recordings', ['googlemeetid' => $googlemeetid]);

    return $recordings ? true : false;
}

/**
 * Generates a list of users who have not yet been notified.
 *
 * @param int $eventid the event ID
 * @return stdClass list of users
 */
function googlemeet_get_users_to_notify($eventid) {
    global $DB;

    $event = $DB->get_record('googlemeet_events', ['id' => (int) $eventid]);
    if ($event === false) {
        return [];
    }
    $meeting = $DB->get_record('googlemeet', ['id' => (int) $event->googlemeetid]);
    if ($meeting === false) {
        return [];
    }
    $cm = get_coursemodule_from_instance(
        'googlemeet',
        (int) $meeting->id,
        (int) $meeting->course,
        false,
        IGNORE_MISSING
    );
    if ($cm === false) {
        return [];
    }
    $context = context_module::instance((int) $cm->id);
    $users = get_enrolled_users(
        $context,
        'mod/googlemeet:receivenotification',
        0,
        'u.*',
        'u.id ASC',
        0,
        0,
        true
    );
    $notified = $DB->get_fieldset_select(
        'googlemeet_notify_done',
        'userid',
        'eventid = :eventid',
        ['eventid' => (int) $eventid]
    );
    foreach ($notified as $userid) {
        unset($users[(int) $userid]);
    }

    return $users;
}

/**
 * Returns a list of future events
 */
function googlemeet_get_future_events() {
    global $DB;

    $now = time();

    $sql = "SELECT DISTINCT
                   me.id,
                   me.eventdate,
                   me.duration,
                   m.id AS googlemeetid,
                   m.name AS googlemeetname,
                   m.url,
                   cm.id AS cmid,
                   c.id AS courseid,
                   c.fullname AS coursename
              FROM {googlemeet_events} me
        INNER JOIN {googlemeet} m
                ON m.id = me.googlemeetid
        INNER JOIN {course_modules} cm
                ON (cm.instance = m.id AND cm.visible = 1 AND cm.deletioninprogress = 0)
        INNER JOIN {course} c
                ON (c.id = cm.course AND c.visible = 1)
        INNER JOIN {modules} md
                ON (md.id = cm.module AND md.name = 'googlemeet')
             WHERE :nowafter >= me.eventdate - m.minutesbefore * 60
               AND :nowbefore <= me.eventdate
               AND m.notify = :notify
          ORDER BY me.eventdate ASC, me.id ASC";

    return $DB->get_records_sql($sql, [
        'nowafter' => $now,
        'nowbefore' => $now,
        'notify' => 1,
    ], 0, 100);
}

/**
 * Send a notification to students in the class about the event.
 *
 * @param object $user
 * @param object $event
 * @return int|false Message ID, or false when delivery failed.
 */
function googlemeet_send_notification($user, $event) {
    global $CFG;

    $startdate = userdate($event->eventdate, get_string('strftimedmy', 'googlemeet'), $user->timezone);
    $starttime = userdate($event->eventdate, get_string('strftimehm', 'googlemeet'), $user->timezone);
    $endtime = userdate($event->eventdate + $event->duration, get_string('strftimehm', 'googlemeet'), $user->timezone);
    $usertimezone = usertimezone($user->timezone);
    $notificationstr = get_string('notification', 'googlemeet');
    $subject = "{$notificationstr}: {$event->googlemeetname} - {$startdate} {$starttime} - {$endtime} ($usertimezone)";
    $url = $CFG->wwwroot . '/mod/googlemeet/view.php?id=' . $event->cmid;

    $message = new \core\message\message();
    $message->component = 'mod_googlemeet';
    $message->name = 'notification';
    $message->userfrom = core_user::get_noreply_user();
    $message->userto = $user;
    $message->subject = $subject;
    $message->fullmessage = googlemeet_get_messagehtml($user, $event);
    $message->fullmessageformat = FORMAT_MARKDOWN;
    $message->fullmessagehtml = googlemeet_get_messagehtml($user, $event);
    $message->smallmessage = $subject;
    $message->notification = 1;
    $message->contexturl = $url;
    $message->contexturlname = $event->googlemeetname;
    $message->courseid = $event->courseid;

    return message_send($message);
}

/**
 * Records the sending of the notification to not send repeated.
 *
 * @param int $userid
 * @param int $eventid
 * @return int Receipt ID.
 */
function googlemeet_notify_done($userid, $eventid) {
    global $DB;

    $existing = $DB->get_field('googlemeet_notify_done', 'id', [
        'userid' => (int) $userid,
        'eventid' => (int) $eventid,
    ]);
    if ($existing !== false) {
        return (int) $existing;
    }

    $notifydone = (object) [
        'userid' => (int) $userid,
        'eventid' => (int) $eventid,
        'timesent' => time(),
    ];

    try {
        return $DB->insert_record('googlemeet_notify_done', $notifydone);
    } catch (dml_write_exception $exception) {
        // A concurrent cron worker may have stored the same receipt first.
        return $DB->get_field(
            'googlemeet_notify_done',
            'id',
            ['userid' => (int) $userid, 'eventid' => (int) $eventid],
            MUST_EXIST
        );
    }
}

/**
 * Removes records of past event notification notifications.
 */
function googlemeet_remove_notify_done_from_old_events() {
    global $DB;

    $now = time();

    $DB->delete_records_select(
        'googlemeet_notify_done',
        'eventid IN (SELECT id FROM {googlemeet_events} WHERE eventdate < :now)',
        ['now' => $now]
    );
}

/**
 * Mount the body content of the notification.
 *
 * @param object $user db record of user
 * @param object $event db record of event
 * @return string - the content of the notification after assembly.
 */
function googlemeet_get_messagehtml($user, $event) {
    global $CFG;

    $config = get_config('googlemeet');

    $startdate = userdate($event->eventdate, get_string('strftimedmy', 'googlemeet'), $user->timezone);
    $starttime = userdate($event->eventdate, get_string('strftimehm', 'googlemeet'), $user->timezone);
    $endtime = userdate($event->eventdate + $event->duration, get_string('strftimehm', 'googlemeet'), $user->timezone);
    $url = "<a href=\"{$CFG->wwwroot}/mod/googlemeet/view.php?id={$event->cmid}\">
        {$CFG->wwwroot}/mod/googlemeet/view.php?id={$event->cmid}</a>";

    $templatevars = [
        '/%userfirstname%/' => $user->firstname,
        '/%userlastname%/' => $user->lastname,
        '/%coursename%/' => $event->coursename,
        '/%googlemeetname%/' => $event->googlemeetname,
        '/%eventdate%/' => $startdate,
        '/%duration%/' => $starttime . ' – ' . $endtime,
        '/%timezone%/' => usertimezone($user->timezone),
        '/%url%/' => $url,
        '/%cmid%/' => $event->cmid,
    ];

    $patterns = array_keys($templatevars); // The placeholders which are to be replaced.

    $replacements = array_values($templatevars); // The values which are to be templated in for the placeholders.

    // Replace %variable% with relevant value everywhere it occurs.
    $emailcontent = preg_replace($patterns, $replacements, $config->emailcontent);

    return $emailcontent;
}

/**
 * upcoming googlemeet events.
 *
 * @param int $googlemeetid db record of user
 */
function googlemeet_get_upcoming_events($googlemeetid) {
    global $DB, $USER;

    $now = time() - MINSECS;
    $events = $DB->get_records_select(
        'googlemeet_events',
        'googlemeetid = :googlemeetid AND eventdate >= :now',
        [
            'googlemeetid' => (int) $googlemeetid,
            'now' => $now,
        ],
        'eventdate ASC, id ASC',
        'id, eventdate, duration',
        0,
        5
    );
    $upcomingevents = [];

    if ($events) {
        foreach ($events as $event) {
            $start = $event->eventdate;
            $nowdate = userdate(time(), '%Y-%m-%d', $USER->timezone);
            $startdate = userdate($start, '%Y-%m-%d', $USER->timezone);

            $upcomingevent = new stdClass();
            $upcomingevent->today = $nowdate === $startdate;
            $upcomingevent->startdate = userdate($start, get_string('strftimedm', 'googlemeet'), $USER->timezone);
            array_push($upcomingevents, $upcomingevent);
        }

        $firstevent = reset($events);

        return [
            'hasupcomingevents' => true,
            'upcomingevents' => $upcomingevents,
            'starttime' => userdate(
                (int) $firstevent->eventdate,
                get_string('strftimehm', 'googlemeet'),
                $USER->timezone
            ),
            'endtime' => userdate(
                (int) $firstevent->eventdate + (int) $firstevent->duration,
                get_string('strftimehm', 'googlemeet'),
                $USER->timezone
            ),
            'duration' => (int) $firstevent->duration,
        ];
    }

    return false;
}
