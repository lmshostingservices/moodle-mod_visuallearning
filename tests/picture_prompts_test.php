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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <https://www.gnu.org/licenses/>.

namespace mod_visuallearning;

use mod_visuallearning\local\media;
use mod_visuallearning\local\prompt_builder;
use mod_visuallearning\local\visual_styles;

/**
 * Picture prompts for GPT Image, the callouts choice, and pictures kept current when prompts change.
 *
 * @package    mod_visuallearning
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(prompt_builder::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(media::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(visual_styles::class)]
final class picture_prompts_test extends \advanced_testcase {
    /**
     * A lesson with one page.
     *
     * @param array $settings
     * @return array [book, page, context]
     */
    protected function lesson(array $settings = []): array {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_visuallearning');
        $book = $gen->create_instance(['course' => $course->id] + $settings);
        $record = $gen->create_page($book->id, []);
        $book = $DB->get_record('visuallearning', ['id' => $book->id]);
        $cm = get_coursemodule_from_instance('visuallearning', $book->id);
        return [$book, $DB->get_record('visuallearning_pages', ['id' => $record->id]), \context_module::instance($cm->id)];
    }

    public function test_every_style_keeps_its_look_and_the_lessons_layout(): void {
        $this->resetAfterTest();
        [$book] = $this->lesson(['teachingapproach' => 'science', 'callouts' => 1, 'imagestyle' => '',
            'scenestyle' => json_encode(['palette' => 'amber = first nerve cell',
                'recurring' => 'Front view of a see-through upper body; the broken left forearm held out to the side.',
                'subject' => 'biology'])]);
        $looks = ['illustration' => 'Editorial textbook illustration', 'realistic' => 'Candid documentary photograph',
            'model3d' => 'Premium scientific or engineering 3D visualisation',
            'diagram' => 'Precise flat-vector educational schematic', 'whiteboard' => 'Skilled teacher\'s sketchnote'];
        foreach ($looks as $style => $look) {
            $book->visualstyle = $style;
            $prompt = prompt_builder::image_prompt($book, 'The nerve runs to the spinal cord and stops there.', 2000);
            $this->assertStringContainsString('LOOK: ' . $look, $prompt, $style);
            $this->assertStringContainsString('SAME ON EVERY PAGE OF THIS LESSON: Front view of a see-through upper '
                . 'body; the broken left forearm held out to the side.', $prompt, $style);
            $this->assertStringNotContainsString('adult learners', $prompt, $style);
        }
    }

    public function test_old_default_looks_give_way_to_the_new_ones(): void {
        $this->resetAfterTest();
        [$book] = $this->lesson(['learnerlevel' => 'secondary', 'visualstyle' => 'illustration',
            'imagestyle' => visual_styles::LEGACY_DEFAULTS[0]]);
        $this->assertSame(get_string('defaultimagestyle', 'mod_visuallearning'), visual_styles::direction($book));
        $book->visualstyle = 'realistic';
        $book->imagestyle = visual_styles::LEGACY_DEFAULTS[1];
        $this->assertSame(get_string('visualstyle_realistic_direction', 'mod_visuallearning'),
            visual_styles::direction($book));
        // A site that still holds the old default gets the new one too.
        set_config('defaultimagestyle', visual_styles::LEGACY_DEFAULTS[0], 'mod_visuallearning');
        $book->visualstyle = 'illustration';
        $book->imagestyle = '';
        $this->assertStringNotContainsString('adult learners', visual_styles::direction($book));
        // A teacher's own words are kept.
        $book->imagestyle = 'Bold flat colours.';
        $this->assertSame('Bold flat colours.', visual_styles::direction($book));
    }

    public function test_every_image_prompt_fits_and_keeps_its_rules(): void {
        $this->resetAfterTest();
        [$book] = $this->lesson(['teachingapproach' => 'science', 'learnerlevel' => 'advanced', 'callouts' => 1]);
        $scene = str_repeat('A long and detailed cutaway of a pump with every valve and its flow shown. ', 20)
            . 'Callouts: "Inlet", "Outlet".';
        foreach (['illustration', 'realistic', 'model3d', 'diagram', 'whiteboard'] as $style) {
            $book->visualstyle = $style;
            $prompt = prompt_builder::image_prompt($book, $scene, 2000);
            $this->assertLessThanOrEqual(2000, \core_text::strlen($prompt), $style);
            $this->assertStringContainsString('WORDS IN THE PICTURE: Exactly the words in quotes', $prompt, $style);
            $this->assertStringContainsString('RULES: Accuracy first', $prompt, $style);
            $this->assertStringContainsString('AVOID: ', $prompt, $style);
            $this->assertMatchesRegularExpression('/LOOK: \\S/', $prompt, $style);
            $this->assertStringNotContainsString('never a photograph', $prompt, $style);
        }
    }

    public function test_young_learners_get_fewer_callouts(): void {
        $this->resetAfterTest();
        [$book] = $this->lesson(['learnerlevel' => 'primary', 'callouts' => 1]);
        $prompt = prompt_builder::image_prompt($book, 'A tap and a sink.', 2000);
        $this->assertStringContainsString('Age-appropriate', $prompt);
        $this->assertStringContainsString("'Callouts:' and up to two exact labels", prompt_builder::writer_prompt($book));
        $edit = prompt_builder::edit_prompt($book, 'Water now runs from the tap.', 2000);
        $this->assertStringContainsString('Keep the existing callouts exactly as they are', $edit);
    }

    public function test_card_pictures_edit_the_picture_before(): void {
        $this->resetAfterTest();
        [$book] = $this->lesson(['visualstyle' => 'diagram', 'teachingapproach' => 'science', 'callouts' => 1]);
        $edit = prompt_builder::edit_prompt($book, 'Add the return pipe.', 2000);
        $this->assertStringStartsWith('EDIT THE ATTACHED PICTURE.', $edit);
        $this->assertStringContainsString('Keep the existing callouts exactly as they are', $edit);
    }

    public function test_page_pictures_name_what_the_page_teaches(): void {
        $this->resetAfterTest();
        [$book, $page] = $this->lesson(['teachingapproach' => 'science']);
        $page->title = 'The signal races to the spinal cord';
        $prompt = media::image_prompt($book, $page);
        $this->assertStringContainsString('THIS PAGE TEACHES: The signal races to the spinal cord (only this step', $prompt);
        // The cover teaches the whole lesson, so it names no single step.
        $this->assertStringNotContainsString('THIS PAGE TEACHES', media::image_prompt($book, null));
        // An edit says what must stay the same.
        $edit = prompt_builder::edit_prompt($book, 'The pulses reach the neck.', 2000);
        $this->assertStringContainsString('KEEP UNCHANGED: the camera angle and composition', $edit);
    }

    public function test_the_image_model_is_recorded_with_each_picture(): void {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAABCAYAAAD0In+KAAAAEUlEQVR4nGP4z8DwnwEKGBgAQ+8C/l0IGQ0AAAAASUVORK5CYII=');
        $receipt = \mod_visuallearning\local\ai\requests::image_receipt(['x-model' => 'gpt-image-2.5-flare<script>',
            'x-usage-output-tokens' => '712', 'x-usage-input-tokens' => 'n/a', 'x-provider-cost-usd' => '0.0214'], $png, 'png');
        $this->assertSame('gpt-image-2.5-flarescript', $receipt['model']);
        $this->assertSame(['output' => 712], $receipt['tokens']);
        $this->assertSame(0.0214, $receipt['costusd']);
        $this->assertSame('2x1', $receipt['size']);
        $this->assertSame('png', $receipt['type']);
    }

    public function test_pictures_stay_current_when_the_prompts_change(): void {
        $this->resetAfterTest();
        [$book, $page, $context] = $this->lesson();
        // A picture made with the previous version's prompt.
        media::save_image($context, media::AREA_IMAGE, (int)$page->id, 'an older prompt', 'png bytes', 'png');
        media::save_image($context, media::AREA_COVER, 0, 'an older cover prompt', 'png bytes', 'png');
        $this->assertFalse(media::image_current($context, media::AREA_IMAGE, (int)$page->id, media::image_prompt($book, $page)));
        media::restamp_all();
        $this->assertTrue(media::image_current($context, media::AREA_IMAGE, (int)$page->id, media::image_prompt($book, $page)));
        $this->assertTrue(media::image_current($context, media::AREA_COVER, 0, media::image_prompt($book, null)));
        $this->assertSame([], media::plan($book, $context, 'missing', 0, ['image']));
        // Running it again changes nothing.
        media::restamp_all();
        $this->assertTrue(media::image_current($context, media::AREA_IMAGE, (int)$page->id, media::image_prompt($book, $page)));
    }

    public function test_callouts_choice_is_saved(): void {
        $this->resetAfterTest();
        require_once(__DIR__ . '/../lib.php');
        $data = (object)['callouts' => '1', 'visualstyle' => 'illustration'];
        visuallearning_clean_instance_data($data);
        $this->assertSame(1, $data->callouts);
        $data = (object)['callouts' => '', 'visualstyle' => 'illustration'];
        visuallearning_clean_instance_data($data);
        $this->assertSame(0, $data->callouts);
    }

    public function test_teacher_instructions_reach_the_writer_and_the_drawings(): void {
        $this->resetAfterTest();
        [$book, $page] = $this->lesson(['picturemode' => 'svg', 'teachingapproach' => 'science',
            'aiinstructions' => "Use the terms in AS/NZS 3000.\nNo brand names."]);
        $block = "<<<MATERIAL\nUse the terms in AS/NZS 3000.\nNo brand names.\nMATERIAL";
        // The writer gets the notes as they were pasted, line breaks kept, after the teaching content.
        $this->assertStringContainsString($block, prompt_builder::writer_prompt($book));
        $this->assertStringContainsString($block, prompt_builder::material_block($book));
        $this->assertStringNotContainsString('AS/NZS', prompt_builder::lesson_extras($book));
        $this->assertStringContainsString("TEACHER'S INSTRUCTIONS (follow them unless they conflict with accuracy or safety): "
            . 'Use the terms in AS/NZS 3000. No brand names.', prompt_builder::svg_prompt($book, $page));
        // A scene stays current when only the instructions change: nothing is made again for it.
        $key = media::svg_key($book, $page);
        $book->aiinstructions = 'Something else.';
        $this->assertSame($key, media::svg_key($book, $page));
    }
}
