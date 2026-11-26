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
 * A written lesson that failed the service's checks (BundleDraftValidationError): code INVALID_PROVIDER_RESULT.
 *
 * @package    mod_visuallearning
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class draft_error extends \Exception {
    /** @var array The failing fields: [field, rule, characters?], as the writer gets them back. */
    public array $diagnostics;

    /**
     * Constructor.
     *
     * @param array $diagnostics
     */
    public function __construct(array $diagnostics = [['field' => 'result', 'rule' => 'contract']]) {
        parent::__construct('INVALID_PROVIDER_RESULT');
        $this->diagnostics = $diagnostics;
    }
}
