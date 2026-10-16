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

/**
 * Build-the-book screen: refresh while media generates; select-all on the prompt preview.
 *
 * @module     mod_visuallearning/manage
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Initialise.
 *
 * @param {number} refreshSeconds Reload interval while generation is running; 0 for none.
 */
export const init = (refreshSeconds) => {
    document.querySelectorAll('.pb-prompt-preview').forEach((area) => {
        area.addEventListener('focus', () => area.select());
    });
    if (refreshSeconds > 0) {
        window.setTimeout(() => window.location.reload(), refreshSeconds * 1000);
    }
};
