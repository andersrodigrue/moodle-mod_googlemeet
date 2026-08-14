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

namespace mod_googlemeet\task;

use mod_googlemeet\local\integration_mode;
use mod_googlemeet\local\remote_cleanup_repository;
use mod_googlemeet\local\sync_state;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for durable remote cleanup reconciliation.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(reconcile_remote_cleanups::class)]
final class reconcile_remote_cleanups_test extends \advanced_testcase {

    /**
     * Cron restores bounded owner-scoped workers without accessing Google itself.
     */
    public function test_execute_queues_due_cleanup_once(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $repository = new remote_cleanup_repository();
        $cleanup = $repository->capture((object) [
            'integrationmode' => integration_mode::MANAGED,
            'owneruserid' => $owner->id,
            'oauthissuerid' => 11,
            'calendarid' => 'primary',
            'googleeventid' => 'event401',
            'guestcount' => 0,
            'syncstatus' => sync_state::READY,
        ]);

        $this->expectOutputString(get_string(
            'reconcileremotecleanupsresult',
            'mod_googlemeet',
            (object) ['found' => 1, 'queued' => 1]
        ) . "\n");
        $task = new reconcile_remote_cleanups();
        $task->execute();

        $tasks = \core\task\manager::get_adhoc_tasks(cancel_deleted_meeting::class);
        $this->assertCount(1, $tasks);
        $this->assertSame((int) $owner->id, (int) $tasks[0]->get_userid());
        $this->assertSame((int) $cleanup->id, (int) $tasks[0]->get_custom_data()->cleanupid);
        $this->assertSame(get_string('reconcileremotecleanupstask', 'mod_googlemeet'), $task->get_name());
    }

    /**
     * A duplicate worker remains durable but is not queued twice.
     */
    public function test_execute_suppresses_duplicate_worker(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $cleanup = (new remote_cleanup_repository())->capture((object) [
            'integrationmode' => integration_mode::MANAGED,
            'owneruserid' => $owner->id,
            'oauthissuerid' => 11,
            'calendarid' => 'primary',
            'googleeventid' => 'event402',
            'guestcount' => 0,
            'syncstatus' => sync_state::READY,
        ]);
        cancel_deleted_meeting::enqueue((int) $cleanup->id, (int) $owner->id);

        $this->expectOutputString(get_string(
            'reconcileremotecleanupsresult',
            'mod_googlemeet',
            (object) ['found' => 1, 'queued' => 0]
        ) . "\n");
        (new reconcile_remote_cleanups())->execute();

        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(cancel_deleted_meeting::class));
    }
}
