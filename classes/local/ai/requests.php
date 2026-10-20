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

namespace mod_visuallearning\local\ai;

use mod_visuallearning\local\approaches;
use mod_visuallearning\local\credentials;
use mod_visuallearning\local\importer;
use mod_visuallearning\local\media;
use mod_visuallearning\local\prompt_builder;
use mod_visuallearning\local\question;
use mod_visuallearning\local\visual_styles;
use moodle_exception;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->dirroot . '/mod/visuallearning/lib.php');

/**
 * Paid LMS Labs requests: pages written by LMS Labs AI, pages imported from the teacher's own AI assistant,
 * illustrations and voiceover clips.
 *
 * The rules (LMS Labs handover):
 * - The price is shown and confirmed before anything is sent ("quoted" requests are never sent).
 * - Every request has its own Idempotency-Key, stored with the exact JSON body before the first send.
 * - "Check again" resends the same key and body, so it can never be charged twice; a deliberate new generation is
 *   a new, separately confirmed request. Nothing is retried automatically after a timeout. The only automatic
 *   repeat is asking again about a request LMS Labs reported as still in progress (HTTP 202), with the same key.
 * - An unknown outcome is shown as unknown ("uncertain"), never as free or refunded. A charge is recorded only as
 *   LMS Labs reports it, never assumed from the confirmed maximum.
 * - LMS Labs does not keep pictures or audio: delivered media is saved at once, a 410 means it can no longer be
 *   delivered ("lost"), and a 410 alone is not taken as proof of a charge.
 *
 * @package    mod_visuallearning
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class requests {
    /** @var string Pages written by LMS Labs AI. */
    public const WRITE = 'write';

    /** @var string Pages written with the teacher's own AI assistant. */
    public const IMPORT = 'import';

    /** @var string A page illustration. */
    public const IMAGE = 'image';

    /** @var string The cover illustration. */
    public const COVER = 'cover';

    /** @var string The square picture of one picture-pick option (part: the option number). */
    public const OPTION = 'option';

    /** @var string One voiceover clip. */
    public const VOICE = 'voice';

    /** @var string One card picture (part: the card, 1 to 3); cards 2 and 3 edit the card picture before. */
    public const CARD = 'card';

    /** @var int Seconds a card picture waits for the picture it edits before it is cancelled. */
    public const CARD_WAIT = 3600;

    /** @var string[] States in which the same request may be sent again with the same key and body. */
    public const CHECKABLE = ['pending', 'uncertain'];

    /** @var string[] States that need the teacher's attention. */
    public const OPEN = ['pending', 'uncertain', 'conflict', 'lost', 'expired', 'failed'];

    /** @var int Seconds after which a request sent but never answered (the process stopped) counts as uncertain. */
    public const STALLED = 900;

    /** @var int Most automatic "still in progress" checks for one request. */
    public const MAX_POLLS = 40;

    /** @var int Seconds a quote stays valid for confirmation. */
    public const QUOTE_TTL = 3600;

    /** @var int Seconds spent at most on free clip price checks for one quote. */
    public const QUOTE_BUDGET = 30;

    /** @var string[] Error codes that leave the outcome unknown. */
    protected const UNCERTAIN = ['settlement_unconfirmed', 'deadline_exceeded', 'tts_unavailable', 'image_unavailable'];

    /** @var string[] 5xx codes LMS Labs documents as refused before anything was charged. */
    protected const PROVIDER_REFUSED = ['provider_unavailable', 'provider_failed', 'provider_rate_limited',
        'invalid_provider_result', 'image_failed', 'unusable_image', 'unusable_audio', 'voiceover_not_enabled',
        'tariff_not_approved', 'provider_not_configured'];

    /**
     * The price operation of a request operation.
     *
     * @param string $operation
     * @return string page, import, image, option or voice
     */
    public static function price_operation(string $operation): string {
        return [self::WRITE => 'page', self::IMPORT => 'import', self::IMAGE => 'image', self::COVER => 'image',
            self::OPTION => 'option', self::VOICE => 'voice', self::CARD => 'card'][$operation];
    }

    /**
     * SHA-1 of the Site ID in use now (the Site ID itself is not stored with requests).
     *
     * @return string
     */
    public static function sitehash(): string {
        $credentials = credentials::find();
        return $credentials === null ? '' : sha1((string)$credentials['siteid']);
    }

    /**
     * The stable identity of one clip: this component, this site, the lesson, the page and the part. It never contains
     * the text or the voice, so a clip made again after an edit or a new voice keeps its identity (and any free
     * remake LMS Labs offers); a deleted page's id is never reused, so a deleted clip's identity is never reused.
     *
     * @param stdClass $book
     * @param int $pageid
     * @param string $part
     * @return string 64 lower-case hex characters
     */
    public static function clipref(stdClass $book, int $pageid, string $part): string {
        return hash('sha256', json_encode(['mod_visuallearning', (string)get_site_identifier(), (int)$book->id, $pageid, $part]));
    }

    /**
     * The exact body of a voiceover clip.
     *
     * @param stdClass $book
     * @param int $pageid
     * @param string $part
     * @param string $text
     * @param int $maxcredits
     * @return array
     */
    public static function voice_body(stdClass $book, int $pageid, string $part, string $text, int $maxcredits): array {
        $voice = lmslabs::voice_name((string)$book->language, (string)$book->voice);
        if ($voice === null) {
            throw new moodle_exception('hold_novoice', 'mod_visuallearning');
        }
        return ['text' => $text, 'locale' => (string)$book->language, 'voice' => $voice, 'speed' => media::speed($book),
            'clipRef' => self::clipref($book, $pageid, $part), 'maxCredits' => $maxcredits];
    }

    /**
     * The exact body of an illustration.
     *
     * @param string $prompt
     * @param int $maxcredits
     * @param string $aspect 4:3 (page, cover) or 1:1 (picture-pick option)
     * @param string $purpose page, cover or option (for LMS Labs reporting only)
     * @return array
     */
    public static function image_body(
        string $prompt,
        int $maxcredits,
        string $aspect = media::ASPECT,
        string $purpose = 'page',
        string $style = 'illustration'
    ): array {
        return ['prompt' => $prompt, 'style' => $style, 'aspectRatio' => $aspect, 'purpose' => $purpose,
            'maxCredits' => $maxcredits];
    }

    /**
     * The style named in a picture request: the lesson's visual style when LMS Labs lists it, otherwise
     * "illustration" (the look is written into the prompt either way).
     *
     * @param stdClass $book
     * @return string
     */
    public static function image_style(stdClass $book): string {
        $style = visual_styles::of($book);
        return in_array($style, lmslabs::visual_styles(), true) ? $style : visual_styles::DEFAULT;
    }

    /**
     * What a picture is for, so LMS Labs can send it to the image model that draws it best (for example technical
     * explanations to the model strongest at accurate diagrams and labels). Sent only for lessons that are not
     * workplace stories, and only once LMS Labs lists "teachingApproach" in its v2 capabilities "imageFields";
     * story pictures send exactly what they always did.
     *
     * @param stdClass $book
     * @return array
     */
    public static function image_hints(stdClass $book): array {
        $out = [];
        $approach = approaches::of($book)->key();
        if ($approach !== approaches::DEFAULT && in_array('teachingApproach', lmslabs::image_fields(), true)) {
            $out['teachingApproach'] = $approach;
        }
        $level = \mod_visuallearning\local\learner_levels::of($book);
        if ($level !== \mod_visuallearning\local\learner_levels::DEFAULT && in_array('learnerLevel', lmslabs::image_fields(), true)) {
            $out['learnerLevel'] = $level;
        }
        return $out;
    }

    /**
     * The learner level in a page-writing request. Adult learners send nothing new. Other levels send
     * "learnerLevel" once LMS Labs lists the level in its v2 capabilities "learnerLevels"; until then the level's
     * brief travels at the end of "imageStyle" ({@see write_body()}), so LMS Labs AI still writes for those learners.
     *
     * @param stdClass $book
     * @return array
     */
    public static function level_fields(stdClass $book): array {
        $level = \mod_visuallearning\local\learner_levels::of($book);
        if ($level === \mod_visuallearning\local\learner_levels::DEFAULT) {
            return [];
        }
        if (in_array($level, lmslabs::learner_levels(), true)) {
            return ['learnerLevel' => $level];
        }
        return [];
    }

    /**
     * The teaching approach and visual style in a page-writing request.
     *
     * Workplace stories in the illustration style send exactly what they always did. Other lessons send
     * "teachingApproach" and "visualStyle" when LMS Labs lists both; until then the choice travels in "imageStyle"
     * as the style's art direction plus a short instruction, so LMS Labs AI still writes towards it.
     *
     * @param stdClass $book
     * @return array fields that replace or follow "imageStyle"
     */
    public static function approach_fields(stdClass $book): array {
        $approach = approaches::of($book);
        $style = visual_styles::of($book);
        if ($approach->key() === approaches::DEFAULT && $style === visual_styles::DEFAULT) {
            return [];
        }
        $direction = visual_styles::direction($book);
        if (in_array($approach->key(), lmslabs::teaching_approaches(), true) && in_array($style, lmslabs::visual_styles(), true)) {
            return ['imageStyle' => $direction, 'teachingApproach' => $approach->key(), 'visualStyle' => $style];
        }
        return ['imageStyle' => trim($direction . ' ' . $approach->draft_hint())];
    }

    /**
     * The exact body of a request for LMS Labs AI to write the pages.
     *
     * @param stdClass $book
     * @return array
     */
    public static function write_body(stdClass $book): array {
        $content = trim(str_replace(["\r\n", "\r"], "\n", (string)$book->sourcecontent));
        if ($content === '') {
            throw new moodle_exception('write_nocontent', 'mod_visuallearning');
        }
        if (\core_text::strlen($content) > lmslabs::MAX_CONTENT) {
            throw new moodle_exception('write_toolong', 'mod_visuallearning', '', lmslabs::MAX_CONTENT);
        }
        $max = (int)$book->maxpages;
        $body = array_merge([
            'title' => (string)$book->name,
            'industry' => trim((string)$book->industry),
            'subindustry' => trim((string)$book->subindustry),
            'language' => (string)$book->language,
            'culture' => prompt_builder::culture($book),
            'imageStyle' => trim((string)$book->imagestyle),
        ], self::approach_fields($book), [
            'maxPages' => $max,
            'recap' => (bool)$book->enablerecap,
            // One check question per page, in the types the teacher allows and LMS Labs AI writes.
            'questionTypes' => $book->enablequiz
                ? array_values(array_intersect(question::allowed($book), lmslabs::question_types())) : [],
            'content' => $content,
            'maxCredits' => $max * lmslabs::PAGE_CREDITS,
        ], self::picture_mode_fields($book), self::level_fields($book));
        $level = \mod_visuallearning\local\learner_levels::brief($book, 'text');
        if ($level !== '' && !isset($body['learnerLevel'])) {
            // Until LMS Labs takes the level as a field, its brief travels with the art direction.
            $body['imageStyle'] = trim($body['imageStyle'] . ' ' . $level);
        }
        return $body;
    }

    /**
     * The picture mode in a page-writing request: only for lessons that use card pictures or animated diagrams, and
     * only once LMS Labs AI says it writes them (its v2 capabilities list "draftPictureModes"). One picture per page
     * sends nothing new.
     *
     * @param stdClass $book
     * @return array
     */
    public static function picture_mode_fields(stdClass $book): array {
        $mode = \mod_visuallearning\local\picture_modes::of($book);
        if ($mode === \mod_visuallearning\local\picture_modes::SINGLE || !in_array($mode, lmslabs::draft_picture_modes(), true)) {
            return [];
        }
        return ['pictureMode' => $mode];
    }

    /**
     * Makes a quote: the requests a confirmation would send, stored as "quoted" (never sent) with their keys,
     * exact bodies and prices. Clip prices come from LMS Labs' free price check when it offers free remakes;
     * a clip whose price is not known counts at the full price, so the teacher never confirms less than it can cost.
     *
     * @param stdClass $book
     * @param int $userid
     * @param array $plans from {@see media::plan()}, or one write plan
     * @param string|null $result kept with an import (the pages it creates once LMS Labs confirms the charge)
     * @return array ['batch', 'items' => count by operation, 'credits' => total, 'free' => free clips,
     *     'included' => count by operation already paid for in the slide price, 'hold' => reason|null,
     *     'skipped' => already waiting, 'dropped' => option pictures left out, 'droppedhold' => why]
     */
    public static function quote(stdClass $book, int $userid, array $plans, ?string $result = null): array {
        global $DB;
        self::expire_quotes((int)$book->id);
        $batch = \core\uuid::generate();
        $summary = ['batch' => $batch, 'items' => [], 'credits' => 0, 'free' => 0, 'included' => [], 'hold' => null,
            'skipped' => 0,
            'dropped' => 0, 'droppedhold' => null, 'droppedcards' => 0, 'droppedcardshold' => null];
        $options = array_filter($plans, fn($p) => $p['operation'] === self::OPTION);
        if ($options && count($options) < count($plans) && ($hold = lmslabs::hold('option')) !== null) {
            // Option pictures cannot be made yet: everything else still can (options show as text cards meanwhile).
            $plans = array_values(array_filter($plans, fn($p) => $p['operation'] !== self::OPTION));
            $summary['dropped'] = count($options);
            $summary['droppedhold'] = $hold;
        }
        $cards = array_filter($plans, fn($p) => $p['operation'] === self::CARD);
        if ($cards && count($cards) < count($plans) && ($hold = lmslabs::hold('card')) !== null) {
            // Card pictures cannot be made yet: the page pictures and voiceover still can (each page shows its picture).
            $plans = array_values(array_filter($plans, fn($p) => $p['operation'] !== self::CARD));
            $summary['droppedcards'] = count($cards);
            $summary['droppedcardshold'] = $hold;
        }
        if (!$plans) {
            return $summary;
        }
        foreach (array_unique(array_map(fn($p) => self::price_operation($p['operation']), $plans)) as $op) {
            if (($hold = lmslabs::hold($op)) !== null) {
                $summary['hold'] = $hold;
                return $summary;
            }
        }
        $remakes = lmslabs::remakes() !== null;
        $until = time() + self::QUOTE_BUDGET;
        $now = time();
        $sitehash = self::sitehash();
        foreach ($plans as $plan) {
            $operation = $plan['operation'];
            $targetid = (int)($plan['targetid'] ?? 0);
            $part = (string)($plan['part'] ?? '');
            if (self::waiting((int)$book->id, $operation, $targetid, $part)) {
                // Already asked for and not resolved: never asked for again with a new key.
                $summary['skipped']++;
                continue;
            }
            if ($operation === self::VOICE) {
                $price = lmslabs::VOICE_CREDITS;
                if ($remakes && time() < $until && empty(bundles::page((int)$book->id, $targetid)->bundleid)) {
                    $quoted = lmslabs::voice_quote(self::clipref($book, $targetid, $part));
                    $price = $quoted ?? lmslabs::VOICE_CREDITS;
                }
                $body = self::voice_body($book, $targetid, $part, (string)$plan['text'], $price);
            } else if ($operation === self::IMAGE || $operation === self::COVER) {
                $price = lmslabs::IMAGE_CREDITS;
                $body = self::image_body((string)$plan['prompt'], $price, media::ASPECT, $operation === self::COVER ? 'cover'
                    : 'page', self::image_style($book));
            } else if ($operation === self::OPTION) {
                $price = lmslabs::IMAGE_CREDITS;
                $body = self::image_body((string)$plan['prompt'], $price, media::OPTION_ASPECT, 'option',
                    self::image_style($book));
            } else if ($operation === self::CARD) {
                $price = lmslabs::approved_price('card');
                $body = self::image_body((string)$plan['prompt'], $price, media::ASPECT, 'card', self::image_style($book));
                if ((int)$part > 1) {
                    // Edits the picture of the card before; which picture is fixed just before it is first sent.
                    $body['_referenceFrom'] = (int)$part - 1;
                }
            } else if ($operation === self::WRITE) {
                $body = self::write_body($book);
                $price = (int)$body['maxCredits'];
            } else {
                $body = $plan['body'];
                $price = (int)$body['maxCredits'];
            }
            if (in_array($operation, [self::IMAGE, self::COVER, self::OPTION, self::CARD], true)) {
                $body += self::image_hints($book);
            }
            $included = false;
            if (in_array($operation, [self::VOICE, self::IMAGE, self::COVER, self::OPTION, self::CARD], true)) {
                $listprice = $price;
                [$body, $price] = bundles::media_quote((int)$book->id, $operation, $targetid, $part, $body);
                // Covered by the slide price the teacher already paid (not a free remake).
                $included = $price === 0 && $listprice > 0;
            } else {
                $body['_contract'] = 'bundle-1';
            }
            $DB->insert_record('visuallearning_aireq', (object)[
                'visuallearningid' => (int)$book->id,
                'userid' => $userid,
                'batch' => $batch,
                'operation' => $operation,
                'targetid' => $targetid,
                'part' => $part,
                'idemkey' => \core\uuid::generate(),
                'sitehash' => $sitehash,
                'body' => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'maxcredits' => $price,
                'status' => 'quoted',
                'result' => $operation === self::IMPORT ? $result : null,
                'tries' => 0,
                'retryafter' => 0,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
            $summary['items'][$operation] = ($summary['items'][$operation] ?? 0) + 1;
            $summary['credits'] += $price;
            if ($included) {
                $summary['included'][$operation] = ($summary['included'][$operation] ?? 0) + 1;
            } else if ($operation === self::VOICE && $price === 0) {
                $summary['free']++;
            }
        }
        return $summary;
    }

    /**
     * The quoted requests of a batch that can still be confirmed.
     *
     * @param int $bookid
     * @param string $batch
     * @return stdClass[]
     */
    public static function quoted(int $bookid, string $batch): array {
        global $DB;
        return array_values($DB->get_records_select(
            'visuallearning_aireq',
            'visuallearningid = :b AND batch = :batch AND status = :s AND timecreated > :since',
            ['b' => $bookid, 'batch' => $batch, 's' => 'quoted', 'since' => time() - self::QUOTE_TTL],
            'id ASC'
        ));
    }

    /**
     * Confirms a quote: its requests become pending and are sent in the background (an import is sent now).
     *
     * @param stdClass $book
     * @param string $batch
     * @param int $userid
     * @param int $credits the total the teacher was shown and confirmed
     * @return stdClass[] the confirmed requests
     */
    public static function confirm(stdClass $book, string $batch, int $userid, int $credits): array {
        global $DB;
        $rows = self::quoted((int)$book->id, $batch);
        if (!$rows) {
            throw new moodle_exception('quote_expired', 'mod_visuallearning');
        }
        if (array_sum(array_map(fn($r) => (int)$r->maxcredits, $rows)) !== $credits) {
            throw new moodle_exception('quote_changed', 'mod_visuallearning');
        }
        foreach (array_unique(array_map(fn($r) => self::price_operation($r->operation), $rows)) as $op) {
            if (($hold = lmslabs::hold($op)) !== null) {
                throw new moodle_exception('hold_' . $hold, 'mod_visuallearning');
            }
        }
        $now = time();
        $tasks = [];
        foreach ($rows as $row) {
            $row->status = 'pending';
            $row->userid = $userid;
            $row->timemodified = $now;
            $DB->update_record('visuallearning_aireq', $row);
            $perpage = in_array($row->operation, [self::VOICE, self::IMAGE, self::OPTION], true);
            $tasks[$perpage ? 'page' . $row->targetid : $row->operation][] = (int)$row->id;
        }
        if (count($rows) === 1 && $rows[0]->operation === self::IMPORT) {
            return [self::send($rows[0], $book)];
        }
        foreach ($tasks as $ids) {
            self::queue($book, $ids, $userid);
        }
        return $rows;
    }

    /**
     * Queues the background task that sends (or asks again about) stored requests.
     *
     * @param stdClass $book
     * @param int[] $ids
     * @param int $userid
     * @param int $delay seconds
     */
    public static function queue(stdClass $book, array $ids, int $userid, int $delay = 0): void {
        $task = new \mod_visuallearning\task\generate_media();
        $task->set_custom_data(['bookid' => (int)$book->id, 'ids' => array_values(array_map('intval', $ids))]);
        $task->set_userid($userid);
        if ($delay > 0) {
            $task->set_next_run_time(time() + $delay);
        }
        \core\task\manager::queue_adhoc_task($task);
    }

    /**
     * Deletes quotes that were never confirmed (they were never sent, so nothing is lost).
     *
     * @param int $bookid
     */
    public static function expire_quotes(int $bookid): void {
        global $DB;
        $DB->delete_records_select(
            'visuallearning_aireq',
            'visuallearningid = :b AND status = :s AND timecreated < :before',
            ['b' => $bookid, 's' => 'quoted', 'before' => time() - self::QUOTE_TTL]
        );
    }

    /**
     * Cancels a quote the teacher did not confirm.
     *
     * @param int $bookid
     * @param string $batch
     */
    public static function cancel_quote(int $bookid, string $batch): void {
        global $DB;
        $DB->delete_records('visuallearning_aireq', ['visuallearningid' => $bookid, 'batch' => $batch, 'status' => 'quoted']);
    }

    /**
     * The unresolved request for one thing, if there is one.
     *
     * @param int $bookid
     * @param string $operation
     * @param int $targetid
     * @param string $part
     * @return stdClass|null
     */
    public static function waiting(int $bookid, string $operation, int $targetid, string $part = ''): ?stdClass {
        global $DB;
        [$insql, $params] = $DB->get_in_or_equal(self::CHECKABLE, SQL_PARAMS_NAMED);
        $params += ['b' => $bookid, 'op' => $operation, 't' => $targetid, 'p' => $part];
        $rows = $DB->get_records_select(
            'visuallearning_aireq',
            "visuallearningid = :b AND operation = :op AND targetid = :t AND part = :p AND status $insql",
            $params,
            'id DESC',
            '*',
            0,
            1
        );
        return $rows ? reset($rows) : null;
    }

    /**
     * Requests of a lesson that need the teacher's attention, newest first. Requests whose sending process stopped
     * without an answer are marked uncertain here, so they can be checked again.
     *
     * @param int $bookid
     * @return stdClass[]
     */
    public static function open(int $bookid): array {
        global $DB;
        $DB->execute(
            "UPDATE {visuallearning_aireq} SET status = 'uncertain', errorcode = 'stalled', timemodified = :now
              WHERE visuallearningid = :b AND status = 'pending' AND tries > 0 AND timemodified < :before",
            ['now' => time(), 'b' => $bookid, 'before' => time() - self::STALLED]
        );
        [$insql, $params] = $DB->get_in_or_equal(self::OPEN, SQL_PARAMS_NAMED);
        $params['b'] = $bookid;
        return array_values($DB->get_records_select(
            'visuallearning_aireq',
            "visuallearningid = :b AND status $insql",
            $params,
            'timecreated DESC, id DESC'
        ));
    }

    /**
     * One request of a lesson.
     *
     * @param int $bookid
     * @param int $id
     * @return stdClass
     */
    public static function get(int $bookid, int $id): stdClass {
        global $DB;
        return $DB->get_record('visuallearning_aireq', ['id' => $id, 'visuallearningid' => $bookid], '*', MUST_EXIST);
    }

    /**
     * Asks LMS Labs again about an uncertain or pending request, with the same key and body (in the background,
     * except an import, which is asked now).
     *
     * @param stdClass $row
     * @param stdClass $book
     * @param int $userid
     * @return stdClass
     */
    public static function check(stdClass $row, stdClass $book, int $userid): stdClass {
        if (self::recoverable($row)) {
            return self::recover($row, $book, $userid);
        }
        if (!in_array($row->status, self::CHECKABLE, true)) {
            return $row;
        }
        if ($row->operation === self::IMPORT) {
            return self::send($row, $book);
        }
        self::queue($book, [(int)$row->id], $userid);
        return $row;
    }

    /**
     * Hides a request from the teacher's list. Its record and key are kept for reference.
     *
     * @param stdClass $row
     * @return stdClass
     */
    public static function dismiss(stdClass $row): stdClass {
        global $DB;
        if (in_array($row->status, self::OPEN, true)) {
            $row->errorcode = $row->errorcode ?: $row->status;
            $row->status = 'dismissed';
            $row->timemodified = time();
            $DB->update_record('visuallearning_aireq', $row);
        }
        return $row;
    }

    /**
     * Whether this missing bundle delivery can be explicitly recovered.
     *
     * @param stdClass $row Request
     * @return bool
     */
    public static function recoverable(stdClass $row): bool {
        $body = json_decode((string)$row->body, true);
        return in_array($row->status, ['lost', 'failed'], true) && !empty($body['bundleId']);
    }

    /**
     * An explicit recovery preserves the original receipt and uses the same entitled slot.
     *
     * @param stdClass $row Original request
     * @param stdClass $book Activity
     * @param int $userid Teacher
     * @return stdClass
     */
    protected static function recover(stdClass $row, stdClass $book, int $userid): stdClass {
        global $DB;
        $lock = \core\lock\lock_config::get_lock_factory('mod_visuallearning_aireq')->get_lock('recover' . $row->id, 5);
        if (!$lock) {
            return $row;
        }
        try {
            $row = self::get((int)$book->id, (int)$row->id);
            if (!self::recoverable($row)) {
                return $row;
            }
            if ($row->sitehash !== self::sitehash()) {
                throw new moodle_exception('lmslabscredentialsmissing', 'mod_visuallearning');
            }
            [$body, $price] = bundles::media_quote(
                (int)$book->id, $row->operation, (int)$row->targetid, $row->part,
                json_decode($row->body, true), true
            );
            if ($price !== 0 || $body['remake']) {
                throw new moodle_exception('bundlesupport', 'mod_visuallearning');
            }
            $transaction = $DB->start_delegated_transaction();
            $next = clone $row;
            unset($next->id);
            $next->idemkey = \core\uuid::generate();
            $next->body = json_encode($body);
            $next->maxcredits = 0;
            $next->charged = null;
            $next->result = null;
            $next->errorcode = null;
            $next->status = 'pending';
            $next->tries = 0;
            $next->timecreated = $next->timemodified = time();
            $next->id = $DB->insert_record('visuallearning_aireq', $next);
            $row->status = 'dismissed';
            $DB->update_record('visuallearning_aireq', $row);
            $transaction->allow_commit();
            self::queue($book, [(int)$next->id], $userid);
            return $next;
        } finally {
            $lock->release();
        }
    }

    /**
     * Sends a stored request (same key, same body) once and records the outcome.
     *
     * @param stdClass $row
     * @param stdClass $book
     * @return stdClass
     */
    public static function send(stdClass $row, stdClass $book): stdClass {
        global $DB;
        $lock = \core\lock\lock_config::get_lock_factory('mod_visuallearning_aireq')->get_lock('req' . $row->id, 5);
        if (!$lock) {
            // Another process is sending it right now.
            return $row;
        }
        $booklock = null;
        try {
            if (in_array($row->operation, [self::WRITE, self::IMPORT], true)) {
                $booklock = \core\lock\lock_config::get_lock_factory('mod_visuallearning_aireq')
                    ->get_lock('book' . $book->id, 5);
                if (!$booklock) {
                    return $row;
                }
            }
            $row = $DB->get_record('visuallearning_aireq', ['id' => $row->id], '*', MUST_EXIST);
            if (!in_array($row->status, self::CHECKABLE, true)) {
                return $row;
            }
            if ($row->sitehash !== '' && $row->sitehash !== self::sitehash()) {
                // The key belongs to another LMS Labs site: sending it now could start a separately charged request.
                $row->status = 'uncertain';
                $row->errorcode = 'sitechanged';
                $row->timemodified = time();
                $DB->update_record('visuallearning_aireq', $row);
                return $row;
            }
            $context = \context_module::instance(
                get_coursemodule_from_instance('visuallearning', $book->id, $book->course, false, MUST_EXIST)->id
            );
            if ((int)$row->tries === 0 && ($reason = self::stale($row, $book, $context)) !== '') {
                // Never sent, and no longer what the page needs (edited or made meanwhile): not sent at all.
                $row->status = 'cancelled';
                $row->errorcode = $reason;
                $row->timemodified = time();
                $DB->update_record('visuallearning_aireq', $row);
                return $row;
            }
            if ($row->operation === self::CARD && !self::card_reference($row, $book, $context)) {
                // Waiting for the picture it edits, or cancelled because it will not come.
                return $row;
            }
            try {
                $payload = self::payload($row, $context);
            } catch (moodle_exception $e) {
                // The picture it edits was removed: never sent, or (if sent before) not sent with another picture.
                $row->status = (int)$row->tries === 0 ? 'cancelled' : 'uncertain';
                $row->errorcode = 'referencegone';
                $row->timemodified = time();
                $DB->update_record('visuallearning_aireq', $row);
                return $row;
            }
            $row->tries++;
            $row->timemodified = time();
            $DB->update_record('visuallearning_aireq', $row);

            $routes = [self::WRITE => lmslabs::DRAFT_ROUTE, self::IMPORT => lmslabs::IMPORT_ROUTE,
                self::IMAGE => lmslabs::IMAGE_ROUTE, self::COVER => lmslabs::IMAGE_ROUTE, self::OPTION => lmslabs::IMAGE_ROUTE,
                self::VOICE => lmslabs::VOICE_ROUTE, self::CARD => lmslabs::IMAGE_ROUTE];
            $image = in_array($row->operation, [self::IMAGE, self::COVER, self::OPTION, self::CARD], true);
            $accept = $image ? 'image/png, image/webp, image/jpeg, application/json'
                : ($row->operation === self::VOICE ? 'audio/mpeg, application/json' : 'application/json');
            $timeout = $image ? lmslabs::IMAGE_TIMEOUT : ($row->operation === self::WRITE ? lmslabs::DRAFT_TIMEOUT : 100);
            \core_php_time_limit::raise($timeout + 60);
            try {
                $sent = json_decode($row->body, true);
                if (($sent['_contract'] ?? '') === 'bundle-1') {
                    [$status, $headers, $body] = bundles::text($row, $book);
                } else {
                    $route = empty($sent['bundleId']) ? $routes[$row->operation] :
                        bundles::PREFIX . ($row->operation === self::VOICE ? '/speech/tts' : '/images');
                    [$status, $headers, $body] = lmslabs::post(
                    $route,
                    $payload,
                    (string)$row->idemkey,
                    $accept,
                    $timeout
                );
                }
            } catch (moodle_exception $e) {
                // No credentials any more: nothing was sent this time.
                $row->status = 'uncertain';
                $row->errorcode = 'nocredentials';
                $DB->update_record('visuallearning_aireq', $row);
                return $row;
            }
            $row->retryafter = max(1, min(60, (int)($headers['retry-after'] ?? 5)));
            self::outcome($row, $book, $context, $status, $headers, $body);
            if ($row->status === 'completed' && !in_array($row->operation, [self::WRITE, self::IMPORT], true)) {
                bundles::media_saved($row, $headers);
            }
            $row->timemodified = time();
            $DB->update_record('visuallearning_aireq', $row);
            if ($row->operation !== self::WRITE && $row->operation !== self::IMPORT) {
                media::refresh($DB->get_record('visuallearning', ['id' => $book->id], '*', MUST_EXIST), $context);
            }
            return $row;
        } finally {
            if ($booklock) {
                $booklock->release();
            }
            $lock->release();
        }
    }

    /**
     * Fixes the picture a card picture edits, before it is first sent.
     *
     * Card 1 needs none. For cards 2 and 3 the current picture of the card before is pinned in the stored body (as its
     * content hash), so every try sends exactly the same picture. While that picture is still being made the request
     * waits (it stays pending and is checked again); if it will not come, the request is cancelled before anything is
     * sent.
     *
     * @param stdClass $row
     * @param stdClass $book
     * @param \context_module $context
     * @return bool true when it can be sent now
     */
    protected static function card_reference(stdClass $row, stdClass $book, \context_module $context): bool {
        global $DB;
        $sent = json_decode((string)$row->body, true) ?: [];
        if (empty($sent['_referenceFrom']) || !empty($sent['_referenceHash'])) {
            return true;
        }
        $page = $DB->get_record('visuallearning_pages', ['id' => $row->targetid, 'visuallearningid' => $book->id]);
        $chain = $page ? media::card_chain($context, $book, $page) : [];
        $before = $chain[(int)$sent['_referenceFrom']]['file'] ?? null;
        if ($before) {
            $sent['_referenceHash'] = $before->get_contenthash();
            $row->body = json_encode($sent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $row->timemodified = time();
            $DB->update_record('visuallearning_aireq', $row);
            return true;
        }
        $waiting = self::waiting((int)$book->id, self::CARD, (int)$row->targetid, (string)$sent['_referenceFrom']);
        if ($page && $waiting && $waiting->status === 'pending' && time() - (int)$row->timecreated < self::CARD_WAIT) {
            $row->errorcode = 'waitingprevious';
            $row->timemodified = time();
            $DB->update_record('visuallearning_aireq', $row);
            return false;
        }
        $row->status = 'cancelled';
        $row->errorcode = $page ? 'needsprevious' : 'pagedeleted';
        $row->timemodified = time();
        $DB->update_record('visuallearning_aireq', $row);
        return false;
    }

    /**
     * The exact text sent for a stored request: its body, without local keys (those starting with "_"), and for a
     * card picture that edits another, that picture (always the same bytes: it is pinned by content hash).
     *
     * @param stdClass $row
     * @param \context_module $context
     * @return string
     */
    public static function payload(stdClass $row, \context_module $context): string {
        $sent = json_decode((string)$row->body, true);
        if (!is_array($sent) || !preg_grep('/^_/', array_keys($sent))) {
            return (string)$row->body;
        }
        $reference = (string)($sent['_referenceHash'] ?? '');
        foreach (array_keys($sent) as $key) {
            if (str_starts_with($key, '_')) {
                unset($sent[$key]);
            }
        }
        if ($reference !== '') {
            $file = media::card_by_hash($context, (int)$row->targetid, $reference);
            if (!$file) {
                // The picture it edits is gone; sending without it would ask for something else.
                throw new moodle_exception('cardreferencegone', 'mod_visuallearning');
            }
            $sent['referenceImage'] = base64_encode($file->get_content());
            $sent['referenceMime'] = $file->get_mimetype();
        }
        return json_encode($sent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Why a request that was never sent should not be sent now, or ''.
     *
     * @param stdClass $row
     * @param stdClass $book
     * @param \context_module $context
     * @return string
     */
    protected static function stale(stdClass $row, stdClass $book, \context_module $context): string {
        global $DB;
        $sent = json_decode((string)$row->body, true) ?: [];
        if ($row->operation === self::IMPORT && !empty($sent['_manual']) &&
                $DB->count_records('visuallearning_pages', ['visuallearningid' => $book->id]) >= 20) {
            return 'maxpagesreached';
        }
        if (in_array($row->operation, [self::IMAGE, self::OPTION, self::VOICE, self::CARD], true)) {
            $page = $DB->get_record('visuallearning_pages', ['id' => $row->targetid, 'visuallearningid' => $book->id]);
            if (!$page) {
                return 'pagedeleted';
            }
            if ($row->operation === self::CARD) {
                $link = media::card_chain($context, $book, $page)[(int)$row->part] ?? null;
                if (!$link || $link['prompt'] !== ($sent['prompt'] ?? null)) {
                    return 'changed';
                }
                return $link['file'] !== null ? 'alreadymade' : '';
            }
            if ($row->operation === self::IMAGE) {
                return media::image_prompt($book, $page) === ($sent['prompt'] ?? null) ? '' : 'changed';
            }
            if ($row->operation === self::OPTION) {
                $prompt = media::option_prompts($book, $page)[(int)$row->part] ?? null;
                if ($prompt !== ($sent['prompt'] ?? null)) {
                    return 'changed';
                }
                return media::option_current($context, (int)$page->id, (int)$row->part, $prompt) ? 'alreadymade' : '';
            }
            $ids = array_map('intval', array_keys(visuallearning_get_pages((int)$book->id)));
            $position = array_search((int)$page->id, $ids);
            $text = media::clips($book, $page, (int)$position, count($ids))[$row->part] ?? null;
            $voice = lmslabs::voice_name((string)$book->language, (string)$book->voice);
            if (
                $text !== ($sent['text'] ?? null) || $voice !== ($sent['voice'] ?? null)
                    || media::speed($book) !== ($sent['speed'] ?? null)
            ) {
                return 'changed';
            }
            return media::clip_current($context, $book, $page, $row->part, $text) ? 'alreadymade' : '';
        }
        if ($row->operation === self::COVER) {
            return media::image_prompt($book, null) === ($sent['prompt'] ?? null) ? '' : 'changed';
        }
        return '';
    }

    /**
     * Reads the error code, LMS Labs request id and balance from either error envelope.
     *
     * @param array $headers
     * @param string $body
     * @return array [code (lower case, '' when none), requestid, balance or null]
     */
    public static function read_error(array $headers, string $body): array {
        $data = json_decode($body, true);
        $data = is_array($data) ? $data : [];
        $error = $data['error'] ?? ($data['code'] ?? '');
        $code = is_array($error) ? (string)($error['code'] ?? '') : (is_string($error) ? $error : '');
        $code = strtolower(clean_param($code, PARAM_ALPHANUMEXT));
        $requestid = clean_param((string)($headers['x-request-id'] ?? ''), PARAM_ALPHANUMEXT);
        if ($requestid === '' && is_scalar($data['requestId'] ?? null)) {
            $requestid = clean_param((string)$data['requestId'], PARAM_ALPHANUMEXT);
        }
        $balance = $data['creditsBalance'] ?? ($data['balance'] ?? null);
        return [substr($code, 0, 64), substr($requestid, 0, 64), is_numeric($balance) ? (int)$balance : null];
    }

    /**
     * A charge as LMS Labs reported it, or null when it did not report one (then it is not known).
     *
     * @param mixed $value
     * @return int|null
     */
    protected static function reported($value): ?int {
        return is_numeric($value) && (int)$value >= 0 ? (int)$value : null;
    }

    /**
     * Records the outcome of one send. Delivered media and pages are saved at once.
     *
     * @param stdClass $row
     * @param stdClass $book
     * @param \context_module $context
     * @param int $status
     * @param array $headers
     * @param string $body
     */
    protected static function outcome(
        stdClass $row,
        stdClass $book,
        \context_module $context,
        int $status,
        array $headers,
        string $body
    ): void {
        global $DB;
        [$code, $requestid, $balance] = self::read_error($headers, $body);
        $row->requestid = $requestid !== '' ? $requestid : $row->requestid;
        if ($status !== 200) {
            self::error_outcome($row, $status, $code, $balance);
            return;
        }
        $sent = json_decode((string)$row->body, true) ?: [];
        if (in_array($row->operation, [self::IMAGE, self::COVER, self::OPTION, self::VOICE, self::CARD], true)) {
            $row->charged = self::reported($headers['x-credits-charged'] ?? null);
            $row->balance = isset($headers['x-credits-balance']) && is_numeric($headers['x-credits-balance'])
                ? (int)$headers['x-credits-balance'] : null;
            $type = strtolower(trim(explode(';', (string)($headers['content-type'] ?? ''))[0]));
            if ($row->operation === self::VOICE) {
                $page = $DB->get_record('visuallearning_pages', ['id' => $row->targetid, 'visuallearningid' => $book->id]);
                if (!$page || !media::is_mp3($body)) {
                    // Delivered (and possibly charged) but not usable here; LMS Labs keeps no copy.
                    $row->status = 'lost';
                    $row->errorcode = $page ? 'unusable_audio' : 'pagedeleted';
                    $row->result = json_encode(['contenttype' => substr($type, 0, 100), 'bytes' => strlen($body)]);
                    return;
                }
                $book = $DB->get_record('visuallearning', ['id' => $book->id], '*', MUST_EXIST);
                // Saved under the voice it was made with, so it plays whenever that voice is chosen.
                $voicebook = clone $book;
                $voicebook->language = (string)$sent['locale'];
                $voicebook->voice = preg_replace('/^.*-Chirp3-HD-/', '', (string)$sent['voice']);
                $voicebook->speakingrate = ['slow' => 0.9, 'fast' => 1.1][$sent['speed'] ?? ''] ?? 1.0;
                media::save_clip($context, $voicebook, (int)$page->id, $row->part, (string)$sent['text'], $body);
                $row->status = 'completed';
                $row->errorcode = null;
                return;
            }
            $bytes = self::image_bytes($type, $body);
            $ext = $bytes === null ? '' : media::image_type($bytes);
            $perpage = in_array($row->operation, [self::IMAGE, self::OPTION, self::CARD], true);
            $page = $perpage
                ? $DB->get_record('visuallearning_pages', ['id' => $row->targetid, 'visuallearningid' => $book->id]) : null;
            if ($ext === '' || ($perpage && !$page)) {
                $row->status = 'lost';
                $row->errorcode = $ext === '' ? 'unusable_image' : 'pagedeleted';
                $row->result = json_encode(['contenttype' => substr($type, 0, 100), 'bytes' => strlen($body)]);
                return;
            }
            if ($row->operation === self::OPTION) {
                media::save_option($context, (int)$row->targetid, (int)$row->part, (string)$sent['prompt'], $bytes, $ext);
            } else if ($row->operation === self::CARD) {
                media::save_card($context, (int)$row->targetid, (int)$row->part, (string)$sent['prompt'],
                    (string)($sent['_referenceHash'] ?? ''), $bytes, $ext);
            } else {
                $area = $row->operation === self::COVER ? media::AREA_COVER : media::AREA_IMAGE;
                media::save_image($context, $area, (int)$row->targetid, (string)$sent['prompt'], $bytes, $ext);
            }
            $row->status = 'completed';
            $row->errorcode = null;
            return;
        }

        // Pages (written by LMS Labs AI, or imported): created only after LMS Labs confirmed the charge.
        $data = json_decode($body, true);
        $data = is_array($data) ? $data : [];
        $row->charged = self::reported($data['creditsCharged'] ?? null);
        $row->balance = isset($data['creditsBalance']) && is_numeric($data['creditsBalance']) ? (int)$data['creditsBalance']
            : null;
        if ($row->operation === self::WRITE) {
            $draft = is_array($data['draft'] ?? null) ? $data['draft'] : null;
            $parsed = $draft === null ? null : importer::parse(json_encode($draft), (int)$book->maxpages)[0];
            if ($parsed === null) {
                // Delivered and possibly charged, but not usable here. Kept for LMS Labs support.
                $row->status = 'failed';
                $row->errorcode = 'unusable_draft';
                $row->result = \core_text::substr($body, 0, 65536);
                return;
            }
            $row->result = json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            $parsed = json_decode((string)$row->result, true);
        }
        $transaction = $DB->start_delegated_transaction();
        $book = $DB->get_record('visuallearning', ['id' => $book->id], '*', MUST_EXIST);
        $bundle = $sent['_bundle'] ?? null;
        foreach ($bundle ? $parsed['pages'] : [] as $i => $unused) {
            $page = &$parsed['pages'][$i];
            $slideid = $bundle['slides'][$i]['slideId'] ?? '';
            if ($slideid === '') {
                throw new \moodle_exception('bundleunavailable', 'mod_visuallearning');
            }
            $page['_bundle'] = ['bundleid' => $bundle['bundleId'], 'slideid' => $slideid,
                'bundlesite' => self::sitehash()];
        }
        unset($page);
        if (!empty($sent['_manual'])) {
            importer::append($book, $parsed['pages'][0], $context);
        } else {
            importer::import($book, $parsed, $context);
        }
        $row->status = 'completed';
        $row->errorcode = null;
        $DB->update_record('visuallearning_aireq', $row);
        $transaction->allow_commit();
        rebuild_course_cache((int)$book->course, true);
        // New or replaced pages change what a full grade needs.
        visuallearning_update_grades($DB->get_record('visuallearning', ['id' => $book->id], '*', MUST_EXIST));
    }

    /**
     * Records a non-200 answer (or no answer, status 0).
     *
     * 202: still in progress. 409: the key was used with a different body (never replaced automatically). 410: the
     * result is no longer available (media: lost; it can no longer be delivered). No answer, an unconfirmed
     * settlement or an unexplained server error: unknown, the key is kept for "Check again". Anything else: LMS Labs
     * refused it and charged nothing.
     *
     * @param stdClass $row
     * @param int $status
     * @param string $code
     * @param int|null $balance
     */
    protected static function error_outcome(stdClass $row, int $status, string $code, ?int $balance): void {
        $row->balance = $balance ?? $row->balance;
        $row->errorcode = $code !== '' ? $code : ($status === 0 ? 'network' : 'http_' . $status);
        if ($status === 404) {
            // The route is not live at LMS Labs (yet): nothing was done.
            $row->errorcode = 'not_live';
        }
        $unknown = $status === 0 || in_array($code, self::UNCERTAIN, true)
            || ($status >= 500 && !in_array($code, self::PROVIDER_REFUSED, true));
        $gone = in_array($row->operation, [self::WRITE, self::IMPORT], true) ? 'expired' : 'lost';
        $map = [202 => 'pending', 409 => 'conflict', 410 => $gone];
        $row->status = $map[$status] ?? ($unknown ? 'uncertain' : 'failed');
        if ($status === 409 && $code === 'price_changed') {
            // A free clip has become a paid one: refused before anything was made. The teacher asks again.
            $row->status = 'failed';
        }
    }

    /**
     * The picture in a delivered answer: raw PNG, WebP or JPEG bytes, or a JSON answer carrying base64.
     *
     * @param string $type lower-case content type
     * @param string $body
     * @return string|null
     */
    public static function image_bytes(string $type, string $body): ?string {
        if (strlen($body) > lmslabs::MAX_IMAGE_BYTES * 2) {
            return null;
        }
        if (media::image_type($body) !== '') {
            return strlen($body) <= lmslabs::MAX_IMAGE_BYTES ? $body : null;
        }
        if ($type !== 'application/json' && !str_ends_with($type, '+json')) {
            return null;
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return null;
        }
        foreach (['image', 'imageBase64', 'b64_json', 'data'] as $field) {
            $value = $data[$field] ?? null;
            if (is_array($value)) {
                $value = $value['b64_json'] ?? $value['base64'] ?? $value['data'] ?? null;
            }
            if (!is_string($value) || $value === '') {
                continue;
            }
            $bytes = base64_decode(preg_replace('~^data:image/[a-z]+;base64,~i', '', $value), true);
            if ($bytes !== false && media::image_type($bytes) !== '' && strlen($bytes) <= lmslabs::MAX_IMAGE_BYTES) {
                return $bytes;
            }
        }
        return null;
    }

    /**
     * A request as shown to the teacher (never the key, the credentials or the raw body).
     *
     * @param stdClass $row
     * @param \context_module $context
     * @return array
     */
    public static function export(stdClass $row, \context_module $context): array {
        global $DB;
        $known = $row->charged !== null && $row->charged !== '';
        $a = (object)[
            'what' => get_string('aireq_op_' . $row->operation, 'mod_visuallearning'),
            'requestid' => $row->requestid ?: '-',
            'credits' => (int)$row->maxcredits,
            'charged' => $known ? (int)$row->charged : (int)$row->maxcredits,
            'balance' => $row->balance === null ? '-' : (int)$row->balance,
            'error' => self::error_text((string)$row->errorcode),
        ];
        $status = (string)$row->status;
        if ($status === 'completed') {
            $message = get_string($known ? 'aireq_completed' : 'aireq_completed_unknown', 'mod_visuallearning', $a);
        } else {
            $message = get_string('aireq_' . $status, 'mod_visuallearning', $a);
        }
        $title = $a->what;
        if ($row->targetid) {
            $page = $DB->get_record('visuallearning_pages', ['id' => $row->targetid], 'id, pagenum, title');
            if ($page) {
                $title .= ' · ' . get_string('pagex', 'mod_visuallearning', (int)$page->pagenum) . ' ' . format_string(
                    $page->title,
                    true,
                    ['context' => $context]
                );
            }
        }
        if ($row->operation === self::OPTION) {
            $title .= ' · ' . get_string('optionx', 'mod_visuallearning', chr(65 + (int)$row->part));
        } else if ($row->operation === self::CARD) {
            $title .= ' · ' . get_string('cardx', 'mod_visuallearning', (int)$row->part);
        } else if ($row->part !== '') {
            $title .= ' · ' . get_string('clip_' . $row->part, 'mod_visuallearning');
        }
        return [
            'id' => (int)$row->id,
            'status' => $status,
            'title' => $title,
            'message' => $message,
            'canrecheck' => in_array($status, self::CHECKABLE, true) || self::recoverable($row),
            'candismiss' => in_array($status, self::OPEN, true),
            'time' => userdate((int)$row->timecreated),
        ];
    }

    /**
     * Plain-language reason for an error code.
     *
     * @param string $code
     * @return string
     */
    public static function error_text(string $code): string {
        if ($code === '') {
            return '';
        }
        $sm = get_string_manager();
        return $sm->string_exists('aierr_' . $code, 'mod_visuallearning') ? get_string('aierr_' . $code, 'mod_visuallearning')
            : get_string('aierr_other', 'mod_visuallearning', s($code));
    }
}
