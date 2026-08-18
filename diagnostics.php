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

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use mod_googlemeet\local\diagnostic_recorder;
use mod_googlemeet\local\diagnostic_repository;

admin_externalpage_setup('mod_googlemeet_diagnostics');

$operation = optional_param('operation', '', PARAM_ALPHANUMEXT);
$outcome = optional_param('outcome', '', PARAM_ALPHANUMEXT);
$googlemeetid = optional_param('googlemeetid', 0, PARAM_INT);
if (!in_array($operation, array_merge([''], diagnostic_recorder::operations()), true)) {
    $operation = '';
}
if (!in_array($outcome, array_merge([''], diagnostic_recorder::outcomes()), true)) {
    $outcome = '';
}
$googlemeetid = max(0, $googlemeetid);

$repository = new diagnostic_repository();
$records = $repository->latest($operation, $outcome, $googlemeetid);
$counts = $repository->outcome_counts_since(time() - DAYSECS);

$PAGE->set_url(new moodle_url('/mod/googlemeet/diagnostics.php', [
    'operation' => $operation,
    'outcome' => $outcome,
    'googlemeetid' => $googlemeetid ?: null,
]));
$PAGE->set_title(get_string('diagnosticspagetitle', 'mod_googlemeet'));
$PAGE->set_heading(get_string('diagnosticspagetitle', 'mod_googlemeet'));

echo $OUTPUT->header();
echo $OUTPUT->notification(
    get_string('diagnosticsprivacyboundary', 'mod_googlemeet', diagnostic_repository::retention_days()),
    \core\output\notification::NOTIFY_INFO
);

$summary = new html_table();
$summary->caption = get_string('diagnosticssummary24h', 'mod_googlemeet');
$summary->head = [
    get_string('diagnosticsoutcome', 'mod_googlemeet'),
    get_string('diagnosticscount', 'mod_googlemeet'),
];
$summary->data = [];
foreach (diagnostic_recorder::outcomes() as $summaryoutcome) {
    $summary->data[] = [
        get_string('diagnosticsoutcome_' . $summaryoutcome, 'mod_googlemeet'),
        (string) $counts[$summaryoutcome],
    ];
}
echo html_writer::table($summary);

$operationoptions = ['' => get_string('all')];
foreach (diagnostic_recorder::operations() as $option) {
    $operationoptions[$option] = get_string('diagnosticsoperation_' . $option, 'mod_googlemeet');
}
$outcomeoptions = ['' => get_string('all')];
foreach (diagnostic_recorder::outcomes() as $option) {
    $outcomeoptions[$option] = get_string('diagnosticsoutcome_' . $option, 'mod_googlemeet');
}

echo html_writer::start_tag('form', [
    'method' => 'get',
    'action' => (new moodle_url('/mod/googlemeet/diagnostics.php'))->out(false),
    'class' => 'd-flex flex-wrap align-items-end gap-3 mb-4',
]);
echo html_writer::start_div();
echo html_writer::label(
    get_string('diagnosticsoperation', 'mod_googlemeet'),
    'diagnostics-operation',
    false,
    ['class' => 'form-label']
);
echo html_writer::select($operationoptions, 'operation', $operation, false, [
    'id' => 'diagnostics-operation',
    'class' => 'form-select',
]);
echo html_writer::end_div();
echo html_writer::start_div();
echo html_writer::label(
    get_string('diagnosticsoutcome', 'mod_googlemeet'),
    'diagnostics-outcome',
    false,
    ['class' => 'form-label']
);
echo html_writer::select($outcomeoptions, 'outcome', $outcome, false, [
    'id' => 'diagnostics-outcome',
    'class' => 'form-select',
]);
echo html_writer::end_div();
echo html_writer::start_div();
echo html_writer::label(
    get_string('diagnosticsactivityid', 'mod_googlemeet'),
    'diagnostics-activity',
    false,
    ['class' => 'form-label']
);
echo html_writer::empty_tag('input', [
    'type' => 'number',
    'name' => 'googlemeetid',
    'id' => 'diagnostics-activity',
    'class' => 'form-control',
    'min' => 1,
    'value' => $googlemeetid ?: '',
]);
echo html_writer::end_div();
echo html_writer::start_div();
echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'class' => 'btn btn-primary',
    'value' => get_string('filter'),
]);
echo html_writer::end_div();
echo html_writer::end_tag('form');

$table = new html_table();
$table->caption = get_string('diagnosticslatest', 'mod_googlemeet', diagnostic_repository::VIEW_LIMIT);
$table->head = [
    get_string('diagnosticstime', 'mod_googlemeet'),
    get_string('course'),
    get_string('activity'),
    get_string('diagnosticsoperation', 'mod_googlemeet'),
    get_string('diagnosticsoutcome', 'mod_googlemeet'),
    get_string('diagnosticssource', 'mod_googlemeet'),
    get_string('diagnosticscode', 'mod_googlemeet'),
];
$table->data = [];
foreach ($records as $record) {
    $coursecontext = context_course::instance((int) $record->courseid);
    $coursename = format_string($record->coursename, true, ['context' => $coursecontext]);
    $course = html_writer::link(
        new moodle_url('/course/view.php', ['id' => $record->courseid]),
        $coursename
    );
    $activityname = format_string($record->activityname, true, ['context' => $coursecontext]);
    $activity = $record->cmid
        ? html_writer::link(new moodle_url('/mod/googlemeet/view.php', ['id' => $record->cmid]), $activityname)
        : $activityname;
    $table->data[] = [
        userdate($record->timecreated),
        $course,
        $activity,
        get_string('diagnosticsoperation_' . $record->operation, 'mod_googlemeet'),
        get_string('diagnosticsoutcome_' . $record->outcome, 'mod_googlemeet'),
        get_string('diagnosticssource_' . $record->source, 'mod_googlemeet'),
        $record->diagnosticcode === null ? '—' : s($record->diagnosticcode),
    ];
}
if ($table->data === []) {
    $cell = new html_table_cell(get_string('diagnosticsnone', 'mod_googlemeet'));
    $cell->colspan = 7;
    $table->data[] = [$cell];
}
echo html_writer::table($table);
echo $OUTPUT->footer();
