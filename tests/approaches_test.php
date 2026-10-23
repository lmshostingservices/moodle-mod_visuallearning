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
namespace mod_visuallearning;

use mod_visuallearning\local\ai\requests;
use mod_visuallearning\local\approaches;
use mod_visuallearning\local\prompt_builder;
use mod_visuallearning\local\visual_styles;

/**
 * Tests for teaching approaches and visual styles.
 *
 * @package    mod_visuallearning
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(approaches::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(visual_styles::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(prompt_builder::class)]
final class approaches_test extends \advanced_testcase {
    /**
     * A lesson about inductance.
     *
     * @param string|null $approach
     * @param string|null $style
     * @return \stdClass
     */
    private function book(?string $approach, ?string $style): \stdClass {
        $book = (object)[
            'id' => 1,
            'name' => 'Why current lags voltage in an inductive AC circuit',
            'industry' => 'Electrotechnology',
            'subindustry' => '',
            'language' => 'en-AU',
            'imageculture' => 'language',
            'maxpages' => 8,
            'imagestyle' => get_string('defaultimagestyle', 'mod_visuallearning'),
            'styleguide' => 'Voltage blue, current red.',
            'characters' => json_encode([['name' => 'Maya', 'description' => 'electrician in hi-vis']]),
            'sourcecontent' => 'Inductors oppose changes in current.',
            'enablequiz' => 1,
            'enablerecap' => 1,
            'questiontypes' => 'truefalse,pick',
            'pickimages' => 1,
        ];
        if ($approach !== null) {
            $book->teachingapproach = $approach;
        }
        if ($style !== null) {
            $book->visualstyle = $style;
        }
        return $book;
    }

    /**
     * Lessons made before approaches existed are workplace stories in the illustration style: same cards, and a
     * picture prompt with no words in the picture.
     */
    public function test_existing_lessons_unchanged(): void {
        $old = $this->book(null, null);
        $new = $this->book('story', 'illustration');
        $scene = 'Maya tests a motor at a switchboard.';
        $this->assertSame(prompt_builder::image_prompt($old, $scene, 2000), prompt_builder::image_prompt($new, $scene, 2000));
        $prompt = prompt_builder::image_prompt($old, $scene, 2000);
        $this->assertStringStartsWith('Create a hand-illustrated teaching picture', $prompt);
        $this->assertStringContainsString('WORDS IN THE PICTURE: None', $prompt);
        $this->assertStringContainsString('any text, letters, numbers, logos or watermarks', $prompt);
        $this->assertStringContainsString('Picture style chosen by the teacher: Illustration', prompt_builder::writer_prompt($new));
        $this->assertStringNotContainsString('Teaching approach:', prompt_builder::writer_prompt($new));
        $this->assertSame('The idea', approaches::card_label($old, 'concept'));
        $this->assertSame('Put it into action', approaches::card_label($old, 'action'));
        $this->assertSame([], requests::approach_fields($old));
    }

    /**
     * The scientific approach explains the mechanism: its own cards, opener and rules, no whole cast.
     */
    public function test_scientific_explanation(): void {
        $this->resetAfterTest();
        $book = $this->book('science', 'diagram');
        $this->assertSame('Key point 1', approaches::card_label($book, 'concept'));
        $this->assertSame('Key point 2', approaches::card_label($book, 'action'));
        $this->assertSame('Key point 3', approaches::card_label($book, 'outcome'));

        $file = prompt_builder::writer_prompt($book);
        $this->assertStringContainsString('Teaching approach: Explain how it works', $file);
        $this->assertStringContainsString('Picture style chosen by the teacher: Technical model', $file);
        $this->assertStringContainsString('KEY POINT 2 - how it works', $file);
        $this->assertStringContainsString('No invented people', $file);
        $this->assertStringContainsString('Accuracy:', $file);
        $this->assertStringContainsString('SQUARE explanatory picture', $file);
        $this->assertStringContainsString('PICTURE WORDS: none', $file);
        $this->assertStringNotContainsString('Use a named character', $file);

        // A technical model goes to the image model (GPT Image) as a clean schematic.
        $scene = 'A coil with field lines; voltage and current sine waves, current a quarter cycle behind. '
            . 'Not shown: people.';
        $prompt = prompt_builder::image_prompt($book, $scene, 2000);
        $this->assertStringStartsWith('Create a clean technical diagram', $prompt);
        $this->assertStringContainsString('EXPLAIN: A coil with field lines', $prompt);
        $this->assertStringContainsString('LOOK: Precise flat-vector educational schematic', $prompt);
        $this->assertStringContainsString('RULES: Accuracy first', $prompt);
        $this->assertStringContainsString("Show only this page's step", $prompt);
        $this->assertStringContainsString('Arrows point only the real way things move', $prompt);
        $this->assertStringNotContainsString('Maya', $prompt);
        $this->assertLessThanOrEqual(2000, \core_text::strlen($prompt));
        // Someone the scene names is still drawn as described.
        $this->assertStringContainsString('Maya - electrician', prompt_builder::image_prompt($book, 'Maya reads a meter.', 2000));
        // The writer is asked for the lesson's fixed layout and for where each pathway stops.
        $writer = prompt_builder::writer_prompt($book);
        $this->assertStringContainsString('"scene_style"', $writer);
        $this->assertStringContainsString("'Not shown:'", $writer);
    }

    /**
     * Any approach works with any style, and callouts are the teacher's choice.
     */
    public function test_independent_choices(): void {
        $book = $this->book('science', 'illustration');
        $prompt = prompt_builder::image_prompt($book, 'A coil and its field.', 2000);
        $this->assertStringContainsString('Explain the concept at a glance', $prompt);
        $this->assertStringContainsString('AVOID: photographic skin', $prompt);
        $this->assertStringContainsString('gouache', $prompt);
        $this->assertStringContainsString('WORDS IN THE PICTURE: None', $prompt);

        $book->callouts = 1;
        $prompt = prompt_builder::image_prompt($book, 'A coil and its field. Callouts: "Coil", "Field".', 2000);
        $this->assertStringContainsString('WORDS IN THE PICTURE: Only the words in quotes in the scene, spelled exactly, in '
            . 'English (Australia); nothing else', $prompt);
        $this->assertStringContainsString('logos, watermarks or misspelled words', $prompt);
        $this->assertStringContainsString("'Callouts:'", prompt_builder::writer_prompt($book));
        // Answer options never carry words.
        $square = prompt_builder::image_prompt($book, 'A coil.', 2000, \mod_visuallearning\local\media::OPTION_ASPECT);
        $this->assertStringContainsString('WORDS IN THE PICTURE: None', $square);
        $this->assertStringContainsString('A square tile', $square);

        $story = $this->book('story', 'realistic');
        $prompt = prompt_builder::image_prompt($story, 'Maya tests a motor.', 2000);
        $this->assertStringStartsWith('Create a realistic documentary photograph', $prompt);
        $this->assertStringContainsString('Maya - electrician', $prompt);
        $this->assertStringContainsString('following safe work practice', $prompt);
        $this->assertStringContainsString('hands outside any machine or danger zone', $prompt);
        $this->assertStringNotContainsString('Explain the concept', $prompt);
        // The illustration default text is not used as the look of a photograph.
        $this->assertStringNotContainsString('gouache', $prompt);
    }

    /**
     * Unknown keys fall back to the defaults; LMS Labs AI gets the choice as text until it lists it.
     */
    public function test_defaults_and_lmslabs_fallback(): void {
        $this->resetAfterTest();
        $this->assertSame('story', approaches::clean('nonsense'));
        $this->assertSame('illustration', visual_styles::clean(''));
        $book = $this->book('science', 'diagram');
        $fields = requests::approach_fields($book);
        $this->assertArrayNotHasKey('teachingApproach', $fields);
        $this->assertStringContainsString('scientific and technical explanation', $fields['imageStyle']);
        $this->assertSame('illustration', requests::image_style($book));
    }

    /**
     * The hand-drawn sketch style: the image model gets the sketchnote look.
     */
    public function test_whiteboard_sketch(): void {
        $this->resetAfterTest();
        $this->assertSame('whiteboard', visual_styles::clean('whiteboard'));
        $this->assertArrayHasKey('whiteboard', visual_styles::options());
        $book = $this->book('science', 'whiteboard');
        $prompt = prompt_builder::image_prompt($book, 'A capillary between two tubes, small fragments moving fastest.', 2000);
        $this->assertStringStartsWith('Create a hand-drawn sketchnote', $prompt);
        $this->assertStringContainsString('graph paper', $prompt);
        $this->assertLessThanOrEqual(2000, \core_text::strlen($prompt));
        $story = $this->book('story', 'whiteboard');
        $this->assertStringNotContainsString('gouache', prompt_builder::image_prompt($story, 'Maya tests a motor.', 2000));
        $this->assertStringContainsString('Hand-drawn sketch', prompt_builder::writer_prompt($story));
        $this->assertSame('illustration', requests::image_style($book));
    }

    /**
     * The prompt file asks for striking pictures, in both approaches, without changing how pictures are wrapped.
     */
    public function test_prompt_file_asks_for_striking_pictures(): void {
        $this->resetAfterTest();
        $story = prompt_builder::writer_prompt($this->book('story', 'illustration'));
        foreach (['HERO:', 'CAMERA:', 'LIGHT:', 'MOMENT:', 'REVEAL'] as $part) {
            $this->assertStringContainsString($part, $story);
        }
        $science = prompt_builder::writer_prompt($this->book('science', 'diagram'));
        foreach (['HERO:', 'REVEAL:', 'LIGHT AND COLOUR AS INFORMATION:', 'MOTION CUES:', 'CAMERA:'] as $part) {
            $this->assertStringContainsString($part, $science);
        }
        $this->assertStringContainsString('a reveal of the mechanism, a motion cue', $science);
    }
}
