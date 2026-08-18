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

namespace mod_googlemeet\output;

use mod_googlemeet\api\recording_authorization_exception;
use mod_googlemeet\local\recording_sync_state;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Minimal recording OAuth client fake.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class recording_panel_oauth_client {

    /**
     * @param bool $loggedin Whether the fake grant is connected.
     */
    public function __construct(private readonly bool $loggedin) {
    }

    /**
     * @return bool
     */
    public function is_logged_in(): bool {
        return $this->loggedin;
    }

    /**
     * @return \moodle_url
     */
    public function get_login_url(): \moodle_url {
        return new \moodle_url('/auth/oauth2/login.php', ['id' => 17]);
    }
}

/**
 * Tests for the recording presentation and OAuth view model.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(recording_panel::class)]
#[CoversClass(recording_sync_state::class)]
final class recording_panel_test extends \advanced_testcase {

    /**
     * Every recording discovery state has a deterministic presentation.
     *
     * @param string $state Stored state.
     * @param string $badge Expected Bootstrap badge.
     * @param bool $inprogress Whether a progress indicator is shown.
     */
    #[DataProvider('recording_state_provider')]
    public function test_every_recording_state_has_a_presentation(
        string $state,
        string $badge,
        bool $inprogress
    ): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $data = $this->panel(
            ['recordingsyncstatus' => $state],
            false,
            false,
            true,
            0,
            static fn() => new recording_panel_oauth_client(true)
        )->export_for_template($this->renderer());

        $this->assertSame($state, $data['recordingsyncstate']);
        $this->assertSame($badge, $data['recordingsyncbadgeclass']);
        $this->assertSame($inprogress, $data['recordingsyncinprogress']);
        $this->assertSame(
            get_string('recordingsyncstatus' . $state, 'mod_googlemeet'),
            $data['recordingsynclabel']
        );
    }

    /**
     * Recording state presentation cases.
     *
     * @return array<string, array{string, string, bool}>
     */
    public static function recording_state_provider(): array {
        return [
            'disconnected' => [recording_sync_state::DISCONNECTED, 'text-bg-danger', false],
            'queued' => [recording_sync_state::QUEUED, 'text-bg-info', true],
            'syncing' => [recording_sync_state::SYNCING, 'text-bg-info', true],
            'ready' => [recording_sync_state::READY, 'text-bg-success', false],
            'failed' => [recording_sync_state::FAILED, 'text-bg-danger', false],
        ];
    }

    /**
     * Invalid stored state falls back to failed without exposing an unsafe value.
     */
    public function test_invalid_recording_state_fails_closed(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $data = $this->panel(
            [
                'recordingsyncstatus' => 'unexpected',
                'recordinglasterrormessage' => '<unsafe>',
            ],
            false,
            false,
            true,
            0,
            static fn() => new recording_panel_oauth_client(true)
        )->export_for_template($this->renderer());

        $this->assertSame(recording_sync_state::FAILED, $data['recordingsyncstate']);
        $this->assertTrue($data['hasrecordingerror']);
        $this->assertSame('<unsafe>', $data['recordingerrormessage']);
    }

    /**
     * Users without the synchronization capability trigger no OAuth work.
     */
    public function test_hidden_sync_panel_does_not_resolve_oauth(): void {
        $called = false;
        $panel = $this->panel(
            [],
            false,
            false,
            false,
            0,
            static function () use (&$called): void {
                $called = true;
            }
        );
        $data = $panel->export_for_template($this->renderer());

        $this->assertFalse($data['showsync']);
        $this->assertFalse($called);
        $this->assertArrayNotHasKey('recordingsyncstate', $data);
    }

    /**
     * A missing dedicated issuer shows a warning and never builds a client.
     */
    public function test_missing_issuer_is_a_warning(): void {
        global $USER;

        $this->resetAfterTest();
        $this->setAdminUser();
        $called = false;
        $data = $this->panel(
            ['recordingowneruserid' => $USER->id],
            false,
            false,
            true,
            $USER->id,
            static function () use (&$called): void {
                $called = true;
            },
            0
        )->export_for_template($this->renderer());

        $this->assertTrue($data['hasoauthnotice']);
        $this->assertSame('alert-warning', $data['oauthnoticeclass']);
        $this->assertSame('alert', $data['oauthnoticerole']);
        $this->assertFalse($called);
        $this->assertArrayNotHasKey('canconnect', $data);
        $this->assertTrue($data['candisconnect']);
        $this->assertSame(sesskey(), $data['sesskey']);
    }

    /**
     * Another recording owner receives information but no owner command.
     */
    public function test_another_owner_has_no_oauth_or_commands(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $data = $this->panel(
            ['recordingowneruserid' => 99],
            false,
            false,
            true,
            7,
            static function (): void {
                throw new \coding_exception('OAuth must not be resolved for another owner.');
            }
        )->export_for_template($this->renderer());

        $this->assertSame('alert-info', $data['oauthnoticeclass']);
        $this->assertSame('status', $data['oauthnoticerole']);
        $this->assertFalse($data['candisconnect']);
        $this->assertArrayNotHasKey('commandurl', $data);
    }

    /**
     * A connected owner receives POST sync and disconnect commands.
     */
    public function test_connected_owner_receives_session_protected_commands(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $data = $this->panel(
            ['recordingowneruserid' => $user->id],
            false,
            false,
            true,
            $user->id,
            static fn() => new recording_panel_oauth_client(true)
        )->export_for_template($this->renderer());

        $this->assertTrue($data['cansync']);
        $this->assertTrue($data['candisconnect']);
        $this->assertTrue($data['oauthconnected']);
        $this->assertSame(sesskey(), $data['sesskey']);
        $this->assertStringEndsWith('/mod/googlemeet/recordings.php', $data['commandurl']);
    }

    /**
     * A disconnected grant exposes only a normal safe connect link.
     */
    public function test_logged_out_grant_exposes_connect_link(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $data = $this->panel(
            [],
            false,
            false,
            true,
            0,
            static fn() => new recording_panel_oauth_client(false)
        )->export_for_template($this->renderer());

        $this->assertTrue($data['canconnect']);
        $this->assertStringContainsString('/auth/oauth2/login.php', $data['connecturl']);
        $this->assertArrayNotHasKey('cansync', $data);
        $this->assertFalse($data['candisconnect']);
    }

    /**
     * OAuth composition failure is reduced to a localized warning.
     */
    public function test_oauth_failure_exposes_no_exception_detail(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $data = $this->panel(
            [],
            false,
            false,
            true,
            0,
            static function (): void {
                throw new recording_authorization_exception('secret remote detail');
            }
        )->export_for_template($this->renderer());

        $this->assertSame(
            get_string('recordingsoauthunavailable', 'mod_googlemeet'),
            $data['oauthnoticemessage']
        );
        $this->assertStringNotContainsString('secret remote detail', json_encode($data));
    }

    /**
     * Search enhancement is limited to read-only presentation.
     */
    public function test_jstable_and_amd_configuration_follow_mutation_capabilities(): void {
        $recording = (object) ['id' => 1];
        $readonly = new recording_panel(
            $this->meeting(),
            42,
            [$recording],
            false,
            false,
            false,
            0,
            0
        );
        $this->assertTrue($readonly->has_recordings());
        $this->assertTrue($readonly->requires_jstable());
        $this->assertTrue($readonly->javascript_config()['searchable']);

        $editor = new recording_panel(
            $this->meeting(),
            42,
            [$recording],
            true,
            true,
            false,
            0,
            0
        );
        $this->assertFalse($editor->requires_jstable());
        $this->assertFalse($editor->javascript_config()['searchable']);

        $remover = new recording_panel(
            $this->meeting(),
            42,
            [$recording],
            false,
            true,
            false,
            0,
            0
        );
        $this->assertFalse($remover->requires_jstable());
    }

    /**
     * The rendered panel uses semantic POST forms and contains no inline script.
     */
    public function test_template_renders_accessible_safe_controls(): void {
        global $OUTPUT;

        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $panel = $this->panel(
            [
                'recordingowneruserid' => $user->id,
                'recordinglasterrormessage' => '<script>alert(1)</script>',
            ],
            false,
            false,
            true,
            $user->id,
            static fn() => new recording_panel_oauth_client(true)
        );
        $html = $OUTPUT->render($panel);

        $this->assertStringContainsString('method="post"', $html);
        $this->assertStringContainsString('name="sesskey"', $html);
        $this->assertStringContainsString('data-region="recording-sync-status"', $html);
        $this->assertStringContainsString('aria-labelledby=', $html);
        $this->assertStringNotContainsString('onclick=', $html);
        $this->assertStringNotContainsString('<script', $html);
    }

    /**
     * Creates a panel with deterministic OAuth configuration.
     *
     * @param array<string, mixed> $meetingfields Meeting overrides.
     * @param bool $canedit Edit capability.
     * @param bool $canremove Removal capability.
     * @param bool $cansync Synchronization capability.
     * @param int $userid Current user.
     * @param callable $factory OAuth client factory.
     * @param int $issuerid Dedicated issuer.
     * @return recording_panel
     */
    private function panel(
        array $meetingfields,
        bool $canedit,
        bool $canremove,
        bool $cansync,
        int $userid,
        callable $factory,
        int $issuerid = 17
    ): recording_panel {
        return new recording_panel(
            (object) ($meetingfields + (array) $this->meeting()),
            42,
            [],
            $canedit,
            $canremove,
            $cansync,
            $userid,
            $issuerid,
            $factory
        );
    }

    /**
     * Returns a minimal recording-enabled activity.
     *
     * @return \stdClass
     */
    private function meeting(): \stdClass {
        return (object) [
            'id' => 23,
            'recordingsyncstatus' => recording_sync_state::DISCONNECTED,
            'recordingowneruserid' => 0,
            'lastsync' => 0,
        ];
    }

    /**
     * Returns a renderer accepted by the templatable contract.
     *
     * @return \renderer_base
     */
    private function renderer(): \renderer_base {
        return $this->createMock(\renderer_base::class);
    }
}
