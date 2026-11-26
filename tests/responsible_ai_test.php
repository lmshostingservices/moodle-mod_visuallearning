<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace mod_visuallearning;

use mod_visuallearning\local\ai_marking;
use mod_visuallearning\local\ai\lmslabs;
use mod_visuallearning\local\responsible_ai;

#[\PHPUnit\Framework\Attributes\CoversClass(\mod_visuallearning\local\ai_marking::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_visuallearning\local\responsible_ai::class)]
/**
 * Responsible AI (EU AI Act): AI media marking, teacher check, learner notice, pause and provenance.
 *
 * @package    mod_visuallearning
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_visuallearning\local\ai_marking
 * @covers     \mod_visuallearning\local\responsible_ai
 */
final class responsible_ai_test extends \advanced_testcase {
    /**
     * A small real image of each format, made with GD.
     *
     * @param string $format png, jpeg or webp
     * @return string
     */
    protected function image(string $format): string {
        $im = imagecreatetruecolor(4, 3);
        imagefill($im, 0, 0, imagecolorallocate($im, 30, 120, 200));
        ob_start();
        $format === 'png' ? imagepng($im) : ($format === 'jpeg' ? imagejpeg($im) : imagewebp($im));
        return ob_get_clean();
    }

    /**
     * PNG, JPEG and WebP get IPTC trainedAlgorithmicMedia XMP and stay valid images; a second pass changes nothing.
     */
    public function test_mark_images(): void {
        $formats = ['png' => 'image/png', 'jpeg' => 'image/jpeg'];
        if (function_exists('imagewebp')) {
            $formats['webp'] = 'image/webp';
        }
        foreach ($formats as $format => $mime) {
            $bytes = $this->image($format);
            $this->assertFalse(ai_marking::is_marked($bytes));
            $marked = ai_marking::mark($bytes, $mime, ai_marking::provenance('picture'));
            $this->assertTrue(ai_marking::is_marked($marked), $format);
            $this->assertStringContainsString(ai_marking::SOURCE_TYPE, $marked);
            $size = getimagesizefromstring($marked);
            $this->assertSame([4, 3, $mime], [$size[0], $size[1], $size['mime']], $format . ' is still a valid image');
            $this->assertNotFalse(imagecreatefromstring($marked), $format . ' still decodes');
            $this->assertSame($marked, ai_marking::mark($marked, $mime, ai_marking::provenance('picture')), 'Marked once.');
        }
    }

    /**
     * A PNG with valid chunk CRCs after marking.
     */
    public function test_png_chunks_valid(): void {
        $marked = ai_marking::mark($this->image('png'), 'image/png', ai_marking::provenance('picture'));
        $at = 8;
        $types = [];
        while ($at < strlen($marked)) {
            $len = unpack('N', substr($marked, $at, 4))[1];
            $type = substr($marked, $at + 4, 4);
            $crc = unpack('N', substr($marked, $at + 8 + $len, 4))[1];
            $this->assertSame(crc32($type . substr($marked, $at + 8, $len)), $crc, $type);
            $types[] = $type;
            $at += 12 + $len;
        }
        $this->assertSame(['IHDR', 'iTXt'], array_slice($types, 0, 2));
        $this->assertSame('IEND', end($types));
    }

    /**
     * Markings already in a file are kept: a file with XMP (for example from LMS Labs or a C2PA tool) is not changed.
     */
    public function test_existing_marking_kept(): void {
        $png = ai_marking::mark($this->image('png'), 'image/png', ['kind' => 'picture', 'system' => 'Other tool',
            'provider' => 'Other', 'created' => '2026-01-01T00:00:00Z', 'version' => '']);
        $this->assertSame($png, ai_marking::mark($png, 'image/png', ai_marking::provenance('picture')));
        $this->assertStringContainsString('Other tool', $png);
        // A JPEG signed with C2PA (APP11 JUMBF) and no XMP is left byte-for-byte as it is.
        $jpeg = $this->image('jpeg');
        $box = 'JP' . "\0\1" . 'jumb' . 'jumdc2pa';
        $signed = substr($jpeg, 0, 2) . "\xFF\xEB" . pack('n', strlen($box) + 2) . $box . substr($jpeg, 2);
        $this->assertTrue(ai_marking::has_c2pa($signed));
        $this->assertSame($signed, ai_marking::mark($signed, 'image/jpeg', ai_marking::provenance('picture')));
        $mp3 = 'ID3' . "\3\0\0\0\0\0\0" . str_repeat("\xFF\xFB\x90\x00", 8);
        $this->assertSame($mp3, ai_marking::mark($mp3, 'audio/mpeg', ai_marking::provenance('voice')));
    }

