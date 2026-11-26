<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace mod_visuallearning;

use mod_visuallearning\local\outcomes;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->dirroot . '/mod/visuallearning/lib.php');
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

#[\PHPUnit\Framework\Attributes\CoversClass(\mod_visuallearning\local\outcomes::class)]
/**
 * Moodle learning outcomes in AI Visual Learning (1.10.0).
 *
 * @package    mod_visuallearning
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_visuallearning\local\outcomes
 */
final class outcomes_test extends \advanced_testcase {
    /** @var \stdClass */
    protected $course;

    /** @var \stdClass Activity record (with cmid) */
    protected $book;

    /** @var \stdClass Course module */
    protected $cm;

    /**
     * A course with a lesson, and learning outcomes switched on.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enableoutcomes', 1);
        outcomes::reset_cache();
        $this->course = $this->getDataGenerator()->create_course();
        $this->book = $this->getDataGenerator()->create_module('visuallearning', ['course' => $this->course->id,
            'enablequiz' => 1]);
        $this->cm = get_coursemodule_from_id('visuallearning', $this->book->cmid);
    }

    /**
     * A course outcome linked to the lesson: scale-less (5.3+) or with a scale (an outcome grade item, as Moodle makes).
     *
     * @param string $shortname
     * @param string $fullname
     * @param int|null $scaleid
     * @param bool $site A site-wide outcome instead of a course one
     * @return \grade_outcome
     */
    protected function link_outcome(
        string $shortname,
        string $fullname,
        ?int $scaleid = null,
        bool $site = false
    ): \grade_outcome {
        global $DB;
        $outcome = new \grade_outcome(['courseid' => $site ? null : $this->course->id, 'shortname' => $shortname,
            'fullname' => $fullname, 'scaleid' => $scaleid]);
        $outcome->insert();
        $outcome->use_in($this->course->id);
        if ($scaleid) {
            $max = (int)$DB->get_field_sql(
                'SELECT MAX(itemnumber) FROM {grade_items} WHERE itemmodule = ? AND iteminstance = ?',
                ['visuallearning', $this->book->id]
            );
            $item = new \grade_item(['courseid' => $this->course->id, 'itemtype' => 'mod', 'itemmodule' => 'visuallearning',
                'iteminstance' => $this->book->id, 'itemnumber' => max(1000, $max + 1), 'itemname' => $fullname,
                'outcomeid' => $outcome->id, 'gradetype' => GRADE_TYPE_SCALE, 'scaleid' => $scaleid]);
            $item->insert();
        } else {
            $outcome->add_outcome_to_module($this->course->id, $this->cm->id);
        }
        outcomes::reset_cache();
        return $outcome;
    }

    /**
     * Skip on Moodle versions without scale-less outcomes.
     */
    protected function require_scaleless(): void {
        if (!outcomes::scaleless_supported()) {
            $this->markTestSkipped('Scale-less learning outcomes need Moodle 5.3.');
        }
    }

    /**
     * The activity supports outcomes, so Moodle shows the Learning outcomes section on its settings form.
     */
    public function test_supports_outcomes(): void {
        $this->assertTrue((bool)visuallearning_supports(FEATURE_GRADE_OUTCOMES));
        $this->assertTrue((bool)plugin_supports('mod', 'visuallearning', FEATURE_GRADE_OUTCOMES, false));
    }

