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

use mod_googlemeet\api\calendar_configuration_exception;
use mod_googlemeet\api\calendar_list_client;
use mod_googlemeet\api\calendar_response_exception;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Deterministic CalendarList transport for catalog tests.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class calendar_catalog_list_client implements calendar_list_client {

    /** @var array<int, array<string, mixed>> Queued CalendarList pages. */
    public array $responses = [];

    /** @var array<int, string|null> Page tokens received from the catalog. */
    public array $pagetokens = [];

    /**
     * Returns the next configured CalendarList page.
     *
     * @param string|null $pagetoken Opaque Google page token.
     * @return array<string, mixed>
     */
    public function list_writable_calendars(?string $pagetoken = null): array {
        $this->pagetokens[] = $pagetoken;
        return array_shift($this->responses) ?? ['items' => []];
    }
}

/**
 * Tests owner-scoped writable Calendar selection.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(calendar_catalog::class)]
final class calendar_catalog_test extends \advanced_testcase {

    /**
     * Pagination is bounded and only writable Meet-compatible entries survive.
     */
    public function test_loads_and_orders_writable_meet_calendars(): void {
        $client = new calendar_catalog_list_client();
        $client->responses = [
            [
                'items' => [
                    [
                        'id' => 'readonly@example.com',
                        'summary' => 'Read only',
                        'accessRole' => 'reader',
                    ],
                    [
                        'id' => 'rooms@example.com',
                        'summary' => 'Rooms',
                        'accessRole' => 'writer',
                        'conferenceProperties' => [
                            'allowedConferenceSolutionTypes' => ['eventHangout'],
                        ],
                    ],
                    [
                        'id' => 'secondary@example.com',
                        'summaryOverride' => ' Team calendar ',
                        'accessRole' => 'writerWithoutPrivateAccess',
                        'conferenceProperties' => [
                            'allowedConferenceSolutionTypes' => ['hangoutsMeet'],
                        ],
                    ],
                ],
                'nextPageToken' => 'page-two',
            ],
            [
                'items' => [
                    [
                        'id' => 'owner@example.com',
                        'summary' => 'Personal',
                        'accessRole' => 'owner',
                        'primary' => true,
                    ],
                    [
                        'id' => 'deleted@example.com',
                        'summary' => 'Deleted',
                        'accessRole' => 'owner',
                        'deleted' => true,
                    ],
                ],
            ],
        ];

        $catalog = new calendar_catalog($client);
        $calendars = $catalog->writable_calendars();

        $this->assertSame([null, 'page-two'], $client->pagetokens);
        $this->assertSame(
            ['owner@example.com', 'secondary@example.com'],
            array_column($calendars, 'id')
        );
        $this->assertSame('Personal', $calendars[0]['summary']);
        $this->assertSame('Team calendar', $calendars[1]['summary']);
        $this->assertSame('owner@example.com', $catalog->default_calendar_id());

        // The catalog is cached for the form and server-side preflight.
        $catalog->writable_calendars();
        $this->assertCount(2, $client->pagetokens);
    }

    /**
     * The historic primary alias resolves only to the real primary Calendar ID.
     */
    public function test_primary_alias_is_canonicalized_without_fallback(): void {
        $client = new calendar_catalog_list_client();
        $client->responses[] = [
            'items' => [
                [
                    'id' => 'primary-owner@example.com',
                    'summary' => 'Owner',
                    'accessRole' => 'owner',
                    'primary' => true,
                ],
                [
                    'id' => 'shared@example.com',
                    'summary' => 'Shared',
                    'accessRole' => 'writer',
                ],
            ],
        ];
        $catalog = new calendar_catalog($client);

        $this->assertSame(
            'primary-owner@example.com',
            $catalog->require_writable('primary')['id']
        );
        $this->assertSame(
            'shared@example.com',
            $catalog->require_writable('shared@example.com')['id']
        );
    }

    /**
     * An unavailable submitted ID fails closed instead of selecting another calendar.
     */
    public function test_unavailable_selection_is_rejected(): void {
        $client = new calendar_catalog_list_client();
        $client->responses[] = [
            'items' => [[
                'id' => 'available@example.com',
                'summary' => 'Available',
                'accessRole' => 'owner',
                'primary' => true,
            ]],
        ];

        $this->expectException(calendar_configuration_exception::class);
        (new calendar_catalog($client))->require_writable('removed@example.com');
    }

    /**
     * Repeated opaque page tokens are treated as an inconsistent response.
     */
    public function test_repeated_page_token_is_rejected(): void {
        $client = new calendar_catalog_list_client();
        $client->responses = [
            ['items' => [], 'nextPageToken' => 'loop'],
            ['items' => [], 'nextPageToken' => 'loop'],
        ];

        $this->expectException(calendar_response_exception::class);
        (new calendar_catalog($client))->writable_calendars();
    }

    /**
     * Duplicate IDs cannot make exact selection ambiguous.
     */
    public function test_duplicate_calendar_id_is_rejected(): void {
        $client = new calendar_catalog_list_client();
        $client->responses[] = [
            'items' => [
                [
                    'id' => 'duplicate@example.com',
                    'summary' => 'One',
                    'accessRole' => 'writer',
                ],
                [
                    'id' => 'duplicate@example.com',
                    'summary' => 'Two',
                    'accessRole' => 'owner',
                ],
            ],
        ];

        $this->expectException(calendar_response_exception::class);
        (new calendar_catalog($client))->writable_calendars();
    }
}
