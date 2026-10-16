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

use mod_visuallearning\local\ai\requests;

/**
 * Sends confirmed LMS Labs requests of a book (pages, illustrations, voiceover clips) in the background.
 *
 * Each stored request is sent once, with its stored key and body. Nothing is retried after a failure or a
 * timeout, and this task never throws, so Moodle does not retry it either. The only repeat is asking again, with
 * the same key and body, about a request LMS Labs answered as still in progress (HTTP 202).
 *
 * @package    mod_visuallearning
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class generate_media extends \core\task\adhoc_task {
    /**
     * Task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_generatemedia', 'mod_visuallearning');
    }

    /**
     * Run.
     */
    public function execute() {
        global $DB;
        $data = $this->get_custom_data();
        $book = $DB->get_record('visuallearning', ['id' => (int)($data->bookid ?? 0)]);
        if (!$book || empty($data->ids)) {
            return;
        }
        $again = [];
        foreach ((array)$data->ids as $id) {
            $row = $DB->get_record('visuallearning_aireq', ['id' => (int)$id, 'visuallearningid' => $book->id]);
            if (!$row || !in_array($row->status, requests::CHECKABLE, true)) {
                continue;
            }
            try {
                $book = $DB->get_record('visuallearning', ['id' => $book->id], '*', MUST_EXIST);
                $row = requests::send($row, $book);
            } catch (\Throwable $e) {
                // Recorded, never rethrown: an unexpected error must not make Moodle send the request again.
                $DB->set_field('visuallearning_aireq', 'errorcode', 'internal', ['id' => $row->id]);
                mtrace("AI Visual Learning {$book->id}: request {$row->id} stopped - " . get_class($e));
                continue;
            }
            mtrace("AI Visual Learning {$book->id}: request {$row->id} ({$row->operation}) {$row->status}.");
            if ($row->status === 'pending' && (int)$row->tries < requests::MAX_POLLS) {
                $again[] = (int)$row->id;
            }
        }
        if ($again) {
            requests::queue($book, $again, (int)$this->get_userid(), 10);
        }
    }
}
