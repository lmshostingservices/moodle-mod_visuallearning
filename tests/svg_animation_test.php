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

use mod_visuallearning\local\ai\lmslabs;
use mod_visuallearning\local\ai\requests;
use mod_visuallearning\local\media;
use mod_visuallearning\local\picture_modes;
use mod_visuallearning\local\prompt_builder;
use mod_visuallearning\local\svg_sanitiser;

/**
 * Animated SVG scenes: the safety filter, the picture mode, the brief and the paid request through LMS Labs.
 *
 * @package    mod_visuallearning
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(svg_sanitiser::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(requests::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(media::class)]
final class svg_animation_test extends \advanced_testcase {
    /** @var array POSTs made to the fake LMS Labs: [url, body]. */
    protected $posts = [];

    /** @var array|null The answer the fake LMS Labs gives to /v2/animations. */
    protected $answer = null;

    /** @var bool Whether the fake LMS Labs says it draws animated scenes. */
    protected $drawsanimations = true;

    /** @var string A small valid scene with a stage per card. */
    protected const SCENE = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 560" class="stage-all" '
        . 'aria-labelledby="t"><title id="t">Test</title><style>.a{fill:url(#g)}.stage-1 .b{opacity:0}'
        . '@keyframes spin{to{transform:rotate(1turn)}}.c{animation:spin 2s infinite}</style><defs>'
        . '<linearGradient id="g"><stop offset="0" stop-color="#fff"/></linearGradient><path id="p" d="M0,0 L10,10"/></defs>'
        . '<rect class="a" width="800" height="560"/><circle r="5" class="b"><animateMotion dur="2s" '
        . 'repeatCount="indefinite"><mpath href="#p"/></animateMotion></circle></svg>';

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('lmslabssiteid', 'site-123', 'mod_visuallearning');
        set_config('lmslabsapikey', 'secret-key', 'mod_visuallearning');
        set_config('unlockstate', json_encode(['status' => 'unlocked']), 'mod_visuallearning');
        set_config('aiimages', 1, 'mod_visuallearning');
        set_config('aivoice', 1, 'mod_visuallearning');
        \mod_visuallearning\local\credentials::$central = fn() => null;
        lmslabs::$transport = function (string $url, array $headers): array {
            if (str_ends_with($url, '/v2/capabilities')) {
                return [200, json_encode([
                    'contract' => 'bundle-1',
                    'bundle' => ['slideCredits' => lmslabs::PAGE_CREDITS],
                    'remakes' => ['pictureCredits' => lmslabs::IMAGE_CREDITS,
                        'voice' => ['credits' => lmslabs::VOICE_CREDITS]],
                    'locales' => [['locale' => 'en-AU', 'ttsLanguageCode' => 'en-AU',
                        'voices' => [['name' => 'en-AU-Chirp3-HD-Aoede']]]],
                    'aspectRatios' => ['4:3'],
                    'animations' => $this->drawsanimations
                        ? ['credits' => lmslabs::ANIMATION_CREDITS, 'contracts' => ['svg-1']] : null,
                ])];
            }
            if (str_contains($url, '/api/credits')) {
                return [200, json_encode(['creditsRaw' => 500])];
            }
            return [404, '{}'];
        };
        lmslabs::$posttransport = function (string $url, array $headers, string $body): array {
            $this->posts[] = [$url, $body];
            if (str_ends_with($url, '/v2/animations') && $this->answer) {
                return $this->answer;
            }
            return [0, [], ''];
        };
        \cache::make('mod_visuallearning', 'capabilities')->purge();
    }

    protected function tearDown(): void {
        lmslabs::$transport = null;
        lmslabs::$posttransport = null;
        \mod_visuallearning\local\credentials::$central = null;
        parent::tearDown();
    }

    /**
     * A lesson in a picture mode, with pages.
     *
     * @param string $mode
     * @param array $settings
     * @return array [book, context, page]
     */
    protected function book(string $mode = picture_modes::SVG, array $settings = []): array {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_visuallearning');
        $book = $gen->create_instance(['course' => $course->id, 'picturemode' => $mode] + $settings);
        $gen->create_page($book->id);
        $book = $DB->get_record('visuallearning', ['id' => $book->id]);
        $context = \context_module::instance(get_coursemodule_from_instance('visuallearning', $book->id)->id);
        $pages = visuallearning_get_pages($book->id);
        return [$book, $context, reset($pages)];
    }

    public function test_sanitiser_removes_everything_that_could_run_or_load(): void {
        $evil = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 10 10" '
            . 'onload="alert(1)"><script>alert(2)</script><style>@import url(http://x/a.css);.a{fill:url(http://e/x#a)}'
            . '</style><a href="javascript:alert(3)"><rect width="5" height="5"/></a><foreignObject><div>x</div>'
            . '</foreignObject><image href="http://e/x.png"/><rect id="r" width="1" height="1" onclick="x()"/>'
            . '<use xlink:href="http://evil/#x"/><use href="#r"/><set attributeName="href" to="javascript:alert(4)"/>'
            . '<animate attributeName="onclick" to="x"/><text>ok</text></svg>';
        $out = svg_sanitiser::clean($evil, 'vls1');
        $this->assertNotNull($out);
        foreach (['script', 'onload', 'onclick', 'javascript', 'foreignObject', '<image', '<a ', 'http://x', 'http://e',
                'evil', '@import', '<set', 'attributeName="onclick"'] as $bad) {
            $this->assertStringNotContainsString($bad, $out);
        }
        $this->assertStringContainsString('<use href="#vls1-r"/>', $out);
        $this->assertStringContainsString('<text>ok</text>', $out);
        // Not an SVG, no viewBox, or a document type: refused.
        $this->assertNull(svg_sanitiser::clean('<html><body>hi</body></html>', 'a'));
        $this->assertNull(svg_sanitiser::clean('<svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>', 'a'));
        $this->assertNull(svg_sanitiser::clean('<!DOCTYPE svg [<!ENTITY x "y">]><svg xmlns="http://www.w3.org/2000/svg" '
            . 'viewBox="0 0 1 1"/>', 'a'));
    }

    public function test_sanitiser_keeps_the_animation_and_scopes_it(): void {
        $out = svg_sanitiser::clean("```svg\n" . self::SCENE . "\n```", 'vls7');
        $this->assertNotNull($out);
        $this->assertStringContainsString('id="vls7"', $out);
        $this->assertStringContainsString('#vls7 .a{fill:url(#vls7-g)}', $out);
        $this->assertStringContainsString('#vls7.stage-1 .b{opacity:0}', $out);
        $this->assertStringContainsString('@keyframes vls7-spin', $out);
        $this->assertStringContainsString('animation:vls7-spin 2s infinite', $out);
        $this->assertStringContainsString('<mpath href="#vls7-p"/>', $out);
        $this->assertStringContainsString('aria-labelledby="vls7-t"', $out);
        $this->assertStringContainsString('<animateMotion', $out);
        // The worked example in the system prompt passes as it is.
        $system = prompt_builder::svg_system();
        $example = substr($system, strpos($system, '<svg', strpos($system, 'WORKED EXAMPLE')));
        $this->assertNotNull(svg_sanitiser::clean($example, 'vls8'));
    }

    public function test_picture_lessons_are_never_turned_into_animated_ones(): void {
        $this->assertSame(picture_modes::SINGLE,
            picture_modes::of((object)['picturemode' => 'single', 'animationmode' => 'automatic']));
        $this->assertSame(picture_modes::SEQUENCE,
            picture_modes::of((object)['picturemode' => 'sequence', 'animationmode' => 'animated']));
        $this->assertSame(picture_modes::SVG, picture_modes::of((object)['picturemode' => 'svg', 'animationmode' => 'automatic']));
        $this->assertSame(picture_modes::ANIMATED,
            picture_modes::of((object)['picturemode' => 'animated', 'animationmode' => 'automatic']));
        $this->assertSame(picture_modes::SINGLE, picture_modes::of((object)['picturemode' => 'animated', 'animationmode' => 'static']));
    }

    public function test_page_writing_is_not_blocked_while_the_scene_engine_is_not_live(): void {
        [$book] = $this->book(picture_modes::SINGLE, ['animationmode' => 'automatic']);
        $this->assertSame([], requests::picture_mode_fields($book));
        [$book] = $this->book(picture_modes::ANIMATED, ['animationmode' => 'automatic']);
        // No exception: the classic animated diagrams are asked for instead (when LMS Labs writes them).
        $this->assertIsArray(requests::picture_mode_fields($book));
    }

    public function test_brief_carries_the_cards_language_and_learner_level(): void {
        [$book, , $page] = $this->book(picture_modes::SVG, ['learnerlevel' => 'primary']);
        $brief = prompt_builder::svg_prompt($book, $page);
        $this->assertStringContainsString('CARD 1', $brief);
        $this->assertStringContainsString('CARD 3', $brief);
        $this->assertStringContainsString(trim($page->concepttext), $brief);
        $this->assertStringContainsString(prompt_builder::SVG_LEVELS['primary'], $brief);
        $this->assertStringContainsString('LANGUAGE OF EVERY LABEL', $brief);
        [$book, , $page] = $this->book(picture_modes::SVG, ['learnerlevel' => 'advanced']);
        $this->assertStringContainsString(prompt_builder::SVG_LEVELS['advanced'], prompt_builder::svg_prompt($book, $page));
    }

    public function test_animated_scene_is_quoted_sent_and_saved(): void {
        global $DB;
        [$book, $context, $page] = $this->book();
        $plans = media::plan($book, $context, 'missing', 0, ['animation']);
        $this->assertCount(1, $plans);
        $this->assertSame('animation', $plans[0]['operation']);
        $summary = requests::quote($book, 2, $plans);
        $this->assertNull($summary['hold']);
        $this->assertSame(lmslabs::ANIMATION_CREDITS, $summary['credits']);
        $this->assertSame([], $this->posts, 'A quote sends nothing.');

        $this->answer = [200, ['content-type' => 'image/svg+xml', 'x-credits-charged' => '2'], self::SCENE];
        requests::confirm($book, $summary['batch'], 2, $summary['credits']);
        ob_start();
        $this->runAdhocTasks('\mod_visuallearning\task\generate_media');
        ob_end_clean();
        $this->assertCount(1, $this->posts);
        $sent = json_decode($this->posts[0][1], true);
        $this->assertStringEndsWith('/v2/animations', $this->posts[0][0]);
        $this->assertSame('svg-1', $sent['contract']);
        $this->assertSame(lmslabs::ANIMATION_CREDITS, $sent['maxCredits']);
        $this->assertSame(media::svg_prompt($book, $page), $sent['prompt']);
        $row = $DB->get_record('visuallearning_aireq', ['operation' => 'animation'], '*', MUST_EXIST);
        $this->assertSame('completed', $row->status);
        $this->assertTrue(media::svg_current($context, (int)$page->id, media::svg_key($book, $page)));
        // Nothing more to make, and the player gets the scene inline.
        $this->assertSame([], media::plan($book, $context, 'missing', 0, ['animation']));
        $cm = get_coursemodule_from_instance('visuallearning', $book->id);
        $data = \mod_visuallearning\local\book_data::player($book, $cm, $context, 2);
        $this->assertSame('svg_scene', $data['pages'][0]['diagram']['type']);
        $this->assertStringStartsWith('<svg', $data['pages'][0]['diagram']['svg']);
    }

    public function test_unusable_answer_is_lost_and_the_picture_stays(): void {
        global $DB;
        [$book, $context, $page] = $this->book();
        $summary = requests::quote($book, 2, media::plan($book, $context, 'missing', 0, ['animation']));
        $this->answer = [200, ['content-type' => 'image/svg+xml'], '<html>not an svg</html>'];
        requests::confirm($book, $summary['batch'], 2, $summary['credits']);
        ob_start();
        $this->runAdhocTasks('\mod_visuallearning\task\generate_media');
        ob_end_clean();
        $row = $DB->get_record('visuallearning_aireq', ['operation' => 'animation'], '*', MUST_EXIST);
        $this->assertSame('lost', $row->status);
        $this->assertSame('unusable_animation', $row->errorcode);
        $this->assertNull(media::svg_file($context, (int)$page->id));
    }

    public function test_scenes_wait_for_lms_labs_and_never_block_the_rest(): void {
        $this->drawsanimations = false;
        [$book, $context] = $this->book();
        $this->assertSame('noanimation', lmslabs::hold('animation'));
        $plans = media::plan($book, $context, 'all', 0, ['image', 'animation']);
        $summary = requests::quote($book, 2, $plans);
        $this->assertSame(1, $summary['droppedanimations']);
        $this->assertSame(['image' => 1], $summary['items']);
    }

    public function test_only_scene_lessons_ask_for_scenes(): void {
        [$book, $context] = $this->book(picture_modes::SINGLE);
        $this->assertSame([], media::plan($book, $context, 'all', 0, ['animation']));
    }

    public function test_teacher_can_paste_a_scene_from_their_own_ai(): void {
        [$book, $context, $page] = $this->book();
        $this->assertFalse(media::save_svg($context, (int)$page->id, 'x', 'not svg'));
        $key = media::svg_key($book, $page);
        $this->assertTrue(media::save_svg($context, (int)$page->id, $key, self::SCENE));
        $this->assertTrue(media::svg_current($context, (int)$page->id, $key));
        $this->assertSame('vls' . $page->id, (new \SimpleXMLElement(media::svg_file($context, (int)$page->id)->get_content()))['id']
            ->__toString());
    }

    public function test_scene_of_a_purchased_slide_is_bound_to_it(): void {
        global $DB;
        [$book, $context, $page] = $this->book();
        $page->bundleid = \core\uuid::generate();
        $page->slideid = \core\uuid::generate();
        $page->bundlesite = requests::sitehash();
        $DB->update_record('visuallearning_pages', $page);
        $summary = requests::quote($book, 2, media::plan($book, $context, 'missing', 0, ['animation']));
        $row = $DB->get_record('visuallearning_aireq', ['batch' => $summary['batch']], '*', MUST_EXIST);
        $body = json_decode($row->body, true);
        $this->assertSame($page->bundleid, $body['bundleId']);
        $this->assertSame($page->slideid, $body['slideId']);
        $this->assertSame('animation', $body['slot']);
        $this->assertFalse($body['remake']);
        $this->assertSame(lmslabs::ANIMATION_CREDITS, $body['maxCredits']);
        // Delivered with its receipt: saved, and the receipt kept against the slot.
        $this->answer = [200, ['content-type' => 'image/svg+xml', 'x-credits-charged' => '2', 'x-delivery-id' => 'd-1'],
            self::SCENE];
        requests::confirm($book, $summary['batch'], 2, $summary['credits']);
        ob_start();
        $this->runAdhocTasks('\mod_visuallearning\task\generate_media');
        ob_end_clean();
        $this->assertSame('completed', $DB->get_field('visuallearning_aireq', 'status', ['id' => $row->id]));
        $receipts = json_decode($DB->get_field('visuallearning_pages', 'bundledeliveries', ['id' => $page->id]), true);
        $this->assertSame('d-1', $receipts['animation']);
    }
}
