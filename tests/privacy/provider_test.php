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

namespace mod_visuallearning\privacy;

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;

/**
 * Privacy provider tests.
 *
 * @package    mod_visuallearning
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
final class provider_test extends provider_testcase {
    /** @var \stdClass */
    private $book;
    /** @var \stdClass */
    private $user1;
    /** @var \stdClass */
    private $user2;
    /** @var \context_module */
    private $context;

    /**
     * Two learners with progress in one lesson.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->book = $this->getDataGenerator()->create_module('visuallearning', ['course' => $course->id]);
        $this->context = \context_module::instance($this->book->cmid);
        $page = $this->getDataGenerator()->get_plugin_generator('mod_visuallearning')->create_page($this->book->id);
        $this->user1 = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->user2 = $this->getDataGenerator()->create_and_enrol($course, 'student');
        foreach ([$this->user1, $this->user2] as $user) {
            $DB->insert_record(
                'visuallearning_progress',
                (object)[
                    'visuallearningid' => $this->book->id, 'pageid' => $page->id, 'userid' => $user->id,
                    'views' => 2, 'listened' => 1, 'timespent' => 40, 'timefirstviewed' => time(),
                    'timelistened' => time(), 'lastping' => time(),
                ],
            );
        }
    }

    /**
     * Contexts and users are found.
     */
    public function test_get_contexts_and_users(): void {
        $contextlist = provider::get_contexts_for_userid($this->user1->id);
        $this->assertEquals([$this->context->id], $contextlist->get_contextids());

        $userlist = new userlist($this->context, 'mod_visuallearning');
        provider::get_users_in_context($userlist);
        $this->assertEqualsCanonicalizing([$this->user1->id, $this->user2->id], $userlist->get_userids());
    }

    /**
     * Export contains the page progress.
     */
    public function test_export_user_data(): void {
        $this->export_context_data_for_user($this->user1->id, $this->context, 'mod_visuallearning');
        $data = writer::with_context($this->context)->get_data([]);
        $this->assertCount(1, $data->pages);
        $this->assertSame(40, $data->pages[0]['timespent']);
    }

    /**
     * Deletion for one user, then for a list of users.
     */
    public function test_delete(): void {
        global $DB;
        provider::delete_data_for_user(new approved_contextlist($this->user1, 'mod_visuallearning', [$this->context->id]));
        $this->assertEquals(0, $DB->count_records('visuallearning_progress', ['userid' => $this->user1->id]));
        $this->assertEquals(1, $DB->count_records('visuallearning_progress', ['userid' => $this->user2->id]));

        provider::delete_data_for_users(new approved_userlist($this->context, 'mod_visuallearning', [$this->user2->id]));
        $this->assertEquals(0, $DB->count_records('visuallearning_progress', ['visuallearningid' => $this->book->id]));
    }

    /**
     * Deleting a whole context removes every learner's progress.
     */
    public function test_delete_all_users_in_context(): void {
        global $DB;
        provider::delete_data_for_all_users_in_context($this->context);
        $this->assertEquals(0, $DB->count_records('visuallearning_progress', ['visuallearningid' => $this->book->id]));
    }
}
