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

// Setting up lessons needs this site to be unlocked with LMS Labs. Learners keep the lessons already made.
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

// The animated SVG prompt of one page, for the teacher's own AI assistant (free).
if ($action === 'svgprompt') {
    $page = $DB->get_record('visuallearning_pages', ['id' => $pageid, 'visuallearningid' => $book->id], '*', MUST_EXIST);
    $text = prompt_builder::svg_system() . "\n\n==========\nTHE PAGE TO DRAW\n==========\n\n"
        . prompt_builder::svg_prompt($book, $page);
    $filename = 'visuallearning-svg-page-' . (int)$page->pagenum . '.txt';
    send_file($text, $filename, 0, 0, true, true, 'text/plain; charset=utf-8');
    die;
}

// Paid actions: price first (free), then the teacher confirms.
if (in_array($action, ['quote', 'writequote', 'confirm', 'cancelquote', 'check', 'dismiss'], true)) {
    require_sesskey();
    require_capability('mod/visuallearning:useai', $context);
    if ($action === 'quote') {
        $what = optional_param('what', 'missing', PARAM_ALPHA);
        $kinds = array_intersect(
            explode(',', optional_param('kinds', 'image,option,cover,voice,card,animation', PARAM_TEXT)),
            ['image', 'option', 'cover', 'voice', 'card']
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
            $next = new moodle_url($baseurl, ['step' => $row->status === 'completed' ? 2 : 1]);
            redirect($next, requests::export($row, $context)['message'], null, $type);
        }
        $written = count($rows) === 1 && $rows[0]->operation === requests::WRITE;
        redirect(
            new moodle_url($baseurl, ['step' => $written ? 1 : 3]),
            get_string('confirmed', 'mod_visuallearning', count($rows)),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    $row = requests::get((int)$book->id, required_param('requestid', PARAM_INT));
    if ($action === 'check') {
        $row = requests::check($row, $book, (int)$USER->id);
        redirect(new moodle_url($baseurl, ['step' => 3]), requests::export($row, $context)['message']);
    }
    requests::dismiss($row);
    media::refresh($book, $context);
    redirect(new moodle_url($baseurl, ['step' => 3]));
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
        $fs->delete_area_files($context->id, 'mod_visuallearning', 'option', $page->id);
        $fs->delete_area_files($context->id, 'mod_visuallearning', 'card', $page->id);
        $fs->delete_area_files($context->id, 'mod_visuallearning', 'svg', $page->id);
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
    if ($action === 'delete') {
        // One question fewer: grades are out of the questions that are left.
        visuallearning_update_grades($book);
    }
    \course_modinfo::purge_course_module_cache($course->id, $cm->id);
    rebuild_course_cache($course->id, true);
    redirect(new moodle_url($baseurl, ['step' => 2]), $msg, null, $msg ? \core\output\notification::NOTIFY_SUCCESS : null);
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
        ['cmid' => $cm->id, 'approach' => $book->teachingapproach ?? null, 'picturemode' => $book->picturemode ?? null,
            'svgprompturl' => $page ? new moodle_url($baseurl, ['action' => 'svgprompt', 'pageid' => $page->id]) : null]
    );
    if ($form->is_cancelled()) {
        redirect(new moodle_url($baseurl, ['step' => 2]));
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
            'timemodified' => time(),
        ];
        // Card picture prompts and the diagram are only on the form in their picture mode; elsewhere they are kept.
        if (property_exists($data, 'cardprompt1')) {
            $cards = importer::card_prompts([$data->cardprompt1, $data->cardprompt2 ?? '', $data->cardprompt3 ?? '']);
            $record->cardprompts = $cards ? json_encode($cards, JSON_UNESCAPED_UNICODE) : null;
        }
        if (property_exists($data, 'diagram')) {
            $record->diagram = \mod_visuallearning\local\diagram::json($data->diagram);
        }
        $fields = \mod_visuallearning\local\question::to_fields(\mod_visuallearning\local\question::from_form($data));
        foreach ($fields as $field => $value) {
            $record->$field = $value;
        }
        if ($page) {
            if (!empty($page->bundleid) && ($record->qtype ?? 'none') !== ($page->qtype ?? 'none')) {
                throw new moodle_exception('bundlequestiontype', 'mod_visuallearning');
            }
            $record->id = $page->id;
            $DB->update_record('visuallearning_pages', $record);
            if (trim((string)($data->animsvg ?? '')) !== '') {
                // An animated scene from the teacher's own AI assistant: saved as made from this page's brief (free).
                $saved = $DB->get_record('visuallearning_pages', ['id' => $page->id], '*', MUST_EXIST);
                media::save_svg($context, (int)$page->id, media::svg_prompt($book, $saved), (string)$data->animsvg);
            }
        } else {
            require_capability('mod/visuallearning:useai', $context);
            $parsed = ['pages' => [[
                'title' => $record->title, 'concept' => $record->concepttext, 'action' => $record->actiontext,
                'outcome' => $record->outcometext, 'image_prompt' => $record->imageprompt,
                'alt_text' => $record->alttext, 'summary' => $record->summarytext,
                'question' => \mod_visuallearning\local\question::from_form($data),
                'card_image_prompts' => json_decode((string)($record->cardprompts ?? ''), true) ?: [],
                'diagram' => \mod_visuallearning\local\diagram::clean($record->diagram ?? null),
            ]]];
            $summary = requests::quote($book, (int)$USER->id, [[
                'operation' => requests::IMPORT,
                'body' => ['_manual' => true, 'pageCount' => 1, 'titles' => [$record->title],
                    'maxCredits' => lmslabs::PAGE_CREDITS],
            ]], json_encode($parsed));
            \mod_visuallearning\local\quote_page::show($summary, $baseurl);
            exit;
        }
        // Edited text or prompts need new media, made only when the teacher confirms its price (step 3).
        media::refresh($book, $context);
        // A question added to or removed from the page changes what a full grade needs.
        visuallearning_update_grades($book);
        redirect(
            new moodle_url($baseurl, ['step' => 2]),
            get_string('pagesaved', 'mod_visuallearning'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    if ($page) {
        $form->set_data(
            [
                'pageid' => $page->id, 'title' => $page->title, 'concepttext' => $page->concepttext,
                'actiontext' => $page->actiontext, 'outcometext' => $page->outcometext,
                'imageprompt' => $page->imageprompt, 'alttext' => $page->alttext,
                'summarytext' => $page->summarytext,
                'cardprompt1' => \mod_visuallearning\local\picture_modes::card_prompts($page)[0],
                'cardprompt2' => \mod_visuallearning\local\picture_modes::card_prompts($page)[1],
                'cardprompt3' => \mod_visuallearning\local\picture_modes::card_prompts($page)[2],
                'diagram' => (string)($page->diagram ?? '') !== ''
                    ? json_encode(json_decode($page->diagram), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '',
            ] + \mod_visuallearning\local\question::to_form($page),
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

// The lesson is built in four steps, shown one at a time like slides.
media::refresh($book, $context);
$book = $DB->get_record('visuallearning', ['id' => $book->id], '*', MUST_EXIST);
$pages = visuallearning_get_pages($book->id);
$open = requests::open((int)$book->id);
$inprogress = count(array_filter($open, fn($r) => $r->status === 'pending'));
$total = count($pages);
$ready = ['image' => 0, 'audio' => 0];
foreach ($pages as $p) {
    $ready['image'] += (int)$p->imagestatus === VISUALLEARNING_MEDIA_READY ? 1 : 0;
    $ready['audio'] += (int)$p->audiostatus === VISUALLEARNING_MEDIA_READY ? 1 : 0;
}
$hascover = trim((string)$book->coverprompt) !== '';
$coverready = !$hascover || (int)$book->coverstatus === VISUALLEARNING_MEDIA_READY;
$mediadone = $total > 0 && $ready['image'] === $total && $ready['audio'] === $total && $coverready;
$mediastarted = $ready['image'] + $ready['audio'] > 0 || $open;
$done = [1 => $total > 0, 2 => $total > 0 && ($mediastarted || $mediadone), 3 => $mediadone, 4 => false];

// Open on the step the action belongs to, or on the first step still to do.
$step = optional_param('step', 0, PARAM_INT);
if ($step < 1 || $step > 4) {
    $step = !$total ? 1 : ($mediadone ? 4 : ($mediastarted ? 3 : 2));
}
// Refresh while LMS Labs requests run in the background.
$PAGE->requires->js_call_amd('mod_visuallearning/manage', 'init', [$inprogress ? 15 : 0, $step]);

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($book->name) . ' — ' . get_string('managepages', 'mod_visuallearning'));

$summary = [
    get_string('industry', 'mod_visuallearning') . ': ' . s($book->industry ?: '—'),
    get_string('subindustry', 'mod_visuallearning') . ': ' . s($book->subindustry ?: '—'),
    get_string('language', 'mod_visuallearning') . ': ' . s(voices::language_name($book->language)),
    get_string('voice', 'mod_visuallearning') . ': ' . s($book->voice),
    get_string('teachingapproach', 'mod_visuallearning') . ': '
        . \mod_visuallearning\local\approaches::options()[\mod_visuallearning\local\approaches::of($book)->key()],
    get_string('visualstyle', 'mod_visuallearning') . ': '
        . \mod_visuallearning\local\visual_styles::options()[\mod_visuallearning\local\visual_styles::of($book)],
    get_string('picturemode', 'mod_visuallearning') . ': '
        . get_string('picturemode_' . \mod_visuallearning\local\picture_modes::of($book) . '_short', 'mod_visuallearning'),
    get_string('learnerlevel', 'mod_visuallearning') . ': '
        . \mod_visuallearning\local\learner_levels::options()[\mod_visuallearning\local\learner_levels::of($book)],
    get_string('maxpages', 'mod_visuallearning') . ': ' . (int)$book->maxpages,
];
echo html_writer::tag(
    'p',
    implode(' &nbsp;·&nbsp; ', $summary) . ' &nbsp; ' .
        html_writer::link(
            new moodle_url('/course/modedit.php', ['update' => $cm->id, 'return' => 1]),
            get_string('editsettings', 'mod_visuallearning')
        ),
    ['class' => 'text-muted small'],
);
if (!$canai) {
    echo $OUTPUT->notification(get_string('nouseai', 'mod_visuallearning'), \core\output\notification::NOTIFY_INFO);
}

// Notices for the paid operations of one step that cannot run now (they fail closed).
$holdnotice = function (array $ops): string {
    global $OUTPUT;
    $holds = [];
    foreach ($ops as $op => $name) {
        if (($hold = lmslabs::hold($op)) !== null) {
            $holds[$hold][] = get_string('holdop_' . $name, 'mod_visuallearning');
        }
    }
    $html = '';
    foreach ($holds as $hold => $names) {
        $msg = get_string('hold_' . $hold, 'mod_visuallearning') . ' (' . implode(', ', $names) . ')';
        if (in_array($hold, ['nocredentials', 'off'], true) && has_capability('moodle/site:config', context_system::instance())) {
            $msg .= ' ' . html_writer::link(
                new moodle_url('/admin/settings.php', ['section' => 'modsettingvisuallearning']),
                get_string('configure', 'mod_visuallearning')
            );
        }
        $html .= $OUTPUT->notification($msg, \core\output\notification::NOTIFY_WARNING);
    }
    return $html;
};

// Slide navigation: Back and Next.
$nav = function (int $n, bool $nextok, string $hint = ''): string {
    $back = $n > 1 ? html_writer::tag(
        'button',
        get_string('wizard_back', 'mod_visuallearning'),
        ['type' => 'button', 'class' => 'btn btn-secondary', 'data-goto' => $n - 1]
    ) : html_writer::span('');
    $next = '';
    if ($n < 4) {
        $attrs = ['type' => 'button', 'class' => 'btn btn-primary', 'data-goto' => $n + 1];
        if (!$nextok) {
            $attrs['disabled'] = 'disabled';
        }
        $next = html_writer::tag('button', get_string('wizard_next_' . ($n + 1), 'mod_visuallearning'), $attrs);
    }
    $hint = $hint !== '' ? html_writer::span($hint, 'vl-wizard-hint text-muted small') : '';
    return html_writer::div($back . html_writer::div($hint . $next, 'vl-wizard-next'), 'vl-wizard-nav');
};

echo html_writer::start_div('vl-wizard', ['data-step' => $step]);

// Step tracker.
$items = '';
for ($n = 1; $n <= 4; $n++) {
    $state = $done[$n] ? 'is-done' : '';
    $items .= html_writer::tag(
        'li',
        html_writer::tag(
            'button',
            html_writer::span($done[$n] ? '✓' : (string)$n, 'vl-wizard-dot') .
                html_writer::span(get_string('wizard_short_' . $n, 'mod_visuallearning'), 'vl-wizard-label'),
            ['type' => 'button', 'data-goto' => $n, 'class' => 'vl-wizard-tab ' . $state,
                'aria-current' => $n === $step ? 'step' : 'false']
        )
    );
}
$label = get_string('managepages', 'mod_visuallearning');
echo html_writer::tag('ol', $items, ['class' => 'vl-wizard-steps', 'aria-label' => $label]);

echo html_writer::start_div('vl-wizard-slides');

// Step 1: write the pages.
echo html_writer::start_tag('section', ['class' => 'vl-slide', 'data-slide' => 1, 'aria-labelledby' => 'vl-slide-1']);
echo html_writer::tag('h3', get_string('wizard_title_1', 'mod_visuallearning'), ['id' => 'vl-slide-1', 'tabindex' => '-1']);
$intro = get_string('wizard_intro_1', 'mod_visuallearning', lmslabs::PAGE_CREDITS);
echo html_writer::tag('p', $intro, ['class' => 'vl-slide-intro']);
if ($total) {
    echo $OUTPUT->notification(get_string('wizard_haspages', 'mod_visuallearning', $total), \core\output\notification::NOTIFY_INFO);
}
echo $holdnotice(['page' => 'write', 'import' => 'import']);
echo html_writer::start_div('vl-choices');
// Choice A: LMS Labs AI.
echo html_writer::start_div('vl-choice');
echo html_writer::tag('h4', get_string('wizard_choice_ai', 'mod_visuallearning'));
echo html_writer::tag('p', get_string('wizard_choice_ai_desc', 'mod_visuallearning'));
if ($canai && lmslabs::hold('page') === null) {
    echo visuallearning_button(
        new moodle_url($baseurl, ['action' => 'writequote', 'sesskey' => sesskey()]),
        get_string('writewithai', 'mod_visuallearning'),
        'post',
        true,
        $pages ? get_string('writewithai_replace', 'mod_visuallearning') : ''
    );
}
echo html_writer::end_div();
// Choice B: the teacher's own AI assistant.
echo html_writer::start_div('vl-choice');
echo html_writer::tag('h4', get_string('wizard_choice_own', 'mod_visuallearning'));
echo html_writer::start_tag('ol', ['class' => 'vl-mini-steps']);
echo html_writer::tag(
    'li',
    get_string('wizard_own_1', 'mod_visuallearning') . html_writer::div(
        visuallearning_button(
            new moodle_url($baseurl, ['action' => 'downloadprompt']),
            get_string('downloadprompt', 'mod_visuallearning'),
            'get'
        ) . html_writer::tag(
            'details',
            html_writer::tag('summary', get_string('previewprompt', 'mod_visuallearning')) .
            html_writer::tag(
                'textarea',
                s(prompt_builder::chatgpt_prompt($book)),
                ['class' => 'pb-prompt-preview form-control mt-2', 'rows' => 10, 'readonly' => 'readonly']
            ),
            ['class' => 'mt-1']
        ),
        'mt-1'
    )
);
echo html_writer::tag('li', get_string('wizard_own_2', 'mod_visuallearning'));
echo html_writer::start_tag('li');
echo get_string('wizard_own_3', 'mod_visuallearning', lmslabs::PAGE_CREDITS);
if ($canai) {
    $importform->display();
}
echo html_writer::end_tag('li');
echo html_writer::end_tag('ol');
echo html_writer::end_div();
echo html_writer::end_div();
echo $nav(1, $total > 0, $total ? '' : get_string('wizard_hint_1', 'mod_visuallearning'));
echo html_writer::end_tag('section');

// Step 2: check the pages.
echo html_writer::start_tag('section', ['class' => 'vl-slide', 'data-slide' => 2, 'aria-labelledby' => 'vl-slide-2']);
echo html_writer::tag('h3', get_string('wizard_title_2', 'mod_visuallearning'), ['id' => 'vl-slide-2', 'tabindex' => '-1']);
echo html_writer::tag('p', get_string('wizard_intro_2', 'mod_visuallearning', $total), ['class' => 'vl-slide-intro']);
echo html_writer::start_div('pb-manage-pages');
$position = 0;
foreach ($pages as $page) {
    $clips = media::clips($book, $page, $position++, count($pages));
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
            html_writer::span(\mod_visuallearning\local\approaches::card_label($book, $card), 'pb-tag pb-tag-' . $card) .
                s($page->{$card . 'text'}),
        );
    }
    if ($book->enablequiz) {
        echo \mod_visuallearning\local\question::teacher_summary($page, $context, $book);
    }
    echo html_writer::tag(
        'p',
        get_string('image', 'mod_visuallearning') . ': ' . visuallearning_status_label((int)$page->imagestatus) .
            ' &nbsp;·&nbsp; ' . get_string('voiceover', 'mod_visuallearning') . ': ' .
            visuallearning_status_label((int)$page->audiostatus),
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
echo $nav(2, $total > 0);
echo html_writer::end_tag('section');

// Step 3: pictures and voiceover.
echo html_writer::start_tag('section', ['class' => 'vl-slide', 'data-slide' => 3, 'aria-labelledby' => 'vl-slide-3']);
echo html_writer::tag('h3', get_string('wizard_title_3', 'mod_visuallearning'), ['id' => 'vl-slide-3', 'tabindex' => '-1']);
echo html_writer::tag('p', get_string('wizard_intro_3_short', 'mod_visuallearning'), ['class' => 'vl-slide-intro']);
echo html_writer::tag(
    'details',
    html_writer::tag('summary', get_string('wizard_pricing', 'mod_visuallearning')) .
    html_writer::tag(
        'p',
        get_string('wizard_intro_3', 'mod_visuallearning', (object)['image' => lmslabs::IMAGE_CREDITS,
            'voice' => lmslabs::VOICE_CREDITS]) .
            ($book->enablequiz && \mod_visuallearning\local\question::book_has_pick($pages) && $book->pickimages
                ? ' ' . get_string('wizard_intro_3_options', 'mod_visuallearning', lmslabs::IMAGE_CREDITS) : ''),
        ['class' => 'small text-muted mb-0 mt-2']
    ),
    ['class' => 'vl-pricing']
);
$optioncount = 0;
foreach ($pages as $p) {
    $optioncount += count(media::option_prompts($book, $p));
}
$sequence = \mod_visuallearning\local\picture_modes::of($book) === \mod_visuallearning\local\picture_modes::SEQUENCE;
$svgmode = \mod_visuallearning\local\picture_modes::of($book) === \mod_visuallearning\local\picture_modes::SVG;
echo $holdnotice(['image' => 'image'] + ($optioncount ? ['option' => 'option'] : []) + ($sequence ? ['card' => 'card'] : [])
    + ($svgmode ? ['animation' => 'animation'] : []) + ['voice' => 'voice']);
if ($svgmode) {
    echo html_writer::tag('p', get_string('svgmode_intro', 'mod_visuallearning', lmslabs::ANIMATION_CREDITS),
        ['class' => 'small text-muted']);
}

// Open requests, by page: what is being made, and what needs the teacher.
$pendingby = [];
$problems = [];
$pendingrows = [];
foreach ($open as $row) {
    if ($row->status === 'pending') {
        if (!in_array($row->operation, [requests::IMAGE, requests::COVER, requests::OPTION, requests::VOICE, requests::CARD,
                requests::ANIMATION], true)) {
            $pendingrows[] = $row;
            continue;
        }
        $kind = [requests::VOICE => 'audio', requests::CARD => 'card', requests::ANIMATION => 'animation'][$row->operation]
            ?? 'image';
        $pendingby[(int)$row->targetid][$kind] = ($pendingby[(int)$row->targetid][$kind] ?? 0) + 1;
        $pendingrows[] = $row;
    } else {
        $problems[] = $row;
    }
}
// One status chip: Ready / Being made (n) / Needs attention / Not made yet.
$chip = function (string $label, int $status, int $pending): string {
    $map = [
        VISUALLEARNING_MEDIA_READY => ['media_chip_ready', 'is-ready'],
        VISUALLEARNING_MEDIA_QUEUED => ['media_chip_making', 'is-making'],
        VISUALLEARNING_MEDIA_FAILED => ['media_chip_failed', 'is-failed'],
        VISUALLEARNING_MEDIA_PENDING => ['media_chip_todo', 'is-todo'],
    ];
    if ($pending && $status !== VISUALLEARNING_MEDIA_FAILED) {
        $status = VISUALLEARNING_MEDIA_QUEUED;
    }
    [$key, $class] = $map[$status] ?? $map[VISUALLEARNING_MEDIA_PENDING];
    $text = get_string($key, 'mod_visuallearning') . ($pending > 1 ? ' (' . $pending . ')' : '');
    return html_writer::span(
        html_writer::span($label, 'vl-chip-label') . html_writer::span($text, 'vl-chip-state'),
        'vl-chip ' . $class
    );
};
// Request rows (compact): title, status message and the buttons that apply.
$requestrow = function (stdClass $row) use ($context, $canai, $baseurl): string {
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
    return html_writer::tag(
        'li',
        html_writer::div(
            html_writer::div(s($item['title']), 'vl-req-title') .
            html_writer::div(s($item['message']) . ' · ' . s($item['time']), 'vl-req-meta'),
            'vl-req-text'
        ) . ($buttons !== '' ? html_writer::div($buttons, 'vl-req-actions') : ''),
        ['class' => 'vl-req is-' . s($row->status)]
    );
};

if ($total) {
    // Status panel: the counts, one bar, and the one thing to do next.
    $made = $ready['image'] + $ready['audio'] + ($hascover && $coverready ? 1 : 0);
    $needed = 2 * $total + ($hascover ? 1 : 0);
    $percent = (int)round(100 * $made / max(1, $needed));
    $tile = function (string $label, string $value, string $class = ''): string {
        return html_writer::div(
            html_writer::div($value, 'vl-stat-value') . html_writer::div($label, 'vl-stat-label'),
            'vl-stat ' . $class
        );
    };
    $tiles = $tile(get_string('media_pictures', 'mod_visuallearning'), $ready['image'] . '/' . $total,
        $ready['image'] === $total ? 'is-ready' : '');
    $tiles .= $tile(get_string('media_voiceover', 'mod_visuallearning'), $ready['audio'] . '/' . $total,
        $ready['audio'] === $total ? 'is-ready' : '');
    if ($hascover) {
        $tiles .= $tile(get_string('cover', 'mod_visuallearning'), get_string($coverready ? 'status_ready'
            : ((int)$book->coverstatus === VISUALLEARNING_MEDIA_QUEUED ? 'status_queued' : 'status_pending'),
            'mod_visuallearning'), $coverready ? 'is-ready' : '');
    }
    if ($inprogress) {
        $live = html_writer::span('', 'vl-spinner', ['aria-hidden' => 'true']) .
            get_string('media_making', 'mod_visuallearning', $inprogress);
        $liveclass = 'is-making';
    } else if ($mediadone) {
        $live = get_string('media_alldone', 'mod_visuallearning');
        $liveclass = 'is-ready';
    } else {
        $live = get_string('media_todo', 'mod_visuallearning', $needed - $made);
        $liveclass = 'is-todo';
    }
    $actions = '';
    if ($canai && !$mediadone) {
        $actions .= visuallearning_button(
            new moodle_url($baseurl, ['action' => 'quote', 'what' => 'missing', 'sesskey' => sesskey()]),
            get_string('generatemissing', 'mod_visuallearning'),
            'post',
            !$inprogress
        );
    }
    echo html_writer::div(
        html_writer::div($tiles, 'vl-stats') .
        html_writer::div(
            html_writer::div('', 'vl-meter-fill', ['style' => 'width:' . $percent . '%']),
            'vl-meter',
            ['role' => 'progressbar', 'aria-valuenow' => $percent, 'aria-valuemin' => 0, 'aria-valuemax' => 100]
        ) .
        html_writer::div(
            html_writer::div($live, 'vl-live ' . $liveclass, ['role' => 'status']) .
            ($actions !== '' ? html_writer::div($actions, 'vl-panel-actions') : ''),
            'vl-panel-foot'
        ),
        'vl-media-panel'
    );
}

// Only the requests that need the teacher are shown in full.
if ($problems) {
    echo html_writer::tag('h4', get_string('media_attention', 'mod_visuallearning', count($problems)), ['class' => 'vl-h4']);
    echo html_writer::tag('ul', implode('', array_map($requestrow, $problems)), ['class' => 'vl-req-list is-problems']);
}

// Every page, with what it has and what is being made.
if ($total) {
    echo html_writer::tag('h4', get_string('media_bypage', 'mod_visuallearning'), ['class' => 'vl-h4']);
    $cards = '';
    $remake = function (array $buttons): string {
        return html_writer::tag(
            'details',
            html_writer::tag('summary', get_string('media_remake', 'mod_visuallearning')) .
            html_writer::div(implode('', $buttons), 'vl-remake-buttons'),
            ['class' => 'vl-remake']
        );
    };
    $quoteurl = fn(string $kinds, int $pageid = 0) => new moodle_url($baseurl, ['action' => 'quote', 'what' => 'all',
        'kinds' => $kinds, 'pageid' => $pageid, 'sesskey' => sesskey()]);
    if ($hascover) {
        $cover = media::image_file($context, media::AREA_COVER, 0);
        $coverpending = $pendingby[0]['image'] ?? 0;
        $cards .= html_writer::div(
            html_writer::div(
                $cover ? html_writer::empty_tag('img', ['src' => visuallearning_file_url($cover), 'alt' => ''])
                    : html_writer::span('', $coverpending ? 'vl-shimmer' : 'vl-empty'),
                'vl-media-thumb'
            ) .
            html_writer::div(
                html_writer::div(get_string('cover', 'mod_visuallearning'), 'vl-media-title') .
                html_writer::div($chip(get_string('media_picture', 'mod_visuallearning'), (int)$book->coverstatus,
                    $coverpending), 'vl-chips') .
                ($canai ? $remake([visuallearning_button($quoteurl('cover'), get_string('regenimage', 'mod_visuallearning'),
                    'post')]) : ''),
                'vl-media-body'
            ),
            'vl-media-card is-cover'
        );
    }
    foreach ($pages as $page) {
        $image = media::image_file($context, media::AREA_IMAGE, (int)$page->id);
        $pend = $pendingby[(int)$page->id] ?? [];
        $buttons = [visuallearning_button($quoteurl('image', (int)$page->id), get_string('regenpageimage', 'mod_visuallearning'),
            'post')];
        if (media::option_prompts($book, $page)) {
            $buttons[] = visuallearning_button($quoteurl('option', (int)$page->id),
                get_string('regenoptions', 'mod_visuallearning'), 'post');
        }
        $cardchip = '';
        if ($sequence && !empty($page->bundleid) && media::card_prompts($book, $page)) {
            $made = count(array_filter(array_column(media::card_chain($context, $book, $page), 'file')));
            $buttons[] = visuallearning_button($quoteurl('card', (int)$page->id),
                get_string('regencards', 'mod_visuallearning'), 'post');
            $cardchip = $chip(get_string('media_cards', 'mod_visuallearning', $made),
                $made === \mod_visuallearning\local\picture_modes::CARDS ? VISUALLEARNING_MEDIA_READY : VISUALLEARNING_MEDIA_PENDING,
                $pend['card'] ?? 0);
        }
        if ($svgmode && ($svgprompt = media::svg_prompt($book, $page)) !== '') {
            $svgready = media::svg_current($context, (int)$page->id, $svgprompt);
            $buttons[] = visuallearning_button($quoteurl('animation', (int)$page->id),
                get_string('regenanimation', 'mod_visuallearning'), 'post');
            $cardchip .= $chip(get_string('media_animation', 'mod_visuallearning'),
                $svgready ? VISUALLEARNING_MEDIA_READY
                    : VISUALLEARNING_MEDIA_PENDING,
                $pend['animation'] ?? 0);
        }
        $buttons[] = visuallearning_button($quoteurl('voice', (int)$page->id), get_string('regenaudio', 'mod_visuallearning'),
            'post');
        $cards .= html_writer::div(
            html_writer::div(
                $image ? html_writer::empty_tag('img', ['src' => visuallearning_file_url($image), 'alt' => s($page->alttext)])
                    : html_writer::span('', !empty($pend['image']) ? 'vl-shimmer' : 'vl-empty'),
                'vl-media-thumb'
            ) . html_writer::span((int)$page->pagenum, 'vl-media-num') .
            html_writer::div(
                html_writer::div(format_string($page->title, true, ['context' => $context]), 'vl-media-title') .
                html_writer::div(
                    $chip(get_string('media_picture', 'mod_visuallearning'), (int)$page->imagestatus, $pend['image'] ?? 0) .
                    $cardchip .
                    $chip(get_string('media_voice', 'mod_visuallearning'), (int)$page->audiostatus, $pend['audio'] ?? 0),
                    'vl-chips'
                ) .
                ($canai ? $remake($buttons) : ''),
                'vl-media-body'
            ),
            'vl-media-card'
        );
    }
    echo html_writer::div($cards, 'vl-media-grid');
    if ($canai) {
        echo html_writer::div(
            visuallearning_button(
                new moodle_url($baseurl, ['action' => 'quote', 'what' => 'all', 'sesskey' => sesskey()]),
                get_string('regenerateall', 'mod_visuallearning'),
                'post'
            ),
            'vl-remake-all'
        );
    }
}

// Requests still running: one line, with the list behind it.
if ($pendingrows) {
    echo html_writer::tag(
        'details',
        html_writer::tag('summary', get_string('media_inprogresslist', 'mod_visuallearning', count($pendingrows))) .
        html_writer::tag('ul', implode('', array_map($requestrow, $pendingrows)), ['class' => 'vl-req-list']),
        ['class' => 'vl-inprogress']
    );
}
echo $nav(3, $total > 0, $mediadone || !$total ? '' : get_string('wizard_hint_3', 'mod_visuallearning'));
echo html_writer::end_tag('section');

// Step 4: the lesson is ready - what learners will see, and what to do next.
echo html_writer::start_tag('section', ['class' => 'vl-slide', 'data-slide' => 4, 'aria-labelledby' => 'vl-slide-4']);
$visible = (bool)$cm->visible;
if ($mediadone) {
    echo html_writer::div(
        html_writer::div(
            '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5" fill="none" stroke="currentColor" ' .
                'stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
            'vl-ready-tick'
        ) . html_writer::div(
            html_writer::tag('h3', get_string('wizard_title_4', 'mod_visuallearning'), ['id' => 'vl-slide-4', 'tabindex' => '-1']) .
            html_writer::tag('p', get_string('wizard_intro_4', 'mod_visuallearning', (object)['pages' => $total,
                'questions' => count(array_filter($pages, fn($p) => \mod_visuallearning\local\question::of_page($p)))])),
            'vl-ready-text'
        ),
        'vl-ready-hero'
    );
} else {
    echo html_writer::tag(
        'h3',
        get_string('wizard_title_4_notready', 'mod_visuallearning'),
        ['id' => 'vl-slide-4', 'tabindex' => '-1']
    );
    echo html_writer::tag('p', get_string('wizard_intro_4_notready', 'mod_visuallearning'), ['class' => 'vl-slide-intro']);
}
// A filmstrip of the slides learners will see.
$strip = '';
$cover = $hascover ? media::image_file($context, media::AREA_COVER, 0) : null;
if ($cover) {
    $strip .= html_writer::div(html_writer::empty_tag('img', ['src' => visuallearning_file_url($cover), 'alt' => '']) .
        html_writer::span(get_string('cover', 'mod_visuallearning')), 'vl-frame');
}
foreach ($pages as $page) {
    $image = media::image_file($context, media::AREA_IMAGE, (int)$page->id);
    $strip .= html_writer::div(
        ($image ? html_writer::empty_tag('img', ['src' => visuallearning_file_url($image), 'alt' => ''])
            : html_writer::span('', 'vl-frame-empty')) .
        html_writer::span((int)$page->pagenum . '. ' . format_string($page->title)),
        'vl-frame'
    );
}
if ($strip !== '') {
    echo html_writer::div($strip, 'vl-filmstrip', ['aria-label' => get_string('wizard_slides', 'mod_visuallearning')]);
}
$preview = new moodle_url('/mod/visuallearning/view.php', ['id' => $cm->id]);
$actions = html_writer::link($preview, get_string('previewbook', 'mod_visuallearning'), ['class' => 'btn btn-primary']);
$actions .= html_writer::link(
    new moodle_url('/mod/visuallearning/export.php', ['id' => $cm->id]),
    get_string('export_button', 'mod_visuallearning'),
    ['class' => 'btn btn-secondary']
);
if ($book->enablequiz && has_capability('moodle/question:add', $context)) {
    $actions .= html_writer::link(
        new moodle_url('/mod/visuallearning/export.php', ['id' => $cm->id, 'action' => 'qbank']),
        get_string('qbank_button', 'mod_visuallearning'),
        ['class' => 'btn btn-secondary']
    );
}
echo html_writer::div($actions, 'pb-manage-actions vl-ready-actions');
// What to do next.
$next = [
    get_string($visible ? 'wizard_next_visible' : 'wizard_next_hidden', 'mod_visuallearning'),
    get_string('wizard_next_preview', 'mod_visuallearning'),
    get_string('wizard_next_report', 'mod_visuallearning', html_writer::link(
        new moodle_url('/mod/visuallearning/report.php', ['id' => $cm->id]),
        get_string('report', 'mod_visuallearning')
    )),
    get_string('wizard_next_reuse', 'mod_visuallearning'),
];
echo html_writer::tag('h4', get_string('wizard_whatnext', 'mod_visuallearning'), ['class' => 'vl-sub']);
echo html_writer::alist($next, ['class' => 'vl-checklist'], 'ol');
echo $nav(4, false);
echo html_writer::end_tag('section');

echo html_writer::end_div();
echo html_writer::end_div();

echo $OUTPUT->footer();
