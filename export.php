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
 * Download all of a lesson's content in one ZIP, or add its questions to the course question bank (both free).
 *
 * @package    mod_visuallearning
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use mod_visuallearning\local\exporter;

$id = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'visuallearning');
$book = $DB->get_record('visuallearning', ['id' => $cm->instance], '*', MUST_EXIST);
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/visuallearning:manage', $context);

$url = new moodle_url('/mod/visuallearning/export.php', ['id' => $cm->id]);
$PAGE->set_url($url);
$PAGE->set_title(format_string($book->name) . ': ' . get_string('export_title', 'mod_visuallearning'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_activity_record($book);
$PAGE->activityheader->disable();

$exporter = new exporter($book, $context);

if ($action === 'download') {
    require_sesskey();
    \core_php_time_limit::raise(300);
    raise_memory_limit(MEMORY_EXTRA);
    $zip = $exporter->zip();
    $name = exporter::slug(format_string($book->name, true, ['context' => $context])) . '.zip';
    send_file($zip, $name, 0, 0, false, true, 'application/zip');
    die;
}

if ($action === 'qbank') {
    $target = \mod_visuallearning\local\qbank::target($course);
    if ($target === null) {
        throw new moodle_exception('qbank_unavailable', 'mod_visuallearning');
    }
    require_capability('moodle/question:add', $target['context']);
    if (optional_param('confirm', 0, PARAM_BOOL) && confirm_sesskey()) {
        $count = \mod_visuallearning\local\qbank::import($exporter, $course, $target);
        redirect(
            $target['url'],
            get_string('qbank_done', 'mod_visuallearning', (object)['count' => $count, 'category' => $target['name']]),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    echo $OUTPUT->header();
    echo $OUTPUT->confirm(
        get_string('qbank_confirm', 'mod_visuallearning', (object)['count' => count($exporter->questions()),
            'where' => $target['where']]),
        new single_button(
            new moodle_url($url, ['action' => 'qbank', 'confirm' => 1, 'sesskey' => sesskey()]),
            get_string('qbank_button', 'mod_visuallearning'),
            'post',
            single_button::BUTTON_PRIMARY
        ),
        $url
    );
    echo $OUTPUT->footer();
    exit;
}

$questions = count($exporter->questions());
$files = $exporter->files();
$count = fn(string $folder): int => count(array_filter(array_keys($files), fn($p) => str_starts_with($p, $folder)));
$items = [
    ['Q', 'questions', $questions ? get_string('export_questions_desc', 'mod_visuallearning', $questions)
        : get_string('export_questions_none', 'mod_visuallearning'), ['Moodle XML', 'GIFT', 'CSV']],
    ['Aa', 'text', get_string('export_text_desc', 'mod_visuallearning', count(visuallearning_get_pages($book->id))),
        ['Word', 'Markdown', 'CSV']],
    ['▣', 'pictures', get_string('export_pictures_desc', 'mod_visuallearning', $count('pictures/')), ['PNG']],
    ['♪', 'voice', get_string('export_voice_desc', 'mod_visuallearning', max(0, $count('voiceover/') - 1)), ['MP3', 'TXT']],
    ['✦', 'prompts', get_string('export_prompts_desc', 'mod_visuallearning'), ['Markdown']],
];

echo $OUTPUT->header();
echo html_writer::start_div('vl-export');
echo html_writer::div(
    html_writer::div('<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v11M7 10l5 5 5-5M5 20h14" fill="none" ' .
        'stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>', 'vl-export-icon') .
    html_writer::div(
        html_writer::tag('h2', get_string('export_title', 'mod_visuallearning')) .
        html_writer::tag('p', get_string('export_intro', 'mod_visuallearning')),
        'vl-export-head-text'
    ),
    'vl-export-head'
);
$rows = '';
foreach ($items as [$icon, $key, $desc, $formats]) {
    $chips = implode('', array_map(fn($f) => html_writer::span(s($f), 'vl-fmt'), $formats));
    $rows .= html_writer::div(
        html_writer::span($icon, 'vl-export-ic vl-export-ic--' . $key, ['aria-hidden' => 'true']) .
        html_writer::div(
            html_writer::div(html_writer::tag('strong', get_string('export_' . $key, 'mod_visuallearning')) .
                html_writer::span($chips, 'vl-fmts'), 'vl-export-row-top') .
            html_writer::span($desc, 'vl-export-desc'),
            'vl-export-row-body'
        ),
        'vl-export-row'
    );
}
echo html_writer::div($rows, 'vl-export-rows');
if ($questions) {
    echo html_writer::tag(
        'p',
        html_writer::tag('strong', get_string('export_types_title', 'mod_visuallearning')) . ' ' .
            get_string('export_types', 'mod_visuallearning'),
        ['class' => 'vl-export-note']
    );
}
$buttons = html_writer::link(
    new moodle_url($url, ['action' => 'download', 'sesskey' => sesskey()]),
    get_string('export_download', 'mod_visuallearning'),
    ['class' => 'btn btn-primary']
);
$target = $questions ? \mod_visuallearning\local\qbank::target($course) : null;
if ($target && has_capability('moodle/question:add', $target['context'])) {
    $buttons .= html_writer::link(
        new moodle_url($url, ['action' => 'qbank']),
        get_string('qbank_button', 'mod_visuallearning'),
        ['class' => 'btn btn-secondary']
    );
}
$buttons .= html_writer::link(
    new moodle_url('/mod/visuallearning/manage.php', ['id' => $cm->id, 'step' => 4]),
    get_string('back'),
    ['class' => 'btn btn-link']
);
echo html_writer::div(html_writer::span(get_string('export_free', 'mod_visuallearning'), 'vl-export-free') .
    html_writer::div($buttons, 'pb-manage-actions'), 'vl-export-foot');
echo html_writer::end_div();
echo $OUTPUT->footer();
