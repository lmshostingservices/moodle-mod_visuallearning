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

/**
 * List of AI Visual Learning activities in a course.
 *
 * @package    mod_visuallearning
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id = required_param('id', PARAM_INT);
$course = $DB->get_record('course', ['id' => $id], '*', MUST_EXIST);
require_login($course);
$coursecontext = context_course::instance($course->id);
require_capability('mod/visuallearning:view', $coursecontext);

// Moodle 5.0+ lists activities on the course "Activities" overview page.
if (class_exists(\core_courseformat\activityoverviewbase::class)) {
    \core_courseformat\activityoverviewbase::redirect_to_overview_page($course->id, 'visuallearning');
}

\mod_visuallearning\event\course_module_instance_list_viewed::create(['context' => $coursecontext])->trigger();

$PAGE->set_url('/mod/visuallearning/index.php', ['id' => $id]);
$PAGE->set_title(format_string($course->shortname) . ': ' . get_string('modulenameplural', 'mod_visuallearning'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_pagelayout('incourse');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('modulenameplural', 'mod_visuallearning'));

$books = get_all_instances_in_course('visuallearning', $course);
if (!$books) {
    notice(
        get_string('thereareno', 'moodle', get_string('modulenameplural', 'mod_visuallearning')),
        new moodle_url('/course/view.php', ['id' => $course->id])
    );
}

$table = new html_table();
$table->head = [get_string('name'), get_string('industry', 'mod_visuallearning'), get_string('pages', 'mod_visuallearning')];
foreach ($books as $book) {
    $link = html_writer::link(
        new moodle_url('/mod/visuallearning/view.php', ['id' => $book->coursemodule]),
        format_string($book->name),
        $book->visible ? [] : ['class' => 'dimmed']
    );
    $table->data[] = [$link, s($book->industry), $DB->count_records('visuallearning_pages', ['visuallearningid' => $book->id])];
}
echo html_writer::table($table);
echo $OUTPUT->footer();
