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

namespace mod_googlemeet\api;

/**
 * A permanent Calendar API rejection that is safe to expose as a stable code.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class calendar_api_exception extends \runtime_exception {

    /** @var string Stable non-secret error code. */
    private string $errorcode;

    /**
     * @param string $errorcode Stable non-secret error code.
     * @param \Throwable|null $previous Previous exception.
     */
    public function __construct(string $errorcode, ?\Throwable $previous = null) {
        $this->errorcode = $errorcode;
        parent::__construct('The Google Calendar API rejected the request.', 0, $previous);
    }

    /**
     * Returns the stable code suitable for local persistence.
     *
     * @return string
     */
    public function error_code(): string {
        return $this->errorcode;
    }
}
