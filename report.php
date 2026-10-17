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
 * Listening and time report.
 *
 * @package    mod_visuallearning
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id = required_param('id', PARAM_INT);
$userid = optional_param('userid', 0, PARAM_INT);
$download = optional_param('download', '', PARAM_ALPHA);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'visuallearning');
$book = $DB->get_record('visuallearning', ['id' => $cm->instance], '*', MUST_EXIST);
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/visuallearning:viewreports', $context);

$url = new moodle_url('/mod/visuallearning/report.php', ['id' => $cm->id]);
$PAGE->set_url($url);
$PAGE->set_title(format_string($book->name) . ': ' . get_string('report', 'mod_visuallearning'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_activity_record($book);
$PAGE->activityheader->disable();

$pages = visuallearning_get_pages($book->id);
$totalpages = count($pages);
// Pages with a check question (any type).
$asked = $book->enablequiz ? array_filter($pages, fn($p) => \mod_visuallearning\local\question::of_page($p) !== null) : [];
$totalstatements = count($asked);

// One learner, page by page.
if ($userid) {
    $user = core_user::get_user($userid, '*', MUST_EXIST);
    $conditions = ['visuallearningid' => $book->id, 'userid' => $userid];
    $progress = $DB->get_records('visuallearning_progress', $conditions, '', 'pageid, *');
    $table = new html_table();
    $table->head = [
        get_string('page', 'mod_visuallearning'), get_string('pagetitle', 'mod_visuallearning'),
        get_string('listenedcol', 'mod_visuallearning'), get_string('timespent', 'mod_visuallearning'),
        get_string('views', 'mod_visuallearning'), get_string('firstviewed', 'mod_visuallearning'),
        get_string('timelistened', 'mod_visuallearning'),
        get_string('qtype', 'mod_visuallearning'),
        get_string('tfattempts', 'mod_visuallearning'),
        get_string('tfcorrect', 'mod_visuallearning'),
    ];
    foreach ($pages as $page) {
        $p = $progress[$page->id] ?? null;
        $table->data[] = [
            (int)$page->pagenum,
            format_string($page->title),
            $p && $p->listened ? '✔' : '—',
            $p ? visuallearning_format_duration((int)$p->timespent) : '—',
            $p ? (int)$p->views : 0,
            $p && $p->timefirstviewed ? userdate($p->timefirstviewed, get_string('strftimedatetimeshort', 'langconfig')) : '—',
            $p && $p->timelistened ? userdate($p->timelistened, get_string('strftimedatetimeshort', 'langconfig')) : '—',
            isset($asked[$page->id]) ? get_string('qtype_' . $page->qtype, 'mod_visuallearning') : '—',
            $p ? (int)$p->tfattempts : 0,
            !isset($asked[$page->id]) ? '—' : ($p && $p->tfpassed ? '✔' : '✘'),
        ];
    }
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('reportfor', 'mod_visuallearning', fullname($user)));
    echo html_writer::link($url, '← ' . get_string('report', 'mod_visuallearning'));
    echo html_writer::table($table);
    echo $OUTPUT->footer();
    exit;
}

// All learners.
[$esql, $params] = get_enrolled_sql($context, 'mod/visuallearning:view', 0, true);
$userfields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
$sql = "SELECT u.id, u.email, {$userfields},
               COALESCE(SUM(pr.listened), 0) AS listened,
               COALESCE(SUM(pr.tfpassed), 0) AS tfpassed,
               COALESCE(SUM(pr.tfattempts), 0) AS tfattempts,
               COALESCE(SUM(pr.timespent), 0) AS timespent,
               MAX(pr.lastping) AS lastaccess
          FROM {user} u
          JOIN ($esql) e ON e.id = u.id
     LEFT JOIN {visuallearning_progress} pr ON pr.userid = u.id AND pr.visuallearningid = :bookid
      GROUP BY u.id, u.email, {$userfields}
      ORDER BY u.lastname, u.firstname";
$params['bookid'] = $book->id;
$rows = $DB->get_records_sql($sql, $params);

$headers = [
    get_string('fullnameuser'), get_string('email'),
    get_string('pageslistened', 'mod_visuallearning'), get_string('percentcomplete', 'mod_visuallearning'),
    get_string('timespent', 'mod_visuallearning'), get_string('lastaccess'), get_string('status'),
];
$hasquiz = $book->enablequiz && $totalstatements > 0;
if ($hasquiz) {
    array_splice($headers, 4, 0, [get_string('tfcorrect', 'mod_visuallearning'), get_string('tfattempts', 'mod_visuallearning')]);
}

// Every learner's attempts at every question: [userid][pageid] => attempts and passed.
$attempts = [];
if ($asked && $rows) {
    [$insql, $inparams] = $DB->get_in_or_equal(array_keys($asked), SQL_PARAMS_NAMED);
    $records = $DB->get_recordset_select(
        'visuallearning_progress',
        "visuallearningid = :bookid AND pageid $insql",
        $inparams + ['bookid' => $book->id],
        '',
        'id, userid, pageid, tfattempts, tfpassed'
    );
    foreach ($records as $record) {
        $attempts[$record->userid][$record->pageid] = $record;
    }
    $records->close();
}

if ($download === 'attempts' && $asked) {
    require_once($CFG->libdir . '/csvlib.class.php');
    $csv = new csv_export_writer();
    $csv->set_filename(clean_filename(format_string($book->name) . '-question-attempts'));
    $head = [get_string('fullnameuser'), get_string('email')];
    foreach ($asked as $page) {
        $head[] = get_string('pagex', 'mod_visuallearning', (int)$page->pagenum) . ' ' . format_string($page->title) . ' (' .
            get_string('qtype_' . $page->qtype, 'mod_visuallearning') . ')';
    }
    $csv->add_data($head);
    foreach ($rows as $r) {
        $line = [fullname($r), $r->email];
        foreach ($asked as $page) {
            $a = $attempts[$r->id][$page->id] ?? null;
            $line[] = !$a || !$a->tfattempts ? '' : ($a->tfpassed ? (int)$a->tfattempts
                : get_string('report_notyet_tries', 'mod_visuallearning', (int)$a->tfattempts));
        }
        $csv->add_data($line);
    }
    $csv->download_file();
    exit;
}

if ($download === 'csv') {
    require_once($CFG->libdir . '/csvlib.class.php');
    $csv = new csv_export_writer();
    $csv->set_filename(clean_filename(format_string($book->name) . '-report'));
    $csv->add_data($headers);
    foreach ($rows as $r) {
        $pct = $totalpages ? round($r->listened / $totalpages * 100) : 0;
        $row = [fullname($r), $r->email, (int)$r->listened . '/' . $totalpages, $pct . '%',
            visuallearning_format_duration((int)$r->timespent),
            $r->lastaccess ? userdate($r->lastaccess) : '',
            $totalpages && $r->listened >= $totalpages ? get_string('complete') : get_string('incomplete', 'mod_visuallearning')];
        if ($hasquiz) {
            array_splice($row, 4, 0, [(int)$r->tfpassed . '/' . $totalstatements, (int)$r->tfattempts]);
        }
        $csv->add_data($row);
    }
    $csv->download_file();
    exit;
}

$table = new html_table();
$table->head = $headers;
$table->attributes['class'] = 'generaltable table-sm';
foreach ($rows as $r) {
    $pct = $totalpages ? round($r->listened / $totalpages * 100) : 0;
    $done = $totalpages && $r->listened >= $totalpages;
    if ($done) {
        $statusbadge = html_writer::span(get_string('complete'), 'badge bg-success badge-success');
    } else if ($r->listened) {
        $statusbadge = html_writer::span(get_string('inprogress', 'mod_visuallearning'), 'badge bg-info badge-info');
    } else {
        $statusbadge = html_writer::span(get_string('notstarted', 'mod_visuallearning'), 'badge bg-secondary badge-secondary');
    }
    $row = [
        html_writer::link(new moodle_url($url, ['userid' => $r->id]), fullname($r)),
        s($r->email),
        (int)$r->listened . ' / ' . $totalpages,
        html_writer::div(
            html_writer::div('', 'progress-bar bg-success', ['style' => "width:{$pct}%"]),
            'progress',
            ['style' => 'min-width:90px;height:8px', 'title' => $pct . '%']
        ),
        visuallearning_format_duration((int)$r->timespent),
        $r->lastaccess ? userdate($r->lastaccess, get_string('strftimedatetimeshort', 'langconfig')) : '—',
        $statusbadge,
    ];
    if ($hasquiz) {
        array_splice($row, 4, 0, [(int)$r->tfpassed . ' / ' . $totalstatements, (int)$r->tfattempts]);
    }
    $table->data[] = $row;
}

echo $OUTPUT->header();
echo html_writer::start_div('vl-report');
echo $OUTPUT->heading(get_string('report', 'mod_visuallearning'));
echo html_writer::tag('p', get_string('reportintro', 'mod_visuallearning', $totalpages), ['class' => 'text-muted']);

// Headline numbers.
$started = count(array_filter($rows, fn($r) => $r->listened > 0));
$finished = count(array_filter($rows, fn($r) => $totalpages && $r->listened >= $totalpages));
$firsttime = 0;
$answered = 0;
foreach ($attempts as $byuser) {
    foreach ($byuser as $a) {
        if ($a->tfpassed) {
            $answered++;
            $firsttime += (int)$a->tfattempts === 1 ? 1 : 0;
        }
    }
}
$kpis = [
    [get_string('kpi_started', 'mod_visuallearning'), $started . ' / ' . count($rows)],
    [get_string('kpi_finished', 'mod_visuallearning'), (string)$finished],
];
if ($asked) {
    $kpis[] = [get_string('kpi_firsttime', 'mod_visuallearning'), $answered ? round($firsttime / $answered * 100) . '%' : '—'];
}
$kpihtml = '';
foreach ($kpis as [$label, $value]) {
    $kpihtml .= html_writer::div(html_writer::span($label, 'vl-kpi-label') . html_writer::span($value, 'vl-kpi-value'), 'vl-kpi');
}
echo html_writer::div($kpihtml, 'vl-kpis');

echo html_writer::div(
    html_writer::link(
        new moodle_url($url, ['download' => 'csv']),
        get_string('downloadcsv', 'mod_visuallearning'),
        ['class' => 'btn btn-secondary']
    ),
    'mb-3',
);
if (!$rows) {
    echo $OUTPUT->notification(get_string('nousers', 'mod_visuallearning'), \core\output\notification::NOTIFY_INFO);
} else {
    echo html_writer::table($table);
}

// The whole class's attempts at every question.
if ($asked && $rows) {
    echo html_writer::start_tag('section', ['class' => 'vl-attempts', 'aria-labelledby' => 'vl-attempts-h']);
    $legend = '';
    $legends = ['first' => 'report_first', 'second' => 'report_second', 'more' => 'report_more', 'notyet' => 'report_notyet'];
    foreach ($legends as $class => $key) {
        $legend .= html_writer::span(html_writer::span('', 'vl-swatch vl-cell--' . $class) .
            get_string($key, 'mod_visuallearning'), 'vl-legend-item');
    }
    echo html_writer::div(
        html_writer::tag('h3', get_string('report_attempts', 'mod_visuallearning'), ['id' => 'vl-attempts-h']) .
        html_writer::span($legend, 'vl-legend') .
        html_writer::link(
            new moodle_url($url, ['download' => 'attempts']),
            get_string('report_attemptscsv', 'mod_visuallearning'),
            ['class' => 'btn btn-secondary btn-sm']
        ),
        'vl-attempts-head'
    );
    $head = html_writer::tag('th', get_string('fullnameuser'), ['scope' => 'col']);
    $missed = [];
    foreach ($asked as $page) {
        $title = format_string($page->title) . ' · ' . get_string('qtype_' . $page->qtype, 'mod_visuallearning');
        $head .= html_writer::tag('th', (int)$page->pagenum, ['scope' => 'col', 'title' => $title]);
        $missed[$page->id] = 0;
    }
    $body = '';
    $sums = array_fill_keys(array_keys($asked), [0, 0]);
    foreach ($rows as $r) {
        $name = html_writer::link(new moodle_url($url, ['userid' => $r->id]), fullname($r));
        $cells = html_writer::tag('th', $name, ['scope' => 'row']);
        foreach ($asked as $page) {
            $a = $attempts[$r->id][$page->id] ?? null;
            $tries = $a ? (int)$a->tfattempts : 0;
            if (!$a || !$a->tfpassed) {
                $class = 'notyet';
                $text = $tries ? '×' . $tries : '';
            } else {
                $class = $tries <= 1 ? 'first' : ($tries === 2 ? 'second' : 'more');
                $text = (string)$tries;
                $sums[$page->id][0] += $tries;
                $sums[$page->id][1]++;
            }
            if ($tries > 1 || ($a && !$a->tfpassed && $tries)) {
                $missed[$page->id]++;
            }
            $cells .= html_writer::tag('td', $text, ['class' => 'vl-cell vl-cell--' . $class]);
        }
        $body .= html_writer::tag('tr', $cells);
    }
    $avg = html_writer::tag('th', get_string('report_average', 'mod_visuallearning'), ['scope' => 'row']);
    foreach ($asked as $page) {
        [$total, $n] = $sums[$page->id];
        $avg .= html_writer::tag('td', $n ? format_float($total / $n, 1) : '—', ['class' => 'vl-cell vl-cell--avg']);
    }
    $body .= html_writer::tag('tr', $avg, ['class' => 'vl-avg']);
    echo html_writer::div(html_writer::tag('table', html_writer::tag('thead', html_writer::tag('tr', $head)) .
        html_writer::tag('tbody', $body), ['class' => 'vl-grid']), 'vl-grid-wrap');
    echo html_writer::tag('p', get_string('report_attempts_help', 'mod_visuallearning'), ['class' => 'text-muted small']);

    // The questions most often missed first time: the pages to go over again in class.
    arsort($missed);
    $top = array_filter(array_slice($missed, 0, 3, true));
    if ($top) {
        $list = [];
        foreach ($top as $pageid => $n) {
            $list[] = get_string('report_missed_item', 'mod_visuallearning', (object)['page' => (int)$asked[$pageid]->pagenum,
                'title' => format_string($asked[$pageid]->title), 'count' => $n]);
        }
        echo html_writer::tag('h4', get_string('report_missed', 'mod_visuallearning'), ['class' => 'vl-sub']);
        echo html_writer::alist($list, ['class' => 'vl-missed']);
    }
    echo html_writer::end_tag('section');
}
echo html_writer::end_div();
echo $OUTPUT->footer();
