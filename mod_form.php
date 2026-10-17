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

/**
 * Activity settings form.
 *
 * @package    mod_visuallearning
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

use mod_visuallearning\local\voices;

/**
 * AI Visual Learning settings form.
 */
class mod_visuallearning_mod_form extends moodleform_mod {
    /**
     * Form definition.
     */
    public function definition() {
        $mform = $this->_form;
        $config = get_config('mod_visuallearning');

        $mform->addElement('header', 'general', get_string('general', 'form'));
        $mform->addElement('text', 'name', get_string('booktitle', 'mod_visuallearning'), ['size' => 64]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        $this->standard_intro_elements();

        // Content.
        $mform->addElement('header', 'contentheader', get_string('contentheader', 'mod_visuallearning'));
        $mform->setExpanded('contentheader');

        $mform->addElement('text', 'industry', get_string('industry', 'mod_visuallearning'), ['size' => 48]);
        $mform->setType('industry', PARAM_TEXT);
        $mform->addHelpButton('industry', 'industry', 'mod_visuallearning');

        $mform->addElement('text', 'subindustry', get_string('subindustry', 'mod_visuallearning'), ['size' => 48]);
        $mform->setType('subindustry', PARAM_TEXT);
        $mform->addHelpButton('subindustry', 'subindustry', 'mod_visuallearning');

        $mform->addElement(
            'textarea',
            'sourcecontent',
            get_string('sourcecontent', 'mod_visuallearning'),
            ['rows' => 16, 'cols' => 80, 'style' => 'width:100%']
        );
        $mform->setType('sourcecontent', PARAM_TEXT);
        $mform->addRule('sourcecontent', null, 'required', null, 'client');
        $mform->addHelpButton('sourcecontent', 'sourcecontent', 'mod_visuallearning');

        $pages = array_combine(range(3, 20), range(3, 20));
        $mform->addElement('select', 'maxpages', get_string('maxpages', 'mod_visuallearning'), $pages);
        $mform->setDefault('maxpages', $config->defaultmaxpages ?? 20);
        $mform->addHelpButton('maxpages', 'maxpages', 'mod_visuallearning');

        // Voice and visuals.
        $mform->addElement('header', 'voiceheader', get_string('voiceheader', 'mod_visuallearning'));
        $mform->setExpanded('voiceheader');

        $mform->addElement('select', 'language', get_string('language', 'mod_visuallearning'), voices::language_options());
        $mform->setDefault('language', $config->defaultlanguage ?? 'en-AU');
        $mform->addHelpButton('language', 'language', 'mod_visuallearning');

        $mform->addElement(
            'select',
            'imageculture',
            get_string('imageculture', 'mod_visuallearning'),
            [
                'language' => get_string('imageculture_language', 'mod_visuallearning'),
                'international' => get_string('imageculture_international', 'mod_visuallearning'),
            ],
        );
        $mform->addHelpButton('imageculture', 'imageculture', 'mod_visuallearning');

        // Only voices LMS Labs offers (its published catalogue); the voice already chosen is kept.
        $default = (string)($config->defaultvoice ?? 'Aoede');
        $current = (string)($this->current->voice ?? $default);
        $mform->addElement('select', 'voice', get_string('voice', 'mod_visuallearning'), voices::voice_options('', $current));
        $mform->setDefault('voice', $default);
        $mform->addHelpButton('voice', 'voice', 'mod_visuallearning');

        $rates = ['0.90' => get_string('speed_slow', 'mod_visuallearning'),
            '1.00' => get_string('speed_normal', 'mod_visuallearning'),
            '1.10' => get_string('speed_fast', 'mod_visuallearning')];
        $mform->addElement('select', 'speakingrate', get_string('speakingrate', 'mod_visuallearning'), $rates);
        $mform->setDefault('speakingrate', '1.00');

        $mform->addElement(
            'textarea',
            'imagestyle',
            get_string('imagestyle', 'mod_visuallearning'),
            ['rows' => 4, 'cols' => 80, 'style' => 'width:100%']
        );
        $mform->setType('imagestyle', PARAM_TEXT);
        $mform->setDefault('imagestyle', $config->defaultimagestyle ?? get_string('defaultimagestyle', 'mod_visuallearning'));
        $mform->addHelpButton('imagestyle', 'imagestyle', 'mod_visuallearning');

        $mform->addElement(
            'select',
            'navmode',
            get_string('navmode', 'mod_visuallearning'),
            [
                VISUALLEARNING_NAV_LINEAR => get_string('navmode_linear', 'mod_visuallearning'),
                VISUALLEARNING_NAV_FREE => get_string('navmode_free', 'mod_visuallearning'),
            ],
        );
        $mform->addHelpButton('navmode', 'navmode', 'mod_visuallearning');

        $mform->addElement(
            'advcheckbox',
            'autoplay',
            get_string('autoplay', 'mod_visuallearning'),
            get_string('autoplay_label', 'mod_visuallearning')
        );
        $mform->setDefault('autoplay', 0);
        $mform->addHelpButton('autoplay', 'autoplay', 'mod_visuallearning');

        // Recap and checks.
        $mform->addElement('header', 'reviewheader', get_string('reviewheader', 'mod_visuallearning'));
        $mform->setExpanded('reviewheader');
        $mform->addElement(
            'advcheckbox',
            'enablerecap',
            get_string('enablerecap', 'mod_visuallearning'),
            get_string('enablerecap_label', 'mod_visuallearning')
        );
        $mform->setDefault('enablerecap', 1);
        $mform->addHelpButton('enablerecap', 'enablerecap', 'mod_visuallearning');
        $mform->addElement(
            'advcheckbox',
            'enablequiz',
            get_string('enablequiz', 'mod_visuallearning'),
            get_string('enablequiz_label', 'mod_visuallearning')
        );
        $mform->setDefault('enablequiz', 1);
        $mform->addHelpButton('enablequiz', 'enablequiz', 'mod_visuallearning');

        // Which kinds of check question the AI may write (it rotates between them).
        $types = [];
        foreach (\mod_visuallearning\local\question::TYPES as $type) {
            $types[] = $mform->createElement('advcheckbox', 'qt_' . $type, '', get_string('qtype_' . $type, 'mod_visuallearning'));
        }
        $mform->addGroup($types, 'questiontypesgroup', get_string('questiontypes', 'mod_visuallearning'), '<br>', false);
        $mform->addHelpButton('questiontypesgroup', 'questiontypes', 'mod_visuallearning');
        foreach (\mod_visuallearning\local\question::TYPES as $type) {
            $mform->setDefault('qt_' . $type, 1);
        }
        $mform->hideIf('questiontypesgroup', 'enablequiz', 'notchecked');

        $mform->addElement(
            'advcheckbox',
            'pickimages',
            get_string('pickimages', 'mod_visuallearning'),
            get_string('pickimages_label', 'mod_visuallearning', \mod_visuallearning\local\ai\lmslabs::IMAGE_CREDITS)
        );
        $mform->setDefault('pickimages', 1);
        $mform->addHelpButton('pickimages', 'pickimages', 'mod_visuallearning');
        $mform->hideIf('pickimages', 'enablequiz', 'notchecked');
        $mform->hideIf('pickimages', 'qt_pick', 'notchecked');

        $mform->addElement(
            'advcheckbox',
            'sounds',
            get_string('sounds', 'mod_visuallearning'),
            get_string('sounds_label', 'mod_visuallearning')
        );
        $mform->setDefault('sounds', 1);
        $mform->addHelpButton('sounds', 'sounds', 'mod_visuallearning');

        // Grade: the share of check questions answered correctly in the end (all right = 100%).
        $this->standard_grading_coursemodule_elements();
        $mform->setDefault('grade', 100);
        if (empty($this->_instance) && $mform->elementExists('gradepass')) {
            $mform->setDefault('gradepass', 100);
        }

        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    /**
     * Normalise the speaking rate for the select, and spread the question types over their checkboxes.
     *
     * @param array $defaultvalues
     */
    public function data_preprocessing(&$defaultvalues) {
        if (isset($defaultvalues['questiontypes'])) {
            $chosen = explode(',', (string)$defaultvalues['questiontypes']);
            foreach (\mod_visuallearning\local\question::TYPES as $type) {
                $defaultvalues['qt_' . $type] = in_array($type, $chosen, true) ? 1 : 0;
            }
        }
        if (isset($defaultvalues['speakingrate'])) {
            $rate = (float)$defaultvalues['speakingrate'];
            $defaultvalues['speakingrate'] = $rate < 0.95 ? '0.90' : ($rate > 1.05 ? '1.10' : '1.00');
        }
    }

    /**
     * Checks the voice against the LMS Labs voice catalogue, when LMS Labs has published one.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        \mod_visuallearning\local\ai\lmslabs::$fetch = false;
        try {
            $catalogue = \mod_visuallearning\local\ai\lmslabs::voice_catalogue();
        } finally {
            \mod_visuallearning\local\ai\lmslabs::$fetch = true;
        }
        if (!empty($data['enablequiz'])) {
            $any = false;
            foreach (\mod_visuallearning\local\question::TYPES as $type) {
                $any = $any || !empty($data['qt_' . $type]);
            }
            if (!$any) {
                $errors['questiontypesgroup'] = get_string('questiontypes_none', 'mod_visuallearning');
            }
        }
        $language = (string)($data['language'] ?? '');
        $voice = (string)($data['voice'] ?? '');
        if ($catalogue && empty($catalogue[$language])) {
            $errors['language'] = get_string('novoicesforlanguage', 'mod_visuallearning');
        } else if ($catalogue && empty($catalogue[$language][$voice])) {
            $errors['voice'] = get_string(
                'voicenotoffered',
                'mod_visuallearning',
                implode(', ', array_keys($catalogue[$language]))
            );
        }
        return $errors;
    }

    /**
     * Custom completion rules.
     *
     * @return array
     */
    public function add_completion_rules() {
        $mform = $this->_form;
        $suffix = $this->get_suffix();
        $element = 'completionlistened' . $suffix;
        $mform->addElement('advcheckbox', $element, '', get_string('completionlistened_label', 'mod_visuallearning'));
        $mform->setDefault($element, 1);
        $mform->addHelpButton($element, 'completionlistened', 'mod_visuallearning');

        $quizelement = 'completionquiz' . $suffix;
        $mform->addElement('advcheckbox', $quizelement, '', get_string('completionquiz_label', 'mod_visuallearning'));
        $mform->setDefault($quizelement, 0);
        $mform->addHelpButton($quizelement, 'completionquiz', 'mod_visuallearning');
        if ($mform->elementExists('enablequiz')) {
            $mform->disabledIf($quizelement, 'enablequiz', 'notchecked');
        }
        return [$element, $quizelement];
    }

    /**
     * Is the custom rule enabled?
     *
     * @param array $data
     * @return bool
     */
    public function completion_rule_enabled($data) {
        $suffix = $this->get_suffix();
        return !empty($data['completionlistened' . $suffix]) || !empty($data['completionquiz' . $suffix]);
    }

    /**
     * Turn off the rule when automatic completion is off.
     *
     * @param stdClass $data
     */
    public function data_postprocessing($data) {
        parent::data_postprocessing($data);
        if (!empty($data->completionunlocked)) {
            $suffix = $this->get_suffix();
            $auto = !empty($data->{'completion' . $suffix})
                && $data->{'completion' . $suffix} == COMPLETION_TRACKING_AUTOMATIC;
            if (!$auto) {
                $data->{'completionlistened' . $suffix} = 0;
                $data->{'completionquiz' . $suffix} = 0;
            }
        }
    }
}
