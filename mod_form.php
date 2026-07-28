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
 * The main mod_googlemeet configuration form.
 *
 * @package     mod_googlemeet
 * @copyright   2020 Rone Santos <ronefel@hotmail.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use mod_googlemeet\api\calendar_authorization_exception;
use mod_googlemeet\api\calendar_guest_limit_exception;
use mod_googlemeet\local\calendar_guest_policy;
use mod_googlemeet\local\calendar_guest_resolver;
use mod_googlemeet\local\integration_mode;
use mod_googlemeet\local\meeting_form_data;
use mod_googlemeet\local\oauth_manager;

require_once($CFG->dirroot . '/course/moodleform_mod.php');
require_once($CFG->dirroot . '/mod/googlemeet/locallib.php');

/**
 * Module instance settings form.
 *
 * @package    mod_googlemeet
 * @copyright  2020 Rone Santos <ronefel@hotmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_googlemeet_mod_form extends moodleform_mod {
    /**
     * Defines forms elements
     */
    public function definition() {
        global $CFG, $COURSE, $OUTPUT, $USER;

        $config = get_config('googlemeet');
        $mform = $this->_form;
        $issuerid = (int) ($config->issuerid ?? 0);
        $managedclient = null;
        $managedauthorized = false;
        if ($issuerid > 0) {
            try {
                $managedclient = (new oauth_manager())->authorization_client($issuerid, (int) $USER->id);
                $managedauthorized = $managedclient->is_logged_in();
            } catch (calendar_authorization_exception | moodle_exception) {
                $managedclient = null;
            }
        }

        // Adding the "general" fieldset, where all the common settings are shown.
        $mform->addElement('header', 'general', get_string('general', 'form'));

        // Adding the standard "name" field.
        $mform->addElement('text', 'name', get_string('roomname', 'googlemeet'), array('size' => '50'));

        if (!empty($CFG->formatstringstriptags)) {
            $mform->setType('name', PARAM_TEXT);
        } else {
            $mform->setType('name', PARAM_CLEANHTML);
        }

        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $this->standard_intro_elements();
        $element = $mform->getElement('introeditor');
        $attributes = $element->getAttributes();
        $attributes['rows'] = 5;
        $element->setAttributes($attributes);

        $mform->addElement('header', 'headerintegration', get_string('integration', 'googlemeet'));
        $mform->addElement('select', 'integrationmode', get_string('integrationmode', 'googlemeet'), [
            integration_mode::MANAGED => get_string('integrationmodemanaged', 'googlemeet'),
            integration_mode::MANUAL => get_string('integrationmodemanual', 'googlemeet'),
        ]);
        $mform->setDefault(
            'integrationmode',
            $issuerid > 0 ? integration_mode::MANAGED : integration_mode::MANUAL
        );
        $mform->addHelpButton('integrationmode', 'integrationmode', 'googlemeet');

        if (
            !empty($this->current->instance) &&
            ($this->current->integrationmode ?? null) === integration_mode::MANAGED
        ) {
            $mform->freeze('integrationmode');
        } else if (
            !empty($this->current->instance) &&
            ($this->current->integrationmode ?? null) === integration_mode::LEGACY
        ) {
            $mform->addElement(
                'static',
                'legacyintegrationnotice',
                '',
                $OUTPUT->notification(get_string('integrationlegacyupgrade', 'googlemeet'), 'warning')
            );
        }

        if ($managedauthorized) {
            $oauthstatus = $OUTPUT->notification(get_string('managedoauthconnected', 'googlemeet'), 'success');
        } else if ($managedclient !== null) {
            $loginurl = new moodle_url($managedclient->get_login_url());
            $button = html_writer::link(
                $loginurl,
                get_string('managedoauthconnect', 'googlemeet'),
                [
                    'class' => 'btn btn-primary',
                    'target' => '_blank',
                    'rel' => 'noopener',
                ]
            );
            $oauthstatus = $OUTPUT->notification(
                get_string('managedoauthrequired', 'googlemeet') . html_writer::div($button, 'mt-2'),
                'info'
            );
        } else {
            $oauthstatus = $OUTPUT->notification(get_string('managedoauthunavailable', 'googlemeet'), 'warning');
        }
        $mform->addElement('static', 'managedoauthstatus', get_string('managedoauth', 'googlemeet'), $oauthstatus);
        $mform->hideIf('managedoauthstatus', 'integrationmode', 'neq', integration_mode::MANAGED);

        $mform->addElement(
            'select',
            'guestpolicy',
            get_string('guestpolicy', 'googlemeet'),
            [
                calendar_guest_policy::NONE => get_string('guestpolicynone', 'googlemeet'),
                calendar_guest_policy::COURSE => get_string('guestpolicycourse', 'googlemeet'),
            ]
        );
        $mform->setDefault('guestpolicy', calendar_guest_policy::NONE);
        $mform->addHelpButton('guestpolicy', 'guestpolicy', 'googlemeet');
        $mform->hideIf('guestpolicy', 'integrationmode', 'neq', integration_mode::MANAGED);
        $mform->addElement(
            'static',
            'guestpolicywarning',
            '',
            $OUTPUT->notification(get_string(
                'guestpolicywarning',
                'googlemeet',
                calendar_guest_resolver::MAX_ATTENDEES
            ), 'warning')
        );
        $mform->hideIf('guestpolicywarning', 'integrationmode', 'neq', integration_mode::MANAGED);
        $mform->hideIf('guestpolicywarning', 'guestpolicy', 'neq', calendar_guest_policy::COURSE);

        $mform->addElement('header', 'headerschedule', get_string('meetingschedule', 'googlemeet'));
        $scheduletimezone = $this->schedule_timezone();
        $dateoptions = [
            'optional' => false,
            'step' => 1,
            'timezone' => $scheduletimezone,
        ];
        $now = \core\di::get(\core\clock::class)->time();
        $defaultstart = max((int) $COURSE->startdate, $now);
        $defaultstart = (int) (ceil($defaultstart / MINSECS) * MINSECS);

        $mform->addElement(
            'date_time_selector',
            'timestart',
            get_string('meetingstart', 'googlemeet'),
            $dateoptions
        );
        $mform->setDefault('timestart', $defaultstart);
        $mform->addHelpButton('timestart', 'meetingstart', 'googlemeet');

        $mform->addElement(
            'date_time_selector',
            'timeend',
            get_string('meetingend', 'googlemeet'),
            $dateoptions
        );
        $mform->setDefault('timeend', $defaultstart + HOURSECS);
        $mform->addHelpButton('timeend', 'meetingend', 'googlemeet');

        $mform->addElement(
            'autocomplete',
            'timezone',
            get_string('meetingtimezone', 'googlemeet'),
            $this->timezone_options(),
            ['multiple' => false]
        );
        $mform->setDefault('timezone', $scheduletimezone);
        $mform->addHelpButton('timezone', 'meetingtimezone', 'googlemeet');

        $mform->addElement('header', 'headerrecurrence', get_string('recurrenceeventdate', 'googlemeet'));
        if (
            !empty($config->multieventdateexpanded)
            || trim((string) ($this->current->recurrence ?? '')) !== ''
        ) {
            $mform->setExpanded('headerrecurrence');
        }

        $mform->addElement(
            'advcheckbox',
            'recurrenceenabled',
            '',
            get_string('recurrenceenabled', 'googlemeet')
        );
        $mform->addHelpButton('recurrenceenabled', 'recurrenceeventdate', 'googlemeet');

        $weekdays = [
            'MO' => get_string('monday', 'calendar'),
            'TU' => get_string('tuesday', 'calendar'),
            'WE' => get_string('wednesday', 'calendar'),
            'TH' => get_string('thursday', 'calendar'),
            'FR' => get_string('friday', 'calendar'),
            'SA' => get_string('saturday', 'calendar'),
            'SU' => get_string('sunday', 'calendar'),
        ];
        if ((int) $CFG->calendar_startwday === 0) {
            $weekdays = ['SU' => $weekdays['SU']] + array_diff_key($weekdays, ['SU' => true]);
        }
        $mform->addElement(
            'autocomplete',
            'recurrenceweekdays',
            get_string('repeaton', 'googlemeet'),
            $weekdays,
            ['multiple' => true]
        );
        $mform->disabledIf('recurrenceweekdays', 'recurrenceenabled', 'notchecked');

        $intervals = array_combine(range(1, 36), range(1, 36));
        $mform->addElement(
            'select',
            'recurrenceinterval',
            get_string('recurrenceinterval', 'googlemeet'),
            $intervals
        );
        $mform->setDefault('recurrenceinterval', 1);
        $mform->disabledIf('recurrenceinterval', 'recurrenceenabled', 'notchecked');

        $mform->addElement(
            'date_time_selector',
            'recurrenceuntil',
            get_string('repeatuntil', 'googlemeet'),
            $dateoptions
        );
        $mform->setDefault('recurrenceuntil', $defaultstart + 4 * WEEKSECS);
        $mform->disabledIf('recurrenceuntil', 'recurrenceenabled', 'notchecked');

        $mform->addElement('hidden', 'recurrencecompatibilitylocked', 0);
        $mform->setType('recurrencecompatibilitylocked', PARAM_BOOL);
        if ($this->schedule_is_locked()) {
            $mform->addElement(
                'static',
                'recurrencecompatibilitynotice',
                '',
                $OUTPUT->notification(get_string('recurrencecompatibilitynotice', 'googlemeet'), 'warning')
            );
            $mform->freeze([
                'timestart',
                'timeend',
                'timezone',
                'recurrenceenabled',
                'recurrenceweekdays',
                'recurrenceinterval',
                'recurrenceuntil',
            ]);
        }

        $mform->addElement('header', 'headerroomurl', get_string('roomurl', 'googlemeet'));
        if (!empty($config->roomurlexpanded)) {
            $mform->setExpanded('headerroomurl');
        }

        $mform->addElement(
            'static',
            'url_desc',
            '',
            $OUTPUT->notification(get_string('managedroomurldesc', 'googlemeet'), 'info')
        );
        $mform->addElement('text', 'url', get_string('roomurl', 'googlemeet'), ['size' => '50']);
        $mform->setType('url', PARAM_URL);
        $mform->addHelpButton('url', 'url', 'googlemeet');
        $mform->disabledIf('url', 'integrationmode', 'neq', integration_mode::MANUAL);

        $mform->addElement('header', 'headernotification', get_string('notification', 'googlemeet'));
        if (!empty($config->notificationexpanded)) {
            $mform->setExpanded('headernotification');
        }

        $mform->addElement('checkbox', 'notify', '', get_string('notify', 'googlemeet'));
        $mform->setDefault('notify', (int) ($config->notify ?? 0));
        $mform->addHelpButton('notify', 'notify', 'googlemeet');

        $minutes = [];
        for ($i = 0; $i <= 120; $i = $i + 5) {
            $minutes[$i] = $i;
        }
        $minutesbefore = $mform->addElement('select',
            'minutesbefore', get_string('minutesbefore', 'googlemeet'), $minutes, false, true
        );
        $minutesbefore->setSelected((int) ($config->minutesbefore ?? 0));
        $mform->addHelpButton('minutesbefore', 'minutesbefore', 'googlemeet');

        // Add standard elements.
        $this->standard_coursemodule_elements();

        // Add standard buttons.
        $this->add_action_buttons();

    }

    /**
     * Maps stored canonical schedule values to the activity form.
     *
     * @param array $defaultvalues Form defaults.
     * @return void
     */
    public function data_preprocessing(&$defaultvalues) {
        if (empty($this->current->instance)) {
            $defaultvalues['timezone'] = $this->schedule_timezone();
            return;
        }

        $defaultvalues = (new meeting_form_data())->prepare_form_defaults(
            $defaultvalues,
            $this->schedule_timezone()
        );
        if (
            !empty($this->current->instance)
            && ($defaultvalues['integrationmode'] ?? null) === integration_mode::LEGACY
        ) {
            $defaultvalues['integrationmode'] = integration_mode::MANUAL;
        }
    }

    /**
     * Enforce validation rules here
     *
     * @param array $data array of ("fieldname"=>value) of submitted data
     * @param array $files array of uploaded files "element_name"=>tmp_file_path
     * @return array
     **/
    public function validation($data, $files) {
        global $COURSE, $USER;

        $errors = parent::validation($data, $files);
        if (!$this->schedule_is_locked()) {
            $timestart = (int) ($data['timestart'] ?? 0);
            $timeend = (int) ($data['timeend'] ?? 0);
            $timezone = (string) ($data['timezone'] ?? '');

            if ($timestart <= 0) {
                $errors['timestart'] = get_string('required');
            }
            if ($timeend <= $timestart) {
                $errors['timeend'] = get_string('invalideventendtime', 'googlemeet');
            }
            if ($timestart > 0 && $timestart < (int) $COURSE->startdate) {
                $errors['timestart'] = get_string(
                    'earlierto',
                    'googlemeet',
                    userdate($COURSE->startdate, get_string('strftimedmyhm', 'googlemeet'))
                );
            }

            try {
                $timezoneobject = new DateTimeZone($timezone);
            } catch (Exception) {
                $timezoneobject = null;
                $errors['timezone'] = get_string('invalidmeetingtimezone', 'googlemeet');
            }

            if (!empty($data['recurrenceenabled'])) {
                $interval = (int) ($data['recurrenceinterval'] ?? 0);
                if ($interval < 1 || $interval > 36) {
                    $errors['recurrenceinterval'] = get_string('invalidrecurrenceinterval', 'googlemeet');
                }
                if (empty($data['recurrenceweekdays'])) {
                    $errors['recurrenceweekdays'] = get_string('checkweekdays', 'googlemeet');
                }

                $until = (int) ($data['recurrenceuntil'] ?? 0);
                if ($until < $timestart) {
                    $errors['recurrenceuntil'] = get_string('invalideventenddate', 'googlemeet');
                } else if ($timezoneobject !== null && $timestart > 0) {
                    $startday = (new DateTimeImmutable('@' . $timestart))
                        ->setTimezone($timezoneobject)
                        ->setTime(0, 0);
                    $untilday = (new DateTimeImmutable('@' . $until))
                        ->setTimezone($timezoneobject)
                        ->setTime(0, 0);
                    if ($untilday > $startday->modify('+1 year')) {
                        $errors['recurrenceuntil'] = get_string('timeahead', 'googlemeet');
                    }
                }
            }

            if (!array_intersect_key($errors, array_flip([
                'timestart',
                'timeend',
                'timezone',
                'recurrenceinterval',
                'recurrenceweekdays',
                'recurrenceuntil',
            ]))) {
                try {
                    (new meeting_form_data())->normalize(
                        (object) $data,
                        $this->schedule_timezone(),
                        !empty($this->current->instance) ? $this->current : null
                    );
                } catch (invalid_parameter_exception) {
                    $errors['recurrenceuntil'] = get_string('invalidschedule', 'googlemeet');
                }
            }
        }

        $mode = (string) ($data['integrationmode'] ?? '');
        if ($mode === integration_mode::MANUAL) {
            $errors = $this->validate_url((string) ($data['url'] ?? ''), $errors);
        } else if ($mode === integration_mode::MANAGED) {
            if (
                !empty($this->current->instance) &&
                ($this->current->integrationmode ?? null) === integration_mode::MANAGED &&
                (int) ($this->current->owneruserid ?? 0) > 0 &&
                (int) ($this->current->owneruserid ?? 0) !== (int) $USER->id
            ) {
                $errors['integrationmode'] = get_string('managedowneronly', 'googlemeet');
                return $errors;
            }
            $issuerid = (int) get_config('googlemeet', 'issuerid');
            if ($issuerid <= 0) {
                $errors['integrationmode'] = get_string('managedoauthunavailable', 'googlemeet');
            } else {
                try {
                    $oauthclient = (new oauth_manager())->authorization_client($issuerid, (int) $USER->id);
                    if (!$oauthclient->is_logged_in()) {
                        $errors['integrationmode'] = get_string('managedoauthrequired', 'googlemeet');
                    }
                } catch (calendar_authorization_exception | moodle_exception) {
                    $errors['integrationmode'] = get_string('managedoauthunavailable', 'googlemeet');
                }
            }
            $guestpolicy = (string) ($data['guestpolicy'] ?? calendar_guest_policy::NONE);
            if (!calendar_guest_policy::is_valid($guestpolicy)) {
                $errors['guestpolicy'] = get_string('invalidguestpolicy', 'googlemeet');
            } else if ($guestpolicy === calendar_guest_policy::COURSE) {
                try {
                    $context = !empty($this->_cm)
                        ? context_module::instance((int) $this->_cm->id)
                        : context_course::instance((int) $COURSE->id);
                    (new calendar_guest_resolver())->resolve_context($context, (int) $USER->id);
                } catch (calendar_guest_limit_exception) {
                    $errors['guestpolicy'] = get_string(
                        'guestlimitexceeded',
                        'googlemeet',
                        calendar_guest_resolver::MAX_ATTENDEES
                    );
                }
            }
        } else {
            $errors['integrationmode'] = get_string('invalidintegrationmode', 'googlemeet');
        }

        return $errors;
    }

    /**
     * Returns the stored or current-user IANA timezone.
     *
     * @return string
     */
    private function schedule_timezone(): string {
        global $USER;

        $timezone = trim((string) ($this->current->timezone ?? ''));
        if ($timezone === '') {
            $timezone = get_user_timezone($USER->timezone);
        }
        try {
            return (new DateTimeZone($timezone))->getName();
        } catch (Exception) {
            return 'UTC';
        }
    }

    /**
     * Returns localized IANA timezone options.
     *
     * @return array<string, string>
     */
    private function timezone_options(): array {
        $timezones = ['UTC' => core_date::get_localised_timezone('UTC')];
        foreach (DateTimeZone::listIdentifiers() as $timezone) {
            $timezones[$timezone] = core_date::get_localised_timezone($timezone);
        }
        core_collator::asort($timezones);

        return $timezones;
    }

    /**
     * Whether an imported recurrence must be preserved read-only.
     *
     * @return bool
     */
    private function schedule_is_locked(): bool {
        if (empty($this->current->instance)) {
            return false;
        }

        return !(new meeting_form_data())->is_recurrence_editable(
            (string) ($this->current->recurrence ?? '')
        );
    }

    /**
     * Validate the provided url
     * @param string $url Url to validate.
     * @param array $errors Form errors.
     *
     * @return array Form errors.
     */
    private function validate_url(string $url, array $errors) {
        if (googlemeet_clear_url($url) == null) {
            $errors['url'] = get_string('url_failed', 'googlemeet');
        }
        return $errors;
    }
}
