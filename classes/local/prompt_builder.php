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
    /** @var string Rules of every picture of a workplace story in the illustration style ({@see image_rules()}). */
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
     * The country or region the lesson's imagery reflects, or '' for international.
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
     * The cultural direction in one short sentence (used by approaches whose pictures rarely show people, and when a
     * picture prompt must be shortened).
     *
     * @param \stdClass $book
     * @return string
     */
    public static function culture_short(\stdClass $book): string {
        $place = self::culture($book);
        return $place !== '' ? "Every scene takes place in {$place}, with authentic local people, settings and customs."
            : 'Neutral, international setting with a diverse cast.';
    }

    /**
     * The cultural direction for pictures that rarely show people (explanatory diagrams).
     *
     * @param \stdClass $book
     * @return string
     */
    public static function culture_people(\stdClass $book): string {
        $place = self::culture($book);
        return $place !== '' ? "If people or workplaces appear, they are in {$place}." : 'If people appear, a diverse cast.';
    }

    /**
     * The rules that close every picture prompt: the visual style's rendering rule, then the teaching approach's
     * rules on text and content. For a workplace story in the illustration style this is exactly IMAGE_GUARDRAILS.
     *
     * @param \stdClass $book
     * @return string
     */
    public static function image_rules(\stdClass $book): string {
        $rules = approaches::of($book)->image_rules();
        if (learner_levels::young($book)) {
            // School-age learners: the people shown need not be adults at work.
            $rules = str_replace('Adults in a professional, workplace-appropriate setting with respectful representation.',
                'Age-appropriate, safe and respectful representation.', $rules);
        }
        return 'STRICT RULES: ' . visual_styles::render_rule($book) . ' ' . $rules;
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
        $approach = approaches::of($book);
        $visualstyle = visual_styles::of($book);
        $style = visual_styles::direction($book);
        $style = $style !== '' ? $style : get_string('defaultimagestyle', 'mod_visuallearning');
        // Lessons made before approaches and styles existed keep exactly the inputs they always had.
        $styleline = $visualstyle === visual_styles::DEFAULT ? "Illustration style chosen by the teacher: {$style}"
            : 'Visual style chosen by the teacher: ' . get_string('visualstyle_' . $visualstyle, 'mod_visuallearning')
                . ". {$style}";
        if ($approach->key() !== approaches::DEFAULT) {
            $styleline .= "\nTeaching approach: " . get_string('approach_' . $approach->key(), 'mod_visuallearning');
        }
        if (($level = learner_levels::brief($book, 'text')) !== '') {
            // Who the lesson is for: how every card, question and picture is pitched. Adult learners add nothing.
            $styleline .= "\n" . $level . "\nWrite every card, summary, question and picture prompt for these learners.";
        }
        $intro = $approach->intro();
        $cardsbrief = $approach->cards_brief($where);
        $example = $approach->example();
        $check = $approach->check();
        $textcheck = $approach->text_check();
        $content = trim((string)$book->sourcecontent);
        $title = trim((string)$book->name);
        $place = self::culture($book);
        $quiz = !empty($book->enablequiz);
        $questionstep = $quiz ? self::question_brief($book, $language) : '- "question": null (this book has no check questions).';
        $questionexample = $quiz ? self::question_example($book) : '"question": null,';

        if ($place !== '') {
            $cultureline = $place;
            $culturestep = <<<CULTURE
The learners live and work in {$place}, so the whole lesson must feel local to {$place}:
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

        $imagesbrief = $approach->images_brief($where, $cultureline, $language);
        $culturestep = $approach->culture_brief($place) ?? $culturestep;
        [$modestep, $modekey, $modecheck, $keycount] = self::picture_mode_brief($book, $language);
        $imagesbrief .= $modestep;

        return <<<PROMPT
VISUAL LEARNING LESSON - CONTENT GENERATION BRIEF
================================================

{$intro} A professional voice actor will read every card aloud, so write for the ear.
After every four pages the learner hears a short recap and then answers one check question about each
of those pages, so each page also needs a one-sentence summary and one check question.

------------------------------------------------
INPUTS
------------------------------------------------
Lesson title: {$title}
Industry: {$industryline}
Sub-industry: {$subindustryline}
Language for ALL learner-facing text: {$language} ({$book->language})
Country / culture of the learners: {$cultureline}
Number of pages: exactly {$max}
{$styleline}

Teaching content:
<<<CONTENT
{$content}
CONTENT

------------------------------------------------
STEP 1 - BREAK THE CONTENT INTO CONCEPTS
------------------------------------------------
- Identify the key concepts a learner must understand from the content. Cover the content fully in
  EXACTLY {$max} pages: the lesson recaps and checks every four pages, so the count matters. If the
  content has fewer concepts, split the biggest ideas into their steps or parts; if it has more, merge
  closely related ones or leave out the least important. Never pad with invented facts.
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
{$cardsbrief}
Writing rules for the cards:
- Plain, warm, confident language. Short sentences (under 25 words each). Active voice.
- Each card is AT MOST 250 characters (the narrator reads one card per clip).
- Written to be READ ALOUD: no bullet points, no brackets, no abbreviations or acronyms without
  saying them in full first, no symbols (write "per cent", not "%"; "and", not "&").
- Each card must make sense when heard on its own, and the three cards must read as one flowing story.
- All card text and titles must be written in {$language}.

{$example}

------------------------------------------------
STEP 4 - RECAP SENTENCE AND CHECK QUESTION
------------------------------------------------
- "summary": ONE neat sentence (under 20 words) that captures the concept for the recap slide.
  Written in {$language}, to be read aloud.
{$questionstep}

------------------------------------------------
STEP 5 - DESIGN THE ILLUSTRATIONS
------------------------------------------------
{$imagesbrief}

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
      {$questionexample}
      "image_prompt": "string",
{$modekey}      "alt_text": "string"
    }
  ]
}

