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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <https://www.gnu.org/licenses/>.

namespace mod_visuallearning\local;

/**
 * Choices on the lesson form shown as clickable cards: an example picture (or an icon), a name, a short line and an
 * optional badge. They are ordinary radio buttons underneath, so they work with the keyboard, screen readers and
 * the form's show/hide rules.
 *
 * @package    mod_visuallearning
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class form_cards {
    /** @var array Line icons (24 x 24, current colour) for cards without an example picture. */
    public const ICONS = [
        'primary' => '<circle cx="12" cy="12" r="9"/><path d="M8.5 14.5c1 1.3 2.2 2 3.5 2s2.5-.7 3.5-2"/><path d="M9 9.5h.01M15 9.5h.01"/>',
        'secondary' => '<path d="M6 8h12v12H6z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/><path d="M6 13h12"/>',
        'adult' => '<path d="M4 15a8 8 0 0 1 16 0"/><path d="M2 15h20v3H2z"/><path d="M12 7V4"/>',
        'advanced' => '<path d="M2 9l10-5 10 5-10 5z"/><path d="M6 11v5c3 2 9 2 12 0v-5"/><path d="M22 9v6"/>',
        'science' => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1'
            . 'M4.9 19.1L7 17M17 7l2.1-2.1"/>',
        'story' => '<circle cx="8" cy="8" r="3"/><circle cx="17" cy="9" r="2.5"/><path d="M2 20c0-3.5 2.7-6 6-6s6 2.5 6 6"/>'
            . '<path d="M14 20c.3-2.8 1.5-4.5 3-4.5s3.2 1.7 3.5 4.5"/>',
    ];

    /**
     * Adds a set of cards for one setting.
     *
     * @param \MoodleQuickForm $mform
     * @param string $name the setting (the radio buttons' name)
     * @param string $label
     * @param array $options value => ['title' => string, 'desc' => string, 'image' => pix name, 'icon' => ICONS key,
     *     'badge' => string] (all but title optional)
     * @param mixed $default
     * @param string $help help string identifier ('' for none)
     */
    public static function add(\MoodleQuickForm $mform, string $name, string $label, array $options, $default,
            string $help = ''): void {
        $cards = [];
        foreach ($options as $value => $option) {
            $cards[] = $mform->createElement('radio', $name, '', self::html($option), $value);
        }
        $mform->addGroup($cards, 'vlcards_' . $name, $label, '', false);
        $mform->setDefault($name, $default);
        if ($help !== '') {
            $mform->addHelpButton('vlcards_' . $name, $help, 'mod_visuallearning');
        }
    }

    /**
     * The inside of one card.
     *
     * @param array $option
     * @return string HTML
     */
    public static function html(array $option): string {
        global $OUTPUT;
        $out = '<span class="vl-card' . (!empty($option['image']) ? ' has-image' : '') . '">';
        if (!empty($option['image'])) {
            $out .= '<img class="vl-card-img" src="' . s($OUTPUT->image_url('cards/' . $option['image'], 'mod_visuallearning'))
                . '" alt="" width="240" height="150">';
        } else if (!empty($option['icon']) && isset(self::ICONS[$option['icon']])) {
            $out .= '<svg class="vl-card-icon" viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" '
                . 'stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
                . self::ICONS[$option['icon']] . '</svg>';
        }
        $out .= '<span class="vl-card-title">' . s($option['title']) . '</span>';
        if (!empty($option['desc'])) {
            $out .= '<span class="vl-card-desc">' . s($option['desc']) . '</span>';
        }
        if (!empty($option['badge'])) {
            $out .= '<span class="vl-card-badge">' . s($option['badge']) . '</span>';
        }
        return $out . '</span>';
    }
}
