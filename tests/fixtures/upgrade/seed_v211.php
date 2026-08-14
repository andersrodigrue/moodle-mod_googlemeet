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
 * Seeds a real v2.1.1 installation for the CI upgrade rehearsal.
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

$fail = static function (string $message): void {
    fwrite(STDERR, 'Google Meet v2.1.1 seed failed: ' . $message . PHP_EOL);
    exit(1);
};

$configpath = getenv('MOODLE_CONFIG');
if (!is_string($configpath) || trim($configpath) === '') {
    $fail('MOODLE_CONFIG is not set.');
}
$configpath = realpath($configpath);
if ($configpath === false || !is_file($configpath)) {
    $fail('MOODLE_CONFIG does not identify a readable config.php.');
}

require($configpath);

if ((int) get_config('mod_googlemeet', 'version') !== 2023050101) {
    $fail('the installed plugin is not v2.1.1 / 2023050101.');
}

$dbman = $DB->get_manager();
$activitytable = new xmldb_table('googlemeet');
if (!$dbman->table_exists($activitytable)) {
    $fail('the legacy googlemeet table is missing.');
}
if (!$dbman->field_exists($activitytable, new xmldb_field('eventid'))) {
    $fail('the v2.1.1 eventid field is missing.');
}
if ($dbman->field_exists($activitytable, new xmldb_field('integrationmode'))) {
    $fail('the database already contains the modern schema.');
}
foreach (['googlemeet_calendar_guests', 'googlemeet_diagnostics'] as $tablename) {
    if ($dbman->table_exists(new xmldb_table($tablename))) {
        $fail('unexpected modern table ' . $tablename . ' exists before the upgrade.');
    }
}
if ($DB->count_records('googlemeet') !== 0) {
    $fail('the rehearsal database is not empty.');
}

$adminid = (int) $DB->get_field('user', 'id', ['username' => 'admin'], MUST_EXIST);
$guestid = (int) $DB->get_field('user', 'id', ['username' => 'guest'], MUST_EXIST);
$day = gmmktime(0, 0, 0, 8, 3, 2026);
$firststart = $day + 10 * HOURSECS + 15 * MINSECS;
$secondstart = $firststart + 7 * DAYSECS;
$timemodified = $day - DAYSECS;
$linkedurl = 'https://meet.google.com/abc-defg-hij';
$manualurl = 'https://meet.google.com/xyz-abcd-efg';

$linkedmeetingid = $DB->insert_record('googlemeet', (object) [
    'course' => SITEID,
    'name' => 'CI legacy linked meeting',
    'originalname' => 'CI legacy linked meeting',
    'url' => $linkedurl,
    'creatoremail' => 'legacy-owner@example.com',
    'intro' => '',
    'introformat' => FORMAT_HTML,
    'lastsync' => null,
    'eventdate' => $day,
    'starthour' => 10,
    'startminute' => 15,
    'endhour' => 11,
    'endminute' => 30,
    'addmultiply' => 1,
    'days' => json_encode(['Mon' => 1]),
    'period' => 1,
    'eventenddate' => $day + 14 * DAYSECS,
    'notify' => 1,
    'minutesbefore' => 15,
    'timemodified' => $timemodified,
    'eventid' => 'legacy-calendar-link',
]);
$manualmeetingid = $DB->insert_record('googlemeet', (object) [
    'course' => SITEID,
    'name' => 'CI legacy manual meeting',
    'originalname' => 'CI legacy manual meeting',
    'url' => $manualurl,
    'creatoremail' => null,
    'intro' => '',
    'introformat' => FORMAT_HTML,
    'lastsync' => null,
    'eventdate' => $day,
    'starthour' => 14,
    'startminute' => 30,
    'endhour' => 15,
    'endminute' => 45,
    'addmultiply' => 0,
    'days' => null,
    'period' => null,
    'eventenddate' => null,
    'notify' => 0,
    'minutesbefore' => 0,
    'timemodified' => $timemodified,
    'eventid' => null,
]);

$firsteventid = $DB->insert_record('googlemeet_events', (object) [
    'googlemeetid' => $linkedmeetingid,
    'eventdate' => $firststart,
    'duration' => 75 * MINSECS,
    'timemodified' => $timemodified,
]);
$duplicateeventid = $DB->insert_record('googlemeet_events', (object) [
    'googlemeetid' => $linkedmeetingid,
    'eventdate' => $firststart,
    'duration' => 75 * MINSECS,
    'timemodified' => $timemodified,
]);
$secondeventid = $DB->insert_record('googlemeet_events', (object) [
    'googlemeetid' => $linkedmeetingid,
    'eventdate' => $secondstart,
    'duration' => 75 * MINSECS,
    'timemodified' => $timemodified,
]);

