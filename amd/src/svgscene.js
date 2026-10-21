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
 * AI-drawn animated SVG scenes: one SVG per page with a stage per card (cleaned and scoped on the server by
 * \mod_visuallearning\local\svg_sanitiser).
 *
 * The player switches the stage by the root's class (stage-1, stage-2, stage-3, stage-all) and runs the scene's
 * clock itself: at a slow teaching pace while a card is read, at normal speed when a card is tapped or the whole
 * animation is replayed after the page has been heard.
 *
 * @module     mod_visuallearning/svgscene
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** Speed while a card is read, by the lesson's animation pacing. */
const PACE = {slow: 0.4, normal: 0.5, fast: 0.7};

/**
 * Draw a scene into a container.
 *
 * @param {HTMLElement} container emptied and filled with the SVG
 * @param {Object} spec {type: 'svg_scene', svg: string}
 * @param {Object} options {label, pacing, strings: {normal}}
 * @returns {Object|null} controller, or null when the SVG cannot be shown
 */
export const render = (container, spec, options = {}) => {
    if (!spec || typeof spec.svg !== 'string' || spec.svg.indexOf('<svg') !== 0) {
        return null;
    }
    const parsed = new DOMParser().parseFromString(spec.svg, 'image/svg+xml');
    const source = parsed.documentElement;
    if (!source || source.nodeName.toLowerCase() !== 'svg' || parsed.querySelector('parsererror')) {
        return null;
    }
    const svg = document.importNode(source, true);
    container.innerHTML = '';
    container.classList.add('is-svgscene');
    svg.classList.add('vl-svgscene');
    svg.setAttribute('preserveAspectRatio', 'xMidYMid meet');
    if (options.label && !svg.getAttribute('aria-labelledby')) {
        svg.setAttribute('aria-label', options.label);
    }
    container.appendChild(svg);

    const reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    // A scene that teaches a real rhythm (CPR compressions, a heartbeat) asks never to be slowed down.
    const slow = svg.getAttribute('data-realtime') === 'true' ? 1 : (PACE[options.pacing] || PACE.normal);
    let rate = slow;
    let paused = false;
    let clock = 0;
    let last = performance.now();
    let frame = 0;
    let live = true;
    const smil = typeof svg.pauseAnimations === 'function';
    if (smil) {
        svg.pauseAnimations();
    }

    /** Apply the speed to CSS animations inside the scene. */
    const applyRate = () => {
        if (typeof svg.getAnimations !== 'function') {
            return;
        }
        svg.getAnimations({subtree: true}).forEach((a) => {
            if (reduced || paused) {
                a.pause();
            } else {
                a.playbackRate = rate;
                if (a.playState === 'paused') {
                    a.play();
                }
            }
        });
    };

    /** The scene's own clock, so its speed can change smoothly. */
    const tick = (now) => {
        if (!live) {
            return;
        }
        if (!svg.isConnected) {
            live = false;
            return;
        }
        const dt = Math.min(0.25, (now - last) / 1000);
        last = now;
        if (!paused && !reduced) {
            clock += dt * rate;
            if (smil) {
                svg.setCurrentTime(clock);
            }
        }
        frame = window.requestAnimationFrame(tick);
    };
    if (reduced && smil) {
        // A still frame part-way through, where most scenes show their parts.
        svg.setCurrentTime(2);
    }
    frame = window.requestAnimationFrame(tick);

    /** The "play at normal speed" button, shown once the page has been heard. */
    let button = null;
    if (options.strings && options.strings.normal) {
        // Its own row under the scene (room kept for it), so it never covers what the scene shows.
        const bar = document.createElement('div');
        bar.className = 'vl-svgscene-bar';
        button = document.createElement('button');
        button.type = 'button';
        button.className = 'vl-svgscene-normal';
        button.hidden = true;
        button.textContent = options.strings.normal;
        bar.appendChild(button);
        container.appendChild(bar);
    }

    /**
     * Show one stage.
     *
     * @param {string} name 1, 2, 3 or all
     * @param {number} speed
     */
    const show = (name, speed) => {
        const keep = Array.from(svg.classList).filter((c) => c.indexOf('stage-') !== 0);
        svg.setAttribute('class', keep.concat('stage-' + name).join(' '));
        rate = speed;
        // Changing classes can start CSS animations: give them the speed too.
        window.requestAnimationFrame(applyRate);
        applyRate();
    };

    const controller = {
        svg,
        /**
         * The card's stage, played slowly while its clip is read.
         *
         * @param {number} card 1-3
         */
        stage(card) {
            if (button) {
                button.hidden = true;
            }
            show(String(Math.max(1, Math.min(3, card))), slow);
        },
        /**
         * The card's stage at normal speed (a card tapped, or the page shown without narration).
         *
         * @param {number} card 0-3 (0: the first stage, before narration starts)
         */
        finish(card) {
            show(String(Math.max(1, Math.min(3, card || 1))), card ? 1 : slow);
        },
        /** The clip length is known: the stage simply plays on at the teaching pace for as long as it lasts. */
        retime() {
            return;
        },
        /** A clip ended. */
        finishStage() {
            return;
        },
        /**
         * Pause or resume with the narration.
         *
         * @param {boolean} on
         */
        pause(on) {
            paused = !!on;
            applyRate();
        },
        /** The whole page has been read: offer the full animation at normal speed. */
        narrated() {
            if (button) {
                button.hidden = false;
            }
        },
        /** The whole animation at normal speed. */
        playAll() {
            if (button) {
                button.hidden = true;
            }
            paused = false;
            show('all', 1);
        },
        /** Stop and remove. */
        destroy() {
            live = false;
            window.cancelAnimationFrame(frame);
            container.innerHTML = '';
            container.classList.remove('is-svgscene');
        },
    };
    if (button) {
        button.addEventListener('click', (e) => {
            e.stopPropagation();
            controller.playAll();
        });
    }
    show('1', slow);
    return controller;
};
