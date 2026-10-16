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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/webservice/tests/helpers.php');
require_once($CFG->libdir . '/completionlib.php');

/**
 * Tests for true/false answers and the "answer every check correctly" completion rule.
 *
 * @package    mod_visuallearning
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(answer_statement::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_visuallearning\completion\custom_completion::class)]
final class answer_statement_test extends \externallib_advanced_testcase {
    /**
     * Wrong then right answers are recorded, and completion follows the correct answers.
     */
    public function test_answers_and_completion(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $book = $this->getDataGenerator()->create_module(
            'visuallearning',
            [
                'course' => $course->id,
                'completion' => COMPLETION_TRACKING_AUTOMATIC,
                'completionquiz' => 1,
            ],
        );
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_visuallearning');
        $truepage = $generator->create_page($book->id, ['tfanswer' => 1]);
        $falsepage = $generator->create_page($book->id, ['tfanswer' => 0]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        $result = external_api::clean_returnvalue(
            answer_statement::execute_returns(),
            answer_statement::execute($book->cmid, $truepage->id, false)
        );
        $this->assertFalse($result['correct']);
        $result = external_api::clean_returnvalue(
            answer_statement::execute_returns(),
            answer_statement::execute($book->cmid, $truepage->id, true)
        );
        $this->assertTrue($result['correct']);
        $this->assertSame(2, $result['attempts']);

        $cm = get_fast_modinfo($course)->get_cm($book->cmid);
        $completion = new \completion_info($course);
        $this->assertEquals(COMPLETION_INCOMPLETE, $completion->get_data($cm, false, $student->id)->completionstate);

        answer_statement::execute($book->cmid, $falsepage->id, false);
        $this->assertEquals(COMPLETION_COMPLETE, $completion->get_data($cm, false, $student->id)->completionstate);
        $this->assertEquals(2, $DB->count_records('visuallearning_progress', ['userid' => $student->id, 'tfpassed' => 1]));
    }

    /**
     * Statements cannot be answered when the checks are switched off.
     */
    public function test_disabled_quiz_rejects_answers(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $book = $this->getDataGenerator()->create_module('visuallearning', ['course' => $course->id, 'enablequiz' => 0]);
        $page = $this->getDataGenerator()->get_plugin_generator('mod_visuallearning')->create_page($book->id);
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'student'));
        $this->expectException(\invalid_parameter_exception::class);
        answer_statement::execute($book->cmid, $page->id, true);
    }
}
