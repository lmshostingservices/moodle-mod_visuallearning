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
 * AI Visual Learning upgrade steps.
 *
 * @package    mod_visuallearning
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade the plugin.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_visuallearning_upgrade($oldversion) {
    global $CFG, $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026101800) {
        // Mixed check questions (true or false, picture pick, matching, sorting), feedback and interface sounds.
        $table = new xmldb_table('visuallearning');
        $fields = [
            new xmldb_field(
                'questiontypes',
                XMLDB_TYPE_CHAR,
                '64',
                null,
                XMLDB_NOTNULL,
                null,
                'truefalse,pick,match,sort',
                'enablequiz'
            ),
            new xmldb_field('pickimages', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'questiontypes'),
            new xmldb_field('sounds', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'pickimages'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        $table = new xmldb_table('visuallearning_pages');
        $fields = [
            new xmldb_field('qtype', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'truefalse', 'tfanswer'),
            new xmldb_field('qdata', XMLDB_TYPE_TEXT, null, null, null, null, null, 'qtype'),
            new xmldb_field('feedback', XMLDB_TYPE_TEXT, null, null, null, null, null, 'qdata'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        $table = new xmldb_table('visuallearning_progress');
        $field = new xmldb_field('relisten', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'timepassed');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // The true/false clip is now the question clip. Same text, so the clip already made keeps playing (and is
        // not paid for again): only its file name changes.
        $fs = get_file_storage();
        $files = $DB->get_records_select(
            'files',
            "component = 'mod_visuallearning' AND filearea = 'audio' AND " . $DB->sql_like('filename', ':prefix'),
            ['prefix' => 'statement-%']
        );
        foreach ($files as $record) {
            $file = $fs->get_file_instance($record);
            $name = 'question-' . substr($file->get_filename(), strlen('statement-'));
            if (!$fs->file_exists($file->get_contextid(), 'mod_visuallearning', 'audio', $file->get_itemid(), '/', $name)) {
                $file->rename('/', $name);
            }
        }
        $DB->set_field('visuallearning_aireq', 'part', 'question', ['operation' => 'voice', 'part' => 'statement']);

        upgrade_mod_savepoint(true, 2026101800, 'visuallearning');
    }

    if ($oldversion < 2026101802) {
        $table = new xmldb_table('visuallearning_pages');
        foreach (['bundleid' => 36, 'slideid' => 36, 'bundlesite' => 64, 'bundledeliveries' => null] as $name => $length) {
            $field = new xmldb_field($name, $length ? XMLDB_TYPE_CHAR : XMLDB_TYPE_TEXT, $length, null, null);
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        upgrade_mod_savepoint(true, 2026101802, 'visuallearning');
    }

    if ($oldversion < 2026101811) {
        // Gradebook: every lesson gets a grade out of 100 (the share of check questions answered correctly).
        $table = new xmldb_table('visuallearning');
        $field = new xmldb_field('grade', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '100', 'completionquiz');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        // Grade items and learners' grades are made by a one-off task straight after the upgrade: writing grades
        // updates completion, which Moodle does not allow while an upgrade is running.
        \core\task\manager::queue_adhoc_task(new \mod_visuallearning\task\regrade(), true);
        upgrade_mod_savepoint(true, 2026101811, 'visuallearning');
    }

    if ($oldversion < 2026101815) {
        // Teaching approach and visual style: existing lessons are workplace stories in the illustration style, which
        // make exactly the same prompts as before, so no picture needs making again.
        $table = new xmldb_table('visuallearning');
        $fields = [
            new xmldb_field('teachingapproach', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'story', 'imagestyle'),
            new xmldb_field('visualstyle', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'illustration',
                'teachingapproach'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        upgrade_mod_savepoint(true, 2026101815, 'visuallearning');
    }

    if ($oldversion < 2026101816) {
        // Picture modes: one picture per page (as before), one per card, or animated diagrams.
        $table = new xmldb_table('visuallearning');
        $field = new xmldb_field('picturemode', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'single', 'visualstyle');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $table = new xmldb_table('visuallearning_pages');
        foreach (['cardprompts', 'diagram'] as $name) {
            $field = new xmldb_field($name, XMLDB_TYPE_TEXT, null, null, null, null, null, 'lasterror');
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        upgrade_mod_savepoint(true, 2026101816, 'visuallearning');
    }

    if ($oldversion < 2026102006) {
        // Lessons are written with 4, 8 or 12 pages (whole recap groups): each setting moves to the nearest
        // allowed count. Pages already in a lesson are kept as they are.
        $table = new xmldb_table('visuallearning');
        $field = new xmldb_field('maxpages', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '8', 'sourcecontent');
        $dbman->change_field_default($table, $field);
        $DB->execute("UPDATE {visuallearning} SET maxpages = 4 WHERE maxpages <= 4");
        $DB->execute("UPDATE {visuallearning} SET maxpages = 8 WHERE maxpages > 4 AND maxpages <= 8");
        $DB->execute("UPDATE {visuallearning} SET maxpages = 12 WHERE maxpages > 8");
        $default = get_config('mod_visuallearning', 'defaultmaxpages');
        if ($default !== false) {
            $value = (int)$default;
            set_config('defaultmaxpages', $value <= 4 ? 4 : ($value <= 8 ? 8 : 12), 'mod_visuallearning');
        }
        // Who the lesson is for; existing lessons are for adult learners, as before.
        $field = new xmldb_field('learnerlevel', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'adult', 'picturemode');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        // Animated diagrams are now chosen as a visual style.
        if (get_config('mod_visuallearning', 'defaultpicturemode') === 'animated') {
            set_config('defaultvisualstyle', 'animated', 'mod_visuallearning');
            set_config('defaultpicturemode', 'single', 'mod_visuallearning');
        }
        upgrade_mod_savepoint(true, 2026102006, 'visuallearning');
    }
    if ($oldversion < 2026102010) {
        $table = new xmldb_table('visuallearning');
        foreach (\mod_visuallearning\local\animation_settings::OPTIONS as $name => $values) {
            $field = new xmldb_field($name, XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, $values[0]);
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        upgrade_mod_savepoint(true, 2026102010, 'visuallearning');
    }
    if ($oldversion < 2026102100) {
        // 1.6.0 gave every new lesson the "Automatic" animation engine, which turned picture lessons into animated
        // ones and blocked page writing until LMS Labs shipped the engine. The engine now applies only to
        // animated-diagram lessons; picture lessons go back to their own picture mode.
        $DB->execute("UPDATE {visuallearning} SET animationmode = 'legacy'
                       WHERE animationmode = 'automatic' AND picturemode <> 'animated'");
        upgrade_mod_savepoint(true, 2026102100, 'visuallearning');
    }
    return true;
}
