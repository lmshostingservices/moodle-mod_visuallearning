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
 * Scientific and technical explanation: each page explains how something works in three numbered key points that
 * build in order (the core idea, how it works, the result); the picture explains the mechanism itself (a diagram, cutaway, graph,
 * process or comparison), not a person standing next to the equipment. Recurring characters are optional.
 *
 * @package    mod_visuallearning
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class science extends base {
    /**
     * The approach key.
     *
     * @return string
     */
    public function key(): string {
        return 'science';
    }

    /**
     * Pictures carry only the people they name (usually nobody).
     *
     * @return bool
     */
    public function whole_cast(): bool {
        return false;
    }

    /**
     * Opening of the prompt file.
     *
     * @return string
     */
    public function intro(): string {
        return <<<'TXT'
You are an expert instructional designer, subject-matter specialist and scientific illustrator.
Your job is to turn the teaching content below into a VISUAL LEARNING LESSON for adult learners that
EXPLAINS how things work. Every page teaches exactly ONE concept, shown as one explanatory picture on the
left and three short narrated cards on the right.
TXT;
    }

    /**
     * Step 3: the cards.
     *
     * @param string $where
     * @return string
     */
    public function cards_brief(string $where): string {
        return <<<TXT
Every page has:
- "title": the concept in 2-7 words (shown as the page heading and in the contents page).
- Three KEY POINTS that build the learner's understanding of this one concept, in order. Each is
  1-2 sentences and adds ONE new fact or principle; together they explain the concept fully:
  - "concept": KEY POINT 1 - the core idea: what it is or what happens.
  - "action": KEY POINT 2 - how it works: the mechanism, the cause, or the next principle that
    builds on point 1. The picture explains this.
  - "outcome": KEY POINT 3 - the result: what changes, how it is measured or recognised, or what it
    means in a real {$where} job.
- No invented people, companies or workplace stories: state the science plainly. Keep numbers and units
  from the content, written for the ear (for example "fifty hertz", "twenty milliseconds").
TXT;
    }

    /**
     * Step 3: example page.
     *
     * @return string
     */
    public function example(): string {
        return <<<'TXT'
Example of ONE good page (topic: alternating current, English, Australia):
  title:   "How alternating current works"
  concept: "Alternating current keeps reversing direction, flowing one way and then the other, over and
            over again."
  action:  "Australia's supply alternates at fifty hertz, which means fifty complete cycles of forward and
            reverse flow happen every second."
  outcome: "So each cycle lasts twenty milliseconds: one positive half-cycle followed by one negative
            half-cycle."
  summary: "Alternating current reverses fifty times a second in Australia, each cycle taking twenty milliseconds."
  question: {"type": "truefalse", "statement": "At fifty hertz, one complete cycle takes twenty milliseconds.",
            "answer": true, "feedback": "Fifty cycles fit into one second, so each cycle lasts one fiftieth of a
            second, which is twenty milliseconds."}
  image_prompt: "Three-quarter view of an open switchboard in a workshop, one circuit breaker in the
            foreground. A transparent cutaway of the cable behind it reveals glowing electrons flowing
            forward, then reversing, shown as two faint motion trails in opposite directions. Above the
            board, a large oscilloscope screen shows exactly one complete sine wave cycle, its positive
            half-cycle glowing blue above the axis and its negative half-cycle glowing red below it, with a
            bracket marking one cycle. Soft workshop light, shallow depth of field. Accuracy: equal positive
            and negative halves, smooth symmetrical wave. Exclude: people, numbers other than what the
            content gives."
TXT;
    }

    /**
     * Step 5: pictures.
     *
     * @param string $where
     * @param string $cultureline
     * @param string $language
     * @return string
     */
    public function images_brief(string $where, string $cultureline, string $language): string {
        return <<<TXT
- First define a "style_guide": 2-4 sentences of visual direction every picture will share (based on the
  teacher's chosen visual style above), including a fixed COLOUR CODE for the quantities and parts that
  recur in this lesson (for example: voltage blue, current red, magnetic field green) so the same colour
  always means the same thing.
- "characters" is optional for this lesson. Leave it as an empty list [] unless some pictures really need
  people (for example a worker using a meter); then describe at most 3, as in a {$where} workplace in
  {$cultureline}.
- For each page write "image_prompt" IN ENGLISH, 60-90 words, that EXPLAINS the page's concept so a
  learner understands it from the picture alone. Work it out from these parts, then write one
  self-contained description (do not list the letters):
  A. the single concept the learner must understand;
  B. the mechanism that explains it (physical, mathematical, biological, chemical or technical);
  C. the parts, directions, sequence, forces, variables or cause-and-effect links that must be shown;
  D. the clearest format: a labelled schematic, cutaway or cross-section, waveforms or graphs, vector or
     phasor diagram, force, motion or energy-flow arrows, molecular or biological process, a sequence of
     steps, a before-and-after comparison, an annotated equipment diagram or a cause-and-effect chain
     (up to three related panels when the mechanism needs them; do not force every page into one layout);
  E. how the visual style applies, without weakening the explanation, and how the picture becomes striking:
     - HERO: one main subject, large in the frame, ideally the real object the learner will meet on the job
       (a switchboard, a forklift, a leaf, a pump) rather than an abstract box;
     - REVEAL: a cutaway, cross-section, x-ray view or magnified inset circle that shows the hidden
       mechanism inside that real object;
     - LIGHT AND COLOUR AS INFORMATION: glow for energy or activity, the lesson's colour code for every part
       that recurs, darker or cooler tones for what is inactive;
     - MOTION CUES: arrows, flowing particles, trails or a faded "before" ghost, so the still picture shows
       what happens and in which direction;
     - CAMERA: a three-quarter or low viewpoint with a clear foreground and background for depth (a flat
       front-on view only for graphs and schematics, where it is clearer);
  F. accuracy: end with "Accuracy:" and the relationships, directions or proportions that must be right;
  G. exclusions: end with "Exclude:" and anything misleading or unnecessary (usually people, logos,
     invented numbers).
- The picture does not have to show the cards literally; it must explain the SAME concept they teach.
- Scientific accuracy comes before looks: correct circuit connections, correct phase relationships,
  graphs that show the intended relationship, forces and fields pointing the right way, accurate
  biological structures, no invented reactions or mechanisms, before-and-after views under the same
  conditions. Never invent a fact to make a picture more attractive. Do not ask for exact numbers,
  equations, angles or scales unless the teaching content gives them; use shapes, arrows and colour.
- Image rules: the picture style chosen above; prefer arrows, colour and standard symbols (V, I, N, S, +
  and -) to words; words only as PICTURE WORDS below says; no logos or brand names; one primary concept
  per picture.
- "alt_text": one sentence in {$language} that explains what the picture shows, for screen-reader users.
- Also write a "cover_image_prompt" (English, 40-90 words) for a clean overview picture of the whole
  topic: the main system or process at a glance, with the key parts the lesson explains. No text on the
  cover image.
TXT;
    }

    /**
     * Self-check.
     *
     * @return string
     */
    public function check(): string {
        return 'the three key points build in order and every image prompt explains the same concept, with '
            . 'scientifically correct relationships, a hero, a reveal of the mechanism, a motion cue, and an Accuracy '
            . 'and Exclude sentence;';
    }

    /**
     * Step 2: local standards and units, no characters.
     *
     * @param string $place
     * @return string|null
     */
    public function culture_brief(string $place): ?string {
        if ($place === '') {
            return <<<'TXT'
The learners are international. Use SI units and widely used international standards and examples. Do not
invent people, companies or workplace stories.
TXT;
        }
        return <<<TXT
The learners live and work in {$place}. Use the units, standards, regulations, supply values and everyday
examples that apply in {$place} (for example the local mains voltage and frequency, or local codes of
practice) wherever the content allows. Do not invent people, companies or workplace stories.
TXT;
    }

    /**
     * Self-check on text in the pictures.
     *
     * @return string
     */
    public function text_check(): string {
        return 'image prompts ask for words only as PICTURE WORDS says;';
    }

    /**
     * Option pictures.
     *
     * @return string
     */
    public function option_brief(): string {
        return <<<'TXT'
  Each option also has an "image_prompt" IN ENGLISH (20-50 words) for a SQUARE explanatory picture of that
  option alone, in the lesson's visual style and colour code, scientifically accurate, with no words in the
  image; plus "alt_text" in the lesson's language.
TXT;
    }

    /**
     * The master block of every picture prompt.
     *
     * @return string
     */
    public function image_master(): string {
        return 'Act as an expert scientific illustrator and instructional designer. The picture must EXPLAIN the '
            . 'concept below so a learner understands it without narration: what happens, why, how the mechanism '
            . 'works and what changes. Use the clearest format (schematic, cutaway, graph, vector or flow arrows, '
            . 'process steps, before-and-after), up to three panels. Show the mechanism, not just the object.';
    }

    /**
     * Text and content rules of every picture.
     *
     * @return string
     */
    public function image_rules(): string {
        return 'Accuracy first: correct parts, connections and proportions; invent nothing. Show only this page\'s step: '
            . 'a path or flow stops exactly where the scene says. Arrows point only the real way things move. One '
            . 'concept; only the colour-code colours this page needs; people only if the scene names them, fully clothed, '
            . 'with anatomy as a see-through overlay on the clothing (never a bare chest).';
    }

    /**
     * Hint for LMS Labs AI while it does not take the approach itself.
     *
     * @return string
     */
    public function draft_hint(): string {
        return 'Teaching approach: scientific and technical explanation. The three cards are key points 1, 2 and 3 '
            . 'that build the concept in order, with no invented people or workplace stories. Each image_prompt describes an accurate explanatory diagram of the '
            . 'mechanism (schematic, cutaway, graph, process or comparison), not people at work; characters optional.';
    }

    /**
     * Key points 1, 2 and 3 show as numbered steps.
     *
     * @return bool
     */
    public function numbered_cards(): bool {
        return true;
    }
}
