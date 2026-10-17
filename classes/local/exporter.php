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

namespace mod_visuallearning\local;

use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->dirroot . '/mod/visuallearning/lib.php');

/**
 * Everything a lesson holds, for teachers to reuse: the questions (Moodle XML, GIFT, CSV), the text (Word, Markdown,
 * CSV), the pictures, the voiceover and an AI prompt pack. Free: nothing is sent to LMS Labs.
 *
 * Question types export as Moodle question types: true or false as True/False, pick the picture as Multiple choice
 * (with its pictures), match them up as Matching, and sort into groups as Matching (each card matched to its group).
 *
 * @package    mod_visuallearning
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class exporter {
    /** @var stdClass */
    protected $book;

    /** @var \context_module */
    protected $context;

    /** @var stdClass[] */
    protected $pages;

    /**
     * Constructor.
     *
     * @param stdClass $book
     * @param \context_module $context
     */
    public function __construct(stdClass $book, \context_module $context) {
        $this->book = $book;
        $this->context = $context;
        $this->pages = array_values(visuallearning_get_pages((int)$book->id));
    }

    /**
     * A file name stem made from a title.
     *
     * @param string $text
     * @return string
     */
    public static function slug(string $text): string {
        $slug = \core_text::strtolower(trim(preg_replace('/[^\p{L}\p{N}]+/u', '-', $text) ?? '', '-'));
        return \core_text::substr($slug, 0, 60) ?: 'book';
    }

    /**
     * The pages that have a usable question, with it.
     *
     * @return array list of [page, question]
     */
    public function questions(): array {
        $out = [];
        if (empty($this->book->enablequiz)) {
            return $out;
        }
        foreach ($this->pages as $page) {
            if (($q = question::of_page($page)) !== null) {
                $out[] = [$page, $q];
            }
        }
        return $out;
    }

    /**
     * The questions as Moodle XML (for the question bank).
     *
     * @return string
     */
    public function moodle_xml(): string {
        $x = fn(string $text): string => htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $cdata = fn(string $html): string => '<![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', $html) . ']]>';
        $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n<quiz>\n";
        $out .= '  <question type="category"><category><text>$course$/' . $x(format_string(
            $this->book->name,
            true,
            ['context' => $this->context]
        )) . "</text></category></question>\n";
        foreach ($this->questions() as [$page, $q]) {
            $name = $x(get_string('pagex', 'mod_visuallearning', (int)$page->pagenum) . ' ' . $page->title);
            $prompt = s($q['prompt']);
            $feedback = $q['feedback'] !== '' ? '<generalfeedback format="html"><text>' . $cdata(s($q['feedback'])) .
                '</text></generalfeedback>' : '';
            if ($q['type'] === question::TRUEFALSE) {
                $out .= '  <question type="truefalse"><name><text>' . $name . '</text></name>' .
                    '<questiontext format="html"><text>' . $cdata($prompt) . '</text></questiontext>' . $feedback .
                    '<defaultgrade>1</defaultgrade>' .
                    '<answer fraction="' . ($q['answer'] ? 100 : 0) . '"><text>true</text></answer>' .
                    '<answer fraction="' . ($q['answer'] ? 0 : 100) . '"><text>false</text></answer>' .
                    "</question>\n";
            } else if ($q['type'] === question::PICK) {
                $answers = '';
                foreach ($q['data']['options'] as $i => $option) {
                    $text = s($option['label']);
                    $files = '';
                    $image = question::pictures($this->book) ? media::option_file($this->context, (int)$page->id, $i) : null;
                    if ($image) {
                        $file = 'option-' . chr(97 + $i) . '.' . pathinfo($image->get_filename(), PATHINFO_EXTENSION);
                        $text = '<p><img src="@@PLUGINFILE@@/' . $file . '" alt="' . s($option['alt_text']) .
                            '" width="240" height="240"></p><p>' . $text . '</p>';
                        $files = '<file name="' . $file . '" path="/" encoding="base64">' .
                            base64_encode($image->get_content()) . '</file>';
                    }
                    $answers .= '<answer fraction="' . ($i === $q['data']['correct'] ? 100 : 0) . '" format="html"><text>' .
                        $cdata($text) . '</text>' . $files . '</answer>';
                }
                $out .= '  <question type="multichoice"><name><text>' . $name . '</text></name>' .
                    '<questiontext format="html"><text>' . $cdata($prompt) . '</text></questiontext>' . $feedback .
                    '<defaultgrade>1</defaultgrade><single>true</single><shuffleanswers>true</shuffleanswers>' .
                    '<answernumbering>abc</answernumbering>' . $answers . "</question>\n";
            } else {
                $pairs = $q['type'] === question::MATCH ? $q['data']['pairs'] : array_map(
                    fn($it) => ['left' => $it['text'], 'right' => $q['data']['groups'][$it['group']]],
                    $q['data']['items']
                );
                $subs = '';
                foreach ($pairs as $pair) {
                    $subs .= '<subquestion format="html"><text>' . $cdata(s($pair['left'])) . '</text><answer><text>' .
                        $x($pair['right']) . '</text></answer></subquestion>';
                }
                $out .= '  <question type="match"><name><text>' . $name . '</text></name>' .
                    '<questiontext format="html"><text>' . $cdata($prompt) . '</text></questiontext>' . $feedback .
                    '<defaultgrade>1</defaultgrade><shuffleanswers>true</shuffleanswers>' . $subs . "</question>\n";
            }
        }
        return $out . "</quiz>\n";
    }

    /**
     * The questions in GIFT format (text only).
     *
     * @return string
     */
    public function gift(): string {
        $g = fn(string $text): string => addcslashes($text, '~=#{}:');
        $out = '';
        foreach ($this->questions() as [$page, $q]) {
            $out .= '::' . $g(get_string('pagex', 'mod_visuallearning', (int)$page->pagenum) . ' ' . $page->title) . ':: ';
            $out .= $g($q['prompt']) . ' {';
            if ($q['type'] === question::TRUEFALSE) {
                $out .= $q['answer'] ? 'TRUE' : 'FALSE';
            } else if ($q['type'] === question::PICK) {
                foreach ($q['data']['options'] as $i => $option) {
                    $out .= "\n  " . ($i === $q['data']['correct'] ? '=' : '~') . $g($option['label']);
                }
            } else {
                $pairs = $q['type'] === question::MATCH ? $q['data']['pairs'] : array_map(
                    fn($it) => ['left' => $it['text'], 'right' => $q['data']['groups'][$it['group']]],
                    $q['data']['items']
                );
                foreach ($pairs as $pair) {
                    $out .= "\n  =" . $g($pair['left']) . ' -> ' . $g($pair['right']);
                }
            }
            if ($q['feedback'] !== '') {
                $out .= "\n  ####" . $g($q['feedback']);
            }
            $out .= "\n}\n\n";
        }
        return $out;
    }

    /**
     * CSV text from rows (first row: headings), with a byte order mark so spreadsheets read UTF-8.
     *
     * @param array $rows
     * @return string
     */
    public static function csv(array $rows): string {
        $handle = fopen('php://temp', 'w+');
        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '\\');
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);
        return "\xEF\xBB\xBF" . $csv;
    }

    /**
     * The questions as a CSV table.
     *
     * @return string
     */
    public function questions_csv(): string {
        $rows = [['page', 'page_title', 'type', 'question', 'answer', 'options_or_pairs', 'feedback']];
        foreach ($this->questions() as [$page, $q]) {
            $answer = '';
            $detail = '';
            if ($q['type'] === question::TRUEFALSE) {
                $answer = $q['answer'] ? 'true' : 'false';
            } else if ($q['type'] === question::PICK) {
                $answer = $q['data']['options'][$q['data']['correct']]['label'];
                $detail = implode(' | ', array_map(fn($o) => $o['label'], $q['data']['options']));
            } else if ($q['type'] === question::MATCH) {
                $detail = implode(' | ', array_map(fn($p) => $p['left'] . ' -> ' . $p['right'], $q['data']['pairs']));
            } else {
                $detail = implode(' | ', array_map(
                    fn($it) => $it['text'] . ' -> ' . $q['data']['groups'][$it['group']],
                    $q['data']['items']
                ));
            }
            $rows[] = [(int)$page->pagenum, $page->title, $q['type'], $q['prompt'], $answer, $detail, $q['feedback']];
        }
        return self::csv($rows);
    }

    /**
     * The lesson's text as Markdown.
     *
     * @return string
     */
    public function markdown(): string {
        $out = '# ' . format_string($this->book->name, true, ['context' => $this->context]) . "\n\n";
        foreach ($this->pages as $page) {
            $out .= '## ' . (int)$page->pagenum . '. ' . $page->title . "\n\n";
            foreach (VISUALLEARNING_CARDS as $card) {
                $out .= '**' . get_string('card_' . $card, 'mod_visuallearning') . ':** ' . $page->{$card . 'text'} . "\n\n";
            }
            if (trim((string)$page->summarytext) !== '') {
                $out .= '*' . get_string('summarytext', 'mod_visuallearning') . ':* ' . $page->summarytext . "\n\n";
            }
            if (($q = $this->book->enablequiz ? question::of_page($page) : null) !== null) {
                $out .= '*' . get_string('qtype_' . $q['type'], 'mod_visuallearning') . ':* ' . $q['prompt'] . "\n\n";
            }
        }
        return $out;
    }

    /**
     * The pages as a CSV table.
     *
     * @return string
     */
    public function pages_csv(): string {
        $rows = [['page', 'title', 'idea', 'action', 'outcome', 'recap', 'image_prompt', 'alt_text']];
        foreach ($this->pages as $page) {
            $rows[] = [(int)$page->pagenum, $page->title, $page->concepttext, $page->actiontext, $page->outcometext,
                $page->summarytext, $page->imageprompt, $page->alttext];
        }
        return self::csv($rows);
    }

    /**
     * The lesson's text as a simple Word document (headings and paragraphs).
     *
     * @return string the .docx bytes
     */
    public function docx(): string {
        $x = fn(string $text): string => htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $p = fn(string $text, string $style = ''): string => '<w:p>' . ($style !== '' ? '<w:pPr><w:pStyle w:val="' . $style .
            '"/></w:pPr>' : '') . '<w:r><w:t xml:space="preserve">' . $x($text) . '</w:t></w:r></w:p>';
        $label = fn(string $name, string $text): string => '<w:p><w:r><w:rPr><w:b/></w:rPr><w:t xml:space="preserve">' .
            $x($name . ': ') . '</w:t></w:r><w:r><w:t xml:space="preserve">' . $x($text) . '</w:t></w:r></w:p>';
        $body = $p(format_string($this->book->name, true, ['context' => $this->context]), 'Title');
        foreach ($this->pages as $page) {
            $body .= $p((int)$page->pagenum . '. ' . $page->title, 'Heading1');
            foreach (VISUALLEARNING_CARDS as $card) {
                $body .= $label(get_string('card_' . $card, 'mod_visuallearning'), (string)$page->{$card . 'text'});
            }
            if (trim((string)$page->summarytext) !== '') {
                $body .= $label(get_string('summarytext', 'mod_visuallearning'), (string)$page->summarytext);
            }
            if (($q = $this->book->enablequiz ? question::of_page($page) : null) !== null) {
                $body .= $label(get_string('qtype_' . $q['type'], 'mod_visuallearning'), $q['prompt']);
            }
        }
        $w = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';
        $parts = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.' .
                'openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.' .
                'openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>' .
                '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.' .
                'wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.' .
                'openxmlformats-officedocument.wordprocessingml.styles+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.' .
                'openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.' .
                'org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>',
            'word/_rels/document.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships ' .
                'xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" ' .
                'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' .
                '</Relationships>',
            'word/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles ' . $w . '>' .
                '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:pPr><w:spacing ' .
                'w:after="120"/></w:pPr><w:rPr><w:sz w:val="22"/></w:rPr></w:style>' .
                '<w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/><w:basedOn w:val="Normal"/><w:rPr>' .
                '<w:b/><w:sz w:val="48"/></w:rPr></w:style>' .
                '<w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:basedOn w:val="Normal"/>' .
                '<w:pPr><w:keepNext/><w:spacing w:before="360" w:after="120"/><w:outlineLvl w:val="0"/></w:pPr><w:rPr>' .
                '<w:b/><w:sz w:val="32"/></w:rPr></w:style></w:styles>',
            'word/document.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document ' . $w . '><w:body>' .
                $body . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1440" w:right="1440" w:bottom="1440" ' .
                'w:left="1440" w:header="708" w:footer="708" w:gutter="0"/></w:sectPr></w:body></w:document>',
        ];
        $dir = make_request_directory();
        $files = [];
        foreach ($parts as $name => $content) {
            $files[$name] = [$content];
        }
        $path = $dir . '/book.docx';
        get_file_packer('application/zip')->archive_to_pathname($files, $path);
        return file_get_contents($path);
    }

    /**
     * Ready-made prompts with the lesson's content inside, for making more activities with any AI assistant.
     *
     * @return array file name => Markdown
     */
    public function prompts(): array {
        $content = '';
        foreach ($this->pages as $page) {
            $content .= '- ' . $page->title . ': ' . $page->concepttext . ' ' . $page->actiontext . ' ' . $page->outcometext . "\n";
        }
        $title = format_string($this->book->name, true, ['context' => $this->context]);
        $language = voices::language_name((string)$this->book->language);
        $out = [];
        foreach (['quizzes', 'scenarios', 'flashcards'] as $kind) {
            $out['prompts/' . get_string('export_prompt_file_' . $kind, 'mod_visuallearning') . '.md'] =
                '# ' . get_string('export_prompt_title_' . $kind, 'mod_visuallearning') . "\n\n" .
                get_string('export_prompt_' . $kind, 'mod_visuallearning', (object)['title' => $title,
                    'language' => $language]) . "\n\n## " . get_string('export_prompt_content', 'mod_visuallearning') .
                "\n\n" . $content;
        }
        return $out;
    }

    /**
     * Every file of the ZIP: path => content string, or path => stored_file.
     *
     * @return array
     */
    public function files(): array {
        $files = [];
        $slug = self::slug(format_string($this->book->name, true, ['context' => $this->context]));
        $files['README.txt'] = get_string('export_readme', 'mod_visuallearning', (object)[
            'title' => format_string($this->book->name, true, ['context' => $this->context]),
            'pages' => count($this->pages),
            'questions' => count($this->questions()),
            'date' => userdate(time()),
        ]);
        if ($this->questions()) {
            $files['questions/moodle-question-bank.xml'] = $this->moodle_xml();
            $files['questions/questions.gift.txt'] = $this->gift();
            $files['questions/questions.csv'] = $this->questions_csv();
        }
        $files['lesson/' . $slug . '.docx'] = $this->docx();
        $files['lesson/' . $slug . '.md'] = $this->markdown();
        $files['lesson/pages.csv'] = $this->pages_csv();
        if ($cover = media::image_file($this->context, media::AREA_COVER, 0)) {
            $files['pictures/cover.' . pathinfo($cover->get_filename(), PATHINFO_EXTENSION)] = $cover;
        }
        $transcript = '';
        $position = 0;
        foreach ($this->pages as $page) {
            $num = sprintf('%02d', (int)$page->pagenum);
            $stem = 'page-' . $num . '-' . self::slug((string)$page->title);
            if ($image = media::image_file($this->context, media::AREA_IMAGE, (int)$page->id)) {
                $files['pictures/' . $stem . '.' . pathinfo($image->get_filename(), PATHINFO_EXTENSION)] = $image;
            }
            for ($i = 0; $i < question::PICK_OPTIONS; $i++) {
                if ($option = media::option_file($this->context, (int)$page->id, $i)) {
                    $files['pictures/page-' . $num . '-option-' . chr(97 + $i) . '.' .
                        pathinfo($option->get_filename(), PATHINFO_EXTENSION)] = $option;
                }
            }
            $transcript .= get_string('pagex', 'mod_visuallearning', (int)$page->pagenum) . ': ' . $page->title . "\n";
            foreach (media::clips($this->book, $page, $position++) as $part => $text) {
                $transcript .= '  [' . get_string('clip_' . $part, 'mod_visuallearning') . '] ' . $text . "\n";
                if ($clip = media::clip_file($this->context, $this->book, $page, $part, $text)) {
                    $files['voiceover/page-' . $num . '-' . $part . '.mp3'] = $clip;
                }
            }
            $transcript .= "\n";
        }
        $files['voiceover/transcript.txt'] = $transcript;
        return $files + $this->prompts();
    }

    /**
     * Builds the ZIP.
     *
     * @return string path to the ZIP file (in a request directory, removed after the request)
     */
    public function zip(): string {
        $files = [];
        foreach ($this->files() as $path => $content) {
            $files[$path] = is_string($content) ? [$content] : $content;
        }
        $zip = make_request_directory() . '/content.zip';
        get_file_packer('application/zip')->archive_to_pathname($files, $zip);
        return $zip;
    }
}
