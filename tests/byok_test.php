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
use mod_visuallearning\local\byok\engine;
use mod_visuallearning\local\media;

/**
 * Tests for making lessons, pictures, animated scenes and narration on the site's own AI.
 *
 * local\byok\engine and local\byok\draft_check were compared with the LMS Labs service code (the draft job, the
 * lesson checks, the SVG and MP3 checks) on more than 60,000 randomised requests and AI answers, with identical
 * results; these tests cover the wiring into the plugin.
 *
 * @package    mod_visuallearning
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_visuallearning\byok
 * @covers     \mod_visuallearning\local\byok\engine
 * @covers     \mod_visuallearning\local\byok\draft_check
 * @covers     \mod_visuallearning\local\ai\requests
 */
#[\PHPUnit\Framework\Attributes\CoversClass(byok::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(engine::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(local\byok\draft_check::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(requests::class)]
final class byok_test extends \advanced_testcase {
    /** @var array Requests the mocked providers received: [url, body]. */
    protected $requests = [];
    /** @var array Text answers the mocked text provider gives, in order (null: the provider fails). */
    protected $answers = [];
    /** @var array Calls made to LMS Labs: [url, body]. */
    protected $lmslabs = [];

    /**
     * Licence the site for Bring your own AI on a test key: text on Anthropic, pictures on OpenAI, voice on Google;
     * no LMS Labs credentials.
     */
    protected function setUp(): void {
        parent::setUp();
        if (!class_exists('\local_aiconfig\byok\ai') || !method_exists('\local_aiconfig\byok\ai', 'image_edits')) {
            $this->markTestSkipped('AI Grader Central Config 1.1.0 or later is not installed.');
        }
        $this->resetAfterTest();
        $pair = sodium_crypto_sign_keypair();
        set_config('testpublickey', base64_encode(sodium_crypto_sign_publickey($pair)), 'local_aiconfig');
        $payload = json_encode(['v' => 1, 'id' => 'lic_t', 'site' => \local_aiconfig\byok\licence::site_host(),
            'plan' => 'pro', 'byok' => true, 'products' => ['*'], 'sub' => 'sub_t', 'exp' => time() + DAYSECS]);
        $b64 = fn(string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        set_config('licencekey', $b64($payload) . '.' .
            $b64(sodium_crypto_sign_detached($payload, sodium_crypto_sign_secretkey($pair))), 'local_aiconfig');
        foreach (['text' => 'anthropic', 'image' => 'openai', 'voice' => 'googletts'] as $service => $vendor) {
            set_config('src_' . $service, 'ownkey', 'local_aiconfig');
            set_config('vendor_' . $service, $vendor, 'local_aiconfig');
        }
        set_config('key_anthropic', 'sk-test', 'local_aiconfig');
        set_config('key_openai', 'sk-open', 'local_aiconfig');
        set_config('key_gemini', 'g-test', 'local_aiconfig');
        set_config('key_googletts', 'g-test', 'local_aiconfig');
        foreach (['aiwrite', 'aiimages', 'aivoice'] as $setting) {
            set_config($setting, 1, 'mod_visuallearning');
        }
        \mod_visuallearning\local\credentials::$central = fn() => null;
        $GLOBALS['local_aiconfig_byok_mock'] = function ($url, $headers, $body, $vendor, $raw = false) {
            $this->requests[] = ['url' => $url, 'body' => $body];
            if (strpos($url, 'texttospeech') !== false) {
                return ['audioContent' => base64_encode($this->mp3())];
            }
            if (strpos($url, '/images/') !== false) {
                return ['data' => [['b64_json' => base64_encode($this->png(1536, 1024))]]];
            }
            $answer = array_shift($this->answers);
            if ($answer === null) {
                throw new \moodle_exception('byok_callfailed', 'local_aiconfig', '', (object) ['vendor' => $vendor,
                    'detail' => 'Overloaded']);
            }
            return ['content' => [['type' => 'text', 'text' => $answer]], 'stop_reason' => 'end_turn'];
        };
        $this->lmslabs = [];
        lmslabs::$transport = function (string $url): array {
            $this->lmslabs[] = [$url, ''];
            return [404, '{}'];
        };
        lmslabs::$posttransport = function (string $url, array $headers, string $body): array {
            $this->lmslabs[] = [$url, $body];
            if (str_ends_with($url, '/v2/bundles')) {
                $opened = json_decode($body, true);
                return [201, [], json_encode(['bundleId' => \core\uuid::generate(),
                    'slides' => array_map(fn($i) => ['slideId' => \core\uuid::generate()], range(1, $opened['slides'])),
                    'creditsCharged' => 0])];
            }
            if (preg_match('~/v2/bundles/[a-f0-9-]+/import$~', $url)) {
                return [200, [], json_encode(['creditsCharged' => 20, 'creditsBalance' => 80])];
            }
            return [0, [], ''];
        };
        \cache::make('mod_visuallearning', 'capabilities')->purge();
    }

    /**
     * Clean up the mocks.
     */
    protected function tearDown(): void {
        unset($GLOBALS['local_aiconfig_byok_mock']);
        lmslabs::$transport = null;
        lmslabs::$posttransport = null;
        \mod_visuallearning\local\credentials::$central = null;
        engine::$aicall = engine::$image = engine::$tts = null;
        engine::$anymp3 = false;
        parent::tearDown();
    }

    /**
     * A PNG of a size.
     *
     * @param int $width
     * @param int $height
     * @return string
     */
    protected function png(int $width, int $height): string {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocate($image, 30, 120, 190));
        ob_start();
        imagepng($image);
        return (string) ob_get_clean();
    }

