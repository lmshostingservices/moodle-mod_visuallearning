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

/**
 * Validates and imports the JSON a teacher pastes back from ChatGPT.
 *
 * @package    mod_visuallearning
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class importer {
    /**
     * Parse and validate pasted text.
     *
     * @param string $raw
     * @param int $maxpages
     * @return array [array|null $data, string[] $errors, string[] $warnings]
     */
    public static function parse(string $raw, int $maxpages): array {
        $errors = [];
        $warnings = [];
        $json = self::extract_json($raw);
        if ($json === null) {
            return [null, [get_string('import_nojson', 'mod_visuallearning')], []];
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            // Common ChatGPT slip: trailing commas.
            $data = json_decode(preg_replace('/,\s*([\]}])/', '$1', $json), true);
        }
        if (!is_array($data)) {
            return [null, [get_string('import_invalidjson', 'mod_visuallearning', json_last_error_msg())], []];
        }
        if (empty($data['pages']) || !is_array($data['pages'])) {
            return [null, [get_string('import_nopages', 'mod_visuallearning')], []];
        }

        $pages = [];
        foreach (array_values($data['pages']) as $i => $p) {
            $n = $i + 1;
            if (!is_array($p)) {
                $errors[] = get_string('import_badpage', 'mod_visuallearning', $n);
                continue;
            }
            $page = [
                'title' => self::clean($p['title'] ?? ''),
                'concept' => self::clean($p['concept'] ?? ''),
                'action' => self::clean($p['action'] ?? ''),
                'outcome' => self::clean($p['outcome'] ?? ''),
                'image_prompt' => self::clean($p['image_prompt'] ?? ''),
                'alt_text' => self::clean($p['alt_text'] ?? ''),
                'summary' => self::clean($p['summary'] ?? ''),
                'tf_statement' => self::clean($p['tf_statement'] ?? ''),
                'tf_answer' => self::to_bool($p['tf_answer'] ?? true),
            ];
            foreach (['title', 'concept', 'action', 'outcome', 'image_prompt'] as $key) {
                if ($page[$key] === '') {
                    $errors[] = get_string('import_missingfield', 'mod_visuallearning', (object)['page' => $n, 'field' => $key]);
                }
            }
            if ($page['alt_text'] === '') {
                $page['alt_text'] = $page['title'];
            }
            if ($page['summary'] === '') {
                $page['summary'] = $page['concept'];
            }
            if ($page['tf_statement'] === '') {
                $warnings[] = get_string('import_notf', 'mod_visuallearning', $n);
            }
            $page['title'] = \core_text::substr($page['title'], 0, 255);
            foreach (['concept', 'action', 'outcome', 'summary', 'tf_statement'] as $key) {
                // Each card is read as one voiceover clip, which has a maximum length.
                if (\core_text::strlen($page[$key]) > \mod_visuallearning\local\ai\lmslabs::MAX_CLIP) {
                    $page[$key] = self::shorten($page[$key], \mod_visuallearning\local\ai\lmslabs::MAX_CLIP);
                    $warnings[] = get_string('import_shortened', 'mod_visuallearning', (object)['page' => $n, 'field' => $key]);
                }
            }
            $pages[] = $page;
        }
        if (count($pages) > $maxpages) {
            $warnings[] = get_string(
                'import_truncated',
                'mod_visuallearning',
                (object)['found' => count($pages), 'max' => $maxpages]
            );
            $pages = array_slice($pages, 0, $maxpages);
        }

        $chars = [];
        if (!empty($data['characters']) && is_array($data['characters'])) {
            foreach ($data['characters'] as $c) {
                if (is_array($c) && !empty($c['name'])) {
                    $chars[] = ['name' => self::clean($c['name']), 'description' => self::clean($c['description'] ?? '')];
                }
            }
        }

        $result = [
            'book_title' => self::clean($data['book_title'] ?? ''),
            'industry' => self::clean($data['industry'] ?? ''),
            'subindustry' => self::clean($data['subindustry'] ?? ''),
            'style_guide' => self::clean($data['style_guide'] ?? ''),
            'characters' => $chars,
            'cover_image_prompt' => self::clean($data['cover_image_prompt'] ?? ''),
            'pages' => $pages,
        ];
        return [$errors ? null : $result, $errors, $warnings];
    }

    /**
     * Replace the book's pages with the imported ones.
     *
     * @param \stdClass $book
     * @param array $data Parsed data from parse().
     * @param \context_module $context
     */
    public static function import(\stdClass $book, array $data, \context_module $context): void {
        global $DB;
        $transaction = $DB->start_delegated_transaction();

        $DB->delete_records('visuallearning_progress', ['visuallearningid' => $book->id]);
        $DB->delete_records('visuallearning_pages', ['visuallearningid' => $book->id]);
        $fs = get_file_storage();
        foreach (['image', 'audio', 'cover'] as $area) {
            $fs->delete_area_files($context->id, 'mod_visuallearning', $area);
        }

        $update = (object)[
            'id' => $book->id,
            'styleguide' => $data['style_guide'],
            'characters' => json_encode($data['characters']),
            'coverprompt' => $data['cover_image_prompt'],
            'coverstatus' => VISUALLEARNING_MEDIA_PENDING,
            'covererror' => null,
            'timemodified' => time(),
        ];
        if (trim((string)$book->industry) === '' && $data['industry'] !== '') {
            $update->industry = \core_text::substr($data['industry'], 0, 255);
        }
        if (trim((string)$book->subindustry) === '' && $data['subindustry'] !== '') {
            $update->subindustry = \core_text::substr($data['subindustry'], 0, 255);
        }
        $DB->update_record('visuallearning', $update);

        $n = 1;
        foreach ($data['pages'] as $p) {
            $DB->insert_record(
                'visuallearning_pages',
                (object)[
                    'visuallearningid' => $book->id,
                    'pagenum' => $n++,
                    'title' => $p['title'],
                    'concepttext' => $p['concept'],
                    'actiontext' => $p['action'],
                    'outcometext' => $p['outcome'],
                    'imageprompt' => $p['image_prompt'],
                    'alttext' => $p['alt_text'],
                    'summarytext' => $p['summary'],
                    'tfstatement' => $p['tf_statement'],
                    'tfanswer' => $p['tf_answer'] ? 1 : 0,
                    'imagestatus' => VISUALLEARNING_MEDIA_PENDING,
                    'audiostatus' => VISUALLEARNING_MEDIA_PENDING,
                    'timemodified' => time(),
                ],
            );
        }
        $transaction->allow_commit();
    }

    /**
     * Pull the JSON object out of a ChatGPT reply (handles code fences and chatter).
     *
     * @param string $raw
     * @return string|null
     */
    public static function extract_json(string $raw): ?string {
        $raw = trim($raw);
        // Strip a UTF-8 BOM and smart quotes ChatGPT sometimes uses around keys.
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        if (preg_match('/\x60{3}(?:json)?\s*(\{.*\})\s*\x60{3}/s', $raw, $m)) {
            return $m[1];
        }
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        return substr($raw, $start, $end - $start + 1);
    }

    /**
     * Read a true/false answer however ChatGPT wrote it.
     *
     * @param mixed $value
     * @return bool
     */
    protected static function to_bool($value): bool {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int)$value === 1;
        }
        $value = \core_text::strtolower(trim((string)$value));
        return !in_array($value, ['false', 'f', 'no', 'n', 'falso', 'faux', 'falsch', 'onwaar', 'fałsz'], true);
    }

    /**
     * Shortens text to at most $max characters, at the end of a sentence where possible, else at a space.
     *
     * @param string $text
     * @param int $max
     * @return string
     */
    public static function shorten(string $text, int $max): string {
        if (\core_text::strlen($text) <= $max) {
            return $text;
        }
        $cut = \core_text::substr($text, 0, $max);
        if (preg_match('/^(.*[.!?…。！？])\s/us', $cut . ' ', $m) && \core_text::strlen($m[1]) >= $max / 3) {
            return trim($m[1]);
        }
        $space = \core_text::strrpos(\core_text::substr($cut, 0, $max - 1), ' ');
        return rtrim($space ? \core_text::substr($cut, 0, $space) : \core_text::substr($cut, 0, $max - 1)) . '…';
    }

    /**
     * Clean a string value.
     *
     * @param mixed $value
     * @return string
     */
    protected static function clean($value): string {
        if (!is_scalar($value)) {
            return '';
        }
        $value = clean_param((string)$value, PARAM_TEXT);
        return trim(preg_replace('/\s+/u', ' ', $value));
    }
}
