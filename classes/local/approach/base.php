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
namespace mod_visuallearning\local\approach;

/**
 * A teaching approach: what each page explains, how its three cards are written and what its pictures must show.
 *
 * The three card slots (concept, action, outcome) are the same for every approach, so lessons, narration, recaps,
 * questions and exports never change shape; an approach changes what the slots are asked to say, their labels, and
 * the instructions behind the pictures. Prompt text is English because it is read by AI models, not learners.
 *
 * @package    mod_visuallearning
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class base {
    /**
     * The approach key (stored in the lesson).
     *
     * @return string
     */
    abstract public function key(): string;

    /**
     * The approach's name in the page writer's brief: fixed English, so renaming the teacher-facing option or translating
     * it never changes what the AI is told.
     *
     * @return string
     */
    public function prompt_name(): string {
        return get_string('approach_' . $this->key(), 'mod_visuallearning');
    }

    /**
     * The string with the label of a card.
     *
     * @param string $card concept, action or outcome
     * @return string
     */
    public function card_string(string $card): string {
        return 'card_' . $this->key() . '_' . $card;
    }

    /**
     * Whether every picture prompt carries the whole recurring cast (otherwise only the people a scene names).
     *
     * @return bool
     */
    abstract public function whole_cast(): bool;

    /**
     * The opening of the prompt file: who the AI is and what one page looks like.
     *
     * @return string
     */
    abstract public function intro(): string;

    /**
     * Step 3 of the prompt file: what each of the three cards says.
     *
     * @param string $where the industry, or "the chosen industry"
     * @return string
     */
    abstract public function cards_brief(string $where): string;

    /**
     * Step 3 of the prompt file: one worked example page.
     *
     * @return string
     */
    abstract public function example(): string;

    /**
     * Step 5 of the prompt file: the art direction, the cast and the picture prompts.
     *
     * @param string $where the industry, or "the chosen industry"
     * @param string $cultureline the learners' country, or "International / neutral"
     * @param string $language the lesson language's name
     * @return string
     */
    abstract public function images_brief(string $where, string $cultureline, string $language): string;

    /**
     * The final self-check: how the cards and the picture prompt relate.
     *
     * @return string
     */
    abstract public function check(): string;

    /**
     * Step 2 of the prompt file when the approach needs its own (null: the standard cultural step with characters).
     *
     * @param string $place the learners' country, or '' when international
     * @return string|null
     */
    public function culture_brief(string $place): ?string {
        return null;
    }

    /**
     * The final self-check on text in the pictures.
     *
     * @return string
     */
    public function text_check(): string {
        return 'image prompts ask for words only as PICTURE WORDS says;';
    }

    /**
     * What a picture-pick option picture shows (prompt file, question rules).
     *
     * @return string
     */
    abstract public function option_brief(): string;

    /**
     * The master block of the final picture prompt (empty when the approach needs none).
     *
     * @return string
     */
    public function image_master(): string {
        return '';
    }

    /**
     * The rules on text and content that follow the visual style's rendering rule in every picture prompt.
     *
     * @return string
     */
    abstract public function image_rules(): string;

    /**
     * A short instruction for LMS Labs AI while it does not take the approach itself (sent with the art direction).
     *
     * @return string
     */
    public function draft_hint(): string {
        return '';
    }

    /**
     * Whether the player shows the cards as numbered steps (1, 2, 3) instead of the idea, action and outcome icons.
     *
     * @return bool
     */
    public function numbered_cards(): bool {
        return false;
    }
}