    /**
     * Scale-less and scaled outcomes are both read, with unique codes, and nothing is read when outcomes are off.
     */
    public function test_for_cm_reads_both_kinds(): void {
        $this->require_scaleless();
        $scale = $this->getDataGenerator()->create_scale(['scale' => 'Not yet,Partly,Yes']);
        $a = $this->link_outcome('LO1', 'Identify the temperature danger zone');
        $b = $this->link_outcome('LO2', 'Use a probe thermometer', (int)$scale->id);
        $c = $this->link_outcome('LO1', 'Store high-risk food correctly', null, true);

        $list = outcomes::for_cm($this->cm);
        $this->assertSame([(int)$a->id, (int)$b->id, (int)$c->id], array_keys($list));
        $this->assertSame('LO1', $list[$a->id]->code);
        $this->assertFalse($list[$a->id]->scaled);
        $this->assertNull($list[$a->id]->itemnumber);
        $this->assertTrue($list[$b->id]->scaled);
        $this->assertSame(1000, $list[$b->id]->itemnumber);
        $this->assertSame(3, $list[$b->id]->scaleitems);
        $this->assertSame('LO3', $list[$c->id]->code, 'A site outcome with the same short name gets its own code.');

        set_config('enableoutcomes', 0);
        outcomes::reset_cache();
        $this->assertSame([], outcomes::for_cm($this->cm));
    }

    /**
     * Codes are cleaned and mapped; unknown codes are dropped, never stored.
     */
    public function test_codes_and_fields(): void {
        $list = [7 => (object)['id' => 7, 'code' => 'LO1'], 9 => (object)['id' => 9, 'code' => 'SITXFSA005-2.1']];
        $this->assertSame(['LO1', 'BOGUS'], outcomes::clean_codes([' LO1 ', 'BOGUS', 'LO1', '', ['x']]));
        $this->assertSame(['LO1', 'LO2'], outcomes::clean_codes('LO1, LO2'));
        $this->assertSame([7, 9], outcomes::codes_to_ids(['lo1', 'SITXFSA005-2.1', 'BOGUS'], $list));
        $this->assertSame('7,9', outcomes::to_field([7, 9, 7, 0]));
        $this->assertNull(outcomes::to_field([]));
        $this->assertSame([7, 9], outcomes::page_ids((object)['outcomes' => '7,9']));
        $this->assertSame([], outcomes::page_ids((object)['outcomes' => null]));
        $this->assertSame('LO4', outcomes::code_from('  ', 4, []));
        $this->assertSame('LO2', outcomes::code_from('lo1', 2, ['lo1' => true]));
        $this->assertSame(1, outcomes::scale_value(0, 3));
        $this->assertSame(3, outcomes::scale_value(0.75, 3));
        $this->assertSame(2, outcomes::scale_value(0.5, 3));
        $this->assertSame(4, outcomes::scale_value(1, 4));
    }

    /**
     * The writer's brief lists the outcomes and asks for an "outcomes" list per page, only when there are outcomes.
     */
    public function test_writer_brief(): void {
        global $DB;
        $this->require_scaleless();
        $book = $DB->get_record('visuallearning', ['id' => $this->book->id]);
        $book->sourcecontent = 'Keep high-risk food out of the danger zone.';
        $plain = \mod_visuallearning\local\prompt_builder::writer_prompt($book);
        $this->assertStringNotContainsString('"outcomes"', $plain);
        $this->assertStringNotContainsString('Learning outcomes this lesson must teach', $plain);

        $this->link_outcome('LO1', 'Identify the temperature danger zone');
        $brief = \mod_visuallearning\local\prompt_builder::writer_prompt($book);
        $this->assertStringContainsString('Learning outcomes this lesson must teach (code: outcome):', $brief);
        $this->assertStringContainsString('- LO1: Identify the temperature danger zone', $brief);
        $this->assertStringContainsString('"outcomes": ["string - outcome code"]', $brief);
        $this->assertStringContainsString('only these codes: "LO1"', $brief);
        $this->assertStringContainsString('the characters < or >', $brief);
        // The lesson inputs travel in the content sent to LMS Labs, so its own brief sees the outcomes too.
        $this->assertStringContainsString(
            '- LO1: Identify the temperature danger zone',
            \mod_visuallearning\local\prompt_builder::lesson_extras($book)
        );
    }

