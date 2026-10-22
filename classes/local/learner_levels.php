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
 * Who the lesson is for. The level sets how the cards are written (sentence length, vocabulary, depth) and how
 * every picture is drawn (page, cover, option and card pictures, and animated diagrams): simple and friendly for
 * young children, precise and detailed for university and professional learners.
 *
 * Adult and vocational learners is how every lesson worked before levels existed, and adds nothing to any prompt,
 * so those lessons' requests and pictures are unchanged.
 *
 * To add a level: add it to LEVELS and add its strings (learnerlevel_<key>).
 *
 * @package    mod_visuallearning
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class learner_levels {
    /** @var string The level of every lesson made before levels existed. */
    public const DEFAULT = 'adult';

    /**
     * Every level: what the writer is told (text), what every picture is told (picture) and what the animated
     * diagrams are told (diagram). Empty for the default level.
     *
     * @var array
     */
    public const LEVELS = [
        'primary' => [
            'text' => 'LEARNER LEVEL: primary school children, about 5 to 11 years old. Use short sentences (about 8 to '
                . '12 words) and everyday words, and explain any new word in the same sentence. Use friendly comparisons '
                . 'with things children know (toys, bikes, kitchens, playgrounds, pets). Keep only the core idea of each '
                . 'concept; leave out formulas, numbers and technical detail unless the content is about them. Check '
                . 'questions are short, concrete and kind. Scenes can be at school, at home or in the local community; '
                . 'adults at work are shown doing their job safely.',
            'picture' => 'AUDIENCE: primary school children (about 5 to 11). Make the picture very simple, friendly and '
                . 'easy to read at a glance: one big clear subject, at most three main parts, bold rounded shapes, '
                . 'bright cheerful colours, no technical detail. Use a familiar comparison where it helps. Any people '
                . 'shown are friendly and age-appropriate. This overrides any audience named above.',
            'diagram' => '- The learners are primary school children: keep every diagram very simple, at most three '
                . 'parts or steps, plain everyday labels, and prefer the process, cycle and bars types (no phasors or '
                . 'phase angles).',
        ],
        'secondary' => [
            'text' => 'LEARNER LEVEL: secondary school students, about 12 to 17 years old. Write clear, direct sentences. '
                . 'Introduce the correct technical terms and explain each one the first time it appears. Use examples '
                . 'from teenagers\' lives and the world around them. Include key numbers and simple formulas only where '
                . 'the content gives them.',
            'picture' => 'AUDIENCE: secondary school students (about 12 to 17). Clear and engaging with moderate detail: '
                . 'show the main parts and how they connect. A modern, energetic look that is never childish. This '
                . 'overrides any audience named above.',
            'diagram' => '- The learners are secondary school students: moderate detail, up to four or five labelled '
                . 'parts, correct technical terms.',
        ],
        'adult' => ['text' => '', 'picture' => '', 'diagram' => ''],
        'advanced' => [
            'text' => 'LEARNER LEVEL: university students and professionals. Use precise technical language and the '
                . 'correct terms without simplifying, include quantitative relationships, standards and conditions '
                . 'where the content gives them, and assume the basics are known.',
            'picture' => 'AUDIENCE: university students and professionals. Technically detailed and precise, like a '
                . 'high-quality textbook or engineering figure: show the full mechanism, every relevant part and the '
                . 'variables involved. This overrides any audience named above.',
            'diagram' => '- The learners are university students and professionals: full technical detail is welcome '
                . '(several waves or vectors, phase relationships, every component, standard symbols and units).',
        ],
    ];

    /**
     * A known level, or the default.
     *
     * @param string|null $key
     * @return string
     */
    public static function clean(?string $key): string {
        return isset(self::LEVELS[(string)$key]) ? (string)$key : self::DEFAULT;
    }

    /**
     * The level of a lesson.
     *
     * @param \stdClass $book
     * @return string
     */
    public static function of(\stdClass $book): string {
        return self::clean($book->learnerlevel ?? null);
    }

    /**
     * Options for a select, youngest first.
     *
     * @return array
     */
    public static function options(): array {
        $out = [];
        foreach (array_keys(self::LEVELS) as $key) {
            $out[$key] = get_string('learnerlevel_' . $key, 'mod_visuallearning');
        }
        return $out;
    }

    /**
     * One brief of a lesson's level: text, picture or diagram ('' for adult learners).
     *
     * @param \stdClass $book
     * @param string $kind
     * @return string
     */
    public static function brief(\stdClass $book, string $kind): string {
        return self::LEVELS[self::of($book)][$kind];
    }

    /**
     * Whether the lesson is for school-age learners (people in pictures need not be adults at work).
     *
     * @param \stdClass $book
     * @return bool
     */
    public static function young(\stdClass $book): bool {
        return in_array(self::of($book), ['primary', 'secondary'], true);
    }
}
