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
 * The AI Visual Learning player.
 *
 * @package    mod_visuallearning
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id = required_param('id', PARAM_INT);
[$course, $cm] = get_course_and_cm_from_cmid($id, 'visuallearning');
$book = $DB->get_record('visuallearning', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/visuallearning:view', $context);

visuallearning_view($book, $course, $cm, $context);

$PAGE->set_url('/mod/visuallearning/view.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($book->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_activity_record($book);
$PAGE->add_body_class('path-mod-visuallearning-player');
// The lesson has its own title bar, description (on the cover) and progress; keep the standard
// activity header compact so the spread fits above the fold.
$PAGE->activityheader->set_attrs(['title' => '', 'description' => '', 'hidecompletion' => true]);

$canmanage = has_capability('mod/visuallearning:manage', $context);
$data = \mod_visuallearning\local\book_data::player($book, $cm, $context, (int)$USER->id);

echo $OUTPUT->header();

if (empty($data['pages'])) {
    echo $OUTPUT->notification(
        get_string($canmanage ? 'nopages_teacher' : 'nopages_student', 'mod_visuallearning'),
        \core\output\notification::NOTIFY_INFO
    );
    if ($canmanage) {
        echo $OUTPUT->single_button(
            new moodle_url('/mod/visuallearning/manage.php', ['id' => $cm->id]),
            get_string('setupbook', 'mod_visuallearning'),
            'get',
            ['type' => 'primary']
        );
    }
    echo $OUTPUT->footer();
    exit;
}

if ($canmanage && $data['missingmedia']) {
    echo $OUTPUT->notification(
        get_string(
            'mediamissing_teacher',
            'mod_visuallearning',
            (new moodle_url('/mod/visuallearning/manage.php', ['id' => $cm->id]))->out()
        ),
        \core\output\notification::NOTIFY_WARNING,
    );
}

$uniqid = html_writer::random_id('visuallearning');
echo $OUTPUT->render_from_template(
    'mod_visuallearning/player',
    [
        'uniqid' => $uniqid,
        'cmid' => $cm->id,
        'json' => json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE),
    ],
);
$PAGE->requires->js_call_amd('mod_visuallearning/player', 'init', [$uniqid]);

echo $OUTPUT->footer();
