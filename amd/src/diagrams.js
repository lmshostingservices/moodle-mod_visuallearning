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
 * Animated explanatory diagrams, drawn as SVG from a specification cleaned by \mod_visuallearning\local\diagram.
 *
 * The geometry is computed, so phase angles, directions and proportions are exact. Each element appears from the card
 * it names (1 to 3), so the diagram builds up while the narrator reads the cards; things then keep moving (current
 * flowing, waveforms tracing, phasors turning, arrows pulsing) unless the learner prefers reduced motion.
 *
 * @module     mod_visuallearning/diagrams
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const NS = 'http://www.w3.org/2000/svg';
const W = 800;
const H = 600;

/** Accessible colours on white, and light tints of them. */
const PALETTE = {
    blue: ['#2563eb', '#dbeafe'],
    red: ['#dc2626', '#fee2e2'],
    green: ['#16a34a', '#dcfce7'],
    orange: ['#ea580c', '#ffedd5'],
    purple: ['#7c3aed', '#ede9fe'],
    teal: ['#0d9488', '#ccfbf1'],
    grey: ['#64748b', '#f1f5f9'],
};
const INK = '#1f2433';
const MUTED = '#5d6478';
const LINE = '#94a3b8';

const colour = (name) => (PALETTE[name] || PALETTE.grey)[0];
const tint = (name) => (PALETTE[name] || PALETTE.grey)[1];
const rad = (deg) => deg * Math.PI / 180;

/**
 * Create an SVG element.
 *
 * @param {string} tag
 * @param {Object} attrs
 * @param {Element} [parent]
 * @returns {SVGElement}
 */
const el = (tag, attrs = {}, parent = null) => {
    const node = document.createElementNS(NS, tag);
    Object.keys(attrs).forEach((k) => node.setAttribute(k, attrs[k]));
    if (parent) {
        parent.appendChild(node);
    }
    return node;
};

/**
 * A text element, on one or more lines.
 *
 * @param {Element} parent
 * @param {string|string[]} textValue
 * @param {number} x
 * @param {number} y
 * @param {Object} attrs
 * @returns {SVGElement}
 */
const text = (parent, textValue, x, y, attrs = {}) => {
    const lines = Array.isArray(textValue) ? textValue : [textValue];
    const size = parseFloat(attrs['font-size'] || 18);
    const node = el('text', Object.assign({x, y, 'font-size': size, fill: INK, 'text-anchor': 'middle'}, attrs), parent);
    const first = -((lines.length - 1) * size * 1.2) / 2;
    lines.forEach((line, i) => {
        const span = el('tspan', {x, dy: i === 0 ? first : size * 1.2}, node);
        span.textContent = line;
    });
    return node;
};

/**
 * Wrap text into lines of at most max characters (whole words where possible).
 *
 * @param {string} value
 * @param {number} max
 * @returns {string[]}
 */
const wrap = (value, max) => {
    const words = String(value || '').split(/\s+/).filter(Boolean);
    const lines = [];
    let line = '';
    words.forEach((word) => {
        if (line && (line + ' ' + word).length > max) {
            lines.push(line);
            line = word;
        } else {
            line = line ? line + ' ' + word : word;
        }
    });
    if (line) {
        lines.push(line);
    }
    return lines.slice(0, 3);
};

/**
 * An arrow head marker for a colour.
 *
 * @param {SVGElement} svg
 * @param {string} fill
 * @returns {string} url(#id)
 */
const marker = (svg, fill) => {
    const id = 'dg-arrow-' + fill.replace('#', '');
    if (!svg.querySelector('#' + id)) {
        const defs = svg.querySelector('defs') || el('defs', {}, svg);
        const m = el('marker', {id, viewBox: '0 0 10 10', refX: 8, refY: 5, markerWidth: 7, markerHeight: 7,
            orient: 'auto-start-reverse'}, defs);
        el('path', {d: 'M0,0 L10,5 L0,10 z', fill}, m);
    }
    return 'url(#' + id + ')';
};

/**
 * A group that appears from a card.
 *
 * @param {Element} parent
 * @param {number} card
 * @returns {SVGElement}
 */
const step = (parent, card) => el('g', {'class': 'dg-el', 'data-card': card || 1}, parent);

/**
 * Smooth start and stop for staged movement.
 *
 * @param {number} p 0 to 1
 * @returns {number}
 */
const ease = (p) => (p < 0.5 ? 4 * p * p * p : 1 - Math.pow(-2 * p + 2, 3) / 2);

/**
 * The value a staged quantity has reached: each card moves it from where the card before left it.
 *
 * @param {Object} stages card => stage
 * @param {string} key the stage field (to, turn)
 * @param {Object} st {card, p}
 * @param {number} start value before any card
 * @returns {number}
 */
const staged = (stages, key, st, start) => {
    let at = start;
    for (let card = 1; card <= 3; card++) {
        const stage = stages[card];
        if (!stage || stage[key] === undefined) {
            continue;
        }
        const from = key === 'to' && stage.from !== undefined ? stage.from : at;
        if (card < st.card) {
            at = stage[key];
        } else if (card === st.card) {
            return from + (stage[key] - from) * ease(st.p);
        } else if (st.card === 0 && key === 'to') {
            // Before anything is read the trace waits where card 1 starts.
            return from;
        }
    }
    return at;
};

/**
 * Whether any stage sets a field.
 *
 * @param {Object} stages
 * @param {string} key
 * @returns {boolean}
 */
const uses = (stages, key) => Object.values(stages).some((stage) => stage && stage[key] !== undefined);

/**
 * Draws one diagram type into the svg and returns its frame function (or null when nothing moves).
 */
