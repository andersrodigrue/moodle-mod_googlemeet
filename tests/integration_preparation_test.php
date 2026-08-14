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

require_once(__DIR__ . '/../lib.php');

use mod_googlemeet\api\calendar_list_client;
use mod_googlemeet\local\calendar_catalog;
use mod_googlemeet\local\calendar_guest_policy;
use mod_googlemeet\local\integration_mode;
use mod_googlemeet\local\oauth_manager;
use mod_googlemeet\local\sync_state;
use PHPUnit\Framework\Attributes\CoversFunction;

/**
 * Minimal Google issuer used by integration preparation tests.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class integration_preparation_issuer {

    /**
     * @param string $property Issuer property.
     * @return mixed
     */
    public function get(string $property): mixed {
        return $property === 'enabled' ? true : null;
    }

    /**
     * @return bool
     */
    public function is_configured(): bool {
        return true;
    }

    /**
     * @param string $type Endpoint type.
     * @return string|false
     */
    public function get_endpoint_url(string $type): string|false {
        return $type === 'authorization'
            ? 'https://accounts.google.com/o/oauth2/v2/auth'
            : false;
    }
}

/**
 * Owner CalendarList transport used by integration preparation tests.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class integration_preparation_calendar_list_client implements calendar_list_client {

    /** @var array<int, array<string, mixed>> Writable CalendarList entries. */
    private array $items;

    /**
     * @param array<int, array<string, mixed>> $items Writable CalendarList entries.
     */
    public function __construct(array $items) {
        $this->items = $items;
    }

    /**
     * @param string|null $pagetoken Opaque Google page token.
     * @return array<string, mixed>
     */
    public function list_writable_calendars(?string $pagetoken = null): array {
        return ['items' => $this->items];
    }
}

