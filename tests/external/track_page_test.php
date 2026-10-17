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
 * Tests for page tracking and the listened-to-every-page completion rule.
 *
 * @package    mod_visuallearning
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(track_page::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_visuallearning\completion\custom_completion::class)]
final class track_page_test extends \externallib_advanced_testcase {
    /**
     * Call the external function and clean the result.
     *
     * @param int $cmid
     * @param int $pageid
     * @param string $action
     * @return array
     */
    private function track(int $cmid, int $pageid, string $action): array {
        $result = track_page::execute($cmid, $pageid, $action);
        return external_api::clean_returnvalue(track_page::execute_returns(), $result);
    }

    /**
     * Views, capped heartbeats, resume and listening through to completion.
     */
    public function test_tracking_and_completion(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $book = $this->getDataGenerator()->create_module(
            'visuallearning',
            [
                'course' => $course->id,
                'completion' => COMPLETION_TRACKING_AUTOMATIC,
                'completionlistened' => 1,
            ],
        );
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_visuallearning');
        $pages = [$generator->create_page($book->id), $generator->create_page($book->id)];
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        $result = $this->track($book->cmid, $pages[0]->id, 'view');
        $this->assertFalse($result['listened']);
        $this->assertSame(2, $result['totalpages']);

        $params = ['pageid' => $pages[0]->id, 'userid' => $student->id];
        $DB->set_field('visuallearning_progress', 'lastping', time() - 20, $params);
        $result = $this->track($book->cmid, $pages[0]->id, 'ping');
        $this->assertGreaterThanOrEqual(19, $result['timespent']);
        $this->assertLessThanOrEqual(21, $result['timespent']);

        // A long gap is capped.
        $DB->set_field('visuallearning_progress', 'lastping', time() - 3600, $params);
        $result = $this->track($book->cmid, $pages[0]->id, 'ping');
        $this->assertLessThanOrEqual(21 + track_page::MAXCREDIT, $result['timespent']);
        $spent = $result['timespent'];

        // Returning to a hidden tab credits nothing.
        $DB->set_field('visuallearning_progress', 'lastping', time() - 3600, $params);
        $result = $this->track($book->cmid, $pages[0]->id, 'resume');
        $this->assertSame($spent, $result['timespent']);

        $cm = get_fast_modinfo($course)->get_cm($book->cmid);
        $completion = new \completion_info($course);
        $this->assertEquals(COMPLETION_INCOMPLETE, $completion->get_data($cm, false, $student->id)->completionstate);

        $this->track($book->cmid, $pages[0]->id, 'listened');
        $this->track($book->cmid, $pages[1]->id, 'view');
        $result = $this->track($book->cmid, $pages[1]->id, 'listened');
        $this->assertTrue($result['allcomplete']);
        $this->assertSame(2, $result['listenedcount']);
        $this->assertEquals(COMPLETION_COMPLETE, $completion->get_data($cm, false, $student->id)->completionstate);
    }

    /**
     * A page from another lesson cannot be tracked.
     */
    public function test_page_must_belong_to_book(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $book1 = $this->getDataGenerator()->create_module('visuallearning', ['course' => $course->id]);
        $book2 = $this->getDataGenerator()->create_module('visuallearning', ['course' => $course->id]);
        $page = $this->getDataGenerator()->get_plugin_generator('mod_visuallearning')->create_page($book2->id);
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'student'));
        $this->expectException(\dml_missing_record_exception::class);
        track_page::execute($book1->cmid, $page->id, 'view');
    }
}
