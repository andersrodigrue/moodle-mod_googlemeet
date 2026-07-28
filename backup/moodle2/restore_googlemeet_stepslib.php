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
 * All the steps to restore mod_googlemeet are defined here.
 *
 * @package     mod_googlemeet
 * @subpackage  backup-moodle2
 * @copyright   2020 Rone Santos <ronefel@hotmail.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Defines the structure step to restore one mod_googlemeet activity.
 */
class restore_googlemeet_activity_structure_step extends restore_activity_structure_step {

    /**
     * Defines the structure to be restored.
     *
     * @return restore_path_element[].
     */
    protected function define_structure() {
        $paths = array();

        $paths[] = new restore_path_element('googlemeet', '/activity/googlemeet');

        $paths[] = new restore_path_element('googlemeet_event',
            '/activity/googlemeet/events/event');

        return $this->prepare_activity_structure($paths);
    }

    /**
     * Process a googlemeet restore.
     *
     * @param object $data The data in object form
     * @return void
     */
    protected function process_googlemeet($data) {
        global $DB;

        $data = (object) $data;
        $data->course = $this->get_courseid();

        // Any changes to the list of dates that needs to be rolled should be same during course restore and course reset.
        // See MDL-9367.
        foreach (['eventdate', 'eventenddate', 'timestart', 'timeend'] as $datefield) {
            if (!empty($data->{$datefield})) {
                $data->{$datefield} = $this->apply_date_offset($data->{$datefield});
            }
        }

        $this->normalise_integration($data);

        // Insert the googlemeet record.
        $newitemid = $DB->insert_record('googlemeet', $data);
        // Immediately after inserting "activity" record, call this.
        $this->apply_activity_instance($newitemid);
    }

    /**
     * Removes non-portable remote identity and restores a safe local lifecycle.
     *
     * OAuth ownership and Calendar identifiers are intentionally never
     * transported. A managed copy must be explicitly claimed before it can
     * create a distinct remote event.
     *
     * @param stdClass $data Restored activity data.
     */
    private function normalise_integration(stdClass $data): void {
        $mode = (string) ($data->integrationmode ?? \mod_googlemeet\local\integration_mode::MANUAL);

        $data->owneruserid = null;
        $data->oauthissuerid = null;
        $data->googleeventid = null;
        $data->googleeventhtmlurl = null;
        $data->googleeventetag = null;
        $data->requestid = null;
        $data->conferenceid = null;
        $data->meetingcode = null;
        $data->conferencestatus = null;
        $data->syncattempts = 0;
        $data->timelastattempt = null;
        $data->recordingowneruserid = null;
        $data->recordingoauthissuerid = null;
        $data->recordingsyncstatus = \mod_googlemeet\local\recording_sync_state::DISCONNECTED;
        $data->recordingsyncattempts = 0;
        $data->recordinglasterrorcode = null;
        $data->recordinglasterrormessage = null;
        $data->recordingtimelastattempt = null;
        $data->lastsync = null;
        $data->creatoremail = null;

        if ($mode === \mod_googlemeet\local\integration_mode::MANAGED) {
            $data->integrationmode = \mod_googlemeet\local\integration_mode::MANAGED;
            $data->calendarid = 'primary';
            $data->url = '';
            $data->meetinguri = null;
            $data->syncstatus = \mod_googlemeet\local\sync_state::DISCONNECTED;
            $data->lasterrorcode = 'restored_reconnect_required';
            $data->lasterrormessage = get_string('syncrestoredreconnectrequired', 'mod_googlemeet');
            return;
        }

        if ($mode === \mod_googlemeet\local\integration_mode::LEGACY) {
            $data->integrationmode = \mod_googlemeet\local\integration_mode::LEGACY;
            $data->calendarid = null;
            $data->meetinguri = (string) ($data->meetinguri ?? $data->url ?? '');
            $data->syncstatus = \mod_googlemeet\local\sync_state::DISCONNECTED;
            $data->lasterrorcode = 'reconnect_required';
            $data->lasterrormessage = get_string('syncreconnectrequired', 'mod_googlemeet');
            return;
        }

        $data->integrationmode = \mod_googlemeet\local\integration_mode::MANUAL;
        $data->calendarid = null;
        $data->meetinguri = (string) ($data->meetinguri ?? $data->url ?? '');
        $data->syncstatus = \mod_googlemeet\local\sync_state::READY;
        $data->lasterrorcode = null;
        $data->lasterrormessage = null;
    }

    /**
     * Process a event restore.
     *
     * @param object $data The data in object form
     * @return void
     */
    protected function process_googlemeet_event($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;

        $data->googlemeetid = $this->get_new_parentid('googlemeet');
        $data->eventdate = $this->apply_date_offset($data->eventdate);
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        $newitemid = $DB->insert_record('googlemeet_events', $data);
        $this->set_mapping('googlemeet_event', $oldid, $newitemid);
    }

    /**
     * Defines post-execution actions.
     */
    protected function after_execute() {
        $this->add_related_files('mod_googlemeet', 'intro', null);
    }
}
