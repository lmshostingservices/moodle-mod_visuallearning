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
 * Plain-text, three-card drawing plans for the svg-1 contract.
 *
 * @package    mod_visuallearning
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scene_plan {
    /**
     * Validate and allowlist a plan; old pages may have no plan.
     *
     * @param mixed $value JSON or array.
     * @return array|null
     */
    public static function clean($value): ?array {
        if (is_string($value)) {
            if (strlen($value) > 16000) {
                return null;
            }
            $value = json_decode($value, true);
        }
        if (!is_array($value) || !is_array($value['stages'] ?? null)
                || array_keys($value['stages']) !== [0, 1, 2] || !is_bool($value['realtime'] ?? null)) {
            return null;
        }
        $out = [];
        foreach (['layout', 'all', 'colours', 'accuracy', 'exclude'] as $key) {
            $text = self::text($value[$key] ?? null, 600);
            if ($text === null || ($text === '' && $key !== 'exclude')) {
                return null;
            }
            $out[$key] = $text;
        }
        $out['stages'] = [];
        foreach ($value['stages'] as $stage) {
            if (!is_array($stage) || !is_array($stage['labels'] ?? null)
                    || array_keys($stage['labels']) !== array_keys(array_values($stage['labels']))
                    || count($stage['labels']) > 4) {
                return null;
            }
            $shows = self::text($stage['shows'] ?? null, 600);
            $moves = self::text($stage['moves'] ?? null, 600);
            if ($shows === null || $shows === '' || $moves === null || $moves === '') {
                return null;
            }
            $labels = [];
            foreach ($stage['labels'] as $label) {
                $label = self::text($label, 32);
                if ($label === null || $label === '' || count(preg_split('/\s+/u', $label)) > 4) {
                    return null;
                }
                $labels[] = $label;
            }
            $out['stages'][] = ['shows' => $shows, 'moves' => $moves, 'labels' => $labels];
        }
        $out['realtime'] = $value['realtime'];
        return $out;
    }

    /**
     * Normalise plain text without truncating a meaning-bearing drawing instruction.
     *
     * @param mixed $value Text.
     * @param int $max Character limit.
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
     * Encode only validated data for storage.
     *
     * @param mixed $value Plan.
     * @return string|null
     */
    public static function json($value): ?string {
        $plan = self::clean($value);
        return $plan === null ? null : json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * The supplied card-writing brief, unchanged except for lesson placeholders.
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
        return str_replace('{$language}', $language, $brief);
    }

    /**
     * A compact drawing brief; no plan means the existing cards-only path.
     *
     * @param mixed $value Plan.
     * @return string
     */
    public static function drawing_brief($value): string {
        $plan = self::clean($value);
        if ($plan === null) {
            return '';
        }
        $lines = ['SCENE PLAN (drawing instructions; the three cards remain the source of facts):',
            'LAYOUT: ' . $plan['layout']];
        foreach ($plan['stages'] as $i => $stage) {
            $lines[] = 'STAGE ' . ($i + 1) . ' SHOWS: ' . $stage['shows'];
            $lines[] = 'STAGE ' . ($i + 1) . ' MOVES: ' . $stage['moves'];
            $lines[] = 'STAGE ' . ($i + 1) . ' LABELS: ' . implode(' | ', $stage['labels']);
        }
        foreach (['all', 'colours', 'accuracy', 'exclude'] as $key) {
            $lines[] = strtoupper($key) . ': ' . $plan[$key];
        }
        $lines[] = 'REALTIME: ' . ($plan['realtime'] ? 'true' : 'false');
        return implode("\n", $lines);
    }
}
