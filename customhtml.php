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
 * A teacher's own HTML in place of a page picture: paste or upload it, preview it with the card messages, or remove it.
 *
 * @package    mod_visuallearning
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use mod_visuallearning\local\approaches;
use mod_visuallearning\local\media;

$id = required_param('id', PARAM_INT);
$pageid = required_param('pageid', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'visuallearning');
$book = $DB->get_record('visuallearning', ['id' => $cm->instance], '*', MUST_EXIST);
$page = $DB->get_record('visuallearning_pages', ['id' => $pageid, 'visuallearningid' => $book->id], '*', MUST_EXIST);
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/visuallearning:manage', $context);
require_capability('mod/visuallearning:addcustomhtml', $context);

$url = new moodle_url('/mod/visuallearning/customhtml.php', ['id' => $cm->id, 'pageid' => $page->id]);
$back = new moodle_url('/mod/visuallearning/manage.php', ['id' => $cm->id, 'step' => 3]);
$PAGE->set_url($url);
$PAGE->set_title(format_string($book->name) . ': ' . get_string('customhtml_title', 'mod_visuallearning'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_activity_record($book);
$PAGE->activityheader->disable();
$PAGE->navbar->add(get_string('managepages', 'mod_visuallearning'), $back);
$PAGE->navbar->add(get_string('customhtml_title', 'mod_visuallearning'));

if (!media::html_enabled()) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('customhtml_disabled', 'mod_visuallearning'), 'info');
    echo $OUTPUT->continue_button($back);
    echo $OUTPUT->footer();
    die();
}

if ($action === 'remove') {
    require_sesskey();
    media::delete_html($context, (int)$page->id);
    redirect($back, get_string('customhtml_removed', 'mod_visuallearning'), null, \core\output\notification::NOTIFY_SUCCESS);
}

$current = media::html_file($context, (int)$page->id);
$form = new \mod_visuallearning\form\customhtml_form($url, ['cmid' => $cm->id, 'pageid' => $page->id]);
$form->set_data(['alttext' => $page->alttext, 'html' => $current ? $current->get_content() : '']);
if ($form->is_cancelled()) {
    redirect($back);
}
if ($form->get_data()) {
    media::save_html($context, (int)$page->id, $form->html());
    $DB->update_record('visuallearning_pages', (object)['id' => $page->id,
        'alttext' => trim((string)$form->get_data()->alttext), 'timemodified' => time()]);
    redirect($url, get_string('customhtml_saved', 'mod_visuallearning'), null, \core\output\notification::NOTIFY_SUCCESS);
}

// A ready-made prompt for Claude (or any assistant) with this page's key points and the message protocol.
$cardlines = [];
foreach (VISUALLEARNING_CARDS as $card) {
    $text = trim((string)$page->{$card . 'text'});
    if ($text !== '') {
        $cardlines[] = get_string(
            'customhtml_prompt_card',
            'mod_visuallearning',
            ['n' => count($cardlines) + 1, 'label' => strip_tags(approaches::card_label($book, $card)), 'text' => $text]
        );
    }
}
$prompt = get_string('customhtml_prompt', 'mod_visuallearning', [
    'title' => $page->title,
    'cards' => implode("\n", $cardlines),
]);

echo $OUTPUT->header();
echo html_writer::start_div('vl-customhtml');
echo $OUTPUT->heading(get_string(
    'customhtml_heading',
    'mod_visuallearning',
    (int)$page->pagenum . '. ' . format_string($page->title, true, ['context' => $context])
), 2);
echo html_writer::tag('p', get_string('customhtml_intro', 'mod_visuallearning'), ['class' => 'vl-lead']);

if ($current) {
    $tests = '';
    foreach ($cardlines as $n => $unused) {
        $tests .= html_writer::tag(
            'button',
            get_string('customhtml_testcard', 'mod_visuallearning', $n + 1),
            ['type' => 'button', 'class' => 'btn btn-outline-secondary btn-sm', 'data-vl-test' => 'card', 'data-card' => $n + 1]
        );
    }
    $tests .= html_writer::tag(
        'button',
        get_string('customhtml_testall', 'mod_visuallearning'),
        ['type' => 'button', 'class' => 'btn btn-outline-secondary btn-sm', 'data-vl-test' => 'finish']
    );
    $tests .= html_writer::tag(
        'button',
        get_string('customhtml_testreset', 'mod_visuallearning'),
        ['type' => 'button', 'class' => 'btn btn-outline-secondary btn-sm', 'data-vl-test' => 'reset']
    );
    echo html_writer::start_div('vl-customhtml-preview card');
    echo html_writer::div(
        html_writer::tag('h3', get_string('customhtml_preview', 'mod_visuallearning'), ['class' => 'h5 mb-1']) .
        html_writer::tag('p', get_string('customhtml_preview_desc', 'mod_visuallearning'), ['class' => 'text-muted mb-2']) .
        html_writer::div($tests, 'vl-customhtml-tests'),
        'card-body pb-0'
    );
    echo html_writer::div('', 'vl-customhtml-stage', ['data-url' => visuallearning_file_url($current),
        'data-title' => s($page->alttext)]);
    echo html_writer::div(
        visuallearning_button(
            new moodle_url($url, ['action' => 'remove', 'sesskey' => sesskey()]),
            get_string('customhtml_remove', 'mod_visuallearning'),
            'post',
            false,
            get_string('customhtml_remove_confirm', 'mod_visuallearning')
        ) .
        html_writer::link($back, get_string('customhtml_done', 'mod_visuallearning'), ['class' => 'btn btn-primary']),
        'card-body vl-customhtml-actions'
    );
    echo html_writer::end_div();
}

echo html_writer::tag(
    'details',
    html_writer::tag('summary', get_string('customhtml_prompt_heading', 'mod_visuallearning')) .
    html_writer::tag('p', get_string('customhtml_prompt_desc', 'mod_visuallearning')) .
    html_writer::tag('textarea', s($prompt), ['id' => 'vl-customhtml-prompt', 'class' => 'form-control vl-code', 'rows' => 14,
        'readonly' => 'readonly']) .
    html_writer::tag(
        'button',
        get_string('customhtml_prompt_copy', 'mod_visuallearning'),
        ['type' => 'button', 'class' => 'btn btn-secondary mt-2', 'data-vl-copy' => 'vl-customhtml-prompt',
        'data-done' => get_string(
            'customhtml_prompt_copied',
            'mod_visuallearning'
        )]
    ),
    ['class' => 'vl-customhtml-prompt card card-body', 'open' => $current ? null : 'open']
);

$form->display();
echo html_writer::end_div();
$PAGE->requires->js_call_amd('mod_visuallearning/htmlpreview', 'init');
echo $OUTPUT->footer();
