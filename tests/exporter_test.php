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

use mod_visuallearning\local\exporter;

/**
 * Tests for downloading all content and adding the questions to the question bank.
 *
 * @package    mod_visuallearning
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(exporter::class)]
final class exporter_test extends \advanced_testcase {
    /**
     * A lesson with one question of each type.
     *
     * @return array [course, lesson, context]
     */
    private function book(): array {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $book = $this->getDataGenerator()->create_module('visuallearning', ['course' => $course->id, 'name' => 'Kitchen teamwork']);
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_visuallearning');
        $gen->create_page($book->id, ['feedback' => 'True, because teams share one goal.']);
        $labels = ['Wait', 'Call out', 'Push', 'Run'];
        $options = array_map(fn($l) => ['label' => $l, 'image_prompt' => '', 'alt_text' => $l], $labels);
        $gen->create_page($book->id, ['qtype' => 'pick', 'tfstatement' => 'What do you do behind a chef?',
            'qdata' => json_encode(['options' => $options, 'correct' => 1])]);
        $gen->create_page($book->id, ['qtype' => 'match', 'tfstatement' => 'Match the roles.', 'qdata' => json_encode(['pairs' => [
            ['left' => 'Head chef', 'right' => 'Calls orders'], ['left' => 'Sous chef', 'right' => 'Runs the pass'],
            ['left' => 'Kitchen hand', 'right' => 'Washes pans']]])]);
        $gen->create_page($book->id, ['qtype' => 'sort', 'tfstatement' => 'Before or during?', 'qdata' => json_encode([
            'groups' => ['Before', 'During'], 'items' => [['text' => 'Prep', 'group' => 0], ['text' => 'Plate', 'group' => 1],
            ['text' => 'Label', 'group' => 0], ['text' => 'Taste', 'group' => 1]]])]);
        $book = $DB->get_record('visuallearning', ['id' => $book->id]);
        return [$course, $book, \context_module::instance(get_coursemodule_from_instance('visuallearning', $book->id)->id)];
    }

    /**
     * The ZIP holds every kind of content, and the question files are well formed.
     */
    public function test_zip_contents(): void {
        $this->resetAfterTest();
        [, $book, $context] = $this->book();
        $exporter = new exporter($book, $context);
        $this->assertCount(4, $exporter->questions());
        $xml = simplexml_load_string($exporter->moodle_xml());
        $types = array_map('strval', $xml->xpath('//question/@type'));
        $this->assertSame(['category', 'truefalse', 'multichoice', 'match', 'match'], $types);
        $this->assertStringContainsString('=Call out', $exporter->gift());
        $this->assertStringContainsString('=Prep -> Before', $exporter->gift());

        $zip = $exporter->zip();
        $files = get_file_packer('application/zip')->list_files($zip);
        $names = array_map(fn($f) => $f->pathname, $files);
        foreach (
            ['README.txt', 'questions/moodle-question-bank.xml', 'questions/questions.gift.txt',
                'questions/questions.csv', 'lesson/kitchen-teamwork.docx', 'lesson/kitchen-teamwork.md', 'lesson/pages.csv',
                'voiceover/transcript.txt', 'prompts/make-more-quizzes.md'] as $name
        ) {
            $this->assertContains($name, $names);
        }
        // The Word document is a valid package.
        $docx = make_request_directory() . '/b.docx';
        file_put_contents($docx, $exporter->docx());
        $parts = array_map(fn($f) => $f->pathname, get_file_packer('application/zip')->list_files($docx));
        $this->assertContains('word/document.xml', $parts);
    }

}
