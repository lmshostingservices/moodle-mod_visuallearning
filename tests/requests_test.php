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

/**
 * Tests for paid LMS Labs requests: price first, stored before sending, never sent twice, fail closed.
 *
 * LMS Labs is replaced by a fake server; nothing leaves the test site.
 *
 * @package    mod_visuallearning
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(requests::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(lmslabs::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(media::class)]
final class requests_test extends \advanced_testcase {
    /** @var array Calls made to the fake LMS Labs: [url, headers, body]. */
    protected $calls = [];

    /** @var array Queued answers per route suffix: list of [status, headers, body]. */
    protected $answers = [];

    /** @var array Published capabilities per kind. */
    protected $caps = [];

    /** @var string A tiny PNG. */
    protected const PNG = "\x89PNG\r\n\x1a\n" . 'fakepngbytes';

    /** @var string A tiny MP3 (ID3 tag). */
    protected const MP3 = 'ID3' . "\x03\x00" . 'fakemp3bytes';

    /**
     * A connected, unlocked site with the approved prices published and a fake LMS Labs.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('lmslabssiteid', 'site-123', 'mod_visuallearning');
        set_config('lmslabsapikey', 'secret-key', 'mod_visuallearning');
        set_config('unlockstate', json_encode(['status' => 'unlocked']), 'mod_visuallearning');
        \mod_visuallearning\local\credentials::$central = fn() => null;
        $this->caps = [
            'pages' => ['tariff' => ['page' => lmslabs::PAGE_CREDITS]],
            'images' => ['tariff' => ['image' => lmslabs::IMAGE_CREDITS]],
            'speech' => [
                'tariff' => ['tts' => lmslabs::VOICE_CREDITS],
                'locales' => [
                    ['locale' => 'en-AU', 'ttsLanguageCode' => 'en-AU',
                        'voices' => [['name' => 'en-AU-Chirp3-HD-Aoede'], ['name' => 'en-AU-Chirp3-HD-Charon'],
                            ['name' => 'Made-up-voice']]],
                ],
            ],
        ];
        lmslabs::$transport = function (string $url, array $headers): array {
            $this->calls[] = [$url, $headers, ''];
            if (str_ends_with($url, '/v2/capabilities')) {
                return [200, json_encode([
                    'contract' => 'bundle-1',
                    'bundle' => ['slideCredits' => $this->caps['pages']['tariff']['page'] ?? null],
                    'remakes' => ['pictureCredits' => $this->caps['images']['tariff']['image'] ?? null,
                        'voice' => ['credits' => $this->caps['speech']['tariff']['tts'] ?? null]],
                    'locales' => $this->caps['speech']['locales'] ?? [],
                    'questionTypes' => $this->caps['pages']['questionTypes'] ?? ['truefalse'],
                    'aspectRatios' => $this->caps['images']['aspectRatios'] ?? ['4:3'],
                    'pictureModes' => $this->caps['images']['pictureModes'] ?? null,
                    'draftPictureModes' => $this->caps['pages']['draftPictureModes'] ?? null,
                    'imageFields' => $this->caps['images']['imageFields'] ?? null,
                    'learnerLevels' => $this->caps['pages']['learnerLevels'] ?? null,
                    'cardPictures' => isset($this->caps['images']['cardCredits'])
                        ? ['credits' => $this->caps['images']['cardCredits']] : null,
                ])];
            }
            foreach ($this->caps as $kind => $data) {
                if (str_ends_with($url, '/' . $kind . '/capabilities')) {
                    return $data === null ? [404, '{}'] : [200, json_encode($data)];
                }
            }
            if (str_contains($url, '/api/credits')) {
                return [200, json_encode(['creditsRaw' => 500])];
            }
            return [404, '{}'];
        };
        lmslabs::$posttransport = function (string $url, array $headers, string $body): array {
            $this->calls[] = [$url, $headers, $body];
            if (str_ends_with($url, '/v2/bundles')) {
                $opened = json_decode($body, true);
                return [201, [], json_encode(['bundleId' => \core\uuid::generate(),
                    'slides' => array_map(fn($i) => ['slideId' => \core\uuid::generate()], range(1, $opened['slides'])),
                    'creditsCharged' => 0])];
            }
            if (preg_match('~/v2/bundles/[a-f0-9-]+/(draft|import|manual)$~', $url, $match)) {
                $url = '/pages/' . ($match[1] === 'draft' ? 'draft' : 'import');
            }
            foreach ($this->answers as $suffix => $list) {
                if (str_ends_with($url, $suffix) && $list) {
                    return array_shift($this->answers[$suffix]);
                }
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
     * A lesson with pages, its context and a teacher.
     *
     * @param int $pages
     * @param array $settings
     * @return array [lesson, context, teacher]
     */
    protected function book(int $pages = 1, array $settings = []): array {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_visuallearning');
        $book = $gen->create_instance(['course' => $course->id] + $settings);
        for ($i = 0; $i < $pages; $i++) {
            $gen->create_page($book->id);
        }
        $book = $DB->get_record('visuallearning', ['id' => $book->id]);
        return [$book, \context_module::instance($book->cmid ?? get_coursemodule_from_instance('visuallearning', $book->id)->id),
            $teacher];
    }

    /**
     * POST calls made to the fake LMS Labs (not the free GETs).
     *
     * @param string $suffix
     * @return array
     */
    protected function posts(string $suffix): array {
        return array_values(array_filter($this->calls, fn($c) => $c[2] !== '' && (str_ends_with($c[0], $suffix) ||
            (str_starts_with($suffix, '/pages/') && preg_match('~/v2/bundles/[a-f0-9-]+/' . basename($suffix) . '$~', $c[0])))));
    }

    /**
     * Runs the queued background tasks.
     */
    protected function run_tasks(): void {
        ob_start();
        $this->runAdhocTasks('\mod_visuallearning\task\generate_media');
        ob_end_clean();
    }

    /** @var array Fake durable server-side slot states. */
    protected $bundleslots = [];

    /**
     * Make an existing purchased slide and mock its entitlement lookup.
     *
     * @return array
     */
    protected function bundle_fixture(): array {
        global $DB;
        [$book, $context, $teacher] = $this->book(1);
        $pages = visuallearning_get_pages($book->id);
        $page = reset($pages);
        $page->bundleid = \core\uuid::generate();
        $page->slideid = \core\uuid::generate();
        $page->bundlesite = requests::sitehash();
        $DB->update_record('visuallearning_pages', $page);
        $base = lmslabs::$transport;
        lmslabs::$transport = function ($url, $headers) use ($page, $base) {
            if (str_ends_with($url, '/v2/bundles/' . $page->bundleid)) {
                return [200, json_encode(['slides' => [['slideId' => $page->slideid, 'slots' => $this->bundleslots]]])];
            }
            return $base($url, $headers);
        };
        return [$book, $context, $teacher, $page];
    }

    public function test_bundle_first_media_is_free_and_bound_to_the_purchased_slide(): void {
        [$book, $context, $teacher, $page] = $this->bundle_fixture();
        $this->bundleslots = ['picture' => ['state' => 'awaiting-retry']];
        $quote = requests::quote($book, $teacher->id, [[
            'operation' => requests::IMAGE, 'targetid' => $page->id, 'prompt' => media::image_prompt($book, $page),
        ]]);
        $this->assertSame(0, $quote['credits']);
        $rows = requests::quoted($book->id, $quote['batch']);
        $row = reset($rows);
        $body = json_decode($row->body, true);
        $this->assertSame($page->bundleid, $body['bundleId']);
        $this->assertSame($page->slideid, $body['slideId']);
        $this->assertSame('picture', $body['slot']);
        $this->assertSame(false, $body['remake']);
        $this->assertSame(0, $body['maxCredits']);
        $this->assertArrayNotHasKey('purpose', $body);
        $delivery = \core\uuid::generate();
        $this->answers['/images'] = [[200, ['content-type' => 'image/png',
            'x-credits-charged' => '0', 'x-delivery-id' => $delivery], self::PNG]];
        requests::confirm($book, $quote['batch'], $teacher->id, 0);
        $this->run_tasks();
        global $DB;
        $saved = $DB->get_record('visuallearning_pages', ['id' => $page->id]);
        $this->assertSame($delivery, json_decode($saved->bundledeliveries, true)['picture']);
        $this->assertStringEndsWith('/v2/images', $this->posts('/images')[0][0]);
        $this->assertContains('X-VL-Contract: bundle-1', $this->posts('/images')[0][1]);
    }

    public function test_lost_included_picture_reopens_the_same_slot_without_a_remake_charge(): void {
        [$book, $context, $teacher, $page] = $this->bundle_fixture();
        $delivery = \core\uuid::generate();
        $this->bundleslots = ['picture' => ['state' => 'delivered', 'deliveryId' => $delivery]];
        $this->answers['/lost'] = [[200, [], '{}'], [200, [], '{}']];
        $plan = [['operation' => requests::IMAGE, 'targetid' => $page->id, 'prompt' => media::image_prompt($book, $page)]];
        $first = requests::quote($book, $teacher->id, $plan);
        requests::cancel_quote($book->id, $first['batch']);
        $second = requests::quote($book, $teacher->id, $plan);
        $this->assertSame(0, $first['credits']);
        $this->assertSame(0, $second['credits']);
        $lost = $this->posts('/lost');
        $this->assertCount(2, $lost);
        $this->assertSame($lost[0][1], $lost[1][1]);
        $this->assertSame(['deliveryId' => $delivery, 'maxCredits' => 0], json_decode($lost[0][2], true));
    }

    public function test_optional_picture_remake_is_two_credits_only_after_saved_delivery(): void {
        global $DB;
        [$book, $context, $teacher, $page] = $this->bundle_fixture();
        $delivery = \core\uuid::generate();
        $this->bundleslots = ['picture' => ['state' => 'delivered', 'deliveryId' => $delivery]];
        $DB->set_field('visuallearning_pages', 'bundledeliveries', json_encode(['picture' => $delivery]), ['id' => $page->id]);
        media::save_image($context, media::AREA_IMAGE, $page->id, 'Old picture', self::PNG, 'png');
        $quote = requests::quote($book, $teacher->id, [[
            'operation' => requests::IMAGE, 'targetid' => $page->id, 'prompt' => 'Replacement picture',
        ]]);
        $this->assertSame(2, $quote['credits']);
        $rows = requests::quoted($book->id, $quote['batch']);
        $row = reset($rows);
        $this->assertTrue(json_decode($row->body, true)['remake']);
        $this->assertSame([], $this->posts('/lost'));
    }

    public function test_manual_slide_requires_ten_credits_and_preserves_existing_pages(): void {
        global $DB;
        [$book, $context, $teacher] = $this->book(1);
        $existing = array_keys(visuallearning_get_pages($book->id));
        $parsed = ['pages' => [['title' => 'Manual', 'concept' => 'C.', 'action' => 'A.', 'outcome' => 'O.',
            'image_prompt' => 'A concrete scene.', 'alt_text' => 'A scene', 'summary' => 'Summary',
            'question' => null]]];
        $quote = requests::quote($book, $teacher->id, [['operation' => requests::IMPORT,
            'body' => ['_manual' => true, 'pageCount' => 1, 'titles' => ['Manual'], 'maxCredits' => 10]]],
            json_encode($parsed));
        $this->assertSame(10, $quote['credits']);
        $this->assertSame(1, $DB->count_records('visuallearning_pages', ['visuallearningid' => $book->id]));
        $this->answers['/pages/import'] = [[200, [], '{"creditsCharged":10}']];
        $rows = requests::confirm($book, $quote['batch'], $teacher->id, 10);
        $this->assertSame('completed', $rows[0]->status);
        $this->assertSame(2, $DB->count_records('visuallearning_pages', ['visuallearningid' => $book->id]));
        $this->assertTrue($DB->record_exists('visuallearning_pages', ['id' => $existing[0]]));
        $new = $DB->get_record('visuallearning_pages', ['visuallearningid' => $book->id, 'title' => 'Manual']);
        $this->assertNotEmpty($new->bundleid);
        $this->assertNotEmpty($new->slideid);
        $opened = json_decode($this->posts('/bundles')[0][2], true);
        $this->assertSame('manual', $opened['source']);
        $this->assertSame(1, $opened['slides']);
        $this->assertSame(10, $opened['maxCredits']);
    }

    public function test_initial_bundle_voice_quote_has_no_legacy_fields_and_no_charge(): void {
        [$book, $context, $teacher, $page] = $this->bundle_fixture();
        $this->bundleslots = ['clip:concept' => ['state' => 'awaiting-retry']];
        $this->answers['/speech/quote'] = [[200, [], '{"credits":0}']];
        [$body, $price] = \mod_visuallearning\local\ai\bundles::media_quote(
            $book->id, requests::VOICE, $page->id, 'concept',
            ['text' => 'A short concept.', 'locale' => 'en-AU', 'voice' => 'Aoede', 'speed' => 'normal',
                'clipRef' => 'old-reference', 'maxCredits' => 2]
        );
        $this->assertSame(0, $price);
        $this->assertSame('clip:concept', $body['slot']);
        $this->assertArrayNotHasKey('clipRef', $body);
        $quoted = json_decode($this->posts('/speech/quote')[0][2], true);
        $this->assertSame(0, $quoted['maxCredits']);
        $this->assertSame(['bundleId', 'slideId', 'slot', 'remake', 'maxCredits'], array_keys($quoted));
    }

    public function test_quote_stores_requests_and_sends_nothing(): void {
        global $DB;
        [$book, $context, $teacher] = $this->book(2);
        $plans = media::plan($book, $context, 'missing', 0, ['image', 'voice']);
        // 2 illustrations and 2 × 5 clips (three cards, recap, true/false).
        $this->assertCount(12, $plans);
        $summary = requests::quote($book, (int)$teacher->id, $plans);
        $this->assertNull($summary['hold']);
        $this->assertSame(2 * lmslabs::IMAGE_CREDITS + 10 * lmslabs::VOICE_CREDITS, $summary['credits']);
        $this->assertSame([], $this->posts('/images'));
        $this->assertSame([], $this->posts('/speech/tts'));
        $rows = $DB->get_records('visuallearning_aireq', ['batch' => $summary['batch']]);
        $this->assertCount(12, $rows);
        foreach ($rows as $row) {
            $this->assertSame('quoted', $row->status);
            $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $row->idemkey);
            $this->assertNotEmpty(json_decode($row->body, true));
            $this->assertStringNotContainsString('secret-key', $row->body);
        }
        // The recap clip speaks its number in the recap.
        $summaryclip = $DB->get_record('visuallearning_aireq', ['batch' => $summary['batch'], 'part' => 'summary',
            'targetid' => array_key_first(visuallearning_get_pages($book->id))]);
        $this->assertStringStartsWith('1. ', json_decode($summaryclip->body, true)['text']);
        requests::cancel_quote((int)$book->id, $summary['batch']);
        $this->assertSame(0, $DB->count_records('visuallearning_aireq'));
    }

    public function test_confirm_sends_once_and_saves_media(): void {
        global $DB;
        [$book, $context, $teacher] = $this->book(1);
        $pages = visuallearning_get_pages($book->id);
        $page = reset($pages);
        $summary = requests::quote($book, (int)$teacher->id, media::plan($book, $context, 'missing', 0, ['image', 'voice']));
        $this->answers['/images'] = [[200, ['content-type' => 'image/png', 'x-credits-charged' => '2',
            'x-credits-balance' => '98'], self::PNG]];
        $this->answers['/speech/tts'] = array_fill(0, 5, [200, ['content-type' => 'audio/mpeg', 'x-credits-charged' => '2'],
            self::MP3]);
        requests::confirm($book, $summary['batch'], (int)$teacher->id, $summary['credits']);
        $this->run_tasks();
        $this->assertCount(1, $this->posts('/images'));
        $this->assertCount(5, $this->posts('/speech/tts'));
        foreach ($DB->get_records('visuallearning_aireq') as $row) {
            $this->assertSame('completed', $row->status);
            $this->assertEquals(1, $row->tries);
        }
        // Keys are sent in headers only, never in the body, and every request has its own Idempotency-Key.
        $keys = [];
        foreach (array_merge($this->posts('/images'), $this->posts('/speech/tts')) as [$url, $headers, $body]) {
            $this->assertContains('X-API-Key: secret-key', $headers);
            $this->assertStringNotContainsString('secret-key', $body . $url);
            $keys[] = implode('', preg_grep('/^Idempotency-Key: /', $headers));
        }
        $this->assertCount(6, array_unique($keys));
        $page = $DB->get_record('visuallearning_pages', ['id' => $page->id]);
        $this->assertEquals(VISUALLEARNING_MEDIA_READY, $page->imagestatus);
        $this->assertEquals(VISUALLEARNING_MEDIA_READY, $page->audiostatus);
        $image = $DB->get_record('visuallearning_aireq', ['operation' => 'image']);
        $this->assertEquals(2, $image->charged);
        $this->assertEquals(98, $image->balance);
        // Nothing more is needed, so nothing more is quoted.
        $this->assertSame([], media::plan($book, $context, 'missing', 0, ['image', 'voice']));
    }

    public function test_timeout_is_uncertain_and_check_again_reuses_key_and_body(): void {
        global $DB;
        [$book, $context, $teacher] = $this->book(1);
        $summary = requests::quote($book, (int)$teacher->id, media::plan($book, $context, 'missing', 0, ['image']));
        // No answer at all (timeout).
        requests::confirm($book, $summary['batch'], (int)$teacher->id, $summary['credits']);
        $this->run_tasks();
        $row = $DB->get_record('visuallearning_aireq', ['operation' => 'image']);
        $this->assertSame('uncertain', $row->status);
        $this->assertSame('network', $row->errorcode);
        $this->assertNull($row->charged);
        // Never sent again on its own.
        $this->run_tasks();
        $this->assertCount(1, $this->posts('/images'));
        // A new quote does not ask for it again with a new key while it is unresolved.
        $again = requests::quote($book, (int)$teacher->id, media::plan($book, $context, 'missing', 0, ['image']));
        $this->assertSame([], $again['items']);
        $this->assertSame(1, $again['skipped']);
        // Check again: the same key and the same body.
        $this->answers['/images'] = [[200, ['content-type' => 'image/png'], self::PNG]];
        requests::check($row, $book, (int)$teacher->id);
        $this->run_tasks();
        [$first, $second] = $this->posts('/images');
        $this->assertSame($first[2], $second[2]);
        $this->assertSame(preg_grep('/^Idempotency-Key/', $first[1]), preg_grep('/^Idempotency-Key/', $second[1]));
        $row = $DB->get_record('visuallearning_aireq', ['id' => $row->id]);
        $this->assertSame('completed', $row->status);
        // The charge was not reported, so it stays unknown (never assumed to be the confirmed maximum).
        $this->assertNull($row->charged);
        $this->assertStringContainsString('did not say what it charged', requests::export($row, $context)['message']);
    }

    public function test_error_answers(): void {
        global $DB;
        $cases = [
            [402, '{"error":"INSUFFICIENT_CREDITS","balance":1}', 'failed', 'insufficient_credits'],
            [404, '{}', 'failed', 'not_live'],
            [409, '{"error":"IDEMPOTENCY_CONFLICT"}', 'conflict', 'idempotency_conflict'],
            [410, '{"error":"RESULT_NOT_RETAINED"}', 'lost', 'result_not_retained'],
            [502, '{"error":"PROVIDER_FAILED"}', 'failed', 'provider_failed'],
            [500, '{}', 'uncertain', 'http_500'],
            [503, '{"error":"SETTLEMENT_UNCONFIRMED"}', 'uncertain', 'settlement_unconfirmed'],
        ];
        foreach ($cases as [$status, $body, $state, $code]) {
            [$book, $context, $teacher] = $this->book(1);
            $summary = requests::quote($book, (int)$teacher->id, media::plan($book, $context, 'missing', 0, ['image']));
            $this->answers['/images'] = [[$status, ['content-type' => 'application/json'], $body]];
            requests::confirm($book, $summary['batch'], (int)$teacher->id, $summary['credits']);
            $this->run_tasks();
            $row = $DB->get_record('visuallearning_aireq', ['visuallearningid' => $book->id, 'operation' => 'image']);
            $this->assertSame($state, $row->status, "HTTP $status");
            $this->assertSame($code, $row->errorcode, "HTTP $status");
            // A 410 alone is never recorded as a charge.
            $this->assertNull($row->charged);
            $this->assertNotEmpty(requests::export($row, $context)['message']);
            $this->assertFalse(media::image_current(
                $context,
                media::AREA_IMAGE,
                (int)$row->targetid,
                json_decode($row->body, true)['prompt']
            ));
        }
    }

    public function test_in_progress_is_asked_again_with_the_same_key(): void {
        global $DB;
        [$book, $context, $teacher] = $this->book(1);
        $summary = requests::quote($book, (int)$teacher->id, media::plan($book, $context, 'missing', 0, ['image']));
        $this->answers['/images'] = [[202, ['retry-after' => '1'], '{}'],
            [200, ['content-type' => 'image/png'], self::PNG]];
        requests::confirm($book, $summary['batch'], (int)$teacher->id, $summary['credits']);
        $this->run_tasks();
        $this->assertSame('pending', $DB->get_field('visuallearning_aireq', 'status', ['operation' => 'image']));
        $DB->set_field('task_adhoc', 'nextruntime', time() - 1, ['classname' => '\mod_visuallearning\task\generate_media']);
        $this->run_tasks();
        $this->assertSame('completed', $DB->get_field('visuallearning_aireq', 'status', ['operation' => 'image']));
        [$first, $second] = $this->posts('/images');
        $this->assertSame(preg_grep('/^Idempotency-Key/', $first[1]), preg_grep('/^Idempotency-Key/', $second[1]));
    }

    public function test_failed_new_voiceover_keeps_the_old_one(): void {
        global $DB;
        [$book, $context, $teacher] = $this->book(1);
        $pages = visuallearning_get_pages($book->id);
        $page = reset($pages);
        $summary = requests::quote($book, (int)$teacher->id, media::plan($book, $context, 'missing', 0, ['voice']));
        $this->answers['/speech/tts'] = array_fill(0, 5, [200, ['content-type' => 'audio/mpeg'], self::MP3]);
        requests::confirm($book, $summary['batch'], (int)$teacher->id, $summary['credits']);
        $this->run_tasks();
        $clips = media::clips($book, $page, 0);
        $before = media::clip_file($context, $book, $page, 'concept', $clips['concept']);
        $this->assertNotNull($before);
        $refs = $DB->get_fieldset_sql("SELECT body FROM {visuallearning_aireq} WHERE part = ? ORDER BY id", ['concept']);
        // A new voice: the old clip keeps playing until the new one is made, and the new one fails.
        $DB->set_field('visuallearning', 'voice', 'Charon', ['id' => $book->id]);
        $book->voice = 'Charon';
        $summary = requests::quote($book, (int)$teacher->id, media::plan($book, $context, 'missing', 0, ['voice']));
        $this->assertSame(5, $summary['items']['voice']);
        $this->answers['/speech/tts'] = array_fill(0, 5, [502, [], '{"error":"PROVIDER_FAILED"}']);
        requests::confirm($book, $summary['batch'], (int)$teacher->id, $summary['credits']);
        $this->run_tasks();
        $this->assertFalse(media::clip_current($context, $book, $page, 'concept', $clips['concept']));
        $after = media::clip_file($context, $book, $page, 'concept', $clips['concept']);
        $this->assertNotNull($after);
        $this->assertSame($before->get_id(), $after->get_id());
        // The clip identity did not change with the voice.
        $bodies = $DB->get_fieldset_sql("SELECT body FROM {visuallearning_aireq} WHERE part = ? ORDER BY id", ['concept']);
        $this->assertCount(2, $bodies);
        $this->assertSame(json_decode($refs[0], true)['clipRef'], json_decode($bodies[1], true)['clipRef']);
        $this->assertSame('en-AU-Chirp3-HD-Charon', json_decode($bodies[1], true)['voice']);
    }

    public function test_prices_of_every_operation(): void {
        foreach (['write', 'import', 'image', 'cover', 'voice'] as $operation) {
            $op = requests::price_operation($operation);
            $this->assertSame(lmslabs::approved_price($op), lmslabs::published_price($op), $operation);
            $this->assertNull(lmslabs::hold($op), $operation);
        }
        $this->assertSame(lmslabs::PAGE_CREDITS, lmslabs::approved_price('import'));
    }

    public function test_clip_identity(): void {
        [$book] = $this->book(2);
        $pages = array_values(visuallearning_get_pages($book->id));
        $ref = requests::clipref($book, (int)$pages[0]->id, 'concept');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $ref);
        // Same place, same identity, whatever the text or the voice.
        $book->voice = 'Charon';
        $this->assertSame($ref, requests::clipref($book, (int)$pages[0]->id, 'concept'));
        // Another place, another identity.
        $this->assertNotSame($ref, requests::clipref($book, (int)$pages[1]->id, 'concept'));
        $this->assertNotSame($ref, requests::clipref($book, (int)$pages[0]->id, 'action'));
    }

    public function test_edit_after_confirmation_cancels_the_unsent_request(): void {
        global $DB;
        [$book, $context, $teacher] = $this->book(1);
        $pages = visuallearning_get_pages($book->id);
        $page = reset($pages);
        $summary = requests::quote($book, (int)$teacher->id, media::plan($book, $context, 'missing', 0, ['image']));
        requests::confirm($book, $summary['batch'], (int)$teacher->id, $summary['credits']);
        $DB->set_field('visuallearning_pages', 'imageprompt', 'A different scene.', ['id' => $page->id]);
        $this->run_tasks();
        $this->assertSame([], $this->posts('/images'));
        $row = $DB->get_record('visuallearning_aireq', ['operation' => 'image']);
        $this->assertSame('cancelled', $row->status);
        $this->assertSame('changed', $row->errorcode);
    }

    public function test_confirm_refuses_a_changed_total(): void {
        [$book, $context, $teacher] = $this->book(1);
        $summary = requests::quote($book, (int)$teacher->id, media::plan($book, $context, 'missing', 0, ['image']));
        $this->expectException(\moodle_exception::class);
        requests::confirm($book, $summary['batch'], (int)$teacher->id, $summary['credits'] + 1);
    }

    public function test_fail_closed(): void {
        [$book, $context, $teacher] = $this->book(1);
        $plans = media::plan($book, $context, 'missing', 0, ['image']);

        // No price published: nothing can be bought.
        $this->caps['images'] = null;
        \cache::make('mod_visuallearning', 'capabilities')->purge();
        $this->assertSame('priceunknown', lmslabs::hold('image'));
        $this->assertSame('priceunknown', requests::quote($book, (int)$teacher->id, $plans)['hold']);

        // A different price published.
        $this->caps['images'] = ['tariff' => ['image' => lmslabs::IMAGE_CREDITS + 3]];
        \cache::make('mod_visuallearning', 'capabilities')->purge();
        $this->assertSame('pricechanged', lmslabs::hold('image'));

        // Switched off.
        $this->caps['images'] = ['tariff' => ['image' => lmslabs::IMAGE_CREDITS]];
        \cache::make('mod_visuallearning', 'capabilities')->purge();
        $this->assertNull(lmslabs::hold('image'));
        set_config('aiimages', 0, 'mod_visuallearning');
        $this->assertSame('off', lmslabs::hold('image'));
        set_config('aiimages', 1, 'mod_visuallearning');

        // Not activated (locked, or never checked).
        set_config('unlockstate', json_encode(['status' => 'locked']), 'mod_visuallearning');
        $this->assertSame('notactivated', lmslabs::hold('image'));
        set_config('unlockstate', '', 'mod_visuallearning');
        $this->assertSame('notactivated', lmslabs::hold('image'));
        // An access check that got no answer keeps an unlocked site usable, as in the other LMS Labs plugins.
        set_config('unlockstate', json_encode(['status' => 'unknown', 'wasunlocked' => true]), 'mod_visuallearning');
        $this->assertNull(lmslabs::hold('image'));

        // No credentials.
        set_config('lmslabsapikey', '', 'mod_visuallearning');
        $this->assertSame('nocredentials', lmslabs::hold('image'));
        $this->assertSame('nocredentials', requests::quote($book, (int)$teacher->id, $plans)['hold']);
        $this->assertSame([], $this->posts('/images'));
    }

    public function test_voice_catalogue_only_lists_offered_voices(): void {
        $catalogue = lmslabs::voice_catalogue();
        $this->assertSame(['en-AU' => ['Aoede' => 'en-AU-Chirp3-HD-Aoede', 'Charon' => 'en-AU-Chirp3-HD-Charon']], $catalogue);
        $this->assertNull(lmslabs::voice_name('en-AU', 'Puck'));
        [$book, $context, $teacher] = $this->book(1, ['voice' => 'Puck']);
        $this->expectException(\moodle_exception::class);
        requests::quote($book, (int)$teacher->id, media::plan($book, $context, 'missing', 0, ['voice']));
    }

    public function test_free_remakes_are_quoted_and_confirmed_as_free(): void {
        global $DB;
        $this->caps['speech']['tariff'] += ['clipRefSupported' => true, 'maxCreditsRequired' => true, 'ttsRemakeLimit' => 10,
            'ttsRemakeWindowDays' => 30];
        [$book, $context, $teacher] = $this->book(1);
        $this->answers['/speech/quote'] = array_merge(
            [[200, [], json_encode(['credits' => 0, 'firstClip' => false, 'freeRemakesRemaining' => 9])]],
            array_fill(0, 4, [200, [], json_encode(['credits' => lmslabs::VOICE_CREDITS])])
        );
        $summary = requests::quote($book, (int)$teacher->id, media::plan($book, $context, 'missing', 0, ['voice']));
        $this->assertSame(4 * lmslabs::VOICE_CREDITS, $summary['credits']);
        $this->assertSame(1, $summary['free']);
        $free = $DB->get_record_select('visuallearning_aireq', 'maxcredits = 0');
        $this->assertSame(0, json_decode($free->body, true)['maxCredits']);
        // A quote failure counts at the full price (never confirmed as less than it can cost).
        $this->answers['/speech/quote'] = [];
        requests::cancel_quote((int)$book->id, $summary['batch']);
        $summary = requests::quote($book, (int)$teacher->id, media::plan($book, $context, 'missing', 0, ['voice']));
        $this->assertSame(5 * lmslabs::VOICE_CREDITS, $summary['credits']);
    }

    public function test_import_creates_pages_only_after_the_charge_is_confirmed(): void {
        global $DB;
        [$book, $context, $teacher] = $this->book(0);
        $parsed = [
            'book_title' => '', 'industry' => '', 'subindustry' => '', 'style_guide' => '', 'characters' => [],
            'cover_image_prompt' => '',
            'pages' => [[
                'title' => 'One', 'concept' => 'C.', 'action' => 'A.', 'outcome' => 'O.', 'image_prompt' => 'Scene.',
                'alt_text' => 'Alt', 'summary' => 'S.', 'tf_statement' => 'T.', 'tf_answer' => true,
            ]],
        ];
        $plan = ['operation' => requests::IMPORT,
            'body' => ['pageCount' => 1, 'titles' => ['One'], 'maxCredits' => lmslabs::PAGE_CREDITS]];
        $summary = requests::quote($book, (int)$teacher->id, [$plan], json_encode($parsed));
        $this->assertSame(lmslabs::PAGE_CREDITS, $summary['credits']);

        // Refused (not enough credits): nothing is created.
        $this->answers['/pages/import'] = [[402, [], '{"error":{"code":"insufficient_credits"}}']];
        $rows = requests::confirm($book, $summary['batch'], (int)$teacher->id, $summary['credits']);
        $this->assertSame('failed', $rows[0]->status);
        $this->assertSame(0, $DB->count_records('visuallearning_pages', ['visuallearningid' => $book->id]));
        // Settlement identifies entitled slides, but never uploads the teaching content.
        $sent = json_decode($this->posts('/pages/import')[0][2], true);
        $this->assertSame(lmslabs::PAGE_CREDITS, $sent['maxCredits']);
        $this->assertCount(1, $sent['slides']);
        $this->assertSame('One', $sent['slides'][0]['title']);
        $this->assertSame('none', $sent['slides'][0]['questionType']);
        $this->assertMatchesRegularExpression('/^[a-f0-9-]{36}$/', $sent['slides'][0]['slideId']);
        $this->assertArrayNotHasKey('pages', $sent);

        // Confirmed: the pages are created.
        $summary = requests::quote($book, (int)$teacher->id, [$plan], json_encode($parsed));
        $this->answers['/pages/import'] = [[200, [], '{"creditsCharged":2,"creditsBalance":40}']];
        $rows = requests::confirm($book, $summary['batch'], (int)$teacher->id, $summary['credits']);
        $this->assertSame('completed', $rows[0]->status);
        $this->assertEquals(2, $rows[0]->charged);
        $this->assertSame(1, $DB->count_records('visuallearning_pages', ['visuallearningid' => $book->id]));
    }

    public function test_written_pages_are_imported_when_delivered(): void {
        global $DB;
        [$book, $context, $teacher] = $this->book(0, ['maxpages' => 4]);
        $summary = requests::quote($book, (int)$teacher->id, [['operation' => requests::WRITE]]);
        $this->assertSame(4 * lmslabs::PAGE_CREDITS, $summary['credits']);
        $draft = ['pages' => [['title' => 'One', 'concept' => 'C.', 'action' => 'A.', 'outcome' => 'O.',
            'image_prompt' => 'Scene.', 'summary' => 'S.', 'tf_statement' => 'T.', 'tf_answer' => false]]];
        $this->answers['/pages/draft'] = [[200, [], json_encode(['draft' => $draft, 'creditsCharged' => 2])]];
        requests::confirm($book, $summary['batch'], (int)$teacher->id, $summary['credits']);
        $this->run_tasks();
        $sent = json_decode($this->posts('/pages/draft')[0][2], true);
        $this->assertSame(4, $sent['maxPages']);
        $this->assertSame(4 * lmslabs::PAGE_CREDITS, $sent['maxCredits']);
        $this->assertSame('Australia', $sent['culture']);
        $this->assertSame(1, $DB->count_records('visuallearning_pages', ['visuallearningid' => $book->id]));
        $this->assertSame('completed', $DB->get_field('visuallearning_aireq', 'status', ['operation' => 'write']));
    }

    public function test_image_prompt_fits_the_route(): void {
        [$book] = $this->book(1);
        $book->styleguide = str_repeat('Soft gouache textures. ', 60);
        $book->characters = json_encode([['name' => 'Maya', 'description' => str_repeat('tall, ', 80)],
            ['name' => 'Tom', 'description' => 'short']]);
        $pages = visuallearning_get_pages($book->id);
        $page = reset($pages);
        $page->imageprompt = 'Maya greets a guest. ' . str_repeat('Warm light. ', 100);
        $prompt = media::image_prompt($book, $page);
        $this->assertLessThanOrEqual(lmslabs::MAX_PROMPT, \core_text::strlen($prompt));
        // The rules are never cut.
        $this->assertStringContainsString(\mod_visuallearning\local\prompt_builder::IMAGE_GUARDRAILS, $prompt);
        $this->assertStringNotContainsString('Tom - short', $prompt);
    }

    public function test_learner_page_never_calls_lms_labs(): void {
        global $USER;
        [$book, $context] = $this->book(2);
        $cm = get_coursemodule_from_instance('visuallearning', $book->id);
        $this->calls = [];
        \mod_visuallearning\local\book_data::player($book, $cm, $context, (int)$USER->id);
        $this->assertSame([], $this->calls);
    }

    public function test_only_editing_teachers_may_spend_credits(): void {
        $course = $this->getDataGenerator()->create_course();
        $book = $this->getDataGenerator()->create_module('visuallearning', ['course' => $course->id]);
        $context = \context_module::instance($book->cmid);
        $editor = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'teacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->assertTrue(has_capability('mod/visuallearning:useai', $context, $editor));
        $this->assertFalse(has_capability('mod/visuallearning:useai', $context, $teacher));
        $this->assertFalse(has_capability('mod/visuallearning:useai', $context, $student));
        $this->assertFalse(has_capability('mod/visuallearning:manage', $context, $student));
    }

    /**
     * A page with a picture-pick question (four options with picture prompts) and feedback.
     *
     * @param int $bookid
     * @return \stdClass
     */
    protected function pick_page(int $bookid): \stdClass {
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_visuallearning');
        $options = [];
        foreach (['Squeezes past', 'Calls "Behind!"', 'Waits by the door', 'Taps their arm'] as $i => $label) {
            $options[] = ['label' => $label, 'image_prompt' => 'A cook who ' . strtolower($label) . '.', 'alt_text' => $label];
        }
        return $gen->create_page($bookid, ['qtype' => 'pick', 'tfstatement' => 'Which is the safe way to pass behind a chef?',
            'qdata' => json_encode(['options' => $options, 'correct' => 1]), 'feedback' => 'Calling out stops collisions.']);
    }

    public function test_option_pictures_are_square_priced_and_saved(): void {
        global $DB;
        $this->caps['images']['aspectRatios'] = ['4:3', '1:1'];
        [$book, $context, $teacher] = $this->book(0);
        $page = $this->pick_page($book->id);
        $plans = media::plan($book, $context, 'missing', 0, ['image', 'option', 'voice']);
        // 1 page picture, 4 square option pictures, and 6 clips: three cards, recap, question and feedback.
        $this->assertCount(11, $plans);
        $this->assertSame(
            ['concept', 'action', 'outcome', 'summary', 'question', 'feedback'],
            array_values(array_column(array_filter($plans, fn($p) => $p['operation'] === 'voice'), 'part'))
        );
        $summary = requests::quote($book, (int)$teacher->id, $plans);
        $this->assertNull($summary['hold']);
        $this->assertSame(4, $summary['items']['option']);
        $this->assertSame(5 * lmslabs::IMAGE_CREDITS + 6 * lmslabs::VOICE_CREDITS, $summary['credits']);

        $this->answers['/images'] = array_fill(0, 5, [200, ['content-type' => 'image/png', 'x-credits-charged' => '2'],
            self::PNG]);
        $this->answers['/speech/tts'] = array_fill(0, 6, [200, ['content-type' => 'audio/mpeg', 'x-credits-charged' => '2'],
            self::MP3]);
        requests::confirm($book, $summary['batch'], (int)$teacher->id, $summary['credits']);
        $this->run_tasks();
        $posts = $this->posts('/images');
        $this->assertCount(5, $posts);
        $bodies = array_map(fn($c) => json_decode($c[2], true), $posts);
        $square = array_values(array_filter($bodies, fn($b) => $b['aspectRatio'] === '1:1'));
        $this->assertCount(4, $square);
        foreach ($square as $body) {
            $this->assertSame('option', $body['purpose']);
            $this->assertSame(lmslabs::IMAGE_CREDITS, $body['maxCredits']);
        }
        $this->assertCount(1, array_filter($bodies, fn($b) => $b['aspectRatio'] === '4:3' && $b['purpose'] === 'page'));
        for ($i = 0; $i < 4; $i++) {
            $this->assertNotNull(media::option_file($context, (int)$page->id, $i));
        }
        $this->assertEquals(VISUALLEARNING_MEDIA_READY, $DB->get_field('visuallearning_pages', 'imagestatus', ['id' => $page->id]));
        $this->assertSame([], media::plan($book, $context, 'missing', 0, ['image', 'option', 'voice']));

        // Editing one option's picture prompt needs that one picture again, nothing else.
        $q = json_decode($DB->get_field('visuallearning_pages', 'qdata', ['id' => $page->id]), true);
        $q['options'][2]['image_prompt'] = 'A cook waiting patiently.';
        $DB->set_field('visuallearning_pages', 'qdata', json_encode($q), ['id' => $page->id]);
        $again = media::plan($book, $context, 'missing', 0, ['image', 'option', 'voice']);
        $this->assertCount(1, $again);
        $this->assertSame(['option', '2'], [$again[0]['operation'], $again[0]['part']]);
    }

    public function test_option_pictures_wait_for_square_pictures_and_never_block_the_rest(): void {
        [$book, $context, $teacher] = $this->book(0);
        $this->pick_page($book->id);
        $plans = media::plan($book, $context, 'missing', 0, ['image', 'option', 'voice']);
        $summary = requests::quote($book, (int)$teacher->id, $plans);
        $this->assertNull($summary['hold']);
        $this->assertSame(4, $summary['dropped']);
        $this->assertSame('nosquare', $summary['droppedhold']);
        $this->assertArrayNotHasKey('option', $summary['items']);
        $this->assertSame(lmslabs::IMAGE_CREDITS + 6 * lmslabs::VOICE_CREDITS, $summary['credits']);
        // Asking only for option pictures says why nothing can be made.
        $only = requests::quote($book, (int)$teacher->id, media::plan($book, $context, 'all', 0, ['option']));
        $this->assertSame('nosquare', $only['hold']);
    }

    public function test_text_card_options_need_no_pictures(): void {
        $this->caps['images']['aspectRatios'] = ['4:3', '1:1'];
        [$book, $context] = $this->book(0, ['pickimages' => 0]);
        $this->pick_page($book->id);
        $this->assertSame([], media::plan($book, $context, 'missing', 0, ['option']));
        $this->assertCount(1, media::plan($book, $context, 'missing', 0, ['image']));
    }

    public function test_write_asks_only_for_question_types_both_sides_allow(): void {
        [$book] = $this->book(0, ['questiontypes' => 'truefalse,pick,sort', 'sourcecontent' => 'Teamwork content.']);
        $this->assertSame(['truefalse'], requests::write_body($book)['questionTypes']);
        $this->caps['pages']['questionTypes'] = ['truefalse', 'pick', 'match', 'sort'];
        \cache::make('mod_visuallearning', 'capabilities')->purge();
        $body = requests::write_body($book);
        $this->assertSame(['truefalse', 'pick', 'sort'], $body['questionTypes']);
        $this->assertArrayNotHasKey('trueFalse', $body);
        $this->assertSame((int)$book->maxpages * lmslabs::PAGE_CREDITS, $body['maxCredits']);
    }

    /**
     * A purchased slide in a lesson with one picture per card, and its three card picture prompts.
     *
     * @return array [lesson, context, teacher, page]
     */
    protected function card_fixture(): array {
        global $DB;
        [$book, $context, $teacher, $page] = $this->bundle_fixture();
        $DB->set_field('visuallearning', 'picturemode', 'sequence', ['id' => $book->id]);
        $DB->set_field('visuallearning_pages', 'cardprompts', json_encode(['A bench with a coil.',
            'Same picture, same viewpoint and layout. Add a battery.',
            'Same picture, same viewpoint and layout. Add glowing field lines.']), ['id' => $page->id]);
        return [$DB->get_record('visuallearning', ['id' => $book->id]), $context, $teacher,
            $DB->get_record('visuallearning_pages', ['id' => $page->id])];
    }

    public function test_card_pictures_are_priced_chained_and_each_edits_the_one_before(): void {
        $this->caps['images']['pictureModes'] = ['single', 'sequence'];
        $this->caps['images']['cardCredits'] = lmslabs::CARD_CREDITS;
        [$book, $context, $teacher, $page] = $this->card_fixture();
        $plans = media::plan($book, $context, 'missing', (int)$page->id, ['card']);
        $this->assertSame(['1', '2', '3'], array_column($plans, 'part'));
        $summary = requests::quote($book, (int)$teacher->id, $plans);
        $this->assertNull($summary['hold']);
        $this->assertSame(3 * lmslabs::CARD_CREDITS, $summary['credits']);

        $bytes = ["\x89PNG\r\n\x1a\nfirst", "\x89PNG\r\n\x1a\nsecond", "\x89PNG\r\n\x1a\nthird"];
        $this->answers['/images'] = array_map(fn($b) => [200, ['content-type' => 'image/png', 'x-credits-charged' => '2',
            'x-delivery-id' => \core\uuid::generate()], $b], $bytes);
        requests::confirm($book, $summary['batch'], (int)$teacher->id, $summary['credits']);
        $this->run_tasks();

        $posts = $this->posts('/images');
        $this->assertCount(3, $posts);
        $sent = array_map(fn($c) => json_decode($c[2], true), $posts);
        foreach ($sent as $n => $body) {
            $this->assertSame('card' . ($n + 1), $body['slot']);
            $this->assertSame($page->bundleid, $body['bundleId']);
            $this->assertSame(lmslabs::CARD_CREDITS, $body['maxCredits']);
            $this->assertSame([], preg_grep('/^_/', array_keys($body)), 'local keys are never sent');
        }
        // Card 1 is a new picture; card 2 edits card 1's picture, and card 3 edits card 2's.
        $this->assertArrayNotHasKey('referenceImage', $sent[0]);
        $this->assertSame(base64_encode($bytes[0]), $sent[1]['referenceImage']);
        $this->assertSame('image/png', $sent[1]['referenceMime']);
        $this->assertSame(base64_encode($bytes[1]), $sent[2]['referenceImage']);
        $this->assertStringStartsWith('EDIT THE ATTACHED PICTURE.', $sent[1]['prompt']);

        $chain = media::card_chain($context, $book, $page);
        foreach ($chain as $n => $link) {
            $this->assertSame($bytes[$n - 1], $link['file']->get_content());
        }
        $this->assertSame([], media::plan($book, $context, 'missing', (int)$page->id, ['card']));
        global $DB;
        $saved = json_decode($DB->get_field('visuallearning_pages', 'bundledeliveries', ['id' => $page->id]), true);
        $this->assertArrayHasKey('card3', $saved);
    }

    public function test_card_picture_waits_for_the_one_it_edits_and_is_cancelled_if_it_never_comes(): void {
        global $DB;
        $this->caps['images']['pictureModes'] = ['single', 'sequence'];
        $this->caps['images']['cardCredits'] = lmslabs::CARD_CREDITS;
        [$book, $context, $teacher, $page] = $this->card_fixture();
        $summary = requests::quote($book, (int)$teacher->id, media::plan($book, $context, 'missing', (int)$page->id, ['card']));
        // Card 1 fails: nothing is charged and cards 2 and 3 are never sent.
        $this->answers['/images'] = [[502, ['content-type' => 'application/json'],
            json_encode(['error' => ['code' => 'provider_failed']])]];
        requests::confirm($book, $summary['batch'], (int)$teacher->id, $summary['credits']);
        $this->run_tasks();
        $this->assertCount(1, $this->posts('/images'));
        $rows = $DB->get_records('visuallearning_aireq', ['visuallearningid' => $book->id, 'operation' => 'card'], 'part');
        $bypart = array_column(array_values($rows), null, 'part');
        $this->assertSame('failed', $bypart['1']->status);
        $this->assertSame('cancelled', $bypart['2']->status);
        $this->assertSame('needsprevious', $bypart['2']->errorcode);
        $this->assertSame('0', (string)$bypart['2']->tries);
        $this->assertSame('cancelled', $bypart['3']->status);
    }

    public function test_card_pictures_wait_for_lms_labs_and_never_block_the_rest(): void {
        [$book, $context, $teacher, $page] = $this->card_fixture();
        $this->bundleslots = ['picture' => ['state' => 'awaiting-retry']];
        $plans = media::plan($book, $context, 'missing', (int)$page->id, ['image', 'card']);
        $summary = requests::quote($book, (int)$teacher->id, $plans);
        $this->assertNull($summary['hold']);
        $this->assertSame(3, $summary['droppedcards']);
        $this->assertSame('nosequence', $summary['droppedcardshold']);
        $this->assertArrayNotHasKey('card', $summary['items']);
        // Asking only for card pictures says why nothing can be made.
        $only = requests::quote($book, (int)$teacher->id, media::plan($book, $context, 'all', (int)$page->id, ['card']));
        $this->assertSame('nosequence', $only['hold']);
    }

    public function test_written_pages_ask_for_card_prompts_or_diagrams_only_when_lms_labs_writes_them(): void {
        global $DB;
        [$book] = $this->book(0, ['sourcecontent' => 'Inductors oppose changes in current.']);
        $this->assertArrayNotHasKey('pictureMode', requests::write_body($book));
        $book->picturemode = 'animated';
        $this->assertArrayNotHasKey('pictureMode', requests::write_body($book));
        $this->caps['pages']['draftPictureModes'] = ['single', 'sequence', 'animated'];
        \cache::make('mod_visuallearning', 'capabilities')->purge();
        $this->assertSame('animated', requests::write_body($book)['pictureMode']);
        $book->picturemode = 'single';
        $this->assertArrayNotHasKey('pictureMode', requests::write_body($book));
    }

    public function test_scientific_pictures_say_what_they_are_for_once_lms_labs_routes_them(): void {
        global $DB;
        [$book, $context, $teacher] = $this->book(1, ['teachingapproach' => 'science']);
        $pages = visuallearning_get_pages($book->id);
        $page = reset($pages);
        $plan = [['operation' => requests::IMAGE, 'targetid' => $page->id, 'prompt' => 'A coil and its field.']];
        $quote = requests::quote($book, (int)$teacher->id, $plan);
        $rows = requests::quoted($book->id, $quote['batch']);
        $this->assertArrayNotHasKey('teachingApproach', json_decode(reset($rows)->body, true));
        requests::cancel_quote($book->id, $quote['batch']);

        $this->caps['images']['imageFields'] = ['teachingApproach'];
        \cache::make('mod_visuallearning', 'capabilities')->purge();
        $quote = requests::quote($book, (int)$teacher->id, $plan);
        $rows = requests::quoted($book->id, $quote['batch']);
        $this->assertSame('science', json_decode(reset($rows)->body, true)['teachingApproach']);
        requests::cancel_quote($book->id, $quote['batch']);

        // Workplace stories never send it.
        $DB->set_field('visuallearning', 'teachingapproach', 'story', ['id' => $book->id]);
        $book = $DB->get_record('visuallearning', ['id' => $book->id]);
        $quote = requests::quote($book, (int)$teacher->id, $plan);
        $rows = requests::quoted($book->id, $quote['batch']);
        $this->assertArrayNotHasKey('teachingApproach', json_decode(reset($rows)->body, true));
    }

    public function test_written_pages_are_pitched_at_the_learner_level(): void {
        [$book] = $this->book(0, ['sourcecontent' => 'How an engine works.', 'learnerlevel' => 'primary']);
        $body = requests::write_body($book);
        $this->assertArrayNotHasKey('learnerLevel', $body);
        $this->assertStringContainsString('LEARNER LEVEL: primary school children', $body['imageStyle']);
        $this->caps['pages']['learnerLevels'] = ['primary', 'secondary', 'adult', 'advanced'];
        \cache::make('mod_visuallearning', 'capabilities')->purge();
        $body = requests::write_body($book);
        $this->assertSame('primary', $body['learnerLevel']);
        $this->assertStringNotContainsString('LEARNER LEVEL', $body['imageStyle']);
        // Adult learners send exactly what they always did.
        $book->learnerlevel = 'adult';
        $this->assertArrayNotHasKey('learnerLevel', requests::write_body($book));
    }
}
