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

namespace mod_visuallearning\local;

use stdClass;

/**
 * Adds a lesson's check questions to the course question bank (through Moodle's own Moodle XML import), so teachers
 * can build a Quiz from them.
 *
 * Moodle 4.4 and 4.5 keep course questions in the course context; Moodle 5.0 and later keep them in the course's
 * own question bank activity, which Moodle creates when it is first needed.
 *
 * @package    mod_visuallearning
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qbank {
    /**
     * Where the questions go: the context, its default category and how to describe it.
     *
     * @param stdClass $course
     * @param bool $create create the course question bank (Moodle 5.0+) when it does not exist yet
     * @return array|null ['context', 'category', 'name', 'where', 'url'], or null when there is no question bank
     */
    public static function target(stdClass $course, bool $create = false): ?array {
        global $CFG;
        require_once($CFG->libdir . '/questionlib.php');
        $helper = '\core_question\local\bank\question_bank_helper';
        if (class_exists($helper) && method_exists($helper, 'get_default_open_instance_system_type')) {
            $cm = $helper::get_default_open_instance_system_type($course, $create);
            if (!$cm && !$create) {
                // Not made yet: Moodle makes it when the teacher confirms.
                $context = \context_course::instance($course->id);
                return ['context' => $context, 'category' => null, 'name' => get_string('questionbank', 'question'),
                    'where' => get_string('qbank_where_new', 'mod_visuallearning'),
                    'url' => new \moodle_url('/course/view.php', ['id' => $course->id])];
            }
            if (!$cm) {
                return null;
            }
            $context = \context_module::instance($cm->id);
            $category = question_get_default_category($context->id, true);
            $url = new \moodle_url('/question/edit.php', ['cmid' => $cm->id]);
        } else {
            $context = \context_course::instance($course->id);
            $category = question_make_default_categories([$context]);
            $url = new \moodle_url('/question/edit.php', ['courseid' => $course->id]);
        }
        if (!$category) {
            return null;
        }
        $name = format_string($category->name, true, ['context' => $context]);
        return ['context' => $context, 'category' => $category, 'name' => $name,
            'where' => get_string('qbank_where', 'mod_visuallearning', $name), 'url' => $url];
    }

    /**
     * Imports the questions into a category of their own (named after the lesson) under the default category.
     *
     * @param exporter $exporter
     * @param stdClass $course
     * @param array $target from {@see target()}
     * @return int questions added
     */
    public static function import(exporter $exporter, stdClass $course, array $target): int {
        global $CFG;
        require_once($CFG->libdir . '/questionlib.php');
        require_once($CFG->dirroot . '/question/format.php');
        require_once($CFG->dirroot . '/question/format/xml/format.php');
        if ($target['category'] === null) {
            $target = self::target($course, true);
            if ($target === null) {
                throw new \moodle_exception('qbank_unavailable', 'mod_visuallearning');
            }
            require_capability('moodle/question:add', $target['context']);
        }
        $file = make_request_directory() . '/questions.xml';
        file_put_contents($file, $exporter->moodle_xml());

        $format = new \qformat_xml();
        $format->setCategory($target['category']);
        $format->setContexts([$target['context']]);
        $format->setCourse($course);
        $format->setFilename($file);
        $format->setRealfilename('questions.xml');
        $format->setMatchgrades('nearest');
        // The file names its own category (the lesson): it is made inside the chosen context.
        $format->setCatfromfile(true);
        $format->setContextfromfile(false);
        $format->setStoponerror(true);
        if (method_exists($format, 'set_display_progress')) {
            $format->set_display_progress(false);
        }
        ob_start();
        try {
            $ok = $format->importpreprocess() && $format->importprocess() && $format->importpostprocess();
        } finally {
            ob_end_clean();
        }
        if (!$ok) {
            throw new \moodle_exception('qbank_failed', 'mod_visuallearning');
        }
        return count($exporter->questions());
    }
}
