<?php
// This file is part of Moodle - https://moodle.org/
// Moodle is free software under the GNU General Public License v3 or later.
namespace mod_visuallearning\local;
defined('MOODLE_INTERNAL') || die();

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

    /** Normalise settings without changing an old lesson's visual mode. */
    public static function values(\stdClass $book): array {
        $out = [];
        foreach (self::OPTIONS as $field => $values) {
            $out[$field] = in_array($book->{$field} ?? '', $values, true) ? $book->{$field} : $values[0];
        }
        return $out;
    }

    /** Prompt guidance used by both downloaded prompts and fallback old-server requests. */
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
