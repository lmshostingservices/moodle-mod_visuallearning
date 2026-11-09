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
 * Paste-back form for the ChatGPT JSON response.
 *
 * @package    mod_visuallearning
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class import_form extends \moodleform {
    /**
     * Definition.
     */
    protected function definition() {
        $mform = $this->_form;
        $mform->addElement('hidden', 'id', $this->_customdata['cmid']);
        $mform->setType('id', PARAM_INT);

        $mform->addElement(
            'textarea',
            'response',
            get_string('pasteresponse', 'mod_visuallearning'),
            ['rows' => 14, 'cols' => 80, 'style' => 'width:100%;font-family:monospace;font-size:.85rem',
            'placeholder' => '{ "book_title": "...", "pages": [ ... ] }']
        );
        // Plain JSON text; every value is cleaned again with PARAM_TEXT by the importer.
        $mform->setType('response', PARAM_TEXT);
        $mform->addRule('response', null, 'required', null, 'client');

        if (!empty($this->_customdata['haspages'])) {
            $mform->addElement('advcheckbox', 'confirmreplace', '', get_string('confirmreplace', 'mod_visuallearning'));
        }
        // Free: the pages and their price are shown for confirmation before anything is charged or created.
        $this->add_action_buttons(false, get_string('importcheck', 'mod_visuallearning'));
    }

    /**
     * Validation: parse the JSON here so errors appear on the form.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (!empty($this->_customdata['haspages']) && empty($data['confirmreplace'])) {
            $errors['confirmreplace'] = get_string('required');
        }
        [$parsed, $parseerrors] = \mod_visuallearning\local\importer::parse(
            (string)($data['response'] ?? ''),
            (int)$this->_customdata['maxpages']
        );
        if (!$parsed) {
            $errors['response'] = implode('<br>', array_map('s', array_slice($parseerrors, 0, 12)));
        }
        return $errors;
    }
}
