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
use mod_visuallearning\local\media;
use mod_visuallearning\local\question;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->dirroot . '/mod/visuallearning/lib.php');

/**
 * Checks a learner's answer to a page's check question on the server (right answers never reach the browser).
 *
 * A wrong answer asks the learner to listen to the page again: until they have, the question stays locked
 * (relisten), however often the player is reloaded. The feedback (text and voiceover) is sent only after a
 * right answer, because it explains the answer.
 *
 * @package    mod_visuallearning
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class answer_question extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'cmid' => new external_value(PARAM_INT, 'Course module id'),
                'pageid' => new external_value(PARAM_INT, 'Page the question belongs to'),
                'response' => new external_value(PARAM_RAW, 'JSON: {value} (truefalse), {choice} (pick), {pairs} (match) or ' // pipeline-ignore: PARAM_RAW — JSON decoded and validated against the stored question.
                    . '{items} (sort)'),
            ],
        );
    }

    /**
     * Execute.
     *
     * @param int $cmid
     * @param int $pageid
     * @param string $response
     * @return array
     */
    public static function execute(int $cmid, int $pageid, string $response): array {
        global $DB, $USER;
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['cmid' => $cmid, 'pageid' => $pageid, 'response' => $response]
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
        $q = $book->enablequiz ? question::of_page($page) : null;
        if ($q === null) {
            throw new \invalid_parameter_exception('This page has no check question.');
        }
        $given = json_decode($params['response'], true);
        if (!is_array($given) || strlen($params['response']) > 4000) {
            throw new \invalid_parameter_exception('Unreadable response.');
        }

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
        if ($progress->relisten && !$progress->tfpassed) {
            // Locked until the page has been listened to again: not counted as an attempt.
            return ['correct' => false, 'attempts' => (int)$progress->tfattempts, 'relisten' => true, 'feedback' => '',
                'feedbackaudio' => ''];
        }

        $correct = question::check($q, (int)$page->id, $given);
        $progress->tfattempts++;
        $newlypassed = $correct && !$progress->tfpassed;
        if ($newlypassed) {
            $progress->tfpassed = 1;
            $progress->timepassed = $now;
        }
        if (!$correct && !$progress->tfpassed) {
            $progress->relisten = 1;
        }
        $DB->update_record('visuallearning_progress', $progress);

        \mod_visuallearning\event\statement_answered::create(
            [
                'objectid' => $page->id,
                'context' => $context,
                'other' => ['pagenum' => (int)$page->pagenum, 'correct' => $correct, 'qtype' => $q['type']],
            ],
        )->trigger();
        if ($newlypassed) {
            visuallearning_update_completion($course, $cm, (int)$USER->id);
        }

        $feedback = '';
        $audio = '';
        if ($correct) {
            $feedback = $q['feedback'] === '' ? '' : format_text($q['feedback'], FORMAT_PLAIN, ['context' => $context,
                'para' => false]);
            $ids = array_map('intval', array_keys(visuallearning_get_pages((int)$book->id)));
            $position = array_search((int)$page->id, $ids);
            $clips = media::clips($book, $page, (int)$position, count($ids));
            if (isset($clips['feedback'])) {
                $audio = visuallearning_file_url(media::clip_file($context, $book, $page, 'feedback', $clips['feedback']));
            }
        }
        return [
            'correct' => $correct,
            'attempts' => (int)$progress->tfattempts,
            'relisten' => !$correct && !$progress->tfpassed,
            'feedback' => $feedback,
            'feedbackaudio' => $audio,
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
                'correct' => new external_value(PARAM_BOOL, 'The answer was right'),
                'attempts' => new external_value(PARAM_INT, 'Answers given to this question'),
                'relisten' => new external_value(PARAM_BOOL, 'The learner must listen to the page again first'),
                'feedback' => new external_value(PARAM_TEXT, 'Why the answer is right (after a right answer only)'),
                'feedbackaudio' => new external_value(PARAM_URL, 'Feedback voiceover (after a right answer only)'),
            ],
        );
    }
}
