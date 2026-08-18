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

namespace mod_googlemeet\event;

use mod_googlemeet\local\diagnostic_recorder;

/**
 * A privacy-safe integration operation changed state.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class operation_recorded extends \core\event\base {

    /**
     * Defines the structured event contract.
     */
    protected function init(): void {
        $this->data['objecttable'] = 'googlemeet';
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Returns the localized event name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('eventoperationrecorded', 'mod_googlemeet');
    }

    /**
     * Returns a bounded description without user identity or remote data.
     *
     * @return string
     */
    public function get_description(): string {
        return "The Google Meet activity with id '{$this->objectid}' recorded operation "
            . "'{$this->other['operation']}' with outcome '{$this->other['outcome']}' "
            . "from source '{$this->other['source']}'.";
    }

    /**
     * Links to the activity when its module record is available.
     *
     * @return \moodle_url
     */
    public function get_url(): \moodle_url {
        if ($this->contextlevel === CONTEXT_MODULE) {
            return new \moodle_url('/mod/googlemeet/view.php', ['id' => $this->contextinstanceid]);
        }

        return new \moodle_url('/course/view.php', ['id' => $this->courseid]);
    }

    /**
     * Validates the closed diagnostic vocabulary.
     */
    protected function validate_data(): void {
        parent::validate_data();

        if (!in_array($this->other['operation'] ?? null, diagnostic_recorder::operations(), true)) {
            throw new \coding_exception('The diagnostic event operation is invalid.');
        }
        if (!in_array($this->other['outcome'] ?? null, diagnostic_recorder::outcomes(), true)) {
            throw new \coding_exception('The diagnostic event outcome is invalid.');
        }
        if (!in_array($this->other['source'] ?? null, diagnostic_recorder::sources(), true)) {
            throw new \coding_exception('The diagnostic event source is invalid.');
        }
        $code = $this->other['diagnosticcode'] ?? null;
        if (
            $code !== null
            && (!is_string($code) || !preg_match('/^[a-z0-9][a-z0-9_.-]{0,99}$/', $code))
        ) {
            throw new \coding_exception('The diagnostic event code is invalid.');
        }
    }

    /**
     * Maps the activity when restoring course logs.
     *
     * @return array
     */
    public static function get_objectid_mapping(): array {
        return ['db' => 'googlemeet', 'restore' => 'googlemeet'];
    }
}
