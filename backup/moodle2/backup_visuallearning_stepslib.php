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
 * Backup structure.
 *
 * @package    mod_visuallearning
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Backup structure step.
 */
class backup_visuallearning_activity_structure_step extends backup_activity_structure_step {
    /**
     * Structure.
     *
     * @return backup_nested_element
     */
    protected function define_structure() {
        $userinfo = $this->get_setting_value('userinfo');

        $book = new backup_nested_element(
            'visuallearning',
            ['id'],
            [
                'name', 'intro', 'introformat', 'industry', 'subindustry', 'sourcecontent', 'maxpages',
                'language', 'voice', 'speakingrate', 'imagestyle', 'navmode', 'autoplay', 'imageculture',
                'enablerecap', 'enablequiz', 'questiontypes', 'pickimages', 'sounds', 'styleguide', 'characters', 'coverprompt',
                'coverstatus',
                'covererror', 'completionlistened', 'completionquiz', 'grade', 'timecreated', 'timemodified',
            ],
        );
        $pages = new backup_nested_element('pages');
        $page = new backup_nested_element(
            'page',
            ['id'],
            [
                'pagenum', 'title', 'concepttext', 'actiontext', 'outcometext', 'imageprompt', 'alttext',
                'summarytext', 'tfstatement', 'tfanswer', 'qtype', 'qdata', 'feedback', 'imagestatus', 'audiostatus', 'lasterror',
                'bundleid', 'slideid', 'bundlesite', 'bundledeliveries',
                'timemodified',
            ],
        );
        $progresses = new backup_nested_element('progresses');
        $progress = new backup_nested_element(
            'progress',
            ['id'],
            [
                'pageid', 'userid', 'views', 'listened', 'timespent', 'timefirstviewed', 'timelistened',
                'lastping', 'tfattempts', 'tfpassed', 'timepassed', 'relisten',
            ],
        );

        $book->add_child($pages);
        $pages->add_child($page);
        $book->add_child($progresses);
        $progresses->add_child($progress);

        $book->set_source_table('visuallearning', ['id' => backup::VAR_ACTIVITYID]);
        $page->set_source_table('visuallearning_pages', ['visuallearningid' => backup::VAR_PARENTID], 'pagenum ASC');
        if ($userinfo) {
            $progress->set_source_table('visuallearning_progress', ['visuallearningid' => backup::VAR_PARENTID]);
        }
        $progress->annotate_ids('user', 'userid');

        $book->annotate_files('mod_visuallearning', 'intro', null);
        $book->annotate_files('mod_visuallearning', 'cover', null);
        $page->annotate_files('mod_visuallearning', 'image', 'id');
        $page->annotate_files('mod_visuallearning', 'audio', 'id');
        $page->annotate_files('mod_visuallearning', 'option', 'id');

        return $this->prepare_activity_structure($book);
    }
}
