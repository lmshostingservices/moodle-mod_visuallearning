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
 * The svg-2 motion layer of AI-drawn scenes: camera moves, lines that draw themselves, a guide character that points
 * and reacts, counters, and a "try it" step the learner plays with once the page has been heard.
 *
 * The scene holds no code. Claude only tags its shapes with data-vl-* attributes (see schema/svg-animation-system.md,
 * "SVG-2 MOTION"), and this module moves them. Every state is a pure function of (stage, progress), so a stage can
 * be stretched over its card's voice clip, paused, scrubbed and replayed exactly.
 *
 * @module     mod_visuallearning/svgmotion
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** Attributes a try-it control may drive. */
const BINDABLE = ['x', 'y', 'cx', 'cy', 'r', 'rx', 'ry', 'width', 'height', 'x1', 'x2', 'y1', 'y2', 'opacity', 'tx', 'ty',
    'rotate'];

const clamp = (x, a = 0, b = 1) => Math.max(a, Math.min(b, x));
const ease = (x) => (x < 0.5 ? 2 * x * x : 1 - Math.pow(-2 * x + 2, 2) / 2);
const lerp = (a, b, u) => a + (b - a) * u;
const nums = (s) => String(s || '').trim().split(/[\s,]+/).filter((v) => v !== '').map(Number)
    .filter((n) => Number.isFinite(n));

/**
 * The stage number (1-3) an attribute names, or 0.
 *
 * @param {Element} el
 * @param {string} name
 * @returns {number}
 */
const stageOf = (el, name) => {
    const n = parseInt(el.getAttribute(name), 10);
    return n >= 1 && n <= 3 ? n : 0;
};

/**
 * The part of its stage (fractions 0-1) in which something happens.
 *
 * @param {Element} el
 * @param {number[]} fallback
 * @returns {number[]}
 */
const windowOf = (el, fallback) => {
    const v = nums(el.getAttribute('data-vl-at')).map((n) => clamp(n));
    return v.length === 2 && v[1] >= v[0] ? v : fallback;
};

/**
 * How far something that happens in stage n, during window w, has got at (stage s, progress u).
 *
 * @param {number} n
 * @param {number[]} w
 * @param {number} s
 * @param {number} u
 * @returns {number} 0-1
 */
const progressAt = (n, w, s, u) => {
    if (s > n) {
        return 1;
    }
    if (s < n) {
        return 0;
    }
    if (w[1] <= w[0]) {
        return u >= w[0] ? 1 : 0;
    }
    return ease(clamp((u - w[0]) / (w[1] - w[0])));
};

/**
 * A value counted from "from" to "to": straight, or doubling (bacteria, compound growth).
 *
 * @param {number} from
 * @param {number} to
 * @param {number} p 0-1
 * @param {string} curve
 * @returns {number}
 */
const counted = (from, to, p, curve) => {
    if (curve === 'double' && from > 0 && to > from) {
        const steps = Math.log2(to / from);
        return from * Math.pow(2, Math.floor(p * steps + 1e-9));
    }
    return lerp(from, to, p);
};

/**
 * Parse "id:lo..hi&id:lo..hi" (all must hold).
 *
 * @param {string} text
 * @returns {Array} [[id, lo, hi], ...]
 */
const parseWhen = (text) => String(text || '').split('&').map((part) => {
    const m = part.trim().match(/^([a-z][a-z0-9]{0,15}):(-?[\d.]*)\.\.(-?[\d.]*)$/i);
    if (!m) {
        return null;
    }
    return [m[1], m[2] === '' ? -Infinity : Number(m[2]), m[3] === '' ? Infinity : Number(m[3])];
}).filter(Boolean);

/**
 * Whether every condition holds for the learner's values.
 *
 * @param {Array} conds
 * @param {Object} values
 * @returns {boolean}
 */
const holds = (conds, values) => conds.every(([id, lo, hi]) => id in values && values[id] >= lo && values[id] <= hi);

/**
 * A number formatted for display.
 *
 * @param {number} v
 * @param {number} decimals
 * @returns {string}
 */
const fmt = (v, decimals) => {
    const d = Math.max(0, Math.min(2, decimals || 0));
    return Number(v.toFixed(d)).toLocaleString(undefined, {minimumFractionDigits: d, maximumFractionDigits: d});
};

/**
 * A sum of terms such as "carbs*17+fat*37" or "temp" over the learner's values; null when it names an unknown value.
 *
 * @param {string} text
 * @param {Object} values
 * @returns {number|null}
 */
