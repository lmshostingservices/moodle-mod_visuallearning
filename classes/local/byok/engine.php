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

namespace mod_visuallearning\local\byok;

/**
 * The LMS Labs AI Visual Learning service, run inside this Moodle site for work on the site's own AI.
 *
 * A port of the service's routes (server/visualLearningBundleRoutes.ts, visualLearningDraftJobs.ts,
 * visualLearningBundleDraft.ts, visualLearningSvgRoutes.ts and visualLearningSvgProvider.ts): the same request
 * checks, the same writer instructions and retries, the same checks of what the AI returns, and the same answers
 * ([status, headers, body], as the plugin reads them from LMS Labs). Nothing is charged: every answer reports 0
 * credits. Bundles, receipts and idempotency exist at LMS Labs to bill and replay paid work, so they have no part
 * here; the plugin's request log still keeps every request and its outcome.
 *
 * The AI calls go through hooks set by {@see \mod_visuallearning\byok}, so tests can answer them.
 *
 * @package    mod_visuallearning
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class engine {
    /** @var callable|null fn(string $system, string $user, int $maxtokens): string, throws provider_error. */
    public static $aicall = null;
    /** @var callable|null fn(string $prompt, string $aspect, ?array $reference [data, mime]): string bytes. */
    public static $image = null;
    /** @var callable|null fn(string $text, string $locale, string $voice, float $rate): string MP3 bytes. */
    public static $tts = null;
    /** @var bool Whether any MPEG Layer III stream is accepted (voices other than Google's 24 kHz MP3). */
    public static $anymp3 = false;

    /** Codes the service reports as refused by the AI provider (502), not as a conflict (409). */
    const PROVIDER_ERRORS = ['PROVIDER_FAILED', 'PROVIDER_RATE_LIMITED', 'PROVIDER_TIMEOUT', 'INVALID_PROVIDER_RESULT',
        'ILLUSTRATED_SCENE_UNAVAILABLE', 'PROVIDER_UNAVAILABLE'];
    /** Codes after which the service tries a draft step once more. */
    const RETRYABLE = ['PROVIDER_FAILED', 'PROVIDER_RATE_LIMITED', 'PROVIDER_TIMEOUT', 'INVALID_PROVIDER_RESULT',
        'ILLUSTRATED_SCENE_UNAVAILABLE'];
    /** Tries of one draft step (draftRecovery.maxStageAttempts). */
    const STAGE_ATTEMPTS = 2;
    /** The writer's answer budget, in tokens. */
    const DRAFT_TOKENS = 24000;
    /** The artist's answer budget, in tokens. */
    const SVG_TOKENS = 16000;
    /** The still picture styles the service names. */
    const IMAGE_STYLES = ['illustration', 'realistic', 'model3d', 'diagram', 'whiteboard'];
    /** The question types the service writes. */
    const QUESTION_TYPES = ['truefalse', 'pick', 'match', 'sort'];
    /** Largest picture, in bytes. */
    const MAX_IMAGE = 10485760;
    /** Largest animated SVG, in bytes. */
    const MAX_SVG = 40000;

    /** @var int Largest svg-2 scene accepted (bytes): the motion tags make scenes about a fifth larger. */
    const MAX_SVG2 = 48000;
    /** Line the writer gets with the plugin's own brief. */
    const WRITE_BRIEF = 'Write the lesson described in the brief. Return only the JSON object.';
    /** Line in front of the failures the writer is asked to fix. */
    const REPAIR_LEAD = "\n\nYOUR PREVIOUS ANSWER FAILED THESE CHECKS (fix only these and return the complete JSON): ";

    /**
     * Write the pages (POST /v2/bundles/:id/draft).
     *
     * The service writes the text as one durable step, tried at most twice; a second try gets the failing fields
     * of the first. Lessons whose pictures are illustrated scenes, and requests without the plugin's own writer
     * brief, are written only at LMS Labs: the plugin never asks for them on the site's own AI.
     *
     * @param mixed $body The request body (decoded JSON).
     * @return array [status, headers, body]
     */
    public static function draft($body): array {
        $input = self::draft_input($body);
        if ($input === null) {
            return self::fail(422, 'INVALID_INPUT');
        }
        $illustrated = ($input['pictureMode'] ?? null) === 'animated'
            && in_array($input['animationMode'] ?? '', ['animated', 'automatic'], true);
        if ($illustrated || !isset($input['writerBrief'])) {
            return self::fail(422, 'INVALID_INPUT');
        }
        $repair = null;
        $code = 'INVALID_PROVIDER_RESULT';
        for ($attempt = 0; $attempt < self::STAGE_ATTEMPTS; $attempt++) {
            try {
                $draft = self::write($input, $repair);
                return [200, ['content-type' => 'application/json'],
                    self::json(['draft' => $draft, 'creditsCharged' => 0])];
            } catch (draft_error $e) {
                $code = 'INVALID_PROVIDER_RESULT';
                $repair = self::utf16_slice(self::json($e->diagnostics), 2000);
            } catch (provider_error $e) {
                $code = $e->error;
            }
            if (!in_array($code, self::RETRYABLE, true)) {
                break;
            }
        }
        return self::fail(in_array($code, self::PROVIDER_ERRORS, true) ? 502 : 409, $code);
    }

    /**
     * One writing of the lesson with the plugin's brief (generateBundleDraft, one attempt).
     *
     * @param array $input
     * @param string|null $repair The failing fields of the try before.
     * @return mixed The checked lesson.
     */
    public static function write(array $input, ?string $repair) {
        $user = self::WRITE_BRIEF . ($repair !== null ? self::REPAIR_LEAD . $repair : '');
        $answer = draft_check::trim((self::$aicall)($input['writerBrief'], $user, self::DRAFT_TOKENS));
        try {
            // The plugin's brief permits one JSON code block: only that outer wrapper is removed.
            $text = preg_match('/^\x60{3}(?:json)?[ \t]*\r?\n([\s\S]*?)\r?\n\x60{3}$/i', $answer, $m) ? $m[1] : $answer;
            try {
                $raw = json_decode($text, false, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new draft_error([['field' => 'result', 'rule' => 'invalid_json']]);
            }
            return draft_check::validate($raw, $input);
        } catch (draft_error $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new draft_error([['field' => 'result', 'rule' => 'invalid_provider_response']]);
        }
    }

    /**
     * Pages imported from the teacher's own AI assistant, or one page made by hand (POST /v2/bundles/:id/import or
     * /manual): no AI is involved; the service only settles the price.
     *
     * @return array
     */
    public static function import(): array {
        return [200, ['content-type' => 'application/json'], self::json(['creditsCharged' => 0])];
    }

    /**
     * One picture (POST /v2/images): a page, cover, answer option or card picture; card 2 and 3 edit the picture of
     * the card before.
     *
     * @param mixed $body
     * @return array
     */
    public static function image($body): array {
        $v = self::object($body);
        $keys = ['prompt', 'style', 'teachingApproach', 'purpose', 'aspectRatio', 'referenceImage', 'referenceMime',
            'maxCredits'];
        if (
            $v === null || array_diff(array_keys($v), $keys)
                || !self::text($v['prompt'] ?? null, 6000, true)
                || !in_array($v['style'] ?? null, self::IMAGE_STYLES, true)
                || (array_key_exists('teachingApproach', $v) && !in_array($v['teachingApproach'], ['science', 'story'], true))
                || (array_key_exists('purpose', $v) && !in_array($v['purpose'], ['page', 'cover', 'option', 'card'], true))
                || !in_array($v['aspectRatio'] ?? null, ['4:3', '1:1'], true)
                || !self::credits($v['maxCredits'] ?? null)
                || (array_key_exists('referenceImage', $v) && !(is_string($v['referenceImage'])
                    && strlen($v['referenceImage']) >= 4 && strlen($v['referenceImage']) <= 2800000
                    && preg_match('~^(?:[A-Za-z0-9+/]{4})*(?:[A-Za-z0-9+/]{2}==|[A-Za-z0-9+/]{3}=)?$~D', $v['referenceImage'])))
                || (array_key_exists('referenceMime', $v) && !in_array($v['referenceMime'], ['image/png', 'image/webp',
                    'image/jpeg'], true))
                || array_key_exists('referenceImage', $v) !== array_key_exists('referenceMime', $v)
                || (array_key_exists('referenceImage', $v) && ($v['purpose'] ?? '') !== 'card')
        ) {
            return self::fail(422, 'INVALID_INPUT');
        }
        if ($v['aspectRatio'] !== (($v['purpose'] ?? '') === 'option' ? '1:1' : '4:3')) {
            return self::fail(422, 'INVALID_INPUT');
        }
        $reference = null;
        if (isset($v['referenceImage'])) {
            $bytes = base64_decode($v['referenceImage'], true);
            $info = $bytes === false ? false : @getimagesizefromstring($bytes);
            if (
                !$info || $info['mime'] !== $v['referenceMime'] || !$info[0] || !$info[1] || $info[0] * $info[1] > 4000000
                    || self::frames($bytes, $info['mime']) !== 1
            ) {
                return self::fail(422, 'INVALID_REFERENCE_IMAGE');
            }
            $reference = ['data' => $bytes, 'mime' => $v['referenceMime']];
        }
        try {
            $raw = (self::$image)($v['prompt'], $v['aspectRatio'], $reference);
        } catch (provider_error $e) {
            return self::fail($e->status, $e->error);
        }
        if (strlen($raw) > self::MAX_IMAGE) {
            return self::fail(502, 'UNUSABLE_IMAGE');
        }
        $info = @getimagesizefromstring($raw);
        $ratio = $v['aspectRatio'] === '1:1' ? 1 : 4 / 3;
        if (
            !$info || !in_array($info['mime'], ['image/png', 'image/webp', 'image/jpeg'], true)
                || $info[0] * $info[1] > 4000000 || $info[0] < 512 || $info[1] < 384
                || abs($info[0] / $info[1] - $ratio) > .03 || self::frames($raw, $info['mime']) !== 1
        ) {
            return self::fail(502, 'UNUSABLE_IMAGE');
        }
        $png = self::png($raw, $info['mime']);
        if ($png === null || strlen($png) > self::MAX_IMAGE) {
            return self::fail(502, 'UNUSABLE_IMAGE');
        }
        return [200, ['content-type' => 'image/png', 'x-credits-charged' => '0'], $png];
    }

    /**
     * One narration clip (POST /v2/speech/tts).
     *
     * @param mixed $body
     * @param array $catalogue The published voices: [locale => [voice names]].
     * @return array
     */
    public static function tts($body, array $catalogue): array {
        $v = self::object($body);
        if (
            $v === null || array_diff(array_keys($v), ['text', 'locale', 'voice', 'speed', 'clipRef', 'maxCredits'])
                || !self::text($v['text'] ?? null, 300, true) || !self::text($v['locale'] ?? null, 32, false)
                || !self::text($v['voice'] ?? null, 120, false)
                || !in_array($v['speed'] ?? null, ['slow', 'normal', 'fast'], true)
                || (array_key_exists('clipRef', $v) && !(is_string($v['clipRef']) && preg_match(
                    '/^[a-f0-9]{64}$/D',
                    $v['clipRef']
                )))
                || !self::credits($v['maxCredits'] ?? null)
        ) {
            return self::fail(422, 'INVALID_INPUT');
        }
        if (!in_array($v['voice'], $catalogue[$v['locale']] ?? [], true)) {
            return self::fail(422, 'INVALID_VOICE');
        }
        $rate = $v['speed'] === 'slow' ? .8 : ($v['speed'] === 'fast' ? 1.2 : 1.0);
        try {
            $bytes = (self::$tts)($v['text'], $v['locale'], $v['voice'], $rate);
        } catch (provider_error $e) {
            return self::fail($e->status, $e->error);
        }
        if (!self::usable_mp3($bytes) && !(self::$anymp3 && self::any_mp3($bytes))) {
            return self::fail(502, 'UNUSABLE_AUDIO');
        }
        return [200, ['content-type' => 'audio/mpeg', 'x-credits-charged' => '0'], $bytes];
    }

    /**
     * One animated SVG scene (POST /v2/animations), drawn with the plugin's svg-1 system prompt.
     *
     * The service draws it (one more try when the drawing is unusable), then renders frames in a browser for a
     * visual review. That review needs a headless browser, which a Moodle site does not have; the service's own rule
     * when the renderer is unavailable is to keep the first valid drawing, and so does this port.
     *
     * @param mixed $body
     * @param string $system The svg-1/svg-2 system prompt (schema/svg-animation-system.md).
     * @return array
     */
    public static function animation($body, string $system): array {
        $v = self::object($body);
        $keys = ['prompt', 'contract', 'language', 'learnerLevel', 'teachingApproach', 'purpose', 'maxCredits',
            'bundleId', 'slideId', 'slot', 'remake'];
        $prompt = $v['prompt'] ?? null;
        if (
            $v === null || array_diff(array_keys($v), $keys)
                || !is_string($prompt) || self::utf16($prompt) < 1 || self::utf16($prompt) > 12000
                || draft_check::trim($prompt) === '' || preg_match(
                    '/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}]/u',
                    $prompt
                )
                || !in_array($v['contract'] ?? null, ['svg-1', 'svg-2'], true)
                || !is_string($v['language'] ?? null) || self::utf16($v['language']) < 2 || self::utf16($v['language']) > 32
                || !in_array($v['learnerLevel'] ?? null, ['primary', 'secondary', 'adult', 'advanced'], true)
                || !in_array($v['teachingApproach'] ?? null, ['story', 'science'], true)
                || ($v['purpose'] ?? null) !== 'animation' || !self::credits($v['maxCredits'] ?? null)
        ) {
            return self::fail(422, 'INVALID_INPUT');
        }
        $identity = array_intersect_key($v, array_flip(['bundleId', 'slideId', 'slot', 'remake']));
        if (
            $identity && (count($identity) !== 4 || !self::uuid($v['bundleId']) || !self::uuid($v['slideId'])
                || $v['slot'] !== 'animation' || $v['remake'] !== false)
        ) {
            return self::fail(422, 'INVALID_INPUT');
        }
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $svg = self::usable_animation(
                    (self::$aicall)($system, $prompt, self::SVG_TOKENS),
                    $v['contract'] === 'svg-2' ? self::MAX_SVG2 : self::MAX_SVG
                );
                return [200, ['content-type' => 'image/svg+xml', 'x-credits-charged' => '0'], $svg];
            } catch (\Throwable $e) {
                // Unusable drawing or provider failure: one more try, then animation_failed.
                continue;
            }
        }
        return self::fail(502, 'animation_failed');
    }

    /**
     * The SVG in the artist's answer, or an exception (usableAnimation): extracted without rewriting, and checked
     * fail-closed against the svg-1 element and attribute allowlist.
     *
     * One deliberate difference: the service's XML reader lets through a few things that the plugin's own SVG cleaner
     * then refuses (text after the root element, HTML entity names such as &nbsp;, an attribute written twice). This
     * port refuses them here, so the artist gets its second try instead of the drawing failing later in the plugin.
     *
     * @param string $answer
     * @param int $max largest SVG accepted, in bytes (svg-2 scenes may be larger)
     * @return string
     */
    public static function usable_animation(string $answer, int $max = self::MAX_SVG): string {
        $start = strpos($answer, '<svg');
        $end = strrpos($answer, '</svg>');
        if ($start === false || $end === false || $end < $start) {
            throw new \moodle_exception('animation_failed', 'error');
        }
        $svg = substr($answer, $start, $end + 6 - $start);
        if (
            strlen($svg) > $max
                || preg_match(
                    '/<!DOCTYPE|<!ENTITY|<(?:script|foreignObject|iframe|image)\b|javascript\s*:|\bon[\w:-]+\s*=/i',
                    $svg
                )
        ) {
            throw new \moodle_exception('animation_failed', 'error');
        }
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $doc = new \DOMDocument();
        $loaded = $doc->loadXML($svg, LIBXML_NONET);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $loaded ? $doc->documentElement : null;
        if (!$root || $errors || $root->nodeName !== 'svg' || $root->namespaceURI !== 'http://www.w3.org/2000/svg') {
            throw new \moodle_exception('animation_failed', 'error');
        }
        $box = array_map([self::class, 'jsnum'], preg_split('/[\s,]+/', draft_check::trim($root->getAttribute('viewBox'))));
        if (
            !$root->hasAttribute('viewBox') || count($box) !== 4 || array_filter($box, fn($n) => !is_finite($n))
                || $box[2] <= 0 || $box[3] <= 0
        ) {
            throw new \moodle_exception('animation_failed', 'error');
        }
        $allowed = ['svg', 'g', 'defs', 'symbol', 'use', 'title', 'desc', 'style', 'path', 'rect', 'circle', 'ellipse',
            'line', 'polyline', 'polygon', 'text', 'tspan', 'textpath', 'lineargradient', 'radialgradient', 'stop',
            'clippath', 'mask', 'pattern', 'marker', 'filter', 'fedropshadow', 'fegaussianblur', 'feoffset', 'feflood',
            'fecomposite', 'femerge', 'femergenode', 'feblend', 'fecolormatrix', 'femorphology', 'feturbulence',
            'fedisplacementmap', 'animate', 'animatetransform', 'animatemotion', 'mpath', 'set'];
        $refs = function (string $value): bool {
            preg_match_all('/url\s*\(([^)]*)\)/i', $value, $m);
            foreach ($m[1] as $ref) {
                if (!preg_match('/^[\'"]?#[\w:.-]+[\'"]?$/', draft_check::trim($ref))) {
                    return false;
                }
            }
            return true;
        };
        $count = 0;
        $walk = function (\DOMElement $node, int $depth) use (&$walk, &$count, $allowed, $refs) {
            if (++$count > 3000 || $depth > 60) {
                throw new \moodle_exception('animation_failed', 'error');
            }
            $name = strtolower($node->nodeName);
            if (
                !in_array($name, $allowed, true)
                    || ($node->namespaceURI && $node->namespaceURI !== 'http://www.w3.org/2000/svg')
            ) {
                throw new \moodle_exception('animation_failed', 'error');
            }
            foreach (self::attributes($node) as $key => $value) {
                $parts = explode(':', $key);
                $local = strtolower(end($parts));
                if (
                    preg_match('/^on/i', $key) || preg_match('/javascript\s*:|@import|expression\s*\(/i', $value)
                        || ($local === 'style' && preg_match('~\\\\|/\*~', $value))
                        || ($local === 'href' && !preg_match('/^#[A-Za-z_][\w:.-]*$/', $value))
                        || !$refs($value)
                ) {
                    throw new \moodle_exception('animation_failed', 'error');
                }
                if ($local === 'attributename' && preg_match('/^(?:on|href|xlink:href|style)/i', $value)) {
                    throw new \moodle_exception('animation_failed', 'error');
                }
            }
            if ($name === 'style') {
                $css = $node->textContent;
                if (preg_match('~@import|javascript\s*:|expression\s*\(|\\\\|/\*~i', $css) || !$refs($css)) {
                    throw new \moodle_exception('animation_failed', 'error');
                }
            }
            foreach ($node->childNodes as $child) {
                if ($child instanceof \DOMElement) {
                    $walk($child, $depth + 1);
                }
            }
        };
        $walk($root, 0);
        return $svg;
    }

    /**
     * An element's attributes as written, namespace declarations included, by qualified name.
     *
     * @param \DOMElement $node
     * @return array
     */
    protected static function attributes(\DOMElement $node): array {
        $out = [];
        $xpath = new \DOMXPath($node->ownerDocument);
        foreach ($xpath->query('namespace::*', $node) as $ns) {
            // Declared here (not only inherited from a parent).
            $declared = $ns->nodeName === 'xmlns' ? 'xmlns' : $ns->nodeName;
            if ($node->hasAttribute($declared) || ($node->parentNode instanceof \DOMElement) === false) {
                if ($ns->nodeName !== 'xmlns:xml') {
                    $out[$declared] = $ns->nodeValue;
                }
            }
        }
        foreach ($node->attributes as $attr) {
            $out[$attr->nodeName] = $attr->value;
        }
        return $out;
    }

    /**
     * Whether the audio is a clean MP3 stream as Google writes it (usableMp3): an optional ID3 tag, then at least two
     * MPEG-2 Layer III frames at 24 kHz, and nothing after them.
     *
     * @param string $audio
     * @return bool
     */
    public static function usable_mp3(string $audio): bool {
        $n = strlen($audio);
        $offset = 0;
        $frames = 0;
        if ($n > 10 && substr($audio, 0, 3) === 'ID3') {
            for ($i = 6; $i < 10; $i++) {
                if (ord($audio[$i]) & 128) {
                    return false;
                }
            }
            $offset = 10 + (ord($audio[6]) << 21 | ord($audio[7]) << 14 | ord($audio[8]) << 7 | ord($audio[9]));
            if (ord($audio[5]) & 16) {
                $offset += 10;
            }
        }
        $rates = [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160, 0];
        while ($offset + 4 <= $n) {
            $a = ord($audio[$offset]);
            $b = ord($audio[$offset + 1]);
            $c = ord($audio[$offset + 2]);
            if ($a !== 255 || ($b & 254) !== 242 || (($c >> 2) & 3) !== 1) {
                return false;
            }
            $bitrate = $rates[$c >> 4];
            if (!$bitrate) {
                return false;
            }
            $offset += intdiv(72000 * $bitrate, 24000) + (($c >> 1) & 1);
            if ($offset > $n) {
                return false;
            }
            $frames++;
        }
        return $frames >= 2 && $offset === $n;
    }

    /**
     * Whether the audio is an MP3 stream of any MPEG version (other voices: OpenAI, ElevenLabs): the same structure as
     * {@see usable_mp3()}, for MPEG-1, 2 and 2.5 Layer III at any standard sample rate, which may also end with an
     * ID3v1 tag or part-way through its last frame.
     *
     * @param string $audio
     * @return bool
     */
    public static function any_mp3(string $audio): bool {
        $n = strlen($audio);
        $offset = 0;
        $frames = 0;
        if ($n > 10 && substr($audio, 0, 3) === 'ID3') {
            for ($i = 6; $i < 10; $i++) {
                if (ord($audio[$i]) & 128) {
                    return false;
                }
            }
            $offset = 10 + (ord($audio[6]) << 21 | ord($audio[7]) << 14 | ord($audio[8]) << 7 | ord($audio[9]));
            if (ord($audio[5]) & 16) {
                $offset += 10;
            }
        }
        $v1 = [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320, 0];
        $v2 = [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160, 0];
        $rates = [3 => [44100, 48000, 32000], 2 => [22050, 24000, 16000], 0 => [11025, 12000, 8000]];
        while ($offset + 4 <= $n) {
            $a = ord($audio[$offset]);
            $b = ord($audio[$offset + 1]);
            $c = ord($audio[$offset + 2]);
            $version = ($b >> 3) & 3;
            if ($a !== 255 || ($b & 0xE0) !== 0xE0 || $version === 1 || (($b >> 1) & 3) !== 1 || (($c >> 2) & 3) === 3) {
                // An ID3v1 tag may close the file.
                return $frames >= 2 && $n - $offset === 128 && substr($audio, $offset, 3) === 'TAG';
            }
            $bitrate = ($version === 3 ? $v1 : $v2)[$c >> 4];
            if (!$bitrate) {
                return false;
            }
            $rate = $rates[$version][($c >> 2) & 3];
            $offset += intdiv(($version === 3 ? 144000 : 72000) * $bitrate, $rate) + (($c >> 1) & 1);
            if ($offset > $n) {
                // Some voices end the stream part-way through its last frame; that frame is only padding.
                return $frames >= 2;
            }
            $frames++;
        }
        return $frames >= 2 && $offset === $n;
    }

    /**
     * The checked page-writing request (bundleDraftRequestInput, after soft breaks became line breaks), or null.
     *
     * @param mixed $body
     * @return array|null
     */
    public static function draft_input($body): ?array {
        $v = self::object($body);
        if ($v === null) {
            return null;
        }
        foreach (['content', 'writerBrief', 'imageStyle'] as $field) {
            if (is_string($v[$field] ?? null)) {
                $v[$field] = preg_replace('/[\x{000B}\x{000C}]/u', "\n", $v[$field]);
            }
        }
        $enums = ['pictureMode' => ['single', 'sequence', 'animated', 'svg'], 'teachingApproach' => ['story', 'science'],
            'visualStyle' => self::IMAGE_STYLES, 'learnerLevel' => ['primary', 'secondary', 'adult', 'advanced'],
            'animationMode' => ['automatic', 'animated', 'static'], 'animationSceneVersion' => ['1.0', '1.1'],
            'animationComplexity' => ['simple', 'standard', 'detailed'], 'animationPacing' => ['slow', 'normal', 'fast']];
        $keys = array_merge(['title', 'industry', 'subindustry', 'language', 'culture', 'imageStyle', 'maxPages', 'recap',
            'content', 'maxCredits', 'writerBrief', 'questionTypes', '_autobuild', '_autobudget'], array_keys($enums));
        $types = $v['questionTypes'] ?? null;
        $ok = !array_diff(array_keys($v), $keys)
            && self::ctext($v['title'] ?? null, 200) && self::ctext($v['industry'] ?? null, 200, true)
            && self::ctext($v['subindustry'] ?? null, 200, true) && self::ctext($v['language'] ?? null, 32)
            && self::ctext($v['culture'] ?? null, 500, true)
            && is_string($v['imageStyle'] ?? null) && draft_check::cp($v['imageStyle']) <= 100000
            && !preg_match('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}]/u', $v['imageStyle'])
            && self::whole($v['maxPages'] ?? null, 1, 20) && is_bool($v['recap'] ?? null)
            && is_string($v['content'] ?? null) && self::utf16($v['content']) >= 1 && draft_check::trim($v['content']) !== ''
            && draft_check::cp($v['content']) <= 60000
            && !preg_match('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}]/u', $v['content'])
            && self::credits($v['maxCredits'] ?? null)
            && (!array_key_exists('writerBrief', $v) || (is_string($v['writerBrief'])
                && self::utf16($v['writerBrief']) >= 1 && self::utf16($v['writerBrief']) <= 100000))
            && is_array($types) && array_is_list($types) && count($types) <= 4
            && !array_diff(array_map(fn($t) => is_string($t) ? $t : '', $types), self::QUESTION_TYPES)
            && count(array_unique(array_map('strval', $types))) === count($types)
            && (!array_key_exists('_autobuild', $v) || is_bool($v['_autobuild']))
            && (!array_key_exists('_autobudget', $v) || self::whole($v['_autobudget'], 0, PHP_INT_MAX));
        foreach ($enums as $key => $values) {
            if (array_key_exists($key, $v) && !in_array($v[$key], $values, true)) {
                $ok = false;
            }
        }
        if (!$ok) {
            return null;
        }
        unset($v['_autobuild'], $v['_autobudget']);
        $v['maxPages'] = (int) $v['maxPages'];
        return $v;
    }

    // Helpers.

    /**
     * An error answer as the service sends it.
     *
     * @param int $status
     * @param string $code
     * @return array
     */
    protected static function fail(int $status, string $code): array {
        $headers = ['content-type' => 'application/json'];
        if ($status === 202 || $status === 429) {
            $headers['retry-after'] = '5';
        }
        return [$status, $headers, self::json(['error' => $code, 'requestId' => \core\uuid::generate()])];
    }

    /**
     * A JSON object as an array of its members, or null.
     *
     * @param mixed $body
     * @return array|null
     */
    protected static function object($body): ?array {
        if (is_string($body)) {
            $body = json_decode($body);
        }
        if (!draft_check::isobj($body)) {
            return null;
        }
        $out = [];
        foreach (get_object_vars($body) as $key => $value) {
            $out[(string) $key] = $value;
        }
        return $out;
    }

    /**
     * Request text: a string, not blank, at most $max code points, no control characters (line breaks and tabs
     * allowed when $multiline).
     *
     * @param mixed $s
     * @param int $max
     * @param bool $multiline
     * @return bool
     */
    protected static function text($s, int $max, bool $multiline): bool {
        $bad = $multiline ? '/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}]/u' : '/[\x{0000}-\x{001F}]/u';
        return is_string($s) && draft_check::trim($s) !== '' && draft_check::cp($s) <= $max && !preg_match($bad, $s);
    }

    /**
     * Picture book contract text (no angle brackets).
     *
     * @param mixed $s
     * @param int $max
     * @param bool $empty
     * @return bool
     */
    protected static function ctext($s, int $max, bool $empty = false): bool {
        return is_string($s) && ($empty || draft_check::trim($s) !== '') && draft_check::cp($s) <= $max
            && !preg_match('/[<>\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}]/u', $s);
    }

    /**
     * A whole JSON number within limits.
     *
     * @param mixed $n
     * @param int|float $min
     * @param int|float $max
     * @return bool
     */
    protected static function whole($n, $min, $max): bool {
        return (is_int($n) || (is_float($n) && is_finite($n) && floor($n) == $n)) && $n >= $min && $n <= $max;
    }

    /**
     * A maxCredits value (0 to 200).
     *
     * @param mixed $n
     * @return bool
     */
    protected static function credits($n): bool {
        return self::whole($n, 0, 200);
    }

    /**
     * A UUID as the service's schema accepts it.
     *
     * @param mixed $s
     * @return bool
     */
    protected static function uuid($s): bool {
        return is_string($s) && preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD',
            $s
        ) === 1;
    }

    /**
     * The number of frames of a picture (1 for a still picture; more for an animated PNG or WebP).
     *
     * @param string $bytes
     * @param string $mime
     * @return int
     */
    protected static function frames(string $bytes, string $mime): int {
        if ($mime === 'image/png' && strpos($bytes, 'acTL') !== false && preg_match('/acTL(.{4})/s', $bytes, $m)) {
            return max(1, unpack('N', $m[1])[1]);
        }
        if ($mime === 'image/webp' && strpos(substr($bytes, 0, 64), 'ANIM') !== false) {
            return substr_count($bytes, 'ANMF') ?: 2;
        }
        return 1;
    }

    /**
     * The picture as PNG.
     *
     * @param string $bytes
     * @param string $mime
     * @return string|null
     */
    protected static function png(string $bytes, string $mime): ?string {
        if ($mime === 'image/png') {
            return $bytes;
        }
        $image = @imagecreatefromstring($bytes);
        if (!$image) {
            return null;
        }
        ob_start();
        imagepng($image);
        imagedestroy($image);
        return (string) ob_get_clean();
    }

    /**
     * JavaScript's Number() of an attribute value.
     *
     * @param string $s
     * @return float
     */
    public static function jsnum(string $s): float {
        $s = draft_check::trim($s);
        if ($s === '') {
            return 0.0;
        }
        if (preg_match('/^0[xX]([0-9a-fA-F]+)$/D', $s, $m)) {
            return (float) hexdec($m[1]);
        }
        if (preg_match('/^0[oO]([0-7]+)$/D', $s, $m)) {
            return (float) octdec($m[1]);
        }
        if (preg_match('/^0[bB]([01]+)$/D', $s, $m)) {
            return (float) bindec($m[1]);
        }
        if (preg_match('/^[+-]?Infinity$/D', $s)) {
            return $s[0] === '-' ? -INF : INF;
        }
        if (preg_match('/^[+-]?(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?$/D', $s)) {
            return (float) $s;
        }
        return NAN;
    }

    /**
     * UTF-16 code units.
     *
     * @param string $s
     * @return int
     */
    protected static function utf16(string $s): int {
        return draft_check::utf16($s);
    }

    /**
     * The first $n UTF-16 code units (String.prototype.slice); a cut surrogate pair becomes U+FFFD.
     *
     * @param string $s
     * @param int $n
     * @return string
     */
    public static function utf16_slice(string $s, int $n): string {
        $units = mb_convert_encoding($s, 'UTF-16LE', 'UTF-8');
        if (strlen($units) <= 2 * $n) {
            return $s;
        }
        $cut = substr($units, 0, 2 * $n);
        $last = unpack('v', substr($cut, -2))[1];
        if ($last >= 0xD800 && $last <= 0xDBFF) {
            return mb_convert_encoding(substr($cut, 0, -2), 'UTF-8', 'UTF-16LE') . "\u{FFFD}";
        }
        return mb_convert_encoding($cut, 'UTF-8', 'UTF-16LE');
    }

    /**
     * JSON as the service writes it.
     *
     * @param mixed $v
     * @return string
     */
    public static function json($v): string {
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS);
    }
}
