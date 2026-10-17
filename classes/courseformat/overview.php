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

namespace mod_visuallearning\courseformat;

use core\output\local\properties\text_align;
use core_courseformat\local\overview\overviewitem;

/**
 * Course "Activities" overview integration (Moodle 5.0+; never loaded on 4.4/4.5).
 *
 * @package    mod_visuallearning
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class overview extends \core_courseformat\activityoverviewbase {
    #[\Override]
    public function get_extra_overview_items(): array {
        return [
            'pages' => $this->get_pages_overview(),
            'progress' => $this->get_progress_overview(),
            'completedlearners' => $this->get_completed_learners_overview(),
        ];
    }

    #[\Override]
    public function get_actions_overview(): ?overviewitem {
        if (!has_capability('mod/visuallearning:viewreports', $this->context)) {
            return null;
        }
        $url = new \moodle_url('/mod/visuallearning/report.php', ['id' => $this->cm->id]);
        return new overviewitem(
            get_string('actions'),
            get_string('report', 'mod_visuallearning'),
            \html_writer::link($url, get_string('report', 'mod_visuallearning'), ['class' => 'btn btn-outline-secondary btn-sm']),
            text_align::CENTER,
        );
    }

    /**
     * Number of pages in the lesson.
     *
     * @return overviewitem
     */
    private function get_pages_overview(): overviewitem {
        global $DB;
        $count = $DB->count_records('visuallearning_pages', ['visuallearningid' => $this->cm->instance]);
        return new overviewitem(get_string('pages', 'mod_visuallearning'), $count, (string)$count, text_align::END);
    }

    /**
     * The current learner's own progress.
     *
     * @return overviewitem|null
     */
    private function get_progress_overview(): ?overviewitem {
        global $DB, $USER;
        if (has_capability('mod/visuallearning:viewreports', $this->context)) {
            return null;
        }
        $total = $DB->count_records('visuallearning_pages', ['visuallearningid' => $this->cm->instance]);
        $listened = $DB->count_records(
            'visuallearning_progress',
            ['visuallearningid' => $this->cm->instance, 'userid' => $USER->id, 'listened' => 1]
        );
        $text = get_string('player_listenedof', 'mod_visuallearning', (object)['done' => $listened, 'total' => $total]);
        return new overviewitem(get_string('percentcomplete', 'mod_visuallearning'), $listened, $text);
    }

    /**
     * Teachers: how many learners have listened to every page.
     *
     * @return overviewitem|null
     */
    private function get_completed_learners_overview(): ?overviewitem {
        global $DB;
        if (!has_capability('mod/visuallearning:viewreports', $this->context)) {
            return null;
        }
        $total = $DB->count_records('visuallearning_pages', ['visuallearningid' => $this->cm->instance]);
        $done = 0;
        if ($total > 0) {
            $sql = "SELECT COUNT(1)
                      FROM (SELECT userid
                              FROM {visuallearning_progress}
                             WHERE visuallearningid = :bookid AND listened = 1
                          GROUP BY userid
                            HAVING COUNT(1) >= :total) finished";
            $done = (int)$DB->count_records_sql($sql, ['bookid' => $this->cm->instance, 'total' => $total]);
        }
        return new overviewitem(get_string('learnersfinished', 'mod_visuallearning'), $done, (string)$done, text_align::END);
    }
}
