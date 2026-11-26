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
use mod_visuallearning\local\animation_settings;
use mod_visuallearning\local\diagram;
use mod_visuallearning\local\picture_modes;
use mod_visuallearning\local\prompt_builder;

#[\PHPUnit\Framework\Attributes\CoversClass(\mod_visuallearning\local\scene_validator::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_visuallearning\local\animation_settings::class)]
/**
 * Scene persistence, settings and fallback contracts.
 *
 * @package mod_visuallearning
 * @copyright 2026 LMS Hosting Services
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_visuallearning\local\scene_validator
 * @covers \mod_visuallearning\local\animation_settings
 */
final class animated_scene_test extends \advanced_testcase {
    public function test_all_subjects_survive_storage_and_browser_json(): void {
        foreach (glob(__DIR__ . '/fixtures/scene-*.json') as $file) {
            $original = json_decode(file_get_contents($file));
            $stored = diagram::json(json_decode(file_get_contents($file), true));
            $this->assertNotNull($stored, basename($file));
            $this->assertEquals($original, json_decode($stored));
            $this->assertSame($stored, diagram::json($stored));
        }
    }

    public function test_invalid_scene_falls_back_without_executable_data(): void {
        $scene = json_decode(file_get_contents(__DIR__ . '/fixtures/scene-gears.json'), true);
        $scene['onload'] = 'alert(1)';
        $this->assertNull(diagram::clean($scene));
        unset($scene['onload']);
        $scene['objects'][0]['parent'] = $scene['objects'][0]['id'];
        $this->assertNull(diagram::clean($scene));
        $this->assertNull(diagram::clean('{"type":"animated_scene","version":"999"}'));
    }

    public function test_old_lessons_keep_their_mode_and_new_choices_are_explicit(): void {
        foreach (picture_modes::MODES as $mode) {
            $book = (object)['picturemode' => $mode];
            $this->assertSame($mode, picture_modes::of($book));
            $this->assertSame('legacy', animation_settings::values($book)['animationmode']);
        }
        // The engine setting refines animated-diagram lessons only; it never turns a picture lesson into an animated one.
        $this->assertSame('animated', picture_modes::of((object)['animationmode' => 'automatic', 'picturemode' => 'animated']));
        $this->assertSame('single', picture_modes::of((object)['animationmode' => 'automatic']));
        $this->assertSame('single', picture_modes::of((object)['animationmode' => 'static', 'picturemode' => 'animated']));
    }

    public function test_scene_brief_is_shared_and_never_requires_a_particular_topic(): void {
        $book = (object)['animationmode' => 'automatic', 'picturemode' => 'animated'];
        [$brief, $field] = prompt_builder::picture_mode_brief($book, 'English');
        $this->assertStringContainsString('"animated_scene"', $brief);
        $this->assertStringContainsString('Teaching content determines the animation', $brief);
        $this->assertStringContainsString('"diagram": null', $field);
        $this->assertStringNotContainsString('Create a premium educational SVG animation explaining how the human heart', $brief);
    }
}
