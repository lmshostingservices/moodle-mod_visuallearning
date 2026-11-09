// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * The teacher's preview of their own HTML: the same sandboxed frame learners get, with buttons that send the card
 * messages the player sends while the voiceover reads each card.
 *
 * @module     mod_visuallearning/htmlpreview
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import * as HtmlEmbed from 'mod_visuallearning/htmlembed';

/** How long a test card "reads" for, like a typical key point clip. */
const TEST_MS = 4500;

export const init = () => {
    const stage = document.querySelector('.vl-customhtml-stage');
    if (stage) {
        const embed = HtmlEmbed.render(stage, {type: 'html_embed', url: stage.dataset.url, title: stage.dataset.title},
            {label: stage.dataset.title});
        if (embed) {
            // Start with everything showing; the buttons then play each key point as the lesson does.
            embed.finish(4);
        }
        document.querySelectorAll('[data-vl-test]').forEach((button) => {
            button.addEventListener('click', () => {
                if (!embed) {
                    return;
                }
                const kind = button.dataset.vlTest;
                if (kind === 'card') {
                    embed.stage(parseInt(button.dataset.card, 10), TEST_MS);
                } else if (kind === 'finish') {
                    embed.finish(4);
                } else {
                    embed.reset();
                }
            });
        });
    }
    document.querySelectorAll('[data-vl-copy]').forEach((button) => {
        button.addEventListener('click', async() => {
            const area = document.getElementById(button.dataset.vlCopy);
            try {
                await navigator.clipboard.writeText(area.value);
            } catch (e) {
                // Older browsers and some app views refuse the clipboard API: select the text and copy it.
                area.select();
                document.execCommand('copy');
            }
            const label = button.textContent;
            button.textContent = button.dataset.done;
            window.setTimeout(() => {
                button.textContent = label;
            }, 2000);
        });
    });
};
