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
 * Workplace story: each page is a short workplace story. The idea, a named person putting it into action, and the
 * outcome; the picture shows that action scene with the lesson's recurring characters. This is how every lesson
 * worked before approaches existed, and its prompts are unchanged.
 *
 * @package    mod_visuallearning
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class story extends base {
    /**
     * The approach key.
     *
     * @return string
     */
    public function key(): string {
        return 'story';
    }

    /**
     * The card labels are the original ones (The idea, Put it into action, The outcome).
     *
     * @param string $card
     * @return string
     */
    public function card_string(string $card): string {
        return 'card_' . $card;
    }

    /**
     * Every picture carries the whole cast.
     *
     * @return bool
     */
    public function whole_cast(): bool {
        return true;
    }

    /**
     * Opening of the prompt file.
     *
     * @return string
     */
    public function intro(): string {
        return <<<'TXT'
You are an expert instructional designer, workplace trainer and illustrated-story author.
Your job is to turn the teaching content below into an illustrated VISUAL LEARNING LESSON for adult learners.
Every page teaches exactly ONE concept, shown as one illustration on the left and three short
narrated cards on the right.
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
- "concept": Card 1 - THE IDEA. 1-2 sentences stating the concept and why it matters.
- "action": Card 2 - THE ACTION. 1-2 sentences describing ONE specific, concrete thing a person
  does in a real {$where} workplace to apply the concept. Use a named character
  from the cast (see Step 5) doing the action. This is exactly what the illustration will show.
- "outcome": Card 3 - THE OUTCOME. 1-2 sentences describing the consequence or positive result of
  that action (or, where it teaches better, the negative consequence of NOT doing it).

TXT;
    }

    /**
     * Step 3: example page.
     *
     * @return string
     */
    public function example(): string {
        return <<<'TXT'
Example of ONE good page (topic: promoting teamwork, English, Australia):
  title:   "Teams that bond work better"
  concept: "It's important for everyone in a team to get to know each other. People work together
            more easily when they feel connected."
  action:  "So, supervisor Maya organises a Friday team get-together at the local pub, where everyone
            relaxes and chats over a drink."
  outcome: "The team gets to know each other better and starts to trust one another. Back at work,
            they share ideas and help each other more."
  summary: "Teams that take time to bond trust each other and work better together."
  question: {"type": "truefalse", "statement": "Getting to know your teammates makes it harder to
            work together.", "answer": false, "feedback": "Teammates who know each other trust each
            other, so they work together more easily."}
  image_prompt: "Low-angle view across a wooden table in a cosy Australian pub, glasses sharp in the
            foreground. Maya, the supervisor (forties, short grey hair, denim shirt), raises her glass in a
            friendly toast at the moment five co-workers of different ages burst out laughing, leaning in
            towards each other. Golden evening light streams through the windows and warms their faces;
            the busy bar behind them is softly blurred."
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
- First define a "style_guide": 2-4 sentences of art direction that every illustration will share
  (based on the teacher's chosen style above: medium, line quality, colour palette, lighting, mood).
- Then define a small recurring "characters" cast (2-5 people) who appear across the lesson, each with
  a name and a precise visual description (age range, hair, skin tone, build, clothing / uniform that
  suits a {$where} workplace in {$cultureline}). Re-use these exact characters in the image prompts so
  the lesson feels like one consistent story.
- For each page write "image_prompt" IN ENGLISH, 50-100 words, describing ONE scene that shows the
  ACTION card exactly as described - the same people, place and activity, with the cultural setting
  from Step 2 (local setting, food, objects and customs). Describe: who is in the scene (use cast names
  AND a short reminder of their look), what they are doing, the setting, facial expressions / body
  language, and lighting. The scene must make the concept obvious at a glance.
- Make every picture striking, not just correct. Build each image_prompt from these parts, then write it
  as one flowing description (do not list them):
  1. HERO: the one person, object or moment the eye goes to first, large in the frame;
  2. CAMERA: a deliberate viewpoint (close-up, over-the-shoulder, low angle, three-quarter view) with a
     sharp foreground and a softly blurred background for depth - never flat and front-on;
  3. LIGHT: light that carries meaning (warm light on the right way of doing it, morning light for a fresh
     start, a cool tone easing to warm as tension drops);
  4. MOMENT: the instant the action happens, with body language and small motion cues (a hand mid-gesture,
     steam rising, a door swinging) so the still picture feels alive;
  5. REVEAL (only when the concept is invisible, such as germs, heat, noise or trust): a subtle visual cue
     that shows it, such as a faint magnified inset circle, a soft glow or gentle motion lines.
- Make every scene distinct and specific to its concept: vary the place, the activity and the composition
  (close-up, wide shot, outdoors, on the job). Never fall back on a generic person at a laptop or a
  meeting room unless the concept is really about that. Show only the people the scene needs, avoid
  complicated hand actions, and show the real equipment and objects of a {$where} workplace.
- Image rules: illustrations only (never photographs or photorealism), NO text, words, letters, numbers,
  signs, logos or speech bubbles in the image, safe and respectful workplace imagery, one clear focal point.
- "alt_text": one sentence in {$language} describing the image for screen-reader users.
- Also write a "cover_image_prompt" (English, 40-90 words) for a welcoming cover illustration
  showing the cast in their workplace, representing the overall topic. No text on the cover image.
TXT;
    }

    /**
     * Self-check.
     *
     * @return string
     */
    public function check(): string {
        return <<<'TXT'
the action card and the image prompt describe the same scene; every image prompt has a hero, a camera
viewpoint, meaningful light and a moment of action;
TXT;
    }

    /**
     * Option pictures.
     *
     * @return string
     */
    public function option_brief(): string {
        return <<<'TXT'
  Each option also has an "image_prompt" IN ENGLISH (20-50 words) for a SQUARE illustration of that
  option alone, in the lesson's style, with the same recurring characters and culture, and NO text in the
  image; plus "alt_text" in the lesson's language.
TXT;
    }

    /**
     * Text and content rules of every picture.
     *
     * @return string
     */
    public function image_rules(): string {
        return 'Do NOT include any text, words, letters, numbers, captions, speech bubbles, '
            . 'labels, logos, brand names or readable signage anywhere in the image. Adults in a professional, '
            . 'workplace-appropriate setting with respectful representation. One clear scene, main subject '
            . 'centred with comfortable margins, uncluttered background, warm and friendly mood. Only the people the '
            . 'scene needs, natural proportions, simple relaxed hands, meaningful objects that show the idea.';
    }
}