const DRAW = {
    /**
     * Waveforms on one time axis.
     *
     * @param {SVGElement} svg
     * @param {Object} spec
     * @param {Object} box plot area
     * @returns {Function}
     */
    waveform(svg, spec, box) {
        const x0 = box.x + 40;
        const x1 = box.x + box.w - 70;
        const mid = box.y + box.h / 2;
        // Room under the wave for the period bracket and its label.
        const period = (spec.markers || []).some((m) => m.kind === 'period');
        const amp = box.h / 2 - (period ? 58 : 40);
        const cycles = spec.cycles || 2;
        const xAt = (t) => x0 + t * (x1 - x0);
        const yAt = (w, t) => mid - amp * w.amplitude * Math.sin(2 * Math.PI * cycles * t + rad(w.phase));
        // Axes.
        el('line', {x1: x0, y1: mid, x2: x1 + 30, y2: mid, stroke: LINE, 'stroke-width': 2,
            'marker-end': marker(svg, LINE)}, svg);
        el('line', {x1: x0, y1: mid + amp + 10, x2: x0, y2: mid - amp - 20, stroke: LINE, 'stroke-width': 2,
            'marker-end': marker(svg, LINE)}, svg);
        if (spec.xlabel) {
            text(svg, spec.xlabel, x1 + 20, mid + 30, {'font-size': 16, fill: MUTED, 'text-anchor': 'end'});
        }
        if (spec.ylabel) {
            text(svg, spec.ylabel, x0 + 8, mid - amp - 28, {'font-size': 16, fill: MUTED, 'text-anchor': 'start'});
        }
        // First rising zero crossing and first peak of a wave (t from 0 to 1).
        const crossing = (w) => {
            let t = (-rad(w.phase)) / (2 * Math.PI * cycles);
            while (t < 0) {
                t += 1 / cycles;
            }
            return t;
        };
        const peak = (w) => {
            let t = (Math.PI / 2 - rad(w.phase)) / (2 * Math.PI * cycles);
            while (t < 0) {
                t += 1 / cycles;
            }
            return t;
        };
        // Markers that sit behind the waves.
        (spec.markers || []).filter((m) => m.kind === 'halfcycles').forEach((m) => {
            const w = spec.waves[m.wave || 0];
            const g = step(svg, m.card);
            const start = crossing(w);
            [[start, start + 0.5 / cycles, w.colour], [start + 0.5 / cycles, start + 1 / cycles, 'grey']].forEach(([a, b, c]) => {
                let d = 'M' + xAt(a) + ',' + mid;
                for (let i = 0; i <= 40; i++) {
                    const t = a + (b - a) * i / 40;
                    d += ' L' + xAt(t).toFixed(1) + ',' + yAt(w, t).toFixed(1);
                }
                el('path', {d: d + ' L' + xAt(b) + ',' + mid + ' Z', fill: tint(c), opacity: 0.9}, g);
            });
            text(g, '+', xAt(start + 0.25 / cycles), mid - amp * w.amplitude * 0.35, {'font-size': 30, 'font-weight': 800,
                fill: colour(w.colour)});
            text(g, '−', xAt(start + 0.75 / cycles), mid + amp * w.amplitude * 0.45, {'font-size': 30, 'font-weight': 800,
                fill: MUTED});
            if (m.label) {
                text(g, m.label, (x0 + x1) / 2, box.y + 12, {'font-size': 16, fill: MUTED});
            }
        });
        const stages = spec.stages || {};
        const tracing = uses(stages, 'to');
        let clip = null;
        if (tracing) {
            // The waves stay faint ahead of the trace and are drawn boldly up to it.
            const id = 'dg-clip-' + Math.random().toString(36).slice(2);
            const cp = el('clipPath', {id}, el('defs', {}, svg));
            clip = el('rect', {x: 0, y: 0, width: x0, height: H}, cp);
            clip.dataset.id = id;
        }
        const paths = [];
        spec.waves.forEach((w) => {
            const g = step(svg, w.card);
            if (clip) {
                let ghost = '';
                for (let i = 0; i <= 240; i++) {
                    const t = i / 240;
                    ghost += (i ? ' L' : 'M') + xAt(t).toFixed(1) + ',' + yAt(w, t).toFixed(1);
                }
                el('path', {d: ghost, fill: 'none', stroke: colour(w.colour), 'stroke-width': 3, opacity: 0.22,
                    'stroke-dasharray': '2 7', 'stroke-linecap': 'round'}, g);
            }
            let d = '';
            for (let i = 0; i <= 240; i++) {
                const t = i / 240;
                d += (i ? ' L' : 'M') + xAt(t).toFixed(1) + ',' + yAt(w, t).toFixed(1);
            }
            const path = el('path', {d, fill: 'none', stroke: colour(w.colour), 'stroke-width': clip ? 5 : 4,
                'stroke-linecap': 'round', 'class': clip ? '' : 'dg-trace', pathLength: 1}, g);
            if (clip) {
                path.setAttribute('clip-path', 'url(#' + clip.dataset.id + ')');
            }
            paths.push(path);
            if (w.label) {
                // Legend, top right: a short line in the wave's colour and its label.
                const i = paths.length - 1;
                const ly = box.y + 6 + i * 26;
                el('line', {x1: box.x + box.w - 150, y1: ly, x2: box.x + box.w - 120, y2: ly, stroke: colour(w.colour),
                    'stroke-width': 4, 'stroke-linecap': 'round'}, g);
                text(g, w.label, box.x + box.w - 112, ly + 6, {'font-size': 17, 'font-weight': 700, fill: colour(w.colour),
                    'text-anchor': 'start'});
            }
        });
        // Markers on top.
        (spec.markers || []).forEach((m) => {
            const g = step(svg, m.card);
            if (m.kind === 'period') {
                const w = spec.waves[0];
                const a = xAt(crossing(w));
                const b = xAt(crossing(w) + 1 / cycles);
                const y = mid + amp + 22;
                el('path', {d: `M${a},${y - 8} L${a},${y} L${b},${y} L${b},${y - 8}`, fill: 'none', stroke: INK,
                    'stroke-width': 2}, g);
                if (m.label) {
                    text(g, m.label, (a + b) / 2, y + 24, {'font-size': 17, 'font-weight': 700});
                }
            } else if (m.kind === 'lag') {
                const from = spec.waves[m.from];
                const to = spec.waves[m.to];
                const a = xAt(peak(from));
                let tb = peak(to);
                while (xAt(tb) < a) {
                    tb += 1 / cycles;
                }
                const b = xAt(tb);
                const y = mid - amp - 8;
                el('line', {x1: a, y1: yAt(from, peak(from)), x2: a, y2: y, stroke: colour(from.colour), 'stroke-width': 1.5,
                    'stroke-dasharray': '4 4'}, g);
                el('line', {x1: b, y1: yAt(to, tb), x2: b, y2: y, stroke: colour(to.colour), 'stroke-width': 1.5,
                    'stroke-dasharray': '4 4'}, g);
                el('line', {x1: a, y1: y, x2: b, y2: y, stroke: INK, 'stroke-width': 2, 'marker-start': marker(svg, INK),
                    'marker-end': marker(svg, INK)}, g);
                if (m.label) {
                    text(g, m.label, (a + b) / 2, y - 12, {'font-size': 17, 'font-weight': 700});
                }
            } else if (m.kind === 'peak') {
                const w = spec.waves[m.wave || 0];
                const t = peak(w);
                el('circle', {cx: xAt(t), cy: yAt(w, t), r: 6, fill: colour(w.colour)}, g);
                if (m.label) {
                    text(g, m.label, xAt(t), yAt(w, t) - 16, {'font-size': 16, 'font-weight': 700, fill: colour(w.colour)});
                }
            }
        });
        // A cursor sweeping along the time axis, with a dot riding each visible wave.
        const cursor = el('line', {y1: mid - amp - 4, y2: mid + amp + 4, stroke: INK, 'stroke-width': 1.5, opacity: 0.35}, svg);
        const dots = spec.waves.map((w) => el('circle', {r: 7, fill: colour(w.colour), stroke: '#fff', 'stroke-width': 2,
            'class': 'dg-dot', 'data-card': w.card}, svg));
        return (time, shown, st) => {
            // Staged: the trace runs from where the card before stopped to where this card ends, then waits.
            const t = tracing ? staged(stages, 'to', st, 0) / (360 * cycles) : (time / 6000) % 1;
            const x = xAt(t);
            if (clip) {
                clip.setAttribute('width', Math.max(0, x).toFixed(1));
            }
            cursor.setAttribute('x1', x);
            cursor.setAttribute('x2', x);
            dots.forEach((dot, i) => {
                const w = spec.waves[i];
                dot.setAttribute('cx', x);
                dot.setAttribute('cy', yAt(w, t));
                dot.style.opacity = w.card <= shown ? 1 : 0;
            });
        };
    },

    /**
     * Phasors from one origin, optionally turning together.
     *
     * @param {SVGElement} svg
     * @param {Object} spec
     * @param {Object} box
     * @returns {Function}
     */
    phasor(svg, spec, box) {
        const cx = box.x + box.w / 2;
        const cy = box.y + box.h / 2;
        const r = Math.min(box.w, box.h) / 2 - 50;
        el('circle', {cx, cy, r, fill: 'none', stroke: '#e2e8f0', 'stroke-width': 2, 'stroke-dasharray': '6 6'}, svg);
        el('line', {x1: cx - r - 20, y1: cy, x2: cx + r + 20, y2: cy, stroke: LINE, 'stroke-width': 1.5}, svg);
        el('line', {x1: cx, y1: cy + r + 20, x2: cx, y2: cy - r - 20, stroke: LINE, 'stroke-width': 1.5}, svg);
        const parts = spec.vectors.map((v) => {
            const g = step(svg, v.card);
            const line = el('line', {x1: cx, y1: cy, stroke: colour(v.colour), 'stroke-width': 5, 'stroke-linecap': 'round',
                'marker-end': marker(svg, colour(v.colour))}, g);
            const label = v.label ? text(g, v.label, 0, 0, {'font-size': 19, 'font-weight': 700, fill: colour(v.colour)}) : null;
            return {v, line, label};
        });
        const arcs = (spec.arcs || []).map((a, i) => {
            const g = step(svg, a.card);
            const path = el('path', {fill: 'none', stroke: INK, 'stroke-width': 2, 'marker-end': marker(svg, INK)}, g);
            const label = a.label ? text(g, a.label, 0, 0, {'font-size': 16, 'font-weight': 700}) : null;
            return {a, path, label, radius: 70 + i * 34};
        });
        el('circle', {cx, cy, r: 6, fill: INK}, svg);
        const place = (spin) => {
            parts.forEach(({v, line, label}) => {
                const ang = rad(v.angle + spin);
                const x = cx + r * v.length * Math.cos(ang);
                const y = cy - r * v.length * Math.sin(ang);
                line.setAttribute('x2', x.toFixed(1));
                line.setAttribute('y2', y.toFixed(1));
                if (label) {
                    label.setAttribute('transform', `translate(${(cx + (r * v.length + 26) * Math.cos(ang)).toFixed(1)},` +
                        `${(cy - (r * v.length + 26) * Math.sin(ang) + 6).toFixed(1)})`);
                }
            });
            arcs.forEach(({a, path, label, radius}) => {
                const from = spec.vectors[a.from].angle + spin;
                const to = spec.vectors[a.to].angle + spin;
                const p = (deg) => [cx + radius * Math.cos(rad(deg)), cy - radius * Math.sin(rad(deg))];
                const [ax, ay] = p(from);
                const [bx, by] = p(to);
                const sweep = to < from ? 1 : 0;
                const large = Math.abs(to - from) > 180 ? 1 : 0;
                path.setAttribute('d', `M${ax.toFixed(1)},${ay.toFixed(1)} A${radius},${radius} 0 ${large} ${sweep} ` +
                    `${bx.toFixed(1)},${by.toFixed(1)}`);
                if (label) {
                    const [lx, ly] = [cx + (radius + 22) * Math.cos(rad((from + to) / 2)),
                        cy - (radius + 22) * Math.sin(rad((from + to) / 2))];
                    label.setAttribute('transform', `translate(${lx.toFixed(1)},${(ly + 6).toFixed(1)})`);
                }
            });
        };
        place(0);
        const stages = spec.stages || {};
        if (uses(stages, 'turn')) {
            // Staged: the vectors turn together to each card's angle, from where the card before left them.
            return (time, shown, st) => place(staged(stages, 'turn', st, 0));
        }
        return spec.rotate ? (time) => place((time / 8000 * 360) % 360) : null;
    },

    /**
     * A series circuit with current flowing round the loop.
     *
     * @param {SVGElement} svg
     * @param {Object} spec
     * @param {Object} box
     * @returns {Function}
     */
    circuit(svg, spec, box) {
        const left = box.x + 90;
        const right = box.x + box.w - 90;
        const top = box.y + 60;
        const bottom = box.y + box.h - 60;
        const wire = {stroke: INK, 'stroke-width': 4, fill: 'none', 'stroke-linejoin': 'round'};
        el('rect', Object.assign({x: left, y: top, width: right - left, height: bottom - top, rx: 8}, wire), svg);
        // The loop as one path for the moving current: clockwise from the source (middle of the left side).
        const perimeter = 2 * (right - left) + 2 * (bottom - top);
        const along = (s) => {
            let d = ((s % perimeter) + perimeter) % perimeter;
            const legs = [[left, (top + bottom) / 2, 0, -1, (bottom - top) / 2], [left, top, 1, 0, right - left],
                [right, top, 0, 1, bottom - top], [right, bottom, -1, 0, right - left],
                [left, bottom, 0, -1, (bottom - top) / 2]];
            for (const [x, y, dx, dy, len] of legs) {
                if (d <= len) {
                    return [x + dx * d, y + dy * d];
                }
                d -= len;
            }
            return [left, (top + bottom) / 2];
        };
        // Source.
        const sg = step(svg, spec.source.card);
        const sy = (top + bottom) / 2;
        el('rect', {x: left - 34, y: sy - 34, width: 68, height: 68, fill: '#fff'}, sg);
        if (spec.source.kind === 'ac') {
            el('circle', {cx: left, cy: sy, r: 30, fill: '#fff', stroke: INK, 'stroke-width': 4}, sg);
            el('path', {d: `M${left - 18},${sy} C${left - 10},${sy - 22} ${left - 2},${sy - 22} ${left},${sy} ` +
                `S${left + 10},${sy + 22} ${left + 18},${sy}`, fill: 'none', stroke: INK, 'stroke-width': 3}, sg);
        } else {
            el('line', {x1: left - 26, y1: sy - 8, x2: left + 26, y2: sy - 8, stroke: INK, 'stroke-width': 4}, sg);
            el('line', {x1: left - 14, y1: sy + 8, x2: left + 14, y2: sy + 8, stroke: INK, 'stroke-width': 7}, sg);
            text(sg, '+', left + 38, sy - 6, {'font-size': 20, 'font-weight': 700});
        }
        if (spec.source.label) {
            text(sg, spec.source.label, left + 44, sy + 6, {'font-size': 17, 'font-weight': 700, 'text-anchor': 'start'});
        }
        // Components: along the top, then the bottom.
        const n = spec.components.length;
        const onTop = Math.ceil(n / 2);
        const slots = [];
        for (let i = 0; i < onTop; i++) {
            slots.push([left + (right - left) * (i + 1) / (onTop + 1), top, -1]);
        }
        for (let i = 0; i < n - onTop; i++) {
            slots.push([right - (right - left) * (i + 1) / (n - onTop + 1), bottom, 1]);
        }
        let inductor = null;
        spec.components.forEach((c, i) => {
            const [x, y, side] = slots[i];
            const g = step(svg, c.card);
            el('rect', {x: x - 50, y: y - 22, width: 100, height: 44, fill: '#fff'}, g);
            const s = {stroke: INK, 'stroke-width': 4, fill: 'none'};
            if (c.kind === 'resistor') {
                el('line', Object.assign({x1: x - 50, y1: y, x2: x - 36, y2: y}, s), g);
                el('line', Object.assign({x1: x + 36, y1: y, x2: x + 50, y2: y}, s), g);
                el('rect', Object.assign({x: x - 36, y: y - 14, width: 72, height: 28, fill: '#fff'}, s), g);
            } else if (c.kind === 'inductor') {
                let d = `M${x - 50},${y} L${x - 40},${y}`;
                for (let k = 0; k < 4; k++) {
                    d += ` a10,13 0 0 1 20,0`;
                }
                d += ` L${x + 50},${y}`;
                el('path', Object.assign({d}, s), g);
                inductor = {x, y, g, side};
            } else if (c.kind === 'capacitor') {
                el('line', Object.assign({x1: x - 50, y1: y, x2: x - 7, y2: y}, s), g);
                el('line', Object.assign({x1: x + 7, y1: y, x2: x + 50, y2: y}, s), g);
                el('line', Object.assign({x1: x - 7, y1: y - 20, x2: x - 7, y2: y + 20}, s), g);
                el('line', Object.assign({x1: x + 7, y1: y - 20, x2: x + 7, y2: y + 20}, s), g);
            } else if (c.kind === 'switch') {
                el('line', Object.assign({x1: x - 50, y1: y, x2: x - 22, y2: y}, s), g);
                el('line', Object.assign({x1: x + 22, y1: y, x2: x + 50, y2: y}, s), g);
                el('circle', {cx: x - 22, cy: y, r: 4, fill: INK}, g);
                el('circle', {cx: x + 22, cy: y, r: 4, fill: INK}, g);
                el('line', Object.assign({x1: x - 22, y1: y, x2: x + 22, y2: y - 4}, s), g);
            } else {
                el('line', Object.assign({x1: x - 50, y1: y, x2: x - 22, y2: y}, s), g);
                el('line', Object.assign({x1: x + 22, y1: y, x2: x + 50, y2: y}, s), g);
                el('circle', Object.assign({cx: x, cy: y, r: 22, fill: '#fff'}, s), g);
                if (c.kind === 'lamp') {
                    el('path', Object.assign({d: `M${x - 15},${y - 15} L${x + 15},${y + 15} M${x + 15},${y - 15} ` +
                        `L${x - 15},${y + 15}`}, s, {'stroke-width': 3}), g);
                } else {
                    const letter = c.kind === 'motor' ? 'M' : (/^v/i.test(c.label) ? 'V' : 'A');
                    text(g, letter, x, y + 7, {'font-size': 20, 'font-weight': 800});
                }
            }
            if (c.label) {
                // Beside an inductor's magnetic field the label goes inside the loop, below the field.
                const field = c.kind === 'inductor' && spec.current && spec.current.show;
                const ly = field ? y - side * 96 + (side < 0 ? 6 : 0) : y + side * 44 + (side > 0 ? 8 : 0);
                text(g, c.label, x, ly, {'font-size': 17, 'font-weight': 700});
            }
        });
        // Current: dots round the loop; alternating current swings back and forth.
        const cur = spec.current;
        if (!cur || !cur.show) {
            return null;
        }
        const cg = step(svg, cur.card);
        const count = 14;
        const dots = [];
        for (let i = 0; i < count; i++) {
            dots.push(el('circle', {r: 6, fill: colour(cur.colour), stroke: '#fff', 'stroke-width': 1.5}, cg));
        }
        let field = null;
        if (inductor) {
            // The breathing happens on an inner group, so it never overrides the reveal.
            field = el('g', {}, step(svg, cur.card));
            [1, 2, 3].forEach((k) => {
                el('ellipse', {cx: inductor.x, cy: inductor.y, rx: 50 + k * 16, ry: 24 + k * 14, fill: 'none',
                    stroke: colour('green'), 'stroke-width': 2, 'stroke-dasharray': '6 6', 'class': 'dg-field'}, field);
            });
        }
        let pointer = null;
        if (cur.label) {
            text(cg, cur.label, right - 10, top + 40, {'font-size': 17, 'font-weight': 700, fill: colour(cur.colour),
                'text-anchor': 'end'});
            const dir = cur.clockwise ? 1 : -1;
            pointer = el('line', {x1: right - 130 - 30 * dir, y1: top + 56, x2: right - 130 + 30 * dir, y2: top + 56,
                stroke: colour(cur.colour), 'stroke-width': 3, 'marker-end': marker(svg, colour(cur.colour)),
                'marker-start': cur.alternating ? marker(svg, colour(cur.colour)) : ''}, cg);
        }
        const dir = cur.clockwise ? 1 : -1;
        const stages = spec.stages || {};
        const staging = uses(stages, 'flow');
        // Staged flow: each card sets the way the current goes (the dots carry on from where they are).
        let travelled = 0;
        let last = null;
        return (time, shown, st) => {
            let offset;
            let strength;
            if (staging && st.card && stages[st.card] && stages[st.card].flow) {
                const flow = stages[st.card].flow;
                const way = flow === 'stopped' ? 0 : (flow === 'clockwise' ? 1 : -1);
                travelled += last === null ? 0 : (time - last) / 1000 * 70 * way;
                offset = travelled;
                strength = way === 0 ? 0 : 1;
                if (pointer) {
                    pointer.style.opacity = way === 0 ? 0 : 1;
                    pointer.setAttribute('transform', way === -dir ? `rotate(180 ${right - 130} ${top + 56})` : '');
                }
                last = time;
                dots.forEach((dot, i) => {
                    const [x, y] = along(offset + i * perimeter / count);
                    dot.setAttribute('cx', x.toFixed(1));
                    dot.setAttribute('cy', y.toFixed(1));
                });
                if (field) {
                    field.style.opacity = (0.15 + 0.85 * strength).toFixed(2);
                }
                return;
            }
            last = time;
            offset = cur.alternating ? Math.sin(time / 2000 * 2 * Math.PI) * perimeter / count * 1.6
                : (time / 1000 * 70) % perimeter;
            dots.forEach((dot, i) => {
                const [x, y] = along(dir * offset + i * perimeter / count);
                dot.setAttribute('cx', x.toFixed(1));
                dot.setAttribute('cy', y.toFixed(1));
            });
            if (field) {
                // The field grows and collapses with the current.
                const strength = cur.alternating ? Math.abs(Math.cos(time / 2000 * 2 * Math.PI)) : 1;
                field.style.opacity = (0.25 + 0.75 * strength).toFixed(2);
            }
        };
    },

    /**
     * Steps of a process from left to right, with inputs and outputs.
     *
     * @param {SVGElement} svg
     * @param {Object} spec
     * @param {Object} box
     * @returns {Function}
     */
    process(svg, spec, box) {
        const n = spec.steps.length;
        const gap = 46;
        const bw = Math.min(200, (box.w - gap * (n - 1)) / n);
        const bh = 130;
        const cy = box.y + box.h / 2;
        const startx = box.x + (box.w - (bw * n + gap * (n - 1))) / 2;
        const chars = Math.max(9, Math.floor(bw / 11));
        const pulses = [];
        spec.steps.forEach((s, i) => {
            const x = startx + i * (bw + gap);
            const g = step(svg, s.card);
            g.dataset.focus = i + 1;
            el('rect', {x, y: cy - bh / 2, width: bw, height: bh, rx: 16, fill: tint(s.colour), stroke: colour(s.colour),
                'stroke-width': 3, 'class': 'dg-box', 'data-index': i}, g);
            el('circle', {cx: x + 22, cy: cy - bh / 2 + 22, r: 14, fill: colour(s.colour)}, g);
            text(g, String(i + 1), x + 22, cy - bh / 2 + 28, {'font-size': 15, 'font-weight': 800, fill: '#fff'});
            text(g, wrap(s.label, chars), x + bw / 2, cy - (s.detail ? 10 : 0) + 6, {'font-size': bw < 140 ? 15 : 17,
                'font-weight': 700});
            if (s.detail) {
                text(g, wrap(s.detail, chars + 2), x + bw / 2, cy + 40, {'font-size': 13, fill: MUTED});
            }
            if (i < n - 1) {
                const a = x + bw + 4;
                const b = x + bw + gap - 4;
                const ag = step(svg, spec.steps[i + 1].card);
                el('line', {x1: a, y1: cy, x2: b, y2: cy, stroke: LINE, 'stroke-width': 3, 'marker-end': marker(svg, LINE)}, ag);
                pulses.push([el('circle', {cy, r: 5, fill: colour(s.colour)}, ag), a, b - 8]);
            }
        });
        const pill = (g, label, px, y, name) => {
            const w = Math.max(80, label.length * 9 + 28);
            const x = Math.max(box.x + w / 2 + 4, Math.min(box.x + box.w - w / 2 - 4, px));
            el('rect', {x: x - w / 2, y: y - 18, width: w, height: 36, rx: 18, fill: tint(name), stroke: colour(name),
                'stroke-width': 2}, g);
            text(g, label, x, y + 6, {'font-size': 15, 'font-weight': 700, fill: colour(name)});
        };
        const first = startx + bw / 2;
        (spec.inputs || []).forEach((item, i, list) => {
            const g = step(svg, item.card);
            const x = first + (i - (list.length - 1) / 2) * 135;
            pill(g, item.label, x, cy - bh / 2 - 90, 'blue');
            el('line', {x1: x, y1: cy - bh / 2 - 70, x2: first + (x - first) * 0.3, y2: cy - bh / 2 - 6, stroke: LINE,
                'stroke-width': 2.5, 'marker-end': marker(svg, LINE)}, g);
        });
        const last = startx + (n - 1) * (bw + gap) + bw / 2;
        (spec.outputs || []).forEach((item, i, list) => {
            const g = step(svg, item.card);
            const x = last + (i - (list.length - 1) / 2) * 120;
            pill(g, item.label, x, cy + bh / 2 + 90, 'green');
            el('line', {x1: last + (x - last) * 0.3, y1: cy + bh / 2 + 6, x2: x, y2: cy + bh / 2 + 70, stroke: LINE,
                'stroke-width': 2.5, 'marker-end': marker(svg, LINE)}, g);
        });
        return (time) => {
            const t = (time / 1600) % 1;
            pulses.forEach(([dot, a, b]) => dot.setAttribute('cx', (a + (b - a) * t).toFixed(1)));
        };
    },

    /**
     * Steps round a loop; the highlight travels round it.
     *
     * @param {SVGElement} svg
     * @param {Object} spec
     * @param {Object} box
     * @returns {Function}
     */
    cycle(svg, spec, box) {
        const n = spec.steps.length;
        const cx = box.x + box.w / 2;
        const cy = box.y + box.h / 2;
        const rx = box.w / 2 - 120;
        const ry = box.h / 2 - 60;
        const at = (i) => {
            const a = -Math.PI / 2 + 2 * Math.PI * i / n;
            return [cx + rx * Math.cos(a), cy + ry * Math.sin(a), a];
        };
        const boxes = [];
        spec.steps.forEach((s, i) => {
            const [x, y] = at(i);
            const ag = step(svg, spec.steps[(i + 1) % n].card);
            // Arrow along the loop to the next step.
            const a1 = -Math.PI / 2 + 2 * Math.PI * (i + 0.28) / n;
            const a2 = -Math.PI / 2 + 2 * Math.PI * (i + 0.72) / n;
            el('path', {d: `M${(cx + rx * Math.cos(a1)).toFixed(1)},${(cy + ry * Math.sin(a1)).toFixed(1)} ` +
                `A${rx},${ry} 0 0 1 ${(cx + rx * Math.cos(a2)).toFixed(1)},${(cy + ry * Math.sin(a2)).toFixed(1)}`,
                fill: 'none', stroke: LINE, 'stroke-width': 3, 'marker-end': marker(svg, LINE)}, ag);
            const g = step(svg, s.card);
            g.dataset.focus = i + 1;
            const bw = 190;
            const bh = s.detail ? 92 : 70;
            const rect = el('rect', {x: x - bw / 2, y: y - bh / 2, width: bw, height: bh, rx: 16, fill: tint(s.colour),
                stroke: colour(s.colour), 'stroke-width': 3}, g);
            text(g, wrap(s.label, 18), x, y + (s.detail ? -8 : 6), {'font-size': 16, 'font-weight': 700});
            if (s.detail) {
                text(g, wrap(s.detail, 24).slice(0, 2), x, y + 28, {'font-size': 12, fill: MUTED});
            }
            boxes.push(rect);
        });
        const focused = uses(spec.stages || {}, 'focus');
        return (time) => {
            if (focused) {
                // Staged: the card's step is highlighted instead of a highlight travelling round.
                return;
            }
            const active = Math.floor(time / 1500) % n;
            boxes.forEach((rect, i) => rect.setAttribute('stroke-width', i === active ? 6 : 3));
        };
    },

    /**
     * Forces on one object, arrows pulsing; the object may drift in its direction of motion.
     *
     * @param {SVGElement} svg
     * @param {Object} spec
     * @param {Object} box
     * @returns {Function}
     */
    forces(svg, spec, box) {
        const cx = box.x + box.w / 2;
        const cy = box.y + box.h / 2;
        const body = el('g', {}, svg);
        const ow = 170;
        const oh = 120;
        el('rect', {x: cx - ow / 2, y: cy - oh / 2, width: ow, height: oh, rx: 14, fill: '#f1f5f9', stroke: INK,
            'stroke-width': 3}, body);
        if (spec.object) {
            text(body, wrap(spec.object, 14), cx, cy - (spec.motion ? 6 : -6), {'font-size': 18, 'font-weight': 700});
        }
        // Where a ray from the centre leaves the object.
        const edge = (a) => {
            const dx = Math.cos(a);
            const dy = -Math.sin(a);
            const k = Math.min(Math.abs((ow / 2) / (dx || 1e-9)), Math.abs((oh / 2) / (dy || 1e-9)));
            return [cx + dx * k, cy + dy * k];
        };
        const arrows = spec.forces.map((f, i) => {
            const g = step(svg, f.card);
            g.dataset.focus = i + 1;
            const a = rad(f.angle);
            const [sx, sy] = edge(a);
            // Room to the edge of the drawing in this direction, less space for the label.
            const roomx = Math.cos(a) > 0.01 ? (box.x + box.w - sx) / Math.cos(a)
                : (Math.cos(a) < -0.01 ? (box.x - sx) / Math.cos(a) : Infinity);
            const roomy = -Math.sin(a) > 0.01 ? (box.y + box.h - sy) / -Math.sin(a)
                : (-Math.sin(a) < -0.01 ? (box.y - sy) / -Math.sin(a) : Infinity);
            const len = Math.max(50, Math.min(roomx, roomy) - 60) * (0.35 + 0.65 * f.magnitude);
            const ex = sx + Math.cos(a) * len;
            const ey = sy - Math.sin(a) * len;
            const line = el('line', {x1: sx, y1: sy, x2: ex, y2: ey, stroke: colour(f.colour), 'stroke-width': 7,
                'stroke-linecap': 'round', 'marker-end': marker(svg, colour(f.colour))}, g);
            if (f.label) {
                text(g, f.label, ex + Math.cos(a) * 34, ey - Math.sin(a) * 30 + 6, {'font-size': 18, 'font-weight': 700,
                    fill: colour(f.colour)});
            }
            return {line, sx, sy, a, len};
        });
        let motion = null;
        if (spec.motion) {
            // The object drifts in its direction of motion; an arrow and the label sit inside it, under its name.
            const g = step(svg, spec.motion.card);
            const a = rad(spec.motion.angle);
            const arrows = ['→', '↗', '↑', '↖', '←', '↙', '↓', '↘'];
            const sym = arrows[Math.round((((spec.motion.angle % 360) + 360) % 360) / 45) % 8];
            text(g, sym + ' ' + (spec.motion.label || ''), cx, cy + oh / 2 - 16, {'font-size': 15, 'font-weight': 700,
                fill: MUTED});
            body.appendChild(g);
            motion = {a, card: spec.motion.card};
        }
        return (time, shown) => {
            const k = 0.92 + 0.08 * Math.sin(time / 600);
            arrows.forEach(({line, sx, sy, a, len}) => {
                line.setAttribute('x2', (sx + Math.cos(a) * len * k).toFixed(1));
                line.setAttribute('y2', (sy - Math.sin(a) * len * k).toFixed(1));
            });
            if (motion && motion.card <= shown) {
                const d = 12 * Math.sin(time / 900);
                body.setAttribute('transform', `translate(${(Math.cos(motion.a) * d).toFixed(1)},${(-Math.sin(motion.a) * d)
                    .toFixed(1)})`);
            }
        };
    },

    /**
     * Bars comparing values; they grow when they appear.
     *
     * @param {SVGElement} svg
     * @param {Object} spec
     * @param {Object} box
     * @returns {null}
     */
    bars(svg, spec, box) {
        const n = spec.bars.length;
        const base = box.y + box.h - 60;
        const topy = box.y + 50;
        const max = Math.max(...spec.bars.map((b) => b.value)) * 1.15 || 1;
        const slot = (box.w - 80) / n;
        const bw = Math.min(110, slot * 0.6);
        el('line', {x1: box.x + 40, y1: base, x2: box.x + box.w - 40, y2: base, stroke: LINE, 'stroke-width': 2}, svg);
        spec.bars.forEach((b, i) => {
            const g = step(svg, b.card);
            g.dataset.focus = i + 1;
            const x = box.x + 40 + slot * i + (slot - bw) / 2;
            const h = (base - topy) * b.value / max;
            el('rect', {x, y: base - h, width: bw, height: h, rx: 8, fill: colour(b.colour), 'class': 'dg-bar',
                style: `transform-origin: ${x + bw / 2}px ${base}px`}, g);
            const value = (Math.round(b.value * 1000) / 1000) + (spec.unit ? ' ' + spec.unit : '');
            text(g, value, x + bw / 2, base - h - 12, {'font-size': 18, 'font-weight': 800});
            text(g, wrap(b.label, 14), x + bw / 2, base + 28, {'font-size': 15, fill: MUTED});
        });
        return null;
    },
};

