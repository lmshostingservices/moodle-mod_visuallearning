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

use stdClass;

/**
 * The check question of a page: true or false, pick the picture, match them up, or sort into groups.
 *
 * Stored on the page: qtype, tfstatement (the statement or prompt), tfanswer (true or false), qdata (JSON) and
 * feedback. The right answer is never sent to a learner's browser: answers are checked here, on the server.
 *
 * qdata by type:
 * - pick: {"options": [{"label", "image_prompt", "alt_text"} x 4], "correct": 0-3}
 * - match: {"pairs": [{"left", "right"} x 3-4]}
 * - sort: {"groups": ["…", "…"], "items": [{"text", "group": 0|1} x 4-6]}
 *
 * @package    mod_visuallearning
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class question {
    /** @var string True or false. */
    public const TRUEFALSE = 'truefalse';

    /** @var string Pick the picture (or card) that is right. */
    public const PICK = 'pick';

    /** @var string Match each left item to its partner. */
    public const MATCH = 'match';

    /** @var string Sort items into two groups. */
    public const SORT = 'sort';

    /** @var string[] Every type, in the order they rotate. */
    public const TYPES = [self::TRUEFALSE, self::PICK, self::MATCH, self::SORT];

    /** @var int Longest statement, prompt or feedback. */
    public const MAX_PROMPT = 160;

    /** @var int Longest option, pair, group or item text. */
    public const MAX_LABEL = 60;

    /** @var int Options of a picture pick. */
    public const PICK_OPTIONS = 4;

    /**
     * The question types a lesson allows.
     *
     * @param stdClass $book
     * @return string[]
     */
    public static function allowed(stdClass $book): array {
        $types = array_intersect(self::TYPES, explode(',', (string)($book->questiontypes ?? '')));
        return $types ? array_values($types) : [self::TRUEFALSE];
    }

    /**
     * Normalises a question as written by an AI (LMS Labs or the teacher's own assistant).
     *
     * Accepts the question object, or the older tf_statement / tf_answer fields. A question that cannot be used
     * becomes null (the page then has no question) and is reported as a warning.
     *
     * @param array $page one page of the AI reply
     * @return array|null ['type', 'prompt', 'answer' (bool), 'data' (array), 'feedback']
     */
    public static function from_ai(array $page): ?array {
        $q = $page['question'] ?? null;
        if (!is_array($q)) {
            $statement = self::text($page['tf_statement'] ?? '', self::MAX_PROMPT);
            if ($statement === '') {
                return null;
            }
            return ['type' => self::TRUEFALSE, 'prompt' => $statement, 'answer' => self::to_bool($page['tf_answer'] ?? true),
                'data' => [], 'feedback' => self::text($page['feedback'] ?? '', self::MAX_PROMPT)];
        }
        $type = strtolower(trim((string)($q['type'] ?? '')));
        $type = ['true_false' => self::TRUEFALSE, 'tf' => self::TRUEFALSE, 'multiple_choice' => self::PICK,
            'matching' => self::MATCH, 'category' => self::SORT, 'categorise' => self::SORT, 'categorize' => self::SORT,
            ][$type] ?? $type;
        $prompt = self::text($q['statement'] ?? ($q['prompt'] ?? ''), self::MAX_PROMPT);
        $feedback = self::text($q['feedback'] ?? '', self::MAX_PROMPT);
        $out = ['type' => $type, 'prompt' => $prompt, 'answer' => true, 'data' => [], 'feedback' => $feedback];
        if ($prompt === '') {
            return null;
        }
        if ($type === self::TRUEFALSE) {
            $out['answer'] = self::to_bool($q['answer'] ?? true);
            return $out;
        }
        if ($type === self::PICK) {
            $options = [];
            foreach (array_values((array)($q['options'] ?? [])) as $option) {
                if (!is_array($option)) {
                    $option = ['label' => $option];
                }
                $label = self::text($option['label'] ?? '', self::MAX_LABEL);
                if ($label === '') {
                    continue;
                }
                $options[] = ['label' => $label, 'image_prompt' => self::text($option['image_prompt'] ?? '', 600),
                    'alt_text' => self::text($option['alt_text'] ?? '', 255) ?: $label];
            }
            $correct = $q['correct'] ?? ($q['answer'] ?? null);
            if (
                count($options) !== self::PICK_OPTIONS || !is_numeric($correct) || (int)$correct < 0
                    || (int)$correct >= self::PICK_OPTIONS
            ) {
                return null;
            }
            $out['data'] = ['options' => $options, 'correct' => (int)$correct];
            return $out;
        }
        if ($type === self::MATCH) {
            $pairs = [];
            foreach (array_values((array)($q['pairs'] ?? [])) as $pair) {
                $left = is_array($pair) ? self::text($pair['left'] ?? '', self::MAX_LABEL) : '';
                $right = is_array($pair) ? self::text($pair['right'] ?? '', self::MAX_LABEL) : '';
                if ($left !== '' && $right !== '') {
                    $pairs[] = ['left' => $left, 'right' => $right];
                }
            }
            $lefts = array_map(fn($p) => \core_text::strtolower($p['left']), $pairs);
            $rights = array_map(fn($p) => \core_text::strtolower($p['right']), $pairs);
            if (
                count($pairs) < 3 || count($pairs) > 4 || count(array_unique($lefts)) !== count($pairs)
                    || count(array_unique($rights)) !== count($pairs)
            ) {
                return null;
            }
            $out['data'] = ['pairs' => $pairs];
            return $out;
        }
        if ($type === self::SORT) {
            $groups = array_values(array_filter(array_map(
                fn($g) => self::text(is_array($g) ? ($g['name'] ?? '') : $g, self::MAX_LABEL),
                array_values((array)($q['groups'] ?? []))
            ), fn($g) => $g !== ''));
            $items = [];
            foreach (array_values((array)($q['items'] ?? [])) as $item) {
                $text = is_array($item) ? self::text($item['text'] ?? '', self::MAX_LABEL) : '';
                $group = is_array($item) ? ($item['group'] ?? null) : null;
                if (is_string($group) && !is_numeric($group)) {
                    $found = array_search(self::text($group, self::MAX_LABEL), $groups, true);
                    $group = $found === false ? null : $found;
                }
                if ($text !== '' && is_numeric($group) && (int)$group >= 0 && (int)$group < count($groups)) {
                    $items[] = ['text' => $text, 'group' => (int)$group];
                }
            }
            $used = array_unique(array_column($items, 'group'));
            if (count($groups) !== 2 || count($items) < 4 || count($items) > 6 || count($used) !== 2) {
                return null;
            }
            $out['data'] = ['groups' => $groups, 'items' => $items];
            return $out;
        }
        return null;
    }

    /**
     * The page fields that store a question.
     *
     * @param array|null $q from {@see from_ai()}
     * @return array qtype, tfstatement, tfanswer, qdata, feedback
     */
    public static function to_fields(?array $q): array {
        if ($q === null) {
            return ['qtype' => self::TRUEFALSE, 'tfstatement' => '', 'tfanswer' => 1, 'qdata' => null, 'feedback' => null];
        }
        return [
            'qtype' => $q['type'],
            'tfstatement' => $q['prompt'],
            'tfanswer' => !empty($q['answer']) ? 1 : 0,
            'qdata' => $q['data'] ? json_encode($q['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'feedback' => $q['feedback'] !== '' ? $q['feedback'] : null,
        ];
    }

    /**
     * The question of a stored page, or null when it has none (or it is not usable).
     *
     * @param stdClass $page
     * @return array|null same shape as {@see from_ai()}
     */
    public static function of_page(stdClass $page): ?array {
        $prompt = trim((string)$page->tfstatement);
        if ($prompt === '') {
            return null;
        }
        $type = (string)($page->qtype ?? self::TRUEFALSE) ?: self::TRUEFALSE;
        if ($type === self::TRUEFALSE) {
            return ['type' => $type, 'prompt' => $prompt, 'answer' => (bool)$page->tfanswer, 'data' => [],
                'feedback' => trim((string)($page->feedback ?? ''))];
        }
        $data = json_decode((string)($page->qdata ?? ''), true);
        $q = ['type' => $type, 'statement' => $prompt, 'feedback' => (string)($page->feedback ?? '')];
        $q += is_array($data) ? $data : [];
        return self::from_ai(['question' => $q]);
    }

    /**
     * Whether a lesson shows picture-pick options as pictures (otherwise as text cards).
     *
     * @param stdClass $book
     * @return bool
     */
    public static function pictures(stdClass $book): bool {
        return !empty($book->pickimages);
    }

    /**
     * A stable, unguessable key for a right-hand match item, so the browser never learns the pairing.
     *
     * @param int $pageid
     * @param int $index
     * @return string
     */
    public static function key(int $pageid, int $index): string {
        return substr(hash_hmac('sha256', $pageid . '|' . $index, (string)get_site_identifier()), 0, 12);
    }

    /**
     * The question as a learner's browser receives it: never the right answer.
     *
     * @param array $q
     * @param stdClass $page
     * @param \context $context
     * @param stdClass $book
     * @return array
     */
    public static function for_player(array $q, stdClass $page, \context $context, stdClass $book): array {
        $f = fn(string $text): string => format_string($text, true, ['context' => $context]);
        $out = ['type' => $q['type'], 'prompt' => format_text($q['prompt'], FORMAT_PLAIN, ['context' => $context,
            'para' => false])];
        $pageid = (int)$page->id;
        if ($q['type'] === self::PICK) {
            $out['options'] = [];
            foreach ($q['data']['options'] as $i => $option) {
                $image = self::pictures($book) ? media::option_file($context, $pageid, $i) : null;
                $out['options'][] = ['label' => $f($option['label']), 'alt' => s($option['alt_text']),
                    'image' => visuallearning_file_url($image)];
            }
        } else if ($q['type'] === self::MATCH) {
            $out['lefts'] = array_map(fn($p) => $f($p['left']), $q['data']['pairs']);
            $rights = [];
            foreach ($q['data']['pairs'] as $i => $pair) {
                $rights[self::key($pageid, $i)] = $f($pair['right']);
            }
            // Ordered by key, which is unrelated to the pairing.
            ksort($rights);
            $out['rights'] = [];
            foreach ($rights as $key => $text) {
                $out['rights'][] = ['key' => $key, 'text' => $text];
            }
        } else if ($q['type'] === self::SORT) {
            $out['groups'] = array_map($f, $q['data']['groups']);
            $items = array_map(fn($it) => $f($it['text']), $q['data']['items']);
            // A fixed shuffle so the groups are not in the order they were written.
            $order = array_keys($items);
            usort($order, fn($a, $b) => strcmp(self::key($pageid, 100 + $a), self::key($pageid, 100 + $b)));
            $out['items'] = [];
            foreach ($order as $i) {
                $out['items'][] = ['index' => $i, 'text' => $items[$i]];
            }
        }
        return $out;
    }

    /**
     * Checks a learner's response.
     *
     * Responses by type: truefalse {"value": bool}, pick {"choice": 0-3}, match {"pairs": {"leftindex": "rightkey"}},
     * sort {"items": {"itemindex": groupindex}}.
     *
     * @param array $q
     * @param int $pageid
     * @param array $response
     * @return bool
     */
    public static function check(array $q, int $pageid, array $response): bool {
        switch ($q['type']) {
            case self::TRUEFALSE:
                return isset($response['value']) && (bool)$response['value'] === (bool)$q['answer'];
            case self::PICK:
                return isset($response['choice']) && is_numeric($response['choice'])
                    && (int)$response['choice'] === (int)$q['data']['correct'];
            case self::MATCH:
                $given = (array)($response['pairs'] ?? []);
                foreach (array_keys($q['data']['pairs']) as $i) {
                    if (($given[$i] ?? null) !== self::key($pageid, $i)) {
                        return false;
                    }
                }
                return count($given) === count($q['data']['pairs']);
            case self::SORT:
                $given = (array)($response['items'] ?? []);
                foreach ($q['data']['items'] as $i => $item) {
                    if (!isset($given[$i]) || !is_numeric($given[$i]) || (int)$given[$i] !== (int)$item['group']) {
                        return false;
                    }
                }
                return count($given) === count($q['data']['items']);
        }
        return false;
    }

    /**
     * Text cleaned for one line, at most $max characters.
     *
     * @param mixed $value
     * @param int $max
     * @return string
     */
    public static function text($value, int $max): string {
        if (!is_scalar($value)) {
            return '';
        }
        $value = trim(preg_replace('/\s+/u', ' ', clean_param((string)$value, PARAM_TEXT)) ?? '');
        return importer::shorten($value, $max);
    }

    /**
     * A true/false answer however an AI wrote it.
     *
     * @param mixed $value
     * @return bool
     */
    public static function to_bool($value): bool {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int)$value === 1;
        }
        $value = \core_text::strtolower(trim((string)$value));
        return !in_array($value, ['false', 'f', 'no', 'n', 'falso', 'faux', 'falsch', 'onwaar', 'fałsz', 'เท็จ', 'ผิด'], true);
    }

    /**
     * The edit form values of a page's question.
     *
     * @param stdClass $page
     * @return array
     */
    public static function to_form(stdClass $page): array {
        $q = self::of_page($page);
        $out = ['qtype' => $q === null ? '' : $q['type'], 'tfstatement' => (string)$page->tfstatement,
            'tfanswer' => (int)$page->tfanswer, 'feedback' => (string)($page->feedback ?? '')];
        if ($q === null) {
            return $out;
        }
        if ($q['type'] === self::PICK) {
            foreach ($q['data']['options'] as $i => $option) {
                $out['option' . $i] = $option['label'];
                $out['optionprompt' . $i] = $option['image_prompt'];
            }
            $out['correct'] = $q['data']['correct'];
        } else if ($q['type'] === self::MATCH) {
            foreach ($q['data']['pairs'] as $i => $pair) {
                $out['left' . $i] = $pair['left'];
                $out['right' . $i] = $pair['right'];
            }
        } else if ($q['type'] === self::SORT) {
            foreach ($q['data']['groups'] as $g => $name) {
                $out['group' . $g] = $name;
            }
            foreach ($q['data']['items'] as $i => $item) {
                $out['item' . $i] = $item['text'];
                $out['itemgroup' . $i] = $item['group'];
            }
        }
        return $out;
    }

    /**
     * The question entered in the edit form, or null when there is none or it is incomplete.
     *
     * @param stdClass $data
     * @return array|null
     */
    public static function from_form(stdClass $data): ?array {
        $type = (string)($data->qtype ?? '');
        if (!in_array($type, self::TYPES, true)) {
            return null;
        }
        $q = ['type' => $type, 'statement' => (string)($data->tfstatement ?? ''), 'feedback' => (string)($data->feedback ?? '')];
        if ($type === self::TRUEFALSE) {
            $q['answer'] = !empty($data->tfanswer);
        } else if ($type === self::PICK) {
            $q['options'] = [];
            for ($i = 0; $i < self::PICK_OPTIONS; $i++) {
                $q['options'][] = ['label' => (string)($data->{'option' . $i} ?? ''),
                    'image_prompt' => (string)($data->{'optionprompt' . $i} ?? '')];
            }
            $q['correct'] = (int)($data->correct ?? 0);
        } else if ($type === self::MATCH) {
            $q['pairs'] = [];
            for ($i = 0; $i < 4; $i++) {
                $q['pairs'][] = ['left' => (string)($data->{'left' . $i} ?? ''), 'right' => (string)($data->{'right' . $i} ?? '')];
            }
        } else {
            $q['groups'] = [(string)($data->group0 ?? ''), (string)($data->group1 ?? '')];
            $q['items'] = [];
            for ($i = 0; $i < 6; $i++) {
                $q['items'][] = ['text' => (string)($data->{'item' . $i} ?? ''), 'group' => (int)($data->{'itemgroup' . $i} ?? 0)];
            }
        }
        return self::from_ai(['question' => $q]);
    }

    /**
     * Whether any page of a lesson has a picture-pick question.
     *
     * @param stdClass[] $pages
     * @return bool
     */
    public static function book_has_pick(array $pages): bool {
        foreach ($pages as $page) {
            if ((string)($page->qtype ?? '') === self::PICK && self::of_page($page) !== null) {
                return true;
            }
        }
        return false;
    }

    /**
     * The question of a page as the teacher sees it while checking the pages (with the right answer).
     *
     * @param stdClass $page
     * @param \context $context
     * @param stdClass $book
     * @return string HTML
     */
    public static function teacher_summary(stdClass $page, \context $context, stdClass $book): string {
        $q = self::of_page($page);
        if ($q === null) {
            return \html_writer::tag('p', \html_writer::span(get_string('questionhdr', 'mod_visuallearning'), 'pb-tag') .
                get_string('qtype_none', 'mod_visuallearning'), ['class' => 'text-muted']);
        }
        $right = \html_writer::span('✓', 'vl-right', ['title' => get_string('rightanswer', 'mod_visuallearning')]);
        $html = \html_writer::tag('p', \html_writer::span(
            get_string('qtype_' . $q['type'], 'mod_visuallearning'),
            'pb-tag pb-tag-question'
        ) . s($q['prompt']));
        $list = [];
        if ($q['type'] === self::TRUEFALSE) {
            $list[] = get_string($q['answer'] ? 'true' : 'false', 'mod_visuallearning') . ' ' . $right;
        } else if ($q['type'] === self::PICK) {
            foreach ($q['data']['options'] as $i => $option) {
                $image = self::pictures($book) ? media::option_file($context, (int)$page->id, $i) : null;
                $thumb = $image ? \html_writer::empty_tag('img', ['src' => visuallearning_file_url($image), 'alt' => '',
                    'class' => 'vl-option-thumb']) : '';
                $list[] = $thumb . chr(65 + $i) . '. ' . s($option['label']) . ($i === $q['data']['correct'] ? ' ' . $right : '');
            }
        } else if ($q['type'] === self::MATCH) {
            foreach ($q['data']['pairs'] as $pair) {
                $list[] = s($pair['left']) . ' → ' . s($pair['right']);
            }
        } else {
            foreach ($q['data']['groups'] as $g => $name) {
                $items = array_filter($q['data']['items'], fn($it) => $it['group'] === $g);
                $texts = implode(', ', array_map(fn($it) => s($it['text']), $items));
                $list[] = \html_writer::tag('strong', s($name)) . ': ' . $texts;
            }
        }
        $html .= \html_writer::alist($list, ['class' => 'vl-qlist']);
        if ($q['feedback'] !== '') {
            $html .= \html_writer::tag('p', \html_writer::span(get_string('feedback', 'mod_visuallearning'), 'pb-tag') .
                s($q['feedback']), ['class' => 'text-muted small']);
        }
        return \html_writer::div($html, 'vl-question');
    }
}