Before you answer, silently check: exactly {$max} pages; one concept per page; every page has all
{$keycount} keys; {$check}{$modecheck} names, places, food and
customs fit the learners' country; {$textcheck} every question follows its type's
exact shape and tests only its own page; the JSON is valid (double quotes, no trailing commas).
PROMPT;
    }

    /**
     * The extra prompt-file step for the lesson's picture mode: [step text, output key line, self-check, key count].
     * One picture per page adds nothing, so those lessons keep exactly the prompt they always had.
     *
     * @param \stdClass $book
     * @param string $language
     * @return array
     */
    public static function picture_mode_brief(\stdClass $book, string $language): array {
        $mode = picture_modes::of($book);
        if ($mode === picture_modes::SEQUENCE) {
            $step = <<<TXT


------------------------------------------------
STEP 6 - ONE PICTURE PER CARD
------------------------------------------------
Each page also has "card_image_prompts": three picture prompts IN ENGLISH, one per card, that build ONE scene
step by step, so each picture flows on from the one before:
- Prompt 1 (40-90 words): the complete starting picture for card 1, written like "image_prompt" (setting,
  composition, people or parts, lighting).
- Prompts 2 and 3 (15-50 words each) are EDITS of the picture before. Start each with "Same picture, same
  viewpoint and layout." and then say only what is added or changes to show that card.
- Picture 3 is the finished scene. "image_prompt" describes that same finished scene in full; it is used for the
  recap and the questions.
- Keep the composition stable. Do not bring in new people in prompts 2 and 3 unless the card needs them.
TXT;
            return [$step, "      \"card_image_prompts\": [\"string\", \"string\", \"string\"],\n", ' card_image_prompts 2 and 3 only '
                . 'describe changes to the picture before;', 'ten'];
        }
        if ($mode === picture_modes::ANIMATED) {
            if (animation_settings::values($book)['animationmode'] !== 'legacy') {
                $guide = file_get_contents(__DIR__ . '/../../schema/scene-generation.txt');
                $schema = file_get_contents(__DIR__ . '/../../schema/animated-scene.json');
                return ["\n" . animation_settings::brief($book) . "\n" . $guide . "\n" . $schema,
                    "      \"diagram\": null,\n",
                    ' diagram is a valid animated_scene, a supported existing diagram, or null with image fallback;', 'ten'];
            }
            $max = diagram::MAX_LABEL;
            $note = diagram::MAX_NOTE;
            $step = <<<TXT


------------------------------------------------
STEP 6 - ANIMATED DIAGRAMS
------------------------------------------------
Each page also has "diagram": a small specification that the lesson draws itself as an exact, animated diagram,
or null when none of the types below really explains the page's concept (the picture is then used instead).
- Labels are short (at most {$max} characters) and written in {$language}. Use only values and units that the
  teaching content gives; never invent numbers.
- Any element may have "card": 1, 2 or 3, the card whose narration introduces it, so the diagram builds up while
  the cards are read. Elements without "card" are there from the start.
- Colours: blue, red, green, orange, purple, teal, grey. Keep the lesson's colour code.
- Angles are in degrees, anticlockwise from pointing right (90 = up, 270 = down). A negative phase lags.
- The types and their exact shapes:
  {"type": "waveform", "title": "string", "xlabel": "string", "ylabel": "string", "cycles": 2,
   "waves": [{"label": "V", "colour": "blue", "phase": 0, "amplitude": 1, "card": 1}, ... up to 3],
   "markers": [{"kind": "halfcycles", "wave": 0, "label": "string", "card": 2},
               {"kind": "period", "label": "string", "card": 3},
               {"kind": "lag", "from": 0, "to": 1, "label": "string", "card": 3},
               {"kind": "peak", "wave": 0, "label": "string"}]}
  {"type": "phasor", "rotate": true, "vectors": [{"label": "string", "colour": "blue", "angle": 0, "length": 1,
   "card": 1}, ... up to 4], "arcs": [{"from": 0, "to": 1, "label": "string", "card": 2}]}
  {"type": "circuit", "source": {"kind": "ac | dc | battery", "label": "string"},
   "components": [{"kind": "resistor | inductor | capacitor | lamp | switch | motor | meter", "label": "string",
   "card": 1}, ... up to 5, in one series loop],
   "current": {"show": true, "direction": "clockwise", "alternating": true, "label": "string", "card": 2}}
  {"type": "process", "steps": [{"label": "string", "detail": "string", "card": 1}, ... 2 to 6 in order],
   "inputs": ["string"], "outputs": ["string"]}
  {"type": "cycle", "steps": [{"label": "string", "detail": "string", "card": 1}, ... 3 to 6 round a loop]}
  {"type": "forces", "object": "string", "forces": [{"label": "string", "angle": 270, "magnitude": 0.7,
   "card": 1}, ... up to 6], "motion": {"angle": 0, "label": "string", "card": 3}}
  {"type": "bars", "unit": "string", "bars": [{"label": "string", "value": 2.4, "card": 1}, ... 2 to 6]}
- Amplitudes, lengths and magnitudes are relative (0.1 to 1). Keep "image_prompt" as well: it is the picture for
  the recap, the questions and any page without a diagram.
- Make the movement follow the narration with "stages": what the diagram DOES while each card is read. Each card's
  movement plays once while that card is read and stops where the next card carries on, so card 1, card 2 and
  card 3 tell one continuous story. Give every stage a "note": a caption of at most {$note} characters saying what
  is happening right now (for example "Voltage rises to its peak"). Use the field that fits the type:
  "stages": {"1": {"to": 90, "note": "string"}, "2": {"to": 270, "note": "string"}, "3": {"to": 360, "note": "string"}}
  - waveform: "to" = how far along the time axis the trace has run by the end of the card, in degrees (one cycle =
    360, so 0 to 360 × cycles). It starts where the card before stopped. Rising to the peak is 0 to 90, falling
    through zero to the trough is 90 to 270, back to zero is 270 to 360.
  - phasor: "turn" = the angle all vectors have turned to by the end of the card (for example 90, then 180, then 360).
  - circuit: "flow" = "clockwise", "anticlockwise" or "stopped" (alternating current: clockwise, then anticlockwise).
  - process, cycle, forces, bars: "focus" = the number (from 1) of the step, force or bar the card is about.
  Each stage must match what its card says, in the same order.
TXT;
            if (($level = learner_levels::brief($book, 'diagram')) !== '') {
                $step .= "\n" . $level;
            }
            return [$step, "      \"diagram\": {\"type\": \"string\", \"...\": \"see Step 6\"} or null,\n",
                ' every diagram uses only the listed types and fields, and values from the content;', 'ten'];
        }
        return ['', '', '', 'nine'];
    }

    /**
     * The check-question part of the brief, for the question types the lesson allows.
     *
     * @param \stdClass $book
     * @param string $language
     * @return string
     */
    public static function question_brief(\stdClass $book, string $language): string {
        $types = question::allowed($book);
        $max = question::MAX_PROMPT;
        $label = question::MAX_LABEL;
        $lines = [];
        $lines[] = "- \"question\": ONE check question in {$language} that tests ONLY this page's concept. Test";
        $lines[] = "  understanding, not trivia (no names, numbers or wording tricks). Avoid \"always\", \"never\" and";
        $lines[] = "  double negatives. Statements and prompts at most {$max} characters; every option, pair, group";
        $lines[] = "  and item text at most {$label} characters. Everything is read aloud, so write for the ear.";
        $lines[] = "- Every question has \"feedback\": ONE sentence in {$language} that explains WHY the right";
        $lines[] = "  answer is right. It is read aloud after the learner gets it right.";
        if (count($types) > 1) {
            $list = implode(', ', array_map(fn($t) => '"' . $t . '"', $types));
            $lines[] = "- Use these types: {$list}. Rotate them so no two pages in a row use the same";
            $lines[] = "  type, and pick the type that suits each concept best.";
        } else {
            $lines[] = "- Every question uses the type \"{$types[0]}\".";
        }
        $lines[] = "- The types and their exact shapes:";
        foreach ($types as $type) {
            $lines[] = self::question_shape($type, !empty($book->pickimages), approaches::of($book)->option_brief());
        }
        return implode("\n", $lines);
    }

    /**
     * The exact JSON shape of one question type, with its rules.
     *
     * @param string $type
     * @param bool $pictures picture-pick options get their own pictures
     * @param string $optionbrief what an option's picture shows (from the teaching approach)
     * @return string
     */
    protected static function question_shape(string $type, bool $pictures, string $optionbrief = ''): string {
        switch ($type) {
            case question::TRUEFALSE:
                return <<<SHAPE
  TRUE OR FALSE - one statement that is definitely true or definitely false according to the page.
  Across the lesson, make roughly half of them false.
    {"type": "truefalse", "statement": "string", "answer": true, "feedback": "string"}
SHAPE;
            case question::PICK:
                $picture = $pictures ? $optionbrief
                    : '  Options are shown as text cards, so leave "image_prompt" empty.';
                return <<<SHAPE
  PICK THE PICTURE - a question with EXACTLY 4 options, of which exactly one is right. The wrong options
  are believable mistakes, not jokes. "correct" is the position of the right option (0 to 3); vary it.
{$picture}
    {"type": "pick", "prompt": "string", "correct": 1, "feedback": "string", "options": [
      {"label": "string", "image_prompt": "string", "alt_text": "string"}, ... exactly 4 ]}
SHAPE;
            case question::MATCH:
                return <<<SHAPE
  MATCH THEM UP - 3 or 4 pairs; the learner matches each left item to its right item. Every left
  item and every right item is different.
    {"type": "match", "prompt": "string", "feedback": "string", "pairs": [
      {"left": "string", "right": "string"}, ... 3 or 4 ]}
SHAPE;
            default:
                return <<<SHAPE
  SORT INTO GROUPS - exactly 2 groups and 4 to 6 short items; each item belongs to one group (0 or 1),
  and each group gets at least one item.
    {"type": "sort", "prompt": "string", "feedback": "string", "groups": ["string", "string"],
      "items": [{"text": "string", "group": 0}, ... 4 to 6 ]}
SHAPE;
        }
    }

    /**
     * The question line in the output example.
     *
     * @param \stdClass $book
     * @return string
     */
    protected static function question_example(\stdClass $book): string {
        $types = question::allowed($book);
        return '"question": {"type": "' . $types[0] . '", "...": "the exact shape for its type, see Step 4"},';
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
        $approach = approaches::of($book);
        $master = $approach->image_master();
        $rules = self::image_rules($book);
        $style = visual_styles::direction($book);
        $direction = trim((string)($book->styleguide ?? ''));
        $culture = $approach->whole_cast() ? self::culture_direction($book) : self::culture_people($book);
        $cast = $approach->whole_cast() ? self::characters_text($book) : self::characters_text($book, $scene);
        $label = $master !== '' ? 'EXPLAIN: ' : 'SCENE: ';
        $audience = learner_levels::brief($book, 'picture');
        $build = function () use (&$style, &$direction, &$culture, &$cast, &$scene, $master, $rules, $label,
                $audience): string {
            $parts = [];
            if ($master !== '') {
                $parts[] = 'VISUAL EXPLANATION: ' . $master;
            }
            if ($style !== '') {
                $parts[] = 'ART STYLE: ' . $style;
            }
            if ($direction !== '') {
                $parts[] = 'ART DIRECTION: ' . $direction;
            }
            if ($audience !== '') {
                // Who the picture is for: how simple or detailed it is.
                $parts[] = $audience;
            }
            $parts[] = 'CULTURAL SETTING: ' . $culture;
            if ($cast !== '') {
                $parts[] = 'RECURRING CHARACTERS (draw them exactly like this whenever they appear): ' . $cast;
            }
            $parts[] = $label . $scene;
            $parts[] = $rules;
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
        if ($over() > 0 && $approach->whole_cast()) {
            $culture = self::culture_short($book);
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
     * The prompt that edits the picture before into the next card's picture (one picture per card).
     *
     * The picture before is sent with it, so only the change is described; the art direction and rules are repeated so
     * the edit keeps the lesson's look and its accuracy rules.
     *
     * @param \stdClass $book
     * @param string $change what is added or changes for this card
     * @param int $max longest prompt in code points (0: no limit)
     * @return string
     */
    public static function edit_prompt(\stdClass $book, string $change, int $max = 0): string {
        $change = trim(preg_replace('/\s+/u', ' ', $change));
        $style = visual_styles::direction($book);
        $parts = ['EDIT THE ATTACHED PICTURE. Keep everything exactly as it is: the layout, viewpoint, framing, people, '
            . 'objects, colours, lighting and art style. Change only what is described below, so this picture flows on '
            . 'from the one before.'];
        if ($style !== '') {
            $parts[] = 'ART STYLE: ' . $style;
        }
        if (($audience = learner_levels::brief($book, 'picture')) !== '') {
            $parts[] = $audience;
        }
        $parts[] = 'CHANGE: ' . $change;
        $parts[] = self::image_rules($book);
        $prompt = implode("\n\n", $parts);
        if ($max > 0 && \core_text::strlen($prompt) > $max) {
            // The art style goes first: the picture being edited already carries it.
            unset($parts[1]);
            $prompt = \core_text::substr(implode("\n\n", $parts), 0, $max);
        }
        return $prompt;
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
