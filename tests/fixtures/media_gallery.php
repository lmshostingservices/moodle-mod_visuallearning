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

/**
 * CLI-only visual fixture. Executes the real Step 3 gallery markup with inert Moodle doubles.
 * Not a Moodle runtime, integration test, or a purchasable lesson.
 *
 * @package mod_visuallearning
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @copyright  2026 LMS-Labs
 */

namespace mod_visuallearning\local {
    class media {
        const AREA_COVER = 'cover';
        const AREA_IMAGE = 'image';

        public static function image_file($context, $area, $id) {
            return $id < 6 ? (object)['id' => $id] : null;
        }

        public static function option_prompts($book, $page) {
            return $page->id === 2 ? ['fixture option'] : [];
        }

        public static function card_prompts($book, $page) {
            return $page->id === 3 ? ['first', 'second', 'third'] : [];
        }

        public static function card_chain($context, $book, $page) {
            return [['file' => true], ['file' => true], ['file' => false]];
        }
    }

    class picture_modes {
        const CARDS = 3;
    }
}

namespace {
    if (PHP_SAPI !== 'cli') {
        http_response_code(404);
        exit;
    }
    define('MOODLE_INTERNAL', true);
    define('VISUALLEARNING_MEDIA_PENDING', 0);
    define('VISUALLEARNING_MEDIA_QUEUED', 1);
    define('VISUALLEARNING_MEDIA_READY', 2);
    define('VISUALLEARNING_MEDIA_FAILED', 3);
    require __DIR__ . '/../../lang/en/visuallearning.php';

    function s($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }

    function get_string($key, $component = '', $value = null) {
        global $string;
        return str_replace('{$a}', (string)$value, $string[$key] ?? $key);
    }

    function format_string($text, ...$unused) {
        return s($text);
    }

    function sesskey() {
        return 'fixture-session-key';
    }

    class moodle_url {
        public $params;
        public function __construct($url, $params = []) {
            $this->params = ($url instanceof self ? $url->params : []) + $params;
        }
        public function __toString() {
            return '/fixture?' . http_build_query($this->params);
        }
    }

    class html_writer {
        public static function attrs($attrs) {
            $html = '';
            foreach ($attrs as $name => $value) {
                $html .= ' ' . $name . '="' . s($value) . '"';
            }
            return $html;
        }
        public static function tag($tag, $content, $attrs = []) {
            return '<' . $tag . self::attrs($attrs) . '>' . $content . '</' . $tag . '>';
        }
        public static function div($content, $class, $attrs = []) {
            return self::tag('div', $content, ['class' => $class] + $attrs);
        }
        public static function span($content, $class, $attrs = []) {
            return self::tag('span', $content, ['class' => $class] + $attrs);
        }
        public static function empty_tag($tag, $attrs) {
            return '<' . $tag . self::attrs($attrs) . '>';
        }
    }

    // Equivalent to Moodle's single_button form structure; never sends a request.
    function visuallearning_button($url, $label, $method = 'post', $primary = false, $confirm = '') {
        $inputs = '';
        foreach ($url->params as $name => $value) {
            $inputs .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
        }
        return html_writer::div(html_writer::tag('form', $inputs .
            html_writer::tag('button', s($label), ['type' => 'submit',
                'class' => 'btn ' . ($primary ? 'btn-primary' : 'btn-secondary')]),
            ['method' => $method, 'action' => '/fixture-no-submission']), 'singlebutton');
    }

