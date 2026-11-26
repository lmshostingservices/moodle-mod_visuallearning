<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace mod_visuallearning\local;

/**
 * Moodle learning outcomes linked to a lesson: reading them, tagging pages, coverage and rating.
 *
 * Moodle 5.3 added scale-less learning outcomes (linked through grade_outcomes_modules). Scaled outcomes (all Moodle
 * versions) are linked through outcome grade items. Every 5.3-only call is guarded, so the plugin still runs on 4.4.
 *
 * @package    mod_visuallearning
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class outcomes {
    /** @var int Most outcomes sent to the writer. */
    public const MAX = 20;

    /** @var int Longest outcome code. */
    public const MAX_CODE = 40;

    /** @var int Longest outcome text sent to the writer. */
    public const MAX_TEXT = 400;

    /** @var array Request cache: cmid => outcomes. */
    protected static $cache = [];

    /**
     * True when the site has learning outcomes switched on.
     *
     * @return bool
     */
    public static function enabled(): bool {
        global $CFG;
        return !empty($CFG->enableoutcomes);
    }

    /**
     * True on Moodle 5.3 and later, where an outcome can have no scale.
     *
     * @return bool
     */
    public static function scaleless_supported(): bool {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        return method_exists(\grade_outcome::class, 'get_outcomes_in_module');
    }

    /**
     * Forget cached outcomes (after the activity's settings or links change).
     */
    public static function reset_cache(): void {
        self::$cache = [];
    }

    /**
     * The outcomes linked to an activity, by outcome id, in a stable order, each with its unique code.
     *
     * @param \stdClass|\cm_info $cm
     * @return \stdClass[] id => {id, code, name, description, text, scaled, itemnumber, scaleitems}
     */
    public static function for_cm($cm): array {
        global $CFG;
        if (!self::enabled() || empty($cm->id)) {
            return [];
        }
        $cmid = (int)$cm->id;
        if (isset(self::$cache[$cmid])) {
            return self::$cache[$cmid];
        }
        require_once($CFG->libdir . '/gradelib.php');
        $courseid = (int)$cm->course;
        $found = [];
        $items = \grade_item::fetch_all(['courseid' => $courseid, 'itemtype' => 'mod',
            'itemmodule' => 'visuallearning', 'iteminstance' => (int)$cm->instance]) ?: [];
        foreach ($items as $item) {
            if (!empty($item->outcomeid)) {
                $found[(int)$item->outcomeid] = (int)$item->itemnumber;
            }
        }
        if (self::scaleless_supported()) {
            foreach (\grade_outcome::get_outcomes_in_module($cmid, $courseid) as $oid) {
                $found[(int)$oid] = $found[(int)$oid] ?? null;
            }
        }
        ksort($found);
        $out = [];
        $used = [];
        $n = 0;
        foreach ($found as $oid => $itemnumber) {
            $outcome = \grade_outcome::fetch(['id' => $oid]);
            if (!$outcome) {
                continue;
            }
            $n++;
            $code = self::code_from((string)$outcome->shortname, $n, $used);
            $used[\core_text::strtolower($code)] = true;
            $name = trim(preg_replace('/\s+/u', ' ', (string)$outcome->fullname));
            $description = trim(preg_replace(
                '/\s+/u',
                ' ',
                content_to_text((string)($outcome->description ?? ''), FORMAT_HTML)
            ));
            $text = $description !== '' && \core_text::strpos($description, $name) === false
                ? $name . '. ' . $description : $name;
            $scaleitems = 0;
            if (!empty($outcome->scaleid) && ($scale = $outcome->load_scale())) {
                $scaleitems = count($scale->load_items());
            }
            $out[$oid] = (object)[
                'id' => $oid,
                'code' => $code,
                'name' => $name,
                'description' => $description,
                'text' => \core_text::substr($text, 0, self::MAX_TEXT),
                'scaled' => !empty($outcome->scaleid),
                'itemnumber' => $itemnumber,
                'scaleitems' => $scaleitems,
            ];
            if (count($out) >= self::MAX) {
                break;
            }
        }
        return self::$cache[$cmid] = $out;
    }

    /**
     * The outcomes linked to a lesson (finds its course module).
     *
     * @param \stdClass $book
     * @return \stdClass[]
     */
    public static function for_book(\stdClass $book): array {
        if (!self::enabled() || empty($book->id)) {
            return [];
        }
        $cm = get_coursemodule_from_instance('visuallearning', $book->id, $book->course ?? 0, false, IGNORE_MISSING);
        return $cm ? self::for_cm($cm) : [];
    }

    /**
     * A short, unique code for an outcome: its short name, or LO1, LO2... when that is empty or taken.
     *
     * @param string $shortname
     * @param int $n Position (1-based)
     * @param array $used Codes already given, as keys
     * @return string
     */
    public static function code_from(string $shortname, int $n, array $used): string {
        $code = trim(preg_replace('/[^\p{L}\p{N}._\-\/ ]+/u', '', $shortname));
        $code = \core_text::substr(trim(preg_replace('/\s+/u', ' ', $code)), 0, self::MAX_CODE);
        if ($code === '' || isset($used[\core_text::strtolower($code)])) {
            $code = 'LO' . $n;
        }
        return $code;
    }

    /**
     * The brief lines that ask the writer to teach and tag the outcomes, or ''.
     *
     * @param \stdClass[] $outcomes
     * @return string
     */
    public static function brief_lines(array $outcomes): string {
        if (!$outcomes) {
            return '';
        }
        $lines = ['Learning outcomes this lesson must teach (code: outcome):'];
        foreach ($outcomes as $o) {
            $lines[] = '- ' . $o->code . ': ' . $o->text;
        }
        return implode("\n", $lines);
    }

    /**
     * Codes from a writer's page ("outcomes": ["LO1", ...]) cleaned for storage until they are mapped to ids.
     *
     * @param mixed $list
     * @return string[]
     */
    public static function clean_codes($list): array {
        if (is_string($list)) {
            $list = preg_split('/[,;]/', $list);
        }
        if (!is_array($list)) {
            return [];
        }
        $out = [];
        foreach ($list as $code) {
            if (!is_scalar($code)) {
                continue;
            }
            $code = \core_text::substr(trim((string)$code), 0, self::MAX_CODE);
            if ($code !== '' && !in_array($code, $out, true)) {
                $out[] = $code;
            }
        }
        return array_slice($out, 0, self::MAX);
    }

    /**
     * Map codes to the ids of the lesson's outcomes. Unknown codes are dropped, never stored.
     *
     * @param string[] $codes
     * @param \stdClass[] $outcomes
     * @return int[]
     */
    public static function codes_to_ids(array $codes, array $outcomes): array {
        $bycode = [];
        foreach ($outcomes as $o) {
            $bycode[\core_text::strtolower($o->code)] = (int)$o->id;
        }
        $ids = [];
        foreach ($codes as $code) {
            $id = $bycode[\core_text::strtolower(trim((string)$code))] ?? null;
            if ($id && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /**
     * The stored form of a page's outcome ids ('' or null when none).
     *
     * @param int[] $ids
     * @return string|null
     */
    public static function to_field(array $ids): ?string {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        return $ids ? implode(',', $ids) : null;
    }

    /**
     * The outcome ids a page is tagged with.
     *
     * @param \stdClass $page
     * @return int[]
     */
    public static function page_ids(\stdClass $page): array {
        $raw = trim((string)($page->outcomes ?? ''));
        return $raw === '' ? [] : array_values(array_unique(array_filter(array_map('intval', explode(',', $raw)))));
    }

    /**
     * Which pages teach each linked outcome.
     *
     * @param \stdClass[] $outcomes
     * @param \stdClass[] $pages
     * @return array outcome id => int[] page numbers
     */
    public static function coverage(array $outcomes, array $pages): array {
        $map = array_fill_keys(array_keys($outcomes), []);
        foreach ($pages as $page) {
            foreach (self::page_ids($page) as $oid) {
                if (isset($map[$oid])) {
                    $map[$oid][] = (int)$page->pagenum;
                }
            }
        }
        foreach ($map as &$nums) {
            sort($nums);
        }
        return $map;
    }

    /**
     * A scale value from a share of first-try right answers: the lowest item for none, the highest for all.
     *
     * @param float $score 0..1
     * @param int $items Number of scale items
     * @return int 1..$items
     */
    public static function scale_value(float $score, int $items): int {
        $items = max(1, $items);
        $score = max(0.0, min(1.0, $score));
        return 1 + (int)round($score * ($items - 1));
    }

    /**
     * Rate a learner's scaled outcomes from the check questions, once every tagged question has been answered.
     *
     * Only when the lesson's "Rate learning outcomes" setting is on. Each outcome is rated once: an outcome that
     * already has a rating (a teacher's or an earlier one) is never changed.
     *
     * @param \stdClass $book
     * @param \stdClass|\cm_info $cm
     * @param int $userid
     * @return int[] outcome id => value written
     */
    public static function rate_user(\stdClass $book, $cm, int $userid): array {
        global $DB, $CFG;
        if (empty($book->outcomerating) || empty($book->enablequiz)) {
            return [];
        }
        // AI wrote the questions: no learning outcome is rated until a teacher has checked the lesson (human oversight).
        if (!responsible_ai::is_reviewed($book)) {
            return [];
        }
        $scaled = array_filter(self::for_cm($cm), fn($o) => $o->scaled && $o->itemnumber !== null && $o->scaleitems > 1);
        if (!$scaled) {
            return [];
        }
        require_once($CFG->libdir . '/gradelib.php');
        $pages = $DB->get_records(
            'visuallearning_pages',
            ['visuallearningid' => $book->id],
            'pagenum',
            'id, pagenum, outcomes, qtype, tfstatement'
        );
        $progress = $DB->get_records(
            'visuallearning_progress',
            ['visuallearningid' => $book->id, 'userid' => $userid],
            '',
            'pageid, tfattempts, tfpassed'
        );
        $written = [];
        foreach ($scaled as $o) {
            $asked = 0;
            $firsttry = 0;
            $complete = true;
            foreach ($pages as $page) {
                if (!in_array((int)$o->id, self::page_ids($page), true) || trim((string)$page->tfstatement) === '') {
                    continue;
                }
                $asked++;
                $row = $progress[$page->id] ?? null;
                if (!$row || (int)$row->tfpassed < 1) {
                    $complete = false;
                    break;
                }
                if ((int)$row->tfattempts === 1) {
                    $firsttry++;
                }
            }
            if (!$asked || !$complete) {
                continue;
            }
            $item = \grade_item::fetch(['courseid' => $cm->course, 'itemtype' => 'mod', 'itemmodule' => 'visuallearning',
                'iteminstance' => $cm->instance, 'itemnumber' => $o->itemnumber]);
            if (!$item) {
                continue;
            }
            $grade = \grade_grade::fetch(['itemid' => $item->id, 'userid' => $userid]);
            if ($grade && $grade->finalgrade !== null) {
                continue; // Rated before, by a teacher or by an earlier finish: never replaced.
            }
            $value = self::scale_value($firsttry / $asked, (int)$o->scaleitems);
            if (
                grade_update_outcomes(
                    'mod/visuallearning',
                    $cm->course,
                    'mod',
                    'visuallearning',
                    $cm->instance,
                    $userid,
                    [$o->itemnumber => $value]
                )
            ) {
                $written[(int)$o->id] = $value;
            }
        }
        return $written;
    }

    /**
     * Rows for the mapping CSV: one per outcome, with the pages and questions that cover it.
     *
     * @param \stdClass[] $outcomes
     * @param \stdClass[] $pages
     * @return array[]
     */
    public static function mapping_rows(array $outcomes, array $pages): array {
        $rows = [['outcome_code', 'outcome', 'scaled', 'pages', 'page_titles', 'check_questions']];
        foreach ($outcomes as $o) {
            $nums = [];
            $titles = [];
            $questions = [];
            foreach ($pages as $page) {
                if (!in_array((int)$o->id, self::page_ids($page), true)) {
                    continue;
                }
                $nums[] = (int)$page->pagenum;
                $titles[] = (string)$page->title;
                if (trim((string)($page->tfstatement ?? '')) !== '') {
                    $questions[] = (int)$page->pagenum . ': ' . trim((string)$page->tfstatement);
                }
            }
            $rows[] = [$o->code, $o->text, $o->scaled ? 'yes' : 'no', implode(' ', $nums), implode(' | ', $titles),
                implode(' | ', $questions)];
        }
        return $rows;
    }

    /**
     * Moodle's learning outcomes import file (semicolon separated) for the lesson's outcomes: re-imports on any site.
     *
     * @param \stdClass[] $outcomes
     * @return string
     */
    public static function import_csv(array $outcomes): string {
        $lines = ['outcome_name;outcome_shortname;outcome_description;scale_name;scale_items;scale_description'];
        foreach ($outcomes as $o) {
            $cells = [$o->name, $o->code, $o->description, '', '', ''];
            $lines[] = implode(';', array_map(fn($c) => '"' . str_replace('"', '""', (string)$c) . '"', $cells));
        }
        return implode("\n", $lines) . "\n";
    }
}
