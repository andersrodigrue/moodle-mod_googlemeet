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

namespace mod_googlemeet\local\upgrade;

/**
 * Declares the supported legacy-to-current upgrade contract.
 *
 * This class contains no migration side effects. It provides one reviewable
 * inventory for upgrade tests, form persistence and future removal decisions.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class compatibility_contract {

    /** Last tagged stable release inherited by this fork. */
    public const STABLE_RELEASE = '2.1.1';

    /** Moodle version number stored by the last tagged stable release. */
    public const STABLE_VERSION = 2023050101;

    /** Version immediately before the legacy eventid savepoint. */
    public const PRE_EVENTID_VERSION = 2023042199;

    /** Current alpha release schema and cache savepoint. */
    public const CURRENT_VERSION = 2026081900;

    /** @var int[] Ordered savepoints supported by the current upgrade chain. */
    public const SAVEPOINTS = [
        2023042200,
        2026072601,
        2026072608,
        2026072610,
        2026072612,
        2026072613,
        2026072614,
        2026072615,
        2026072616,
        2026072617,
        2026072618,
        2026072619,
        2026072620,
        2026072621,
        2026072622,
        2026072623,
        self::CURRENT_VERSION,
    ];

    /** @var string[] Activity schedule fields accepted only from legacy data. */
    public const LEGACY_SCHEDULE_FIELDS = [
        'eventdate',
        'starthour',
        'startminute',
        'endhour',
        'endminute',
        'addmultiply',
        'days',
        'period',
        'eventenddate',
    ];

    /** @var string[] Activity identity fields retained for bounded legacy reads. */
    public const LEGACY_IDENTITY_FIELDS = [
        'creatoremail',
        'eventid',
    ];

    /**
     * Returns every retained legacy activity field and its allowed purpose.
     *
     * The values are stable machine-readable identifiers used by tests and the
     * migration report. They must not be displayed directly to end users.
     *
     * @return array<string, array{category: string, writepolicy: string, removalcondition: string}>
     */
    public static function retained_activity_fields(): array {
        $fields = [];
        foreach (self::LEGACY_SCHEDULE_FIELDS as $field) {
            $fields[$field] = [
                'category' => 'schedule',
                'writepolicy' => 'legacy_conversion_only',
                'removalcondition' => 'legacy_backup_restore_window_closed',
            ];
        }

        $fields['creatoremail'] = [
            'category' => 'organizer_identity',
            'writepolicy' => 'privacy_lifecycle_only',
            'removalcondition' => 'legacy_owner_privacy_window_closed',
        ];
        $fields['eventid'] = [
            'category' => 'calendar_identity',
            'writepolicy' => 'legacy_read_only',
            'removalcondition' => 'legacy_calendar_reconnect_window_closed',
        ];

        return $fields;
    }

    /**
     * Whether a submitted field belongs to the conversion-only schedule.
     *
     * @param string $field Field name.
     * @return bool
     */
    public static function is_legacy_schedule_field(string $field): bool {
        return in_array($field, self::LEGACY_SCHEDULE_FIELDS, true);
    }
}
