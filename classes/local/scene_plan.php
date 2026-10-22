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

namespace mod_visuallearning\local;

/**
 * Scene plans for animated scenes (contract svg-1): what Claude draws on a page, written by the page writer.
 *
 * A page's plan ("scene") is compact: a type, a layout, three stages (one per card: what appears and the one thing
 * that moves, plus up to three labels), what must be right, and whether the scene plays at real speed. A page whose
 * concept has nothing that moves gets "none": it keeps its picture and no scene is bought for it.
 *
 * The lesson's "scene_style" (a palette, how recurring things are drawn and the subject) is written once, so every
 * page's scene matches. The subject picks one short rule pack that is added to each scene's brief.
 *
 * Plans written by 1.7.2 (shows, moves, all, colours, accuracy, exclude) are read too.
 *
 * @package    mod_visuallearning
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scene_plan {
    /** @var string Stored for a page whose concept has nothing to animate. */
    public const NONE = 'none';

    /** @var string[] Scene types. */
    public const TYPES = ['flow', 'mechanism', 'cycle', 'cause_effect', 'steps', 'compare', 'graph'];

    /** @var int Longest stage, layout or must line (characters). */
    public const MAX_LINE = 240;

    /** @var int Labels per stage. */
    public const MAX_LABELS = 3;

    /** @var int Longest label (characters). */
    public const MAX_LABEL = 32;

    /**
     * Subject rule packs, added to a scene's brief as "SUBJECT RULES". Keep each to about 80 tokens.
     *
     * @var array
     */
    public const SUBJECTS = [
        'biology' => 'Front views use anatomical position: the body\'s right is on the viewer\'s left. Show flow in its '
            . 'real one-way direction and valves letting blood through one way only. Blue for low-oxygen and red for '
            . 'oxygen-rich blood. No gore. Organs in correct relative positions; simplify shapes, never invent parts.',
        'physics' => 'Label conventional current from + to - with the direction stated. Keep waveforms, phases and '
            . 'vectors mathematically consistent, vectors drawn from one origin. Label axes with the quantity, and the '
            . 'unit only where the content gives it. Show energy changes with consistent colours (heat = orange).',
        'chemistry' => 'Conserve atoms: the same atoms before and after a reaction. Standard element colours (carbon '
            . 'dark grey, oxygen red, hydrogen white, nitrogen blue, chlorine green). Show states by particle spacing '
            . 'and motion. Arrows show what moves, not decoration.',
        'mechanical' => 'Linked parts move together correctly: meshing gears turn opposite ways at speeds set by their '
            . 'size, belts turn both pulleys the same way, pistons follow their crank. Draw forces as arrows from where '
            . 'they act. Never add dimensions the content does not give.',
        'earth' => 'Cycles close back on themselves; arrows show the real direction of movement. Scale is not literal: '
            . 'add a small "not to scale" caption when sizes could mislead. Show the energy source (sun, heat) where it '
            . 'drives the process.',
        'maths' => 'Graphs have labelled axes, an origin and even scales; plot only values the content gives. Build one '
            . 'step per stage: draw, then transform, then compare. Keep notation standard and large enough to read.',
        'safety' => 'Show the steps in the correct order and the correct equipment. Where signs appear use AS 1319 '
            . 'colours: red = prohibition, danger or fire equipment, yellow = warning, blue = mandatory, green = '
            . 'emergency or safe condition. Compare controls in hierarchy-of-controls order.',
        'business' => 'Show roles as simple labelled icons or boxes, and documents, money or information as tokens '
            . 'moving between them. Decisions are forks with short labels. Keep the flow left to right or as a loop.',
    ];

    /**
     * A clean plan, the NONE marker, or null when it is not usable. Accepts the 1.8 form and the 1.7.2 form.
     *
     * @param mixed $value JSON, array, "none" or null (a page with nothing to animate, when written as null).
     * @return array|string|null
     */
    public static function clean($value) {
        if ($value === self::NONE) {
            return self::NONE;
        }
        if (is_string($value)) {
            if ($value === '' || strlen($value) > 16000) {
                return null;
            }
            $decoded = json_decode($value, true);
            if ($decoded === self::NONE) {
                return self::NONE;
            }
            $value = $decoded;
        }
        if (!is_array($value) || !is_array($value['stages'] ?? null) || count($value['stages']) !== 3
                || array_keys($value['stages']) !== [0, 1, 2]) {
            return null;
        }
        $out = [];
        $type = (string)($value['type'] ?? '');
        if (in_array($type, self::TYPES, true)) {
            $out['type'] = $type;
        }
        $layout = self::text($value['layout'] ?? null, self::MAX_LINE * 2);
        if ($layout === null || $layout === '') {
            return null;
        }
        $out['layout'] = $layout;
        $out['stages'] = [];
        foreach ($value['stages'] as $stage) {
            if (!is_array($stage)) {
                return null;
            }
            // 1.8 writes "do"; 1.7.2 wrote "shows" and "moves".
            $do = $stage['do'] ?? trim(((string)($stage['shows'] ?? '')) . ' ' . ((string)($stage['moves'] ?? '')));
            $do = self::text($do, self::MAX_LINE * 2);
            if ($do === null || $do === '') {
                return null;
            }
            $labels = [];
            foreach (array_slice(array_values((array)($stage['labels'] ?? [])), 0, self::MAX_LABELS + 1) as $label) {
                $label = self::text($label, self::MAX_LABEL);
                if ($label === null || $label === '' || count(preg_split('/\s+/u', $label)) > 5) {
                    return null;
                }
                $labels[] = $label;
            }
            $out['stages'][] = ['do' => $do, 'labels' => array_slice($labels, 0, self::MAX_LABELS)];
        }
        $must = self::text($value['must'] ?? ($value['accuracy'] ?? ''), self::MAX_LINE * 2);
        if ($must === null) {
            return null;
        }
        if ($must !== '') {
            $out['must'] = $must;
        }
        // A colour code written per page (1.7.2) is kept for that page.
        $colours = self::text($value['colours'] ?? '', self::MAX_LINE * 2);
        if ($colours !== null && $colours !== '') {
            $out['colours'] = $colours;
        }
        if (!empty($value['realtime'])) {
            $out['realtime'] = true;
        }
        return $out;
    }

    /**
     * Plain text, whitespace folded; null when not text or too long.
     *
     * @param mixed $value
     * @param int $max characters
     * @return string|null
     */
    private static function text($value, int $max): ?string {
        if (!is_string($value)) {
            return null;
        }
        $value = trim(preg_replace('/\s+/u', ' ', clean_param($value, PARAM_TEXT)) ?? '');
        return \core_text::strlen($value) <= $max ? $value : null;
    }

    /**
     * What to store for a page: the plan's JSON, "none", or null (no plan: drawn from the cards alone).
     *
     * @param mixed $value
     * @return string|null
     */
    public static function json($value): ?string {
        $plan = self::clean($value);
        if ($plan === self::NONE) {
            return self::NONE;
        }
        return $plan === null ? null : json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Whether a page was planned with nothing to animate (it keeps its picture; no scene is bought).
     *
     * @param \stdClass $page
     * @return bool
     */
    public static function is_none(\stdClass $page): bool {
        return trim((string)($page->sceneplan ?? '')) === self::NONE;
    }

    /**
     * The lesson-wide scene style: palette, recurring rules and subject, cleaned.
     *
     * @param mixed $value JSON or array
     * @return array|null ['palette' => string, 'recurring' => string, 'subject' => key or '']
     */
    public static function clean_style($value): ?array {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        if (!is_array($value)) {
            return null;
        }
        $palette = self::text($value['palette'] ?? '', 300) ?? '';
        $recurring = self::text($value['recurring'] ?? '', 300) ?? '';
        $subject = (string)($value['subject'] ?? '');
        $subject = isset(self::SUBJECTS[$subject]) ? $subject : '';
        if ($palette === '' && $recurring === '' && $subject === '') {
            return null;
        }
        return ['palette' => $palette, 'recurring' => $recurring, 'subject' => $subject];
    }

    /**
     * The lesson-wide scene style as stored JSON, or null.
     *
     * @param mixed $value
     * @return string|null
     */
    public static function style_json($value): ?string {
        $style = self::clean_style($value);
        return $style === null ? null : json_encode($style, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * STEP 5 of the prompt file for scene lessons.
     *
     * @param string $language Learner-facing language.
     * @param bool $story Whether the lesson uses workplace stories.
     * @return string
     */
    public static function brief(string $language, bool $story): string {
        $root = __DIR__ . '/../../schema/';
        $brief = file_get_contents($root . 'scene-plan-generation.txt');
        if ($story) {
            $brief .= "\n\n" . file_get_contents($root . 'scene-plan-story.txt');
        }
        return str_replace(['{$language}', '{$subjects}'], [$language, implode(', ', array_keys(self::SUBJECTS))], $brief);
    }

    /**
     * The compact SCENE PLAN section of a scene's brief ('' when the page has no plan).
     *
     * @param mixed $value the page's plan
     * @param array|null $style the lesson's scene style
     * @return string
     */
    public static function drawing_brief($value, ?array $style = null): string {
        $plan = self::clean($value);
        $lines = [];
        if (is_array($plan)) {
            $lines[] = 'SCENE PLAN (follow it exactly; the cards stay the source of facts)';
            if (!empty($plan['type'])) {
                $lines[] = 'TYPE: ' . $plan['type'];
            }
            $lines[] = 'LAYOUT: ' . $plan['layout'];
            foreach ($plan['stages'] as $i => $stage) {
                $lines[] = 'STAGE ' . ($i + 1) . ': ' . $stage['do']
                    . ($stage['labels'] ? ' | LABELS: ' . implode('; ', $stage['labels']) : '');
            }
            if (!empty($plan['must'])) {
                $lines[] = 'MUST BE RIGHT: ' . $plan['must'];
            }
            if (!empty($plan['colours'])) {
                $lines[] = 'COLOURS: ' . $plan['colours'];
            }
            if (!empty($plan['realtime'])) {
                $lines[] = 'REAL SPEED: yes (animate at the true rate and set data-realtime="true")';
            }
        }
        if ($style) {
            if ($style['palette'] !== '') {
                $lines[] = 'PALETTE (same on every page of the lesson): ' . $style['palette'];
            }
            if ($style['recurring'] !== '') {
                $lines[] = 'RECURRING (draw these the same on every page): ' . $style['recurring'];
            }
            if ($style['subject'] !== '') {
                $lines[] = 'SUBJECT RULES: ' . self::SUBJECTS[$style['subject']];
            }
        }
        return implode("\n", $lines);
    }

    /**
     * A plan as pretty JSON for the page editor.
     *
     * @param \stdClass $page
     * @return string
     */
    public static function for_editor(\stdClass $page): string {
        $plan = self::clean($page->sceneplan ?? null);
        if ($plan === self::NONE) {
            return self::NONE;
        }
        return $plan === null ? '' : json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
