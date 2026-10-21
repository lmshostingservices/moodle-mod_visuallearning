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

namespace mod_visuallearning;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * Backup and restore tests.
 *
 * @package    mod_visuallearning
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\restore_visuallearning_activity_structure_step::class)]
final class backup_restore_test extends \advanced_testcase {
    /**
     * Pages and their media survive backup and restore, with file item ids remapped to the new pages.
     */
    public function test_duplicate_keeps_pages_and_media(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $book = $this->getDataGenerator()->create_module('visuallearning', ['course' => $course->id]);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_visuallearning');
        $context = \context_module::instance($book->cmid);
        $fs = get_file_storage();
        $stage = ['shows' => 'A basin', 'moves' => 'Water enters a pipe', 'labels' => ['Water']];
        $plan = \mod_visuallearning\local\scene_plan::json([
            'layout' => 'A basin above a pipe', 'stages' => [$stage, $stage, $stage],
            'all' => 'Water flows through the pipe', 'colours' => 'blue = water',
            'realtime' => false, 'accuracy' => 'Flow follows the pipe', 'exclude' => '',
        ]);
        foreach ([1, 2] as $n) {
            $page = $generator->create_page($book->id);
            $DB->set_field('visuallearning_pages', 'sceneplan', $n === 1 ? $plan : null, ['id' => $page->id]);
            $fs->create_file_from_string(
                ['contextid' => $context->id, 'component' => 'mod_visuallearning',
                    'filearea' => 'image', 'itemid' => $page->id, 'filepath' => '/', 'filename' => "image_$n.png"],
                'img',
            );
            $fs->create_file_from_string(
                ['contextid' => $context->id, 'component' => 'mod_visuallearning',
                    'filearea' => 'audio', 'itemid' => $page->id, 'filepath' => '/', 'filename' => "concept_$n.mp3"],
                'mp3',
            );
        }

        // Back up the activity and restore it into a second course (works on every supported version).
        $bc = new \backup_controller(
            \backup::TYPE_1ACTIVITY,
            $book->cmid,
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
        $rc->execute_precheck();
        $rc->execute_plan();
        $rc->destroy();

        $newbook = $DB->get_record('visuallearning', ['course' => $target->id], '*', MUST_EXIST);
        $newcm = get_coursemodule_from_instance('visuallearning', $newbook->id);
        $newpages = $DB->get_records('visuallearning_pages', ['visuallearningid' => $newbook->id]);
        $this->assertCount(2, $newpages);
        $restored = array_values($newpages);
        $this->assertSame($plan, $restored[0]->sceneplan);
        $this->assertNull($restored[1]->sceneplan);

        $newcontext = \context_module::instance($newcm->id);
        foreach (['image', 'audio'] as $area) {
            $files = $fs->get_area_files($newcontext->id, 'mod_visuallearning', $area, false, 'id', false);
            $this->assertCount(2, $files);
            foreach ($files as $file) {
                $this->assertArrayHasKey($file->get_itemid(), $newpages);
            }
        }
    }
}
