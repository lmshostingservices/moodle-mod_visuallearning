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

use mod_visuallearning\local\importer;
use mod_visuallearning\local\prompt_builder;

/**
 * Tests for the ChatGPT prompt file and the paste-back importer.
 *
 * @package    mod_visuallearning
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(importer::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(prompt_builder::class)]
final class importer_test extends \advanced_testcase {
    /**
     * A ChatGPT-style reply with chatter, a code fence, a trailing comma and one page too many.
     *
     * @return string
     */
    private function sample_reply(): string {
        $page = function (int $n): array {
            return [
                'page' => $n,
                'title' => "Concept $n",
                'concept' => "Idea $n.",
                'action' => "Action $n.",
                'outcome' => "Outcome $n.",
                'summary' => "Summary $n.",
                'tf_statement' => "Statement $n.",
                'tf_answer' => $n % 2 === 0,
                'image_prompt' => "Scene $n.",
                'alt_text' => "Alt $n",
            ];
        };
        $json = json_encode(
            [
                'book_title' => 'Teamwork',
                'industry' => 'Hospitality',
                'subindustry' => 'Restaurants',
                'style_guide' => 'Warm gouache.',
                'characters' => [['name' => 'Maya', 'description' => 'supervisor']],
                'cover_image_prompt' => 'The team together.',
                'pages' => [$page(1), $page(2), $page(3), $page(4)],
            ],
            JSON_PRETTY_PRINT,
        );
        $json = preg_replace('/\]\s*\}$/', '],}', $json);
        $fence = str_repeat(chr(96), 3);
        return "Here is your book!\n{$fence}json\n{$json}\n{$fence}\nEnjoy.";
    }

    /**
     * Parsing tolerates chatter, fences and trailing commas, and enforces the page limit.
     */
    public function test_parse_valid_reply(): void {
        [$data, $errors, $warnings] = importer::parse($this->sample_reply(), 3);
        $this->assertEmpty($errors);
        $this->assertNotNull($data);
        $this->assertCount(3, $data['pages']);
        $this->assertCount(1, $warnings);
        $this->assertSame('Action 2.', $data['pages'][1]['action']);
        $this->assertSame('Maya', $data['characters'][0]['name']);
        $this->assertSame('Summary 1.', $data['pages'][0]['summary']);
        $this->assertFalse($data['pages'][0]['tf_answer']);
        $this->assertTrue($data['pages'][1]['tf_answer']);
    }

    /**
     * Missing fields and non-JSON are rejected with errors.
     */
    public function test_parse_invalid_reply(): void {
        [$data, $errors] = importer::parse('{"pages":[{"title":"Only a title"}]}', 20);
        $this->assertNull($data);
        $this->assertCount(4, $errors);

        [$data, $errors] = importer::parse('Sorry, I cannot help with that.', 20);
        $this->assertNull($data);
        $this->assertNotEmpty($errors);

        [$data, $errors] = importer::parse('{"book_title": "x"}', 20);
        $this->assertNull($data);
        $this->assertNotEmpty($errors);
    }

    /**
     * Importing replaces pages, fills blank industry fields and resets media status.
     */
    public function test_import(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $book = $this->getDataGenerator()->create_module('visuallearning', ['course' => $course->id, 'subindustry' => '']);
        $context = \context_module::instance($book->cmid);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_visuallearning');
        $generator->create_page($book->id);

        [$data] = importer::parse($this->sample_reply(), 3);
        importer::import($DB->get_record('visuallearning', ['id' => $book->id]), $data, $context);

        $pages = array_values($DB->get_records('visuallearning_pages', ['visuallearningid' => $book->id], 'pagenum'));
        $this->assertCount(3, $pages);
        $this->assertSame('Concept 1', $pages[0]->title);
        $this->assertEquals(0, $pages[0]->imagestatus);
        $this->assertSame('Statement 2.', $pages[1]->tfstatement);
        $this->assertEquals(1, $pages[1]->tfanswer);
        $this->assertEquals(0, $pages[0]->tfanswer);
        $updated = $DB->get_record('visuallearning', ['id' => $book->id]);
        $this->assertSame('Restaurants', $updated->subindustry);
        $this->assertSame('The team together.', $updated->coverprompt);
    }

    /**
     * The prompt file carries the teacher's inputs and the JSON schema.
     */
    public function test_prompt_file(): void {
        $this->resetAfterTest();
        $book = (object)[
            'name' => 'Teamwork',
            'industry' => '',
            'subindustry' => 'Kitchens',
            'language' => 'th-TH',
            'maxpages' => 12,
            'imagestyle' => 'Watercolour',
            'sourcecontent' => 'Get to know each other.',
        ];
        $prompt = prompt_builder::chatgpt_prompt($book);
        $this->assertStringContainsString('Maximum number of pages: 12', $prompt);
        $this->assertStringContainsString('Thai (th-TH)', $prompt);
        $this->assertStringContainsString('NOT PROVIDED', $prompt);
        $this->assertStringContainsString('Get to know each other.', $prompt);
        $this->assertStringContainsString('"outcome"', $prompt);
        $this->assertStringContainsString('"tf_statement"', $prompt);
        $this->assertStringContainsString('Country / culture of the learners: Thailand', $prompt);

        $book->styleguide = 'Soft light.';
        $book->characters = json_encode([['name' => 'Maya', 'description' => 'supervisor']]);
        $imageprompt = prompt_builder::image_prompt($book, 'Maya at the pub.');
        $this->assertStringContainsString('Watercolour', $imageprompt);
        $this->assertStringContainsString('Maya - supervisor', $imageprompt);
        $this->assertStringContainsString('ILLUSTRATION', $imageprompt);
        $this->assertStringContainsString('takes place in Thailand', $imageprompt);

        $book->imageculture = 'international';
        $this->assertStringContainsString('international setting', prompt_builder::image_prompt($book, 'Scene.'));
        $this->assertStringContainsString('International / neutral', prompt_builder::chatgpt_prompt($book));
    }
}