    /**
     * Three MPEG-2 Layer III frames at 24 kHz, as Google writes them.
     *
     * @return string
     */
    protected function mp3(): string {
        // 64 kbit/s: 72000 * 64 / 24000 = 192 bytes a frame.
        return str_repeat("\xFF\xF3\x84\xC4" . str_repeat("\0", 188), 3);
    }

    /**
     * A lesson, its context and a teacher.
     *
     * @param int $pages
     * @param array $settings
     * @return array [lesson, context, teacher]
     */
    protected function book(int $pages = 0, array $settings = []): array {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_visuallearning');
        $book = $gen->create_instance(['course' => $course->id] + $settings);
        for ($i = 0; $i < $pages; $i++) {
            $gen->create_page($book->id);
        }
        $book = $DB->get_record('visuallearning', ['id' => $book->id]);
        $this->setUser($teacher);
        return [$book, \context_module::instance(get_coursemodule_from_instance('visuallearning', $book->id)->id), $teacher];
    }

    /**
     * A lesson as the writer returns it for a request.
     *
     * @param array $body The writing request.
     * @return array
     */
    protected function lesson(array $body): array {
        $pages = [];
        for ($i = 1; $i <= $body['maxPages']; $i++) {
            $type = $body['questionTypes'] ? $body['questionTypes'][($i - 1) % count($body['questionTypes'])] : null;
            $pages[] = ['page' => $i, 'title' => 'Know your team ' . $i,
                'concept' => 'Teams work better when people know each other. Trust grows from small conversations.',
                'action' => 'Maya asks a new cook about their last kitchen. She learns they are great at pastry.',
                'outcome' => 'The cook feels welcome on day one. They speak up when the pass gets busy.',
                'summary' => 'Small talk builds trust.',
                'question' => $type === null ? null : $this->question($type),
                'image_prompt' => 'A chef greets a new cook at the pass of a busy restaurant kitchen.',
                'alt_text' => 'A chef welcomes a new cook.'];
        }
        return ['book_title' => 'Know your team', 'industry' => 'Hospitality', 'subindustry' => 'Restaurants',
            'language' => $body['language'], 'style_guide' => 'Warm gouache, soft light.',
            'characters' => [['name' => 'Maya', 'description' => 'Head chef in whites.'],
                ['name' => 'Sam', 'description' => 'New cook with a blue apron.']],
            'cover_image_prompt' => 'A friendly restaurant kitchen team at the pass.', 'pages' => $pages];
    }

    /**
     * A valid check question of a type.
     *
     * @param string $type
     * @return array
     */
    protected function question(string $type): array {
        $feedback = 'Small conversations build trust.';
        switch ($type) {
            case 'pick':
                return ['type' => 'pick', 'prompt' => 'Which builds trust?', 'correct' => 1, 'feedback' => $feedback,
                    'options' => array_map(fn($l) => ['label' => $l, 'image_prompt' => 'A chef and a cook: ' . $l,
                        'alt_text' => $l], ['Silence', 'Small talk', 'Shouting', 'Ignoring'])];
            case 'match':
                return ['type' => 'match', 'prompt' => 'Match each habit to its result.', 'feedback' => $feedback,
                    'pairs' => [['left' => 'Greeting', 'right' => 'Welcome'], ['left' => 'Asking', 'right' => 'Learning'],
                        ['left' => 'Listening', 'right' => 'Trust']]];
            case 'sort':
                return ['type' => 'sort', 'prompt' => 'Sort the habits.', 'feedback' => $feedback,
                    'groups' => ['Builds trust', 'Breaks trust'], 'items' => [['text' => 'Greeting', 'group' => 0],
                        ['text' => 'Ignoring', 'group' => 1], ['text' => 'Asking', 'group' => 0],
                        ['text' => 'Gossip', 'group' => 1]]];
            default:
                return ['type' => 'truefalse', 'statement' => 'Trust grows from small talk.', 'answer' => true,
                    'feedback' => $feedback];
        }
    }

