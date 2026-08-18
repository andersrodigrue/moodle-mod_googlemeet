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

use mod_googlemeet\api\recording_authorization_exception;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Minimal configured recording issuer fake.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class recording_oauth_manager_issuer {

    /** @var string Authorization URL. */
    private string $authorizationurl;

    /**
     * @param string $authorizationurl Authorization endpoint.
     */
    public function __construct(
        string $authorizationurl = 'https://accounts.google.com/o/oauth2/v2/auth'
    ) {
        $this->authorizationurl = $authorizationurl;
    }

    /**
     * @param string $property Issuer property.
     * @return mixed
     */
    public function get(string $property): mixed {
        return $property === 'enabled';
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
 * Tests for dedicated per-teacher recording OAuth composition.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(recording_oauth_manager::class)]
final class recording_oauth_manager_test extends \advanced_testcase {

    /**
     * The client requests only Meet metadata and enables token refresh.
     */
    public function test_builds_least_privilege_autorefresh_client(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        set_config('issuerid', 11, 'googlemeet');

        $client = $this->createMock(\core\oauth2\client::class);
        $client->expects($this->once())->method('is_logged_in')->willReturn(true);
        $captured = null;
        $manager = new recording_oauth_manager(
            static fn(int $issuerid) => new recording_oauth_manager_issuer(),
            static function ($issuer, \moodle_url $returnurl, string $scope, bool $autorefresh) use (
                $client,
                &$captured
            ) {
                $captured = [$returnurl, $scope, $autorefresh];
                return $client;
            }
        );

        $result = $manager->authenticated_client((object) [
            'id' => 42,
            'recordingowneruserid' => $user->id,
            'recordingoauthissuerid' => 17,
        ]);

        $this->assertSame($client, $result);
        $this->assertSame(recording_oauth_manager::RECORDING_SCOPE, $captured[1]);
        $this->assertTrue($captured[2]);
        $this->assertSame(1, (int) $captured[0]->get_param('recordings'));
        $this->assertSame(42, (int) $captured[0]->get_param('googlemeetid'));
        $this->assertSame(17, (int) $captured[0]->get_param('issuerid'));
        $this->assertSame(sesskey(), $captured[0]->get_param('sesskey'));
    }

    /**
     * A task cannot borrow another teacher's OAuth grant.
     */
    public function test_rejects_task_running_as_another_user(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->setUser($other);

        $manager = new recording_oauth_manager(
            static function (): void {
                throw new \coding_exception('The issuer must not be loaded.');
            },
            static function (): void {
                throw new \coding_exception('The client must not be created.');
            }
        );

        $this->expectException(recording_authorization_exception::class);
        $manager->authenticated_client((object) [
            'id' => 42,
            'recordingowneruserid' => $owner->id,
            'recordingoauthissuerid' => 17,
        ]);
    }

    /**
     * Calendar and recording grants cannot share an issuer namespace.
     */
    public function test_rejects_calendar_issuer_reuse(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        set_config('issuerid', 17, 'googlemeet');

        $this->expectException(recording_authorization_exception::class);
        (new recording_oauth_manager())->authorization_client(17, $user->id, 42);
    }

    /**
     * A custom issuer cannot redirect authorization away from Google.
     */
    public function test_rejects_non_google_issuer(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        set_config('issuerid', 11, 'googlemeet');

        $manager = new recording_oauth_manager(
            static fn(int $issuerid) => new recording_oauth_manager_issuer(
                'https://example.com/oauth/authorize'
            ),
            static function (): void {
                throw new \coding_exception('The client must not be created.');
            }
        );

        $this->expectException(recording_authorization_exception::class);
        $manager->authorization_client(17, $user->id, 42);
    }

    /**
     * Configuration accepts only a distinct pair of positive issuer IDs.
     */
    public function test_configured_issuer_must_be_distinct(): void {
        $this->resetAfterTest();
        set_config('issuerid', 11, 'googlemeet');
        set_config('recordingissuerid', 11, 'googlemeet');
        $this->assertSame(0, recording_oauth_manager::configured_issuer_id());

        set_config('recordingissuerid', 17, 'googlemeet');
        $this->assertSame(17, recording_oauth_manager::configured_issuer_id());
    }
}