    /**
     * MP3 gets an ID3v2.3 tag with a valid syncsafe size; SVG gets RDF metadata and stays well-formed XML.
     */
    public function test_mark_audio_and_svg(): void {
        $mp3 = str_repeat("\xFF\xFB\x90\x00", 8);
        $marked = ai_marking::mark($mp3, 'audio/mpeg', ai_marking::provenance('voice'));
        $this->assertSame('ID3', substr($marked, 0, 3));
        $b = array_map('ord', str_split(substr($marked, 6, 4)));
        $size = ($b[0] << 21) | ($b[1] << 14) | ($b[2] << 7) | $b[3];
        $this->assertSame($mp3, substr($marked, 10 + $size), 'Audio frames follow the tag unchanged.');
        $this->assertTrue(ai_marking::is_marked($marked));

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10"/></svg>';
        $marked = ai_marking::mark($svg, 'image/svg+xml', ai_marking::provenance('animation'));
        $this->assertTrue(ai_marking::is_marked($marked));
        $doc = new \DOMDocument();
        $this->assertTrue($doc->loadXML($marked));
        $this->assertSame('metadata', $doc->documentElement->firstChild->localName);
        $this->assertSame('hello', ai_marking::mark('hello', 'text/plain', ai_marking::provenance('picture')));
    }

    /**
     * Saving AI media marks it; the teacher's own HTML is never marked; the site can switch marking off.
     */
    public function test_media_save_marks(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $book = $this->getDataGenerator()->create_module('visuallearning', ['course' => $course->id]);
        $context = \context_module::instance($book->cmid);
        $page = $this->getDataGenerator()->get_plugin_generator('mod_visuallearning')->create_page($book->id);
        $save = new \ReflectionMethod(local\media::class, 'save');
        $fs = get_file_storage();

        $save->invoke(null, $context, local\media::AREA_IMAGE, $page->id, 'p.png', $this->image('png'), 'image/png');
        $file = $fs->get_file($context->id, 'mod_visuallearning', local\media::AREA_IMAGE, $page->id, '/', 'p.png');
        $this->assertTrue(ai_marking::is_marked($file->get_content()));

        $save->invoke(null, $context, local\media::AREA_HTML, $page->id, 'p.html', '<p>Mine</p>', 'text/html');
        $file = $fs->get_file($context->id, 'mod_visuallearning', local\media::AREA_HTML, $page->id, '/', 'p.html');
        $this->assertSame('<p>Mine</p>', $file->get_content());

        set_config('aimarking', 0, 'mod_visuallearning');
        $plain = $this->image('png');
        $save->invoke(null, $context, local\media::AREA_IMAGE, $page->id, 'q.png', $plain, 'image/png');
        $file = $fs->get_file($context->id, 'mod_visuallearning', local\media::AREA_IMAGE, $page->id, '/', 'q.png');
        $this->assertSame($plain, $file->get_content());
    }