require_once($CFG->dirroot . '/calendar/lib.php');
$calendarproperties = [
    'eventtype' => 'googlemeet_event',
    'type' => CALENDAR_EVENT_TYPE_ACTION,
    'name' => 'CI legacy linked meeting',
    'description' => '',
    'format' => FORMAT_HTML,
    'courseid' => SITEID,
    'groupid' => 0,
    'userid' => 0,
    'modulename' => 'googlemeet',
    'instance' => $linkedmeetingid,
    'timeduration' => 75 * MINSECS,
    'visible' => 1,
    'priority' => null,
];
$firstcalendarevent = calendar_event::create((object) ($calendarproperties + [
    'timestart' => $firststart,
    'timesort' => $firststart,
]), false);
$secondcalendarevent = calendar_event::create((object) ($calendarproperties + [
    'timestart' => $secondstart,
    'timesort' => $secondstart,
]), false);

$firstreceiptid = $DB->insert_record('googlemeet_notify_done', (object) [
    'eventid' => $firsteventid,
    'userid' => $adminid,
    'timesent' => $firststart - HOURSECS,
]);
$duplicatereceiptid = $DB->insert_record('googlemeet_notify_done', (object) [
    'eventid' => $duplicateeventid,
    'userid' => $adminid,
    'timesent' => $firststart - HOURSECS,
]);
$movedreceiptid = $DB->insert_record('googlemeet_notify_done', (object) [
    'eventid' => $duplicateeventid,
    'userid' => $guestid,
    'timesent' => $firststart - HOURSECS,
]);

$firstrecordingid = $DB->insert_record('googlemeet_recordings', (object) [
    'googlemeetid' => $linkedmeetingid,
    'recordingid' => 'DriveFile_duplicate123',
    'name' => 'Recording kept by upgrade',
    'createdtime' => $firststart,
    'duration' => '1:15:00',
    'webviewlink' => 'https://drive.google.com/file/d/DriveFile_duplicate123/view',
    'visible' => 1,
    'timemodified' => $timemodified,
]);
$duplicaterecordingid = $DB->insert_record('googlemeet_recordings', (object) [
    'googlemeetid' => $linkedmeetingid,
    'recordingid' => 'DriveFile_duplicate123',
    'name' => 'Recording removed by upgrade',
    'createdtime' => $firststart,
    'duration' => '1:15:00',
    'webviewlink' => 'https://drive.google.com/file/d/DriveFile_duplicate123/view',
    'visible' => 1,
    'timemodified' => $timemodified,
]);
$distinctrecordingid = $DB->insert_record('googlemeet_recordings', (object) [
    'googlemeetid' => $linkedmeetingid,
    'recordingid' => 'DriveFile_distinct456',
    'name' => 'Distinct recording',
    'createdtime' => $secondstart,
    'duration' => '1:15:00',
    'webviewlink' => 'https://drive.google.com/file/d/DriveFile_distinct456/view',
    'visible' => 0,
    'timemodified' => $timemodified,
]);

$fixture = [
    'linkedmeetingid' => $linkedmeetingid,
    'manualmeetingid' => $manualmeetingid,
    'linkedurl' => $linkedurl,
    'manualurl' => $manualurl,
    'day' => $day,
    'timemodified' => $timemodified,
    'firststart' => $firststart,
    'secondstart' => $secondstart,
    'manualstart' => $day + 14 * HOURSECS + 30 * MINSECS,
    'firsteventid' => $firsteventid,
    'duplicateeventid' => $duplicateeventid,
    'secondeventid' => $secondeventid,
    'firstcalendareventid' => (int) $firstcalendarevent->id,
    'secondcalendareventid' => (int) $secondcalendarevent->id,
    'firstreceiptid' => $firstreceiptid,
    'duplicatereceiptid' => $duplicatereceiptid,
    'movedreceiptid' => $movedreceiptid,
    'firstrecordingid' => $firstrecordingid,
    'duplicaterecordingid' => $duplicaterecordingid,
    'distinctrecordingid' => $distinctrecordingid,
    'adminid' => $adminid,
    'guestid' => $guestid,
];
set_config('ciupgradefixture', json_encode($fixture, JSON_THROW_ON_ERROR), 'mod_googlemeet');

mtrace(
    'Seeded v2.1.1 upgrade fixture: 2 activities, 3 occurrences, '
    . '2 Moodle Calendar events, 3 receipts and 3 recording rows.'
);