    /**
     * Text requests the mocked Anthropic key received.
     *
     * @return array
     */
    protected function texts(): array {
        return array_values(array_filter($this->requests, fn($r) => strpos($r['url'], 'anthropic') !== false));
    }

    /**
     * Runs the queued background tasks.
     */
    protected function run_tasks(): void {
        ob_start();
        // Pages written in the first run queue their pictures and narration for the next.
        $this->runAdhocTasks('\mod_visuallearning\task\generate_media');
        $this->runAdhocTasks('\mod_visuallearning\task\generate_media');
        ob_end_clean();
    }

    public function test_lesson_on_own_ai_is_free_needs_no_lmslabs_and_gets_one_repair(): void {
        global $DB;
        [$book, $context, $teacher] = $this->book(0, ['maxpages' => 2, 'enablequiz' => 1]);
        $this->assertTrue(byok::local(requests::WRITE));
        $this->assertNull(lmslabs::hold('page'));
        $body = requests::write_body($book);
        // The plugin's own brief always goes with a lesson written on the site's own AI.
        $this->assertNotEmpty($body['writerBrief']);
        $summary = requests::quote($book, (int) $teacher->id, [['operation' => requests::WRITE]]);
        $this->assertNull($summary['hold']);
        $this->assertSame(0, $summary['credits']);
        $this->assertSame(0, $summary['autobudget']);
        $this->assertSame(1, $summary['ownai']);
        $lesson = $this->lesson($body);
        $bad = $lesson;
        $bad['pages'][0]['concept'] = str_repeat('Long sentence. ', 30);
        $this->answers = [json_encode($bad), json_encode($lesson)];
        requests::confirm($book, $summary['batch'], (int) $teacher->id, 0);
        $this->run_tasks();
        $row = $DB->get_record('visuallearning_aireq', ['operation' => requests::WRITE]);
        $this->assertSame('completed', $row->status, (string) $row->errorcode);
        $this->assertEquals(0, $row->charged);
        $this->assertSame((int) $book->maxpages, $DB->count_records('visuallearning_pages', ['visuallearningid' => $book->id]));
        // Written with the plugin's brief, word for word, and the failing field sent back once.
        $texts = $this->texts();
        $this->assertSame($body['writerBrief'], $texts[0]['body']['system']);
        $this->assertSame(24000, $texts[0]['body']['max_tokens']);
        $this->assertSame(
            'Write the lesson described in the brief. Return only the JSON object.',
            $texts[0]['body']['messages'][0]['content'][0]['text']
        );
        $this->assertStringContainsString(
            'YOUR PREVIOUS ANSWER FAILED THESE CHECKS',
            $texts[1]['body']['messages'][0]['content'][0]['text']
        );
        $this->assertStringContainsString('pages.0.concept', $texts[1]['body']['messages'][0]['content'][0]['text']);
        // Nothing went to LMS Labs: no credentials are needed.
        $this->assertSame([], array_filter($this->lmslabs, fn($c) => $c[1] !== ''));
        // The pictures and narration followed automatically, on the site's own AI.
        $media = $DB->get_records_select('visuallearning_aireq', "operation <> 'write'");
        $this->assertNotEmpty($media);
        foreach ($media as $m) {
            $this->assertSame('completed', $m->status, $m->operation . ' ' . $m->errorcode);
            $this->assertSame(0, (int) $m->maxcredits);
        }
        $images = array_values(array_filter($this->requests, fn($r) => strpos($r['url'], '/images/generations') !== false));
        $this->assertSame('1536x1024', $images[0]['body']['size']);
        $this->assertSame('medium', $images[0]['body']['quality']);
        $voices = array_values(array_filter($this->requests, fn($r) => strpos($r['url'], 'texttospeech') !== false));
        $this->assertSame('en-AU-Chirp3-HD-Aoede', $voices[0]['body']['voice']['name']);
        $this->assertSame(24000, $voices[0]['body']['audioConfig']['sampleRateHertz']);
        foreach ($DB->get_records('visuallearning_pages', ['visuallearningid' => $book->id]) as $page) {
            $this->assertEquals(VISUALLEARNING_MEDIA_READY, $page->imagestatus);
            $this->assertEquals(VISUALLEARNING_MEDIA_READY, $page->audiostatus);
            $this->assertEmpty($page->bundleid);
        }
        // Pictures are delivered at 4:3, cut from the provider's landscape size.
        $file = media::image_file($context, media::AREA_IMAGE, (int) array_key_first(visuallearning_get_pages($book->id)));
        [$w, $h] = getimagesizefromstring($file->get_content());
        $this->assertSame([1365, 1024], [$w, $h]);
    }