export const linear = (text, values) => {
    const terms = String(text || '').replace(/\s+/g, '').split('+');
    let total = 0;
    for (const term of terms) {
        const m = term.match(/^(?:([a-z][a-z0-9]{0,15})(?:\*(-?\d+(?:\.\d+)?))?|(-?\d+(?:\.\d+)?))$/i);
        if (!m) {
            return null;
        }
        if (m[3] !== undefined) {
            total += Number(m[3]);
        } else if (m[1] in values) {
            total += values[m[1]] * (m[2] === undefined ? 1 : Number(m[2]));
        } else {
            return null;
        }
    }
    return total;
};

/**
 * The try-it definition on the root, checked (labels are only ever shown as text).
 *
 * @param {SVGSVGElement} svg
 * @returns {Object|null} {controls, rules}
 */
export const parseTry = (svg) => {
    let raw;
    try {
        raw = JSON.parse(svg.getAttribute('data-vl-try') || 'null');
    } catch (e) {
        return null;
    }
    if (!raw || !Array.isArray(raw.controls)) {
        return null;
    }
    const str = (v, max) => (typeof v === 'string' ? v.trim().slice(0, max) : '');
    const controls = raw.controls.slice(0, 8).map((c) => {
        const min = Number(c.min);
        const max = Number(c.max);
        if (!c || !/^[a-z][a-z0-9]{0,15}$/i.test(String(c.id)) || !Number.isFinite(min) || !Number.isFinite(max) || max <= min) {
            return null;
        }
        const step = Number(c.step) > 0 ? Number(c.step) : (max - min) / 100;
        const value = clamp(Number.isFinite(Number(c.value)) ? Number(c.value) : min, min, max);
        return {id: String(c.id), label: str(c.label, 40) || String(c.id), min, max, step, value, unit: str(c.unit, 8)};
    }).filter(Boolean).slice(0, 2);
    if (!controls.length) {
        return null;
    }
    const rules = (Array.isArray(raw.rules) ? raw.rules : []).slice(0, 8).map((r) => {
        if (!r || typeof r.when !== 'object' || !r.when) {
            return null;
        }
        const conds = Object.keys(r.when).map((id) => {
            const range = r.when[id];
            if (!Array.isArray(range) || range.length !== 2) {
                return null;
            }
            const lo = range[0] === null ? -Infinity : Number(range[0]);
            const hi = range[1] === null ? Infinity : Number(range[1]);
            return Number.isNaN(lo) || Number.isNaN(hi) ? null : [id, lo, hi];
        });
        if (conds.includes(null)) {
            return null;
        }
        return {conds, say: str(r.say, 160), mood: str(r.mood, 16), tone: ['ok', 'warn', 'bad'].includes(r.tone) ? r.tone : 'ok'};
    }).filter(Boolean);
    return {controls, rules};
};

/**
 * Attach the motion layer to a cleaned svg-2 scene.
 *
 * @param {SVGSVGElement} svg
 * @param {Object} options {reduced: boolean}
 * @returns {Object|null} {render(stage, progress), setTry(values|null), tryDef, idle(now)}; null for an svg-1 scene
 */
