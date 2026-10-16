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

declare(strict_types=1);

namespace mod_visuallearning\completion;

use core_completion\activity_custom_completion;

/**
 * Custom completion: every page's voiceover listened to the end.
 *
 * @package    mod_visuallearning
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class custom_completion extends activity_custom_completion {
    /**
     * State of a rule.
     *
     * @param string $rule
     * @return int
     */
    public function get_state(string $rule): int {
        global $DB;
        $this->validate_rule($rule);
        $bookid = (int)$this->cm->instance;
        $params = ['bookid' => $bookid, 'userid' => $this->userid];

        if ($rule === 'completionquiz') {
            $book = $DB->get_record('visuallearning', ['id' => $bookid], 'id, enablequiz', MUST_EXIST);
            if (!$book->enablequiz) {
                return COMPLETION_COMPLETE;
            }
            $hasstatement = $DB->sql_isnotempty('visuallearning_pages', 'pg.tfstatement', true, true);
            $total = (int)$DB->count_records_sql(
                "SELECT COUNT(1) FROM {visuallearning_pages} pg
                    WHERE pg.visuallearningid = :bookid AND $hasstatement",
                $params,
            );
            $passed = (int)$DB->count_records_sql(
                "SELECT COUNT(1)
                    FROM {visuallearning_progress} pr
                    JOIN {visuallearning_pages} pg ON pg.id = pr.pageid
                    WHERE pr.visuallearningid = :bookid AND pr.userid = :userid
                    AND pr.tfpassed = 1 AND $hasstatement",
                $params,
            );
            return $passed >= $total ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE;
        }

        $total = $DB->count_records('visuallearning_pages', ['visuallearningid' => $bookid]);
        if ($total === 0) {
            return COMPLETION_INCOMPLETE;
        }
        $sql = "SELECT COUNT(1)
                  FROM {visuallearning_progress} pr
                  JOIN {visuallearning_pages} pg ON pg.id = pr.pageid
                 WHERE pr.visuallearningid = :bookid AND pr.userid = :userid AND pr.listened = 1";
        $listened = (int)$DB->count_records_sql($sql, $params);
        return $listened >= $total ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE;
    }

    /**
     * Rules this module defines.
     *
     * @return array
     */
    public static function get_defined_custom_rules(): array {
        return ['completionlistened', 'completionquiz'];
    }

    /**
     * Rule descriptions.
     *
     * @return array
     */
    public function get_custom_rule_descriptions(): array {
        return [
            'completionlistened' => get_string('completiondetail:listened', 'mod_visuallearning'),
            'completionquiz' => get_string('completiondetail:quiz', 'mod_visuallearning'),
        ];
    }

    /**
     * Display order.
     *
     * @return array
     */
    public function get_sort_order(): array {
        return ['completionview', 'completionlistened', 'completionquiz'];
    }
}
