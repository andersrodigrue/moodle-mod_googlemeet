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

use mod_googlemeet\local\sync_state;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the synchronization status presentation model.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(sync_status::class)]
final class sync_status_test extends \advanced_testcase {

    /**
     * Pending meetings are presented as in progress without diagnostics.
     */
    public function test_pending_status_is_in_progress(): void {
        $data = (new sync_status((object) [
            'syncstatus' => sync_state::PENDING,
            'timelastattempt' => 0,
            'lasterrorcode' => 'must_not_leak',
        ]))->export_for_template($this->renderer());

        $this->assertSame(sync_state::PENDING, $data['state']);
        $this->assertTrue($data['inprogress']);
        $this->assertSame('text-bg-info', $data['badgeclass']);
        $this->assertArrayNotHasKey('haserror', $data);
    }

    /**
     * Sanitized diagnostics are exported only when explicitly authorized.
     */
    public function test_failed_status_can_include_safe_diagnostics(): void {
        $data = (new sync_status((object) [
            'syncstatus' => sync_state::FAILED,
            'timelastattempt' => 1722528000,
            'lasterrorcode' => 'calendar_permission_denied',
            'lasterrormessage' => 'Safe local message',
        ], true))->export_for_template($this->renderer());

        $this->assertSame('text-bg-danger', $data['badgeclass']);
        $this->assertFalse($data['inprogress']);
        $this->assertTrue($data['haserror']);
        $this->assertSame('calendar_permission_denied', $data['errorcode']);
        $this->assertSame('Safe local message', $data['errormessage']);
        $this->assertNotEmpty($data['lastattempttext']);
    }

    /**
     * Only explicitly authorized owners receive POST command data.
     */
    public function test_owner_actions_are_state_scoped(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $failed = (new sync_status((object) [
            'syncstatus' => sync_state::FAILED,
            'timelastattempt' => 0,
        ], false, 42, true))->export_for_template($this->renderer());
        $this->assertTrue($failed['hasactions']);
        $this->assertTrue($failed['canretry']);
        $this->assertTrue($failed['cancancel']);
        $this->assertFalse($failed['canreconnect']);
        $this->assertSame(42, $failed['cmid']);
        $this->assertSame(sesskey(), $failed['sesskey']);

        $student = (new sync_status((object) [
            'syncstatus' => sync_state::FAILED,
        ], false, 42, false))->export_for_template($this->renderer());
        $this->assertArrayNotHasKey('actionurl', $student);
        $this->assertArrayNotHasKey('hasactions', $student);
    }

    /**
     * Authorization failure during cancellation exposes reconnect without losing intent.
     */
    public function test_blocked_cancellation_exposes_reconnect(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $data = (new sync_status((object) [
            'syncstatus' => sync_state::CANCELLING,
            'lasterrorcode' => 'authorization_required',
        ], true, 42, true))->export_for_template($this->renderer());

        $this->assertFalse($data['inprogress']);
        $this->assertFalse($data['canretry']);
        $this->assertTrue($data['canreconnect']);
        $this->assertFalse($data['cancancel']);
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
