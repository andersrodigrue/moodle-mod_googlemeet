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

namespace mod_googlemeet;

use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/googlemeet/mod_form.php');

/**
 * Testable activity form exposing its QuickForm definition.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class testable_mod_form extends \mod_googlemeet_mod_form {

    /**
     * Sets the module name before the generic form constructor validates it.
     *
     * @param \stdClass $current Current activity data.
     * @param int $section Course section number.
     * @param \stdClass|null $cm Course module.
     * @param \stdClass $course Course.
     */
    public function __construct($current, $section, $cm, $course) {
        $this->_modname = 'googlemeet';
        parent::__construct($current, $section, $cm, $course);
    }

    /**
     * Returns the underlying form definition.
     *
     * @return \MoodleQuickForm
     */
    public function quickform(): \MoodleQuickForm {
        return $this->_form;
    }

    /**
     * Keeps these focused tests independent of course-format form controls.
     *
     * @return bool
     */
    protected function standard_coursemodule_elements() {
        return true;
    }
}

/**
 * Tests for the Moodle 5.2 activity editor.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\mod_googlemeet_mod_form::class)]
final class mod_form_test extends \advanced_testcase {

    /**
     * New activities expose only canonical schedule controls.
     */
    public function test_form_uses_canonical_schedule_controls(): void {
        global $COURSE;

        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('issuerid', 0, 'googlemeet');
        $COURSE = $this->getDataGenerator()->create_course();
        $current = (object) [
            'instance' => 0,
            'timezone' => 'America/Sao_Paulo',
        ];

        $form = new testable_mod_form($current, 0, null, $COURSE);
        $mform = $form->quickform();

        foreach ([
            'timestart',
            'timeend',
            'timezone',
            'recurrenceenabled',
            'recurrenceweekdays',
            'recurrenceinterval',
            'recurrenceuntil',
            'calendarid',
            'guestpolicy',
            'guestpolicywarning',
        ] as $field) {
            $this->assertTrue($mform->elementExists($field), $field);
        }
        foreach ([
            'eventdate',
            'starthour',
            'startminute',
            'endhour',
            'endminute',
            'addmultiply',
            'days',
            'period',
            'eventenddate',
        ] as $field) {
            $this->assertFalse($mform->elementExists($field), $field);
        }
    }

    /**
     * An existing managed event exposes its stored Calendar as read-only.
     */
    public function test_existing_managed_calendar_is_frozen(): void {
        global $COURSE;

        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('issuerid', 0, 'googlemeet');
        $COURSE = $this->getDataGenerator()->create_course();
        $start = $COURSE->startdate + DAYSECS;
        $current = (object) [
            'instance' => 42,
            'integrationmode' => \mod_googlemeet\local\integration_mode::MANAGED,
            'calendarid' => 'course-calendar@example.com',
            'url' => '',
            'timestart' => $start,
            'timeend' => $start + HOURSECS,
            'timezone' => 'America/Sao_Paulo',
            'recurrence' => null,
        ];

        $form = new testable_mod_form($current, 0, null, $COURSE);
        $mform = $form->quickform();

        $this->assertTrue($mform->getElement('calendarid')->isFrozen());
        $this->assertTrue($mform->getElement('integrationmode')->isFrozen());
    }

    /**
     * A non-owner coeditor does not receive the stored Calendar identifier.
     */
    public function test_non_owner_sees_only_generic_calendar_state(): void {
        global $COURSE;

        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->setUser($other);
        set_config('issuerid', 0, 'googlemeet');
        $COURSE = $this->getDataGenerator()->create_course();
        $start = $COURSE->startdate + DAYSECS;
        $current = (object) [
            'instance' => 42,
            'integrationmode' => \mod_googlemeet\local\integration_mode::MANAGED,
            'owneruserid' => $owner->id,
            'calendarid' => 'private-owner@example.com',
            'url' => '',
            'timestart' => $start,
            'timeend' => $start + HOURSECS,
            'timezone' => 'America/Sao_Paulo',
            'recurrence' => null,
        ];

        $form = new testable_mod_form($current, 0, null, $COURSE);
        $mform = $form->quickform();

        $this->assertFalse($mform->elementExists('calendarid'));
        $this->assertTrue($mform->elementExists('managedcalendarownerstatus'));
        $this->assertStringNotContainsString(
            'private-owner@example.com',
            (string) $mform->getElement('managedcalendarownerstatus')->getValue()
        );
    }

    /**
     * Imported exception schedules are visible but cannot be simplified.
     */
    public function test_imported_exception_schedule_is_frozen(): void {
        global $COURSE;

        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('issuerid', 0, 'googlemeet');
        $COURSE = $this->getDataGenerator()->create_course();
        $start = $COURSE->startdate + DAYSECS;
        $current = (object) [
            'instance' => 42,
            'integrationmode' => \mod_googlemeet\local\integration_mode::MANUAL,
            'url' => 'https://meet.google.com/abc-defg-hij',
            'timestart' => $start,
            'timeend' => $start + HOURSECS,
            'timezone' => 'America/Sao_Paulo',
            'recurrence' => "RRULE:FREQ=WEEKLY;COUNT=1\nRDATE:" . gmdate(
                'Ymd\THis\Z',
                $start + WEEKSECS
            ),
        ];

        $form = new testable_mod_form($current, 0, null, $COURSE);
        $mform = $form->quickform();

        $this->assertTrue($mform->elementExists('recurrencecompatibilitynotice'));
        foreach ([
            'timestart',
            'timeend',
            'timezone',
            'recurrenceenabled',
            'recurrenceweekdays',
            'recurrenceinterval',
            'recurrenceuntil',
        ] as $field) {
            $this->assertTrue($mform->getElement($field)->isFrozen(), $field);
        }
    }
}
