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
 * Teacher workflow: prompt file, pages (imported or written by LMS Labs AI), illustrations and voiceover through
 * LMS Labs (each priced and confirmed first), page editing and the LMS Labs requests that need attention.
 *
 * @package    mod_visuallearning
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use mod_visuallearning\local\ai\lmslabs;
use mod_visuallearning\local\ai\requests;
use mod_visuallearning\local\importer;
use mod_visuallearning\local\media;
use mod_visuallearning\local\prompt_builder;
use mod_visuallearning\local\unlock;
use mod_visuallearning\local\voices;

$id = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$pageid = optional_param('pageid', 0, PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'visuallearning');
$book = $DB->get_record('visuallearning', ['id' => $cm->instance], '*', MUST_EXIST);
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/visuallearning:manage', $context);
$canai = has_capability('mod/visuallearning:useai', $context);

$baseurl = new moodle_url('/mod/visuallearning/manage.php', ['id' => $cm->id]);
$PAGE->set_url($baseurl);
$PAGE->set_title(format_string($book->name) . ': ' . get_string('managepages', 'mod_visuallearning'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_activity_record($book);
$PAGE->activityheader->disable();

// Setting up books needs this site to be unlocked with LMS Labs. Learners keep the books already made.
if (!unlock::active()) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(format_string($book->name) . ' — ' . get_string('managepages', 'mod_visuallearning'));
    echo unlock::locked_notice(true);
    echo $OUTPUT->footer();
    exit;
}

// Download the ChatGPT prompt file (free).
if ($action === 'downloadprompt') {
    $prompt = prompt_builder::chatgpt_prompt($book);
    $filename = clean_filename('visuallearning-prompt-' . core_text::strtolower(format_string($book->name)) . '.txt');
    $filename = str_replace(' ', '-', $filename);
    send_file($prompt, $filename, 0, 0, true, true, 'text/plain; charset=utf-8');
    die;
}

// Paid actions: price first (free), then the teacher confirms.
if (in_array($action, ['quote', 'writequote', 'confirm', 'cancelquote', 'check', 'dismiss'], true)) {
    require_sesskey();
    require_capability('mod/visuallearning:useai', $context);
    if ($action === 'quote') {
        $what = optional_param('what', 'missing', PARAM_ALPHA);
        $kinds = array_intersect(
            explode(',', optional_param('kinds', 'image,cover,voice', PARAM_TEXT)),
            ['image', 'cover', 'voice']
        );
        $plans = media::plan($book, $context, $what === 'all' ? 'all' : 'missing', $pageid, array_values($kinds));
        \mod_visuallearning\local\quote_page::show(requests::quote($book, (int)$USER->id, $plans), $baseurl);
    }
    if ($action === 'writequote') {
        $summary = requests::quote($book, (int)$USER->id, [['operation' => requests::WRITE]]);
        \mod_visuallearning\local\quote_page::show($summary, $baseurl);
    }
    if ($action === 'cancelquote') {
        requests::cancel_quote((int)$book->id, required_param('batch', PARAM_ALPHANUMEXT));
        redirect($baseurl, get_string('quote_cancelled', 'mod_visuallearning'));
    }
    if ($action === 'confirm') {
        $rows = requests::confirm(
            $book,
            required_param('batch', PARAM_ALPHANUMEXT),
            (int)$USER->id,
            required_param('credits', PARAM_INT)
        );
        media::refresh($book, $context);
        if (count($rows) === 1 && $rows[0]->operation === requests::IMPORT) {
            $row = $rows[0];
            $type = $row->status === 'completed' ? \core\output\notification::NOTIFY_SUCCESS
                : \core\output\notification::NOTIFY_WARNING;
            redirect($baseurl, requests::export($row, $context)['message'], null, $type);
        }
        redirect(
            $baseurl,
            get_string('confirmed', 'mod_visuallearning', count($rows)),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    $row = requests::get((int)$book->id, required_param('requestid', PARAM_INT));
    if ($action === 'check') {
        $row = requests::check($row, $book, (int)$USER->id);
        redirect($baseurl, requests::export($row, $context)['message']);
    }
    requests::dismiss($row);
    media::refresh($book, $context);
    redirect($baseurl);
}

// Free page actions.
if (in_array($action, ['delete', 'moveup', 'movedown'], true)) {
    require_sesskey();
    $msg = '';
    if ($action === 'delete') {
        $page = $DB->get_record('visuallearning_pages', ['id' => $pageid, 'visuallearningid' => $book->id], '*', MUST_EXIST);
        $fs = get_file_storage();
        $fs->delete_area_files($context->id, 'mod_visuallearning', 'image', $page->id);
        $fs->delete_area_files($context->id, 'mod_visuallearning', 'audio', $page->id);
        $DB->delete_records('visuallearning_progress', ['pageid' => $page->id]);
        $DB->delete_records('visuallearning_pages', ['id' => $page->id]);
        visuallearning_renumber_pages($book->id);
        $msg = get_string('pagedeleted', 'mod_visuallearning');
    } else {
        $pages = array_values(visuallearning_get_pages($book->id));
        foreach ($pages as $i => $p) {
            if ((int)$p->id === $pageid) {
                $j = $action === 'moveup' ? $i - 1 : $i + 1;
                if (isset($pages[$j])) {
                    $DB->set_field('visuallearning_pages', 'pagenum', $pages[$j]->pagenum, ['id' => $p->id]);
                    $DB->set_field('visuallearning_pages', 'pagenum', $p->pagenum, ['id' => $pages[$j]->id]);
                }
                break;
            }
        }
        visuallearning_renumber_pages($book->id);
    }
    // Moving pages changes recap numbers, so some recap clips may need making again.
    media::refresh($book, $context);
    \course_modinfo::purge_course_module_cache($course->id, $cm->id);
    rebuild_course_cache($course->id, true);
    redirect($baseurl, $msg, null, $msg ? \core\output\notification::NOTIFY_SUCCESS : null);
}

// Edit / add a page.
if ($action === 'edit' || $action === 'add') {
    $page = null;
    if ($action === 'edit') {
        $page = $DB->get_record('visuallearning_pages', ['id' => $pageid, 'visuallearningid' => $book->id], '*', MUST_EXIST);
    }
    $count = $DB->count_records('visuallearning_pages', ['visuallearningid' => $book->id]);
    if ($action === 'add' && $count >= 20) {
        redirect($baseurl, get_string('maxpagesreached', 'mod_visuallearning'), null, \core\output\notification::NOTIFY_ERROR);
    }
    $form = new \mod_visuallearning\form\page_form(
        new moodle_url($baseurl, ['action' => $action, 'pageid' => $pageid]),
        ['cmid' => $cm->id]
    );
    if ($form->is_cancelled()) {
        redirect($baseurl);
    }
    if ($data = $form->get_data()) {
        $record = (object)[
            'title' => $data->title,
            'concepttext' => $data->concepttext,
            'actiontext' => $data->actiontext,
            'outcometext' => $data->outcometext,
            'imageprompt' => $data->imageprompt,
            'alttext' => $data->alttext,
            'summarytext' => $data->summarytext,
            'tfstatement' => $data->tfstatement,
            'tfanswer' => (int)$data->tfanswer,
            'timemodified' => time(),
        ];
        if ($page) {
            $record->id = $page->id;
            $DB->update_record('visuallearning_pages', $record);
        } else {
            $record->visuallearningid = $book->id;
            $record->pagenum = $count + 1;
            $record->imagestatus = VISUALLEARNING_MEDIA_PENDING;
            $record->audiostatus = VISUALLEARNING_MEDIA_PENDING;
            $DB->insert_record('visuallearning_pages', $record);
        }
        // Edited text or prompts need new media, made only when the teacher confirms its price (step 3).
        media::refresh($book, $context);
        redirect($baseurl, get_string('pagesaved', 'mod_visuallearning'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    if ($page) {
        $form->set_data(
            [
                'pageid' => $page->id, 'title' => $page->title, 'concepttext' => $page->concepttext,
                'actiontext' => $page->actiontext, 'outcometext' => $page->outcometext,
                'imageprompt' => $page->imageprompt, 'alttext' => $page->alttext,
                'summarytext' => $page->summarytext, 'tfstatement' => $page->tfstatement, 'tfanswer' => $page->tfanswer,
            ],
        );
    }
    echo $OUTPUT->header();
    echo $OUTPUT->heading(
        $page ? get_string('editpage', 'mod_visuallearning', $page->pagenum)
            : get_string('addpage', 'mod_visuallearning'),
    );
    $form->display();
    echo $OUTPUT->footer();
    exit;
}


// Pages written with the teacher's own AI assistant: checked and shown free, created once the charge is confirmed.
$pages = visuallearning_get_pages($book->id);
$importform = new \mod_visuallearning\form\import_form(
    $baseurl,
    [
        'cmid' => $cm->id,
        'haspages' => !empty($pages),
        'maxpages' => (int)$book->maxpages,
    ],
);
if ($data = $importform->get_data()) {
    require_capability('mod/visuallearning:useai', $context);
    [$parsed, , $warnings] = importer::parse($data->response, (int)$book->maxpages);
    if ($parsed) {
        $titles = array_map(fn($p) => (string)$p['title'], $parsed['pages']);
        $count = count($parsed['pages']);
        $plan = [
            'operation' => requests::IMPORT,
            'body' => ['pageCount' => $count, 'titles' => $titles, 'maxCredits' => $count * lmslabs::PAGE_CREDITS],
        ];
        $summary = requests::quote(
            $book,
            (int)$USER->id,
            [$plan],
            json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        $intro = html_writer::tag('p', get_string('import_preview', 'mod_visuallearning', $count));
        $intro .= html_writer::alist(array_map('s', $titles), [], 'ol');
        foreach ($warnings as $warning) {
            $intro .= $OUTPUT->notification(s($warning), \core\output\notification::NOTIFY_INFO);
        }
        \mod_visuallearning\local\quote_page::show($summary, $baseurl, $intro);
    }
}

// Overview.
media::refresh($book, $context);
$book = $DB->get_record('visuallearning', ['id' => $book->id], '*', MUST_EXIST);
$pages = visuallearning_get_pages($book->id);
$open = requests::open((int)$book->id);
$inprogress = count(array_filter($open, fn($r) => $r->status === 'pending'));
// Refresh while LMS Labs requests run in the background.
$PAGE->requires->js_call_amd('mod_visuallearning/manage', 'init', [$inprogress ? 15 : 0]);

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($book->name) . ' — ' . get_string('managepages', 'mod_visuallearning'));

$holds = [];
foreach (['page' => 'write', 'import' => 'import', 'image' => 'image', 'voice' => 'voice'] as $op => $name) {
    if (($hold = lmslabs::hold($op)) !== null) {
        $holds[$hold][] = get_string('holdop_' . $name, 'mod_visuallearning');
    }
}
foreach ($holds as $hold => $names) {
    $msg = get_string('hold_' . $hold, 'mod_visuallearning') . ' (' . implode(', ', $names) . ')';
    if (in_array($hold, ['nocredentials', 'off'], true) && has_capability('moodle/site:config', context_system::instance())) {
        $msg .= ' ' . html_writer::link(
            new moodle_url('/admin/settings.php', ['section' => 'modsettingvisuallearning']),
            get_string('configure', 'mod_visuallearning')
        );
    }
    echo $OUTPUT->notification($msg, \core\output\notification::NOTIFY_WARNING);
}
if (!$canai) {
    echo $OUTPUT->notification(get_string('nouseai', 'mod_visuallearning'), \core\output\notification::NOTIFY_INFO);
}

// LMS Labs requests that need attention.
if ($open) {
    echo $OUTPUT->heading(get_string('aireq_heading', 'mod_visuallearning'), 3);
    echo html_writer::start_div('pb-requests mb-4');
    foreach ($open as $row) {
        $item = requests::export($row, $context);
        $buttons = '';
        if ($canai && $item['canrecheck'] && $row->status !== 'pending') {
            $buttons .= visuallearning_button(
                new moodle_url($baseurl, ['action' => 'check', 'requestid' => $row->id, 'sesskey' => sesskey()]),
                get_string('aireq_checkagain', 'mod_visuallearning'),
                'post'
            );
        }
        if ($canai && $item['candismiss']) {
            $buttons .= visuallearning_button(
                new moodle_url($baseurl, ['action' => 'dismiss', 'requestid' => $row->id, 'sesskey' => sesskey()]),
                get_string('aireq_dismiss', 'mod_visuallearning'),
                'post',
                false,
                in_array($row->status, requests::CHECKABLE, true) ? get_string('aireq_dismissconfirm', 'mod_visuallearning') : ''
            );
        }
        $class = in_array($row->status, ['pending'], true) ? 'alert-info' : 'alert-warning';
        echo html_writer::div(
            html_writer::tag('strong', s($item['title'])) . ' ' . html_writer::span(s($item['time']), 'text-muted small') .
            html_writer::div(s($item['message']), 'small') .
            ($buttons !== '' ? html_writer::div($buttons, 'pb-manage-actions mt-1') : ''),
            "alert $class py-2"
        );
    }
    echo html_writer::end_div();
}

$summary = [
    get_string('industry', 'mod_visuallearning') . ': ' . s($book->industry ?: '—'),
    get_string('subindustry', 'mod_visuallearning') . ': ' . s($book->subindustry ?: '—'),
    get_string('language', 'mod_visuallearning') . ': ' . s(voices::language_name($book->language)),
    get_string('voice', 'mod_visuallearning') . ': ' . s($book->voice),
    get_string('maxpages', 'mod_visuallearning') . ': ' . (int)$book->maxpages,
];
echo html_writer::tag(
    'p',
    implode(' &nbsp;·&nbsp; ', $summary) . ' &nbsp; ' .
        html_writer::link(
            new moodle_url('/course/modedit.php', ['update' => $cm->id, 'return' => 1]),
            get_string('editsettings', 'mod_visuallearning')
        ),
    ['class' => 'text-muted'],
);

echo html_writer::start_div('pb-manage-steps');

// Step 1: write the pages - LMS Labs AI, or the teacher's own AI assistant (same price per page).
echo html_writer::start_div('pb-step');
echo html_writer::tag('h4', html_writer::span('1', 'pb-step-num') . get_string('step1', 'mod_visuallearning'));
echo html_writer::tag('p', get_string('step1_desc', 'mod_visuallearning', lmslabs::PAGE_CREDITS));
if ($canai && lmslabs::hold('page') === null) {
    echo visuallearning_button(
        new moodle_url($baseurl, ['action' => 'writequote', 'sesskey' => sesskey()]),
        get_string('writewithai', 'mod_visuallearning'),
        'post',
        true,
        $pages ? get_string('writewithai_replace', 'mod_visuallearning') : ''
    );
}
echo visuallearning_button(
    new moodle_url($baseurl, ['action' => 'downloadprompt']),
    get_string('downloadprompt', 'mod_visuallearning'),
    'get'
);
$prompttext = prompt_builder::chatgpt_prompt($book);
echo html_writer::tag(
    'details',
    html_writer::tag('summary', get_string('previewprompt', 'mod_visuallearning')) .
    html_writer::tag(
        'textarea',
        s($prompttext),
        ['class' => 'pb-prompt-preview form-control mt-2', 'rows' => 12, 'readonly' => 'readonly'],
    ),
    ['class' => 'mt-2']
);
echo html_writer::end_div();

// Step 2: paste the assistant's reply.
echo html_writer::start_div('pb-step');
echo html_writer::tag('h4', html_writer::span('2', 'pb-step-num') . get_string('step2', 'mod_visuallearning'));
echo html_writer::tag('p', get_string('step2_desc', 'mod_visuallearning', lmslabs::PAGE_CREDITS));
if ($canai) {
    $importform->display();
}
echo html_writer::end_div();

// Step 3: illustrations and voiceover.
echo html_writer::start_div('pb-step');
echo html_writer::tag('h4', html_writer::span('3', 'pb-step-num') . get_string('step3', 'mod_visuallearning'));
echo html_writer::tag(
    'p',
    get_string('step3_desc', 'mod_visuallearning', (object)['image' => lmslabs::IMAGE_CREDITS, 'voice' => lmslabs::VOICE_CREDITS])
);
if ($pages) {
    $ready = ['image' => 0, 'audio' => 0];
    foreach ($pages as $p) {
        $ready['image'] += (int)$p->imagestatus === VISUALLEARNING_MEDIA_READY ? 1 : 0;
        $ready['audio'] += (int)$p->audiostatus === VISUALLEARNING_MEDIA_READY ? 1 : 0;
    }
    $status = (object)['images' => $ready['image'], 'audio' => $ready['audio'], 'total' => count($pages),
        'queued' => $inprogress];
    echo html_writer::tag('p', get_string('mediastatus', 'mod_visuallearning', $status), ['class' => 'font-weight-bold fw-bold']);
    if ($inprogress) {
        echo html_writer::tag('p', get_string('generating', 'mod_visuallearning'), ['class' => 'text-muted small']);
    }
    if ($canai) {
        echo visuallearning_button(
            new moodle_url($baseurl, ['action' => 'quote', 'what' => 'missing', 'sesskey' => sesskey()]),
            get_string('generatemissing', 'mod_visuallearning'),
            'post',
            true
        );
        echo visuallearning_button(
            new moodle_url($baseurl, ['action' => 'quote', 'what' => 'all', 'sesskey' => sesskey()]),
            get_string('regenerateall', 'mod_visuallearning'),
            'post'
        );
    }
    echo html_writer::div(
        visuallearning_button(
            new moodle_url('/mod/visuallearning/view.php', ['id' => $cm->id]),
            get_string('previewbook', 'mod_visuallearning'),
            'get'
        ),
        'mt-2',
    );
} else {
    echo html_writer::tag('p', get_string('nopagesyet', 'mod_visuallearning'), ['class' => 'text-muted']);
}
echo html_writer::end_div();

echo html_writer::end_div();

// Cover.
if (trim((string)$book->coverprompt) !== '') {
    $cover = media::image_file($context, media::AREA_COVER, 0);
    echo $OUTPUT->heading(get_string('cover', 'mod_visuallearning'), 3);
    echo html_writer::start_div('pb-manage-pages mb-4');
    echo html_writer::start_div('pb-manage-page');
    echo html_writer::div(
        $cover ? html_writer::empty_tag('img', ['src' => visuallearning_file_url($cover), 'alt' => ''])
            : visuallearning_status_label((int)$book->coverstatus),
        'pb-manage-thumb',
    );
    echo html_writer::start_div('pb-manage-body');
    echo html_writer::tag('p', s($book->coverprompt), ['class' => 'small text-muted']);
    echo html_writer::tag(
        'p',
        get_string('image', 'mod_visuallearning') . ': ' . visuallearning_status_label((int)$book->coverstatus),
        ['class' => 'small']
    );
    if ($canai) {
        echo html_writer::div(
            visuallearning_button(
                new moodle_url($baseurl, ['action' => 'quote', 'what' => 'all', 'kinds' => 'cover', 'sesskey' => sesskey()]),
                get_string('regenimage', 'mod_visuallearning'),
                'post'
            ),
            'pb-manage-actions',
        );
    }
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::end_div();
}

// Pages.
echo $OUTPUT->heading(get_string('pages', 'mod_visuallearning') . ' (' . count($pages) . ')', 3);
echo html_writer::start_div('pb-manage-pages');
$total = count($pages);
$position = 0;
foreach ($pages as $page) {
    $clips = media::clips($book, $page, $position++);
    $image = media::image_file($context, media::AREA_IMAGE, (int)$page->id);
    echo html_writer::start_div('pb-manage-page');
    echo html_writer::div(
        $image ? html_writer::empty_tag('img', ['src' => visuallearning_file_url($image), 'alt' => s($page->alttext)])
            : visuallearning_status_label((int)$page->imagestatus),
        'pb-manage-thumb',
    );
    echo html_writer::start_div('pb-manage-body');
    echo html_writer::tag('strong', (int)$page->pagenum . '. ' . format_string($page->title));
    foreach (VISUALLEARNING_CARDS as $card) {
        echo html_writer::tag(
            'p',
            html_writer::span(get_string('card_' . $card, 'mod_visuallearning'), 'pb-tag pb-tag-' . $card) .
                s($page->{$card . 'text'}),
        );
    }
    if (trim((string)$page->summarytext) !== '') {
        echo html_writer::tag(
            'p',
            html_writer::span(get_string('summarytext', 'mod_visuallearning'), 'pb-tag') . s($page->summarytext),
            ['class' => 'text-muted'],
        );
    }
    if (trim((string)$page->tfstatement) !== '') {
        $answer = get_string($page->tfanswer ? 'true' : 'false', 'mod_visuallearning');
        echo html_writer::tag(
            'p',
            html_writer::span(get_string('tfstatement', 'mod_visuallearning'), 'pb-tag') .
                s($page->tfstatement) . ' ' . html_writer::tag('strong', '(' . $answer . ')'),
            ['class' => 'text-muted'],
        );
    }
    echo html_writer::tag(
        'p',
        get_string('image', 'mod_visuallearning') . ': ' . visuallearning_status_label((int)$page->imagestatus) .
            ' &nbsp;·&nbsp; ' .
            get_string('voiceover', 'mod_visuallearning') . ': ' . visuallearning_status_label((int)$page->audiostatus),
        ['class' => 'small'],
    );
    $players = '';
    foreach ($clips as $part => $text) {
        $audio = media::clip_file($context, $book, $page, $part, $text);
        if ($audio) {
            $players .= html_writer::tag(
                'audio',
                '',
                ['controls' => 'controls', 'preload' => 'none', 'src' => visuallearning_file_url($audio),
                    'style' => 'width:100%;height:32px', 'title' => get_string('clip_' . $part, 'mod_visuallearning')],
            );
        }
    }
    if ($players !== '') {
        echo html_writer::tag(
            'details',
            html_writer::tag('summary', get_string('listen', 'mod_visuallearning'), ['class' => 'small']) . $players,
        );
    }
    $actions = [];
    $actions[] = visuallearning_button(
        new moodle_url($baseurl, ['action' => 'edit', 'pageid' => $page->id]),
        get_string('edit'),
        'get'
    );
    if ($canai) {
        $actions[] = visuallearning_button(
            new moodle_url(
                $baseurl,
                ['action' => 'quote', 'what' => 'all', 'kinds' => 'image', 'pageid' => $page->id, 'sesskey' => sesskey()],
            ),
            get_string('regenimage', 'mod_visuallearning'),
            'post',
        );
        $actions[] = visuallearning_button(
            new moodle_url(
                $baseurl,
                ['action' => 'quote', 'what' => 'all', 'kinds' => 'voice', 'pageid' => $page->id, 'sesskey' => sesskey()],
            ),
            get_string('regenaudio', 'mod_visuallearning'),
            'post',
        );
    }
    if ((int)$page->pagenum > 1) {
        $actions[] = visuallearning_button(
            new moodle_url($baseurl, ['action' => 'moveup', 'pageid' => $page->id, 'sesskey' => sesskey()]),
            '↑',
            'post',
        );
    }
    if ((int)$page->pagenum < $total) {
        $actions[] = visuallearning_button(
            new moodle_url($baseurl, ['action' => 'movedown', 'pageid' => $page->id, 'sesskey' => sesskey()]),
            '↓',
            'post',
        );
    }
    $actions[] = visuallearning_button(
        new moodle_url($baseurl, ['action' => 'delete', 'pageid' => $page->id, 'sesskey' => sesskey()]),
        get_string('delete'),
        'post',
        false,
        get_string('deletepage_confirm', 'mod_visuallearning'),
    );
    echo html_writer::div(implode('', $actions), 'pb-manage-actions');
    echo html_writer::end_div();
    echo html_writer::end_div();
}
echo html_writer::end_div();

if ($total < 20) {
    echo html_writer::div(
        visuallearning_button(
            new moodle_url($baseurl, ['action' => 'add']),
            get_string('addpage', 'mod_visuallearning'),
            'get'
        ),
        'mt-3',
    );
}

echo $OUTPUT->footer();
