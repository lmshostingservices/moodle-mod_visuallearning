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
 * Records page views, time-on-page heartbeats and fully listened pages.
 *
 * Time is measured server-side from the gap between heartbeats (capped), so a client cannot
 * inflate it by sending large numbers.
 *
 * @package    mod_visuallearning
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class track_page extends external_api {
    /** Heartbeat interval the player uses, in seconds. */
    public const HEARTBEAT = 15;
    /** Longest gap credited for a single heartbeat. */
    public const MAXCREDIT = 30;

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'cmid' => new external_value(PARAM_INT, 'Course module id'),
                'pageid' => new external_value(PARAM_INT, 'Page id'),
                'action' => new external_value(PARAM_ALPHA, 'view, resume, ping or listened'),
            ],
        );
    }

    /**
     * Execute.
     *
     * @param int $cmid
     * @param int $pageid
     * @param string $action
     * @return array
     */
    public static function execute(int $cmid, int $pageid, string $action): array {
        global $DB, $USER;
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['cmid' => $cmid, 'pageid' => $pageid, 'action' => $action]
        );

        [$course, $cm] = get_course_and_cm_from_cmid($params['cmid'], 'visuallearning');
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/visuallearning:view', $context);

        $page = $DB->get_record(
            'visuallearning_pages',
            ['id' => $params['pageid'], 'visuallearningid' => $cm->instance],
            '*',
            MUST_EXIST
        );
        if (!in_array($params['action'], ['view', 'resume', 'ping', 'listened'], true)) {
            throw new \invalid_parameter_exception('Unknown action');
        }

        $now = time();
        $progress = $DB->get_record('visuallearning_progress', ['pageid' => $page->id, 'userid' => $USER->id]);
        if (!$progress) {
            $progress = (object)[
                'visuallearningid' => $cm->instance,
                'pageid' => $page->id,
                'userid' => $USER->id,
                'views' => 0,
                'listened' => 0,
                'timespent' => 0,
                'timefirstviewed' => $now,
                'timelistened' => 0,
                'lastping' => $now,
            ];
            $progress->id = $DB->insert_record('visuallearning_progress', $progress);
        }

        if ($params['action'] === 'view') {
            $progress->views++;
            $progress->lastping = $now;
        } else if ($params['action'] === 'resume') {
            // Tab became visible again: restart the clock without crediting the hidden time.
            $progress->lastping = $now;
        } else {
            // Credit the time since the last beat on this page, capped.
            if ($progress->lastping > 0) {
                $gap = $now - (int)$progress->lastping;
                if ($gap > 0) {
                    $progress->timespent += min($gap, self::MAXCREDIT);
                }
            }
            $progress->lastping = $now;
        }

        if ($params['action'] === 'listened') {
            // Listening to the whole page again unlocks its check question after a wrong answer.
            $progress->relisten = 0;
        }
        $newlylistened = false;
        if ($params['action'] === 'listened' && !$progress->listened) {
            $progress->listened = 1;
            $progress->timelistened = $now;
            $newlylistened = true;
        }
        $DB->update_record('visuallearning_progress', $progress);

        if ($newlylistened) {
            \mod_visuallearning\event\page_listened::create(
                [
                    'objectid' => $page->id,
                    'context' => $context,
                    'other' => ['pagenum' => (int)$page->pagenum],
                ],
            )->trigger();
            // Lessons without check questions are graded on pages listened.
            visuallearning_update_grades($DB->get_record('visuallearning', ['id' => $cm->instance], '*', MUST_EXIST),
                (int)$USER->id);
            visuallearning_update_completion($course, $cm, (int)$USER->id);
        }

        $total = $DB->count_records('visuallearning_pages', ['visuallearningid' => $cm->instance]);
        $listened = $DB->count_records(
            'visuallearning_progress',
            ['visuallearningid' => $cm->instance, 'userid' => $USER->id, 'listened' => 1]
        );

        return [
            'listened' => (bool)$progress->listened,
            'listenedcount' => $listened,
            'totalpages' => $total,
            'allcomplete' => $total > 0 && $listened >= $total,
            'timespent' => (int)$progress->timespent,
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
                'listened' => new external_value(PARAM_BOOL, 'This page has been fully listened to'),
                'listenedcount' => new external_value(PARAM_INT, 'Pages listened'),
                'totalpages' => new external_value(PARAM_INT, 'Pages in the book'),
                'allcomplete' => new external_value(PARAM_BOOL, 'Every page listened'),
                'timespent' => new external_value(PARAM_INT, 'Seconds on this page'),
            ],
        );
    }
}
