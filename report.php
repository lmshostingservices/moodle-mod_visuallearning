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
$totalstatements = count(array_filter($pages, fn($p) => trim((string)$p->tfstatement) !== ''));

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
            $p ? (int)$p->tfattempts : 0,
            trim((string)$page->tfstatement) === '' ? '—' : ($p && $p->tfpassed ? '✔' : '✘'),
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
echo $OUTPUT->heading(get_string('report', 'mod_visuallearning'));
echo html_writer::tag('p', get_string('reportintro', 'mod_visuallearning', $totalpages), ['class' => 'text-muted']);
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
echo $OUTPUT->footer();