/**
 * Tests server-owned integration preparation.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversFunction('googlemeet_prepare_integration')]
final class integration_preparation_test extends \advanced_testcase {

    /**
     * Manual mode clears submitted integration ownership and remote identity.
     */
    public function test_manual_mode_clears_managed_identity(): void {
        $this->resetAfterTest();
        $data = (object) [
            'integrationmode' => integration_mode::MANUAL,
            'url' => 'https://meet.google.com/abc-defg-hij',
            'owneruserid' => 999,
            'oauthissuerid' => 888,
            'eventid' => 'legacy',
            'googleeventid' => 'remote',
            'guestpolicy' => calendar_guest_policy::COURSE,
            'guesthash' => str_repeat('a', 64),
            'guestcount' => 99,
        ];

        $result = \googlemeet_prepare_integration($data);

        $this->assertSame(integration_mode::MANUAL, $result->integrationmode);
        $this->assertSame(sync_state::READY, $result->syncstatus);
        $this->assertSame($result->url, $result->meetinguri);
        $this->assertNull($result->owneruserid);
        $this->assertNull($result->oauthissuerid);
        $this->assertNull($result->eventid);
        $this->assertNull($result->googleeventid);
        $this->assertSame(calendar_guest_policy::NONE, $result->guestpolicy);
        $this->assertNull($result->guesthash);
        $this->assertSame(0, $result->guestcount);
        $this->assertSame('none', $result->sendupdates);
    }

    /**
     * Managed mode derives owner and issuer on the server.
     */
    public function test_managed_mode_overrides_submitted_owner_and_remote_ids(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        set_config('issuerid', 17, 'googlemeet');

        $client = $this->createMock(\core\oauth2\client::class);
        $client->expects($this->once())->method('is_logged_in')->willReturn(true);
        $manager = new oauth_manager(
            static fn(int $issuerid) => new integration_preparation_issuer(),
            static fn() => $client
        );
        $data = (object) [
            'integrationmode' => integration_mode::MANAGED,
            'calendarid' => 'course-calendar@example.com',
            'owneruserid' => 999,
            'oauthissuerid' => 888,
            'googleeventid' => 'attacker-controlled',
            'guestpolicy' => calendar_guest_policy::COURSE,
            'guesthash' => str_repeat('b', 64),
            'guestcount' => 999,
            'url' => 'https://meet.google.com/abc-defg-hij',
        ];

        $result = \googlemeet_prepare_integration(
            $data,
            null,
            $manager,
            $this->calendar_catalog([
                $this->calendar('course-calendar@example.com', 'Course calendar', false, 'writer'),
            ])
        );

        $this->assertSame((int) $user->id, $result->owneruserid);
        $this->assertSame(17, $result->oauthissuerid);
        $this->assertSame('course-calendar@example.com', $result->calendarid);
        $this->assertSame(sync_state::DRAFT, $result->syncstatus);
        $this->assertSame('', $result->url);
        $this->assertNull($result->meetinguri);
        $this->assertNull($result->googleeventid);
        $this->assertSame(calendar_guest_policy::COURSE, $result->guestpolicy);
        $this->assertSame('all', $result->sendupdates);
        $this->assertNull($result->guesthash);
        $this->assertSame(0, $result->guestcount);
    }

    /**
     * Managed persistence cannot be reached without a completed authorization.
     */
    public function test_managed_mode_requires_authorized_client(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        set_config('issuerid', 17, 'googlemeet');

        $client = $this->createMock(\core\oauth2\client::class);
        $client->expects($this->once())->method('is_logged_in')->willReturn(false);
        $manager = new oauth_manager(
            static fn(int $issuerid) => new integration_preparation_issuer(),
            static fn() => $client
        );

        $this->expectException(\moodle_exception::class);
        \googlemeet_prepare_integration(
            (object) ['integrationmode' => integration_mode::MANAGED],
            null,
            $manager
        );
    }

    /**
     * An existing managed event cannot be silently downgraded.
     */
    public function test_existing_managed_meeting_cannot_become_manual(): void {
        $this->resetAfterTest();
        $data = (object) [
            'integrationmode' => integration_mode::MANUAL,
            'url' => 'https://meet.google.com/abc-defg-hij',
        ];
        $existing = (object) [
            'integrationmode' => integration_mode::MANAGED,
        ];

        $this->expectException(\moodle_exception::class);
        \googlemeet_prepare_integration($data, $existing);
    }

    /**
     * Another editor cannot take over a managed owner's stored authorization.
     */
    public function test_existing_managed_meeting_rejects_different_owner(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->setUser($other);

        $data = (object) [
            'integrationmode' => integration_mode::MANAGED,
        ];
        $existing = (object) [
            'integrationmode' => integration_mode::MANAGED,
            'owneruserid' => $owner->id,
        ];

        $this->expectException(\moodle_exception::class);
        \googlemeet_prepare_integration($data, $existing);
    }

    /**
     * A restored ownerless managed copy can be explicitly claimed.
     */
    public function test_restored_managed_meeting_can_be_claimed(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        set_config('issuerid', 17, 'googlemeet');

        $client = $this->createMock(\core\oauth2\client::class);
        $client->expects($this->once())->method('is_logged_in')->willReturn(true);
        $manager = new oauth_manager(
            static fn(int $issuerid) => new integration_preparation_issuer(),
            static fn() => $client
        );
        $existing = (object) [
            'integrationmode' => integration_mode::MANAGED,
            'owneruserid' => null,
            'url' => '',
            'meetinguri' => null,
        ];

        $result = \googlemeet_prepare_integration(
            (object) [
                'integrationmode' => integration_mode::MANAGED,
                'calendarid' => 'restored-calendar@example.com',
            ],
            $existing,
            $manager,
            $this->calendar_catalog([
                $this->calendar('restored-calendar@example.com', 'Restored calendar'),
            ])
        );

        $this->assertSame((int) $user->id, $result->owneruserid);
        $this->assertSame(17, $result->oauthissuerid);
        $this->assertSame('restored-calendar@example.com', $result->calendarid);
    }

    /**
     * An existing event cannot be redirected by a forged Calendar selection.
     */
    public function test_existing_managed_calendar_is_immutable(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        set_config('issuerid', 17, 'googlemeet');

        $client = $this->createMock(\core\oauth2\client::class);
        $client->expects($this->once())->method('is_logged_in')->willReturn(true);
        $manager = new oauth_manager(
            static fn(int $issuerid) => new integration_preparation_issuer(),
            static fn() => $client
        );
        $existing = (object) [
            'integrationmode' => integration_mode::MANAGED,
            'owneruserid' => $user->id,
            'calendarid' => 'bound-calendar@example.com',
            'url' => '',
            'meetinguri' => null,
        ];

        $result = \googlemeet_prepare_integration(
            (object) [
                'integrationmode' => integration_mode::MANAGED,
                'calendarid' => 'attacker-calendar@example.com',
            ],
            $existing,
            $manager,
            $this->calendar_catalog([
                $this->calendar('bound-calendar@example.com', 'Bound calendar'),
                $this->calendar('attacker-calendar@example.com', 'Other calendar'),
            ])
        );

        $this->assertSame('bound-calendar@example.com', $result->calendarid);
    }

    /**
     * Pre-release primary aliases are upgraded to the exact primary ID on edit.
     */
    public function test_existing_primary_alias_is_canonicalized(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        set_config('issuerid', 17, 'googlemeet');

        $client = $this->createMock(\core\oauth2\client::class);
        $client->expects($this->once())->method('is_logged_in')->willReturn(true);
        $manager = new oauth_manager(
            static fn(int $issuerid) => new integration_preparation_issuer(),
            static fn() => $client
        );
        $existing = (object) [
            'integrationmode' => integration_mode::MANAGED,
            'owneruserid' => $user->id,
            'calendarid' => 'primary',
            'url' => '',
            'meetinguri' => null,
        ];

        $result = \googlemeet_prepare_integration(
            (object) ['integrationmode' => integration_mode::MANAGED],
            $existing,
            $manager,
            $this->calendar_catalog([
                $this->calendar('teacher@example.com', 'Teacher', true, 'owner'),
            ])
        );

        $this->assertSame('teacher@example.com', $result->calendarid);
    }

    /**
     * Builds a deterministic writable Calendar catalog.
     *
     * @param array<int, array<string, mixed>> $items CalendarList entries.
     * @return calendar_catalog
     */
    private function calendar_catalog(array $items): calendar_catalog {
        return new calendar_catalog(new integration_preparation_calendar_list_client($items));
    }

    /**
     * Builds one CalendarList entry.
     *
     * @param string $id Calendar ID.
     * @param string $summary Display name.
     * @param bool $primary Whether this is the primary calendar.
     * @param string $accessrole Effective user role.
     * @return array<string, mixed>
     */
    private function calendar(
        string $id,
        string $summary,
        bool $primary = false,
        string $accessrole = 'writer'
    ): array {
        return [
            'id' => $id,
            'summary' => $summary,
            'primary' => $primary,
            'accessRole' => $accessrole,
            'conferenceProperties' => [
                'allowedConferenceSolutionTypes' => ['hangoutsMeet'],
            ],
        ];
    }
}
