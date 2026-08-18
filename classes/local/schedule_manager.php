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

namespace mod_googlemeet\local;

use calendar_event;
use mod_googlemeet\helper;

/**
 * Reconciles canonical meeting fields with local occurrences and Calendar.
 *
 * Existing occurrence IDs are retained when their canonical start timestamp
 * is unchanged. This preserves reminder receipts and prevents duplicate
 * notifications after an unrelated activity edit.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class schedule_manager {

    /** @var schedule_expander Canonical recurrence expander. */
    private schedule_expander $expander;

    /**
     * @param schedule_expander|null $expander Recurrence expander.
     */
    public function __construct(?schedule_expander $expander = null) {
        $this->expander = $expander ?? new schedule_expander();
    }

    /**
     * Reconciles one activity's occurrence rows and Moodle Calendar events.
     *
     * @param \stdClass $meeting Activity record.
     */
    public function synchronise(\stdClass $meeting): void {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/calendar/lib.php');

        if (empty($meeting->id) || (int) $meeting->id <= 0) {
            throw new \invalid_parameter_exception('A positive Google Meet activity ID is required.');
        }

        $cm = $this->course_module($meeting);
        $meeting->coursemodule = $cm->id;
        $occurrences = $this->expander->expand($meeting);
        $existing = $DB->get_records('googlemeet_events', [
            'googlemeetid' => (int) $meeting->id,
        ]);
        $bykey = [];
        foreach ($existing as $record) {
            $key = trim((string) ($record->occurrencekey ?? ''));
            if ($key === '') {
                $key = (new schedule_occurrence(
                    (int) $record->eventdate,
                    (int) $record->eventdate + (int) $record->duration
                ))->key();
            }
            $bykey[$key] = $record;
        }

        $calendarevents = $this->calendar_events((int) $meeting->id);
        $now = time();
        foreach ($occurrences as $occurrence) {
            $key = $occurrence->key();
            $record = $bykey[$key] ?? null;
            if ($record === null) {
                $record = (object) [
                    'googlemeetid' => (int) $meeting->id,
                    'occurrencekey' => $key,
                    'eventdate' => $occurrence->timestart,
                    'duration' => $occurrence->duration(),
                    'calendareventid' => null,
                    'timemodified' => $now,
                ];
                $record->id = $DB->insert_record('googlemeet_events', $record);
            } else {
                unset($bykey[$key]);
                $changed = (int) $record->eventdate !== $occurrence->timestart
                    || (int) $record->duration !== $occurrence->duration()
                    || (string) ($record->occurrencekey ?? '') !== $key;
                if ($changed) {
                    $record->eventdate = $occurrence->timestart;
                    $record->duration = $occurrence->duration();
                    $record->occurrencekey = $key;
                    $record->timemodified = $now;
                    $DB->update_record('googlemeet_events', $record);
                }
            }

            $calendarevent = $this->take_calendar_event($record, $calendarevents);
            $properties = $this->calendar_properties($meeting, $occurrence, $cm);
            if ($calendarevent === null) {
                $calendarevent = calendar_event::create($properties, false);
            } else {
                $calendarevent->update($properties, false);
            }
            if ((int) ($record->calendareventid ?? 0) !== (int) $calendarevent->id) {
                $DB->set_field(
                    'googlemeet_events',
                    'calendareventid',
                    (int) $calendarevent->id,
                    ['id' => (int) $record->id]
                );
            }
        }

        foreach ($bykey as $obsolete) {
            $this->delete_occurrence($obsolete, $calendarevents);
        }
        foreach ($calendarevents as $orphan) {
            calendar_event::load((int) $orphan->id)->delete(false);
        }
    }

    /**
     * Deletes local occurrences, reminder receipts and Calendar events.
     *
     * @param int $googlemeetid Activity ID.
     */
    public function delete(int $googlemeetid): void {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/calendar/lib.php');

        if ($googlemeetid <= 0) {
            throw new \invalid_parameter_exception('A positive Google Meet activity ID is required.');
        }

        $calendarevents = $this->calendar_events($googlemeetid);
        $events = $DB->get_records('googlemeet_events', ['googlemeetid' => $googlemeetid]);
        foreach ($events as $event) {
            $this->delete_occurrence($event, $calendarevents);
        }
        foreach ($calendarevents as $orphan) {
            calendar_event::load((int) $orphan->id)->delete(false);
        }
    }

    /**
     * Resolves the activity course module.
     *
     * @param \stdClass $meeting Activity record.
     * @return \cm_info|\stdClass
     */
    private function course_module(\stdClass $meeting): \cm_info|\stdClass {
        if (!empty($meeting->coursemodule)) {
            return (object) ['id' => (int) $meeting->coursemodule];
        }

        return get_coursemodule_from_instance(
            'googlemeet',
            (int) $meeting->id,
            (int) $meeting->course,
            false,
            MUST_EXIST
        );
    }

    /**
     * Loads this activity's Calendar events.
     *
     * @param int $googlemeetid Activity ID.
     * @return array<int, \stdClass>
     */
    private function calendar_events(int $googlemeetid): array {
        global $DB;

        return $DB->get_records('event', [
            'modulename' => 'googlemeet',
            'instance' => $googlemeetid,
            'eventtype' => helper::GOOGLEMEET_EVENT_START,
        ]);
    }

    /**
     * Selects the linked or legacy time-matched Calendar event.
     *
     * @param \stdClass $record Local occurrence record.
     * @param array<int, \stdClass> $calendarevents Remaining Calendar rows.
     * @return calendar_event|null
     */
    private function take_calendar_event(\stdClass $record, array &$calendarevents): ?calendar_event {
        $calendarid = (int) ($record->calendareventid ?? 0);
        if ($calendarid > 0 && isset($calendarevents[$calendarid])) {
            unset($calendarevents[$calendarid]);
            return calendar_event::load($calendarid);
        }

        foreach ($calendarevents as $id => $event) {
            if ((int) $event->timestart === (int) $record->eventdate) {
                unset($calendarevents[$id]);
                return calendar_event::load((int) $id);
            }
        }

        return null;
    }

    /**
     * Builds Calendar API properties for one occurrence.
     *
     * @param \stdClass $meeting Activity.
     * @param schedule_occurrence $occurrence Canonical occurrence.
     * @param \cm_info|\stdClass $cm Course module.
     * @return \stdClass
     */
    private function calendar_properties(
        \stdClass $meeting,
        schedule_occurrence $occurrence,
        \cm_info|\stdClass $cm
    ): \stdClass {
        return (object) [
            'eventtype' => helper::GOOGLEMEET_EVENT_START,
            'type' => CALENDAR_EVENT_TYPE_ACTION,
            'name' => get_string('calendareventname', 'mod_googlemeet', $meeting->name),
            'description' => format_module_intro('googlemeet', $meeting, (int) $cm->id, false),
            'format' => FORMAT_HTML,
            'courseid' => (int) $meeting->course,
            'groupid' => 0,
            'userid' => 0,
            'modulename' => 'googlemeet',
            'instance' => (int) $meeting->id,
            'component' => 'mod_googlemeet',
            'timestart' => $occurrence->timestart,
            'timeduration' => $occurrence->duration(),
            'timesort' => $occurrence->timestart,
            'visible' => instance_is_visible('googlemeet', $meeting),
            'priority' => null,
        ];
    }

    /**
     * Deletes one occurrence and any remaining linked Calendar event.
     *
     * @param \stdClass $record Local occurrence.
     * @param array<int, \stdClass> $calendarevents Remaining Calendar rows.
     */
    private function delete_occurrence(\stdClass $record, array &$calendarevents): void {
        global $DB;

        $DB->delete_records('googlemeet_notify_done', ['eventid' => (int) $record->id]);
        $calendarid = (int) ($record->calendareventid ?? 0);
        if ($calendarid > 0 && isset($calendarevents[$calendarid])) {
            calendar_event::load($calendarid)->delete(false);
            unset($calendarevents[$calendarid]);
        }
        $DB->delete_records('googlemeet_events', ['id' => (int) $record->id]);
    }
}
