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
     * Lessons made before approaches existed (and workplace stories in the illustration style) make exactly the
     * same prompts, so none of their pictures needs making again.
     */
    public function test_existing_lessons_unchanged(): void {
        $old = $this->book(null, null);
        $new = $this->book('story', 'illustration');
        $this->assertSame(prompt_builder::IMAGE_GUARDRAILS, prompt_builder::image_rules($old));
        $scene = 'Maya tests a motor at a switchboard.';
        $this->assertSame(prompt_builder::image_prompt($old, $scene, 2000), prompt_builder::image_prompt($new, $scene, 2000));
        $this->assertStringContainsString('Illustration style chosen by the teacher:', prompt_builder::chatgpt_prompt($new));
        $this->assertStringNotContainsString('Teaching approach:', prompt_builder::chatgpt_prompt($new));
        $this->assertSame('The idea', approaches::card_label($old, 'concept'));
        $this->assertSame('Put it into action', approaches::card_label($old, 'action'));
        $this->assertSame([], requests::approach_fields($old));
    }

    /**
     * The scientific approach explains the mechanism: its own cards, master prompt and rules, no whole cast.
     */
    public function test_scientific_explanation(): void {
        $book = $this->book('science', 'diagram');
        $this->assertSame('Key point 1', approaches::card_label($book, 'concept'));
        $this->assertSame('Key point 2', approaches::card_label($book, 'action'));
        $this->assertSame('Key point 3', approaches::card_label($book, 'outcome'));

        $file = prompt_builder::chatgpt_prompt($book);
        $this->assertStringContainsString('Teaching approach: Scientific and technical explanation', $file);
        $this->assertStringContainsString('Visual style chosen by the teacher: Technical diagram', $file);
        $this->assertStringContainsString('KEY POINT 2 - how it works', $file);
        $this->assertStringContainsString('No invented people', $file);
        $this->assertStringContainsString('Accuracy:', $file);
        $this->assertStringContainsString('SQUARE explanatory picture', $file);
        $this->assertStringNotContainsString('Use a named character', $file);

        $scene = 'Two-panel diagram: a coil with field lines; voltage and current sine waves, current a quarter '
            . 'cycle behind. Accuracy: 90 degree lag. Exclude: people.';
        $prompt = prompt_builder::image_prompt($book, $scene, 2000);
        $this->assertStringContainsString('VISUAL EXPLANATION:', $prompt);
        $this->assertStringContainsString('EXPLAIN: Two-panel', $prompt);
        $this->assertStringContainsString('TECHNICAL DIAGRAM', $prompt);
        $this->assertStringContainsString('ART STYLE: Modern professional educational diagram', $prompt);
        $this->assertStringNotContainsString('Maya', $prompt);
        $this->assertStringNotContainsString('Do NOT include any text', $prompt);
        $this->assertLessThanOrEqual(2000, \core_text::strlen($prompt));
        // Someone the scene names is still drawn as described.
        $this->assertStringContainsString('Maya - electrician', prompt_builder::image_prompt($book, 'Maya reads a meter.', 2000));
    }

    /**
     * Any approach works with any style: a scientific explanation in the illustration style.
     */
    public function test_independent_choices(): void {
        $book = $this->book('science', 'illustration');
        $prompt = prompt_builder::image_prompt($book, 'A coil and its field.', 2000);
        $this->assertStringContainsString('VISUAL EXPLANATION:', $prompt);
        $this->assertStringContainsString('This must be an ILLUSTRATION', $prompt);
        $this->assertStringContainsString('gouache', $prompt);

        $story = $this->book('story', 'diagram');
        $prompt = prompt_builder::image_prompt($story, 'Maya tests a motor.', 2000);
        $this->assertStringContainsString('TECHNICAL DIAGRAM', $prompt);
        $this->assertStringContainsString('Maya - electrician', $prompt);
        $this->assertStringNotContainsString('VISUAL EXPLANATION:', $prompt);
        // The illustration default text is not used as the art direction of a diagram.
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
     * The whiteboard sketch style: its own look in every picture prompt, for either approach, and sent to LMS Labs
     * only once it lists it.
     */
    public function test_whiteboard_sketch(): void {
        $this->resetAfterTest();
        $this->assertSame('whiteboard', visual_styles::clean('whiteboard'));
        $this->assertArrayHasKey('whiteboard', visual_styles::options());
        $book = $this->book('science', 'whiteboard');
        $prompt = prompt_builder::image_prompt($book, 'A capillary between two tubes, small fragments moving fastest.', 2000);
        $this->assertStringContainsString('WHITEBOARD SKETCH', $prompt);
        $this->assertStringContainsString('marker outlines', $prompt);
        $this->assertStringContainsString('VISUAL EXPLANATION:', $prompt);
        $this->assertLessThanOrEqual(2000, \core_text::strlen($prompt));
        $story = $this->book('story', 'whiteboard');
        $prompt = prompt_builder::image_prompt($story, 'Maya tests a motor.', 2000);
        $this->assertStringContainsString('WHITEBOARD SKETCH', $prompt);
        $this->assertStringNotContainsString('gouache', $prompt);
        $this->assertStringContainsString('Whiteboard sketch', prompt_builder::chatgpt_prompt($story));
        $this->assertSame('illustration', requests::image_style($book));
    }
}
