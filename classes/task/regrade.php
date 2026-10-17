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

namespace mod_visuallearning\task;

/**
 * Creates every lesson's grade item and pushes its learners' grades, once, after the upgrade that added grades.
 *
 * Grades cannot be written during an upgrade (they update completion, which needs course data Moodle does not let an
 * upgrade build), so the upgrade only adds the grade setting and queues this task.
 *
 * @package    mod_visuallearning
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class regrade extends \core\task\adhoc_task {
    /**
     * Grade every lesson's learners.
     */
    public function execute() {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/visuallearning/lib.php');
        $sql = "SELECT v.*, cm.idnumber AS cmidnumber
                  FROM {visuallearning} v
                  JOIN {course_modules} cm ON cm.instance = v.id
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'visuallearning'";
        $books = $DB->get_records_sql($sql);
        foreach ($books as $book) {
            try {
                visuallearning_grade_item_update($book);
                visuallearning_update_grades($book);
            } catch (\Throwable $e) {
                // One broken lesson must not stop the others.
                mtrace('mod_visuallearning: could not grade activity ' . $book->id . ': ' . $e->getMessage());
            }
        }
    }
}
