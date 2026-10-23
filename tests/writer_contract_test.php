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

use mod_visuallearning\local\prompt_builder;

/**
 * The writer's brief and the draft request match what LMS Labs' delivery check accepts.
 *
 * @package    mod_visuallearning
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(prompt_builder::class)]
final class writer_contract_test extends \advanced_testcase {
    public function test_pasted_breaks_become_line_breaks(): void {
        $this->assertSame("Slide 1\nline two\npage\n\nx", prompt_builder::plain_text("Slide 1\vline two\fpage\r\n\r\n\x01x"));
        $this->assertSame("Tabs\tstay", prompt_builder::plain_text("Tabs\tstay"));
    }

    public function test_text_card_options_still_get_a_picture_prompt(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $book = $this->getDataGenerator()->get_plugin_generator('mod_visuallearning')->create_instance([
            'course' => $course->id, 'questiontypes' => 'pick', 'pickimages' => 0,
            'sourcecontent' => "Hand hygiene\vfrom a slide", 'aiinstructions' => "Notes\fpage two",
        ]);
        global $DB;
        $book = $DB->get_record('visuallearning', ['id' => $book->id]);
        $brief = prompt_builder::writer_prompt($book);
        $this->assertStringNotContainsString('leave "image_prompt" empty', $brief);
        $this->assertStringContainsString('still write a short "image_prompt"', $brief);
        $this->assertStringContainsString('never more than 250', $brief);
        $this->assertStringContainsString('the characters < or >', $brief);
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $brief);
        $body = \mod_visuallearning\local\ai\requests::write_body($book);
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $body['content']);
    }
}
