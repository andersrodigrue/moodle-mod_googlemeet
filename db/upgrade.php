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

    if ($oldversion < 2026072610) {
        $eventtable = new xmldb_table('googlemeet_events');
        $occurrencekeyfield = new xmldb_field(
            'occurrencekey',
            XMLDB_TYPE_CHAR,
            '64',
            null,
            null,
            null,
            null,
            'googlemeetid'
        );
        if (!$dbman->field_exists($eventtable, $occurrencekeyfield)) {
            $dbman->add_field($eventtable, $occurrencekeyfield);
        }
        $calendareventidfield = new xmldb_field(
            'calendareventid',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            null,
            null,
            null,
            'duration'
        );
        if (!$dbman->field_exists($eventtable, $calendareventidfield)) {
            $dbman->add_field($eventtable, $calendareventidfield);
        }

        // Existing expanded rows are the safest source for legacy absolute times.
        $meetings = $DB->get_recordset('googlemeet', null, 'id ASC');
        foreach ($meetings as $meeting) {
            $events = $DB->get_records(
                'googlemeet_events',
                ['googlemeetid' => $meeting->id],
                'eventdate ASC, id ASC'
            );
            $firstevent = $events ? reset($events) : false;
            $update = (object) ['id' => $meeting->id];
            $changed = false;

            if ((int) $meeting->timestart <= 0) {
                $update->timestart = $firstevent
                    ? (int) $firstevent->eventdate
                    : (int) $meeting->eventdate
                        + (int) $meeting->starthour * HOURSECS
                        + (int) $meeting->startminute * MINSECS;
                $changed = true;
            }
            $start = (int) ($update->timestart ?? $meeting->timestart);
            if ((int) $meeting->timeend <= $start) {
                $legacyduration = (int) $meeting->endhour * HOURSECS
                    + (int) $meeting->endminute * MINSECS
                    - (int) $meeting->starthour * HOURSECS
                    - (int) $meeting->startminute * MINSECS;
                $duration = $firstevent ? (int) $firstevent->duration : $legacyduration;
                $update->timeend = $start + max(MINSECS, $duration);
                $changed = true;
            }
            if (trim((string) ($meeting->timezone ?? '')) === '') {
                $update->timezone = \core_date::get_server_timezone();
                $changed = true;
            }
            $eventtimes = array_values(array_unique(array_map(
                static fn(\stdClass $event): int => (int) $event->eventdate,
                array_values($events)
            )));
            if (trim((string) ($meeting->recurrence ?? '')) === '' && count($eventtimes) > 1) {
                $rdates = [];
                foreach (array_slice($eventtimes, 1) as $eventtime) {
                    $rdates[] = gmdate('Ymd\THis\Z', $eventtime);
                }
                $update->recurrence = 'RRULE:FREQ=WEEKLY;COUNT=1'
                    . "\nRDATE:" . implode(',', $rdates);
                $changed = true;
            }
            if ($changed) {
                $DB->update_record('googlemeet', $update);
            }
        }
        $meetings->close();

        // Add stable occurrence keys while consolidating any legacy duplicates.
        $events = $DB->get_recordset(
            'googlemeet_events',
            null,
            'googlemeetid ASC, eventdate ASC, id ASC'
        );
        $kept = [];
        foreach ($events as $event) {
            $key = hash('sha256', 'v1:' . (int) $event->eventdate);
            $activitykey = (int) $event->googlemeetid . ':' . $key;
            if (!isset($kept[$activitykey])) {
                $kept[$activitykey] = (int) $event->id;
                $DB->set_field(
                    'googlemeet_events',
                    'occurrencekey',
                    $key,
                    ['id' => (int) $event->id]
                );
                continue;
            }

            $keeperid = $kept[$activitykey];
            $receipts = $DB->get_records('googlemeet_notify_done', ['eventid' => (int) $event->id]);
            foreach ($receipts as $receipt) {
                if ($DB->record_exists('googlemeet_notify_done', [
                    'eventid' => $keeperid,
                    'userid' => (int) $receipt->userid,
                ])) {
                    $DB->delete_records('googlemeet_notify_done', ['id' => (int) $receipt->id]);
                } else {
                    $DB->set_field(
                        'googlemeet_notify_done',
                        'eventid',
                        $keeperid,
                        ['id' => (int) $receipt->id]
                    );
                }
            }
            $DB->delete_records('googlemeet_events', ['id' => (int) $event->id]);
        }
        $events->close();

        // Associate matching pre-existing Moodle Calendar events where possible.
        $meetings = $DB->get_records('googlemeet', null, 'id ASC', 'id');
        foreach ($meetings as $meeting) {
            $calendarevents = $DB->get_records('event', [
                'modulename' => 'googlemeet',
                'instance' => (int) $meeting->id,
                'eventtype' => \mod_googlemeet\helper::GOOGLEMEET_EVENT_START,
            ], 'timestart ASC, id ASC', 'id, timestart');
            $bytime = [];
            foreach ($calendarevents as $calendarevent) {
                $bytime[(int) $calendarevent->timestart][] = (int) $calendarevent->id;
            }
            $localevents = $DB->get_records(
                'googlemeet_events',
                ['googlemeetid' => (int) $meeting->id],
                'eventdate ASC, id ASC'
            );
            foreach ($localevents as $localevent) {
                $time = (int) $localevent->eventdate;
                if (!empty($bytime[$time])) {
                    $DB->set_field(
                        'googlemeet_events',
                        'calendareventid',
                        array_shift($bytime[$time]),
                        ['id' => (int) $localevent->id]
                    );
                }
            }
        }

        // Remove duplicate receipts before enforcing task idempotency in the database.
        $receipts = $DB->get_recordset(
            'googlemeet_notify_done',
            null,
            'eventid ASC, userid ASC, id ASC'
        );
        $seenreceipts = [];
        foreach ($receipts as $receipt) {
            $key = (int) $receipt->eventid . ':' . (int) $receipt->userid;
            if (isset($seenreceipts[$key])) {
                $DB->delete_records('googlemeet_notify_done', ['id' => (int) $receipt->id]);
            } else {
                $seenreceipts[$key] = true;
            }
        }
        $receipts->close();

        $activityoccurrenceindex = new xmldb_index(
            'activityoccurrence',
            XMLDB_INDEX_UNIQUE,
            ['googlemeetid', 'occurrencekey']
        );

        // A failed pre-release attempt may already have created the index while
        // the field still had its temporary default. Drop that dependency before
        // finalising the field, then recreate the index below.
        if ($dbman->index_exists($eventtable, $activityoccurrenceindex)) {
            $dbman->drop_index($eventtable, $activityoccurrenceindex);
        }
        $finaloccurrencekeyfield = new xmldb_field(
            'occurrencekey',
            XMLDB_TYPE_CHAR,
            '64',
            null,
            XMLDB_NOTNULL,
            null,
            null,
            'googlemeetid'
        );
        $eventcolumns = $DB->get_columns('googlemeet_events');
        if (!empty($eventcolumns['occurrencekey']->has_default)) {
            $dbman->change_field_default($eventtable, $finaloccurrencekeyfield);
            $eventcolumns = $DB->get_columns('googlemeet_events');
        }
        if (empty($eventcolumns['occurrencekey']->not_null)) {
            $dbman->change_field_notnull($eventtable, $finaloccurrencekeyfield);
        }

        $eventindexes = [
            $activityoccurrenceindex,
            new xmldb_index('calendareventid', XMLDB_INDEX_NOTUNIQUE, ['calendareventid']),
            new xmldb_index('eventdate', XMLDB_INDEX_NOTUNIQUE, ['eventdate']),
        ];
        foreach ($eventindexes as $index) {
            if (!$dbman->index_exists($eventtable, $index)) {
                $dbman->add_index($eventtable, $index);
            }
        }

        $receipttable = new xmldb_table('googlemeet_notify_done');
        $receiptindexes = [
            new xmldb_index('eventuser', XMLDB_INDEX_UNIQUE, ['eventid', 'userid']),
            new xmldb_index('userid', XMLDB_INDEX_NOTUNIQUE, ['userid']),
        ];
        foreach ($receiptindexes as $index) {
            if (!$dbman->index_exists($receipttable, $index)) {
                $dbman->add_index($receipttable, $index);
            }
        }

        upgrade_mod_savepoint(true, 2026072610, 'googlemeet');
    }

    if ($oldversion < 2026072612) {
        $table = new xmldb_table('googlemeet');
        $fields = [
            new xmldb_field(
                'guestpolicy',
                XMLDB_TYPE_CHAR,
                '16',
                null,
                XMLDB_NOTNULL,
                null,
                'none',
                'sendupdates'
            ),
            new xmldb_field('guesthash', XMLDB_TYPE_CHAR, '64', null, null, null, null, 'guestpolicy'),
            new xmldb_field(
                'guestcount',
                XMLDB_TYPE_INTEGER,
                '10',
                null,
                XMLDB_NOTNULL,
                null,
                '0',
                'guesthash'
            ),
            new xmldb_field(
                'guesttimelastsync',
                XMLDB_TYPE_INTEGER,
                '10',
                null,
                null,
                null,
                null,
                'guestcount'
            ),
            new xmldb_field(
                'guesttimechecked',
                XMLDB_TYPE_INTEGER,
                '10',
                null,
                null,
                null,
                null,
                'guesttimelastsync'
            ),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        // Existing activities never managed attendees, regardless of a historic
        // sendUpdates value stored by pre-release builds.
        $DB->set_field('googlemeet', 'guestpolicy', 'none');
        $DB->set_field('googlemeet', 'sendupdates', 'none');

        $guestreconcileindex = new xmldb_index(
            'guestreconcile',
            XMLDB_INDEX_NOTUNIQUE,
            ['guestpolicy', 'syncstatus', 'guesttimechecked']
        );
        if (!$dbman->index_exists($table, $guestreconcileindex)) {
            $dbman->add_index($table, $guestreconcileindex);
        }

        $guesttable = new xmldb_table('googlemeet_calendar_guests');
        $guesttable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $guesttable->add_field('googlemeetid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $guesttable->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $guesttable->add_field('emailhash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL);
        $guesttable->add_field(
            'timemodified',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            null,
            '0'
        );
        $guesttable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $guesttable->add_key(
            'googlemeetidfk',
            XMLDB_KEY_FOREIGN,
            ['googlemeetid'],
            'googlemeet',
            ['id']
        );
        $guesttable->add_key('useridfk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $guesttable->add_index('activityuser', XMLDB_INDEX_UNIQUE, ['googlemeetid', 'userid']);
        $guesttable->add_index('activityemail', XMLDB_INDEX_UNIQUE, ['googlemeetid', 'emailhash']);
        if (!$dbman->table_exists($guesttable)) {
            $dbman->create_table($guesttable);
        }

        upgrade_mod_savepoint(true, 2026072612, 'googlemeet');
    }

    if ($oldversion < 2026072613) {
        $table = new xmldb_table('googlemeet_diagnostics');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('googlemeetid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('operation', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL);
        $table->add_field('outcome', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL);
        $table->add_field('source', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL);
        $table->add_field('diagnosticcode', XMLDB_TYPE_CHAR, '100');
        $table->add_field(
            'timecreated',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            null,
            '0'
        );
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key(
            'googlemeetidfk',
            XMLDB_KEY_FOREIGN,
            ['googlemeetid'],
            'googlemeet',
            ['id']
        );
        $table->add_index('activitytime', XMLDB_INDEX_NOTUNIQUE, ['googlemeetid', 'timecreated']);
        $table->add_index('operationoutcome', XMLDB_INDEX_NOTUNIQUE, ['operation', 'outcome']);
        $table->add_index('timecreated', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_mod_savepoint(true, 2026072613, 'googlemeet');
    }

    if ($oldversion < 2026072614) {
        // Calendar selection is resolved against each owner's live Google
        // authorization. Existing `primary` aliases remain valid and are
        // canonicalized to the exact primary Calendar ID on the next edit.
        upgrade_mod_savepoint(true, 2026072614, 'googlemeet');
    }

    if ($oldversion < 2026072615) {
        // Participant meeting links are now protected by a configurable
        // server-side access window and a revalidating local join gateway.
        upgrade_mod_savepoint(true, 2026072615, 'googlemeet');
    }

    if ($oldversion < 2026072616) {
        // Recording mutations now use namespaced external functions with
        // explicit, activity-scoped contracts. No database migration is needed.
        upgrade_mod_savepoint(true, 2026072616, 'googlemeet');
    }

    if ($oldversion < 2026072617) {
        // Activity synchronization and OAuth status now use testable Moodle
        // renderables and Mustache contexts. No database migration is needed.
        upgrade_mod_savepoint(true, 2026072617, 'googlemeet');
    }

    if ($oldversion < 2026072618) {
        // The complete legacy upgrade chain is now covered by an explicit,
        // machine-readable compatibility contract and migration tests. Retained
        // legacy columns are intentionally not removed at this savepoint.
        upgrade_mod_savepoint(true, 2026072618, 'googlemeet');
    }

    if ($oldversion < 2026072619) {
        // CI now rehearses a normal Moodle CLI upgrade from the preserved
        // v2.1.1 schema and verifies the resulting schema and representative
        // legacy data before allowing the fresh-install PHPUnit job to run.
        upgrade_mod_savepoint(true, 2026072619, 'googlemeet');
    }

    if ($oldversion < 2026072620) {
        // Real Google Workspace acceptance now has a protected manual workflow,
        // a CLI-only dedicated-tenant runner and a closed sanitized evidence
        // schema. No database migration is required.
        upgrade_mod_savepoint(true, 2026072620, 'googlemeet');
    }

    if ($oldversion < 2026072621) {
        // Acceptance evidence is now grouped by closed runbook case, campaign
        // and actual Moodle/PHP runtime, with an offline dual-runtime campaign
        // verifier. No database migration is required.
        upgrade_mod_savepoint(true, 2026072621, 'googlemeet');
    }

    if ($oldversion < 2026072622) {
        // A read-only protected readiness gate now proves that the two
        // disposable Moodle environments are isolated and correctly prepared
        // before any live Google acceptance scenario. No migration is needed.
        upgrade_mod_savepoint(true, 2026072622, 'googlemeet');
    }

    if ($oldversion < 2026072623) {
        $table = new xmldb_table('googlemeet_remote_cleanup');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('cleanupkey', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL);
        $table->add_field('owneruserid', XMLDB_TYPE_INTEGER, '10');
        $table->add_field('oauthissuerid', XMLDB_TYPE_INTEGER, '10');
        $table->add_field('calendarid', XMLDB_TYPE_CHAR, '255');
        $table->add_field('googleeventid', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
        $table->add_field('guestcount', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('status', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'pending');
        $table->add_field('attempts', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('lasterrorcode', XMLDB_TYPE_CHAR, '100');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timelastattempt', XMLDB_TYPE_INTEGER, '10');
        $table->add_field('timenextattempt', XMLDB_TYPE_INTEGER, '10');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('cleanupkey', XMLDB_INDEX_UNIQUE, ['cleanupkey']);
        $table->add_index('ownerstatus', XMLDB_INDEX_NOTUNIQUE, ['owneruserid', 'status']);
        $table->add_index('due', XMLDB_INDEX_NOTUNIQUE, ['status', 'timenextattempt']);
        $table->add_index('timecreated', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_mod_savepoint(true, 2026072623, 'googlemeet');
    }

    return true;
}