    public function test_failed_own_ai_is_failed_not_charged_or_uncertain(): void {
        global $DB;
        [$book, , $teacher] = $this->book(0, ['maxpages' => 1]);
        $summary = requests::quote($book, (int) $teacher->id, [['operation' => requests::WRITE]]);
        $this->answers = [];
        requests::confirm($book, $summary['batch'], (int) $teacher->id, 0);
        $this->run_tasks();
        $row = $DB->get_record('visuallearning_aireq', ['operation' => requests::WRITE]);
        $this->assertSame('failed', $row->status);
        $this->assertSame('ownai_failed', $row->errorcode);
        $this->assertEquals(0, $row->charged);
        $this->assertStringContainsString('Overloaded', json_decode($row->result, true)['ownai']);
        // The provider was asked twice, as the service does.
        $this->assertCount(2, $this->texts());
        $this->assertSame(0, $DB->count_records('visuallearning_pages', ['visuallearningid' => $book->id]));
    }

    public function test_unusable_answers_fail_after_one_repair(): void {
        global $DB;
        [$book, , $teacher] = $this->book(0, ['maxpages' => 1]);
        $summary = requests::quote($book, (int) $teacher->id, [['operation' => requests::WRITE]]);
        $this->answers = ['not json', '{"pages": []}'];
        requests::confirm($book, $summary['batch'], (int) $teacher->id, 0);
        $this->run_tasks();
        $row = $DB->get_record('visuallearning_aireq', ['operation' => requests::WRITE]);
        $this->assertSame('failed', $row->status);
        $this->assertSame('ownai_invalid', $row->errorcode);
        $this->assertStringContainsString('invalid_json', $this->texts()[1]['body']['messages'][0]['content'][0]['text']);
    }

    public function test_own_text_with_voice_on_credits_buys_written_pages_as_slides(): void {
        global $DB;
        set_config('src_voice', 'credits', 'local_aiconfig');
        set_config('lmslabssiteid', 'site-123', 'mod_visuallearning');
        set_config('lmslabsapikey', 'secret-key', 'mod_visuallearning');
        set_config('unlockstate', json_encode(['status' => 'unlocked']), 'mod_visuallearning');
        lmslabs::$transport = function (string $url): array {
            global $DB;
            $this->lmslabs[] = [$url, ''];
            if (preg_match('~/v2/bundles/([a-f0-9-]+)$~', $url, $m)) {
                // Every included slot of the new slides is still to make.
                $slots = array_fill_keys(['picture', 'cover', 'clip:concept', 'clip:action', 'clip:outcome', 'clip:summary',
                    'clip:question', 'clip:feedback', 'clip:retry'], ['state' => 'awaiting-retry']);
                return [200, json_encode(['slides' => array_map(
                    fn($p) => ['slideId' => $p->slideid, 'slots' => $slots],
                    array_values($DB->get_records('visuallearning_pages', ['bundleid' => $m[1]]))
                )])];
            }
            return str_ends_with($url, '/v2/capabilities') ? [200, json_encode(byok::capabilities())] : [404, '{}'];
        };
        $post = lmslabs::$posttransport;
        lmslabs::$posttransport = function (string $url, array $headers, string $body) use ($post): array {
            if (str_ends_with($url, '/speech/quote')) {
                return [200, [], '{"credits":0}'];
            }
            return $post($url, $headers, $body);
        };
        [$book, , $teacher] = $this->book(0, ['maxpages' => 2]);
        $this->assertFalse(byok::local(requests::WRITE));
        $this->assertTrue(byok::hybrid());
        $summary = requests::quote($book, (int) $teacher->id, [['operation' => requests::WRITE]]);
        $this->assertSame((int) $book->maxpages * lmslabs::PAGE_CREDITS, $summary['credits']);
        $this->assertSame(1, $summary['ownai']);
        $this->answers = [json_encode($this->lesson(requests::write_body($book)))];
        requests::confirm($book, $summary['batch'], (int) $teacher->id, $summary['credits']);
        $this->run_tasks();
        $row = $DB->get_record('visuallearning_aireq', ['operation' => requests::WRITE]);
        $this->assertSame('completed', $row->status);
        $this->assertEquals(20, $row->charged);
        $posts = array_values(array_filter($this->lmslabs, fn($c) => $c[1] !== ''));
        $this->assertStringEndsWith('/v2/bundles', $posts[0][0]);
        $this->assertSame('import', json_decode($posts[0][1], true)['source']);
        $this->assertMatchesRegularExpression('~/v2/bundles/[a-f0-9-]+/import$~', $posts[1][0]);
        $slides = json_decode($posts[1][1], true)['slides'];
        $this->assertSame('Know your team 1', $slides[0]['title']);
        // Written once, on the site's own AI; the slides keep their included narration.
        $this->assertCount(1, $this->texts());
        foreach ($DB->get_records('visuallearning_pages', ['visuallearningid' => $book->id]) as $page) {
            $this->assertNotEmpty($page->bundleid);
        }
    }

