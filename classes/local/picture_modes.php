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
 * How the pictures of a lesson's pages work:
 *
 * - single: one picture per page, as always;
 * - sequence: one picture per card, made in a chain so each one builds on the one before (card 2 edits card 1's
 *   picture, card 3 edits card 2's); the page picture stays the cover, recap and question picture;
 * - animated: an animated diagram the plugin draws itself (SVG) from a small specification the AI writes, building
 *   up card by card; pages whose concept fits no diagram keep their picture;
 * - svg: one animated scene per page, drawn as SVG by Claude through LMS Labs (or pasted from the teacher's own AI);
 *   it holds a stage for each card, plays at a slow teaching pace timed to each card's voiceover, and can be replayed
 *   at normal speed once the page has been heard. The page picture stays the cover, recap and question picture.
 *
 * @package    mod_visuallearning
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class picture_modes {
    /** @var string One picture per page. */
    public const SINGLE = 'single';

    /** @var string One picture per card, each building on the one before. */
    public const SEQUENCE = 'sequence';

    /** @var string Animated diagrams drawn by the plugin. */
    public const ANIMATED = 'animated';

    /** @var string One AI-drawn animated SVG scene per page, staged card by card. */
    public const SVG = 'svg';

    /** @var string[] Every mode, in the order teachers see them. */
    public const MODES = [self::SINGLE, self::SEQUENCE, self::ANIMATED, self::SVG];

    /** @var int Pictures in a sequence: one per card. */
    public const CARDS = 3;

    /**
     * A known mode, or single.
     *
     * @param string|null $mode
     * @return string
     */
    public static function clean(?string $mode): string {
        return in_array((string)$mode, self::MODES, true) ? (string)$mode : self::SINGLE;
    }

    /**
     * The mode of a lesson.
     *
     * @param \stdClass $book
     * @return string
     */
    public static function of(\stdClass $book): string {
        // The lesson's own choice decides. The animation engine settings only refine animated-diagram lessons
        // ("Static image" turns their diagrams off); they never turn a picture lesson into an animated one.
        $chosen = self::clean($book->picturemode ?? null);
        if ($chosen === self::ANIMATED && animation_settings::values($book)['animationmode'] === 'static') {
            return self::SINGLE;
        }
        return $chosen;
    }

    /**
     * Options for a select.
     *
     * @return array
     */
    public static function options(): array {
        $out = [];
        foreach (self::MODES as $mode) {
            $out[$mode] = get_string('picturemode_' . $mode, 'mod_visuallearning');
        }
        return $out;
    }

    /**
     * The picture modes teachers pick under "Picture mode" (animated diagrams are picked as a visual style).
     *
     * @return array
     */
    public static function form_options(): array {
        return array_intersect_key(self::options(), [self::SINGLE => 1, self::SEQUENCE => 1, self::SVG => 1]);
    }

    /**
     * The three card picture prompts of a page (empty strings where there are none).
     *
     * @param \stdClass $page
     * @return string[] three prompts, card 1 to 3
     */
    public static function card_prompts(\stdClass $page): array {
        $list = json_decode((string)($page->cardprompts ?? ''), true);
        $out = [];
        for ($i = 0; $i < self::CARDS; $i++) {
            $out[] = is_array($list) && is_string($list[$i] ?? null) ? trim($list[$i]) : '';
        }
        return $out;
    }

    /**
     * Whether a page has a full set of card picture prompts.
     *
     * @param \stdClass $page
     * @return bool
     */
    public static function has_sequence(\stdClass $page): bool {
        return !in_array('', self::card_prompts($page), true);
    }
}
