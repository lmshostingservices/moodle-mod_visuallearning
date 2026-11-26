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

use mod_visuallearning\local\ai\requests;
use mod_visuallearning\local\byok\engine;
use mod_visuallearning\local\byok\provider_error;

/**
 * Bring your own AI: Visual Learning work that runs on the site's own AI instead of LMS Labs credits.
 *
 * AI Grader Central Config (local_aiconfig) decides, for each kind of work, whether it runs on LMS Labs credits, the
 * Moodle AI subsystem or the site's own key. Page writing and animated scenes are text, pictures are images and
 * narration is voice; each is decided separately, so a site can write pages on its own AI and still narrate on
 * credits. Work on the site's own AI runs the service's own logic (local\byok\engine) inside this Moodle site, costs no
 * credits and answers exactly as the service would, so the request log, the review and the saved media work as before.
 *
 * Only an own OpenAI image key can edit a picture, so the second and third card pictures of a lesson (which edit the
 * card before) wait for that key when pictures run on the site's own AI.
 *
 * @package    mod_visuallearning
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class byok {
    /** The component Central Config knows this plugin by. */
    const COMPONENT = 'mod_visuallearning';
    /** Seconds one text call may take: a 20-page lesson is a long answer. */
    const TEXT_TIMEOUT = 240;
    /** The marker on a stored request that runs on the site's own AI. */
    const MARKER = '_ownai';
    /** The kind of AI each media request needs. */
    const KINDS = [requests::ANIMATION => 'text', requests::IMAGE => 'image', requests::COVER => 'image',
        requests::OPTION => 'image', requests::CARD => 'image', requests::VOICE => 'voice'];
    /** The request operation behind each price operation. */
    const PRICE_OPERATIONS = ['page' => requests::WRITE, 'import' => requests::IMPORT, 'animation' => requests::ANIMATION,
        'image' => requests::IMAGE, 'option' => requests::OPTION, 'card' => requests::CARD, 'voice' => requests::VOICE];
    /** The service's Chirp 3 HD voices, and the closest OpenAI voice for each. */
    const OPENAI_VOICES = ['Achernar' => 'shimmer', 'Aoede' => 'nova', 'Autonoe' => 'coral', 'Callirrhoe' => 'shimmer',
        'Despina' => 'coral', 'Erinome' => 'sage', 'Gacrux' => 'sage', 'Kore' => 'shimmer', 'Laomedeia' => 'nova',
        'Leda' => 'coral', 'Pulcherrima' => 'nova', 'Sulafat' => 'sage', 'Vindemiatrix' => 'sage', 'Zephyr' => 'sage',
        'Achird' => 'echo', 'Algenib' => 'onyx', 'Algieba' => 'ash', 'Alnilam' => 'onyx', 'Charon' => 'echo',
        'Enceladus' => 'ash', 'Fenrir' => 'ash', 'Iapetus' => 'echo', 'Orus' => 'ballad', 'Puck' => 'onyx',
        'Rasalgethi' => 'ballad', 'Sadachbia' => 'verse', 'Sadaltager' => 'echo', 'Schedar' => 'onyx',
        'Umbriel' => 'ash', 'Zubenelgenubi' => 'verse'];

    /** @var string|null What the site's own AI said when it last failed, for the request log. */
    public static $lasterror = null;

    /**
     * Whether a kind of work runs on the site's own AI now.
     *
     * @param string $kind text, image or voice.
     * @return bool
     */
    public static function uses(string $kind): bool {
        return class_exists('\local_aiconfig\byok\ai') && \local_aiconfig\byok\ai::uses_own_ai(self::COMPONENT, $kind);
    }

    /**
     * Whether any work runs on the site's own AI now.
     *
     * @return bool
     */
    public static function any(): bool {
        return self::uses('text') || self::uses('image') || self::uses('voice');
    }

    /**
     * Which kinds of work run on the site's own AI, for the page.
     *
     * @return array text, image, voice => bool.
     */
    public static function state(): array {
        return ['text' => self::uses('text'), 'image' => self::uses('image'), 'voice' => self::uses('voice')];
    }

    /**
     * Whether a request runs entirely on the site's own AI now, free of LMS Labs credits.
     *
     * Media follow their own kind (an animated scene is drawn by the text AI). A lesson's pages are bought at LMS Labs
     * as slides, and a slide's price includes its picture and narration: pages are free only when pictures and voice
     * also run on the site's own AI. Pages written on the site's own AI while pictures or voice stay on credits are
     * still written here, then bought as imported slides ({@see self::hybrid()}), so their included media stay
     * included. Pages imported from the teacher's own assistant use no AI at all: only the included media make them
     * cost credits.
     *
     * @param string $operation A requests:: operation.
     * @return bool
     */
    public static function local(string $operation): bool {
        if ($operation === requests::WRITE) {
            return self::uses('text') && self::uses('image') && self::uses('voice');
        }
        if ($operation === requests::IMPORT) {
            return self::uses('image') && self::uses('voice');
        }
        return isset(self::KINDS[$operation]) && self::uses(self::KINDS[$operation]);
    }

    /**
     * Whether pages are written on the site's own AI and then bought at LMS Labs as imported slides, because pictures
     * or voice still run on credits.
     *
     * @return bool
     */
    public static function hybrid(): bool {
        return self::uses('text') && !self::local(requests::WRITE);
    }

    /**
     * Whether a price operation (lmslabs::hold()) runs entirely on the site's own AI now.
     *
     * @param string $operation page, import, image, option, voice, card or animation.
     * @return bool
     */
    public static function local_price(string $operation): bool {
        return isset(self::PRICE_OPERATIONS[$operation]) && self::local(self::PRICE_OPERATIONS[$operation]);
    }

    /**
     * Why work of this kind cannot run on the site's own AI now, or null when it can.
     *
     * The site's AI switches still apply. No LMS Labs credentials, activation or price are needed: the Bring your own
     * AI licence covers the work and it costs no credits.
     *
     * @param string $operation A price operation.
     * @return string|null A hold_... string suffix, or null.
     */
    public static function hold(string $operation): ?string {
        $setting = ['page' => 'aiwrite', 'image' => 'aiimages', 'option' => 'aiimages', 'voice' => 'aivoice',
            'import' => '', 'card' => 'aiimages', 'animation' => 'aiimages'][$operation] ?? '';
        if ($setting !== '' && !get_config('mod_visuallearning', $setting)) {
            return 'off';
        }
        if ($operation === 'card' && !self::image_edits()) {
            // Cards 2 and 3 edit the card before: only an own OpenAI image key can.
            return 'ownainoedits';
        }
        return null;
    }

    /**
     * Whether the site's own pictures can edit a given picture (card pictures 2 and 3).
     *
     * @return bool
     */
    public static function image_edits(): bool {
        return method_exists('\local_aiconfig\byok\ai', 'image_edits')
            && \local_aiconfig\byok\ai::image_edits(self::COMPONENT);
    }

    /**
     * Mark a request body to run on the site's own AI: it is free, so its most credits is 0.
     *
     * @param array $body
     * @return array
     */
    public static function mark(array $body): array {
        $body['maxCredits'] = 0;
        $body[self::MARKER] = true;
        return $body;
    }

    /**
     * Whether a stored request runs on the site's own AI.
     *
     * @param \stdClass $row
     * @return bool
     */
    public static function marked(\stdClass $row): bool {
        $body = json_decode((string) $row->body, true);
        return is_array($body) && !empty($body[self::MARKER]);
    }

    /**
     * Run a stored request on the site's own AI, as LMS Labs would answer it.
     *
     * @param string $operation A requests:: operation.
     * @param string $payload The exact body the plugin would send to LMS Labs.
     * @return array [status, headers (lower-case names), body]
     */
    public static function send(string $operation, string $payload): array {
        global $CFG;
        self::$lasterror = null;
        if (!self::local($operation) && !($operation === requests::WRITE && self::hybrid())) {
            // The site moved this work back to LMS Labs credits after it was confirmed as free: never sent.
            return [409, ['content-type' => 'application/json', 'x-ownai' => '1'], json_encode(['error' => 'OWNAI_OFF'])];
        }
        \core_php_time_limit::raise(2 * self::TEXT_TIMEOUT + 60);
        self::hooks();
        $body = json_decode($payload);
        switch ($operation) {
            case requests::WRITE:
                $result = engine::draft($body);
                break;
            case requests::IMPORT:
                $result = engine::import();
                break;
            case requests::ANIMATION:
                $result = engine::animation(
                    $body,
                    (string) file_get_contents($CFG->dirroot . '/mod/visuallearning/schema/svg-animation-system.md')
                );
                break;
            case requests::VOICE:
                $result = engine::tts($body, self::catalogue());
                break;
            default:
                $result = engine::image($body);
        }
        // Marks the answer as made here, not at LMS Labs.
        $result[1]['x-ownai'] = '1';
        return $result;
    }

    /**
     * Point the engine at the site's own AI.
     */
    protected static function hooks(): void {
        engine::$aicall = function (string $system, string $user, int $maxtokens): string {
            try {
                // The writer's and the artist's instructions go word for word, as the service sends them.
                return \local_aiconfig\byok\ai::text(
                    self::COMPONENT,
                    $system,
                    $user,
                    ['maxtokens' => $maxtokens, 'timeout' => self::TEXT_TIMEOUT]
                );
            } catch (\moodle_exception $e) {
                throw self::failed($e);
            }
        };
        engine::$image = function (string $prompt, string $aspect, ?array $reference): string {
            $square = $aspect === '1:1';
            // The service draws at medium quality; 4:3 pictures are cut from the nearest landscape size.
            $options = ['size' => $square ? '1024x1024' : '1536x1024', 'quality' => 'medium'];
            if ($reference !== null) {
                $options['reference'] = $reference;
            }
            try {
                $bytes = \local_aiconfig\byok\ai::image(self::COMPONENT, $prompt, $options);
            } catch (\moodle_exception $e) {
                throw self::failed($e);
            }
            return self::shape($bytes, $square ? 1.0 : 4 / 3);
        };
        engine::$tts = function (string $text, string $locale, string $voice, float $rate): string {
            try {
                return self::speech($text, $voice, $rate);
            } catch (\moodle_exception $e) {
                throw self::failed($e);
            }
        };
        $vendor = \local_aiconfig\byok\router::vendor(\local_aiconfig\byok\router::VOICE);
        engine::$anymp3 = $vendor !== 'googletts';
    }

    /**
     * The service's answer to a failed call to the site's own AI: the provider failed (nothing was charged).
     *
     * @param \moodle_exception $e
     * @return provider_error
     */
    protected static function failed(\moodle_exception $e): provider_error {
        self::$lasterror = $e->getMessage();
        return new provider_error('PROVIDER_FAILED', 502, $e->getMessage());
    }

    /**
     * A picture cut to the shape the service delivers (4:3 or square), from its centre. A picture already that shape,
     * or one this site cannot read, is left as it is (the engine then checks it as the service would).
     *
     * @param string $bytes
     * @param float $ratio width / height
     * @return string
     */
    public static function shape(string $bytes, float $ratio): string {
        $info = @getimagesizefromstring($bytes);
        if (
            !$info || !$info[0] || !$info[1] || abs($info[0] / $info[1] - $ratio) <= .03
                || !function_exists('imagecreatefromstring')
        ) {
            return $bytes;
        }
        $image = @imagecreatefromstring($bytes);
        if (!$image) {
            return $bytes;
        }
        [$width, $height] = [$info[0], $info[1]];
        if ($width / $height > $ratio) {
            $w = (int) round($height * $ratio);
            $box = ['x' => intdiv($width - $w, 2), 'y' => 0, 'width' => $w, 'height' => $height];
        } else {
            $h = (int) round($width / $ratio);
            $box = ['x' => 0, 'y' => intdiv($height - $h, 2), 'width' => $width, 'height' => $h];
        }
        $cut = imagecrop($image, $box);
        if (!$cut) {
            return $bytes;
        }
        ob_start();
        imagepng($cut);
        return (string) ob_get_clean();
    }

    /**
     * One narration clip.
     *
     * The service narrates with Google Chirp 3 HD voices at 24 kHz. A Google key uses the same voice; an OpenAI key
     * gets the closest OpenAI voice of the same kind; ElevenLabs uses the voice the administrator chose.
     *
     * @param string $text
     * @param string $voice A Chirp 3 HD voice, such as en-AU-Chirp3-HD-Aoede.
     * @param float $rate 0.8, 1 or 1.2.
     * @return string MP3 bytes.
     */
    protected static function speech(string $text, string $voice, float $rate): string {
        $vendor = \local_aiconfig\byok\router::vendor(\local_aiconfig\byok\router::VOICE);
        $parts = explode('-Chirp3-HD-', $voice);
        $language = $parts[0];
        $options = ['language' => $language, 'format' => 'mp3', 'rate' => $rate];
        if ($vendor === 'googletts') {
            $options['voice'] = $voice;
            $options['samplerate'] = 24000;
        } else if ($vendor === 'openai') {
            $options['voice'] = self::OPENAI_VOICES[$parts[1] ?? ''] ?? '';
        } else {
            $options['voice'] = '';
        }
        return \local_aiconfig\byok\ai::speech(self::COMPONENT, $text, $options);
    }

    /**
     * The voices a clip may use: the service's catalogue as LMS Labs last published it, or every Chirp 3 HD voice in
     * every lesson language when this site has never read it.
     *
     * @return array [locale => [full voice names]]
     */
    public static function catalogue(): array {
        $out = [];
        foreach (\mod_visuallearning\local\ai\lmslabs::voice_catalogue() as $locale => $voices) {
            $out[$locale] = array_values($voices);
        }
        return $out;
    }

    /**
     * What the service publishes about itself (GET /v2/capabilities), for a site on its own AI that has never read it
     * from LMS Labs. The voice catalogue lists every Chirp 3 HD voice in every lesson language.
     *
     * @return array
     */
    public static function capabilities(): array {
        $names = array_merge(\mod_visuallearning\local\voices::FEMALE, \mod_visuallearning\local\voices::MALE);
        sort($names);
        $locales = [];
        foreach (array_keys(\mod_visuallearning\local\voices::LANGUAGES) as $locale) {
            $locales[] = ['locale' => $locale, 'ttsLanguageCode' => $locale,
                'voices' => array_map(fn($n) => ['name' => $locale . '-Chirp3-HD-' . $n,
                    'gender' => in_array($n, \mod_visuallearning\local\voices::FEMALE, true) ? 'FEMALE' : 'MALE'], $names)];
        }
        $slots = ['text', 'picture', 'option0', 'option1', 'option2', 'option3', 'cover'];
        foreach (['concept', 'action', 'outcome', 'summary', 'question', 'feedback', 'retry'] as $clip) {
            $slots[] = 'clip:' . $clip;
        }
        return [
            'contract' => 'bundle-1',
            'bundle' => ['slideCredits' => 10, 'maxSlides' => 20, 'slots' => $slots],
            'remakes' => ['pictureCredits' => 2, 'voice' => ['limit' => 0, 'windowDays' => 30, 'credits' => 2]],
            'questionTypes' => engine::QUESTION_TYPES,
            'learnerLevels' => ['primary', 'secondary', 'adult', 'advanced'],
            'aspectRatios' => ['4:3', '1:1'],
            'writerBrief' => true,
            'pictureModes' => ['single', 'sequence'],
            'cardPictures' => ['credits' => 2],
            'animations' => ['credits' => 6, 'contracts' => ['svg-1']],
            'imageFields' => ['teachingApproach'],
            'teachingApproaches' => ['story', 'science'],
            'draftPictureModes' => ['single', 'sequence', 'animated', 'svg'],
            'visualStyles' => engine::IMAGE_STYLES,
            'animatedSceneVersions' => ['1.0', '1.1'],
            'animationModes' => ['automatic', 'animated', 'static'],
            'animationComplexities' => ['simple', 'standard', 'detailed'],
            'animationPacing' => ['slow', 'normal', 'fast'],
            'locales' => $locales,
            'tariff' => ['page' => 10, 'image' => 2, 'card' => 2, 'animation' => 6, 'tts' => 2,
                'clipRefSupported' => true, 'maxCreditsRequired' => true, 'ttsRemakeLimit' => 0,
                'ttsRemakeWindowDays' => 30],
        ];
    }
}