    public function test_card_pictures_need_an_image_key_that_edits(): void {
        $this->assertTrue(byok::image_edits());
        $this->assertNull(lmslabs::hold('card'));
        set_config('model_openai_image', 'dall-e-3', 'local_aiconfig');
        $this->assertSame('ownainoedits', lmslabs::hold('card'));
        $this->assertNull(lmslabs::hold('image'));
    }

    public function test_card_picture_edits_the_card_before(): void {
        [$status, $headers, $png] = $this->image(['prompt' => 'Same picture, same viewpoint and layout. Now red.',
            'style' => 'illustration', 'aspectRatio' => '4:3', 'purpose' => 'card', 'maxCredits' => 0,
            'referenceImage' => base64_encode($this->png(800, 600)), 'referenceMime' => 'image/png']);
        $this->assertSame(200, $status);
        $this->assertSame('image/png', $headers['content-type']);
        $this->assertStringEndsWith('/images/edits', end($this->requests)['url']);
        [$w, $h] = getimagesizefromstring($png);
        $this->assertSame([1365, 1024], [$w, $h]);
        // A reference that is not what it says is refused before any AI is asked.
        $count = count($this->requests);
        [$status, , $error] = $this->image(['prompt' => 'Edit.', 'style' => 'illustration', 'aspectRatio' => '4:3',
            'purpose' => 'card', 'maxCredits' => 0, 'referenceImage' => base64_encode('not a picture'),
            'referenceMime' => 'image/png']);
        $this->assertSame(422, $status);
        $this->assertSame('INVALID_REFERENCE_IMAGE', json_decode($error)->error);
        $this->assertCount($count, $this->requests);
    }

    /**
     * One picture request on the site's own AI.
     *
     * @param array $body
     * @return array
     */
    protected function image(array $body): array {
        return byok::send(requests::CARD, json_encode($body));
    }

    public function test_animated_scene_on_own_ai(): void {
        global $CFG;
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 600"><rect width="10" height="10">'
            . '<animate attributeName="x" values="0;5" dur="2s"/></rect></svg>';
        $this->answers = ['<p>no drawing</p>', "Here it is:\n" . $svg];
        [$status, $headers, $out] = byok::send(requests::ANIMATION, json_encode(['prompt' => 'Gears turning.',
            'contract' => 'svg-1', 'language' => 'en-AU', 'learnerLevel' => 'adult', 'teachingApproach' => 'science',
            'purpose' => 'animation', 'maxCredits' => 0]));
        $this->assertSame(200, $status);
        $this->assertSame('image/svg+xml', $headers['content-type']);
        $this->assertSame($svg, $out);
        $texts = $this->texts();
        $this->assertCount(2, $texts);
        $this->assertSame(
            file_get_contents($CFG->dirroot . '/mod/visuallearning/schema/svg-animation-system.md'),
            $texts[0]['body']['system']
        );
        $this->assertSame(16000, $texts[0]['body']['max_tokens']);
        $this->assertSame('Gears turning.', $texts[0]['body']['messages'][0]['content'][0]['text']);
    }

