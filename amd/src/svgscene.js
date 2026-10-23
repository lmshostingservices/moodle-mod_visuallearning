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

/**
 * Keep every label inside its card, pill or panel, and inside the plate. An AI-drawn scene sometimes sizes a card
 * for shorter text than it holds ("Oxygen-poor blood" running past its legend card). Each text that overflows the
 * smallest shape behind it first widens that shape when there is free room beside it on the plate, then is
 * scaled down slightly, and only as a last resort condensed to fit. Works in screen pixels, so transforms,
 * text-anchor and the scene's scale do not matter. Texts hidden at the moment (zero size) are fitted later.
 *
 * @param {SVGSVGElement} svg
 */
export const fitText = (svg) => {
    const box = (el) => el.getBoundingClientRect();
    const plateBox = box(svg);
    if (!plateBox.width) {
        return;
    }
    const scale = plateBox.width / ((svg.viewBox && svg.viewBox.baseVal && svg.viewBox.baseVal.width) || plateBox.width);
    const plateArea = plateBox.width * plateBox.height;
    // Shapes that can hold text: filled rects smaller than the plate (cards, pills, panels, legends).
    const shapes = Array.from(svg.querySelectorAll('rect')).map((el) => ({el, b: box(el)}))
        .filter(({el, b}) => b.width > 4 && b.height > 4 && b.width * b.height < plateArea * 0.6
            && getComputedStyle(el).fill !== 'none');
    const texts = Array.from(svg.querySelectorAll('text'));
    // Everything painted, in painting order (document order), with its box.
    const painted = Array.from(svg.querySelectorAll('rect, circle, ellipse, path, polygon, use'))
        .filter((el) => !el.closest('defs, clipPath, mask, pattern, marker, symbol'))
        .map((el) => ({el, b: box(el)}))
        .filter(({b}) => b.width > 2 && b.height > 2 && b.width * b.height < plateArea * 0.6);
    // Safe to run again at any time: a text that fits is left alone, and a smaller size is always worked out from
    // the text's own size (so the player refits once its fonts are in, and as stages show more).
    // The house style's padding: about 12-14px between a label's text and the end of its card or pill.
    const pad = (t) => Math.max(10 * scale, parseFloat(getComputedStyle(t).fontSize) * scale * 0.9);
    texts.forEach((t) => {
        let tb = box(t);
        if (!tb.width || !tb.height) {
            return;
        }
        const cy = tb.top + tb.height / 2;
        // The shape painted last under the text's start (a card or pill, or an organ or part drawn over a vessel):
        // only a card or pill there can hold the label, so a vessel under an organ's name never counts as its card.
        const px = tb.left + Math.min(8, tb.width / 2);
        const under = painted.filter(({b}) => px > b.left && px < b.right && cy > b.top && cy < b.bottom).pop();
        // The smallest shape behind the text: it holds the text's middle line and overlaps it sideways.
        let holder = null;
        shapes.forEach((s) => {
            const b = s.b;
            const overlap = Math.min(tb.right, b.right) - Math.max(tb.left, b.left);
            if (cy > b.top && cy < b.bottom && overlap > Math.min(tb.width, b.width) * 0.6 && tb.left > b.left - 2
                    && tb.height < b.height + 4
                    && (!under || under.el === s.el)
                    && (!holder || b.width * b.height < holder.b.width * holder.b.height)) {
                holder = s;
            }
        });
        const p = pad(t);
        const anchor = getComputedStyle(t).textAnchor || t.getAttribute('text-anchor') || 'start';
        const limitL = (holder ? holder.b.left : plateBox.left) + (holder ? p : 2);
        const limitR = (holder ? holder.b.right : plateBox.right) - (holder ? p : 2);
        const overR = tb.right - limitR;
        const overL = limitL - tb.left;
        if (overR <= 0.5 && overL <= 0.5) {
            return;
        }
        if (holder && anchor === 'middle') {
            // A centred label (a pill): widen it evenly on both sides when there is room.
            const grow = Math.max(overR, overL) * 2 + 1;
            const wanted = {left: holder.b.left - grow / 2, right: holder.b.right + grow / 2, top: holder.b.top,
                bottom: holder.b.bottom};
            const free = wanted.left > plateBox.left + 8 * scale && wanted.right < plateBox.right - 8 * scale
                && !shapes.some((s) => s !== holder && s.b.left < wanted.right && s.b.right > wanted.left
                    && s.b.top < wanted.bottom && s.b.bottom > wanted.top
                    && !(s.b.left >= holder.b.left - 1 && s.b.right <= holder.b.right + 1));
            if (free) {
                const ctm = holder.el.getScreenCTM();
                const unit = ctm ? Math.hypot(ctm.a, ctm.b) : scale;
                holder.el.setAttribute('x', String(holder.el.x.baseVal.value - grow / 2 / unit));
                holder.el.setAttribute('width', String(holder.el.width.baseVal.value + grow / unit));
                holder.b = box(holder.el);
                return;
            }
        }
        if (holder && anchor !== 'middle' && overR > 0 && overL <= 0.5) {
            // Widen the card to the right when nothing else is in the way and it stays on the plate.
            const grow = overR + 1;
            const wanted = {left: holder.b.right, right: holder.b.right + grow, top: holder.b.top, bottom: holder.b.bottom};
            const blocked = wanted.right > plateBox.right - 8 * scale || shapes.some((s) => s !== holder
                && s.b.left < wanted.right && s.b.right > wanted.left && s.b.top < wanted.bottom && s.b.bottom > wanted.top
                && !(s.b.left >= holder.b.left && s.b.right <= holder.b.right));
            // No room on the right (a legend at the plate's edge): move the card and what is in it to the left.
            const left = {left: holder.b.left - grow, right: holder.b.left, top: holder.b.top, bottom: holder.b.bottom};
            const blockedLeft = left.left < plateBox.left + 8 * scale || shapes.some((s) => s !== holder
                && s.b.left < left.right && s.b.right > left.left && s.b.top < left.bottom && s.b.bottom > left.top
                && !(s.b.left >= holder.b.left - 1 && s.b.right <= holder.b.right + 1));
            if (blocked && !blockedLeft) {
                const inside = (el) => {
                    const b = box(el);
                    return b.width > 0 && b.left >= holder.b.left - 1 && b.right <= holder.b.right + grow + 1
                        && b.top >= holder.b.top - 1 && b.bottom <= holder.b.bottom + 1;
                };
                const moving = new Set([holder.el]);
                shapes.forEach((s) => {
                    if (Math.abs(s.b.left - holder.b.left) < 1 && Math.abs(s.b.top - holder.b.top) < 1) {
                        moving.add(s.el);
                    }
                });
                svg.querySelectorAll('text, circle, ellipse, line, path, use, rect').forEach((el) => {
                    if (!moving.has(el) && !el.closest('defs') && inside(el)) {
                        moving.add(el);
                    }
                });
                moving.forEach((el) => {
                    const ctm = el.getScreenCTM();
                    const unit = ctm ? Math.hypot(ctm.a, ctm.b) : scale;
                    const old = el.getAttribute('transform') || '';
                    el.setAttribute('transform', ('translate(' + (-grow / unit).toFixed(2) + ' 0) ' + old).trim());
                    if (el.tagName.toLowerCase() === 'rect' && (el === holder.el || shapes.some((s) => s.el === el))) {
                        el.setAttribute('width', String(el.width.baseVal.value + grow / unit));
                    }
                });
                shapes.forEach((s) => {
                    s.b = box(s.el);
                });
                return;
            }
            if (!blocked) {
                const el = holder.el;
                const ctm = el.getScreenCTM();
                const unit = ctm ? Math.hypot(ctm.a, ctm.b) : scale;
                el.setAttribute('width', String(el.width.baseVal.value + grow / unit));
                holder.b = box(el);
                // Rects drawn with it as one card (its shadow layer, an outline) grow the same.
                shapes.forEach((s) => {
                    if (s !== holder && Math.abs(s.b.left - holder.b.left) < 1 && Math.abs(s.b.top - holder.b.top) < 1
                            && Math.abs(s.b.bottom - holder.b.bottom) < 1) {
                        s.el.setAttribute('width', String(el.width.baseVal.value));
                        s.b = box(s.el);
                    }
                });
                return;
            }
        }
        // Scale the text down (at most to 78%), then condense whatever still does not fit.
        if (!t.dataset.vlSize) {
            t.dataset.vlSize = getComputedStyle(t).fontSize;
        }
        const size = parseFloat(t.dataset.vlSize);
        if (t.dataset.vlSized) {
            t.style.fontSize = t.dataset.vlSize;
        }
        t.removeAttribute('textLength');
        tb = box(t);
        let room = limitR - limitL;
        if (anchor === 'start') {
            room = limitR - Math.max(tb.left, limitL);
        } else if (anchor === 'end') {
            room = Math.min(tb.right, limitR) - limitL;
        }
        const factor = Math.max(0.78, Math.min(1, room / tb.width));
        t.style.fontSize = (size * factor).toFixed(2) + 'px';
        t.dataset.vlSized = '1';
        tb = box(t);
        const still = Math.max(tb.right - limitR, limitL - tb.left);
        if (still > 0.5) {
            const ctm = t.getScreenCTM();
            const unit = ctm ? Math.hypot(ctm.a, ctm.b) : scale;
            t.setAttribute('textLength', String(Math.max(1, (tb.width - still - 1) / unit)));
            t.setAttribute('lengthAdjust', 'spacingAndGlyphs');
        }
    });
};

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
    // Fit the labels once the scene has its size and the player's fonts are in (and again as stages show more).
    const refit = () => window.requestAnimationFrame(() => fitText(svg));
    refit();
    if (document.fonts && document.fonts.load) {
        // The scene's text uses the player's fonts, which may still be loading: fit again once they are in.
        Promise.all(['700 13px "VL Sans"', '800 13px "VL Sans"', '700 22px "VL Hand"']
            .map((font) => document.fonts.load(font).catch(() => null))).then(refit).catch(() => null);
    }
    window.setTimeout(refit, 800);

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

    /**
     * The scene's own clock, so its speed can change smoothly.
     * @param {number} now
     */
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
        refit();
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
