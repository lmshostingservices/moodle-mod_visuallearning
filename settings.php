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
 * Admin settings.
 *
 * @package    mod_visuallearning
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    $component = 'mod_visuallearning';

    // Activation (one-time LMS Labs unlock): status, "Check access" and "Unlock" (confirmed on the next step).
    $settings->add(new \mod_visuallearning\admin\setting_activation("$component/activation"));

    $settings->add(
        new admin_setting_heading(
            "$component/aiheading",
            get_string('settings_ai', $component),
            get_string(
                'settings_ai_desc',
                $component,
                (object)[
                    'page' => \mod_visuallearning\local\ai\lmslabs::PAGE_CREDITS,
                    'image' => \mod_visuallearning\local\ai\lmslabs::IMAGE_CREDITS,
                    'voice' => \mod_visuallearning\local\ai\lmslabs::VOICE_CREDITS,
                ]
            ) . '<p><strong>' . get_string(\mod_visuallearning\local\credentials::status(), $component) . '</strong></p>'
        )
    );
    foreach (['aiwrite', 'aiimages', 'aivoice'] as $name) {
        $settings->add(
            new admin_setting_configcheckbox(
                "$component/$name",
                get_string('settings_' . $name, $component),
                get_string('settings_' . $name . '_desc', $component),
                1
            )
        );
    }
    $settings->add(
        new admin_setting_configtext(
            "$component/lmslabssiteid",
            get_string('lmslabssiteid', $component),
            get_string('lmslabssiteid_desc', $component),
            '',
            PARAM_ALPHANUMEXT
        )
    );
    $settings->add(
        new admin_setting_configpasswordunmask(
            "$component/lmslabsapikey",
            get_string('lmslabsapikey', $component),
            get_string('lmslabsapikey_desc', $component),
            ''
        )
    );

    $settings->add(
        new admin_setting_heading(
            "$component/defaultsheading",
            get_string('settings_defaultsheading', $component),
            ''
        )
    );
    $settings->add(
        new admin_setting_configselect(
            "$component/defaultapproach",
            get_string('settings_defaultapproach', $component),
            get_string('settings_defaultapproach_desc', $component),
            \mod_visuallearning\local\approaches::DEFAULT,
            \mod_visuallearning\local\approaches::options()
        )
    );
    $settings->add(
        new admin_setting_configselect(
            "$component/defaultvisualstyle",
            get_string('settings_defaultvisualstyle', $component),
            get_string('settings_defaultvisualstyle_desc', $component),
            \mod_visuallearning\local\visual_styles::DEFAULT,
            \mod_visuallearning\local\visual_styles::options()
        )
    );
    $settings->add(
        new admin_setting_configselect(
            "$component/defaultpicturemode",
            get_string('settings_defaultpicturemode', $component),
            get_string('settings_defaultpicturemode_desc', $component),
            \mod_visuallearning\local\picture_modes::SINGLE,
            \mod_visuallearning\local\picture_modes::options()
        )
    );
    $settings->add(
        new admin_setting_configtextarea(
            "$component/defaultimagestyle",
            get_string('settings_defaultimagestyle', $component),
            get_string('settings_defaultimagestyle_desc', $component),
            get_string('defaultimagestyle', $component),
            PARAM_TEXT
        )
    );
    $settings->add(
        new admin_setting_configselect(
            "$component/defaultlanguage",
            get_string('language', $component),
            '',
            'en-AU',
            \mod_visuallearning\local\voices::language_options()
        )
    );
    $default = (string)(get_config($component, 'defaultvoice') ?: 'Aoede');
    $settings->add(
        new admin_setting_configselect(
            "$component/defaultvoice",
            get_string('voice', $component),
            get_string('settings_defaultvoice_desc', $component),
            'Aoede',
            \mod_visuallearning\local\voices::voice_options('', $default)
        )
    );
    $pages = array_combine(range(3, 20), range(3, 20));
    $settings->add(
        new admin_setting_configselect(
            "$component/defaultmaxpages",
            get_string('maxpages', $component),
            '',
            20,
            $pages
        )
    );
}
