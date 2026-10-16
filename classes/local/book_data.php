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

namespace mod_visuallearning\local;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->dirroot . '/mod/visuallearning/lib.php');

/**
 * Builds the data the player needs.
 *
 * @package    mod_visuallearning
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class book_data {
    /**
     * Player payload.
     *
     * @param \stdClass $book
     * @param \cm_info|\stdClass $cm
     * @param \context_module $context
     * @param int $userid
     * @return array
     */
    public static function player(\stdClass $book, $cm, \context_module $context, int $userid): array {
        global $DB;
        $pages = visuallearning_get_pages($book->id);
        $progress = $DB->get_records(
            'visuallearning_progress',
            ['visuallearningid' => $book->id, 'userid' => $userid],
            '',
            'pageid, listened, timespent, tfpassed'
        );

        $out = [];
        $missing = false;
        $position = 0;
        foreach ($pages as $page) {
            // Learners play what has been made; nothing here ever asks LMS Labs for anything.
            $image = media::image_file($context, media::AREA_IMAGE, (int)$page->id);
            $clips = media::clips($book, $page, $position++);
            $clipurl = function (string $part) use ($context, $book, $page, $clips): string {
                return isset($clips[$part])
                    ? visuallearning_file_url(media::clip_file($context, $book, $page, $part, $clips[$part])) : '';
            };
            $cards = [];
            foreach (VISUALLEARNING_CARDS as $card) {
                $text = trim((string)$page->{$card . 'text'});
                if ($text === '') {
                    continue;
                }
                $audio = $clipurl($card);
                if ($audio === '') {
                    $missing = true;
                }
                $cards[] = [
                    'key' => $card,
                    'label' => get_string('card_' . $card, 'mod_visuallearning'),
                    'text' => format_text($text, FORMAT_PLAIN, ['context' => $context, 'para' => false]),
                    'audio' => $audio,
                ];
            }
            if (!$image) {
                $missing = true;
            }
            $p = $progress[$page->id] ?? null;
            $summary = trim((string)$page->summarytext) !== '' ? $page->summarytext : $page->concepttext;
            $statement = trim((string)$page->tfstatement);
            $out[] = [
                'id' => (int)$page->id,
                'num' => (int)$page->pagenum,
                'title' => format_string($page->title, true, ['context' => $context]),
                'image' => visuallearning_file_url($image),
                'alt' => s((string)$page->alttext),
                'cards' => $cards,
                'summary' => format_text($summary, FORMAT_PLAIN, ['context' => $context, 'para' => false]),
                // The recap clip says its number first, so no separate number clip is needed.
                'summaryaudio' => $clipurl('summary'),
                // The correct answer is never sent to the browser; answers are checked on the server.
                'statement' => $statement === '' ? '' : format_text(
                    $statement,
                    FORMAT_PLAIN,
                    ['context' => $context, 'para' => false]
                ),
                'statementaudio' => $clipurl('statement'),
                'passed' => $p ? (bool)$p->tfpassed : false,
                'listened' => $p ? (bool)$p->listened : false,
                'timespent' => $p ? (int)$p->timespent : 0,
            ];
        }

        $cover = media::image_file($context, media::AREA_COVER, 0);
        $chips = array_values(
            array_filter(
                [
                format_string((string)$book->industry, true, ['context' => $context]),
                format_string((string)$book->subindustry, true, ['context' => $context]),
                ],
            ),
        );

        // Kept for the player: the numbers are spoken inside each recap clip.
        $numbers = [];

        $strings = [];
        foreach (
            ['start', 'continuefrom', 'startover', 'contents', 'close', 'previous', 'next', 'finish', 'pause',
                'play', 'replay', 'pageof', 'listenedof', 'taptolisten', 'finishedtitle', 'finishedtext',
                'readagain', 'backtocourse', 'locked', 'listenedstatus', 'notlistened', 'nowreading',
                'imagecoming', 'listenfirst', 'pagescount', 'cover', 'listen', 'listening', 'listenagain',
                'recaptitle', 'recapsubtitle', 'recaptoc', 'quiztitle', 'quizof', 'quiztoc', 'true', 'false',
                'correct', 'incorrect', 'revisit', 'backtoquiz', 'quizdone', 'continue', 'pressplay'] as $key
        ) {
            $strings[$key] = get_string('player_' . strtolower($key), 'mod_visuallearning');
        }

        return [
            'cmid' => (int)$cm->id,
            'title' => format_string($book->name, true, ['context' => $context]),
            'intro' => trim(strip_tags((string)$book->intro)) !== ''
                ? format_module_intro('visuallearning', $book, $cm->id, false) : '',
            'chips' => $chips,
            'cover' => visuallearning_file_url($cover),
            'coveralt' => format_string($book->name, true, ['context' => $context]),
            'navmode' => (int)$book->navmode,
            'autoplay' => (bool)$book->autoplay,
            'recap' => (bool)$book->enablerecap,
            'quiz' => (bool)$book->enablequiz,
            'groupsize' => VISUALLEARNING_GROUP_SIZE,
            'numbers' => $numbers,
            'pages' => $out,
            'missingmedia' => $missing,
            'courseurl' => (new \moodle_url('/course/view.php', ['id' => $cm->course]))->out(false),
            'strings' => $strings,
        ];
    }
}
