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
 * The visual styles a lesson's pictures can have. A style decides only how the pictures look; what they show is
 * decided by the teaching approach ({@see approaches}), so any style works with any approach.
 *
 * To add a style: add an entry to STYLES and its strings (visualstyle_<key>, visualstyle_<key>_desc and the
 * string named in 'direction').
 *
 * @package    mod_visuallearning
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class visual_styles {
    /** @var string The style of every lesson made before styles existed. */
    public const DEFAULT = 'illustration';

    /** @var string The "Visual style" choice that means animated diagrams drawn by the plugin. */
    public const ANIMATED = 'animated';

    /**
     * Every style.
     *
     * - direction: the string holding its default art direction, used when the teacher writes none;
     * - render: the rendering rule that opens the image rules (it never says anything about text or people, which
     *   belong to the approach);
     * - wire: the style value sent to LMS Labs when it does not list this style (it then gets the default).
     *
     * @var array
     */
    public const STYLES = [
        'illustration' => [
            'direction' => 'defaultimagestyle',
            'render' => 'This must be an ILLUSTRATION, never a photograph, never photorealistic, never 3D-rendered realism.',
        ],
        'diagram' => [
            'direction' => 'visualstyle_diagram_direction',
            'render' => 'This must be a clean, flat TECHNICAL DIAGRAM in a modern educational style: precise vector '
                . 'linework, never a photograph, never photorealistic, no decorative 3D effects.',
        ],
        'whiteboard' => [
            'direction' => 'visualstyle_whiteboard_direction',
            'render' => 'This must be a hand-drawn WHITEBOARD SKETCH: marker-pen linework on a plain white board, like a '
                . 'teacher explaining at the board; never a photograph, never photorealistic, no 3D rendering, no shading '
                . 'beyond simple marker hatching.',
        ],
    ];

    /**
     * A known key, or the default.
     *
     * @param string|null $key
     * @return string
     */
    public static function clean(?string $key): string {
        return isset(self::STYLES[(string)$key]) ? (string)$key : self::DEFAULT;
    }

    /**
     * The style key of a lesson.
     *
     * @param \stdClass $book
     * @return string
     */
    public static function of(\stdClass $book): string {
        return self::clean($book->visualstyle ?? self::DEFAULT);
    }

    /**
     * Options for a select: key => name.
     *
     * @return array
     */
    public static function options(): array {
        $out = [];
        foreach (array_keys(self::STYLES) as $key) {
            $out[$key] = get_string('visualstyle_' . $key, 'mod_visuallearning');
        }
        return $out;
    }

    /**
     * The choice teachers see as "Visual style": every style, plus animated diagrams. Animated diagrams are stored
     * as the picture mode "animated" with the Technical diagram style (for the recap, cover and question pictures).
     *
     * @return array
     */
    public static function form_options(): array {
        return self::options() + [self::ANIMATED => get_string('visualstyle_animated', 'mod_visuallearning')];
    }

    /**
     * Turns the form's visual style choice into the stored style and picture mode.
     *
     * @param \stdClass $data form or instance data (visualstyle, picturemode); changed in place
     */
    public static function apply_choice(\stdClass $data): void {
        if (($data->visualstyle ?? '') === self::ANIMATED) {
            $data->visualstyle = 'diagram';
            $data->picturemode = picture_modes::ANIMATED;
        }
    }

    /**
     * The rendering rule of a lesson's style.
     *
     * @param \stdClass $book
     * @return string
     */
    public static function render_rule(\stdClass $book): string {
        return self::STYLES[self::of($book)]['render'];
    }

    /**
     * The art direction the pictures follow: the teacher's own words, or the style's default when the teacher left
     * the field empty or kept the default of another style (for example the illustration default after switching
     * to Technical diagram).
     *
     * @param \stdClass $book
     * @return string
     */
    public static function direction(\stdClass $book): string {
        $text = trim((string)($book->imagestyle ?? ''));
        $key = self::of($book);
        if ($key === self::DEFAULT) {
            // Exactly as before styles existed (an empty field stays empty in the picture prompts).
            return $text;
        }
        if ($text === '' || self::is_default_text($text)) {
            return get_string(self::STYLES[$key]['direction'], 'mod_visuallearning');
        }
        return $text;
    }

    /**
     * Whether some text is a default art direction (of any style, or the site's own default).
     *
     * @param string $text
     * @return bool
     */
    public static function is_default_text(string $text): bool {
        $text = trim($text);
        foreach (self::STYLES as $style) {
            if ($text === trim(get_string($style['direction'], 'mod_visuallearning'))) {
                return true;
            }
        }
        $site = trim((string)get_config('mod_visuallearning', 'defaultimagestyle'));
        return $site !== '' && $text === $site;
    }
}