/**
 * Draw a diagram.
 *
 * The controller builds it card by card: reveal(card) shows what appears on a card; stage(card, ms) also plays that
 * card's movement (from the spec's stages) once over ms, the length of the card's clip, and shows its caption.
 *
 * @param {HTMLElement} container emptied and filled with the SVG
 * @param {Object} spec cleaned specification
 * @param {Object} [options] {label: accessible description, reduced: no movement}
 * @returns {Object|null} {svg, reveal, stage, retime, finish, pause, destroy}
 */
export const render = (container, spec, options = {}) => {
    if (!container || !spec || !DRAW[spec.type]) {
        return null;
    }
    container.innerHTML = '';
    const svg = el('svg', {viewBox: `0 0 ${W} ${H}`, 'class': 'dg-svg', role: 'img', 'aria-label': options.label || ''});
    container.appendChild(svg);
    el('rect', {x: 0, y: 0, width: W, height: H, rx: 24, fill: '#fff'}, svg);
    let top = 20;
    if (spec.title) {
        text(svg, spec.title, W / 2, 50, {'font-size': 24, 'font-weight': 800});
        top = 80;
    }
    const stages = spec.stages || {};
    const captioned = Object.values(stages).some((stage) => stage && stage.note);
    const frame = DRAW[spec.type](svg, spec, {x: 20, y: top, w: W - 40, h: H - top - (captioned ? 64 : 20)});
    // The caption of the card being read, in a band along the bottom.
    let caption = null;
    if (captioned) {
        const band = el('g', {'class': 'dg-note'}, svg);
        const pill = el('rect', {x: W / 2 - 200, y: H - 58, width: 400, height: 40, rx: 20, fill: '#1f2433'}, band);
        const words = text(band, '', W / 2, H - 31, {'font-size': 18, 'font-weight': 700, fill: '#fff'});
        caption = {band, pill, words};
    }
    const reduced = options.reduced !== undefined ? options.reduced
        : window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    svg.classList.toggle('is-still', !!reduced);
    let shown = 0;
    let raf = 0;
    const start = performance.now();
    // The card whose movement is playing: when it started, how long it lasts, and when it was paused.
    const play = {card: 0, start: 0, length: 1, paused: null};
    const state = (now) => {
        if (!play.card) {
            return {card: 0, p: 0};
        }
        const at = (play.paused === null ? now : play.paused) - play.start;
        return {card: play.card, p: reduced ? 1 : Math.max(0, Math.min(1, at / play.length))};
    };
    const draw = (now) => {
        if (frame) {
            frame(reduced ? 1500 : now - start, shown, state(now));
        }
    };
    const tick = (now) => {
        if (!svg.isConnected) {
            raf = 0;
            return;
        }
        draw(now);
        raf = window.requestAnimationFrame(tick);
    };
    const focus = (card) => {
        const stage = stages[card];
        const target = stage && stage.focus ? String(stage.focus) : null;
        svg.querySelectorAll('[data-focus]').forEach((g) => {
            g.classList.toggle('is-dim', target !== null && g.dataset.focus !== target);
            g.classList.toggle('is-focus', target !== null && g.dataset.focus === target);
        });
        if (caption) {
            const note = stage && stage.note ? stage.note : '';
            caption.words.textContent = note;
            const width = Math.min(W - 40, Math.max(160, note.length * 10.5 + 48));
            caption.pill.setAttribute('x', ((W - width) / 2).toFixed(1));
            caption.pill.setAttribute('width', width.toFixed(1));
            caption.band.classList.toggle('is-on', note !== '');
        }
    };
    const controller = {
        svg,
        /**
         * Show every element that appears from this card or earlier.
         *
         * @param {number} card
         */
        reveal(card) {
            shown = Math.max(shown, card);
            svg.querySelectorAll('.dg-el').forEach((g) => {
                g.classList.toggle('is-on', Number(g.dataset.card) <= shown);
            });
            draw(performance.now());
        },
        /**
         * Play a card: show what appears on it and play its movement once, over the given time.
         *
         * @param {number} card
         * @param {number} ms how long the card is read
         */
        stage(card, ms) {
            controller.reveal(card);
            Object.assign(play, {card, start: performance.now(), length: Math.max(800, ms || 0), paused: null});
            focus(card);
            draw(performance.now());
        },
        /**
         * The card's clip turned out longer or shorter than estimated: its movement keeps pace with it.
         *
         * @param {number} ms
         */
        retime(ms) {
            if (play.card && ms > 0 && isFinite(ms)) {
                play.length = Math.max(800, ms);
            }
        },
        /**
         * Show a card with its movement already played (for a page the learner has heard).
         *
         * @param {number} card
         */
        finish(card) {
            controller.reveal(card);
            Object.assign(play, {card, start: -1e9, length: 1, paused: null});
            focus(card);
            draw(performance.now());
        },
        /**
         * Hold or resume the movement with the narration.
         *
         * @param {boolean} on
         */
        pause(on) {
            const now = performance.now();
            if (on && play.paused === null) {
                play.paused = now;
            } else if (!on && play.paused !== null) {
                play.start += now - play.paused;
                play.paused = null;
            }
        },
        /**
         * Stop moving.
         */
        destroy() {
            if (raf) {
                window.cancelAnimationFrame(raf);
                raf = 0;
            }
        },
    };
    controller.reveal(1);
    if (frame && !reduced) {
        raf = window.requestAnimationFrame(tick);
    }
    return controller;
};