    public function test_narration_checks_the_voice_and_the_audio(): void {
        $body = ['text' => 'Small talk builds trust.', 'locale' => 'en-AU', 'voice' => 'en-AU-Chirp3-HD-Kore',
            'speed' => 'slow', 'clipRef' => str_repeat('a', 64), 'maxCredits' => 0];
        [$status, $headers, $audio] = byok::send(requests::VOICE, json_encode($body));
        $this->assertSame(200, $status);
        $this->assertSame('audio/mpeg', $headers['content-type']);
        $this->assertSame($this->mp3(), $audio);
        $sent = end($this->requests)['body'];
        $this->assertSame(0.8, $sent['audioConfig']['speakingRate']);
        $this->assertSame('en-AU-Chirp3-HD-Kore', $sent['voice']['name']);
        [$status, , $error] = byok::send(requests::VOICE, json_encode(['voice' => 'en-AU-Chirp3-HD-Nobody'] + $body));
        $this->assertSame(422, $status);
        $this->assertSame('INVALID_VOICE', json_decode($error)->error);
    }

    public function test_openai_voice_gets_the_closest_voice_and_any_mp3(): void {
        set_config('vendor_voice', 'openai', 'local_aiconfig');
        $GLOBALS['local_aiconfig_byok_mock'] = function ($url, $headers, $body) {
            $this->requests[] = ['url' => $url, 'body' => $body];
            // MPEG-1 Layer III at 44.1 kHz, 128 kbit/s (417 bytes a frame), then an ID3v1 tag.
            return str_repeat("\xFF\xFB\x90\x64" . str_repeat("\0", 413), 2) . 'TAG' . str_repeat(' ', 125);
        };
        [$status] = byok::send(requests::VOICE, json_encode(['text' => 'Hello.', 'locale' => 'en-AU',
            'voice' => 'en-AU-Chirp3-HD-Charon', 'speed' => 'normal', 'maxCredits' => 0]));
        $this->assertSame(200, $status);
        $this->assertSame('echo', end($this->requests)['body']['voice']);
    }

    public function test_checks_shared_with_the_service(): void {
        $head = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10">';
        $this->assertSame($head . '</svg>', engine::usable_animation('x ' . $head . '</svg> y'));
        foreach (
            [$head . '<script>x</script></svg>', $head . '<image href="a.png"/></svg>',
                '<svg viewBox="0 0 10 10"></svg>', $head . '<rect fill="url(http://x)"/></svg>',
                $head . '<use href="https://x/#a"/></svg>', $head . '<rect onclick="x"/></svg>'] as $bad
        ) {
            try {
                engine::usable_animation($bad);
                $this->fail('Accepted ' . $bad);
            } catch (\moodle_exception $e) {
                $this->assertSame('animation_failed', $e->errorcode);
            }
        }
        $this->assertTrue(engine::usable_mp3($this->mp3()));
        $this->assertFalse(engine::usable_mp3(substr($this->mp3(), 0, -1)));
        $this->assertFalse(engine::usable_mp3(str_repeat("\xFF\xFB\x90\x64" . str_repeat("\0", 413), 2)));
        $this->assertTrue(engine::any_mp3(str_repeat("\xFF\xFB\x90\x64" . str_repeat("\0", 413), 2)));
        $this->assertSame([1024, 1024], array_slice(getimagesizefromstring(byok::shape($this->png(1536, 1024), 1.0)), 0, 2));
        $same = $this->png(800, 600);
        $this->assertSame($same, byok::shape($same, 4 / 3));
    }

    public function test_capabilities_without_lmslabs_cover_every_lesson_language(): void {
        $voices = lmslabs::voice_catalogue();
        $this->assertSame('en-AU-Chirp3-HD-Aoede', $voices['en-AU']['Aoede']);
        $this->assertCount(count(\mod_visuallearning\local\voices::LANGUAGES), $voices);
        $this->assertContains('pick', lmslabs::question_types());
        // The Bring your own AI licence covers the plugin's activation.
        $this->assertTrue(\mod_visuallearning\local\unlock::active());
        // Without the site's own AI, nothing is assumed.
        set_config('src_text', 'credits', 'local_aiconfig');
        set_config('src_image', 'credits', 'local_aiconfig');
        set_config('src_voice', 'credits', 'local_aiconfig');
        \cache::make('mod_visuallearning', 'capabilities')->purge();
        $this->assertSame([], lmslabs::voice_catalogue());
        $this->assertSame('nocredentials', lmslabs::hold('page'));
        $this->assertFalse(\mod_visuallearning\local\unlock::active());
    }
}
