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
            $included = (int)($summary['included'][$operation] ?? 0);
            $free = $operation === requests::VOICE ? (int)$summary['free'] : 0;
            $a = (object)['count' => $count - $included, 'free' => $free,
                'price' => lmslabs::approved_price(requests::price_operation($operation))];
            $parts = [];
            if ($count - $included > 0) {
                $key = $free ? 'quote_line_voicefree' : 'quote_line_' . $operation;
                $parts[] = get_string($key, 'mod_visuallearning', $a);
            }
            if ($included) {
                $parts[] = get_string('quote_line_included', 'mod_visuallearning', (object)['count' => $included,
                    'what' => get_string('quote_what_' . $operation, 'mod_visuallearning')]);
            }
            foreach ($parts as $part) {
                $lines[] = html_writer::tag('li', $part);
            }
        }
        echo html_writer::tag('ul', implode('', $lines));
        if (!empty($summary['dropped'])) {
            $dropped = get_string('quote_dropped', 'mod_visuallearning', (object)['count' => $summary['dropped'],
                'why' => get_string('hold_' . $summary['droppedhold'], 'mod_visuallearning')]);
            echo html_writer::tag('p', $dropped, ['class' => 'text-muted']);
        }
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
        if ((int)$summary['credits'] === 0) {
            echo html_writer::tag('p', get_string('quote_nocharge', 'mod_visuallearning'));
        }
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
        $nocharge = (int)$summary['credits'] === 0;
        echo $OUTPUT->confirm(
            get_string($nocharge ? 'quote_confirmfree' : 'quote_confirm', 'mod_visuallearning', $a),
            new single_button(
                $confirm,
                $nocharge ? get_string('quote_confirmbuttonfree', 'mod_visuallearning')
                    : get_string('quote_confirmbutton', 'mod_visuallearning', $summary['credits']),
                'post',
                single_button::BUTTON_PRIMARY
            ),
            new single_button($cancel, get_string('cancel'), 'post')
        );
        echo $OUTPUT->footer();
        exit;
    }
}
