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

namespace mod_visuallearning\local;

/**
 * Responsible AI (EU AI Act): teacher check of AI-made lessons, learner disclosure and AI provenance.
 *
 * Supports the transparency duties of Article 50 (disclosing and marking AI-generated content) and the deployer's
 * editorial control and human oversight. It is the provider's technical support for those duties, not legal advice.
 *
 * @package    mod_visuallearning
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class responsible_ai {
    /**
     * Whether learners are told which parts of a lesson AI made (site setting, on by default).
     *
     * @return bool
     */
    public static function disclosure_on(): bool {
        $value = get_config('mod_visuallearning', 'aidisclosure');
        return $value === false || $value === null || $value === '' || (bool)$value;
    }

    /**
     * Whether learners only see lessons a teacher has checked (site setting, off by default).
     *
     * @return bool
     */
    public static function review_required(): bool {
        return (bool)get_config('mod_visuallearning', 'requirereview');
    }

    /**
     * Whether all AI generation is paused on the site.
     *
     * @return bool
     */
    public static function paused(): bool {
        return (bool)get_config('mod_visuallearning', 'aipaused');
    }

    /**
     * Whether a teacher has checked the lesson since its pages were last written.
     *
     * @param \stdClass $book
     * @return bool
     */
    public static function is_reviewed(\stdClass $book): bool {
        return !empty($book->timereviewed);
    }

    /**
     * Whether learners must wait: the site requires a teacher check and this lesson has not had one.
     *
     * @param \stdClass $book
     * @return bool
     */
    public static function learners_wait(\stdClass $book): bool {
        return self::review_required() && !self::is_reviewed($book);
    }

    /**
     * Record that a teacher checked every page (editorial responsibility for the AI-made lesson).
     *
     * @param \stdClass $book
     * @param int $userid
     */
    public static function mark_reviewed(\stdClass $book, int $userid): void {
        global $DB;
        $book->reviewedby = $userid;
        $book->timereviewed = time();
        $DB->update_record('visuallearning', (object)['id' => $book->id, 'reviewedby' => $userid,
            'timereviewed' => $book->timereviewed]);
    }

    /**
     * Clear the teacher check: new AI pages need checking again, or the teacher withdrew it.
     *
     * @param int $bookid
     */
    public static function reset_review(int $bookid): void {
        global $DB;
        $DB->update_record('visuallearning', (object)['id' => $bookid, 'reviewedby' => 0, 'timereviewed' => 0]);
    }

    /**
     * The teacher who checked the lesson, for display ('' when nobody has, or they no longer exist).
     *
     * @param \stdClass $book
     * @return string
     */
    public static function reviewer_name(\stdClass $book): string {
        global $DB;
        if (!self::is_reviewed($book)) {
            return '';
        }
        $user = $DB->get_record('user', ['id' => $book->reviewedby, 'deleted' => 0]);
        return $user ? fullname($user) : '';
    }

    /**
     * Which kinds of AI-made content the lesson has: text always, plus pictures, animations and voice when present.
     *
     * @param array $pages Player pages from {@see book_data::player()}
     * @return string[] text, pictures, animations, voice
     */
    public static function parts(array $pages): array {
        $parts = ['text'];
        $cards = fn($p) => is_array($p['cards'] ?? null) ? $p['cards'] : [];
        $has = fn(callable $test) => (bool)array_filter($pages, $test);
        if ($has(fn($p) => !empty($p['image']) || array_filter($cards($p), fn($c) => !empty($c['image'])))) {
            $parts[] = 'pictures';
        }
        // A teacher's own HTML page is not made by AI; animated diagrams are.
        if ($has(fn($p) => !empty($p['diagram']) && ($p['diagram']['type'] ?? '') !== 'html_embed')) {
            $parts[] = 'animations';
        }
        if ($has(fn($p) => !empty($p['summaryaudio']) || array_filter($cards($p), fn($c) => !empty($c['audio'])))) {
            $parts[] = 'voice';
        }
        return $parts;
    }

    /**
     * What the player shows learners about AI in this lesson (null when the site switched the notice off).
     *
     * @param \stdClass $book
     * @param array $pages Player pages
     * @return array|null ['heading', 'text', 'checked', 'report']
     */
    public static function player_data(\stdClass $book, array $pages): ?array {
        if (!self::disclosure_on()) {
            return null;
        }
        $names = array_map(fn($part) => get_string('aipart_' . $part, 'mod_visuallearning'), self::parts($pages));
        $last = array_pop($names);
        $list = $names ? implode(', ', $names) . ' ' . get_string('aiand', 'mod_visuallearning') . ' ' . $last : $last;
        $reviewer = self::reviewer_name($book);
        $date = self::is_reviewed($book) ? userdate((int)$book->timereviewed, get_string('strftimedate', 'langconfig')) : '';
        if ($date === '') {
            $checked = '';
        } else if ($reviewer === '') {
            $checked = get_string('ainotice_checkedteacher', 'mod_visuallearning', $date);
        } else {
            $checked = get_string('ainotice_checked', 'mod_visuallearning', (object)['name' => $reviewer, 'date' => $date]);
        }
        return [
            'heading' => get_string('ainotice_heading', 'mod_visuallearning'),
            'text' => get_string('ainotice_text', 'mod_visuallearning', $list),
            'checked' => $checked,
            'report' => get_string('ainotice_report', 'mod_visuallearning'),
        ];
    }

    /**
     * The AI provenance record for "Download all content" (machine-readable, ai-provenance.json).
     *
     * @param \stdClass $book
     * @param string[] $files Paths in the download that hold AI-generated media
     * @return array
     */
    public static function provenance_manifest(\stdClass $book, array $files): array {
        return [
            'schema' => ai_marking::NS,
            'lesson' => format_string($book->name),
            'aiGenerated' => true,
            'digitalSourceType' => ai_marking::SOURCE_TYPE,
            'system' => ai_marking::SYSTEM,
            'provider' => ai_marking::PROVIDER,
            'generatedParts' => ['text: pages, cards, summaries and check questions written by AI from the teacher\'s content',
                'pictures, animated diagrams and narration made by AI'],
            'humanReview' => self::is_reviewed($book) ? [
                'checkedBy' => self::reviewer_name($book) ?: 'teacher',
                'checkedAt' => gmdate('Y-m-d\TH:i:s\Z', (int)$book->timereviewed),
            ] : null,
            'aiGeneratedFiles' => array_values($files),
            'notes' => 'Pictures, voice clips and animated diagrams also carry an IPTC "trainedAlgorithmicMedia" marking '
                . 'in their own metadata. Teacher-supplied HTML is not AI-generated by this plugin.',
        ];
    }
}