    /**
     * Imported pages are tagged with the lesson's outcomes; unknown codes are dropped.
     */
    public function test_import_tags_pages(): void {
        global $DB;
        $this->require_scaleless();
        $a = $this->link_outcome('LO1', 'Identify the temperature danger zone');
        $b = $this->link_outcome('LO2', 'Use a probe thermometer');
        $page = function (string $title, array $codes) {
            return ['title' => $title, 'concept' => 'An idea. A reason.', 'action' => 'Do it. Like this.',
                'outcome' => 'It works. Here is why.', 'summary' => 'Summary.', 'image_prompt' => 'A kitchen',
                'alt_text' => 'A kitchen', 'outcomes' => $codes,
                'question' => ['type' => 'truefalse', 'statement' => 'Food is safe at 30 degrees.', 'answer' => false,
                    'feedback' => 'It is in the danger zone.']];
        };
        $raw = json_encode(['book_title' => 'Food safety', 'pages' => [
            $page('Danger zone', ['LO1']), $page('Probing', ['LO2', 'LO9']), $page('Storage', [])]]);
        [$parsed, $errors] = \mod_visuallearning\local\importer::parse($raw, 4);
        $this->assertSame([], $errors);
        $book = $DB->get_record('visuallearning', ['id' => $this->book->id]);
        \mod_visuallearning\local\importer::import($book, $parsed, \context_module::instance($this->cm->id));
        $tags = $DB->get_records_menu('visuallearning_pages', ['visuallearningid' => $book->id], 'pagenum', 'pagenum, outcomes');
        $this->assertSame([1 => (string)$a->id, 2 => (string)$b->id, 3 => null], $tags);

        $pages = $DB->get_records('visuallearning_pages', ['visuallearningid' => $book->id], 'pagenum');
        $coverage = outcomes::coverage(outcomes::for_cm($this->cm), $pages);
        $this->assertSame([(int)$a->id => [1], (int)$b->id => [2]], $coverage);
        $rows = outcomes::mapping_rows(outcomes::for_cm($this->cm), $pages);
        $this->assertSame(['outcome_code', 'outcome', 'scaled', 'pages', 'page_titles', 'check_questions'], $rows[0]);
        $this->assertSame(['LO2', 'Use a probe thermometer', 'no', '2', 'Probing', '2: Food is safe at 30 degrees.'], $rows[2]);
        $csv = outcomes::import_csv(outcomes::for_cm($this->cm));
        $this->assertStringStartsWith('outcome_name;outcome_shortname;outcome_description;scale_name;scale_items;', $csv);
        $this->assertStringContainsString('"Use a probe thermometer";"LO2";"";"";"";""', $csv);
    }

    /**
     * Scaled outcomes are rated once every tagged question is right, never over a teacher's rating, and only once.
     */
    public function test_rating(): void {
        global $DB;
        $scale = $this->getDataGenerator()->create_scale(['scale' => 'Not yet,Partly,Yes']);
        $outcome = $this->link_outcome('LO1', 'Use a probe thermometer', (int)$scale->id);
        $DB->set_field('visuallearning', 'outcomerating', 1, ['id' => $this->book->id]);
        $book = $DB->get_record('visuallearning', ['id' => $this->book->id]);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_visuallearning');
        $pages = [];
        foreach ([1, 2, 3, 4] as $n) {
            $pages[$n] = $generator->create_page($book->id, ['tfstatement' => "Statement $n", 'tfanswer' => 1,
                'outcomes' => (string)$outcome->id]);
        }
        $learner = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $teacherrated = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $answer = function (int $userid, int $n, int $attempts, int $passed) use ($book, $pages, $DB) {
            $DB->insert_record('visuallearning_progress', (object)['visuallearningid' => $book->id,
                'pageid' => $pages[$n]->id, 'userid' => $userid, 'tfattempts' => $attempts, 'tfpassed' => $passed]);
        };
        // Three right first time, one needed a second try: 0.75 of a 3-item scale is the top item (3).
        $answer($learner->id, 1, 1, 1);
        $answer($learner->id, 2, 1, 1);
        $answer($learner->id, 3, 2, 1);
        $this->assertSame([], outcomes::rate_user($book, $this->cm, (int)$learner->id), 'Not every question answered yet.');
        $answer($learner->id, 4, 1, 1);
        $this->assertSame([(int)$outcome->id => 3], outcomes::rate_user($book, $this->cm, (int)$learner->id));
        $item = \grade_item::fetch(['iteminstance' => $book->id, 'itemmodule' => 'visuallearning', 'itemnumber' => 1000]);
        $this->assertEquals(3, \grade_grade::fetch(['itemid' => $item->id, 'userid' => $learner->id])->finalgrade);
        $this->assertSame([], outcomes::rate_user($book, $this->cm, (int)$learner->id), 'Rated only once.');

        // A teacher's rating is never replaced.
        $item->update_final_grade($teacherrated->id, 1, 'gradebook');
        foreach ([1, 2, 3, 4] as $n) {
            $answer($teacherrated->id, $n, 1, 1);
        }
        $this->assertSame([], outcomes::rate_user($book, $this->cm, (int)$teacherrated->id));
        $this->assertEquals(1, \grade_grade::fetch(['itemid' => $item->id, 'userid' => $teacherrated->id])->finalgrade);

        // Off by default.
        $book->outcomerating = 0;
        $this->assertSame([], outcomes::rate_user($book, $this->cm, (int)$learner->id));
    }