    function visuallearning_file_url($file) {
        // Deliberately schematic fixture art, not a provider output or a production image.
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 300">' .
            '<rect width="400" height="300" fill="#e9f2f5"/>' .
            '<circle cx="315" cy="65" r="28" fill="#edc66d"/>' .
            '<path d="M0 215 Q70 165 135 210 T270 210 T405 210 V300 H0Z" fill="#73b7c4"/>' .
            '<path d="M45 120 Q125 60 200 120 T355 120" fill="none" stroke="#6876ad" stroke-width="8"/>' .
            '<path d="M335 98 L357 120 L335 140" fill="none" stroke="#6876ad" stroke-width="8"/>' .
            '</svg>';
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    $context = (object)[];
    $cm = (object)['id' => 42];
    $book = (object)['coverstatus' => VISUALLEARNING_MEDIA_READY];
    $canai = !in_array('--no-ai', $argv, true);
    $baseurl = new moodle_url('/manage.php', ['id' => 42]);
    $titles = ['The Sun Warms Our Water', 'Water Escapes into the Air', 'Trees Release Water into Air',
        'Rising Air Becomes Cooler', 'Tiny Droplets Make Clouds', 'When Clouds Become Heavy',
        'Rain Returns to the Ground', 'A Very Long Translated Page Title That Should Wrap Without Clipping'];
    $pages = [];
    foreach ($titles as $i => $title) {
        $pages[] = (object)['id' => $i + 1, 'pagenum' => $i + 1, 'title' => $title, 'alttext' => 'Fixture water cycle diagram',
            'bundleid' => 1, 'imagestatus' => $i < 5 ? 2 : ($i === 6 ? 3 : 1),
            'audiostatus' => $i < 4 ? 2 : ($i === 6 ? 3 : 0)];
    }
    $total = count($pages);
    $ready = ['image' => 5, 'audio' => 4];
    $hascover = true;
    $coverready = true;
    $inprogress = 2;
    $mediadone = false;
    $pendingby = [6 => ['image' => 1], 8 => ['image' => 1]];
    $problems = [];
    $sequence = true;
    if (in_array('--complete', $argv, true)) {
        $ready = ['image' => 8, 'audio' => 8];
        $mediadone = true;
        $inprogress = 0;
        $pendingby = [];
        foreach ($pages as $page) {
            $page->imagestatus = VISUALLEARNING_MEDIA_READY;
            $page->audiostatus = VISUALLEARNING_MEDIA_READY;
        }
    }
    if (in_array('--empty', $argv, true)) {
        $total = 0;
        $pages = [];
    }
    echo '<!doctype html><html lang="en"><meta charset="utf-8">' .
        '<meta name="viewport" content="width=device-width, initial-scale=1">' .
        '<title>Step 3 gallery — isolated markup fixture</title>' .
        '<link rel="stylesheet" href="/styles.css">' .
        '<style>body{margin:0;background:#edf1f6;font-family:system-ui,sans-serif;padding:24px;color:#243149}' .
        'main{max-width:1160px;margin:auto}.fixture-label{font-size:13px;margin-bottom:20px}' .
        '.vl-slide{background:#fdfefe}h3{margin-top:0}button{font:inherit;cursor:pointer}' .
        '.vl-meter{border-radius:99px;overflow:hidden}.vl-meter-fill{height:100%;background:#12a474}' .
        '@media(max-width:767px){body{padding:12px}.vl-wizard .vl-slide{padding:18px!important}}</style>' .
        '<body class="path-mod-visuallearning"><main>' .
        '<p class="fixture-label">Isolated Step 3 markup fixture · Not a live Moodle lesson. Forms do not submit.</p>' .
        '<div class="vl-wizard"><section class="vl-slide" data-slide="3">' .
        '<h3>Step 3 — Create the pictures and voiceover</h3>' .
        '<p>Review each page, track what is ready, and choose what to remake.</p>';
    // Execute the actual production rendering block, so markup assertions cannot drift.
    $source = file_get_contents(__DIR__ . '/../../manage.php');
    $start = strpos($source, '// One status chip:');
    $end = strpos($source, '// Requests still running:', $start);
    if ($start === false || $end === false) {
        throw new \RuntimeException('Step 3 markup boundaries not found.');
    }
    eval('use mod_visuallearning\\local\\media;' .
        'use mod_visuallearning\\local\\ai\\requests;' . substr($source, $start, $end - $start));
    echo '</section></div></main><script>document.addEventListener("submit",function (e){e.preventDefault();' .
        'document.body.dataset.submitted="true";});</script></body></html>';
}
