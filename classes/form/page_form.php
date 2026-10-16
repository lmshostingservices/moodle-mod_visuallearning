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
        foreach (VISUALLEARNING_CARDS as $card) {
            $mform->addElement(
                'textarea',
                $card . 'text',
                get_string('card_' . $card, 'mod_visuallearning'),
                ['rows' => 3, 'cols' => 70, 'style' => 'width:100%']
            );
            $mform->setType($card . 'text', PARAM_TEXT);
            $mform->addRule($card . 'text', null, 'required', null, 'client');
            $mform->addRule($card . 'text', get_string('maximumchars', '', $max), 'maxlength', $max, 'client');
            $mform->addHelpButton($card . 'text', 'card_' . $card, 'mod_visuallearning');
        }

        $mform->addElement('text', 'summarytext', get_string('summarytext', 'mod_visuallearning'), ['size' => 80]);
        $mform->setType('summarytext', PARAM_TEXT);
        $mform->addHelpButton('summarytext', 'summarytext', 'mod_visuallearning');
        $mform->addRule('summarytext', get_string('maximumchars', '', $max - 4), 'maxlength', $max - 4, 'client');

        $mform->addElement('text', 'tfstatement', get_string('tfstatement', 'mod_visuallearning'), ['size' => 80]);
        $mform->setType('tfstatement', PARAM_TEXT);
        $mform->addHelpButton('tfstatement', 'tfstatement', 'mod_visuallearning');
        $mform->addRule('tfstatement', get_string('maximumchars', '', $max), 'maxlength', $max, 'client');
        $mform->addElement(
            'select',
            'tfanswer',
            get_string('tfanswer', 'mod_visuallearning'),
            [
                1 => get_string('true', 'mod_visuallearning'),
                0 => get_string('false', 'mod_visuallearning'),
            ],
        );

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

        $this->add_action_buttons(true, get_string('savechanges'));
    }
}
