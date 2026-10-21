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
     * A harmless topic-independent plan.
     *
     * @return array
     */
    private function plan(): array {
        $stage = ['shows' => 'Water in a basin', 'moves' => 'Water flows down the outlet',
            'labels' => ['Water', 'Outlet']];
        return ['layout' => 'A basin above a pipe', 'stages' => [$stage, $stage, $stage],
            'all' => 'Water moves through the basin and outlet', 'colours' => 'blue = water',
            'realtime' => false, 'accuracy' => 'Flow follows the pipe', 'exclude' => 'Invented numbers'];
    }

    /**
     * Validate structure, language lengths, safe plain text and no silent truncation.
     */
    public function test_plan_validation_and_plain_text(): void {
        $plan = $this->plan();
        $this->assertEquals($plan, scene_plan::clean(json_encode($plan)));
        $this->assertNull(scene_plan::clean(null));
        $this->assertNull(scene_plan::clean('{broken'));
        $bad = $plan;
        $bad['stages'][] = $bad['stages'][0];
        $this->assertNull(scene_plan::clean($bad));
        $bad = $plan;
        $bad['stages'][0]['labels'] = ['one two three four five'];
        $this->assertNull(scene_plan::clean($bad));
        $bad['stages'][0]['labels'] = [str_repeat('é', 33)];
        $this->assertNull(scene_plan::clean($bad));
        $bad['stages'][0]['labels'] = array_fill(0, 5, 'Water');
        $this->assertNull(scene_plan::clean($bad));
        $bad = $plan;
        $bad['realtime'] = 'false';
        $this->assertNull(scene_plan::clean($bad));
        $bad = $plan;
        $bad['layout'] = str_repeat('x', 601);
        $this->assertNull(scene_plan::clean($bad));
        $plan['layout'] = '<b>A basin</b>';
        $plan['unknown'] = '<script>ignored</script>';
        $clean = scene_plan::clean($plan);
        $this->assertSame('A basin', $clean['layout']);
        $this->assertArrayNotHasKey('unknown', $clean);
    }

    /**
     * Real Moodle schema, importer and drawing brief preserve the plan.
     */
    public function test_import_and_svg_brief(): void {
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
            'scene_plan' => $this->plan()];
        [$data, $errors] = importer::parse(json_encode(['pages' => [$page]]), 8);
        $this->assertSame([], $errors);
        importer::import($book, $data, $context);
        global $DB;
        $saved = $DB->get_record('visuallearning_pages', ['visuallearningid' => $book->id], '*', MUST_EXIST);
        $this->assertEquals($this->plan(), scene_plan::clean($saved->sceneplan));
        $brief = prompt_builder::svg_prompt($book, $saved);
        $this->assertStringContainsString('SCENE PLAN', $brief);
        $this->assertStringContainsString('STAGE 2 MOVES: Water flows down the outlet', $brief);
        $this->assertLessThan(strpos($brief, 'SCENE PLAN'), strpos($brief, 'CARD 3'));
        $this->assertStringContainsString('REALTIME: false', $brief);
        $saved->sceneplan = null;
        $legacy = prompt_builder::svg_prompt($book, $saved);
        $this->assertStringNotContainsString('SCENE PLAN', $legacy);
        $this->assertStringContainsString('CARD 3', $legacy);
        $page['scene_plan']['stages'] = [];
        [$data, $errors] = importer::parse(json_encode(['pages' => [$page]]), 8);
        $this->assertNull($data);
        $this->assertNotEmpty($errors);
    }

    /**
     * Native SVG prompts replace only the relevant mode's picture brief.
     */
    public function test_prompt_mode_and_exact_default(): void {
        $this->resetAfterTest();
        $book = $this->getDataGenerator()->create_module('visuallearning', [
            'course' => $this->getDataGenerator()->create_course()->id, 'name' => 'Flow',
            'picturemode' => 'svg', 'teachingapproach' => 'science', 'language' => 'en-AU',
            'imagestyle' => '',
        ]);
        $prompt = prompt_builder::chatgpt_prompt($book);
        $this->assertStringContainsString('STEP 5 - WRITE EVERY PAGE AS AN ANIMATED SCENE', $prompt);
        $this->assertStringContainsString('"scene_plan": {', $prompt);
        $this->assertStringNotContainsString('STEP 5 - DESIGN THE ILLUSTRATIONS', $prompt);
        $this->assertStringNotContainsString('{$language}', $prompt);
        $this->assertStringContainsString('Clean, modern animated textbook diagram', $prompt);
        $book->picturemode = 'single';
        $prompt = prompt_builder::chatgpt_prompt($book);
        $this->assertStringContainsString('STEP 5 - DESIGN THE ILLUSTRATIONS', $prompt);
        $this->assertStringNotContainsString('"scene_plan": {', $prompt);
        $book->picturemode = 'svg';
        $book->teachingapproach = 'story';
        $prompt = prompt_builder::chatgpt_prompt($book);
        $this->assertStringContainsString('the animation shows the SKILL or PROCESS', $prompt);
    }
}
