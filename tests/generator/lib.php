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
 * Data generator for mod_visuallearning.
 *
 * @package    mod_visuallearning
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_visuallearning_generator extends testing_module_generator {
    /**
     * Create an AI Visual Learning activity.
     *
     * @param array|stdClass|null $record
     * @param array|null $options
     * @return stdClass
     */
    public function create_instance($record = null, ?array $options = null) {
        $record = (object)(array)$record;
        $defaults = [
            'industry' => 'Hospitality',
            'subindustry' => 'Restaurants',
            'sourcecontent' => 'Teams work better when people know each other.',
            'maxpages' => 20,
            'language' => 'en-AU',
            'voice' => 'Aoede',
            'speakingrate' => 1.0,
            'imagestyle' => 'Hand-drawn illustration',
            'navmode' => 0,
            'completionlistened' => 0,
            'completionquiz' => 0,
            'autoplay' => 0,
            'imageculture' => 'language',
            'enablerecap' => 1,
            'enablequiz' => 1,
        ];
        foreach ($defaults as $name => $value) {
            if (!isset($record->{$name})) {
                $record->{$name} = $value;
            }
        }
        return parent::create_instance($record, (array)$options);
    }

    /**
     * Add a page to a lesson.
     *
     * @param int $bookid
     * @param array $record
     * @return stdClass
     */
    public function create_page(int $bookid, array $record = []): stdClass {
        global $DB;
        $num = $DB->count_records('visuallearning_pages', ['visuallearningid' => $bookid]) + 1;
        $page = (object)array_merge(
            [
                'visuallearningid' => $bookid,
                'pagenum' => $num,
                'title' => 'Concept ' . $num,
                'concepttext' => 'The idea of page ' . $num . '.',
                'actiontext' => 'The action of page ' . $num . '.',
                'outcometext' => 'The outcome of page ' . $num . '.',
                'imageprompt' => 'A friendly scene for page ' . $num . '.',
                'alttext' => 'Scene ' . $num,
                'summarytext' => 'Summary of page ' . $num . '.',
                'tfstatement' => 'Statement about page ' . $num . '.',
                'tfanswer' => 1,
                'imagestatus' => 0,
                'audiostatus' => 0,
                'timemodified' => time(),
            ],
            $record,
        );
        $page->id = $DB->insert_record('visuallearning_pages', $page);
        return $page;
    }
}