export const attach = (svg, options = {}) => {
    const all = (sel) => Array.from(svg.querySelectorAll(sel));
    const has = svg.hasAttribute('data-vl-try') || [1, 2, 3].some((n) => svg.hasAttribute('data-vl-camera-' + n))
        || svg.querySelector('[data-vl-draw],[data-vl-show],[data-vl-hide],[data-vl-guide],[data-vl-count],[data-vl-seq],'
            + '[data-vl-move-1],[data-vl-move-2],[data-vl-move-3]');
    if (!has) {
        return null;
    }
    const reduced = !!options.reduced;
    const base = nums(svg.getAttribute('viewBox'));
    const camera = [base.length === 4 ? base : [0, 0, 800, 560]];
    for (let n = 1; n <= 3; n++) {
        const v = nums(svg.getAttribute('data-vl-camera-' + n));
        camera.push(v.length === 4 && v[2] > 0 && v[3] > 0 ? v : camera[n - 1]);
    }
    const tryCam = nums(svg.getAttribute('data-vl-camera-try'));
    const cameraTry = tryCam.length === 4 && tryCam[2] > 0 && tryCam[3] > 0 ? tryCam : camera[0];
    // Remember what the scene set itself, so our changes always start from it.
    const orig = new Map();
    const remember = (el) => {
        if (!orig.has(el)) {
            orig.set(el, {
                opacity: el.hasAttribute('opacity') ? Number(el.getAttribute('opacity')) : 1,
                transform: el.getAttribute('transform') || '',
                attrs: {},
            });
        }
        return orig.get(el);
    };
    const draws = all('[data-vl-draw]').filter((el) => stageOf(el, 'data-vl-draw'));
    draws.forEach((el) => {
        el.setAttribute('pathLength', '1');
        el.style.strokeDasharray = '1 1';
    });
    const shows = all('[data-vl-show],[data-vl-hide],[data-vl-say],[data-vl-try-only],[data-vl-narration-only]');
    shows.forEach(remember);
    // A shown thing with data-vl-pop springs up from 60% around its own centre as it fades in.
    const popCentre = new Map();
    shows.filter((el) => el.hasAttribute('data-vl-pop') && el.hasAttribute('data-vl-show')).forEach((el) => {
        try {
            const b = el.getBBox();
            popCentre.set(el, [b.x + b.width / 2, b.y + b.height / 2]);
        } catch (e) {
            popCentre.delete(el);
        }
    });
    const springy = (x) => {
        if (x <= 0 || x >= 1) {
            return x <= 0 ? 0 : 1;
        }
        const c1 = 1.70158;
        const c3 = c1 + 1;
        return 1 + c3 * Math.pow(x - 1, 3) + c1 * Math.pow(x - 1, 2);
    };
    const moves = all('[data-vl-move-1],[data-vl-move-2],[data-vl-move-3]');
    moves.forEach(remember);
    const counts = all('text[data-vl-count],tspan[data-vl-count]');
    const seqs = all('[data-vl-seq]');
    const guides = all('[data-vl-guide]').map((g) => {
        const pos = [];
        for (let n = 0; n <= 3; n++) {
            const v = nums(g.getAttribute('data-vl-pos-' + n));
            pos.push(v.length === 2 ? v : null);
        }
        if (!pos[1]) {
            pos[1] = pos[0] || [0, 0];
        }
        if (!pos[0]) {
            pos[0] = pos[1];
        }
        for (let n = 2; n <= 3; n++) {
            pos[n] = pos[n] || pos[n - 1];
        }
        const points = [null, 1, 2, 3].map((n) => (n ? String(g.getAttribute('data-vl-point-' + n) || '').split(';')
            .map(nums).filter((v) => v.length === 2) : []));
        const moods = [null, 1, 2, 3].map((n) => (n ? String(g.getAttribute('data-vl-mood-' + n) || '').trim().split(/\s+/)
            .filter(Boolean) : []));
        const arm = g.querySelector('[data-vl-arm]');
        const blink = g.querySelector('[data-vl-blink]');
        let blinkCentre = null;
        if (blink && typeof blink.getBBox === 'function') {
            try {
                const b = blink.getBBox();
                blinkCentre = [b.x + b.width / 2, b.y + b.height / 2];
            } catch (e) {
                blinkCentre = null;
            }
        }
        return {
            g, pos, points, moods, arm, blink, blinkCentre,
            scale: Number(g.getAttribute('data-vl-scale')) > 0 ? Number(g.getAttribute('data-vl-scale')) : 1,
            pivot: arm ? (nums(arm.getAttribute('data-vl-pivot')).length === 2 ? nums(arm.getAttribute('data-vl-pivot')) : [0, 0])
                : [0, 0],
            rest: arm && Number.isFinite(Number(arm.getAttribute('data-vl-rest'))) ? Number(arm.getAttribute('data-vl-rest'))
                : 120,
            moodEls: Array.from(g.querySelectorAll('[data-vl-mood]')),
            posTry: nums(g.getAttribute('data-vl-pos-try')),
            pointTry: nums(g.getAttribute('data-vl-point-try')),
            angle: null,
        };
    });
    const tryDef = parseTry(svg);
    const binds = all('[data-vl-bind]').map((el) => {
        remember(el);
        const list = String(el.getAttribute('data-vl-bind')).split(';').map((part) => {
            const m = part.trim().match(/^([a-z][a-z0-9]{0,15}):([a-z0-9]+):(.+)$/i);
            if (!m || !BINDABLE.includes(m[2])) {
                return null;
            }
            const stops = m[3].split(',').map((s) => s.split('=').map(Number)).filter((p) => p.length === 2
                && p.every(Number.isFinite)).sort((a, b) => a[0] - b[0]);
            return stops.length >= 2 ? {id: m[1], attr: m[2], stops} : null;
        }).filter(Boolean);
        list.forEach((b) => {
            if (!['tx', 'ty', 'rotate'].includes(b.attr)) {
                orig.get(el).attrs[b.attr] = el.getAttribute(b.attr);
            }
        });
        return {el, list};
    }).filter((b) => b.list.length);
    const whens = all('[data-vl-when]').map((el) => ({el: remember(el) && el, conds: parseWhen(el.getAttribute('data-vl-when'))}));
    const texts = all('text[data-vl-text],tspan[data-vl-text]');

    /**
     * A value along piecewise straight stops.
     *
     * @param {number[][]} stops
     * @param {number} v
     * @returns {number}
     */
    const along = (stops, v) => {
        if (v <= stops[0][0]) {
            return stops[0][1];
        }
        for (let i = 1; i < stops.length; i++) {
            if (v <= stops[i][0]) {
                const [a, fa] = stops[i - 1];
                const [b, fb] = stops[i];
                return lerp(fa, fb, (v - a) / (b - a));
            }
        }
        return stops[stops.length - 1][1];
    };

    let values = null;
    let lastNow = 0;
    let stageNow = 1;
    let progressNow = 1;

    /**
     * Point an arm from its world pivot at a target.
     *
     * @param {Object} gd guide
     * @param {number[]} at guide position
     * @param {number[]} target
     * @returns {number} degrees
     */
    const aim = (gd, at, target) => {
        const px = at[0] + gd.pivot[0] * gd.scale;
        const py = at[1] + gd.pivot[1] * gd.scale;
        return Math.atan2(target[1] - py, target[0] - px) * 180 / Math.PI;
    };
    const turn = (a, b, u) => a + ((((b - a) % 360) + 540) % 360 - 180) * u;

    /**
     * Draw the scene at a stage and a point in it.
     *
     * @param {number} s stage 1-3
     * @param {number} u progress through it, 0-1
     */
    // eslint-disable-next-line complexity -- One pass over every kind of tag keeps each frame consistent.
    const render = (s, u) => {
        stageNow = s;
        progressNow = u;
        const trying = !!values;
        if (trying) {
            s = 3;
            u = 1;
        }
        // Camera: each stage glides from the one before in its first quarter.
        const k = reduced ? 1 : ease(clamp(u / 0.25));
        const cam = trying ? cameraTry : camera[s - 1].map((v, i) => lerp(v, camera[s][i], k));
        svg.setAttribute('viewBox', cam.map((v) => +v.toFixed(2)).join(' '));
        draws.forEach((el) => {
            const p = progressAt(stageOf(el, 'data-vl-draw'), windowOf(el, [0, 0.6]), s, reduced ? 1 : u);
            el.style.strokeDashoffset = String(1 - p);
        });
        shows.forEach((el) => {
            const o = orig.get(el);
            let vis = 1;
            const sn = stageOf(el, 'data-vl-show');
            if (sn) {
                vis *= progressAt(sn, windowOf(el, [0, 0.3]), s, reduced ? 1 : u);
            }
            const hn = stageOf(el, 'data-vl-hide');
            if (hn) {
                vis *= 1 - progressAt(hn, windowOf(el, [0, 0.3]), s, reduced ? 1 : u);
            }
            const say = stageOf(el, 'data-vl-say');
            if (say) {
                const w = windowOf(el, [0.05, 0.95]);
                vis *= !trying && s === say && u >= w[0] && u <= w[1] ? 1 : 0;
            }
            if (el.hasAttribute('data-vl-try-only')) {
                vis *= trying ? 1 : 0;
            }
            if (el.hasAttribute('data-vl-narration-only')) {
                vis *= trying ? 0 : 1;
            }
            el.style.opacity = String(o.opacity * vis);
            const centre = popCentre.get(el);
            if (centre) {
                const w = windowOf(el, [0, 0.3]);
                const n = stageOf(el, 'data-vl-show');
                const raw = s > n ? 1 : s < n ? 0 : clamp((u - w[0]) / Math.max(0.001, w[1] - w[0]));
                const k = reduced ? 1 : 0.6 + 0.4 * springy(raw);
                const [cx, cy] = centre;
                el.setAttribute('transform', ('translate(' + cx + ' ' + cy + ') scale(' + k.toFixed(4) + ') translate(' + -cx + ' '
                    + -cy + ') ' + o.transform).trim());
            }
        });
        moves.forEach((el) => {
            let dx = 0;
            let dy = 0;
            for (let n = 1; n <= 3; n++) {
                const v = nums(el.getAttribute('data-vl-move-' + n));
                if (v.length === 2) {
                    const p = progressAt(n, windowOf(el, [0, 0.6]), s, reduced ? 1 : u);
                    dx += v[0] * p;
                    dy += v[1] * p;
                }
            }
            el.setAttribute('transform', ('translate(' + dx.toFixed(2) + ' ' + dy.toFixed(2) + ') '
                + orig.get(el).transform).trim());
        });
        counts.forEach((el) => {
            const n = stageOf(el, 'data-vl-count');
            const from = Number(el.getAttribute('data-vl-from')) || 0;
            const to = Number(el.getAttribute('data-vl-to')) || 0;
            const curve = el.getAttribute('data-vl-curve') || 'linear';
            let p = progressAt(n, windowOf(el, [0.1, 0.9]), s, reduced ? 1 : u);
            if (trying && el.hasAttribute('data-vl-count-bind')) {
                p = bindProgress(el.getAttribute('data-vl-count-bind'), el.getAttribute('data-vl-count-when'));
            }
            const v = counted(from, to, p, curve);
            el.textContent = (el.getAttribute('data-vl-prefix') || '') + fmt(v, Number(el.getAttribute('data-vl-decimals')))
                + (el.getAttribute('data-vl-suffix') || '');
        });
        seqs.forEach((el) => {
            const kids = Array.from(el.children).filter((c) => !/^(title|desc)$/i.test(c.tagName));
            const n = stageOf(el, 'data-vl-seq');
            let p = progressAt(n, windowOf(el, [0.1, 0.9]), s, reduced ? 1 : u);
            if (trying && el.hasAttribute('data-vl-seq-bind')) {
                p = bindProgress(el.getAttribute('data-vl-seq-bind'), el.getAttribute('data-vl-seq-when'));
            }
            const shown = el.getAttribute('data-vl-curve') === 'double'
                ? Math.floor(Math.pow(2, p * Math.log2(Math.max(1, kids.length))) + 1e-9)
                : Math.floor(p * kids.length + 1e-9);
            kids.forEach((c, i) => {
                c.style.opacity = i < shown ? '' : '0';
            });
        });
        guides.forEach((gd) => {
            let at;
            if (trying) {
                at = gd.posTry.length === 2 ? gd.posTry : gd.pos[3];
            } else {
                at = gd.pos[s - 1].map((v, i) => lerp(v, gd.pos[s][i], reduced ? 1 : ease(clamp(u / 0.25))));
            }
            gd.at = at;
            // Pointing: the stage's targets in turn, each held for its share of the stage.
            let angle = gd.rest;
            if (trying) {
                if (gd.pointTry.length === 2) {
                    angle = aim(gd, at, gd.pointTry);
                }
            } else {
                const targets = gd.points[s];
                targets.forEach((t, i) => {
                    const a = i / targets.length + 0.04;
                    const b = (i + 1) / targets.length - 0.04;
                    const on = clamp((u - a) / 0.08) - clamp((u - b) / 0.06);
                    angle = turn(angle, aim(gd, at, t), ease(clamp(on)));
                });
            }
            gd.angle = angle;
            if (gd.arm) {
                gd.arm.setAttribute('transform', 'translate(' + gd.pivot.join(' ') + ') rotate(' + angle.toFixed(2) + ')');
            }
            // Mood: the stage's moods in turn, or the try-it rule's.
            let mood = '';
            if (trying) {
                const rule = tryDef && tryDef.rules.find((r) => holds(r.conds, values));
                mood = rule ? rule.mood : '';
            } else {
                const list = gd.moods[s];
                mood = list.length ? list[Math.min(list.length - 1, Math.floor(u * list.length))] : '';
            }
            if (!gd.moodEls.some((m) => m.getAttribute('data-vl-mood') === mood)) {
                mood = gd.moodEls.length ? gd.moodEls[0].getAttribute('data-vl-mood') : '';
            }
            gd.moodEls.forEach((m) => {
                m.style.opacity = m.getAttribute('data-vl-mood') === mood ? '' : '0';
            });
        });
        // Try it: the learner's values drive shapes, texts and what shows.
        if (trying) {
            binds.forEach(({el, list}) => {
                let tx = 0;
                let ty = 0;
                let rot = null;
                list.forEach((b) => {
                    if (!(b.id in values)) {
                        return;
                    }
                    const v = along(b.stops, values[b.id]);
                    if (b.attr === 'tx') {
                        tx = v;
                    } else if (b.attr === 'ty') {
                        ty = v;
                    } else if (b.attr === 'rotate') {
                        rot = v;
                    } else {
                        el.setAttribute(b.attr, String(+v.toFixed(2)));
                    }
                });
                el.setAttribute('transform', ('translate(' + tx.toFixed(2) + ' ' + ty.toFixed(2) + ')'
                    + (rot === null ? '' : ' rotate(' + rot.toFixed(2) + ')') + ' ' + orig.get(el).transform).trim());
            });
            whens.forEach(({el, conds}) => {
                el.style.opacity = holds(conds, values) ? String(orig.get(el).opacity) : '0';
            });
            texts.forEach((el) => {
                const v = linear(el.getAttribute('data-vl-text'), values);
                if (v !== null) {
                    el.textContent = (el.getAttribute('data-vl-prefix') || '') + fmt(v, Number(el.getAttribute('data-vl-decimals')))
                        + (el.getAttribute('data-vl-suffix') || '');
                }
            });
        } else {
            binds.forEach(({el}) => {
                const o = orig.get(el);
                Object.keys(o.attrs).forEach((a) => {
                    if (o.attrs[a] === null) {
                        el.removeAttribute(a);
                    } else {
                        el.setAttribute(a, o.attrs[a]);
                    }
                });
                if (!el.hasAttribute('data-vl-move-1') && !el.hasAttribute('data-vl-move-2')
                        && !el.hasAttribute('data-vl-move-3')) {
                    el.setAttribute('transform', o.transform);
                }
            });
            whens.forEach(({el}) => {
                if (!el.hasAttribute('data-vl-show') && !el.hasAttribute('data-vl-hide') && !el.hasAttribute('data-vl-try-only')) {
                    el.style.opacity = String(orig.get(el).opacity);
                }
            });
        }
        idle(typeof performance !== 'undefined' ? performance.now() : lastNow);
    };

    /**
     * How far a try-it binding has got: "id:lo..hi" maps the learner's value to 0-1; a failed gate gives 0.
     *
     * @param {string} bind
     * @param {string|null} gate
     * @returns {number}
     */
    const bindProgress = (bind, gate) => {
        const [cond] = parseWhen(bind);
        if (!cond || !(cond[0] in values) || (gate && !holds(parseWhen(gate), values))) {
            return 0;
        }
        const [id, lo, hi] = cond;
        return hi > lo && Number.isFinite(lo) && Number.isFinite(hi) ? clamp((values[id] - lo) / (hi - lo)) : 0;
    };

    /**
     * Small life between frames: the guide bobs and blinks (never with reduced motion).
     *
     * @param {number} now ms
     */
    const idle = (now) => {
        lastNow = now;
        const t = now / 1000;
        guides.forEach((gd) => {
            if (!gd.at) {
                return;
            }
            const bob = reduced ? 0 : Math.sin(t * 2.2) * 2.5;
            gd.g.setAttribute('transform', 'translate(' + gd.at[0].toFixed(2) + ' ' + (gd.at[1] + bob).toFixed(2) + ') scale('
                + gd.scale + ')');
            if (gd.blink && gd.blinkCentre) {
                const closed = !reduced && (t % 3.7) < 0.12;
                const [cx, cy] = gd.blinkCentre;
                gd.blink.setAttribute('transform', closed ? 'translate(' + cx + ' ' + cy + ') scale(1 .12) translate(' + -cx
                    + ' ' + -cy + ')' : '');
            }
        });
    };

    return {
        render,
        idle,
        tryDef,
        /**
         * Enter (values) or leave (null) the try-it step.
         *
         * @param {Object|null} v {id: value}
         */
        setTry(v) {
            values = v ? Object.assign({}, v) : null;
            render(stageNow, progressNow);
        },
        /**
         * The rule that matches the learner's values.
         *
         * @returns {Object|null} {say, tone, mood}
         */
        verdict() {
            return values && tryDef ? (tryDef.rules.find((r) => holds(r.conds, values)) || null) : null;
        },
        get trying() {
            return !!values;
        },
    };
};
