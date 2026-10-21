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
 * Restore structure.
 *
 * @package    mod_visuallearning
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Restore structure step.
 */
class restore_visuallearning_activity_structure_step extends restore_activity_structure_step {
    /**
     * Paths.
     *
     * @return array
     */
    protected function define_structure() {
        $paths = [];
        $paths[] = new restore_path_element('visuallearning', '/activity/visuallearning');
        $paths[] = new restore_path_element('visuallearning_page', '/activity/visuallearning/pages/page');
        if ($this->get_setting_value('userinfo')) {
            $paths[] = new restore_path_element('visuallearning_progress', '/activity/visuallearning/progresses/progress');
        }
        return $this->prepare_activity_structure($paths);
    }

    /**
     * Book.
     *
     * @param array $data
     */
    protected function process_visuallearning($data) {
        global $DB;
        $data = (object)$data;
        $data->course = $this->get_courseid();
        // Queued work does not survive a restore.
        if ((int)$data->coverstatus === 1) {
            $data->coverstatus = 0;
        }
        // A scale grade points at the restored scale; older backups have no grade and get the default (100).
        if (isset($data->grade) && (int)$data->grade < 0) {
            $data->grade = -(int)$this->get_mappingid('scale', abs((int)$data->grade));
        }
        $newid = $DB->insert_record('visuallearning', $data);
        $this->apply_activity_instance($newid);
    }

    /**
     * Page.
     *
     * @param array $data
     */
    protected function process_visuallearning_page($data) {
        global $DB;
        $data = (object)$data;
        $oldid = $data->id;
        $data->visuallearningid = $this->get_new_parentid('visuallearning');
        foreach (['imagestatus', 'audiostatus'] as $field) {
            if ((int)$data->$field === 1) {
                $data->$field = 0;
            }
        }
        $newid = $DB->insert_record('visuallearning_pages', $data);
        $this->set_mapping('visuallearning_page', $oldid, $newid, true);
    }

    /**
     * Progress.
     *
     * @param array $data
     */
    protected function process_visuallearning_progress($data) {
        global $DB;
        $data = (object)$data;
        $data->visuallearningid = $this->get_new_parentid('visuallearning');
        $data->pageid = $this->get_mappingid('visuallearning_page', $data->pageid);
        $data->userid = $this->get_mappingid('user', $data->userid);
        if ($data->pageid && $data->userid) {
            $DB->insert_record('visuallearning_progress', $data);
        }
    }

    /**
     * Files.
     */
    protected function after_execute() {
        $this->add_related_files('mod_visuallearning', 'intro', null);
        $this->add_related_files('mod_visuallearning', 'cover', null);
        $this->add_related_files('mod_visuallearning', 'image', 'visuallearning_page');
        $this->add_related_files('mod_visuallearning', 'audio', 'visuallearning_page');
        $this->add_related_files('mod_visuallearning', 'option', 'visuallearning_page');
        $this->add_related_files('mod_visuallearning', 'card', 'visuallearning_page');
        $this->add_related_files('mod_visuallearning', 'svg', 'visuallearning_page');
    }
}
