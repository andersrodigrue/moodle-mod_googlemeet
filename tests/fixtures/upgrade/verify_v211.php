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
 * Verifies the CI database after Moodle's real v2.1.1 upgrade.
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
    fwrite(STDERR, 'Google Meet v2.1.1 verification failed: ' . $message . PHP_EOL);
    exit(1);
};
$expect = static function (bool $condition, string $message) use ($fail): void {
    if (!$condition) {
        $fail($message);
    }
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

$currentversion = \mod_googlemeet\local\upgrade\compatibility_contract::CURRENT_VERSION;
$expect(
    (int) get_config('mod_googlemeet', 'version') === $currentversion,
    'the plugin version did not reach ' . $currentversion . '.'
);

$fixturejson = get_config('mod_googlemeet', 'ciupgradefixture');
$expect(is_string($fixturejson) && $fixturejson !== '', 'the seed manifest is missing.');
try {
    $fixture = json_decode($fixturejson, true, 32, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    $fail('the seed manifest is invalid JSON.');
}
$expect(is_array($fixture), 'the seed manifest is not an array.');

$linked = $DB->get_record(
    'googlemeet',
    ['id' => $fixture['linkedmeetingid']],
    '*',
    MUST_EXIST
);
$manual = $DB->get_record(
    'googlemeet',
    ['id' => $fixture['manualmeetingid']],
    '*',
    MUST_EXIST
);

$expect($linked->integrationmode === 'legacy', 'the linked activity is not legacy.');
$expect($linked->syncstatus === 'disconnected', 'the linked activity is not disconnected.');
$expect($linked->eventid === 'legacy-calendar-link', 'the legacy Calendar link was lost.');
$expect($linked->creatoremail === 'legacy-owner@example.com', 'the legacy organizer was lost.');
$expect($linked->meetinguri === $fixture['linkedurl'], 'the linked Meet URI was not preserved.');
$expect($linked->googleeventid === null, 'eventid was incorrectly promoted to googleeventid.');
$expect($linked->owneruserid === null, 'the upgrade invented a meeting owner.');
$expect($linked->oauthissuerid === null, 'the upgrade invented an OAuth issuer.');
$expect($linked->recordingowneruserid === null, 'the upgrade invented a recording owner.');
$expect($linked->recordingoauthissuerid === null, 'the upgrade invented a recording OAuth issuer.');
$expect($linked->recordingsyncstatus === 'disconnected', 'recording discovery is not disconnected.');
$expect((int) $linked->eventdate === $fixture['day'], 'the legacy event date changed.');
$expect((int) $linked->starthour === 10, 'the legacy start hour changed.');
$expect((int) $linked->startminute === 15, 'the legacy start minute changed.');
$expect((int) $linked->endhour === 11, 'the legacy end hour changed.');
$expect((int) $linked->endminute === 30, 'the legacy end minute changed.');
$expect((int) $linked->addmultiply === 1, 'the legacy recurrence flag changed.');
$expect($linked->days === json_encode(['Mon' => 1]), 'the legacy recurrence weekdays changed.');
$expect((int) $linked->period === 1, 'the legacy recurrence interval changed.');
$expect(
    (int) $linked->eventenddate === $fixture['day'] + 14 * DAYSECS,
    'the legacy recurrence end changed.'
);
$expect((int) $linked->timecreated === $fixture['timemodified'], 'timecreated was not migrated.');
$expect((int) $linked->timestart === $fixture['firststart'], 'the canonical start is incorrect.');
$expect(
    (int) $linked->timeend === $fixture['firststart'] + 75 * MINSECS,
    'the canonical end is incorrect.'
);
$expect(
    $linked->timezone === \core_date::get_server_timezone(),
    'the server timezone was not assigned.'
);
$expect(
    $linked->recurrence ===
        "RRULE:FREQ=WEEKLY;COUNT=1\nRDATE:" . gmdate('Ymd\THis\Z', $fixture['secondstart']),
    'the canonical recurrence is incorrect or contains a duplicate RDATE.'
);
$expect($linked->sendupdates === 'none', 'legacy attendee updates were not disabled.');
$expect($linked->guestpolicy === 'none', 'legacy attendee management was not disabled.');

$expect($manual->integrationmode === 'manual', 'the URL-only activity is not manual.');
$expect($manual->syncstatus === 'ready', 'the URL-only activity is not ready.');
$expect($manual->eventid === null, 'the manual activity acquired a legacy event ID.');
$expect($manual->meetinguri === $fixture['manualurl'], 'the manual Meet URI was not preserved.');
$expect((int) $manual->timestart === $fixture['manualstart'], 'the field-only start is incorrect.');
$expect(
    (int) $manual->timeend === $fixture['manualstart'] + 75 * MINSECS,
    'the field-only end is incorrect.'
);

$events = array_values($DB->get_records(
    'googlemeet_events',
    ['googlemeetid' => $linked->id],
    'eventdate ASC, id ASC'
));
$expect(count($events) === 2, 'duplicate legacy occurrences were not consolidated.');
$expect(
    [(int) $events[0]->id, (int) $events[1]->id] ===
        [$fixture['firsteventid'], $fixture['secondeventid']],
    'the stable occurrence rows were not preserved.'
);
$expect(
    [(int) $events[0]->eventdate, (int) $events[1]->eventdate] ===
        [$fixture['firststart'], $fixture['secondstart']],
    'the occurrence dates changed.'
);
foreach ($events as $event) {
    $expect(
        $event->occurrencekey === hash('sha256', 'v1:' . (int) $event->eventdate),
        'an occurrence key is not canonical.'
    );
}
$expect(
    [(int) $events[0]->calendareventid, (int) $events[1]->calendareventid] ===
        [$fixture['firstcalendareventid'], $fixture['secondcalendareventid']],
    'legacy Moodle Calendar rows were not associated with their occurrences.'
);
$expect(
    $DB->count_records('event', [
        'modulename' => 'googlemeet',
        'instance' => $linked->id,
        'eventtype' => 'googlemeet_event',
    ]) === 2,
    'legacy Moodle Calendar rows were lost or duplicated.'
);
$expect(
    !$DB->record_exists('googlemeet_events', ['id' => $fixture['duplicateeventid']]),
    'the duplicate occurrence still exists.'
);

$receipts = array_values($DB->get_records(
    'googlemeet_notify_done',
    ['eventid' => $fixture['firsteventid']],
    'userid ASC, id ASC'
));
$expect(count($receipts) === 2, 'reminder receipts were lost or duplicated.');
$expectedreceiptusers = [(int) $fixture['guestid'], (int) $fixture['adminid']];
sort($expectedreceiptusers);
$expect(
    array_map(static fn(stdClass $receipt): int => (int) $receipt->userid, $receipts) ===
        $expectedreceiptusers,
    'the distinct reminder recipients were not preserved.'
);
$expect(
    $DB->record_exists('googlemeet_notify_done', ['id' => $fixture['firstreceiptid']]),
    'the original reminder receipt was removed.'
);
$expect(
    !$DB->record_exists('googlemeet_notify_done', ['id' => $fixture['duplicatereceiptid']]),
    'the duplicate reminder receipt still exists.'
);
$expect(
    $DB->record_exists('googlemeet_notify_done', ['id' => $fixture['movedreceiptid']]),
    'the distinct reminder receipt was not reassigned.'
);

$expect(
    $DB->count_records('googlemeet_recordings', ['googlemeetid' => $linked->id]) === 2,
    'duplicate recording references were not consolidated.'
);
$expect(
    $DB->record_exists('googlemeet_recordings', ['id' => $fixture['firstrecordingid']]),
    'the first recording reference was not preserved.'
);
$expect(
    !$DB->record_exists('googlemeet_recordings', ['id' => $fixture['duplicaterecordingid']]),
    'the duplicate recording reference still exists.'
);
$expect(
    $DB->record_exists('googlemeet_recordings', ['id' => $fixture['distinctrecordingid']]),
    'the distinct recording reference was lost.'
);

$xml = simplexml_load_file($CFG->dirroot . '/mod/googlemeet/db/install.xml');
$expect($xml instanceof SimpleXMLElement, 'install.xml could not be loaded.');
$dbman = $DB->get_manager();
$activitytable = new xmldb_table('googlemeet');
$expectedtables = [];
foreach ($xml->TABLES->TABLE as $tablexml) {
    $tablename = (string) $tablexml['NAME'];
    $expectedtables[] = $tablename;
    $table = new xmldb_table($tablename);
    $expect($dbman->table_exists($table), 'missing table ' . $tablename . '.');

    $expectedfields = [];
    foreach ($tablexml->FIELDS->FIELD as $fieldxml) {
        $expectedfields[] = (string) $fieldxml['NAME'];
    }
    $actualfields = array_keys($DB->get_columns($tablename));
    sort($expectedfields);
    sort($actualfields);
    $expect($expectedfields === $actualfields, 'field drift in ' . $tablename . '.');

    foreach ($tablexml->INDEXES->INDEX ?? [] as $indexxml) {
        $indexfields = preg_split('/,\s*/', (string) $indexxml['FIELDS']);
        $expect(is_array($indexfields), 'invalid index fields in ' . $tablename . '.');
        $index = new xmldb_index(
            (string) $indexxml['NAME'],
            (string) $indexxml['UNIQUE'] === 'true'
                ? XMLDB_INDEX_UNIQUE
                : XMLDB_INDEX_NOTUNIQUE,
            $indexfields
        );
        $expect(
            $dbman->index_exists($table, $index),
            'missing index ' . $tablename . '.' . (string) $indexxml['NAME'] . '.'
        );
    }
}
$actualtables = array_values(array_filter(
    $DB->get_tables(),
    static fn(string $tablename): bool => str_starts_with($tablename, 'googlemeet')
));
sort($expectedtables);
sort($actualtables);
$expect($expectedtables === $actualtables, 'the upgraded and fresh-install table sets differ.');

$eventcolumns = $DB->get_columns('googlemeet_events');
$expect(!empty($eventcolumns['occurrencekey']->not_null), 'occurrencekey is not required.');
$expect(empty($eventcolumns['occurrencekey']->has_default), 'occurrencekey retained a temporary default.');

foreach (\mod_googlemeet\local\upgrade\compatibility_contract::retained_activity_fields() as $field => $policy) {
    $expect(
        $dbman->field_exists($activitytable, new xmldb_field($field)),
        'retained compatibility field ' . $field . ' is missing.'
    );
    $expect($policy['removalcondition'] !== '', 'field ' . $field . ' has no removal gate.');
}
$expect($DB->count_records('googlemeet_calendar_guests') === 0, 'the upgrade invented Calendar guests.');
$expect($DB->count_records('googlemeet_diagnostics') === 0, 'the upgrade invented diagnostics.');
$tasklike = $DB->sql_like('classname', ':classname', false);
$expect(
    $DB->count_records_select('task_adhoc', $tasklike, ['classname' => '%mod_googlemeet%']) === 0,
    'the upgrade queued a Google Meet ad hoc task.'
);

mtrace(
    'Verified v2.1.1 -> 3.0.0-dev upgrade: schema, activities, occurrences, '
    . 'receipts and recordings are consistent.'
);
