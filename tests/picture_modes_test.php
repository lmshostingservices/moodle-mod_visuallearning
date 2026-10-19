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

use mod_visuallearning\local\book_data;
use mod_visuallearning\local\diagram;
use mod_visuallearning\local\importer;
use mod_visuallearning\local\media;
use mod_visuallearning\local\picture_modes;
use mod_visuallearning\local\prompt_builder;

/**
 * Tests for the picture modes: one picture per page, one picture per card, and animated diagrams.
 *
 * @package    mod_visuallearning
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(picture_modes::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(diagram::class)]
final class picture_modes_test extends \advanced_testcase {
    /**
     * Sample specifications (valid ones, and "bad" and "badwave" that are not).
     *
     * @return array
     */
    protected function specs(): array {
        return json_decode(file_get_contents(__DIR__ . '/fixtures/diagrams.json'), true);
    }

    /**
     * A lesson with one page.
     *
     * @param array $settings
     * @param array $page
     * @return array [lesson, page, context]
     */
    protected function lesson(array $settings = [], array $page = []): array {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_visuallearning');
        $book = $gen->create_instance(['course' => $course->id] + $settings);
        $record = $gen->create_page($book->id, $page);
        $book = $DB->get_record('visuallearning', ['id' => $book->id]);
        $cm = get_coursemodule_from_instance('visuallearning', $book->id);
        return [$book, $DB->get_record('visuallearning_pages', ['id' => $record->id]), \context_module::instance($cm->id)];
    }

    public function test_modes_are_cleaned(): void {
        $this->assertSame('single', picture_modes::clean(null));
        $this->assertSame('single', picture_modes::clean('slideshow'));
        $this->assertSame('sequence', picture_modes::clean('sequence'));
        $this->assertSame('animated', picture_modes::clean('animated'));
        $this->assertSame(['single', 'sequence', 'animated'], array_keys(picture_modes::options()));
    }

    public function test_every_sample_diagram_is_accepted_and_bad_ones_are_refused(): void {
        foreach ($this->specs() as $name => $spec) {
            $clean = diagram::clean($spec);
            if (str_starts_with($name, 'bad')) {
                $this->assertNull($clean, $name);
                continue;
            }
            $this->assertIsArray($clean, $name);
            $this->assertSame($spec['type'], $clean['type'], $name);
            // Cleaning is stable: a cleaned diagram cleans to itself.
            $this->assertSame($clean, diagram::clean(json_decode(diagram::json($clean), true)), $name);
        }
        $this->assertNull(diagram::clean('not a diagram'));
        $this->assertNull(diagram::json(['type' => 'waveform']));
    }

    public function test_diagram_values_are_kept_in_range(): void {
        $clean = diagram::clean(['type' => 'waveform', 'cycles' => 40, 'waves' => [
            ['label' => str_repeat('Very long label ', 10), 'colour' => 'magenta', 'amplitude' => 9, 'phase' => 900,
                'card' => 7],
        ]]);
        $wave = $clean['waves'][0];
        $this->assertLessThanOrEqual(diagram::MAX_LABEL, \core_text::strlen($wave['label']));
        $this->assertContains($wave['colour'], diagram::COLOURS);
        $this->assertLessThanOrEqual(1, $wave['amplitude']);
        $this->assertLessThanOrEqual(360, $wave['phase']);
        $this->assertSame(3, $wave['card']);
        $this->assertLessThanOrEqual(4, $clean['cycles']);
        // Markup in labels never survives.
        $clean = diagram::clean(['type' => 'bars', 'bars' => [['label' => '<script>x</script>A', 'value' => 1],
            ['label' => 'B', 'value' => 2]]]);
        $this->assertStringNotContainsString('<', $clean['bars'][0]['label']);
    }

    public function test_import_keeps_card_prompts_and_diagrams(): void {
        $page = ['title' => 'T', 'concept' => 'C.', 'action' => 'A.', 'outcome' => 'O.', 'image_prompt' => 'Scene.',
            'card_image_prompts' => ['Card one scene.', 'Same picture, same viewpoint and layout. Add a coil.',
                'Same picture, same viewpoint and layout. Add the field lines.'],
            'diagram' => $this->specs()['lag']];
        [$data, $errors] = importer::parse(json_encode(['pages' => [$page]]), 10);
        $this->assertSame([], $errors);
        $parsed = $data['pages'][0];
        $this->assertCount(3, $parsed['card_image_prompts']);
        $this->assertSame('waveform', $parsed['diagram']['type']);
        $fields = importer::picture_fields($parsed);
        $this->assertCount(3, json_decode($fields['cardprompts'], true));
        $this->assertSame('waveform', json_decode($fields['diagram'], true)['type']);

        // Two prompts, or a diagram the player cannot draw, are left out rather than half kept.
        $page['card_image_prompts'] = ['One.', 'Two.'];
        $page['diagram'] = ['type' => 'hologram'];
        [$data] = importer::parse(json_encode(['pages' => [$page]]), 10);
        $this->assertSame([], $data['pages'][0]['card_image_prompts']);
        $this->assertNull($data['pages'][0]['diagram']);
        $this->assertSame(['cardprompts' => null, 'diagram' => null], importer::picture_fields($data['pages'][0]));
    }

    public function test_prompt_file_asks_for_the_mode_only_when_chosen(): void {
        $this->resetAfterTest();
        [$single] = $this->lesson();
        [$sequence] = $this->lesson(['picturemode' => 'sequence']);
        [$animated] = $this->lesson(['picturemode' => 'animated']);
        $plain = prompt_builder::chatgpt_prompt($single);
        $this->assertStringNotContainsString('STEP 6', $plain);
        $this->assertStringNotContainsString('card_image_prompts', $plain);
        $this->assertStringNotContainsString('"diagram"', $plain);

        $cards = prompt_builder::chatgpt_prompt($sequence);
        $this->assertStringContainsString('STEP 6 - ONE PICTURE PER CARD', $cards);
        $this->assertStringContainsString('"card_image_prompts": ["string", "string", "string"]', $cards);
        $this->assertStringContainsString('"Same picture, same', $cards);

        $diagrams = prompt_builder::chatgpt_prompt($animated);
        $this->assertStringContainsString('STEP 6 - ANIMATED DIAGRAMS', $diagrams);
        $this->assertStringContainsString('"diagram":', $diagrams);
        foreach (diagram::TYPES as $type) {
            $this->assertStringContainsString('"' . $type . '"', $diagrams);
        }
    }

    public function test_edit_prompt_keeps_the_picture_and_fits(): void {
        $this->resetAfterTest();
        [$book] = $this->lesson(['picturemode' => 'sequence']);
        $prompt = prompt_builder::edit_prompt($book, 'Add a red arrow showing the current.', 2000);
        $this->assertStringStartsWith('EDIT THE ATTACHED PICTURE.', $prompt);
        $this->assertStringContainsString('Add a red arrow showing the current.', $prompt);
        $long = prompt_builder::edit_prompt($book, str_repeat('Add one more detail. ', 80), 2000);
        $this->assertLessThanOrEqual(2000, \core_text::strlen($long));
    }

    public function test_card_pictures_chain_and_go_out_of_date_together(): void {
        $this->resetAfterTest();
        $prompts = ['A workbench with a coil.', 'Same picture, same viewpoint and layout. Add a battery.',
            'Same picture, same viewpoint and layout. Add glowing field lines.'];
        [$book, $page, $context] = $this->lesson(['picturemode' => 'sequence'], ['cardprompts' => json_encode($prompts)]);

        $chain = media::card_chain($context, $book, $page);
        $this->assertSame([1, 2, 3], array_keys($chain));
        $this->assertSame('', $chain[1]['reference']);
        $this->assertStringStartsWith('EDIT THE ATTACHED PICTURE.', $chain[2]['prompt']);
        $this->assertNull($chain[1]['file']);

        media::save_card($context, (int)$page->id, 1, $chain[1]['prompt'], '', 'png-one', 'png');
        $chain = media::card_chain($context, $book, $page);
        $one = $chain[1]['file'];
        $this->assertSame($one->get_contenthash(), $chain[2]['reference']);
        media::save_card($context, (int)$page->id, 2, $chain[2]['prompt'], $one->get_contenthash(), 'png-two', 'png');
        $chain = media::card_chain($context, $book, $page);
        $two = $chain[2]['file'];
        $this->assertNotNull($two);
        media::save_card($context, (int)$page->id, 3, $chain[3]['prompt'], $two->get_contenthash(), 'png-three', 'png');
        $chain = media::card_chain($context, $book, $page);
        $this->assertCount(3, array_filter(array_column($chain, 'file')));

        // A new card 1 picture makes cards 2 and 3 out of date: they were made by editing the old one.
        media::save_card($context, (int)$page->id, 1, $chain[1]['prompt'], '', 'png-one-again', 'png');
        $chain = media::card_chain($context, $book, $page);
        $this->assertNotNull($chain[1]['file']);
        $this->assertNull($chain[2]['file']);
        $this->assertNull($chain[3]['file']);

        // Only lessons in this mode have card pictures.
        $book->picturemode = 'single';
        $this->assertSame([], media::card_chain($context, $book, $page));
    }

    public function test_player_gets_card_pictures_diagrams_and_numbered_cards(): void {
        global $DB;
        $this->resetAfterTest();
        $prompts = ['Scene one.', 'Same picture, same viewpoint and layout. Two.', 'Same picture, same viewpoint and layout. Three.'];
        [$book, $page, $context] = $this->lesson(['picturemode' => 'sequence', 'teachingapproach' => 'science'],
            ['cardprompts' => json_encode($prompts), 'diagram' => diagram::json($this->specs()['ac'])]);
        $chain = media::card_chain($context, $book, $page);
        media::save_card($context, (int)$page->id, 1, $chain[1]['prompt'], '', 'png-one', 'png');
        $cm = get_coursemodule_from_instance('visuallearning', $book->id);
        $data = book_data::player($book, $cm, $context, 0);
        $this->assertTrue($data['numbered']);
        $cards = $data['pages'][0]['cards'];
        $this->assertStringContainsString('/card/', $cards[0]['image']);
        $this->assertSame('', $cards[1]['image']);
        // The diagram is only drawn in the animated mode.
        $this->assertNull($data['pages'][0]['diagram']);

        $DB->set_field('visuallearning', 'picturemode', 'animated', ['id' => $book->id]);
        $DB->set_field('visuallearning', 'teachingapproach', 'story', ['id' => $book->id]);
        $book = $DB->get_record('visuallearning', ['id' => $book->id]);
        $data = book_data::player($book, $cm, $context, 0);
        $this->assertFalse($data['numbered']);
        $this->assertSame('waveform', $data['pages'][0]['diagram']['type']);
        $this->assertSame('', $data['pages'][0]['cards'][0]['image']);
    }
}
