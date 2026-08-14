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

use mod_googlemeet\api\calendar_api_exception;
use mod_googlemeet\api\calendar_authorization_exception;
use mod_googlemeet\api\calendar_configuration_exception;
use mod_googlemeet\api\calendar_response_exception;
use mod_googlemeet\api\calendar_transport_exception;
use mod_googlemeet\task\cancel_deleted_meeting;

/**
 * Captures and fulfills Calendar cancellation obligations after local deletion.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class remote_cleanup_manager {

    /** Retry blocked provider access at most once per day. */
    private const BLOCKED_RETRY_DELAY = DAYSECS;

    /** @var remote_cleanup_repository Durable cleanup storage. */
    private remote_cleanup_repository $repository;

    /** @var remote_cleanup_lock Per-cleanup lock. */
    private remote_cleanup_lock $lock;

    /** @var calendar_adapter_provider|null Production or test adapter factory. */
    private ?calendar_adapter_provider $adapterprovider;

    /**
     * @param remote_cleanup_repository|null $repository Durable storage.
     * @param remote_cleanup_lock|null $lock Cleanup lock.
     * @param calendar_adapter_provider|null $adapterprovider Calendar adapter factory.
     */
    public function __construct(
        ?remote_cleanup_repository $repository = null,
        ?remote_cleanup_lock $lock = null,
        ?calendar_adapter_provider $adapterprovider = null
    ) {
        $this->repository = $repository ?? new remote_cleanup_repository();
        $this->lock = $lock ?? new remote_cleanup_lock();
        $this->adapterprovider = $adapterprovider;
    }

    /**
     * Persists and queues cleanup before a Moodle activity record disappears.
     *
     * @param \stdClass $meeting Activity record being deleted.
     * @return int|null Cleanup ID, or null when no remote event exists.
     */
    public function capture_and_queue(\stdClass $meeting): ?int {
        global $DB;

        $cleanup = $this->repository->capture($meeting);
        if ($cleanup === null) {
            return null;
        }
        if ($cleanup->status !== remote_cleanup_repository::PENDING) {
            return (int) $cleanup->id;
        }

        $owneruserid = (int) ($cleanup->owneruserid ?? 0);
        $owner = $owneruserid > 0
            ? $DB->get_record('user', ['id' => $owneruserid], 'id, deleted', IGNORE_MISSING)
            : false;
        if (!$owner || !empty($owner->deleted)) {
            $this->repository->mark_blocked(
                (int) $cleanup->id,
                'cleanup_owner_unavailable',
                null
            );
            return (int) $cleanup->id;
        }

        cancel_deleted_meeting::enqueue((int) $cleanup->id, $owneruserid);
        return (int) $cleanup->id;
    }

    /**
     * Performs an idempotent remote DELETE under the original owner's OAuth context.
     *
     * @param int $cleanupid Cleanup ID.
     */
    public function process(int $cleanupid): void {
        $this->lock->with_lock($cleanupid, function () use ($cleanupid): void {
            $cleanup = $this->repository->get_optional($cleanupid);
            if ($cleanup === null) {
                mtrace(get_string('remotecleanupmissing', 'mod_googlemeet', $cleanupid));
                return;
            }

            $cleanup = $this->repository->start_attempt($cleanupid);
            $request = $this->calendar_request($cleanup);
            $provider = $this->adapterprovider ?? new calendar_adapter_factory();

            try {
                $provider->create($request)->cancel($request);
            } catch (calendar_authorization_exception $e) {
                $this->repository->mark_blocked(
                    $cleanupid,
                    'authorization_required',
                    self::BLOCKED_RETRY_DELAY
                );
                return;
            } catch (calendar_api_exception $e) {
                $this->repository->mark_blocked(
                    $cleanupid,
                    $e->error_code(),
                    self::BLOCKED_RETRY_DELAY
                );
                return;
            } catch (calendar_configuration_exception $e) {
                $this->repository->mark_blocked(
                    $cleanupid,
                    'calendar_configuration_invalid',
                    self::BLOCKED_RETRY_DELAY
                );
                return;
            } catch (calendar_response_exception $e) {
                $this->repository->mark_blocked(
                    $cleanupid,
                    'calendar_response_invalid',
                    self::BLOCKED_RETRY_DELAY
                );
                return;
            } catch (calendar_transport_exception $e) {
                $this->repository->mark_retrying($cleanupid, 'calendar_transport_failure');
                throw $e;
            }

            $this->repository->complete($cleanupid);
            mtrace(get_string('remotecleanupcompleted', 'mod_googlemeet', $cleanupid));
        });
    }

    /**
     * Builds the minimal activity-shaped request accepted by the Calendar boundary.
     *
     * @param \stdClass $cleanup Durable cleanup record.
     * @return \stdClass
     */
    private function calendar_request(\stdClass $cleanup): \stdClass {
        return (object) [
            'id' => (int) $cleanup->id,
            'integrationmode' => integration_mode::MANAGED,
            'owneruserid' => $cleanup->owneruserid,
            'oauthissuerid' => $cleanup->oauthissuerid,
            'calendarid' => $cleanup->calendarid,
            'googleeventid' => $cleanup->googleeventid,
            'guestpolicy' => calendar_guest_policy::NONE,
            'guestcount' => (int) $cleanup->guestcount,
        ];
    }
}
