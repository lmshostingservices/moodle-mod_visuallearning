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
 * decided by the teaching approach ({@see approaches}), so any style works with any approach. Every still picture,
 * in every style, is made by the image model (OpenAI GPT Image, through LMS Labs); Claude draws only the animated
 * diagrams.
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

    /**
     * Earlier default art directions, replaced in 1.8.0 (the illustration one named an audience, "for adult learners",
     * which contradicted school lessons). A lesson or site still holding one gets today's direction instead.
     *
     * @var string[]
     */
    public const LEGACY_DEFAULTS = [
        'Modern hand-illustrated style for adult learners: warm hand-painted gouache and soft pencil textures, gentle '
            . 'rounded shapes, friendly expressive characters, rich but calm colour palette, soft natural lighting, clean '
            . 'uncluttered backgrounds.',
        'Natural, documentary-style photograph: real people, places and equipment, natural light, '
            . 'true-to-life colours and textures, sharp focus on the subject with a softly blurred background '
            . '(shallow depth of field, 35-50mm lens look), never a posed stock-photo look.',
        'Highly realistic 3D render, like a premium medical or engineering visualisation: realistic materials '
            . 'and textures, soft studio lighting, subtle depth of field, a plain softly lit background.',
        'Modern professional educational diagram: precise, clean vector linework, flat colours with a '
            . 'consistent colour code, clear visual hierarchy, generous white space, plain white or very light '
            . 'background, subtle shading only where it shows depth or a cutaway.',
        'Hand-drawn sketchnote on light grid paper: confident dark ink outlines with slightly uneven, natural '
            . 'strokes, a pale blue and pink colour wash, a yellow highlighter on the key word, red marker arrows '
            . 'for the key point, simple shapes, generous white space.',
    ];

    /** @var string[] The order teachers see the styles in. */
    public const ORDER = ['illustration', 'realistic', 'model3d', 'diagram', 'whiteboard'];

    /** @var string The "Visual style" choice that means animated diagrams drawn by the plugin. */
    public const ANIMATED = 'animated';

    /**
     * Every style.
     *
     * - direction: the string holding its default art direction, used when the teacher writes none;
     * - medium: how the image-model prompt names the picture ("Create a ... for a lesson");
     * - explain: what an explanatory (science) picture in this style must do;
     * - avoid: the short list of failures the image model is warned about;
     * - render: the rendering rule kept for LMS Labs AI drafts.
     *
     * @var array
     */
    public const STYLES = [
        'illustration' => [
            'direction' => 'defaultimagestyle',
            'medium' => 'hand-illustrated teaching picture',
            'explain' => 'Explain the concept at a glance in one clear scene: show the mechanism, not just the object, '
                . 'with a cutaway or flow arrows where it helps.',
            'avoid' => 'photographic skin, glossy or plastic-looking 3D surfaces, anime styling, exaggerated faces, '
                . 'decorative clutter',
            'render' => 'This must be an ILLUSTRATION, never a photograph, never photorealistic, never 3D-rendered realism.',
        ],
        'realistic' => [
            'direction' => 'visualstyle_realistic_direction',
            'medium' => 'realistic documentary photograph',
            'explain' => 'Show the real thing in its real setting, so the learner sees exactly what it looks like and '
                . 'how it is used: the key part is the sharply lit hero of the frame.',
            'avoid' => 'CGI, illustration or cartoon looks; airbrushed skin; fantasy lighting or heavy bokeh; posed '
                . 'stock-photo smiles; unsafe work practice',
            'render' => 'This must look like a real PHOTOGRAPH: photorealistic people, places, materials and light; '
                . 'never an illustration, cartoon or 3D render.',
        ],
        'model3d' => [
            'direction' => 'visualstyle_model3d_direction',
            'medium' => 'highly realistic 3D visualisation',
            'explain' => 'Reveal how it works: a cutaway or three-quarter view of the real structure, every part in its '
                . 'true place and shape, with bold arrows for the direction of flow or movement.',
            'avoid' => 'toy-like plastic, inflated shapes, floating parts, random transparency, heavy reflections; '
                . 'invented, extra or missing parts; gore',
            'render' => 'This must be a highly realistic 3D RENDER of the real structure (medical or engineering '
                . 'visualisation quality), accurate in every part, with clear arrows showing flow or movement; never a '
                . 'flat illustration or cartoon.',
        ],
        'diagram' => [
            'direction' => 'visualstyle_diagram_direction',
            'medium' => 'clean technical diagram',
            'explain' => 'Explain the mechanism with a clean schematic, cutaway or cross-section and flow arrows, every '
                . 'part in its correct place.',
            'avoid' => 'painterly textures, photographic materials, dramatic lighting, perspective distortion, '
                . 'decoration',
            'render' => 'This must be a clean, flat TECHNICAL DIAGRAM in a modern educational style: precise vector '
                . 'linework, never a photograph, never photorealistic, no decorative 3D effects.',
        ],
        'whiteboard' => [
            'direction' => 'visualstyle_whiteboard_direction',
            'medium' => 'hand-drawn sketchnote',
            'explain' => 'Explain the idea like a teacher sketching in a notebook: simple shapes, arrows and the key '
                . 'point highlighted.',
            'avoid' => 'fully painted scenes, smooth digital gradients, photorealism, comic-book colouring, messy '
                . 'handwriting',
            'render' => 'This must be a hand-drawn SKETCHNOTE: dark ink outlines on light grid paper with pale colour '
                . 'washes, like a teacher\'s notebook; never a photograph, never photorealistic, no 3D rendering.',
        ],
    ];

    /**
     * Whether the lesson's pictures carry callouts: short labels and at most one small info card (teacher's choice).
     *
     * @param \stdClass $book
     * @return bool
     */
    public static function callouts(\stdClass $book): bool {
        return !empty($book->callouts);
    }

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
        foreach (self::ORDER as $key) {
            $out[$key] = get_string('visualstyle_' . $key, 'mod_visuallearning');
        }
        return $out;
    }

    /**
     * The "Picture style" choices. Lessons that use the classic built-in animated diagrams keep that choice.
     *
     * @param bool $classic include the classic built-in animated diagrams (only for lessons already using them)
     * @return array
     */
    public static function form_options(bool $classic = false): array {
        return self::options() + ($classic ? [self::ANIMATED => get_string('visualstyle_animated', 'mod_visuallearning')] : []);
    }

    /**
     * Turns the form's choices (picture style, "animate each page", "one picture per card") into the stored style
     * and picture mode. Data that does not come from the form (web services, generators) keeps its picture mode.
     *
     * @param \stdClass $data form or instance data; changed in place
     */
    public static function apply_choice(\stdClass $data): void {
        $style = (string)($data->visualstyle ?? '');
        if ($style === self::ANIMATED) {
            $data->visualstyle = 'diagram';
            $data->picturemode = picture_modes::ANIMATED;
            return;
        }
        if ($style === picture_modes::SVG) {
            // 1.7.2 offered animated scenes as a style.
            $data->visualstyle = 'diagram';
            $data->picturemode = picture_modes::SVG;
            return;
        }
        if (property_exists($data, 'animate')) {
            if (!empty($data->animate)) {
                $data->picturemode = picture_modes::SVG;
            } else {
                $data->picturemode = !empty($data->cardpictures) ? picture_modes::SEQUENCE : picture_modes::SINGLE;
            }
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
     * to Technical model).
     *
     * @param \stdClass $book
     * @return string
     */
    public static function direction(\stdClass $book): string {
        $text = trim((string)($book->imagestyle ?? ''));
        $text = in_array($text, self::LEGACY_DEFAULTS, true) ? '' : $text;
        $key = self::of($book);
        if ($key === self::DEFAULT) {
            // An illustration lesson keeps exactly the words it was given; an empty field gets the site's default.
            $site = trim((string)get_config('mod_visuallearning', 'defaultimagestyle'));
            $site = in_array($site, self::LEGACY_DEFAULTS, true) ? '' : $site;
            return $text !== '' ? $text : ($site ?: trim(get_string('defaultimagestyle', 'mod_visuallearning')));
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
        if (in_array($text, self::LEGACY_DEFAULTS, true)) {
            return true;
        }
        foreach (self::STYLES as $style) {
            if ($text === trim(get_string($style['direction'], 'mod_visuallearning'))) {
                return true;
            }
        }
        if ($text === trim(get_string('svg_art_direction', 'mod_visuallearning'))) {
            return true;
        }
        $site = trim((string)get_config('mod_visuallearning', 'defaultimagestyle'));
        return $site !== '' && $text === $site;
    }
}
