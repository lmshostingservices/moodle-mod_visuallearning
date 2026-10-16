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

namespace mod_visuallearning\local;

use html_writer;
use mod_visuallearning\local\ai\lmslabs;
use mod_visuallearning\local\ai\requests;
use moodle_url;
use single_button;

/**
 * The price confirmation page shown before any paid LMS Labs request is sent.
 *
 * @package    mod_visuallearning
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quote_page {
    /**
     * Shows a quote for confirmation (nothing has been sent or charged) and ends the page.
     *
     * @param array $summary from {@see requests::quote()}
     * @param moodle_url $baseurl
     * @param string $intro HTML shown above the price (an import's pages)
     */
    public static function show(array $summary, moodle_url $baseurl, string $intro = ''): void {
        global $OUTPUT;
        if ($summary['hold'] !== null) {
            redirect(
                $baseurl,
                get_string('hold_' . $summary['hold'], 'mod_visuallearning'),
                null,
                \core\output\notification::NOTIFY_WARNING
            );
        }
        if (!$summary['items']) {
            redirect(
                $baseurl,
                get_string($summary['skipped'] ? 'quote_allwaiting' : 'quote_nothing', 'mod_visuallearning'),
                null,
                \core\output\notification::NOTIFY_INFO
            );
        }
        echo $OUTPUT->header();
        echo $OUTPUT->heading(get_string('quote_heading', 'mod_visuallearning'));
        echo $intro;
        $lines = [];
        foreach ($summary['items'] as $operation => $count) {
            $a = (object)['count' => $count, 'price' => lmslabs::approved_price(requests::price_operation($operation)),
                'free' => $summary['free']];
            $key = $operation === requests::VOICE && $summary['free'] ? 'quote_line_voicefree' : 'quote_line_' . $operation;
            $lines[] = html_writer::tag('li', get_string($key, 'mod_visuallearning', $a));
        }
        echo html_writer::tag('ul', implode('', $lines));
        if ($summary['skipped']) {
            $skipped = get_string('quote_skipped', 'mod_visuallearning', $summary['skipped']);
            echo html_writer::tag('p', $skipped, ['class' => 'text-muted']);
        }
        $balance = lmslabs::balance();
        $a = (object)[
            'credits' => $summary['credits'],
            'balance' => $balance === null ? get_string('balance_unknown', 'mod_visuallearning')
                : ($balance['unlimited'] ? get_string('balance_unlimited', 'mod_visuallearning') : $balance['credits']),
        ];
        echo html_writer::tag('p', html_writer::tag('strong', get_string('quote_total', 'mod_visuallearning', $a)));
        if ($balance !== null && !$balance['unlimited'] && $balance['credits'] < $summary['credits']) {
            $low = get_string('quote_lowbalance', 'mod_visuallearning', $a);
            echo $OUTPUT->notification($low, \core\output\notification::NOTIFY_WARNING);
        }
        echo html_writer::tag('p', get_string('quote_rules', 'mod_visuallearning'), ['class' => 'text-muted small']);
        $confirm = new moodle_url(
            $baseurl,
            ['action' => 'confirm', 'batch' => $summary['batch'], 'credits' => $summary['credits'], 'sesskey' => sesskey()]
        );
        $cancel = new moodle_url($baseurl, ['action' => 'cancelquote', 'batch' => $summary['batch'], 'sesskey' => sesskey()]);
        echo $OUTPUT->confirm(
            get_string('quote_confirm', 'mod_visuallearning', $a),
            new single_button(
                $confirm,
                get_string('quote_confirmbutton', 'mod_visuallearning', $summary['credits']),
                'post',
                single_button::BUTTON_PRIMARY
            ),
            new single_button($cancel, get_string('cancel'), 'post')
        );
        echo $OUTPUT->footer();
        exit;
    }
}
