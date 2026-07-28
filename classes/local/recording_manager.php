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

use mod_googlemeet\api\recording_api_exception;
use mod_googlemeet\api\recording_authorization_exception;
use mod_googlemeet\api\recording_response_exception;
use mod_googlemeet\task\discover_recordings;

/**
 * Claims, queues and processes recording discovery for one activity.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class recording_manager {

    /** @var recording_repository Recording state repository. */
    private recording_repository $repository;

    /** @var meeting_lock Shared per-activity lock. */
    private meeting_lock $lock;

    /** @var recording_discovery|null Injected discovery service. */
    private ?recording_discovery $discovery;

    /** @var recording_discovery_provider|null Production discovery provider. */
    private ?recording_discovery_provider $provider;

    /**
     * @param recording_repository|null $repository Recording repository.
     * @param meeting_lock|null $lock Per-activity lock.
     * @param recording_discovery|null $discovery Injected discovery service.
     * @param recording_discovery_provider|null $provider Production discovery provider.
     */
    public function __construct(
        ?recording_repository $repository = null,
        ?meeting_lock $lock = null,
        ?recording_discovery $discovery = null,
        ?recording_discovery_provider $provider = null
    ) {
        $this->repository = $repository ?? new recording_repository();
        $this->lock = $lock ?? new meeting_lock();
        $this->discovery = $discovery;
        $this->provider = $provider;
    }

    /**
     * Claims an unowned integration and queues discovery for its owner.
     *
     * @param int $googlemeetid Activity instance ID.
     * @param int $owneruserid Authorized Moodle user.
     * @param int $issuerid Dedicated recording OAuth issuer.
     * @return bool True when a new task was queued.
     */
    public function claim_and_queue(int $googlemeetid, int $owneruserid, int $issuerid): bool {
        return $this->lock->with_lock(
            $googlemeetid,
            function () use ($googlemeetid, $owneruserid, $issuerid): bool {
                $meeting = $this->repository->claim($googlemeetid, $owneruserid, $issuerid);
                return $this->queue_locked($meeting, $owneruserid);
            }
        );
    }

    /**
     * Queues discovery for the persisted recording owner.
     *
     * @param int $googlemeetid Activity instance ID.
     * @param int $owneruserid Current Moodle user.
     * @return bool True when a new task was queued.
     */
    public function queue(int $googlemeetid, int $owneruserid): bool {
        return $this->lock->with_lock($googlemeetid, function () use ($googlemeetid, $owneruserid): bool {
            $meeting = $this->repository->get($googlemeetid);
            return $this->queue_locked($meeting, $owneruserid);
        });
    }

    /**
     * Processes a queued discovery under the activity lock.
     *
     * Transient transport failures escape so Moodle retries the same task.
     *
     * @param int $googlemeetid Activity instance ID.
     */
    public function process(int $googlemeetid): void {
        $this->lock->with_lock($googlemeetid, function () use ($googlemeetid): void {
            $meeting = $this->repository->get_optional($googlemeetid);
            if ($meeting === null) {
                mtrace(get_string('recordingsactivitymissing', 'mod_googlemeet', $googlemeetid));
                return;
            }
            if (!in_array(
                $meeting->recordingsyncstatus,
                [recording_sync_state::QUEUED, recording_sync_state::SYNCING],
                true
            )) {
                mtrace(get_string('recordingsstateskipped', 'mod_googlemeet', (object) [
                    'id' => $googlemeetid,
                    'state' => $meeting->recordingsyncstatus,
                ]));
                return;
            }

            $meeting = $this->repository->start_attempt($googlemeetid);
            try {
                $discovery = $this->discovery ?? $this->provider?->create($meeting);
                if ($discovery === null) {
                    throw new \coding_exception('A recording discovery provider is required.');
                }
                $artifacts = $discovery->discover($meeting);
            } catch (recording_authorization_exception $e) {
                $this->repository->mark_disconnected(
                    $googlemeetid,
                    'recording_authorization_required',
                    get_string('recordingsoauthrequired', 'mod_googlemeet')
                );
                return;
            } catch (recording_api_exception $e) {
                $this->repository->mark_failed(
                    $googlemeetid,
                    $e->error_code(),
                    get_string('recordingsapifailed', 'mod_googlemeet')
                );
                return;
            } catch (recording_response_exception $e) {
                $this->repository->mark_failed(
                    $googlemeetid,
                    'recording_response_invalid',
                    get_string('recordingsresponseinvalid', 'mod_googlemeet')
                );
                return;
            }

            $this->repository->apply_artifacts($googlemeetid, $artifacts);
            mtrace(get_string('recordingssynced', 'mod_googlemeet', (object) [
                'id' => $googlemeetid,
                'count' => count($artifacts),
            ]));
        });
    }

    /**
     * Validates ownership, queues one task and advances the state.
     *
     * @param \stdClass $meeting Activity record.
     * @param int $owneruserid Current Moodle user.
     * @return bool
     */
    private function queue_locked(\stdClass $meeting, int $owneruserid): bool {
        if (
            $owneruserid <= 0 ||
            (int) ($meeting->recordingowneruserid ?? 0) !== $owneruserid
        ) {
            throw new \moodle_exception('recordingowneronly', 'mod_googlemeet');
        }
        if ((int) ($meeting->recordingoauthissuerid ?? 0) <= 0) {
            throw new \coding_exception('A recording OAuth issuer is required.');
        }

        $transitionrequired = !in_array(
            $meeting->recordingsyncstatus,
            [recording_sync_state::QUEUED, recording_sync_state::SYNCING],
            true
        );
        if ($transitionrequired) {
            recording_sync_state::assert_transition(
                $meeting->recordingsyncstatus,
                recording_sync_state::QUEUED
            );
        }

        $queued = discover_recordings::enqueue((int) $meeting->id, $owneruserid);
        if ($queued && $transitionrequired) {
            $this->repository->transition((int) $meeting->id, recording_sync_state::QUEUED);
        }

        return $queued;
    }
}
