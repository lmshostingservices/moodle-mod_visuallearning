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

use mod_visuallearning\local\approach\base;

/**
 * The teaching approaches a lesson can use: what each page explains, how its three cards are written and what its
 * pictures must show. The look of the pictures is chosen separately ({@see visual_styles}).
 *
 * To add an approach: write a class in classes/local/approach/ that extends {@see base}, add it to APPROACHES and
 * add its strings (approach_<key>, approach_<key>_desc, card_<key>_concept/action/outcome).
 *
 * @package    mod_visuallearning
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class approaches {
    /** @var string The approach of every lesson made before approaches existed. */
    public const DEFAULT = 'story';

    /** @var string Pre-selected for new lessons: suits most topics, with people in the pictures only when needed. */
    public const NEW_DEFAULT = 'science';

    /** @var array key => class, in the order teachers see them. */
    public const APPROACHES = [
        'science' => approach\science::class,
        'story' => approach\story::class,
    ];

    /**
     * One approach by key (an unknown or empty key is the default).
     *
     * @param string|null $key
     * @return base
     */
    public static function get(?string $key): base {
        $class = self::APPROACHES[(string)$key] ?? self::APPROACHES[self::DEFAULT];
        return new $class();
    }

    /**
     * The approach of a lesson.
     *
     * @param \stdClass $book
     * @return base
     */
    public static function of(\stdClass $book): base {
        return self::get($book->teachingapproach ?? self::DEFAULT);
    }

    /**
     * Options for a select: key => name.
     *
     * @return array
     */
    public static function options(): array {
        $out = [];
        foreach (array_keys(self::APPROACHES) as $key) {
            $out[$key] = get_string('approach_' . $key, 'mod_visuallearning');
        }
        return $out;
    }

    /**
     * A known key, or the default.
     *
     * @param string|null $key
     * @return string
     */
    public static function clean(?string $key): string {
        return isset(self::APPROACHES[(string)$key]) ? (string)$key : self::DEFAULT;
    }

    /**
     * The label learners and teachers see on a card of this lesson.
     *
     * @param \stdClass|null $book null: the default approach
     * @param string $card concept, action or outcome
     * @return string
     */
    public static function card_label(?\stdClass $book, string $card): string {
        $approach = $book ? self::of($book) : self::get(self::DEFAULT);
        return get_string($approach->card_string($card), 'mod_visuallearning');
    }
}
