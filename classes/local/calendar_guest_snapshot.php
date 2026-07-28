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
 * Immutable, normalized snapshot of Calendar guests managed by Moodle.
 *
 * Raw email addresses live only for the duration of one synchronization task.
 * Persistent rows contain the Moodle user ID and a one-way normalized email
 * hash so a later reconciliation can distinguish managed attendees from guests
 * added directly in Google Calendar.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class calendar_guest_snapshot {

    /** @var array<string, array{userid: int, email: string, emailhash: string}> Guests keyed by normalized email. */
    private array $guests;

    /** @var string|null Stable hash of the complete managed guest set. */
    private ?string $hash;

    /**
     * @param array<string, array{userid: int, email: string, emailhash: string}> $guests Normalized guests.
     * @param string|null $hash Complete set hash, or null when guest management is disabled.
     */
    private function __construct(array $guests, ?string $hash) {
        $this->guests = $guests;
        $this->hash = $hash;
    }

    /**
     * Creates a disabled guest snapshot.
     *
     * @return self
     */
    public static function none(): self {
        return new self([], null);
    }

    /**
     * Creates a managed snapshot from validated Moodle users.
     *
     * Duplicate email addresses are collapsed deterministically to the lowest
     * user ID because Calendar identifies attendees by email.
     *
     * @param \stdClass[] $users Users with positive id and normalized email.
     * @return self
     */
    public static function course(array $users): self {
        $guests = [];
        foreach ($users as $user) {
            $userid = (int) ($user->id ?? 0);
            $email = self::normalize_email((string) ($user->email ?? ''));
            if ($userid <= 0 || $email === '' || !validate_email($email)) {
                throw new \invalid_parameter_exception('A Calendar guest has invalid Moodle identity data.');
            }

            $candidate = [
                'userid' => $userid,
                'email' => $email,
                'emailhash' => self::email_hash($email),
            ];
            if (!isset($guests[$email]) || $userid < $guests[$email]['userid']) {
                $guests[$email] = $candidate;
            }
        }
        ksort($guests, SORT_STRING);

        return new self($guests, hash('sha256', implode("\n", array_keys($guests))));
    }

    /**
     * Normalizes an address for comparison with Calendar.
     *
     * @param string $email Email address.
     * @return string
     */
    public static function normalize_email(string $email): string {
        return \core_text::strtolower(trim($email));
    }

    /**
     * Returns the stable one-way identifier used by persistent snapshots.
     *
     * @param string $email Email address.
     * @return string
     */
    public static function email_hash(string $email): string {
        return hash('sha256', self::normalize_email($email));
    }

    /**
     * Returns the complete managed set hash.
     *
     * @return string|null
     */
    public function hash(): ?string {
        return $this->hash;
    }

    /**
     * Returns the number of unique managed addresses.
     *
     * @return int
     */
    public function count(): int {
        return count($this->guests);
    }

    /**
     * Returns whether guest management is enabled for this snapshot.
     *
     * @return bool
     */
    public function is_managed(): bool {
        return $this->hash !== null;
    }

    /**
     * Returns a minimal Calendar attendee payload.
     *
     * @return array<int, array{email: string}>
     */
    public function event_attendees(): array {
        return array_values(array_map(
            static fn(array $guest): array => ['email' => $guest['email']],
            $this->guests
        ));
    }

    /**
     * Returns guests keyed by normalized email for reconciliation.
     *
     * @return array<string, array{userid: int, email: string, emailhash: string}>
     */
    public function guests(): array {
        return $this->guests;
    }

    /**
     * Returns rows safe to store locally.
     *
     * @return array<int, array{userid: int, emailhash: string}>
     */
    public function database_rows(): array {
        return array_values(array_map(
            static fn(array $guest): array => [
                'userid' => $guest['userid'],
                'emailhash' => $guest['emailhash'],
            ],
            $this->guests
        ));
    }
}
