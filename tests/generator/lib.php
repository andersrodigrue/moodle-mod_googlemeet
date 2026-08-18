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
 * Google Meet module data generator.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Google Meet module data generator class.
 *
 * This generator bypasses the external Google APIs so that activity security can
 * be tested with deterministic local records.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_googlemeet_generator extends testing_module_generator {

    /**
     * Creates a Google Meet activity for testing.
     *
     * @param array|stdClass|null $record Activity fields.
     * @param array|null $options Course module options.
     * @return stdClass The activity record with its course module ID.
     */
    public function create_instance($record = null, $options = null) {
        global $DB;

        $this->instancecount++;
        $record = (array) $record;
        $now = time();

        if (empty($record['course'])) {
            throw new coding_exception('The Google Meet generator requires a course.');
        }

        $record += [
            'name' => 'Google Meet ' . $this->instancecount,
        ];
        $record += [
            'originalname' => $record['name'],
            'url' => 'https://meet.google.com/abc-defg-hij',
            'intro' => '',
            'introformat' => FORMAT_HTML,
            'eventdate' => time(),
            'starthour' => 10,
            'startminute' => 0,
            'endhour' => 11,
            'endminute' => 0,
            'addmultiply' => 0,
            'notify' => 0,
            'minutesbefore' => 0,
            'timemodified' => $now,
        ];
        $record += [
            'integrationmode' => \mod_googlemeet\local\integration_mode::MANUAL,
            'meetinguri' => $record['url'],
            'timestart' => (int) $record['eventdate'],
            'timeend' => (int) $record['eventdate'] + HOURSECS,
            'sendupdates' => 'none',
            'guestpolicy' => \mod_googlemeet\local\calendar_guest_policy::NONE,
            'guestcount' => 0,
            'syncstatus' => \mod_googlemeet\local\sync_state::READY,
            'syncattempts' => 0,
            'timecreated' => $now,
            'recordingsyncstatus' => \mod_googlemeet\local\recording_sync_state::DISCONNECTED,
            'recordingsyncattempts' => 0,
        ];

        $record = (object) $record;
        $cmid = $this->precreate_course_module($record->course, (array) $options);
        $record->id = $DB->insert_record('googlemeet', $record);

        return $this->post_add_instance($record->id, $cmid);
    }
}
