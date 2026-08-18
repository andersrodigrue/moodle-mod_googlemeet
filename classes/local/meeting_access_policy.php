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

/**
 * Applies one server-side access window to every meeting presentation surface.
 *
 * The participant interval is half-open: it starts exactly N minutes before an
 * occurrence and closes exactly N minutes after its end. The raw Meet URI is
 * returned only while that interval is open or to an explicit meeting manager.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class meeting_access_policy {

    /** Site default: participants can enter fifteen minutes before an occurrence. */
    public const DEFAULT_BEFORE_MINUTES = 15;

    /** Site default: participant access closes sixty minutes after an occurrence. */
    public const DEFAULT_AFTER_MINUTES = 60;

    /** @var int[] Bounded early-entry values accepted by administration settings. */
    private const BEFORE_OPTIONS = [0, 5, 10, 15, 30, 45, 60, 90, 120];

    /** @var int[] Bounded post-meeting values accepted by administration settings. */
    private const AFTER_OPTIONS = [0, 15, 30, 60, 90, 120, 180, 240, 360, 720, 1440];

    /** @var \core\clock Moodle server clock. */
    private \core\clock $clock;

    /** @var schedule_expander Canonical occurrence expander. */
    private schedule_expander $expander;

    /** @var int Minutes before each occurrence when participant access begins. */
    private int $beforeminutes;

    /** @var int Minutes after each occurrence when participant access ends. */
    private int $afterminutes;

    /**
     * @param \core\clock|null $clock Moodle server clock.
     * @param schedule_expander|null $expander Canonical occurrence expander.
     * @param int|null $beforeminutes Explicit test override.
     * @param int|null $afterminutes Explicit test override.
     */
    public function __construct(
        ?\core\clock $clock = null,
        ?schedule_expander $expander = null,
        ?int $beforeminutes = null,
        ?int $afterminutes = null
    ) {
        $this->clock = $clock ?? \core\di::get(\core\clock::class);
        $this->expander = $expander ?? new schedule_expander();
        $this->beforeminutes = $this->resolve_minutes(
            'joinbeforeminutes',
            $beforeminutes,
            self::DEFAULT_BEFORE_MINUTES,
            self::BEFORE_OPTIONS
        );
        $this->afterminutes = $this->resolve_minutes(
            'joinafterminutes',
            $afterminutes,
            self::DEFAULT_AFTER_MINUTES,
            self::AFTER_OPTIONS
        );
    }

    /**
     * Returns allowed early-entry settings.
     *
     * @return int[]
     */
    public static function before_options(): array {
        return self::BEFORE_OPTIONS;
    }

    /**
     * Returns allowed post-meeting settings.
     *
     * @return int[]
     */
    public static function after_options(): array {
        return self::AFTER_OPTIONS;
    }

    /**
     * Evaluates one activity without trusting a browser or app clock.
     *
     * @param \stdClass $meeting Activity record.
     * @param bool $canmanage Whether the caller has the meeting-management capability.
     * @return meeting_access_result
     */
    public function evaluate(\stdClass $meeting, bool $canmanage = false): meeting_access_result {
        if (($meeting->syncstatus ?? null) !== sync_state::READY) {
            return new meeting_access_result(meeting_access_result::NOT_READY);
        }

        $joinuri = $this->valid_join_uri($meeting);
        if ($joinuri === null) {
            return new meeting_access_result(meeting_access_result::INVALID_LINK);
        }

        if ($canmanage) {
            return new meeting_access_result(
                meeting_access_result::MANAGER_OVERRIDE,
                $joinuri
            );
        }

        try {
            $occurrences = $this->expander->expand($meeting);
        } catch (\invalid_parameter_exception) {
            return new meeting_access_result(meeting_access_result::INVALID_SCHEDULE);
        }

        $now = $this->clock->time();
        $beforeseconds = $this->beforeminutes * MINSECS;
        $afterseconds = $this->afterminutes * MINSECS;
        foreach ($occurrences as $occurrence) {
            $windowstart = $occurrence->timestart - $beforeseconds;
            $windowend = $occurrence->timeend + $afterseconds;
            if ($now >= $windowstart && $now < $windowend) {
                return new meeting_access_result(meeting_access_result::AVAILABLE, $joinuri);
            }
            if ($now < $windowstart) {
                return new meeting_access_result(
                    meeting_access_result::SCHEDULED,
                    null,
                    $windowstart
                );
            }
        }

        return new meeting_access_result(meeting_access_result::CLOSED);
    }

    /**
     * Returns a strict stored Google Meet URI.
     *
     * @param \stdClass $meeting Activity record.
     * @return string|null
     */
    private function valid_join_uri(\stdClass $meeting): ?string {
        $uri = trim((string) (($meeting->meetinguri ?? '') ?: ($meeting->url ?? '')));
        if (!preg_match(
            '/^https:\/\/meet\.google\.com\/[a-zA-Z0-9]{3}-[a-zA-Z0-9]{4}-[a-zA-Z0-9]{3}$/',
            $uri
        )) {
            return null;
        }

        return $uri;
    }

    /**
     * Resolves one bounded setting, failing closed to the documented default.
     *
     * @param string $configname Plugin configuration key.
     * @param int|null $override Explicit test override.
     * @param int $default Safe default.
     * @param int[] $allowed Closed accepted values.
     * @return int
     */
    private function resolve_minutes(
        string $configname,
        ?int $override,
        int $default,
        array $allowed
    ): int {
        if ($override !== null) {
            if (!in_array($override, $allowed, true)) {
                throw new \invalid_parameter_exception('The meeting access window is invalid.');
            }
            return $override;
        }

        $configured = get_config('googlemeet', $configname);
        if (
            $configured === false ||
            !preg_match('/^(0|[1-9][0-9]*)$/D', (string) $configured)
        ) {
            return $default;
        }

        $value = (int) $configured;
        return in_array($value, $allowed, true) ? $value : $default;
    }
}
