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
 * Google Meet external functions and service definitions.
 *
 * @package     mod_googlemeet
 * @copyright   2020 Rone Santos <ronefel@hotmail.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'mod_googlemeet_rename_recording' => [
        'classname' => 'mod_googlemeet\external\rename_recording',
        'methodname' => 'execute',
        'description' => 'Rename one recording reference in an authorised Google Meet activity.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/googlemeet:editrecording',
    ],
    'mod_googlemeet_set_recording_visibility' => [
        'classname' => 'mod_googlemeet\external\set_recording_visibility',
        'methodname' => 'execute',
        'description' => 'Set participant visibility for one recording reference explicitly.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/googlemeet:editrecording',
    ],
    'mod_googlemeet_delete_recordings' => [
        'classname' => 'mod_googlemeet\external\delete_recordings',
        'methodname' => 'execute',
        'description' => 'Delete all local recording references from an authorised Google Meet activity.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/googlemeet:removerecording',
    ],
];
