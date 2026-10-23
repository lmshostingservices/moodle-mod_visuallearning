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
 * Build-the-lesson screen: four steps shown one at a time like slides, with Back and Next, a step tracker,
 * and a refresh while LMS Labs works in the background. Without JavaScript every step is shown in order.
 *
 * @module     mod_visuallearning/manage
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Show one step.
 *
 * @param {HTMLElement} root The wizard.
 * @param {number} step Step to show (1-4).
 * @param {boolean} animate Slide it in.
 */
const show = (root, step, animate) => {
    const slides = root.querySelectorAll('.vl-slide');
    const current = Number(root.dataset.step) || 1;
    const direction = step >= current ? 'is-from-right' : 'is-from-left';
    slides.forEach((slide) => {
        const mine = Number(slide.dataset.slide) === step;
        slide.hidden = !mine;
        slide.classList.remove('is-from-right', 'is-from-left');
        if (mine && animate) {
            // Restart the entrance animation.
            void slide.offsetWidth;
            slide.classList.add(direction);
        }
    });
    root.querySelectorAll('.vl-wizard-tab').forEach((tab) => {
        tab.setAttribute('aria-current', Number(tab.dataset.goto) === step ? 'step' : 'false');
    });
    root.dataset.step = String(step);
    try {
        const url = new URL(window.location.href);
        url.searchParams.set('step', String(step));
        window.history.replaceState(null, '', url.toString());
    } catch (e) {
        // The address bar is a convenience only.
    }
    if (animate) {
        const heading = root.querySelector(`.vl-slide[data-slide="${step}"] h3`);
        if (heading) {
            heading.focus({preventScroll: true});
        }
        root.scrollIntoView({behavior: 'smooth', block: 'start'});
    }
};

/**
 * Initialise.
 *
 * @param {number} refreshSeconds Reload interval while LMS Labs works; 0 for none.
 * @param {number} step The step to open on.
 */
export const init = (refreshSeconds, step) => {
    document.querySelectorAll('.pb-prompt-preview').forEach((area) => {
        area.addEventListener('focus', () => area.select());
    });
    const root = document.querySelector('.vl-wizard');
    if (root) {
        root.classList.add('is-slides');
        show(root, step || 1, false);
        root.addEventListener('click', (e) => {
            const button = e.target.closest('[data-goto]');
            if (!button || button.disabled) {
                return;
            }
            e.preventDefault();
            show(root, Number(button.dataset.goto), true);
        });
    }
    if (refreshSeconds > 0) {
        poll(root, refreshSeconds);
    }
};

/**
 * While LMS Labs works in the background, check the page again every few seconds and update it in place (no
 * flash, no lost scroll position, never a resubmitted form). It stops once nothing is being made any more.
 *
 * @param {HTMLElement|null} root The wizard.
 * @param {number} seconds Interval.
 */
const poll = (root, seconds) => {
    let failures = 0;
    const check = async() => {
        if (document.hidden) {
            // Checked again as soon as the tab is visible.
            document.addEventListener('visibilitychange', check, {once: true});
            return;
        }
        let next = 0;
        try {
            const response = await fetch(window.location.href, {credentials: 'same-origin', cache: 'no-store',
                headers: {'X-Requested-With': 'fetch'}});
            const html = await response.text();
            const doc = new DOMParser().parseFromString(html, 'text/html');
            const fresh = doc.querySelector('.vl-wizard');
            if (!response.ok || !fresh || !root) {
                throw new Error('No wizard');
            }
            const step = Number(root.dataset.step) || 1;
            // Keep open details (the list of requests) open.
            const open = Array.from(root.querySelectorAll('details[open]')).map((d) => d.className);
            root.innerHTML = fresh.innerHTML;
            open.forEach((name) => root.querySelectorAll('details').forEach((d) => {
                if (d.className === name) {
                    d.open = true;
                }
            }));
            show(root, step, false);
            next = Number(fresh.dataset.refresh) || 0;
            failures = 0;
            if (!next) {
                // Everything has arrived: a full load picks up anything else on the page (notices, the step tracker).
                window.location.reload();
                return;
            }
        } catch (e) {
            failures++;
            if (failures >= 3) {
                window.location.reload();
                return;
            }
            next = seconds;
        }
        window.setTimeout(check, next * 1000);
    };
    window.setTimeout(check, seconds * 1000);
};
