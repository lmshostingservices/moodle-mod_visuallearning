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
    global $DB;
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
    return true;
}
