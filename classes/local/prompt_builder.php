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
 * Builds the page writer's brief (run by Claude at LMS Labs), the picture prompts for OpenAI GPT Image and the briefs
 * for Claude's animated diagrams.
 *
 * @package    mod_visuallearning
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class prompt_builder {
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
     * The teaching approach's content rules for a picture (people, safety, accuracy), adjusted for young learners.
     * What may be written in the picture is decided by the lesson's callouts setting ({@see words_rule()}).
     *
     * @param \stdClass $book
     * @return string
     */
    public static function image_rules(\stdClass $book): string {
        $rules = approaches::of($book)->image_rules();
        if (learner_levels::young($book)) {
            // School-age learners: the people shown need not be adults at work.
            $rules = str_replace('Adults in a professional, workplace-appropriate setting with respectful representation',
                'Age-appropriate, safe and respectful representation', $rules);
        }
        return $rules;
    }

    /**
     * What may be written in an image-model picture: callouts (the teacher's choice) or nothing at all.
     *
     * @param \stdClass $book
     * @param bool $edit for an edit of the picture before
     * @return string
     */
    public static function words_rule(\stdClass $book, bool $edit = false): string {
        $language = voices::language_name((string)$book->language);
        if (!visual_styles::callouts($book)) {
            return 'None. A completely text-free picture: no labels, captions, numbers, letters or marks that look '
                . 'like writing; clothing, equipment, screens, signs and packaging show no readable text.';
        }
        if ($edit) {
            return "Keep the existing callouts exactly as they are; add one only where the change gives its words in "
                . "quotes, in {$language}, in the same tag style.";
        }
        return "Only the words in quotes in the scene, spelled exactly, in {$language}; nothing else. Callouts: white "
            . 'rounded tags, navy bold sans-serif, a thin leader line to a dot on the part. The "Info card" words: one '
            . 'small navy rounded card in a corner. Readable on a phone; never over the subject.';
    }

    /**
     * The page writer's brief: everything Claude needs to write the lesson's pages as JSON. Sent to LMS Labs with each
     * draft once it says it takes it (capability "writerBrief"); until then LMS Labs uses its own copy of this brief.
     *
     * @param \stdClass $book
     * @return string
     */
    public static function writer_prompt(\stdClass $book): string {
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
        $styleline = 'Picture style chosen by the teacher: ' . get_string('visualstyle_' . $visualstyle, 'mod_visuallearning')
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
        $extras = trim(self::lesson_extras($book));
        $extras = $extras !== '' ? $extras . "\n" : '';
        $material = self::material_block($book);
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
        $imagesbrief .= $modestep . self::picture_words_brief($book, $language) . self::consistency_brief();
        $imageheading = "------------------------------------------------\nSTEP 5 - DESIGN THE ILLUSTRATIONS\n"
            . "------------------------------------------------";
        // Every lesson describes its fixed layout once, so every picture (and every animated scene) matches.
        $scenestylekey = "  \"scene_style\": {\"palette\": \"string\", \"recurring\": \"string\", "
            . "\"subject\": \"string\"},\n";
        if (picture_modes::of($book) === picture_modes::SVG) {
            // The animated-scene brief replaces STEP 5, never appends a conflicting picture brief.
            $imageheading = '';
            $imagesbrief = $modestep . "\nAlso write \"style_guide\", \"characters\" and \"cover_image_prompt\" as "
                . "usual: the cover, recap and question pictures are stills in the lesson's picture style."
                . self::picture_words_brief($book, $language);
        }

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
{$extras}{$styleline}

What to teach (the topic and the key points):
<<<CONTENT
{$content}
CONTENT{$material}

------------------------------------------------
STEP 1 - BREAK THE CONTENT INTO CONCEPTS
------------------------------------------------
- Identify the key concepts a learner must understand from the content. Cover the content fully in
  EXACTLY {$max} pages: the lesson recaps and checks every four pages, so the count matters. If the
  content has fewer concepts, split the biggest ideas into their steps or parts; if it has more, merge
  closely related ones or leave out the least important. Never pad with invented facts.
- ONE concept per page. A concept is a single, simple, memorable idea. Never combine two ideas.
- Order the pages so they build logically: foundations first, then application, then the big picture.
- Stay faithful to the content and the teacher's material. Do not invent facts, laws, figures or procedures
  that are not in them.
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

{$imageheading}
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
{$scenestylekey}  "pages": [
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
     * What keeps a lesson's pictures consistent and accurate, for the page writer (pictures of every style).
     *
     * @return string starting with a new line
     */
    public static function consistency_brief(): string {
        return "\n- Once for the whole lesson, write \"scene_style\" (IN ENGLISH): \"palette\": the colour code used on "
            . "every page, each colour with its meaning; \"recurring\": at most 25 words on the fixed layout and "
            . "viewpoint, and how things that appear on several pages are drawn, so every picture matches (for example "
            . "\"front view of a see-through upper body, the broken left forearm held out to the side\"); \"subject\": "
            . "biology, physics, chemistry, mechanical, earth, maths, safety, business, or \"\" when none fits."
            . "\n- Each image_prompt shows only its own page's step. When it shows a path, flow or process, say exactly "
            . "where it starts and where it stops on this page (\"... and stops at the spinal cord\") and which way "
            . "the arrows point, then end with 'Not shown:' and what later pages teach (\"Not shown: the brain\"); "
            . "fix which side of the body or machine is involved and keep it the same on every page.";
    }

    /**
     * The rule for words in pictures, for the page writer: the exact callouts to name in each picture prompt, or none.
     *
     * @param \stdClass $book
     * @param string $language learner-facing language name
     * @return string starting with a new line
     */
    public static function picture_words_brief(\stdClass $book, string $language): string {
        $count = learner_levels::of($book) === 'primary' ? 'two' : 'three';
        if (visual_styles::callouts($book)) {
            return "\n- PICTURE WORDS (callouts are on): end each page image_prompt and card picture prompt with "
                . "'Callouts:' and up to " . $count . " exact labels in double quotes, in {$language}, one "
                . "to three words each, naming the parts the learner must notice; then 'Info card:' and at most eight "
                . "words in double quotes stating the key point. The cover and answer-option pictures have no words.";
        }
        return "\n- PICTURE WORDS: none. Pictures carry no words at all, so never ask for labels, captions, signs or "
            . "text in a picture prompt; show meaning with arrows, colour and composition.";
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
        if ($mode === picture_modes::SVG) {
            $key = file_get_contents(__DIR__ . '/../../schema/scene-plan-output.txt');
            $check = file_get_contents(__DIR__ . '/../../schema/scene-plan-checks.txt');
            return [scene_plan::brief($language, approaches::of($book)->key() === 'story'),
                '      ' . trim($key) . ",\n", ' ' . trim($check) . ' ', 'ten'];
        }
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
     * The final prompt of a still picture (page, cover, option or the first card picture).
     *
     * Every style gets a short, positive, narrative prompt for the image model (OpenAI GPT Image): what to make and
     * why, the scene, the look, the audience, people and place, the words allowed in the picture, the approach's
     * rules, the composition and a short list of what to avoid.
     *
     * @param \stdClass $book
     * @param string $sceneprompt what the picture shows (written by the page writer, in English)
     * @param int $max longest prompt in Unicode code points (0: no limit)
     * @param string $aspect media::ASPECT or media::OPTION_ASPECT
     * @param string $objective what this page teaches (its title), so the picture shows only that
     * @return string
     */
    public static function image_prompt(
        \stdClass $book,
        string $sceneprompt,
        int $max = 0,
        string $aspect = media::ASPECT,
        string $objective = ''
    ): string {
        $scene = trim(preg_replace('/\s+/u', ' ', $sceneprompt));
        $objective = trim(preg_replace('/\s+/u', ' ', $objective));
        $approach = approaches::of($book);
        $style = visual_styles::STYLES[visual_styles::of($book)];
        $science = $approach->image_master() !== '';
        $open = 'Create a ' . $style['medium'] . ' for a lesson called "' . trim((string)$book->name) . '". '
            . ($science ? $style['explain'] : 'It shows one moment of a workplace story, so the idea is obvious at a glance.');
        $look = visual_styles::direction($book);
        $guide = trim(preg_replace('/\s+/u', ' ', (string)($book->styleguide ?? '')));
        $shared = scene_plan::clean_style($book->scenestyle ?? null);
        $same = trim((string)($shared['recurring'] ?? ''));
        $culture = $approach->whole_cast() ? self::culture_direction($book) : self::culture_people($book);
        $cast = $approach->whole_cast() ? self::characters_text($book) : self::characters_text($book, $scene);
        // The look never names an audience any more, so the level's override sentence is not needed.
        $audience = str_replace(' This overrides any audience named above.', '', learner_levels::brief($book, 'picture'));
        $square = $aspect === media::OPTION_ASPECT;
        $tail = [
            // Answer options stay free of words: a label could give the answer away.
            'WORDS IN THE PICTURE: ' . ($square ? 'None. Keep every surface free of writing.' : self::words_rule($book)),
            'RULES: ' . self::image_rules($book),
            'COMPOSITION: ' . ($square ? 'A square tile: one simple subject filling the frame' : 'One clear focal subject, '
                . 'large in the frame') . ', comfortable margins, a calm uncluttered background.',
            'AVOID: ' . $style['avoid'] . (visual_styles::callouts($book) && !$square ? '; logos, watermarks or misspelled words.'
                : '; any text, letters, numbers, logos or watermarks.'),
        ];
        $build = function () use (&$look, &$guide, &$culture, &$cast, &$scene, &$same, $open, $audience, $science,
                $tail, $objective, $square): string {
            $parts = [$open];
            if ($objective !== '') {
                $parts[] = 'THIS PAGE TEACHES: ' . $objective;
            }
            $parts[] = ($science ? 'EXPLAIN: ' : 'SCENE: ') . $scene;
            $parts[] = 'LOOK: ' . trim($look . ($guide !== '' ? ' ' . $guide . ' (Use each colour only where this picture '
                . 'shows that part.)' : ''));
            if ($same !== '' && !$square) {
                $parts[] = 'SAME ON EVERY PAGE OF THIS LESSON: ' . $same;
            }
            if ($audience !== '') {
                $parts[] = $audience;
            }
            $parts[] = 'PEOPLE AND PLACE: ' . $culture
                . ($cast !== '' ? ' Recurring characters, drawn exactly like this whenever they appear: ' . $cast : '');
            return implode("\n\n", array_merge($parts, $tail));
        };
        $prompt = $build();
        if ($max <= 0 || \core_text::strlen($prompt) <= $max) {
            return $prompt;
        }
        // Too long: shorten the least important parts first. The lesson's own art direction, layout and cast keep
        // its pages consistent, so they go last, just before the scene itself.
        $over = fn(): int => \core_text::strlen($build()) - $max;
        if ($over() > 0 && $approach->whole_cast()) {
            $culture = self::culture_short($book);
        }
        if ($over() > 0) {
            $cast = self::characters_text($book, $scene);
        }
        if ($over() > 0) {
            // The look is shortened but never lost: at least its opening words (such as "Editorial textbook
            // illustration") always stay, so the picture keeps the teacher's chosen style.
            $essence = trim((string)strtok($look, ':.'));
            $look = self::cut($look, $over());
            $look = \core_text::strlen($look) > \core_text::strlen($essence) + 1 ? $look : $essence;
        }
        if ($over() > 0) {
            $guide = self::cut($guide, $over());
        }
        if ($over() > 0) {
            $same = self::cut($same, $over());
        }
        if ($over() > 0) {
            // The characters the scene names keep the start of their description, so they look the same on every page.
            $essential = $cast !== '' ? rtrim(\core_text::substr($cast, 0, 160)) . (\core_text::strlen($cast) > 160 ? '…' : '')
                : '';
            $cast = self::cut($cast, $over());
            $cast = $cast !== '' ? $cast : $essential;
        }
        if ($over() > 0) {
            $scene = self::cut($scene, $over());
        }
        return \core_text::substr($build(), 0, $max);
    }

    /**
     * Text shortened by some characters, ending with an ellipsis ('' when too little would be left).
     *
     * @param string $text
     * @param int $by
     * @return string
     */
    protected static function cut(string $text, int $by): string {
        $keep = max(0, \core_text::strlen($text) - $by - 1);
        return $keep < 20 ? '' : rtrim(\core_text::substr($text, 0, $keep)) . '…';
    }

    /**
     * The prompt that edits the picture before into the next card's picture (one picture per card).
     *
     * The picture before is sent with it, so only the change is described; the look and the rules are repeated so
     * the edit keeps the lesson's look and its accuracy rules.
     *
     * @param \stdClass $book
     * @param string $change what is added or changes for this card
     * @param int $max longest prompt in code points (0: no limit)
     * @return string
     */
    public static function edit_prompt(\stdClass $book, string $change, int $max = 0): string {
        $change = trim(preg_replace('/\s+/u', ' ', $change));
        $style = visual_styles::STYLES[visual_styles::of($book)];
        $parts = ['EDIT THE ATTACHED PICTURE. Keep everything exactly as it is: the layout, viewpoint, framing, people, '
            . 'objects, colours, lighting and art style. Change only what is described below, so this picture flows on '
            . 'from the one before.', 'CHANGE: ' . $change];
        $keep = 'KEEP UNCHANGED: the camera angle and composition; every person\'s face, hair, skin tone, build and '
            . 'clothing; the colour code; every structure, part and connection already shown; every object, where it is '
            . 'and in its colour, above all safety devices such as locks, tags and guards, unless the change names it; '
            . 'the art style.';
        if (($cast = self::characters_text($book, $change)) !== '') {
            $keep .= ' The people keep exactly this appearance: ' . $cast . '.';
        }
        $parts[] = $keep;
        $look = visual_styles::direction($book);
        if ($look !== '') {
            $parts['look'] = 'LOOK: ' . $look;
        }
        if (($audience = learner_levels::brief($book, 'picture')) !== '') {
            $parts[] = $audience;
        }
        $parts[] = 'WORDS IN THE PICTURE: ' . self::words_rule($book, true);
        $parts[] = 'RULES: ' . self::image_rules($book);
        $parts[] = 'AVOID: ' . $style['avoid'] . '.';
        $prompt = implode("\n\n", $parts);
        if ($max > 0 && \core_text::strlen($prompt) > $max) {
            // The look goes first: the picture being edited already carries it.
            unset($parts['look']);
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

    /** @var int Longest animated SVG brief sent to LMS Labs (characters). */
    public const MAX_SVG_PROMPT = 12000;

    /**
     * Who an animated SVG scene is for, in drawing terms (on top of the level's picture brief).
     *
     * @var array
     */
    public const SVG_LEVELS = [
        'primary' => 'Very simple: one big subject, at most three main parts, big friendly shapes and bright colours, '
            . 'labels of one or two everyday words, slow and obvious motion, a familiar comparison where it helps.',
        'secondary' => 'Clear and engaging: the main parts and how they connect, correct technical terms in a few labels, '
            . 'lively but calm motion.',
        'adult' => 'Real equipment and real workplace context where it helps; practical labels; motion that shows '
            . 'what the learner will see on the job.',
        'advanced' => 'Textbook detail: every relevant part, correct terminology and symbols, variables and units '
            . 'the content gives, precise relationships in the motion.',
    ];

    /**
     * The brief for one page's animated SVG scene. LMS Labs sends it to Claude with the plugin's SVG system prompt
     * (schema/svg-animation-system.md).
     *
     * @param \stdClass $book
     * @param \stdClass $page
     * @return string
     */
    public static function svg_prompt(\stdClass $book, \stdClass $page): string {
        $approach = approaches::of($book);
        $lines = [];
        $lines[] = 'Draw the animated SVG scene for one page of a narrated lesson.';
        $lines[] = '';
        $lines[] = 'LESSON: ' . trim((string)$book->name);
        $lines[] = 'PAGE TITLE: ' . trim((string)$page->title);
        $lines[] = 'LANGUAGE OF EVERY LABEL AND CAPTION: ' . voices::language_name((string)$book->language);
        $lines[] = '';
        $lines[] = 'THE THREE CARDS (read aloud one after the other; stage N plays while card N is read):';
        $n = 0;
        foreach (VISUALLEARNING_CARDS as $card) {
            $n++;
            $text = trim(preg_replace('/\s+/u', ' ', (string)$page->{$card . 'text'}));
            $lines[] = 'CARD ' . $n . ': ' . ($text !== '' ? $text : '(empty)');
        }
        if (!is_array(scene_plan::clean($page->sceneplan ?? null))) {
            // No plan: the page picture's description is the best hint of the layout.
            $scene = trim((string)$page->imageprompt);
            if ($scene !== '') {
                $lines[] = '';
                $lines[] = 'THE PAGE PICTURE SHOWS (a hint for the layout): ' . $scene;
            }
        }
        $plan = scene_plan::drawing_brief($page->sceneplan ?? null, scene_plan::clean_style($book->scenestyle ?? null));
        if ($plan !== '') {
            $lines[] = '';
            $lines[] = $plan;
        }
        $lines[] = '';
        if ($approach->image_master() !== '') {
            $lines[] = 'APPROACH: explain the real mechanism: what happens, why and what changes.';
        } else {
            $lines[] = 'APPROACH: a workplace story. Animate the SKILL or PROCESS it teaches, not the people; simple '
                . 'figure icons only where a role must be shown. ' . self::culture_people($book);
        }
        $lines[] = 'AUDIENCE: ' . self::SVG_LEVELS[learner_levels::of($book)];
        $lines[] = visual_styles::of($book) === 'whiteboard'
            ? 'SCENE LOOK: hand-drawn sketch (follow the HAND-DRAWN rules).'
            : 'SCENE LOOK: clean diagram (the house style).';
        $own = trim((string)($book->imagestyle ?? ''));
        if ($own !== '' && !visual_styles::is_default_text($own)) {
            $lines[] = 'TEACHER\'S ART DIRECTION (use its colours and mood where they fit the house style): '
                . \core_text::substr(preg_replace('/\s+/u', ' ', $own), 0, 400);
        }
        if (($own = self::teacher_instructions($book, 600)) !== '') {
            $lines[] = 'TEACHER\'S INSTRUCTIONS (follow them unless they conflict with accuracy or safety): ' . $own;
        }
        $lines[] = 'Use only facts the cards give; never invent numbers. Answer with the SVG only.';
        $out = implode("\n", $lines);
        return \core_text::strlen($out) > self::MAX_SVG_PROMPT ? \core_text::substr($out, 0, self::MAX_SVG_PROMPT) : $out;
    }

    /**
     * The system prompt for animated SVG scenes (the contract, with a worked example).
     *
     * @return string
     */
    public static function svg_system(): string {
        return (string)file_get_contents(__DIR__ . '/../../schema/svg-animation-system.md');
    }

    /**
     * The optional lesson inputs (learning objectives, prior knowledge, unit code) as brief lines, or ''.
     *
     * @param \stdClass $book
     * @return string ending with a blank line when not empty
     */
    public static function lesson_extras(\stdClass $book): string {
        $lines = [];
        foreach (['objectives' => 'Learning objectives', 'priorknowledge' => 'What the learners already know',
                'unitcode' => 'Unit / outcome code'] as $field => $label) {
            $text = trim(preg_replace('/\s+/u', ' ', (string)($book->{$field} ?? '')));
            if ($text !== '') {
                $lines[] = $label . ': ' . $text;
            }
        }
        return $lines ? implode("\n", $lines) . "\n\n" : '';
    }

    /**
     * The teacher's notes, documents and instructions, as typed or pasted (line breaks kept), or ''.
     *
     * @param \stdClass $book
     * @return string
     */
    public static function teacher_material(\stdClass $book): string {
        $text = str_replace(["\r\n", "\r"], "\n", (string)($book->aiinstructions ?? ''));
        $text = trim(preg_replace("/\n{3,}/", "\n\n", $text));
        return \core_text::substr($text, 0, self::MAX_MATERIAL);
    }

    /**
     * The block that follows the teaching content in the writer's brief and in a draft request ('' without material).
     *
     * @param \stdClass $book
     * @return string starting with blank lines
     */
    public static function material_block(\stdClass $book): string {
        $material = self::teacher_material($book);
        if ($material === '') {
            return '';
        }
        return "\n\nTeacher's notes, documents and instructions (use them as a source of facts, together with the "
            . "teaching content; follow the instructions unless they conflict with accuracy, safety or the output format):\n"
            . "<<<MATERIAL\n" . $material . "\nMATERIAL";
    }

    /** @var int Longest teacher's notes, documents and instructions (characters). */
    public const MAX_MATERIAL = 50000;

    /**
     * The teacher's extra instructions and reference material for the AI, on one line ('' when none, or when longer
     * than $max for a drawing brief, which takes only short instructions).
     *
     * @param \stdClass $book
     * @param int $max longest result (characters)
     * @return string
     */
    public static function teacher_instructions(\stdClass $book, int $max = 50000): string {
        $text = trim(preg_replace('/\s+/u', ' ', (string)($book->aiinstructions ?? '')));
        if (\core_text::strlen($text) > $max) {
            // Too long to be instructions for a drawing (pasted reference material): the page writer uses it instead.
            return $max < self::MAX_MATERIAL ? '' : \core_text::substr($text, 0, $max);
        }
        return $text;
    }
}
