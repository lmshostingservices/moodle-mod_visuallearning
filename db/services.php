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

/**
 * External functions.
 *
 * @package    mod_visuallearning
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'mod_visuallearning_track_page' => [
        'classname' => 'mod_visuallearning\external\track_page',
        'description' => 'Record a page view, a time heartbeat or a fully listened page.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/visuallearning:view',
    ],
    'mod_visuallearning_answer_question' => [
        'classname' => 'mod_visuallearning\external\answer_question',
        'description' => 'Check a learner\'s answer to a page\'s check question and record it.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/visuallearning:view',
    ],
];
