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

use mod_visuallearning\local\ai\lmslabs;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->dirroot . '/mod/visuallearning/lib.php');

/**
 * The illustrations and voiceover clips a lesson needs, and the files that hold them.
 *
 * Every file name carries a hash of what made it, so a file is "current" only when it matches the page as it is now.
 * A new file replaces the old one only after it has been saved, so a failed or unfinished request never removes
 * media learners already have. Only the card contents are read aloud - never page titles or card headings.
 *
 * @package    mod_visuallearning
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class media {
    /** @var string Illustration of a page (item id: page id). */
    public const AREA_IMAGE = 'image';

    /** @var string Cover illustration (item id 0). */
    public const AREA_COVER = 'cover';

    /** @var string Voiceover clips (item id: page id). */
    public const AREA_AUDIO = 'audio';

    /** @var string Pictures of picture-pick options (item id: page id; one file per option). */
    public const AREA_OPTION = 'option';

    /** @var string Aspect ratio of page and cover illustrations. */
    public const ASPECT = '4:3';

    /** @var string Aspect ratio of picture-pick options (square tiles). */
    public const OPTION_ASPECT = '1:1';

    /**
     * The voiceover speed sent to LMS Labs for a lesson's speaking rate.
     *
     * @param stdClass $book
     * @return string slow, normal or fast
     */
    public static function speed(stdClass $book): string {
        $rate = (float)$book->speakingrate;
        return $rate < 0.95 ? 'slow' : ($rate > 1.05 ? 'fast' : 'normal');
    }

    /**
     * The clips of one page, in playing order: part => text (cards, recap sentence, question, feedback).
     *
     * The recap sentence starts with its number in the recap (1 to 4), which the narrator reads in the lesson's
     * language, so no separate number clips are needed.
     *
     * @param stdClass $book
     * @param stdClass $page
     * @param int $position 0-based position of the page in the lesson
     * @param int $total number of pages in the lesson (0: count them)
     * @return array part => text
     */
    public static function clips(stdClass $book, stdClass $page, int $position, int $total = 0): array {
        global $DB;
        $out = [];
        foreach (VISUALLEARNING_CARDS as $card) {
            $text = self::clean((string)$page->{$card . 'text'});
            if ($text !== '') {
                $out[$card] = $text;
            }
        }
        if (!empty($book->enablerecap)) {
            $summary = self::clean((string)$page->summarytext) ?: self::clean((string)$page->concepttext);
            if ($summary !== '') {
                if ($total <= 0) {
                    $total = $DB->count_records('visuallearning_pages', ['visuallearningid' => $book->id]);
                }
                $out['summary'] = visuallearning_recap_number($position, $total) . '. ' . $summary;
            }
        }
        if (!empty($book->enablequiz) && ($q = question::of_page($page)) !== null) {
            // The question is read when it opens; the feedback after a right answer (never before: it explains it).
            $out['question'] = self::clean($q['prompt']);
            $feedback = self::clean($q['feedback']);
            if ($feedback !== '') {
                $out['feedback'] = $feedback;
            }
        }
        return $out;
    }

    /**
     * Text as read aloud: one line, no control characters, at most the longest clip.
     *
     * @param string $text
     * @return string
     */
    public static function clean(string $text): string {
        $text = trim(preg_replace('/\s+/u', ' ', preg_replace('/[^\P{Cc}\t\n]/u', '', $text) ?? '') ?? '');
        return \core_text::substr($text, 0, lmslabs::MAX_CLIP);
    }

    /**
     * The file name of a clip: its part, a hash of its text and a hash of the voice that reads it.
     *
     * @param stdClass $book
     * @param string $part
     * @param string $text
     * @return string
     */
    public static function clip_filename(stdClass $book, string $part, string $text): string {
        $voice = substr(sha1($book->language . '|' . $book->voice . '|' . self::speed($book)), 0, 10);
        return $part . '-' . sha1($text) . '-' . $voice . '.mp3';
    }

    /**
     * The clip learners hear for one part: the current one, or an older one with the same text (read in an
     * earlier voice or speed) until the new one has been made. Null when none matches the text.
     *
     * @param \context $context
     * @param stdClass $book
     * @param stdClass $page
     * @param string $part
     * @param string $text
     * @return \stored_file|null
     */
    public static function clip_file(
        \context $context,
        stdClass $book,
        stdClass $page,
        string $part,
        string $text
    ): ?\stored_file {
        $fs = get_file_storage();
        $name = self::clip_filename($book, $part, $text);
        $current = $fs->get_file($context->id, 'mod_visuallearning', self::AREA_AUDIO, (int)$page->id, '/', $name);
        if ($current) {
            return $current;
        }
        $prefix = $part . '-' . sha1($text) . '-';
        $sort = 'timemodified DESC';
        $files = $fs->get_area_files($context->id, 'mod_visuallearning', self::AREA_AUDIO, (int)$page->id, $sort, false);
        foreach ($files as $file) {
            if (str_starts_with($file->get_filename(), $prefix)) {
                return $file;
            }
        }
        return null;
    }

    /**
     * Whether the current clip of a part exists.
     *
     * @param \context $context
     * @param stdClass $book
     * @param stdClass $page
     * @param string $part
     * @param string $text
     * @return bool
     */
    public static function clip_current(\context $context, stdClass $book, stdClass $page, string $part, string $text): bool {
        $name = self::clip_filename($book, $part, $text);
        return get_file_storage()->file_exists($context->id, 'mod_visuallearning', self::AREA_AUDIO, (int)$page->id, '/', $name);
    }

    /**
     * Saves a delivered clip, then removes the older clips of the same part.
     *
     * @param \context $context
     * @param stdClass $book
     * @param int $pageid
     * @param string $part
     * @param string $text
     * @param string $mp3
     */
    public static function save_clip(
        \context $context,
        stdClass $book,
        int $pageid,
        string $part,
        string $text,
        string $mp3
    ): void {
        $name = self::clip_filename($book, $part, $text);
        self::save($context, self::AREA_AUDIO, $pageid, $name, $mp3, 'audio/mpeg');
        $files = get_file_storage()->get_area_files($context->id, 'mod_visuallearning', self::AREA_AUDIO, $pageid, 'id', false);
        foreach ($files as $file) {
            if (str_starts_with($file->get_filename(), $part . '-') && $file->get_filename() !== $name) {
                $file->delete();
            }
        }
    }

    /**
     * The full illustration prompt of a page, or of the cover (page null).
     *
     * @param stdClass $book
     * @param stdClass|null $page
     * @return string
     */
    public static function image_prompt(stdClass $book, ?stdClass $page): string {
        $scene = $page ? (string)$page->imageprompt : (string)$book->coverprompt;
        if (trim($scene) === '' && $page) {
            $scene = $page->title . '. ' . $page->actiontext;
        }
        return prompt_builder::image_prompt($book, $scene, lmslabs::MAX_PROMPT);
    }

    /**
     * The picture prompts of a page's picture-pick options: option number => full prompt. Empty when the page has
     * no picture question, or the lesson shows options as text cards.
     *
     * @param stdClass $book
     * @param stdClass $page
     * @return array int => string
     */
    public static function option_prompts(stdClass $book, stdClass $page): array {
        if (empty($book->enablequiz) || !question::pictures($book)) {
            return [];
        }
        $q = question::of_page($page);
        if ($q === null || $q['type'] !== question::PICK) {
            return [];
        }
        $out = [];
        foreach ($q['data']['options'] as $i => $option) {
            $scene = trim($option['image_prompt']) !== '' ? $option['image_prompt'] : $option['label'];
            $scene .= ' Square picture, one simple subject, filling the frame.';
            $out[$i] = prompt_builder::image_prompt($book, $scene, lmslabs::MAX_PROMPT);
        }
        return $out;
    }

    /**
     * The file name stem of one option picture.
     *
     * @param int $option
     * @param string $prompt
     * @return string
     */
    public static function option_stem(int $option, string $prompt): string {
        return 'option' . $option . '-' . sha1($prompt);
    }

    /**
     * The picture learners see for one option: the latest one made for it, or null.
     *
     * @param \context $context
     * @param int $pageid
     * @param int $option
     * @return \stored_file|null
     */
    public static function option_file(\context $context, int $pageid, int $option): ?\stored_file {
        $files = self::option_files($context, $pageid, 'timemodified DESC, id DESC');
        foreach ($files as $file) {
            if (str_starts_with($file->get_filename(), 'option' . $option . '-')) {
                return $file;
            }
        }
        return null;
    }

    /**
     * The option pictures of a page.
     *
     * @param \context $context
     * @param int $pageid
     * @param string $sort
     * @return \stored_file[]
     */
    protected static function option_files(\context $context, int $pageid, string $sort = 'id'): array {
        return get_file_storage()->get_area_files($context->id, 'mod_visuallearning', self::AREA_OPTION, $pageid, $sort, false);
    }

    /**
     * Whether the picture made from this prompt exists for an option.
     *
     * @param \context $context
     * @param int $pageid
     * @param int $option
     * @param string $prompt
     * @return bool
     */
    public static function option_current(\context $context, int $pageid, int $option, string $prompt): bool {
        $stem = self::option_stem($option, $prompt) . '.';
        foreach (self::option_files($context, $pageid) as $file) {
            if (str_starts_with($file->get_filename(), $stem)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Saves a delivered option picture, then removes the older pictures of the same option.
     *
     * @param \context $context
     * @param int $pageid
     * @param int $option
     * @param string $prompt
     * @param string $bytes
     * @param string $ext
     */
    public static function save_option(
        \context $context,
        int $pageid,
        int $option,
        string $prompt,
        string $bytes,
        string $ext
    ): void {
        $name = self::option_stem($option, $prompt) . '.' . $ext;
        $mimes = ['png' => 'image/png', 'webp' => 'image/webp', 'jpg' => 'image/jpeg'];
        self::save($context, self::AREA_OPTION, $pageid, $name, $bytes, $mimes[$ext]);
        foreach (self::option_files($context, $pageid) as $file) {
            if (str_starts_with($file->get_filename(), 'option' . $option . '-') && $file->get_filename() !== $name) {
                $file->delete();
            }
        }
    }

    /**
     * The file name stem of an illustration: a hash of its full prompt.
     *
     * @param string $prompt
     * @return string
     */
    public static function image_stem(string $prompt): string {
        return 'image-' . sha1($prompt);
    }

    /**
     * The illustration learners see: the current one, or the latest earlier one until a new one has been made.
     *
     * @param \context $context
     * @param string $area image or cover
     * @param int $itemid
     * @return \stored_file|null
     */
    public static function image_file(\context $context, string $area, int $itemid): ?\stored_file {
        $sort = 'timemodified DESC, id DESC';
        $files = get_file_storage()->get_area_files($context->id, 'mod_visuallearning', $area, $itemid, $sort, false);
        return $files ? reset($files) : null;
    }

    /**
     * Whether the current illustration (made from this prompt) exists.
     *
     * @param \context $context
     * @param string $area
     * @param int $itemid
     * @param string $prompt
     * @return bool
     */
    public static function image_current(\context $context, string $area, int $itemid, string $prompt): bool {
        $stem = self::image_stem($prompt);
        foreach (get_file_storage()->get_area_files($context->id, 'mod_visuallearning', $area, $itemid, 'id', false) as $file) {
            if (str_starts_with($file->get_filename(), $stem . '.')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Saves a delivered illustration, then removes the older ones of the same item.
     *
     * @param \context $context
     * @param string $area
     * @param int $itemid
     * @param string $prompt
     * @param string $bytes
     * @param string $ext png, webp or jpg
     */
    public static function save_image(
        \context $context,
        string $area,
        int $itemid,
        string $prompt,
        string $bytes,
        string $ext
    ): void {
        $name = self::image_stem($prompt) . '.' . $ext;
        $mimes = ['png' => 'image/png', 'webp' => 'image/webp', 'jpg' => 'image/jpeg'];
        self::save($context, $area, $itemid, $name, $bytes, $mimes[$ext]);
        foreach (get_file_storage()->get_area_files($context->id, 'mod_visuallearning', $area, $itemid, 'id', false) as $file) {
            if ($file->get_filename() !== $name) {
                $file->delete();
            }
        }
    }

    /**
     * The picture format of some bytes, or '' when they are not a PNG, WebP or JPEG.
     *
     * @param string $bytes
     * @return string png, webp, jpg or ''
     */
    public static function image_type(string $bytes): string {
        if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            return 'png';
        }
        if (str_starts_with($bytes, "\xff\xd8\xff")) {
            return 'jpg';
        }
        if (substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP') {
            return 'webp';
        }
        return '';
    }

    /**
     * Whether bytes are MP3 audio (an ID3 tag or an MPEG audio frame) of at most 4 MB.
     *
     * @param string $bytes
     * @return bool
     */
    public static function is_mp3(string $bytes): bool {
        if ($bytes === '' || strlen($bytes) > 4 * 1048576) {
            return false;
        }
        $frame = strlen($bytes) > 1 && ord($bytes[0]) === 255 && (ord($bytes[1]) & 0xe0) === 0xe0;
        return substr($bytes, 0, 3) === 'ID3' || $frame;
    }

    /**
     * Stores one file (replacing a file of the same name).
     *
     * @param \context $context
     * @param string $area
     * @param int $itemid
     * @param string $name
     * @param string $content
     * @param string $mime
     */
    protected static function save(
        \context $context,
        string $area,
        int $itemid,
        string $name,
        string $content,
        string $mime
    ): void {
        $fs = get_file_storage();
        if ($old = $fs->get_file($context->id, 'mod_visuallearning', $area, $itemid, '/', $name)) {
            $old->delete();
        }
        $fs->create_file_from_string(
            ['contextid' => $context->id, 'component' => 'mod_visuallearning', 'filearea' => $area, 'itemid' => $itemid,
                'filepath' => '/', 'filename' => $name, 'mimetype' => $mime],
            $content
        );
    }

    /**
     * What a lesson still needs (or everything, for a full remake), as request plans.
     *
     * @param stdClass $book
     * @param \context $context
     * @param string $scope missing or all
     * @param int $pageid 0 for every page
     * @param string[] $kinds image, option, cover and/or voice
     * @return array list of ['operation', 'targetid', 'part', 'text' (voice) or 'prompt' (image, option, cover)]
     */
    public static function plan(stdClass $book, \context $context, string $scope, int $pageid, array $kinds): array {
        $out = [];
        $position = 0;
        $pages = visuallearning_get_pages((int)$book->id);
        foreach ($pages as $page) {
            $index = $position++;
            if ($pageid && (int)$page->id !== $pageid) {
                continue;
            }
            if (in_array('image', $kinds, true)) {
                $prompt = self::image_prompt($book, $page);
                if ($scope === 'all' || !self::image_current($context, self::AREA_IMAGE, (int)$page->id, $prompt)) {
                    $out[] = ['operation' => 'image', 'targetid' => (int)$page->id, 'part' => '', 'prompt' => $prompt];
                }
            }
            if (in_array('option', $kinds, true)) {
                foreach (self::option_prompts($book, $page) as $option => $prompt) {
                    if ($scope === 'all' || !self::option_current($context, (int)$page->id, $option, $prompt)) {
                        $out[] = ['operation' => 'option', 'targetid' => (int)$page->id, 'part' => (string)$option,
                            'prompt' => $prompt];
                    }
                }
            }
            if (in_array('voice', $kinds, true)) {
                foreach (self::clips($book, $page, $index, count($pages)) as $part => $text) {
                    if ($scope === 'all' || !self::clip_current($context, $book, $page, $part, $text)) {
                        $out[] = ['operation' => 'voice', 'targetid' => (int)$page->id, 'part' => $part, 'text' => $text];
                    }
                }
            }
        }
        if (in_array('cover', $kinds, true) && !$pageid && trim((string)$book->coverprompt) !== '') {
            $prompt = self::image_prompt($book, null);
            if ($scope === 'all' || !self::image_current($context, self::AREA_COVER, 0, $prompt)) {
                $out[] = ['operation' => 'cover', 'targetid' => 0, 'part' => '', 'prompt' => $prompt];
            }
        }
        return $out;
    }

    /**
     * Brings the stored media statuses of a lesson up to date with its files and LMS Labs requests.
     *
     * @param stdClass $book
     * @param \context $context
     */
    public static function refresh(stdClass $book, \context $context): void {
        global $DB;
        $open = [];
        $failed = [];
        foreach ($DB->get_records('visuallearning_aireq', ['visuallearningid' => $book->id], 'id ASC') as $row) {
            $kind = ['voice' => 'audio', 'option' => 'image'][$row->operation] ?? $row->operation;
            $key = $kind . ':' . $row->targetid;
            if (in_array($row->status, ['pending', 'uncertain'], true)) {
                $open[$key] = true;
            }
            $failed[$key] = in_array($row->status, ['failed', 'lost', 'conflict', 'expired'], true);
        }
        $state = function (bool $current, string $key) use ($open, $failed): int {
            if ($current) {
                return VISUALLEARNING_MEDIA_READY;
            }
            if (!empty($open[$key])) {
                return VISUALLEARNING_MEDIA_QUEUED;
            }
            return !empty($failed[$key]) ? VISUALLEARNING_MEDIA_FAILED : VISUALLEARNING_MEDIA_PENDING;
        };
        $position = 0;
        $pages = visuallearning_get_pages((int)$book->id);
        foreach ($pages as $page) {
            $index = $position++;
            $prompt = self::image_prompt($book, $page);
            $pictures = self::image_current($context, self::AREA_IMAGE, (int)$page->id, $prompt);
            foreach (self::option_prompts($book, $page) as $option => $optionprompt) {
                $pictures = $pictures && self::option_current($context, (int)$page->id, $option, $optionprompt);
            }
            $image = $state($pictures, 'image:' . $page->id);
            $allclips = true;
            foreach (self::clips($book, $page, $index, count($pages)) as $part => $text) {
                $allclips = $allclips && self::clip_current($context, $book, $page, $part, $text);
            }
            $audio = $state($allclips, 'audio:' . $page->id);
            if ((int)$page->imagestatus !== $image || (int)$page->audiostatus !== $audio) {
                $DB->update_record('visuallearning_pages', (object)['id' => $page->id, 'imagestatus' => $image,
                    'audiostatus' => $audio]);
            }
        }
        $cover = trim((string)$book->coverprompt) === '' ? VISUALLEARNING_MEDIA_PENDING
            : $state(self::image_current($context, self::AREA_COVER, 0, self::image_prompt($book, null)), 'cover:0');
        if ((int)$book->coverstatus !== $cover) {
            $DB->set_field('visuallearning', 'coverstatus', $cover, ['id' => $book->id]);
        }
    }
}