    /**
     * Teacher check: recorded, shown to learners, cleared, required before learners when the site asks.
     */
    public function test_review_and_notice(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher', ['firstname' => 'Priya',
            'lastname' => 'Shah']);
        $record = $this->getDataGenerator()->create_module('visuallearning', ['course' => $course->id]);
        $book = $DB->get_record('visuallearning', ['id' => $record->id]);
        $pages = [['image' => 'x.png', 'cards' => [['audio' => 'a.mp3', 'image' => '']], 'diagram' => null]];

        $this->assertFalse(responsible_ai::is_reviewed($book));
        $this->assertFalse(responsible_ai::learners_wait($book), 'No check needed unless the site asks.');
        set_config('requirereview', 1, 'mod_visuallearning');
        $this->assertTrue(responsible_ai::learners_wait($book));

        $notice = responsible_ai::player_data($book, $pages);
        $this->assertSame('Made with AI', $notice['heading']);
        $this->assertStringContainsString('text, pictures and narration', $notice['text']);
        $this->assertSame('', $notice['checked']);

        responsible_ai::mark_reviewed($book, (int)$teacher->id);
        $book = $DB->get_record('visuallearning', ['id' => $record->id]);
        $this->assertTrue(responsible_ai::is_reviewed($book));
        $this->assertFalse(responsible_ai::learners_wait($book));
        $this->assertStringContainsString('Checked by Priya Shah', responsible_ai::player_data($book, $pages)['checked']);

        $manifest = responsible_ai::provenance_manifest($book, ['pictures/cover.png']);
        $this->assertSame(ai_marking::SOURCE_TYPE, $manifest['digitalSourceType']);
        $this->assertSame('Priya Shah', $manifest['humanReview']['checkedBy']);
        $this->assertSame(['pictures/cover.png'], $manifest['aiGeneratedFiles']);

        responsible_ai::reset_review((int)$book->id);
        $book = $DB->get_record('visuallearning', ['id' => $record->id]);
        $this->assertFalse(responsible_ai::is_reviewed($book));
        $this->assertNull(responsible_ai::provenance_manifest($book, [])['humanReview']);

        set_config('aidisclosure', 0, 'mod_visuallearning');
        $this->assertNull(responsible_ai::player_data($book, $pages));
    }

    /**
     * A teacher's own HTML page is not listed as AI-made; animated diagrams are.
     */
    public function test_parts(): void {
        $this->assertSame(['text'], responsible_ai::parts([['diagram' => ['type' => 'html_embed']]]));
        $this->assertSame(['text', 'animations'], responsible_ai::parts([['diagram' => ['type' => 'svg_scene']]]));
    }

    /**
     * An administrator's pause stops every paid AI operation before anything else is checked.
     */
    public function test_pause(): void {
        $this->resetAfterTest();
        $this->assertNotSame('paused', lmslabs::hold('write'));
        set_config('aipaused', 1, 'mod_visuallearning');
        $this->assertTrue(responsible_ai::paused());
        foreach (['write', 'image', 'voice'] as $operation) {
            $this->assertSame('paused', lmslabs::hold($operation));
        }
        $this->assertNotEmpty(get_string('hold_paused', 'mod_visuallearning'));
    }

    /**
     * The privacy API reports and removes the teacher who checked a lesson, and the lesson stays checked.
     */
    public function test_privacy_reviewer(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $record = $this->getDataGenerator()->create_module('visuallearning', ['course' => $course->id]);
        $book = $DB->get_record('visuallearning', ['id' => $record->id]);
        responsible_ai::mark_reviewed($book, (int)$teacher->id);
        $context = \context_module::instance($record->cmid);

        $contexts = privacy\provider::get_contexts_for_userid((int)$teacher->id)->get_contextids();
        $this->assertContains((string)$context->id, array_map('strval', $contexts));
        $userlist = new \core_privacy\local\request\userlist($context, 'mod_visuallearning');
        privacy\provider::get_users_in_context($userlist);
        $this->assertContains((int)$teacher->id, array_map('intval', $userlist->get_userids()));

        privacy\provider::delete_data_for_all_users_in_context($context);
        $book = $DB->get_record('visuallearning', ['id' => $record->id]);
        $this->assertSame(0, (int)$book->reviewedby);
        $this->assertTrue(responsible_ai::is_reviewed($book));
        $this->assertStringContainsString('Checked by a teacher', responsible_ai::player_data($book, [])['checked']);
    }
}
