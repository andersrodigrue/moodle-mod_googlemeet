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
        ];

        $result = \googlemeet_prepare_integration($data);

        $this->assertSame(integration_mode::MANUAL, $result->integrationmode);
        $this->assertSame(sync_state::READY, $result->syncstatus);
        $this->assertSame($result->url, $result->meetinguri);
        $this->assertNull($result->owneruserid);
        $this->assertNull($result->oauthissuerid);
        $this->assertNull($result->eventid);
        $this->assertNull($result->googleeventid);
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
            'owneruserid' => 999,
            'oauthissuerid' => 888,
            'googleeventid' => 'attacker-controlled',
            'url' => 'https://meet.google.com/abc-defg-hij',
        ];

        $result = \googlemeet_prepare_integration($data, null, $manager);

        $this->assertSame((int) $user->id, $result->owneruserid);
        $this->assertSame(17, $result->oauthissuerid);
        $this->assertSame('primary', $result->calendarid);
        $this->assertSame(sync_state::DRAFT, $result->syncstatus);
        $this->assertSame('', $result->url);
        $this->assertNull($result->meetinguri);
        $this->assertNull($result->googleeventid);
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
}
