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

use mod_visuallearning\local\media;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->libdir . '/formslib.php');

/**
 * A teacher's own HTML for one page: pasted or uploaded (for example a file saved from Claude).
 *
 * @package    mod_visuallearning
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class customhtml_form extends \moodleform {
    /**
     * Definition.
     */
    protected function definition() {
        $mform = $this->_form;
        $mform->addElement('hidden', 'id', $this->_customdata['cmid']);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'pageid', $this->_customdata['pageid']);
        $mform->setType('pageid', PARAM_INT);

        $mform->addElement(
            'filepicker',
            'htmlfile',
            get_string('customhtml_upload', 'mod_visuallearning'),
            null,
            ['accepted_types' => ['.html', '.htm'], 'maxbytes' => media::HTML_MAX_BYTES]
        );
        $mform->addHelpButton('htmlfile', 'customhtml_upload', 'mod_visuallearning');

        $mform->addElement(
            'textarea',
            'html',
            get_string('customhtml_paste', 'mod_visuallearning'),
            ['rows' => 14, 'cols' => 80, 'class' => 'vl-code', 'spellcheck' => 'false', 'wrap' => 'off']
        );
        // Raw on purpose: the HTML is never shown on a Moodle page, only in a sandboxed frame (see media::save_html()).
        $mform->setType('html', PARAM_RAW); // pipeline-ignore: PARAM_RAW — stored as a file, only ever served with a CSP sandbox header into a sandboxed iframe.
        $mform->addHelpButton('html', 'customhtml_paste', 'mod_visuallearning');

        $mform->addElement('text', 'alttext', get_string('customhtml_alt', 'mod_visuallearning'), ['size' => 80]);
        $mform->setType('alttext', PARAM_TEXT);
        $mform->addRule('alttext', null, 'required', null, 'client');
        $mform->addHelpButton('alttext', 'customhtml_alt', 'mod_visuallearning');

        $this->add_action_buttons(true, get_string('customhtml_save', 'mod_visuallearning'));
    }

    /**
     * The uploaded file wins over the pasted HTML; one of them is needed, and it must be a usable HTML document.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $problem = media::html_problem(self::html_from($data));
        if ($problem !== null) {
            $errors['html'] = get_string($problem, 'mod_visuallearning', display_size(media::HTML_MAX_BYTES));
        }
        return $errors;
    }

    /**
     * The HTML to save: the uploaded file, or else what was pasted.
     *
     * @return string
     */
    public function html(): string {
        return self::html_from((array)$this->get_data());
    }

    /**
     * The uploaded file (in the user's draft area) or else the pasted HTML, from submitted form data. Read straight
     * from the draft area: moodleform::get_file_content() validates the form again, which would loop from validation().
     *
     * @param array $data
     * @return string
     */
    protected static function html_from(array $data): string {
        global $USER;
        $draftid = (int)($data['htmlfile'] ?? 0);
        if ($draftid) {
            $files = get_file_storage()->get_area_files(
                \context_user::instance($USER->id)->id,
                'user',
                'draft',
                $draftid,
                'id DESC',
                false
            );
            if ($files) {
                $content = reset($files)->get_content();
                if (trim($content) !== '') {
                    return $content;
                }
            }
        }
        return (string)($data['html'] ?? '');
    }
}
