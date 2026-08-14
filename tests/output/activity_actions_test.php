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

use mod_googlemeet\local\integration_mode;
use mod_googlemeet\local\meeting_access_result;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for safe activity action presentation.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(activity_actions::class)]
final class activity_actions_test extends \advanced_testcase {

    /**
     * An allowed decision exports only the local revalidating gateway.
     */
    public function test_join_action_never_exports_provider_uri(): void {
        $data = (new activity_actions(
            (object) ['integrationmode' => integration_mode::MANUAL],
            new meeting_access_result(
                meeting_access_result::AVAILABLE,
                'https://meet.google.com/abc-defg-hij'
            ),
            42,
            false
        ))->export_for_template($this->renderer());

        $this->assertTrue($data['canjoin']);
        $this->assertStringContainsString('/mod/googlemeet/join.php', $data['joinurl']);
        $this->assertStringNotContainsString('meet.google.com', json_encode($data));
        $this->assertArrayNotHasKey('availabilitymessage', $data);
    }

    /**
     * Unavailable and invalid states produce accessible notice models.
     */
    public function test_unavailable_actions_distinguish_status_and_error_notices(): void {
        $scheduled = (new activity_actions(
            (object) ['integrationmode' => integration_mode::MANUAL],
            new meeting_access_result(
                meeting_access_result::SCHEDULED,
                null,
                1785405600
            ),
            42,
            false
        ))->export_for_template($this->renderer());
        $this->assertSame('status', $scheduled['availabilityrole']);
        $this->assertSame('alert-info', $scheduled['availabilityclass']);

        $invalid = (new activity_actions(
            (object) ['integrationmode' => integration_mode::MANUAL],
            new meeting_access_result(meeting_access_result::INVALID_LINK),
            42,
            false
        ))->export_for_template($this->renderer());
        $this->assertSame('alert', $invalid['availabilityrole']);
        $this->assertSame('alert-danger', $invalid['availabilityclass']);
        $this->assertArrayNotHasKey('joinurl', $invalid);
    }

    /**
     * Managed Calendar details require an exact trusted HTTPS origin.
     */
    public function test_managed_event_details_fail_closed(): void {
        $access = new meeting_access_result(meeting_access_result::CLOSED);
        $valid = (new activity_actions(
            (object) [
                'integrationmode' => integration_mode::MANAGED,
                'googleeventhtmlurl' => 'https://calendar.google.com/calendar/event?eid=abc',
            ],
            $access,
            42,
            true
        ))->export_for_template($this->renderer());
        $this->assertTrue($valid['haseventdetails']);
        $this->assertSame(
            'https://calendar.google.com/calendar/event?eid=abc',
            $valid['eventdetailsurl']
        );

        foreach ([
            'javascript:alert(1)',
            'https://calendar.google.com.evil.example/event',
            'https://calendar.google.com:443/event',
            'https://user@calendar.google.com/event',
            'https://calendar.google.com/event#fragment',
            'https://calendar.google.com/event?' . str_repeat('a', 2048),
        ] as $url) {
            $invalid = (new activity_actions(
                (object) [
                    'integrationmode' => integration_mode::MANAGED,
                    'googleeventhtmlurl' => $url,
                ],
                $access,
                42,
                true
            ))->export_for_template($this->renderer());
            $this->assertArrayNotHasKey('eventdetailsurl', $invalid);
        }
    }

    /**
     * Legacy event identifiers are encoded and hidden without permission.
     */
    public function test_legacy_event_details_are_encoded_and_capability_scoped(): void {
        $meeting = (object) [
            'integrationmode' => integration_mode::LEGACY,
            'eventid' => 'legacy/event?id=1',
        ];
        $access = new meeting_access_result(meeting_access_result::CLOSED);

        $allowed = (new activity_actions(
            $meeting,
            $access,
            42,
            true
        ))->export_for_template($this->renderer());
        $this->assertStringEndsWith(
            'legacy%2Fevent%3Fid%3D1',
            $allowed['eventdetailsurl']
        );

        $hidden = (new activity_actions(
            $meeting,
            $access,
            42,
            false
        ))->export_for_template($this->renderer());
        $this->assertArrayNotHasKey('eventdetailsurl', $hidden);
    }

    /**
     * The rendered controls use normal links and accessible notification roles.
     */
    public function test_template_contains_no_inline_navigation_handler(): void {
        global $OUTPUT;

        $this->resetAfterTest();
        $this->setAdminUser();
        $html = $OUTPUT->render(new activity_actions(
            (object) ['integrationmode' => integration_mode::MANUAL],
            new meeting_access_result(
                meeting_access_result::AVAILABLE,
                'https://meet.google.com/abc-defg-hij'
            ),
            42,
            false
        ));

        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertStringContainsString('rel="noopener"', $html);
        $this->assertStringContainsString('/mod/googlemeet/join.php', $html);
        $this->assertStringNotContainsString('meet.google.com', $html);
        $this->assertStringNotContainsString('onclick=', $html);
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
