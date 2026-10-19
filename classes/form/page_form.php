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

namespace mod_visuallearning\form;

use mod_visuallearning\local\question;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->libdir . '/formslib.php');

/**
 * Edit one page (or add a new one).
 *
 * @package    mod_visuallearning
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class page_form extends \moodleform {
    /**
     * Definition.
     */
    protected function definition() {
        $mform = $this->_form;
        $mform->addElement('hidden', 'id', $this->_customdata['cmid']);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'pageid', 0);
        $mform->setType('pageid', PARAM_INT);

        $mform->addElement('text', 'title', get_string('pagetitle', 'mod_visuallearning'), ['size' => 60]);
        $mform->setType('title', PARAM_TEXT);
        $mform->addRule('title', null, 'required', null, 'client');
        $mform->addRule('title', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        // Each card is read as one voiceover clip.
        $max = \mod_visuallearning\local\ai\lmslabs::MAX_CLIP;
        $approach = \mod_visuallearning\local\approaches::get($this->_customdata['approach'] ?? null);
        foreach (VISUALLEARNING_CARDS as $card) {
            $mform->addElement(
                'textarea',
                $card . 'text',
                get_string($approach->card_string($card), 'mod_visuallearning'),
                ['rows' => 3, 'cols' => 70, 'style' => 'width:100%']
            );
            $mform->setType($card . 'text', PARAM_TEXT);
            $mform->addRule($card . 'text', null, 'required', null, 'client');
            $mform->addRule($card . 'text', get_string('maximumchars', '', $max), 'maxlength', $max, 'client');
            $mform->addHelpButton($card . 'text', $approach->card_string($card), 'mod_visuallearning');
        }

        $mform->addElement('text', 'summarytext', get_string('summarytext', 'mod_visuallearning'), ['size' => 80]);
        $mform->setType('summarytext', PARAM_TEXT);
        $mform->addHelpButton('summarytext', 'summarytext', 'mod_visuallearning');
        $mform->addRule('summarytext', get_string('maximumchars', '', $max - 4), 'maxlength', $max - 4, 'client');

        // The check question: one per page, in one of four types.
        $mform->addElement('header', 'questionhdr', get_string('questionhdr', 'mod_visuallearning'));
        $types = ['' => get_string('qtype_none', 'mod_visuallearning')];
        foreach (question::TYPES as $type) {
            $types[$type] = get_string('qtype_' . $type, 'mod_visuallearning');
        }
        $mform->addElement('select', 'qtype', get_string('qtype', 'mod_visuallearning'), $types);
        $mform->addHelpButton('qtype', 'qtype', 'mod_visuallearning');

        $mform->addElement('text', 'tfstatement', get_string('tfstatement', 'mod_visuallearning'), ['size' => 80]);
        $mform->setType('tfstatement', PARAM_TEXT);
        $mform->addHelpButton('tfstatement', 'tfstatement', 'mod_visuallearning');
        $mform->addRule(
            'tfstatement',
            get_string('maximumchars', '', question::MAX_PROMPT),
            'maxlength',
            question::MAX_PROMPT,
            'client'
        );
        $mform->hideIf('tfstatement', 'qtype', 'eq', '');

        $mform->addElement(
            'select',
            'tfanswer',
            get_string('tfanswer', 'mod_visuallearning'),
            [
                1 => get_string('true', 'mod_visuallearning'),
                0 => get_string('false', 'mod_visuallearning'),
            ],
        );
        $mform->hideIf('tfanswer', 'qtype', 'neq', question::TRUEFALSE);

        $letters = [];
        for ($i = 0; $i < question::PICK_OPTIONS; $i++) {
            $letter = chr(65 + $i);
            $letters[$i] = $letter;
            $mform->addElement('text', 'option' . $i, get_string('optionlabel', 'mod_visuallearning', $letter), ['size' => 50]);
            $mform->setType('option' . $i, PARAM_TEXT);
            $mform->hideIf('option' . $i, 'qtype', 'neq', question::PICK);
            $mform->addElement(
                'textarea',
                'optionprompt' . $i,
                get_string('optionprompt', 'mod_visuallearning', $letter),
                ['rows' => 2, 'cols' => 70, 'style' => 'width:100%']
            );
            $mform->setType('optionprompt' . $i, PARAM_TEXT);
            $mform->hideIf('optionprompt' . $i, 'qtype', 'neq', question::PICK);
        }
        $mform->addElement('select', 'correct', get_string('correctoption', 'mod_visuallearning'), $letters);
        $mform->hideIf('correct', 'qtype', 'neq', question::PICK);

        for ($i = 0; $i < 4; $i++) {
            $group = [
                $mform->createElement('text', 'left' . $i, '', ['size' => 30,
                    'placeholder' => get_string('pairleft', 'mod_visuallearning')]),
                $mform->createElement('text', 'right' . $i, '', ['size' => 30,
                    'placeholder' => get_string('pairright', 'mod_visuallearning')]),
            ];
            $mform->addGroup($group, 'pair' . $i, get_string('pairx', 'mod_visuallearning', $i + 1), ' ', false);
            $mform->setType('left' . $i, PARAM_TEXT);
            $mform->setType('right' . $i, PARAM_TEXT);
            $mform->hideIf('pair' . $i, 'qtype', 'neq', question::MATCH);
        }

        for ($g = 0; $g < 2; $g++) {
            $mform->addElement('text', 'group' . $g, get_string('groupname', 'mod_visuallearning', $g + 1), ['size' => 40]);
            $mform->setType('group' . $g, PARAM_TEXT);
            $mform->hideIf('group' . $g, 'qtype', 'neq', question::SORT);
        }
        $groupchoice = [0 => get_string('groupx', 'mod_visuallearning', 1), 1 => get_string('groupx', 'mod_visuallearning', 2)];
        for ($i = 0; $i < 6; $i++) {
            $group = [
                $mform->createElement('text', 'item' . $i, '', ['size' => 40]),
                $mform->createElement('select', 'itemgroup' . $i, '', $groupchoice),
            ];
            $mform->addGroup($group, 'sortitem' . $i, get_string('itemx', 'mod_visuallearning', $i + 1), ' ', false);
            $mform->setType('item' . $i, PARAM_TEXT);
            $mform->hideIf('sortitem' . $i, 'qtype', 'neq', question::SORT);
        }

        $mform->addElement('text', 'feedback', get_string('feedback', 'mod_visuallearning'), ['size' => 80]);
        $mform->setType('feedback', PARAM_TEXT);
        $mform->addHelpButton('feedback', 'feedback', 'mod_visuallearning');
        $mform->addRule(
            'feedback',
            get_string('maximumchars', '', question::MAX_PROMPT),
            'maxlength',
            question::MAX_PROMPT,
            'client'
        );
        $mform->hideIf('feedback', 'qtype', 'eq', '');

        $mform->addElement('header', 'picturehdr', get_string('picturehdr', 'mod_visuallearning'));
        $mform->addElement(
            'textarea',
            'imageprompt',
            get_string('imageprompt', 'mod_visuallearning'),
            ['rows' => 5, 'cols' => 70, 'style' => 'width:100%']
        );
        $mform->setType('imageprompt', PARAM_TEXT);
        $mform->addRule('imageprompt', null, 'required', null, 'client');
        $mform->addHelpButton('imageprompt', 'imageprompt', 'mod_visuallearning');

        $mform->addElement('text', 'alttext', get_string('alttext', 'mod_visuallearning'), ['size' => 60]);
        $mform->setType('alttext', PARAM_TEXT);

        // One picture per card: three prompts, 2 and 3 edit the picture before.
        $mode = \mod_visuallearning\local\picture_modes::clean($this->_customdata['picturemode'] ?? null);
        if ($mode === \mod_visuallearning\local\picture_modes::SEQUENCE) {
            for ($i = 1; $i <= \mod_visuallearning\local\picture_modes::CARDS; $i++) {
                $mform->addElement(
                    'textarea',
                    'cardprompt' . $i,
                    get_string('cardprompt', 'mod_visuallearning', $i),
                    ['rows' => $i === 1 ? 4 : 2, 'cols' => 70, 'style' => 'width:100%']
                );
                $mform->setType('cardprompt' . $i, PARAM_TEXT);
            }
            $mform->addHelpButton('cardprompt1', 'cardprompt', 'mod_visuallearning');
        }
        // Animated diagram: its specification (JSON), checked when saved.
        if ($mode === \mod_visuallearning\local\picture_modes::ANIMATED) {
            $mform->addElement(
                'textarea',
                'diagram',
                get_string('diagramspec', 'mod_visuallearning'),
                ['rows' => 8, 'cols' => 70, 'style' => 'width:100%;font-family:monospace']
            );
            $mform->setType('diagram', PARAM_RAW); // pipeline-ignore: PARAM_RAW — JSON decoded and allowlist-validated by diagram::clean() in validation() and on save.
            $mform->addHelpButton('diagram', 'diagramspec', 'mod_visuallearning');
        }

        $this->add_action_buttons(true, get_string('savechanges'));
    }

    /**
     * Validation: a question with a type must be complete.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (($data['qtype'] ?? '') !== '' && question::from_form((object)$data) === null) {
            $errors['qtype'] = get_string('question_incomplete_' . $data['qtype'], 'mod_visuallearning');
        }
        if (trim((string)($data['diagram'] ?? '')) !== '' && \mod_visuallearning\local\diagram::clean($data['diagram']) === null) {
            $errors['diagram'] = get_string('diagramspec_invalid', 'mod_visuallearning');
        }
        if (array_key_exists('cardprompt1', $data)) {
            $filled = array_filter([$data['cardprompt1'] ?? '', $data['cardprompt2'] ?? '', $data['cardprompt3'] ?? ''],
                fn($t) => trim((string)$t) !== '');
            if ($filled && count($filled) < \mod_visuallearning\local\picture_modes::CARDS) {
                $errors['cardprompt1'] = get_string('cardprompt_all', 'mod_visuallearning');
            }
        }
        return $errors;
    }
}
