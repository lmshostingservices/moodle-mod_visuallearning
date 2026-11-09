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
use mod_visuallearning\local\exporter;
use mod_visuallearning\local\media;

/**
 * A teacher's own HTML in place of a page picture (1.9.0).
 *
 * @package    mod_visuallearning
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(media::class)]
final class customhtml_test extends \advanced_testcase {
    /**
     * A lesson with one page.
     *
     * @return array [lesson, page, context, cm]
     */
    private function lesson(): array {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $book = $this->getDataGenerator()->create_module('visuallearning', ['course' => $course->id, 'name' => 'Danger zone']);
        $page = $this->getDataGenerator()->get_plugin_generator('mod_visuallearning')->create_page($book->id, []);
        $cm = get_coursemodule_from_instance('visuallearning', $book->id);
        return [$DB->get_record('visuallearning', ['id' => $book->id]), $page, \context_module::instance($cm->id), $cm];
    }

    /**
     * Only a usable, reasonably sized HTML document is accepted.
     */
    public function test_html_problem(): void {
        $this->assertSame('customhtml_err_empty', media::html_problem("  \n"));
        $this->assertSame('customhtml_err_nothtml', media::html_problem('just some words'));
        $this->assertSame('customhtml_err_size', media::html_problem('<div>' . str_repeat('x', media::HTML_MAX_BYTES) . '</div>'));
        $this->assertNull(media::html_problem('<!doctype html><html><body><canvas></canvas></body></html>'));
        $this->assertNull(media::html_problem('<div class="kpi">568</div>'));
    }

    /**
     * Saving replaces the earlier HTML, wraps a fragment in a UTF-8 document, and removing it brings the picture back.
     */
    public function test_save_replace_remove(): void {
        $this->resetAfterTest();
        [$book, $page, $context, $cm] = $this->lesson();
        media::save_html($context, (int)$page->id, '<div>First</div>');
        $file = media::save_html($context, (int)$page->id, '<div>Second</div>');
        $files = get_file_storage()->get_area_files($context->id, 'mod_visuallearning', media::AREA_HTML, $page->id, 'id', false);
        $this->assertCount(1, $files);
        $this->assertStringStartsWith('<!doctype html>', $file->get_content());
        $this->assertStringContainsString('charset="utf-8"', $file->get_content());
        $this->assertStringContainsString('<div>Second</div>', $file->get_content());
        $this->assertMatchesRegularExpression('/^page-[0-9a-f]{16}\.html$/', $file->get_filename());

        $data = book_data::player($book, $cm, $context, 0);
        $this->assertSame('html_embed', $data['pages'][0]['diagram']['type']);
        $this->assertStringContainsString('/mod_visuallearning/html/' . $page->id . '/', $data['pages'][0]['diagram']['url']);

        media::delete_html($context, (int)$page->id);
        $this->assertNull(media::html_file($context, (int)$page->id));
        $data = book_data::player($book, $cm, $context, 0);
        $this->assertNotSame('html_embed', $data['pages'][0]['diagram']['type'] ?? null);
    }

    /**
     * The site switch hides the HTML from learners but keeps it; the downloads include it.
     */
    public function test_site_switch_and_export(): void {
        $this->resetAfterTest();
        [$book, $page, $context, $cm] = $this->lesson();
        media::save_html($context, (int)$page->id, '<!doctype html><html><body><p>Count up</p></body></html>');

        set_config('allowcustomhtml', 0, 'mod_visuallearning');
        $this->assertFalse(media::html_enabled());
        $this->assertNull(media::html_file($context, (int)$page->id));
        $this->assertNotNull(media::html_file($context, (int)$page->id, true));
        $data = book_data::player($book, $cm, $context, 0);
        $this->assertNotSame('html_embed', $data['pages'][0]['diagram']['type'] ?? null);

        set_config('allowcustomhtml', 1, 'mod_visuallearning');
        $files = (new exporter($book, $context))->files();
        $this->assertNotEmpty(array_filter(array_keys($files), fn($name) => str_starts_with($name, 'custom-html/')));
    }
}
