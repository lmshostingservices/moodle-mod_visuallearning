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
        echo html_writer::start_div('vl-quote-page');
        echo $OUTPUT->heading(get_string('quote_heading', 'mod_visuallearning'));
        echo $intro;
        // What will be made, without prices: the teacher sees one price, the total.
        $lines = [];
        $total = (int)$summary['credits'];
        if (isset($summary['autobudget'])) {
            $lines[] = get_string('quote_item_lesson', 'mod_visuallearning');
            if ((int)$summary['autobudget'] > 0) {
                $lines[] = get_string('quote_item_lesson_animated', 'mod_visuallearning');
            }
            $total += (int)$summary['autobudget'];
        } else {
            foreach ($summary['items'] as $operation => $count) {
                $one = (int)$count === 1 && get_string_manager()->string_exists('quote_item_' . $operation . '_one',
                    'mod_visuallearning');
                $lines[] = get_string('quote_item_' . $operation . ($one ? '_one' : ''), 'mod_visuallearning', $count);
            }
        }
        echo html_writer::tag('ul', implode('', array_map(fn($l) => html_writer::tag('li', $l, ['class' => 'vl-quote-line']),
            $lines)), ['class' => 'vl-quote-lines']);
        foreach (['dropped' => 'quote_dropped', 'droppedcards' => 'quote_droppedcards',
                'droppedanimations' => 'quote_droppedanimations'] as $field => $string) {
            if (!empty($summary[$field])) {
                $hold = $summary[$field === 'dropped' ? 'droppedhold' : $field . 'hold'];
                echo html_writer::tag('p', get_string($string, 'mod_visuallearning', (object)['count' => $summary[$field],
                    'why' => get_string('hold_' . $hold, 'mod_visuallearning')]), ['class' => 'text-muted']);
            }
        }
        if ($summary['skipped']) {
            echo html_writer::tag('p', get_string('quote_skipped', 'mod_visuallearning', $summary['skipped']),
                ['class' => 'text-muted']);
        }
        $balance = lmslabs::balance();
        $a = (object)[
            'credits' => $total,
            'usd' => lmslabs::usd($total),
            'balance' => $balance === null ? get_string('balance_unknown', 'mod_visuallearning')
                : ($balance['unlimited'] ? get_string('balance_unlimited', 'mod_visuallearning') : $balance['credits']),
        ];
        // The one price: the total for everything listed above.
        echo html_writer::div(
            html_writer::tag('strong', get_string('quote_total', 'mod_visuallearning', $a)) .
            ($balance !== null && !$balance['unlimited']
                ? html_writer::div(get_string('quote_balance', 'mod_visuallearning', $a), 'vl-quote-balance') : ''),
            'vl-quote-total'
        );
        if ($balance !== null && !$balance['unlimited'] && $balance['credits'] < $total) {
            $low = get_string('quote_lowbalance', 'mod_visuallearning', $a);
            echo $OUTPUT->notification($low, \core\output\notification::NOTIFY_WARNING);
        }
        $confirm = new moodle_url(
            $baseurl,
            ['action' => 'confirm', 'batch' => $summary['batch'], 'credits' => $summary['credits'], 'sesskey' => sesskey()]
        );
        $cancel = new moodle_url($baseurl, ['action' => 'cancelquote', 'batch' => $summary['batch'], 'sesskey' => sesskey()]);
        $nocharge = $total === 0;
        $label = $nocharge ? get_string('quote_confirmbuttonfree', 'mod_visuallearning')
            : get_string(isset($summary['autobudget']) ? 'quote_confirmbutton_lesson' : 'quote_confirmbutton', 'mod_visuallearning');
        echo html_writer::div(
            $OUTPUT->render(new single_button($cancel, get_string('cancel'), 'post')) .
            $OUTPUT->render(new single_button($confirm, $label, 'post', single_button::BUTTON_PRIMARY)),
            'vl-quote-actions'
        );
        echo html_writer::tag('p', get_string($nocharge ? 'quote_confirmfree' : 'quote_confirm', 'mod_visuallearning', $a) . ' '
            . get_string('quote_rules', 'mod_visuallearning'), ['class' => 'text-muted small vl-quote-rules']);
        echo html_writer::end_div();
        echo $OUTPUT->footer();
        exit;
    }
}
