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
use mod_visuallearning\local\question;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/webservice/tests/helpers.php');
require_once($CFG->libdir . '/completionlib.php');

/**
 * Tests for check-question answers (all four types), the listen-again lock and the completion rule.
 *
 * @package    mod_visuallearning
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(answer_question::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(track_page::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_visuallearning\completion\custom_completion::class)]
final class answer_question_test extends \externallib_advanced_testcase {
    /**
     * Answers a question.
     *
     * @param int $cmid
     * @param int $pageid
     * @param array $response
     * @return array
     */
    private function answer(int $cmid, int $pageid, array $response): array {
        return external_api::clean_returnvalue(
            answer_question::execute_returns(),
            answer_question::execute($cmid, $pageid, json_encode($response))
        );
    }

    /**
     * Marks a page listened to in full.
     *
     * @param int $cmid
     * @param int $pageid
     */
    private function listen(int $cmid, int $pageid): void {
        track_page::execute($cmid, $pageid, 'listened');
    }

    /**
     * A wrong answer locks the question until the page is heard again; a right one passes and completes.
     */
    public function test_wrong_answer_locks_until_listened_and_completion(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $book = $this->getDataGenerator()->create_module('visuallearning', ['course' => $course->id,
            'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionquiz' => 1]);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_visuallearning');
        $truepage = $generator->create_page($book->id, ['tfanswer' => 1, 'feedback' => 'Because it is true.']);
        $falsepage = $generator->create_page($book->id, ['tfanswer' => 0]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        $result = $this->answer($book->cmid, $truepage->id, ['value' => false]);
        $this->assertFalse($result['correct']);
        $this->assertTrue($result['relisten']);
        $this->assertSame('', $result['feedback']);
        $this->assertSame(1, $result['attempts']);

        // Locked: even the right answer is not accepted (or counted) before listening again.
        $result = $this->answer($book->cmid, $truepage->id, ['value' => true]);
        $this->assertFalse($result['correct']);
        $this->assertTrue($result['relisten']);
        $this->assertSame(1, $result['attempts']);

        $this->listen($book->cmid, $truepage->id);
        $result = $this->answer($book->cmid, $truepage->id, ['value' => true]);
        $this->assertTrue($result['correct']);
        $this->assertFalse($result['relisten']);
        $this->assertSame(2, $result['attempts']);
        $this->assertSame('Because it is true.', $result['feedback']);

        $cm = get_fast_modinfo($course)->get_cm($book->cmid);
        $completion = new \completion_info($course);
        $this->assertEquals(COMPLETION_INCOMPLETE, $completion->get_data($cm, false, $student->id)->completionstate);
        $this->answer($book->cmid, $falsepage->id, ['value' => false]);
        $this->assertEquals(COMPLETION_COMPLETE, $completion->get_data($cm, false, $student->id)->completionstate);
        $this->assertEquals(2, $DB->count_records('visuallearning_progress', ['userid' => $student->id, 'tfpassed' => 1]));
    }

    /**
     * With the teacher's "send back to listen" setting off, a wrong answer can simply be tried again.
     */
    public function test_try_again_without_listening_when_the_lock_is_off(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $book = $this->getDataGenerator()->create_module('visuallearning', ['course' => $course->id, 'relistenlock' => 0]);
        $this->assertEquals(0, $DB->get_field('visuallearning', 'relistenlock', ['id' => $book->id]));
        $page = $this->getDataGenerator()->get_plugin_generator('mod_visuallearning')->create_page($book->id, ['tfanswer' => 1]);
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'student'));

        $result = $this->answer($book->cmid, $page->id, ['value' => false]);
        $this->assertFalse($result['correct']);
        $this->assertFalse($result['relisten']);
        $this->assertSame('', $result['retryaudio']);
        // Not locked: the next answer counts straight away.
        $result = $this->answer($book->cmid, $page->id, ['value' => true]);
        $this->assertTrue($result['correct']);
        $this->assertSame(2, $result['attempts']);
    }

    /**
     * Picture pick, matching and sorting are checked on the server; their answers never reach the browser.
     */
    public function test_pick_match_and_sort(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $book = $this->getDataGenerator()->create_module('visuallearning', ['course' => $course->id]);
        $context = \context_module::instance($book->cmid);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_visuallearning');
        $options = array_map(fn($l) => ['label' => $l, 'image_prompt' => '', 'alt_text' => $l], ['A', 'B', 'C', 'D']);
        $pick = $generator->create_page($book->id, ['qtype' => 'pick', 'tfstatement' => 'Which one?',
            'qdata' => json_encode(['options' => $options, 'correct' => 2])]);
        $pairs = [['left' => 'Head chef', 'right' => 'Calls the orders'], ['left' => 'Sous chef', 'right' => 'Runs the pass'],
            ['left' => 'Kitchen hand', 'right' => 'Washes the pans']];
        $match = $generator->create_page($book->id, ['qtype' => 'match', 'tfstatement' => 'Match them.',
            'qdata' => json_encode(['pairs' => $pairs])]);
        $items = [['text' => 'Prep', 'group' => 0], ['text' => 'Taste', 'group' => 1], ['text' => 'Label', 'group' => 0],
            ['text' => 'Plate', 'group' => 1]];
        $sort = $generator->create_page($book->id, ['qtype' => 'sort', 'tfstatement' => 'Sort them.',
            'qdata' => json_encode(['groups' => ['Before', 'During'], 'items' => $items])]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        $bookrecord = $GLOBALS['DB']->get_record('visuallearning', ['id' => $book->id]);
        // The browser gets no correct index, no pairing and no groups of the cards.
        $view = question::for_player(question::of_page($pick), $pick, $context, $bookrecord);
        $this->assertArrayNotHasKey('correct', $view);
        $view = question::for_player(question::of_page($match), $match, $context, $bookrecord);
        $this->assertCount(3, $view['rights']);
        $this->assertStringNotContainsString('"right"', json_encode($view));
        $view = question::for_player(question::of_page($sort), $sort, $context, $bookrecord);
        $this->assertStringNotContainsString('group', json_encode($view['items']));

        $this->assertFalse($this->answer($book->cmid, $pick->id, ['choice' => 1])['correct']);
        $this->listen($book->cmid, $pick->id);
        $this->assertTrue($this->answer($book->cmid, $pick->id, ['choice' => 2])['correct']);

        $right = [];
        foreach (array_keys($pairs) as $i) {
            $right[$i] = question::key((int)$match->id, $i);
        }
        $swapped = $right;
        [$swapped[0], $swapped[1]] = [$right[1], $right[0]];
        $this->assertFalse($this->answer($book->cmid, $match->id, ['pairs' => $swapped])['correct']);
        $this->listen($book->cmid, $match->id);
        $this->assertTrue($this->answer($book->cmid, $match->id, ['pairs' => $right])['correct']);

        $this->assertFalse($this->answer($book->cmid, $sort->id, ['items' => [0, 1, 0]])['correct']);
        $this->listen($book->cmid, $sort->id);
        $this->assertTrue($this->answer($book->cmid, $sort->id, ['items' => [0, 1, 0, 1]])['correct']);
    }

    /**
     * Questions cannot be answered when the checks are switched off.
     */
    public function test_disabled_checks_reject_answers(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $book = $this->getDataGenerator()->create_module('visuallearning', ['course' => $course->id, 'enablequiz' => 0]);
        $page = $this->getDataGenerator()->get_plugin_generator('mod_visuallearning')->create_page($book->id);
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'student'));
        $this->expectException(\invalid_parameter_exception::class);
        answer_question::execute($book->cmid, $page->id, json_encode(['value' => true]));
    }
}
