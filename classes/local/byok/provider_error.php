<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace mod_visuallearning\local\byok;

/**
 * The site's own AI refused or failed a call, as the service reports a provider failure.
 *
 * @package    mod_visuallearning
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider_error extends \Exception {
    /** @var int HTTP status the service answers with (429 when rate limited, else 502). */
    public int $status;
    /** @var string The service's error code, such as PROVIDER_FAILED. */
    public string $error;

    /**
     * Constructor.
     *
     * @param string $error
     * @param int $status
     * @param string $detail What the provider said, for the site log only.
     */
    public function __construct(string $error = 'PROVIDER_FAILED', int $status = 502, string $detail = '') {
        parent::__construct($detail !== '' ? $detail : $error);
        $this->error = $error;
        $this->status = $status;
    }
}
