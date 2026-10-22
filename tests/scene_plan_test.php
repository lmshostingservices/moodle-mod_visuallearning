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

use mod_visuallearning\local\scene_plan;
use mod_visuallearning\local\prompt_builder;
use mod_visuallearning\local\importer;

/**
 * Scene plans survive the real import, storage and prompt-building paths.
 *
 * @package    mod_visuallearning
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(scene_plan::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(prompt_builder::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(importer::class)]
final class scene_plan_test extends \advanced_testcase {
    /**
     * A harmless topic-independent plan (1.8 form).
     *
     * @return array
     */
    private function plan(): array {
        $stage = ['do' => 'Water in a basin flows down the outlet', 'labels' => ['Water', 'Outlet']];
        return ['type' => 'flow', 'layout' => 'A basin above a pipe', 'stages' => [$stage, $stage, $stage],
            'must' => 'Flow follows the pipe'];
    }

    /**
     * Validate structure, label lengths, safe plain text, the "none" marker and plans written by 1.7.2.
     */
    public function test_plan_validation_and_plain_text(): void {
        $plan = $this->plan();
        $this->assertEquals($plan, scene_plan::clean(json_encode($plan)));
        $this->assertNull(scene_plan::clean(null));
        $this->assertNull(scene_plan::clean('{broken'));
        $this->assertSame(scene_plan::NONE, scene_plan::clean('none'));
        $this->assertSame(scene_plan::NONE, scene_plan::json('none'));
        $bad = $plan;
        $bad['stages'][] = $bad['stages'][0];
        $this->assertNull(scene_plan::clean($bad));
        $bad = $plan;
        $bad['stages'][0]['labels'] = ['one two three four five six'];
        $this->assertNull(scene_plan::clean($bad));
        $bad['stages'][0]['labels'] = [str_repeat('é', 33)];
        $this->assertNull(scene_plan::clean($bad));
        $bad = $plan;
        $bad['layout'] = str_repeat('x', 601);
        $this->assertNull(scene_plan::clean($bad));
        // A fourth label is dropped, never an error.
        $many = $plan;
        $many['stages'][0]['labels'] = ['A', 'B', 'C', 'D'];
        $this->assertSame(['A', 'B', 'C'], scene_plan::clean($many)['stages'][0]['labels']);
        $plan['layout'] = '<b>A basin</b>';
        $plan['unknown'] = '<script>ignored</script>';
        $plan['realtime'] = true;
        $clean = scene_plan::clean($plan);
        $this->assertSame('A basin', $clean['layout']);
        $this->assertArrayNotHasKey('unknown', $clean);
        $this->assertTrue($clean['realtime']);

        // 1.7.2 plans are read too.
        $old = ['shows' => 'Water in a basin', 'moves' => 'flows down the outlet', 'labels' => ['Water']];
        $legacy = scene_plan::clean(['layout' => 'A basin', 'stages' => [$old, $old, $old], 'colours' => 'blue = water',
            'realtime' => false, 'accuracy' => 'Flow follows the pipe', 'exclude' => 'numbers']);
        $this->assertSame('Water in a basin flows down the outlet', $legacy['stages'][0]['do']);
        $this->assertSame('Flow follows the pipe', $legacy['must']);
        $this->assertSame('blue = water', $legacy['colours']);
        $this->assertArrayNotHasKey('realtime', $legacy);

        // The lesson's scene style keeps only known subjects.
        $this->assertSame(['palette' => 'blue = water', 'recurring' => '', 'subject' => 'earth'],
            scene_plan::clean_style(['palette' => 'blue = water', 'subject' => 'earth']));
        $this->assertSame('', scene_plan::clean_style(['palette' => 'x', 'subject' => 'astrology'])['subject']);
        $this->assertNull(scene_plan::clean_style(['subject' => 'astrology']));
    }

    /**
     * Real Moodle schema, importer and drawing brief preserve the plan and the lesson's scene style.
     */
    public function test_import_and_svg_brief(): void {
        global $DB;
        $this->resetAfterTest();
        require_once(__DIR__ . '/../lib.php');
        $course = $this->getDataGenerator()->create_course();
        $book = $this->getDataGenerator()->create_module('visuallearning', [
            'course' => $course->id, 'name' => 'Flow lesson', 'picturemode' => 'svg',
            'teachingapproach' => 'science', 'language' => 'en-AU',
        ]);
        $context = \context_module::instance($book->cmid);
        $page = ['title' => 'Water moves', 'concept' => 'Water starts in a basin.',
            'action' => 'Water flows through the outlet.', 'outcome' => 'Water enters the pipe.',
            'summary' => 'Water moves through an outlet.', 'question' => null,
            'image_prompt' => 'A basin and a pipe', 'alt_text' => 'Water in a basin',
            'scene' => $this->plan()];
        $still = ['title' => 'What water is', 'concept' => 'Water is a liquid.', 'action' => 'It has no shape.',
            'outcome' => 'It takes the shape of its container.', 'summary' => 'Water is a liquid.', 'question' => null,
            'image_prompt' => 'A glass of water', 'alt_text' => 'A glass of water', 'scene' => null];
        $json = ['scene_style' => ['palette' => 'blue = water', 'recurring' => 'pipes grey', 'subject' => 'earth'],
            'pages' => [$page, $still]];
        [$data, $errors] = importer::parse(json_encode($json), 8);
        $this->assertSame([], $errors);
        importer::import($book, $data, $context);
        $book = $DB->get_record('visuallearning', ['id' => $book->id], '*', MUST_EXIST);
        $pages = array_values($DB->get_records('visuallearning_pages', ['visuallearningid' => $book->id], 'pagenum'));
        $this->assertEquals($this->plan(), scene_plan::clean($pages[0]->sceneplan));
        $this->assertTrue(scene_plan::is_none($pages[1]));
        $this->assertSame('', \mod_visuallearning\local\media::svg_prompt($book, $pages[1]));
        $brief = prompt_builder::svg_prompt($book, $pages[0]);
        $this->assertStringContainsString('SCENE PLAN', $brief);
        $this->assertStringContainsString('STAGE 2: Water in a basin flows down the outlet | LABELS: Water; Outlet', $brief);
        $this->assertStringContainsString('PALETTE (same on every page of the lesson): blue = water', $brief);
        $this->assertStringContainsString('SUBJECT RULES: Cycles close back on themselves', $brief);
        $this->assertLessThan(strpos($brief, 'SCENE PLAN'), strpos($brief, 'CARD 3'));
        // The scene is current for exactly these inputs.
        $key = \mod_visuallearning\local\media::svg_key($book, $pages[0]);
        $pages[0]->outcometext = 'Water leaves through the pipe.';
        $this->assertNotSame($key, \mod_visuallearning\local\media::svg_key($book, $pages[0]));
        $pages[0]->sceneplan = null;
        $legacy = prompt_builder::svg_prompt($book, $pages[0]);
        $this->assertStringNotContainsString('SCENE PLAN', $legacy);
        $this->assertStringContainsString('THE PAGE PICTURE SHOWS', $legacy);
        $page['scene']['stages'] = [];
        [$data, $errors, $warnings] = importer::parse(json_encode(['pages' => [$page]]), 8);
        // A broken plan is only a warning: the page keeps its picture.
        $this->assertSame([], $errors);
        $this->assertNotEmpty($warnings);
    }

    /**
     * Animated lessons replace the picture step of the prompt file with the scene step.
     */
    public function test_prompt_mode_and_exact_default(): void {
        $this->resetAfterTest();
        $book = $this->getDataGenerator()->create_module('visuallearning', [
            'course' => $this->getDataGenerator()->create_course()->id, 'name' => 'Flow',
            'picturemode' => 'svg', 'teachingapproach' => 'science', 'language' => 'en-AU',
            'imagestyle' => '',
        ]);
        $prompt = prompt_builder::writer_prompt($book);
        $this->assertStringContainsString('STEP 5 - WRITE EACH PAGE AS AN ANIMATED SCENE', $prompt);
        $this->assertStringContainsString('"scene": {', $prompt);
        $this->assertStringContainsString('"scene_style": {', $prompt);
        $this->assertStringNotContainsString('STEP 5 - DESIGN THE ILLUSTRATIONS', $prompt);
        $this->assertStringNotContainsString('{$language}', $prompt);
        $this->assertStringNotContainsString('{$subjects}', $prompt);
        $book->picturemode = 'single';
        $prompt = prompt_builder::writer_prompt($book);
        $this->assertStringContainsString('STEP 5 - DESIGN THE ILLUSTRATIONS', $prompt);
        $this->assertStringNotContainsString('"scene": {', $prompt);
        $book->picturemode = 'svg';
        $book->teachingapproach = 'story';
        $prompt = prompt_builder::writer_prompt($book);
        $this->assertStringContainsString('the scene shows the PROCESS the story teaches', $prompt);
    }
}
