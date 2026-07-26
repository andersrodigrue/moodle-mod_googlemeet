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

use mod_googlemeet\api\calendar_authorization_exception;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Minimal configured issuer fake.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class oauth_manager_issuer {

    /** @var bool Whether the issuer is enabled. */
    private bool $enabled;

    /** @var string Authorization URL. */
    private string $authorizationurl;

    /**
     * @param bool $enabled Whether the issuer is enabled.
     * @param string $authorizationurl Authorization URL.
     */
    public function __construct(
        bool $enabled = true,
        string $authorizationurl = 'https://accounts.google.com/o/oauth2/v2/auth'
    ) {
        $this->enabled = $enabled;
        $this->authorizationurl = $authorizationurl;
    }

    /**
     * @param string $property Property name.
     * @return mixed
     */
    public function get(string $property): mixed {
        return $property === 'enabled' ? $this->enabled : null;
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
        return $type === 'authorization' ? $this->authorizationurl : false;
    }
}

/**
 * Tests for owner-scoped Moodle OAuth composition.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(oauth_manager::class)]
final class oauth_manager_test extends \advanced_testcase {

    /**
     * The authenticated client uses only Calendar events and enables refresh.
     */
    public function test_builds_least_privilege_autorefresh_client(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $client = $this->createMock(\core\oauth2\client::class);
        $client->expects($this->once())->method('is_logged_in')->willReturn(true);
        $captured = null;
        $manager = new oauth_manager(
            static fn(int $issuerid) => new oauth_manager_issuer(),
            static function ($issuer, \moodle_url $returnurl, string $scopes, bool $autorefresh) use (
                $client,
                &$captured
            ) {
                $captured = [$returnurl, $scopes, $autorefresh];
                return $client;
            }
        );

        $result = $manager->authenticated_client((object) [
            'owneruserid' => $user->id,
            'oauthissuerid' => 17,
        ]);

        $this->assertSame($client, $result);
        $this->assertSame(oauth_manager::CALENDAR_SCOPE, $captured[1]);
        $this->assertTrue($captured[2]);
        $this->assertSame(1, (int) $captured[0]->get_param('managed'));
        $this->assertSame(17, (int) $captured[0]->get_param('issuerid'));
    }

    /**
     * A task cannot borrow another teacher's stored OAuth token.
     */
    public function test_rejects_task_running_as_different_user(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->setUser($other);

        $manager = new oauth_manager(
            static function (): void {
                throw new \coding_exception('The issuer must not be loaded.');
            },
            static function (): void {
                throw new \coding_exception('The OAuth client must not be created.');
            }
        );

        $this->expectException(calendar_authorization_exception::class);
        $manager->authenticated_client((object) [
            'owneruserid' => $owner->id,
            'oauthissuerid' => 17,
        ]);
    }

    /**
     * A custom issuer cannot redirect the bearer flow away from Google.
     */
    public function test_rejects_non_google_issuer(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $manager = new oauth_manager(
            static fn(int $issuerid) => new oauth_manager_issuer(
                true,
                'https://example.com/oauth/authorize'
            ),
            static function (): void {
                throw new \coding_exception('The OAuth client must not be created.');
            }
        );

        $this->expectException(calendar_authorization_exception::class);
        $manager->authenticated_client((object) [
            'owneruserid' => $user->id,
            'oauthissuerid' => 17,
        ]);
    }

    /**
     * Moodle returning false after refresh-token rejection requires reconnect.
     */
    public function test_rejected_refresh_token_requires_reconnect(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $client = $this->createMock(\core\oauth2\client::class);
        $client->expects($this->once())->method('is_logged_in')->willReturn(false);
        $manager = new oauth_manager(
            static fn(int $issuerid) => new oauth_manager_issuer(),
            static fn() => $client
        );

        $this->expectException(calendar_authorization_exception::class);
        $manager->authenticated_client((object) [
            'owneruserid' => $user->id,
            'oauthissuerid' => 17,
        ]);
    }

    /**
     * Explicit disconnect delegates token removal to Moodle core.
     */
    public function test_revoke_logs_out_plugin_scoped_client(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $client = $this->createMock(\core\oauth2\client::class);
        $client->expects($this->once())->method('log_out');
        $manager = new oauth_manager(
            static fn(int $issuerid) => new oauth_manager_issuer(),
            static fn() => $client
        );

        $manager->revoke((object) [
            'owneruserid' => $user->id,
            'oauthissuerid' => 17,
        ]);
        $this->addToAssertionCount(1);
    }
}