    /**
     * The player lists the outcomes on the opening page unless the teacher hides them.
     */
    public function test_player_data(): void {
        global $DB;
        $this->require_scaleless();
        $this->link_outcome('LO1', 'Identify the temperature danger zone');
        $book = $DB->get_record('visuallearning', ['id' => $this->book->id]);
        $data = \mod_visuallearning\local\book_data::player(
            $book,
            \cm_info::create($this->cm),
            \context_module::instance($this->cm->id),
            (int)get_admin()->id
        );
        $this->assertSame([['code' => 'LO1', 'name' => 'Identify the temperature danger zone']], $data['outcomes']);
        $this->assertSame('Learning outcomes', $data['strings']['coveroutcomes']);
        $book->showoutcomes = 0;
        $data = \mod_visuallearning\local\book_data::player(
            $book,
            \cm_info::create($this->cm),
            \context_module::instance($this->cm->id),
            (int)get_admin()->id
        );
        $this->assertSame([], $data['outcomes']);
    }

    /**
     * Page tags survive a backup and restore into another course, pointing at the restored outcomes.
     */
    public function test_backup_restore_keeps_tags(): void {
        global $DB;
        $this->require_scaleless();
        $outcome = $this->link_outcome('LO1', 'Identify the temperature danger zone');
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_visuallearning');
        $generator->create_page($this->book->id, ['outcomes' => (string)$outcome->id]);
        $generator->create_page($this->book->id, ['outcomes' => (string)($outcome->id + 999)]);

        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $this->course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_IMPORT,
            get_admin()->id
        );
        $bc->execute_plan();
        $backupid = $bc->get_backupid();
        $bc->destroy();
        $target = $this->getDataGenerator()->create_course();
        $rc = new \restore_controller(
            $backupid,
            $target->id,
            \backup::INTERACTIVE_NO,
            \backup::MODE_IMPORT,
            get_admin()->id,
            \backup::TARGET_EXISTING_ADDING
        );
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();

        outcomes::reset_cache();
        $newbook = $DB->get_record('visuallearning', ['course' => $target->id], '*', MUST_EXIST);
        $newcm = get_coursemodule_from_instance('visuallearning', $newbook->id);
        $linked = outcomes::for_cm($newcm);
        $this->assertCount(1, $linked);
        $newid = (int)array_key_first($linked);
        $tags = $DB->get_records_menu(
            'visuallearning_pages',
            ['visuallearningid' => $newbook->id],
            'pagenum',
            'pagenum, outcomes'
        );
        $this->assertSame([1 => (string)$newid, 2 => null], $tags, 'Tags point at the restored outcome; others go.');
    }
}
