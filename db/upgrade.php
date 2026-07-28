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
 * Plugin upgrade steps are defined here.
 *
 * @package     mod_googlemeet
 * @category    upgrade
 * @copyright   2020 Rone Santos <ronefel@hotmail.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Execute mod_googlemeet upgrade from the given old version.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_googlemeet_upgrade($oldversion): bool {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2023042200) {

        // Define field eventid to be added to googlemeet.
        $table = new xmldb_table('googlemeet');
        $field = new xmldb_field('eventid', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'timemodified');

        // Conditionally launch add field eventid.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Googlemeet savepoint reached.
        upgrade_mod_savepoint(true, 2023042200, 'googlemeet');
    }

    if ($oldversion < 2026072601) {
        $table = new xmldb_table('googlemeet');
        $fields = [
            new xmldb_field(
                'integrationmode',
                XMLDB_TYPE_CHAR,
                '16',
                null,
                XMLDB_NOTNULL,
                null,
                'manual',
                'eventid'
            ),
            new xmldb_field('owneruserid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'integrationmode'),
            new xmldb_field('oauthissuerid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'owneruserid'),
            new xmldb_field('calendarid', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'oauthissuerid'),
            new xmldb_field('googleeventid', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'calendarid'),
            new xmldb_field('googleeventhtmlurl', XMLDB_TYPE_TEXT, null, null, null, null, null, 'googleeventid'),
            new xmldb_field('googleeventetag', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'googleeventhtmlurl'),
            new xmldb_field('requestid', XMLDB_TYPE_CHAR, '64', null, null, null, null, 'googleeventetag'),
            new xmldb_field('conferenceid', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'requestid'),
            new xmldb_field('meetingcode', XMLDB_TYPE_CHAR, '32', null, null, null, null, 'conferenceid'),
            new xmldb_field('meetinguri', XMLDB_TYPE_TEXT, null, null, null, null, null, 'meetingcode'),
            new xmldb_field('timestart', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'meetinguri'),
            new xmldb_field('timeend', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'timestart'),
            new xmldb_field('timezone', XMLDB_TYPE_CHAR, '64', null, null, null, null, 'timeend'),
            new xmldb_field('recurrence', XMLDB_TYPE_TEXT, null, null, null, null, null, 'timezone'),
            new xmldb_field(
                'sendupdates',
                XMLDB_TYPE_CHAR,
                '16',
                null,
                XMLDB_NOTNULL,
                null,
                'none',
                'recurrence'
            ),
            new xmldb_field(
                'syncstatus',
                XMLDB_TYPE_CHAR,
                '32',
                null,
                XMLDB_NOTNULL,
                null,
                'draft',
                'sendupdates'
            ),
            new xmldb_field('conferencestatus', XMLDB_TYPE_CHAR, '32', null, null, null, null, 'syncstatus'),
            new xmldb_field(
                'syncattempts',
                XMLDB_TYPE_INTEGER,
                '10',
                null,
                XMLDB_NOTNULL,
                null,
                '0',
                'conferencestatus'
            ),
            new xmldb_field('lasterrorcode', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'syncattempts'),
            new xmldb_field('lasterrormessage', XMLDB_TYPE_TEXT, null, null, null, null, null, 'lasterrorcode'),
            new xmldb_field('timelastattempt', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'lasterrormessage'),
            new xmldb_field(
                'timecreated',
                XMLDB_TYPE_INTEGER,
                '10',
                null,
                XMLDB_NOTNULL,
                null,
                '0',
                'timelastattempt'
            ),
        ];

        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        // Preserve the existing link, but do not treat the legacy eventid as a Calendar API event ID.
        $DB->execute(
            'UPDATE {googlemeet}
                SET meetinguri = url
              WHERE meetinguri IS NULL'
        );
        $DB->execute(
            'UPDATE {googlemeet}
                SET timecreated = timemodified
              WHERE timecreated = :zero',
            ['zero' => 0]
        );

        // Manual links are already usable without remote synchronization.
        $DB->set_field('googlemeet', 'integrationmode', 'manual');
        $DB->set_field('googlemeet', 'syncstatus', 'ready');

        // Legacy-linked meetings need an explicit reconnect before the modern client can manage them.
        $legacyselect = 'eventid IS NOT NULL AND eventid <> :emptyeventid';
        $legacyparams = ['emptyeventid' => ''];
        $DB->set_field_select('googlemeet', 'integrationmode', 'legacy', $legacyselect, $legacyparams);
        $DB->set_field_select('googlemeet', 'syncstatus', 'disconnected', $legacyselect, $legacyparams);

        $indexes = [
            new xmldb_index('owneruserid', XMLDB_INDEX_NOTUNIQUE, ['owneruserid']),
            new xmldb_index('syncstatus', XMLDB_INDEX_NOTUNIQUE, ['syncstatus']),
        ];

        foreach ($indexes as $index) {
            if (!$dbman->index_exists($table, $index)) {
                $dbman->add_index($table, $index);
            }
        }

        upgrade_mod_savepoint(true, 2026072601, 'googlemeet');
    }

    if ($oldversion < 2026072608) {
        $table = new xmldb_table('googlemeet');
        $fields = [
            new xmldb_field('recordingowneruserid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'timecreated'),
            new xmldb_field(
                'recordingoauthissuerid',
                XMLDB_TYPE_INTEGER,
                '10',
                null,
                null,
                null,
                null,
                'recordingowneruserid'
            ),
            new xmldb_field(
                'recordingsyncstatus',
                XMLDB_TYPE_CHAR,
                '32',
                null,
                XMLDB_NOTNULL,
                null,
                'disconnected',
                'recordingoauthissuerid'
            ),
            new xmldb_field(
                'recordingsyncattempts',
                XMLDB_TYPE_INTEGER,
                '10',
                null,
                XMLDB_NOTNULL,
                null,
                '0',
                'recordingsyncstatus'
            ),
            new xmldb_field(
                'recordinglasterrorcode',
                XMLDB_TYPE_CHAR,
                '100',
                null,
                null,
                null,
                null,
                'recordingsyncattempts'
            ),
            new xmldb_field(
                'recordinglasterrormessage',
                XMLDB_TYPE_TEXT,
                null,
                null,
                null,
                null,
                null,
                'recordinglasterrorcode'
            ),
            new xmldb_field(
                'recordingtimelastattempt',
                XMLDB_TYPE_INTEGER,
                '10',
                null,
                null,
                null,
                null,
                'recordinglasterrormessage'
            ),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        $indexes = [
            new xmldb_index('recordingowneruserid', XMLDB_INDEX_NOTUNIQUE, ['recordingowneruserid']),
            new xmldb_index('recordingsyncstatus', XMLDB_INDEX_NOTUNIQUE, ['recordingsyncstatus']),
        ];
        foreach ($indexes as $index) {
            if (!$dbman->index_exists($table, $index)) {
                $dbman->add_index($table, $index);
            }
        }

        // Remove duplicate references before enforcing activity-scoped idempotency.
        $recordset = $DB->get_recordset(
            'googlemeet_recordings',
            null,
            'googlemeetid ASC, recordingid ASC, id ASC',
            'id, googlemeetid, recordingid'
        );
        $seen = [];
        foreach ($recordset as $recording) {
            $key = $recording->googlemeetid . ':' . $recording->recordingid;
            if (isset($seen[$key])) {
                $DB->delete_records('googlemeet_recordings', ['id' => $recording->id]);
                continue;
            }
            $seen[$key] = true;
        }
        $recordset->close();

        $recordingtable = new xmldb_table('googlemeet_recordings');
        $recordingindex = new xmldb_index(
            'activityrecording',
            XMLDB_INDEX_UNIQUE,
            ['googlemeetid', 'recordingid']
        );
        if (!$dbman->index_exists($recordingtable, $recordingindex)) {
            $dbman->add_index($recordingtable, $recordingindex);
        }

        upgrade_mod_savepoint(true, 2026072608, 'googlemeet');
    }

    return true;
}
