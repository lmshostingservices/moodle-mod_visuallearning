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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <https://www.gnu.org/licenses/>.

namespace mod_visuallearning\aicoursebuilder;

defined('MOODLE_INTERNAL') || die();

/**
 * AI Course Builder adapter for AI Visual Learning.
 *
 * @package mod_visuallearning
 * @copyright 2026 LMS Hosting Services
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class generator {
    private const COMPONENT = 'mod_visuallearning';

    /**
     * Resolve the page count identically for price estimates and generation.
     *
     * @param array $brief
     * @return int
     */
    private static function target_maxpages(array $brief): int {
        global $CFG;
        // The page-count helper lives in lib.php, which is not loaded for cron or estimate calls.
        require_once($CFG->dirroot . '/mod/visuallearning/lib.php');
        $tool = is_array($brief['tool'] ?? null) ? $brief['tool'] : [];
        $lesson = is_array($brief['lesson'] ?? null) ? $brief['lesson'] : [];
        $outcomes = is_array($lesson['outcomes'] ?? null) ? $lesson['outcomes'] : [];
        $requested = (int)($tool['maxpages'] ?? $lesson['maxpages'] ?? (count($outcomes) ?: 4));
        return visuallearning_page_count($requested);
    }

    /**
     * Estimate using Visual Learning's approved price list and the activity's configured page/picture mode.
     *
     * @param array $brief
     * @return int
     */
    public static function native_estimate(array $brief): int {
        global $DB;
        $cmid = (int)($brief['cmid'] ?? 0);
        if ($cmid > 0) {
            [$course, $cm] = get_course_and_cm_from_cmid($cmid, 'visuallearning');
            $book = $DB->get_record('visuallearning', ['id' => $cm->instance], '*', MUST_EXIST);
            $book->maxpages = self::target_maxpages($brief);
            return \mod_visuallearning\local\ai\requests::lesson_price($book);
        }

        $tool = is_array($brief['tool'] ?? null) ? $brief['tool'] : [];
        $lesson = is_array($brief['lesson'] ?? null) ? $brief['lesson'] : [];
        $book = (object)[
            'maxpages' => self::target_maxpages($brief),
            'picturemode' => \mod_visuallearning\local\picture_modes::clean($tool['picturemode'] ?? null),
            'animationmode' => 'automatic',
        ];
        return \mod_visuallearning\local\ai\requests::lesson_price($book);
    }

    /**
     * Start a lesson through Visual Learning's confirmed quote and existing v2 draft route.
     *
     * @param int $cmid
     * @param array $brief
     * @return string Visual Learning request id
     */
    public static function native_generate(int $cmid, array $brief): string {
        global $DB, $USER;
        [$course, $cm] = get_course_and_cm_from_cmid($cmid, 'visuallearning');
        $book = $DB->get_record('visuallearning', ['id' => $cm->instance, 'course' => $course->id], '*', MUST_EXIST);

        // Use the module's own update path to store the generated lesson source before quoting its draft.
        $lesson = is_array($brief['lesson'] ?? null) ? $brief['lesson'] : [];
        $outcomes = is_array($lesson['outcomes'] ?? null) ? $lesson['outcomes'] : [];
        $source = [];
        $objectives = [];
        foreach ($outcomes as $outcome) {
            $text = is_array($outcome) ? trim((string)($outcome['text'] ?? '')) : trim((string)$outcome);
            if ($text !== '') {
                $source[] = $text;
                $objectives[] = $text;
            }
        }
        $material = trim((string)($lesson['content'] ?? $brief['sourcecontent'] ?? ''));
        if ($material !== '') {
            array_unshift($source, $material);
        }
        if (!$source) {
            throw new \moodle_exception('write_nocontent', 'mod_visuallearning');
        }
        $book->instance = (int)$book->id;
        $book->coursemodule = $cmid;
        $book->name = clean_param((string)($lesson['title'] ?? $book->name), PARAM_TEXT);
        $book->sourcecontent = implode("\n\n", $source);
        if ($objectives) {
            $book->objectives = implode("\n", $objectives);
        }
        $program = is_array($brief['program'] ?? null) ? $brief['program'] : [];
        $book->learnerlevel = self::learner_level((string)($program['level'] ?? ''));
        $tool = is_array($brief['tool'] ?? null) ? $brief['tool'] : [];
        $book->maxpages = self::target_maxpages($brief);
        $keysource = trim((string)($brief['idempotencyKey'] ?? ''));
        if ($keysource === '') {
            throw new \moodle_exception('generationfailed', 'local_aicoursebuilder');
        }
        // The LMS Labs v2 bundle boundary requires an RFC-4122 UUID header.
        // Derive one deterministically so a retry reuses the same request key.
        $idempotencykey = \mod_visuallearning\local\ai\bundles::key($keysource, 'course-builder');

        $transaction = $DB->start_delegated_transaction();
        try {
            visuallearning_update_instance($book);
            $book = $DB->get_record('visuallearning', ['id' => $book->id], '*', MUST_EXIST);
            $estimate = \mod_visuallearning\local\ai\requests::lesson_price($book);
            $existing = $DB->get_record('visuallearning_aireq', ['idemkey' => $idempotencykey]);
            if ($existing && (int)$existing->visuallearningid !== (int)$book->id) {
                throw new \moodle_exception('generationfailed', 'local_aicoursebuilder');
            }
            $transaction->allow_commit();
        } catch (\Throwable $error) {
            $transaction->rollback($error);
        }
        if ($existing && $existing->status !== 'quoted') {
            return (string)$existing->id;
        }
        // A site that has never checked its Visual Learning access holds every quote as "not activated".
        // The access check is free, so run it once before quoting a course-builder lesson.
        if (!\mod_visuallearning\local\unlock::active()) {
            \mod_visuallearning\local\unlock::verify();
        }
        $quote = $existing ? [
            'batch' => (string)$existing->batch,
            'items' => [\mod_visuallearning\local\ai\requests::WRITE => 1],
            'credits' => (int)$existing->maxcredits,
            'hold' => null,
        ] : \mod_visuallearning\local\ai\requests::quote(
            $book,
            (int)$USER->id,
            [['operation' => \mod_visuallearning\local\ai\requests::WRITE]],
            null,
            $idempotencykey
        );
        if (!empty($quote['hold']) || ($quote['items'][\mod_visuallearning\local\ai\requests::WRITE] ?? 0) !== 1
                || (int)$quote['credits'] !== $estimate) {
            throw new \moodle_exception('generatornotready', 'local_aicoursebuilder', '', 'Visual Learning quote unavailable');
        }
        $confirmed = \mod_visuallearning\local\ai\requests::confirm(
            $book,
            (string)$quote['batch'],
            (int)$USER->id,
            $estimate
        );
        if (count($confirmed) !== 1 || $confirmed[0]->operation !== \mod_visuallearning\local\ai\requests::WRITE
                || empty($confirmed[0]->id)) {
            throw new \moodle_exception('generationfailed', 'local_aicoursebuilder');
        }
        $requestid = (string)$confirmed[0]->id;
        return $requestid;
    }

    /**
     * Read the owning plugin's durable request state; its own task performs the request and persists pages.
     *
     * @param int $cmid
     * @param string $jobid
     * @return array
     */
    public static function native_status(int $cmid, string $jobid): array {
        global $DB;
        [$course, $cm] = get_course_and_cm_from_cmid($cmid, 'visuallearning');
        $book = $DB->get_record('visuallearning', ['id' => $cm->instance, 'course' => $course->id], '*', MUST_EXIST);
        if (!ctype_digit($jobid)) {
            return ['status' => 'blocked', 'error' => 'Invalid Visual Learning request reference.', 'retryable' => false];
        }
        $request = $DB->get_record('visuallearning_aireq', [
            'id' => (int)$jobid,
            'visuallearningid' => $book->id,
            'operation' => \mod_visuallearning\local\ai\requests::WRITE,
        ]);
        if (!$request) {
            return ['status' => 'blocked', 'error' => 'Visual Learning request was not found.', 'retryable' => false];
        }
        if ($request->status === 'completed') {
            $pages = $DB->get_records('visuallearning_pages', ['visuallearningid' => $book->id], 'pagenum ASC');
            $pagecount = count($pages);
            if ($pagecount < 1) {
                return ['status' => 'blocked', 'error' => 'Visual Learning completed without saved pages.', 'retryable' => false];
            }
            return [
                'status' => 'complete',
                'retryable' => false,
                'result' => [
                    'requestid' => (int)$request->id,
                    'pagecount' => $pagecount,
                    'pages' => array_map(static function ($page) {
                        return [
                            'id' => (int)$page->id,
                            'title' => (string)$page->title,
                            'outcome' => (string)$page->outcometext,
                        ];
                    }, array_values($pages)),
                    'creditscharged' => $request->charged === null ? null : (int)$request->charged,
                ],
            ];
        }
        if (in_array($request->status, ['pending', 'quoted'], true)) {
            return ['status' => 'running', 'retryable' => false];
        }
        if ($request->status === 'uncertain') {
            return [
                'status' => 'blocked',
                'retryable' => false,
                'error' => 'Visual Learning needs its existing manual request check before the build can continue.',
            ];
        }
        return [
            'status' => 'failed',
            'retryable' => false,
            'error' => 'Visual Learning request ended with status: ' . clean_param((string)$request->status, PARAM_ALPHANUMEXT),
        ];
    }

    /**
     * Confirm that Visual Learning's native draft importer has already persisted its completed result.
     *
     * @param int $cmid
     * @param array $result
     * @return void
     */
    public static function native_save_result(int $cmid, array $result): void {
        global $DB;
        [$course, $cm] = get_course_and_cm_from_cmid($cmid, 'visuallearning');
        $book = $DB->get_record('visuallearning', ['id' => $cm->instance, 'course' => $course->id], '*', MUST_EXIST);
        $saved = $DB->count_records('visuallearning_pages', ['visuallearningid' => $book->id]);
        if ($saved < 1 || $saved !== (int)($result['pagecount'] ?? 0)) {
            throw new \moodle_exception('emptygeneratorresult', 'local_aicoursebuilder');
        }
    }

    public static function is_available(): bool {
        return class_exists('\local_aicoursebuilder\local\generator_runtime')
            && \local_aicoursebuilder\local\generator_runtime::component_available(self::COMPONENT);
    }

    public static function estimate(array $brief): int {
        return \local_aicoursebuilder\local\generator_runtime::estimate(self::COMPONENT, self::prepare_brief($brief));
    }

    public static function generate(int $cmid, array $brief): string {
        return \local_aicoursebuilder\local\generator_runtime::generate(self::COMPONENT, $cmid, self::prepare_brief($brief));
    }

    public static function status(int $cmid, string $jobid): array {
        return \local_aicoursebuilder\local\generator_runtime::status(self::COMPONENT, $cmid, $jobid);
    }

    /**
     * Map shared curriculum details onto this plugin's existing activity fields.
     *
     * @param array $brief
     * @return array
     */
    public static function prepare_brief(array $brief): array {
        $program = $brief['program'] ?? [];
        $lesson = $brief['lesson'] ?? [];
        $level = strtolower(trim((string)($program['level'] ?? '')));
        $learnerlevel = 'adult';
        if (preg_match('/primary|elementary|early years/', $level)) {
            $learnerlevel = 'primary';
        } else if (preg_match('/secondary|high school|senior secondary/', $level)) {
            $learnerlevel = 'secondary';
        } else if (preg_match('/university|professional|advanced/', $level)) {
            $learnerlevel = 'advanced';
        }
        $outcomes = $lesson['outcomes'] ?? [];
        $objectives = [];
        foreach (is_array($outcomes) ? $outcomes : [] as $outcome) {
            $text = is_array($outcome) ? ($outcome['text'] ?? '') : $outcome;
            if (is_string($text) && trim($text) !== '') {
                $objectives[] = trim($text);
            }
        }
        $brief['tool'] = is_array($brief['tool'] ?? null) ? $brief['tool'] : [];
        $brief['tool']['learnerlevel'] = $learnerlevel;
        $brief['tool']['objectives'] = implode("\n", $objectives);
        return $brief;
    }

    /**
     * Map Moodle course levels to Visual Learning's supported learner-level choices.
     *
     * @param string $level
     * @return string
     */
    private static function learner_level(string $level): string {
        $level = strtolower(trim($level));
        if (preg_match('/primary|elementary|early years/', $level)) {
            return 'primary';
        }
        if (preg_match('/secondary|high school|senior secondary/', $level)) {
            return 'secondary';
        }
        if (preg_match('/university|professional|advanced/', $level)) {
            return 'advanced';
        }
        return 'adult';
    }
}
