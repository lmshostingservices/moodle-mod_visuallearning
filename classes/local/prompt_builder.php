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
 * Builds the ChatGPT prompt file and the final image prompts sent to Nano Banana.
 *
 * @package    mod_visuallearning
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class prompt_builder {
    /** @var string Rules appended to every image request. */
    public const IMAGE_GUARDRAILS = 'STRICT RULES: This must be an ILLUSTRATION, never a photograph, never photorealistic, '
        . 'never 3D-rendered realism. Do NOT include any text, words, letters, numbers, captions, speech bubbles, '
        . 'labels, logos, brand names or readable signage anywhere in the image. Adults in a professional, '
        . 'workplace-appropriate setting with respectful representation. One clear scene, main subject '
        . 'centred with comfortable margins, uncluttered background, warm and friendly mood. Only the people the '
        . 'scene needs, natural proportions, simple relaxed hands, meaningful objects that show the idea.';

    /** @var string Culture setting: match the narration language's country. */
    public const CULTURE_LANGUAGE = 'language';

    /** @var string Culture setting: neutral, international imagery. */
    public const CULTURE_INTERNATIONAL = 'international';

    /**
     * The country or region the book's imagery reflects, or '' for international.
     *
     * @param \stdClass $book
     * @return string
     */
    public static function culture(\stdClass $book): string {
        if (($book->imageculture ?? self::CULTURE_LANGUAGE) === self::CULTURE_INTERNATIONAL) {
            return '';
        }
        return voices::culture((string)$book->language);
    }

    /**
     * Cultural direction for the illustrations.
     *
     * @param \stdClass $book
     * @return string
     */
    public static function culture_direction(\stdClass $book): string {
        $place = self::culture($book);
        if ($place === '') {
            return 'Neutral, international setting: a mixed, diverse cast in modern workplaces that could be in many countries.';
        }
        return "Every scene takes place in {$place}. Show people who look like they live and work there, "
            . "everyday clothing and work uniforms typical there, authentic local architecture, interiors, streets, "
            . "vehicles, plants and weather, and the food, drink, tableware and customs people there would really use. "
            . "Be authentic and respectful: no stereotypes, costumes, flags or tourist clichés.";
    }

    /**
     * The full prompt file a teacher pastes into ChatGPT.
     *
     * @param \stdClass $book
     * @return string
     */
    public static function chatgpt_prompt(\stdClass $book): string {
        $language = voices::language_name($book->language);
        $industry = trim((string)$book->industry);
        $subindustry = trim((string)$book->subindustry);
        $industryline = $industry !== '' ? $industry
            : 'NOT PROVIDED - infer the most suitable industry from the content and return it in "industry".';
        $subindustryline = $subindustry !== '' ? $subindustry
            : 'NOT PROVIDED - infer the most suitable sub-industry from the content and return it in "subindustry".';
        $where = $industry !== '' ? $industry : 'the chosen industry';
        $max = (int)$book->maxpages;
        $style = trim((string)$book->imagestyle) !== '' ? trim((string)$book->imagestyle)
            : get_string('defaultimagestyle', 'mod_visuallearning');
        $content = trim((string)$book->sourcecontent);
        $title = trim((string)$book->name);
        $place = self::culture($book);

        if ($place !== '') {
            $cultureline = $place;
            $culturestep = <<<CULTURE
The learners live and work in {$place}, so the whole book must feel local to {$place}:
- Characters have names that are common in {$place}, and look like people who live and work there.
- Workplaces, tools, uniforms, food, drink, celebrations, transport, homes and streets are the ones
  people in {$place} really use. Example: a team-bonding dinner in Spain might be paella at a local
  restaurant; in Thailand, a shared meal at a local Thai restaurant or a street-food market.
- Adapt every example and activity to local norms and etiquette. If something is not customary or
  appropriate there (for example drinking alcohol, a particular food, mixed social settings, or a
  hand gesture), choose the local equivalent instead.
- Use local units, currency and seasons where relevant. Be authentic and respectful: never use
  stereotypes, costumes, flags or tourist clichés.
- Put this cultural detail into BOTH the action cards and the image prompts.
CULTURE;
        } else {
            $cultureline = 'International / neutral';
            $culturestep = <<<CULTURE
The learners are international. Use a mixed, diverse cast with names from several cultures, and
modern workplaces that could be in many countries. Avoid customs that only make sense in one country.
CULTURE;
        }

        return <<<PROMPT
PICTURE BOOK LEARNING - CONTENT GENERATION BRIEF
================================================

You are an expert instructional designer, workplace trainer and children's picture-book author.
Your job is to turn the teaching content below into a PICTURE BOOK for adult learners.
Every page teaches exactly ONE concept, shown as one illustration on the left and three short
narrated cards on the right. A professional voice actor will read every card aloud, so write for the ear.
After every four pages the learner hears a short recap and answers true/false questions, so each
page also needs a one-sentence summary and one true/false statement.

------------------------------------------------
INPUTS
------------------------------------------------
Book title: {$title}
Industry: {$industryline}
Sub-industry: {$subindustryline}
Language for ALL learner-facing text: {$language} ({$book->language})
Country / culture of the learners: {$cultureline}
Maximum number of pages: {$max}
Illustration style chosen by the teacher: {$style}

Teaching content:
<<<CONTENT
{$content}
CONTENT

------------------------------------------------
STEP 1 - BREAK THE CONTENT INTO CONCEPTS
------------------------------------------------
- Identify the key concepts a learner must understand from the content. Cover the content fully,
  but use NO MORE than {$max} pages. If the content is small, use fewer pages - never pad.
- ONE concept per page. A concept is a single, simple, memorable idea. Never combine two ideas.
- Order the pages so they build logically: foundations first, then application, then the big picture.
- Stay faithful to the content. Do not invent facts, laws, figures or procedures that are not in it.
  You may add realistic workplace examples to illustrate the content.

------------------------------------------------
STEP 2 - MAKE IT CULTURALLY RIGHT
------------------------------------------------
{$culturestep}

------------------------------------------------
STEP 3 - WRITE EACH PAGE (THREE CARDS)
------------------------------------------------
Every page has:
- "title": the concept in 2-7 words (shown as the page heading and in the contents page).
- "concept": Card 1 - THE IDEA. 1-2 sentences stating the concept and why it matters.
- "action": Card 2 - THE ACTION. 1-2 sentences describing ONE specific, concrete thing a person
  does in a real {$where} workplace to apply the concept. Use a named character
  from the cast (see Step 5) doing the action. This is exactly what the illustration will show.
- "outcome": Card 3 - THE OUTCOME. 1-2 sentences describing the consequence or positive result of
  that action (or, where it teaches better, the negative consequence of NOT doing it).

Writing rules for the cards:
- Plain, warm, confident language. Short sentences (under 25 words each). Active voice.
- Each card is AT MOST 250 characters (the narrator reads one card per clip).
- Written to be READ ALOUD: no bullet points, no brackets, no abbreviations or acronyms without
  saying them in full first, no symbols (write "per cent", not "%"; "and", not "&").
- Each card must make sense when heard on its own, and the three cards must read as one flowing story.
- All card text and titles must be written in {$language}.

Example of ONE good page (topic: promoting teamwork, English, Australia):
  title:   "Teams that bond work better"
  concept: "It's important for everyone in a team to get to know each other. People work together
            more easily when they feel connected."
  action:  "So, supervisor Maya organises a Friday team get-together at the local pub, where everyone
            relaxes and chats over a drink."
  outcome: "The team gets to know each other better and starts to trust one another. Back at work,
            they share ideas and help each other more."
  summary: "Teams that take time to bond trust each other and work better together."
  tf_statement: "Getting to know your teammates makes it harder to work together."
  tf_answer: false
  image_prompt: "A cheerful group of six co-workers of different ages sitting around a wooden table
            in a cosy Australian pub, laughing and talking over drinks. Maya, the supervisor, raises her
            glass in a friendly toast. Warm evening light through the windows."

------------------------------------------------
STEP 4 - RECAP SENTENCE AND TRUE/FALSE CHECK
------------------------------------------------
- "summary": ONE neat sentence (under 20 words) that captures the concept for the recap slide.
  Written in {$language}, to be read aloud.
- "tf_statement": ONE clear statement in {$language} about this page's concept that is either
  definitely true or definitely false according to the page. Test understanding of the concept,
  not trivia (no names, numbers or wording tricks). Avoid "always", "never" and double negatives.
- "tf_answer": true or false (a JSON boolean). Across the book, make roughly half the statements
  false, mixed in no obvious pattern.

------------------------------------------------
STEP 5 - DESIGN THE ILLUSTRATIONS
------------------------------------------------
- First define a "style_guide": 2-4 sentences of art direction that every illustration will share
  (based on the teacher's chosen style above: medium, line quality, colour palette, lighting, mood).
- Then define a small recurring "characters" cast (2-5 people) who appear across the book, each with
  a name and a precise visual description (age range, hair, skin tone, build, clothing / uniform that
  suits a {$where} workplace in {$cultureline}). Re-use these exact characters in the image prompts so
  the book feels like one consistent story.
- For each page write "image_prompt" IN ENGLISH, 40-90 words, describing ONE scene that shows the
  ACTION card exactly as described - the same people, place and activity, with the cultural setting
  from Step 2 (local setting, food, objects and customs). Describe: who is in the scene (use cast names
  AND a short reminder of their look), what they are doing, the setting, facial expressions / body
  language, and lighting. The scene must make the concept obvious at a glance.
- Make every scene distinct and specific to its concept: vary the place, the activity and the composition
  (close-up, wide shot, outdoors, on the job). Never fall back on a generic person at a laptop or a
  meeting room unless the concept is really about that. Show only the people the scene needs, avoid
  complicated hand actions, and show the real equipment and objects of a {$where} workplace.
- Image rules: illustrations only (never photographs or photorealism), NO text, words, letters, numbers,
  signs, logos or speech bubbles in the image, safe and respectful workplace imagery, one clear focal point.
- "alt_text": one sentence in {$language} describing the image for screen-reader users.
- Also write a "cover_image_prompt" (English, 40-90 words) for a welcoming book-cover illustration
  showing the cast in their workplace, representing the overall topic. No text on the cover image.

------------------------------------------------
OUTPUT FORMAT - VERY IMPORTANT
------------------------------------------------
Reply with ONLY one valid JSON object. No introduction, no explanation, no markdown headings.
You may wrap it in a ```json code block. Use exactly this structure and these key names:

{
  "book_title": "string - keep the given title unless it is empty",
  "industry": "string",
  "subindustry": "string",
  "language": "{$book->language}",
  "style_guide": "string",
  "characters": [
    {"name": "string", "description": "string"}
  ],
  "cover_image_prompt": "string",
  "pages": [
    {
      "page": 1,
      "title": "string",
      "concept": "string",
      "action": "string",
      "outcome": "string",
      "summary": "string",
      "tf_statement": "string",
      "tf_answer": true,
      "image_prompt": "string",
      "alt_text": "string"
    }
  ]
}

Before you answer, silently check: maximum {$max} pages; one concept per page; every page has all
ten keys; the action card and the image prompt describe the same scene; names, places, food and
customs fit the learners' country; no text in any image prompt; tf_answer is a boolean; the JSON is
valid (double quotes, no trailing commas).
PROMPT;
    }

    /**
     * Final prompt sent (through LMS Labs) to the image model for a page or the cover.
     *
     * When the prompt is longer than the route accepts, the least important parts are shortened first: characters
     * who are not in the scene, then the art direction, then the cultural detail (the place is always kept), then
     * the remaining characters and finally the scene itself. The rules are never shortened.
     *
     * @param \stdClass $book
     * @param string $sceneprompt
     * @param int $max longest prompt in Unicode code points (0: no limit)
     * @return string
     */
    public static function image_prompt(\stdClass $book, string $sceneprompt, int $max = 0): string {
        $scene = trim(preg_replace('/\s+/u', ' ', $sceneprompt));
        $style = trim((string)$book->imagestyle);
        $direction = trim((string)($book->styleguide ?? ''));
        $culture = self::culture_direction($book);
        $cast = self::characters_text($book);
        $build = function () use (&$style, &$direction, &$culture, &$cast, &$scene): string {
            $parts = [];
            if ($style !== '') {
                $parts[] = 'ART STYLE: ' . $style;
            }
            if ($direction !== '') {
                $parts[] = 'ART DIRECTION: ' . $direction;
            }
            $parts[] = 'CULTURAL SETTING: ' . $culture;
            if ($cast !== '') {
                $parts[] = 'RECURRING CHARACTERS (draw them exactly like this whenever they appear): ' . $cast;
            }
            $parts[] = 'SCENE: ' . $scene;
            $parts[] = self::IMAGE_GUARDRAILS;
            return implode("\n\n", $parts);
        };
        $prompt = $build();
        if ($max <= 0 || \core_text::strlen($prompt) <= $max) {
            return $prompt;
        }
        $cast = self::characters_text($book, $scene);
        $over = fn(): int => \core_text::strlen($build()) - $max;
        $cut = function (string $text, int $by): string {
            $keep = max(0, \core_text::strlen($text) - $by - 1);
            return $keep < 20 ? '' : rtrim(\core_text::substr($text, 0, $keep)) . '…';
        };
        if ($over() > 0) {
            $direction = $cut($direction, $over());
        }
        if ($over() > 0) {
            $place = self::culture($book);
            $culture = $place !== '' ? "Every scene takes place in {$place}, with authentic local people, settings and customs."
                : 'Neutral, international setting with a diverse cast.';
        }
        if ($over() > 0) {
            $style = $cut($style, $over());
        }
        if ($over() > 0) {
            $cast = $cut($cast, $over());
        }
        if ($over() > 0) {
            $scene = $cut($scene, $over());
        }
        return \core_text::substr($build(), 0, $max);
    }

    /**
     * Characters as one line of text, optionally only those named in a scene.
     *
     * @param \stdClass $book
     * @param string|null $scene only characters whose name appears here (null: all)
     * @return string
     */
    public static function characters_text(\stdClass $book, ?string $scene = null): string {
        $chars = json_decode((string)($book->characters ?? ''), true);
        if (!is_array($chars)) {
            return '';
        }
        $out = [];
        foreach ($chars as $c) {
            if (empty($c['name']) || !is_string($c['name'])) {
                continue;
            }
            $haystack = \core_text::strtolower((string)$scene);
            $named = $scene === null || \core_text::strpos($haystack, \core_text::strtolower($c['name'])) !== false;
            if (!$named) {
                continue;
            }
            $out[] = $c['name'] . ' - ' . ($c['description'] ?? '');
        }
        return implode('; ', $out);
    }
}
