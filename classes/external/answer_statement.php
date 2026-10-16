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

namespace mod_visuallearning\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->dirroot . '/mod/visuallearning/lib.php');

/**
 * Checks a learner's true/false answer on the server (answers are never sent to the browser).
 *
 * @package    mod_visuallearning
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class answer_statement extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'cmid' => new external_value(PARAM_INT, 'Course module id'),
                'pageid' => new external_value(PARAM_INT, 'Page the statement belongs to'),
                'answer' => new external_value(PARAM_BOOL, 'The learner answered true'),
            ],
        );
    }

    /**
     * Execute.
     *
     * @param int $cmid
     * @param int $pageid
     * @param bool $answer
     * @return array
     */
    public static function execute(int $cmid, int $pageid, bool $answer): array {
        global $DB, $USER;
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['cmid' => $cmid, 'pageid' => $pageid, 'answer' => $answer]
        );

        [$course, $cm] = get_course_and_cm_from_cmid($params['cmid'], 'visuallearning');
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/visuallearning:view', $context);

        $book = $DB->get_record('visuallearning', ['id' => $cm->instance], '*', MUST_EXIST);
        $page = $DB->get_record(
            'visuallearning_pages',
            ['id' => $params['pageid'], 'visuallearningid' => $book->id],
            '*',
            MUST_EXIST
        );
        if (!$book->enablequiz || trim((string)$page->tfstatement) === '') {
            throw new \invalid_parameter_exception('This page has no true/false statement.');
        }

        $correct = ((bool)$page->tfanswer) === (bool)$params['answer'];
        $now = time();
        $progress = $DB->get_record('visuallearning_progress', ['pageid' => $page->id, 'userid' => $USER->id]);
        if (!$progress) {
            $progress = (object)[
                'visuallearningid' => $book->id, 'pageid' => $page->id, 'userid' => $USER->id,
                'timefirstviewed' => $now, 'lastping' => $now,
            ];
            $progress->id = $DB->insert_record('visuallearning_progress', $progress);
            $progress = $DB->get_record('visuallearning_progress', ['id' => $progress->id]);
        }
        $progress->tfattempts++;
        $newlypassed = $correct && !$progress->tfpassed;
        if ($newlypassed) {
            $progress->tfpassed = 1;
            $progress->timepassed = $now;
        }
        $DB->update_record('visuallearning_progress', $progress);

        \mod_visuallearning\event\statement_answered::create(
            [
                'objectid' => $page->id,
                'context' => $context,
                'other' => ['pagenum' => (int)$page->pagenum, 'correct' => $correct],
            ],
        )->trigger();
        if ($newlypassed) {
            visuallearning_update_completion($course, $cm, (int)$USER->id);
        }

        return [
            'correct' => $correct,
            'attempts' => (int)$progress->tfattempts,
        ];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure(
            [
                'correct' => new external_value(PARAM_BOOL, 'The answer was correct'),
                'attempts' => new external_value(PARAM_INT, 'Answers given on this statement'),
            ],
        );
    }
}
