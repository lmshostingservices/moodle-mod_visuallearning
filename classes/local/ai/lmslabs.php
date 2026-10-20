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

namespace mod_visuallearning\local\ai;

use mod_visuallearning\local\credentials;

/**
 * The LMS Labs connection of AI Visual Learning. LMS Labs holds every AI provider key; this site holds only its
 * LMS Labs Site ID and API key (Central Config first), which are sent in request headers from PHP and never reach
 * a browser, a URL or a log.
 *
 * Routes (proposed contract, see docs/LMSLABS_CONTRACT.md; none is assumed to exist until LMS Labs publishes it):
 *   GET  /api/moodle/ai-visuallearning/pages/capabilities    free: {tariff: {page}, questionTypes}
 *   POST /api/moodle/ai-visuallearning/pages/draft           pages written by LMS Labs AI, charged per page delivered
 *   POST /api/moodle/ai-visuallearning/pages/import          pages written with the teacher's own AI assistant, same price
 *   GET  /api/moodle/ai-visuallearning/images/capabilities   free: {tariff: {image}, model, aspectRatios}
 *   POST /api/moodle/ai-visuallearning/images                one Nano Banana picture (4:3 page or 1:1 option), raw bytes
 *   GET  /api/moodle/ai-visuallearning/speech/capabilities   free: Chirp 3 HD voice catalogue and tariff
 *   POST /api/moodle/ai-visuallearning/speech/quote          free: the price of one clip (free remakes)
 *   POST /api/moodle/ai-visuallearning/speech/tts            one MP3 clip
 *
 * Nothing here is retried automatically, and redirects are never followed (the key headers would go with them).
 *
 * @package    mod_visuallearning
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class lmslabs {
    /** @var string Fixed LMS Labs host. */
    public const BASE_URL = 'https://lms-labs.com';

    /** @var string Route prefix of this product. */
    public const PREFIX = '/api/moodle/ai-visuallearning';

    /** @var string Pages written by LMS Labs AI. */
    public const DRAFT_ROUTE = self::PREFIX . '/pages/draft';

    /** @var string Pages written with the teacher's own AI assistant (charged like a draft, per page). */
    public const IMPORT_ROUTE = self::PREFIX . '/pages/import';

    /** @var string One illustration. */
    public const IMAGE_ROUTE = self::PREFIX . '/images';

    /** @var string One voiceover clip. */
    public const VOICE_ROUTE = self::PREFIX . '/speech/tts';

    /** @var string Free price of one clip. */
    public const VOICE_QUOTE_ROUTE = self::PREFIX . '/speech/quote';

    /** @var string[] Free capability routes, by kind. */
    public const CAPABILITY_ROUTES = [
        'pages' => self::PREFIX . '/pages/capabilities',
        'images' => self::PREFIX . '/images/capabilities',
        'speech' => self::PREFIX . '/speech/capabilities',
    ];

    /** @var int Proposed credits per page (written by LMS Labs AI or imported); needs owner approval. */
    public const PAGE_CREDITS = 10;

    /** @var int Proposed credits per illustration (Nano Banana through LMS Labs); needs owner approval. */
    public const IMAGE_CREDITS = 2;

    /** @var int Credits per voiceover clip, as AI Soft Skills; needs owner approval for this product. */
    public const VOICE_CREDITS = 2;

    /** @var int Proposed price of one extra card picture (one picture per card); needs owner approval. */
    public const CARD_CREDITS = 2;

    /** @var int Longest picture prompt, in Unicode code points. */
    public const MAX_PROMPT = 2000;

    /** @var int Longest clip, in characters (a card is 1-2 short sentences). */
    public const MAX_CLIP = 300;

    /** @var int Longest teaching content sent for a draft, in characters. */
    public const MAX_CONTENT = 60000;

    /** @var int Largest picture accepted, in bytes. */
    public const MAX_IMAGE_BYTES = 10485760;

    /** @var int Seconds to wait for an illustration. */
    public const IMAGE_TIMEOUT = 170;

    /** @var int Seconds to wait for a book of drafted pages. */
    public const DRAFT_TIMEOUT = 170;

    /** @var int Seconds the published capabilities are kept. */
    public const CAPABILITIES_TTL = 3600;

    /** @var int Seconds a failed capabilities read is remembered. */
    public const CAPABILITIES_RETRY = 300;

    /** @var callable|null Test seam for GET: fn(string $url, array $headers): array [status, body]. */
    public static $transport = null;

    /** @var callable|null Test seam for POST: fn(string $url, array $headers, string $body): array [status, headers, body]. */
    public static $posttransport = null;

    /** @var bool Whether capabilities may be read from LMS Labs now (false while building a learner's page). */
    public static $fetch = true;

    /**
     * Whether a complete credential pair is available.
     *
     * @return bool
     */
    public static function is_connected(): bool {
        return credentials::find() !== null;
    }

    /**
     * Request headers with the site's credentials.
     *
     * @param array $credentials
     * @param string $accept
     * @return string[]
     */
    protected static function headers(array $credentials, string $accept): array {
        return [
            'Accept: ' . $accept,
            'X-Site-ID: ' . $credentials['siteid'],
            'X-API-Key: ' . $credentials['apikey'],
            'X-VL-Contract: bundle-1',
        ];
    }

    /**
     * Sends one POST with the given Idempotency-Key. The caller stores the key and the exact body first.
     *
     * @param string $route one of the POST routes
     * @param string $body exact JSON body
     * @param string $key Idempotency-Key
     * @param string $accept Accept header
     * @param int $timeout seconds
     * @return array [status (0: no answer), lower-case headers, body]
     */
    public static function post(string $route, string $body, string $key, string $accept, int $timeout = 100): array {
        global $CFG;
        $routes = [self::DRAFT_ROUTE, self::IMPORT_ROUTE, self::IMAGE_ROUTE, self::VOICE_ROUTE, self::VOICE_QUOTE_ROUTE];
        if (!in_array($route, $routes, true) && !preg_match(
            '~^/api/moodle/ai-visuallearning/v2/(bundles(?:/[a-f0-9-]{36}(?:/(?:draft|import|manual)|/slides/[a-f0-9-]{36}/slots/[a-z0-9:]+/lost)?)?|images|speech/(?:tts|quote))$~',
            $route
        )) {
            throw new \coding_exception('Unknown LMS Labs route');
        }
        $credentials = credentials::find();
        if ($credentials === null) {
            throw new \moodle_exception('lmslabscredentialsmissing', 'mod_visuallearning');
        }
        $url = self::BASE_URL . $route;
        $headers = array_merge(
            ['Content-Type: application/json'],
            self::headers($credentials, $accept),
            ['Idempotency-Key: ' . $key]
        );
        if (self::$posttransport) {
            [$status, $responseheaders, $response] = (self::$posttransport)($url, $headers, $body);
            return [(int)$status, array_change_key_case((array)$responseheaders, CASE_LOWER), (string)$response];
        }
        require_once($CFG->libdir . '/filelib.php');
        $curl = new \curl(['ignoresecurity' => false]);
        $curl->setHeader($headers);
        $response = $curl->post(
            $url,
            $body,
            ['CURLOPT_CONNECTTIMEOUT' => 10, 'CURLOPT_TIMEOUT' => $timeout, 'CURLOPT_FOLLOWLOCATION' => false]
        );
        if ($curl->get_errno()) {
            return [0, [], ''];
        }
        $status = (int)($curl->get_info()['http_code'] ?? 0);
        $responseheaders = [];
        foreach ((array)$curl->getResponse() as $name => $value) {
            $responseheaders[strtolower((string)$name)] = is_array($value) ? (string)end($value) : (string)$value;
        }
        return [$status, $responseheaders, (string)$response];
    }

    /**
     * Sends one free GET.
     *
     * @param string $path route (and query) on the LMS Labs host
     * @param int $timeout seconds
     * @param bool $keyonly send only the API key (the credits route)
     * @return array [status (0: no answer), decoded JSON or null]
     */
    public static function get(string $path, int $timeout = 10, bool $keyonly = false): array {
        global $CFG;
        $credentials = credentials::find();
        if ($credentials === null) {
            return [0, null];
        }
        $url = self::BASE_URL . $path;
        $headers = $keyonly ? ['Accept: application/json', 'X-API-Key: ' . $credentials['apikey']]
            : self::headers($credentials, 'application/json');
        if (self::$transport) {
            [$status, $body] = (self::$transport)($url, $headers);
        } else {
            require_once($CFG->libdir . '/filelib.php');
            $curl = new \curl();
            $curl->setHeader($headers);
            $body = $curl->get(
                $url,
                [],
                ['CURLOPT_CONNECTTIMEOUT' => 5, 'CURLOPT_TIMEOUT' => $timeout, 'CURLOPT_FOLLOWLOCATION' => false]
            );
            if ($curl->get_errno()) {
                return [0, null];
            }
            $status = (int)($curl->get_info()['http_code'] ?? 0);
        }
        $data = json_decode((string)$body, true);
        return [(int)$status, is_array($data) ? $data : null];
    }

    /**
     * What LMS Labs publishes for one kind of operation (free), kept for an hour (a failure for five minutes).
     *
     * The last good answer is also kept in the plugin settings, so no learner's page ever waits for LMS Labs.
     *
     * @param string $kind pages, images or speech
     * @param bool $fresh read it again now
     * @return array the published data ([] when LMS Labs has published nothing yet)
     */
    public static function capabilities(string $kind, bool $fresh = false): array {
        if (!isset(self::CAPABILITY_ROUTES[$kind])) {
            throw new \coding_exception('Unknown capabilities kind');
        }
        $credentials = credentials::find();
        $cache = \cache::make('mod_visuallearning', 'capabilities');
        $key = 'bundle1_' . $kind . '_' . sha1((string)($credentials['siteid'] ?? '') . '|' . ($credentials['apikey'] ?? ''));
        $cached = $cache->get($key);
        $kept = json_decode((string)get_config('mod_visuallearning', 'caps_' . $kind), true);
        $kept = is_array($kept) ? $kept : [];
        if (!self::$fetch || $credentials === null) {
            return is_array($cached) && $cached['data'] ? $cached['data'] : $kept;
        }
        if (!$fresh && is_array($cached)) {
            $ttl = $cached['data'] ? self::CAPABILITIES_TTL : self::CAPABILITIES_RETRY;
            if (($cached['time'] ?? 0) > time() - $ttl) {
                return $cached['data'] ?: [];
            }
        }
        [$status, $data] = self::get(self::PREFIX . '/v2/capabilities', 10);
        if ($status === 200 && ($data['contract'] ?? '') === 'bundle-1') {
            $data['tariff'] = [
                'page' => $data['bundle']['slideCredits'] ?? null,
                'image' => $data['remakes']['pictureCredits'] ?? null,
                'card' => $data['cardPictures']['credits'] ?? null,
                'tts' => $data['remakes']['voice']['credits'] ?? null,
                'clipRefSupported' => true,
                'maxCreditsRequired' => true,
                'ttsRemakeLimit' => 10,
                'ttsRemakeWindowDays' => 30,
            ];
        } else {
            $data = [];
        }
        $data = $status === 200 && is_array($data) ? $data : [];
        $cache->set($key, ['time' => time(), 'data' => $data]);
        if ($data && $data !== $kept) {
            set_config('caps_' . $kind, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'mod_visuallearning');
        }
        // A failed read never falls back to an old price: the price is unknown until LMS Labs answers again.
        return $data;
    }

    /**
     * The price LMS Labs publishes for one unit of an operation, or null when it publishes none.
     *
     * @param string $operation page, import (same price as page), image, option (same price as image) or voice
     * @return int|null
     */
    public static function published_price(string $operation): ?int {
        $map = ['page' => ['pages', 'page'], 'import' => ['pages', 'page'], 'image' => ['images', 'image'],
            'option' => ['images', 'image'], 'voice' => ['speech', 'tts'], 'card' => ['images', 'card']];
        [$kind, $field] = $map[$operation];
        $price = self::capabilities($kind)['tariff'][$field] ?? null;
        return is_int($price) && $price >= 0 ? $price : null;
    }

    /**
     * The approved (here: proposed) price of one unit.
     *
     * @param string $operation page, import (same price as page), image, option (same price as image) or voice
     * @return int
     */
    public static function approved_price(string $operation): int {
        $prices = ['page' => self::PAGE_CREDITS, 'import' => self::PAGE_CREDITS, 'image' => self::IMAGE_CREDITS,
            'option' => self::IMAGE_CREDITS, 'voice' => self::VOICE_CREDITS, 'card' => self::CARD_CREDITS];
        return $prices[$operation];
    }

    /**
     * Why nothing of this kind may be bought now, or null when it may.
     *
     * New paid generation fails closed: no credentials, a site that is not unlocked, a switched-off feature, an
     * unpublished price or a price other than the approved one all stop it before anything is sent.
     *
     * @param string $operation page (LMS Labs AI writing), import (pages from the teacher's own assistant), image, option
     *     (a square picture-pick option) or voice
     * @return string|null string identifier suffix (hold_...), or null
     */
    public static function hold(string $operation): ?string {
        if (!self::is_connected()) {
            return 'nocredentials';
        }
        if (!\mod_visuallearning\local\unlock::active()) {
            return 'notactivated';
        }
        $setting = ['page' => 'aiwrite', 'image' => 'aiimages', 'option' => 'aiimages', 'voice' => 'aivoice',
            'import' => '', 'card' => 'aiimages'][$operation];
        if ($setting !== '' && !get_config('mod_visuallearning', $setting)) {
            return 'off';
        }
        if ($operation === 'card' && !in_array('sequence', self::picture_modes(), true)) {
            // Card pictures are made only once LMS Labs says it makes them (they edit the picture before).
            return 'nosequence';
        }
        if ($operation === 'option' && !in_array(\mod_visuallearning\local\media::OPTION_ASPECT, self::aspect_ratios(), true)) {
            // Square option pictures are made only once LMS Labs says it makes them.
            return 'nosquare';
        }
        // Pages imported from the teacher's own AI assistant cost the same as pages LMS Labs writes.
        $operation = $operation === 'import' ? 'page' : $operation;
        $published = self::published_price($operation);
        if ($published === null) {
            return 'priceunknown';
        }
        return $published === self::approved_price($operation) ? null : 'pricechanged';
    }

    /**
     * The teaching approaches LMS Labs AI writes pages for (its v2 capabilities list "teachingApproaches"). Only the
     * workplace story is assumed when it says nothing.
     *
     * @return string[]
     */
    public static function teaching_approaches(): array {
        $list = self::capabilities('pages')['teachingApproaches'] ?? ['story'];
        return is_array($list) ? array_values(array_filter($list, 'is_string')) : ['story'];
    }

    /**
     * The visual styles LMS Labs accepts by name (its v2 capabilities list "visualStyles"). Only the illustration
     * style is assumed when it says nothing; every style's look is also written into the picture prompt itself.
     *
     * @return string[]
     */
    public static function visual_styles(): array {
        $list = self::capabilities('pages')['visualStyles'] ?? ['illustration'];
        return is_array($list) ? array_values(array_filter($list, 'is_string')) : ['illustration'];
    }

    /**
     * Optional fields LMS Labs accepts in picture requests (its v2 capabilities list "imageFields"). None are sent
     * until it lists them, so its strict schema never sees an unknown field.
     *
     * @return string[]
     */
    public static function image_fields(): array {
        $list = self::capabilities('images')['imageFields'] ?? [];
        return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
    }

    /**
     * The picture modes LMS Labs AI writes pages for (its v2 capabilities list "draftPictureModes"): it then adds the
     * per-card picture prompts or the diagram specs to the pages it writes. Only one picture per page is assumed when
     * it says nothing.
     *
     * @return string[]
     */
    public static function draft_picture_modes(): array {
        $list = self::capabilities('pages')['draftPictureModes'] ?? ['single'];
        return is_array($list) ? array_values(array_filter($list, 'is_string')) : ['single'];
    }

    /**
     * The picture modes LMS Labs makes pictures for (its v2 capabilities list "pictureModes"). Only one picture per
     * page is assumed when it says nothing; animated diagrams are drawn by the plugin and need nothing from LMS Labs.
     *
     * @return string[]
     */
    public static function picture_modes(): array {
        $list = self::capabilities('images')['pictureModes'] ?? ['single'];
        return is_array($list) ? array_values(array_filter($list, 'is_string')) : ['single'];
    }

    /**
     * Whether LMS Labs makes the wrong-answer clip: its bundle lists the "clip:retry" slot.
     *
     * Until it does, the clip is never requested, never priced and never counted as missing.
     *
     * @return bool
     */
    public static function retry_supported(): bool {
        $slots = self::capabilities('speech')['bundle']['slots'] ?? [];
        return is_array($slots) && in_array('clip:retry', $slots, true);
    }

    /**
     * The picture shapes LMS Labs says it makes. Page pictures (4:3) are assumed when it says nothing.
     *
     * @return string[]
     */
    public static function aspect_ratios(): array {
        $ratios = self::capabilities('images')['aspectRatios'] ?? ['4:3'];
        return is_array($ratios) ? array_values(array_filter($ratios, 'is_string')) : ['4:3'];
    }

    /**
     * The question types LMS Labs AI says it writes. Only true or false is assumed when it says nothing.
     *
     * @return string[]
     */
    public static function question_types(): array {
        $types = self::capabilities('pages')['questionTypes'] ?? ['truefalse'];
        $types = is_array($types) ? array_intersect(\mod_visuallearning\local\question::TYPES, $types) : [];
        return $types ? array_values($types) : ['truefalse'];
    }

    /**
     * The free remake rule when LMS Labs supports it for voiceover (clips then carry clipRef and maxCredits).
     *
     * @return array|null {limit, days}, or null when LMS Labs does not say it supports remakes
     */
    public static function remakes(): ?array {
        $tariff = self::capabilities('speech')['tariff'] ?? [];
        if (empty($tariff['clipRefSupported']) || empty($tariff['maxCreditsRequired'])) {
            return null;
        }
        return ['limit' => (int)($tariff['ttsRemakeLimit'] ?? 0), 'days' => (int)($tariff['ttsRemakeWindowDays'] ?? 0)];
    }

    /**
     * The voice catalogue LMS Labs publishes: locales with their exact Chirp 3 HD voice names.
     *
     * @return array [locale => [voice short name => full voice name]]
     */
    public static function voice_catalogue(): array {
        $out = [];
        foreach ((array)(self::capabilities('speech')['locales'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $locale = (string)($entry['locale'] ?? '');
            $code = (string)($entry['ttsLanguageCode'] ?? $locale);
            if (!preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]{2,4})+$/', $locale)) {
                continue;
            }
            foreach ((array)($entry['voices'] ?? []) as $voice) {
                $name = is_array($voice) ? (string)($voice['name'] ?? '') : '';
                if (str_starts_with($name, $code . '-Chirp3-HD-') && preg_match('/^[A-Za-z0-9-]+-Chirp3-HD-[A-Za-z]+$/', $name)) {
                    $out[$locale][substr($name, strlen($code . '-Chirp3-HD-'))] = $name;
                }
            }
        }
        return $out;
    }

    /**
     * The exact voice name LMS Labs offers for a locale and voice, or null.
     *
     * @param string $locale
     * @param string $voice short name, such as Aoede
     * @return string|null
     */
    public static function voice_name(string $locale, string $voice): ?string {
        return self::voice_catalogue()[$locale][$voice] ?? null;
    }

    /**
     * The current price of one clip (free; nothing is made, charged or reserved).
     *
     * @param string $clipref
     * @return int|null 0 or the price per clip, or null when LMS Labs gave no usable price
     */
    public static function voice_quote(string $clipref): ?int {
        try {
            [$status, , $body] = self::post(
                self::VOICE_QUOTE_ROUTE,
                json_encode(['clipRef' => $clipref]),
                \core\uuid::generate(),
                'application/json',
                8
            );
        } catch (\moodle_exception $e) {
            return null;
        }
        $data = json_decode($body, true);
        $credits = is_array($data) ? ($data['credits'] ?? null) : null;
        return $status === 200 && in_array($credits, [0, self::VOICE_CREDITS], true) ? (int)$credits : null;
    }

    /**
     * The site's balance, for information only (it can change before any charge).
     *
     * @return array|null ['unlimited' => bool, 'credits' => int]
     */
    public static function balance(): ?array {
        $credentials = credentials::find();
        if ($credentials === null) {
            return null;
        }
        [$status, $data] = self::get('/api/credits?siteId=' . rawurlencode($credentials['siteid']), 10, true);
        if ($status !== 200 || $data === null) {
            return null;
        }
        if (!empty($data['isUnlimited'])) {
            return ['unlimited' => true, 'credits' => -1];
        }
        if (isset($data['creditsRaw']) && is_numeric($data['creditsRaw'])) {
            return ['unlimited' => false, 'credits' => (int)$data['creditsRaw']];
        }
        return null;
    }
}
