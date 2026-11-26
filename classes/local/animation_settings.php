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
 * Persistent animation choices. Legacy preserves existing lesson behaviour.
 *
 * @package mod_visuallearning
 * @copyright 2026 LMS Hosting Services
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class animation_settings {
    /** @var array Valid setting values. */
    public const OPTIONS = [
        'animationmode' => ['legacy', 'automatic', 'animated', 'static'],
        'animationcomplexity' => ['standard', 'simple', 'detailed'],
        'animationpacing' => ['normal', 'slow', 'fast'],
    ];

    /**
     * Normalise settings without changing an old lesson's visual mode.
     *
     * @param \stdClass $book Lesson record
     * @return array Setting values
     */
    public static function values(\stdClass $book): array {
        $out = [];
        foreach (self::OPTIONS as $field => $values) {
            $out[$field] = in_array($book->{$field} ?? '', $values, true) ? $book->{$field} : $values[0];
        }
        return $out;
    }

    /**
     * Prompt guidance used by both downloaded prompts and fallback old-server requests.
     *
     * @param \stdClass $book Lesson record
     * @return string
     */
    public static function brief(\stdClass $book): string {
        $settings = self::values($book);
        if ($settings['animationmode'] === 'legacy') {
            return '';
        }
        return "Animation preference: {$settings['animationmode']}; complexity: {$settings['animationcomplexity']}; "
            . "pacing: {$settings['animationpacing']}. Teaching content determines subjects and mechanisms. "
            . "Art direction controls appearance, not an unrelated lesson topic. Never invent measurements. "
            . "Use static fallback if the explanation cannot be represented accurately.";
    }
}
