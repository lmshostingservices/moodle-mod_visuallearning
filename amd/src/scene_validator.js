/**
 * Portable animated_scene v1.0 boundary validation. No browser globals.
 * Structural rules mirror schema/animated-scene.json; semantic rules are mandatory too.
 * @module mod_visuallearning/scene_validator
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
import {compatiblePaths, flattenPath, samplePath, travelRotation} from './scene_geometry';

const own = (o, k) => Object.prototype.hasOwnProperty.call(o, k);
const number = (v, lo, hi) => typeof v === 'number' && Number.isFinite(v) && v >= lo && v <= hi;
const integer = (v, lo, hi) => number(v, lo, hi) && Number.isInteger(v);
const string = (v, lo, hi) => typeof v === 'string' && [...v].length >= lo && [...v].length <= hi;
const id = v => string(v, 1, 48) && /^[A-Za-z][A-Za-z0-9_-]*$/.test(v);
const colour = v => typeof v === 'string' && /^#[0-9a-fA-F]{6}$/.test(v);
const paint = v => v === 'none' || colour(v);
const coord = v => number(v, -10000, 10000);
const unit = v => number(v, 0, 1);
const bool = v => typeof v === 'boolean';
const short = v => string(v, 1, 180);
const long = v => string(v, 1, 2400);
const enumeration = values => v => values.includes(v);
const array = (v, test, lo, hi) => Array.isArray(v) && v.length >= lo && v.length <= hi && v.every(test);
const point = v => array(v, coord, 2, 2);
const points = v => array(v, point, 1, 64);
const scale = v => number(v, 0.01, 20);
const rotation = v => number(v, -36000, 36000);
const width = v => number(v, 0, 16);
const value = v => number(v, -1e9, 1e9);
const positive = v => number(v, 0.1, 10000);
const isObject = v => v !== null && typeof v === 'object' && !Array.isArray(v);
const object = (v, fields, required = Object.keys(fields)) => isObject(v) &&
    Object.keys(v).every(k => own(fields, k) && fields[k](v[k])) && required.every(k => own(v, k));
const stateFields = {x: coord, y: coord, rotation, scaleX: scale, scaleY: scale, opacity: unit,
    visible: bool, fill: paint, stroke: paint, strokeWidth: width, value, connected: bool,
    drawProgress: unit, flowOffset: coord};
const geometryFields = {width: positive, height: positive, radius: positive, rx: positive, ry: positive,
    x2: coord, y2: coord, points, path: id, teeth: v => integer(v, 6, 64), from: id, to: id};
const kinds = ['group', 'rect', 'circle', 'ellipse', 'line', 'arrow', 'polygon', 'path', 'text', 'gear',
    'particles', 'connector'];
const shapeKeys = {group: [], rect: ['width', 'height'], circle: ['radius'], ellipse: ['rx', 'ry'],
    line: ['x2', 'y2'], arrow: ['x2', 'y2'], polygon: ['points'], path: ['path'], text: [],
    gear: ['radius', 'teeth'], particles: ['radius', 'points'], connector: ['from', 'to']};
const xy = {x: coord, y: coord};
const scales = {scaleX: scale, scaleY: scale};
const visibility = {visible: bool, opacity: unit};
const cycles = v => integer(v, 1, 32);
const operations = {
    move: [{}, xy], rotate: [{}, {rotation}], scale: [{}, scales], fade: [{}, {opacity: unit}],
    highlight: [{colour, width}, {stroke: paint, strokeWidth: width}],
    draw_path: [{}, {drawProgress: unit}],
    follow_path: [{path: id, from: unit, to: unit, orient: bool}, {...xy, rotation}],
    emit_particles: [{origin: point, stagger: v => number(v, 0, 0.9)}, {positions: points}],
    merge_particles: [{}, {point}],
    split_particles: [{}, {positions: points}],
    morph: [{}, {path: id}],
    reveal: [{}, visibility], hide: [{}, visibility],
    flow: [{cycles, alternate: bool}, {flowOffset: coord}],
    pulse: [{amplitude: v => number(v, 0, 0.9), cycles}, scales],
    zoom: [{}, {zoom: v => number(v, 0.1, 10)}],
    pan: [{}, xy], change_colour: [{}, {fill: colour}], change_value: [{}, {value}],
    connect: [{}, {connected: v => v === true}], disconnect: [{}, {connected: v => v === false}]
};
const operation = v => object(v, {type: enumeration(Object.keys(operations)), params: isObject, "final": isObject}) &&
    object(v.params, operations[v.type][0]) && object(v.final, operations[v.type][1]);
const action = v => object(v, {target: x => x === '@camera' || id(x),
    startMs: x => integer(x, 0, 120000), durationMs: x => integer(x, 1, 120000),
    easing: enumeration(['linear', 'smooth', 'ease-in', 'ease-out']), operation});

/**
 * Property channels are public so other validators can reproduce concurrency checks.
 * @param {Object} op
 */
export const actionChannels = op => {
    if (['reveal', 'hide'].includes(op.type)) {
        return ['visible', 'opacity'];
    }
    if (['draw_path', 'flow'].includes(op.type)) {
        return ['dash'];
    }
    if (['emit_particles', 'merge_particles', 'split_particles'].includes(op.type)) {
        return ['particles'];
    }
    return Object.keys(op.final);
};

/**
 * Reject non-JSON values, accessors, cycles, surprising prototypes and oversized inputs before recursion.
 * @param {Object} spec
 */
const safeJson = spec => {
    let count = 0;
    let textBytes = 0;
    const seen = new Set();
    const visit = (v, depth) => {
        if (++count > 40000 || depth > 32) {
            return false;
        }
        if (v === null || typeof v === 'boolean') {
            return true;
        }
        if (typeof v === 'number') {
            return Number.isFinite(v);
        }
        if (typeof v === 'string') {
            textBytes += v.length * 3;
            return textBytes <= 786432;
        }
        if (typeof v !== 'object' || seen.has(v)) {
            return false;
        }
        const proto = Object.getPrototypeOf(v);
        if (Array.isArray(v) ? proto !== Array.prototype : proto !== Object.prototype && proto !== null) {
            return false;
        }
        if (Object.getOwnPropertySymbols(v).length) {
            return false;
        }
        seen.add(v);
        const keys = Object.keys(v);
        if (keys.length > 40000 || (Array.isArray(v) ? v.length > 40000 || keys.length !== v.length ||
            Object.getOwnPropertyNames(v).length !== keys.length + 1 ||
            keys.some((key, i) => key !== String(i)) :
            Object.getOwnPropertyNames(v).length !== keys.length)) {
            return false;
        }
        const ok = keys.every(k => !['__proto__', 'prototype', 'constructor'].includes(k) &&
            own(Object.getOwnPropertyDescriptor(v, k), 'value') && visit(v[k], depth + 1));
        seen.delete(v);
        return ok;
    };
    return visit(spec, 0) && JSON.stringify(spec).length <= 262144;
};

const structure = s => object(s, {
    type: v => v === 'animated_scene', version: v => v === '1.0', title: short, description: long,
    representation: enumeration(['schematic', 'proportional', 'metaphor']),
    viewBox: v => array(v, coord, 4, 4), aspectRatio: enumeration(['meet', 'slice']),
    style: v => object(v, {reference: short, artDirection: long, background: colour, foreground: colour,
        accent: colour, font: enumeration(['sans-serif', 'serif', 'monospace']),
        fontSize: x => number(x, 10, 72), strokeWidth: width}),
    layers: v => array(v, x => object(x, {id, label: short}), 1, 8),
    paths: v => array(v, x => object(x, {id, segments: a => array(a, seg => object(seg, {
        command: enumeration(['M', 'L', 'Q', 'C', 'Z']), values: a => array(a, coord, 0, 6)
    }), 2, 128)}), 0, 32),
    gradients: v => array(v, x => object(x, {id, kind: enumeration(['linear', 'radial']),
        stops: a => array(a, stop => object(stop, {offset: unit, colour, opacity: unit}), 2, 8)}), 0, 16),
    objects: v => array(v, x => object(x, {id, kind: enumeration(kinds),
        role: enumeration(['draw', 'clip', 'mask']), layer: id, label: a => string(a, 0, 180),
        parent: id, clip: id, mask: id, gradient: id,
        geometry: g => object(g, geometryFields, []), initial: state => object(state, stateFields, [])
    }, ['id', 'kind', 'role', 'layer', 'label', 'geometry', 'initial']), 1, 160),
    stages: v => array(v, stage => object(stage, {title: short, description: long,
        durationMs: x => integer(x, 1, 120000), actions: a => array(a, action, 0, 96)}), 3, 3),
    accessibility: v => object(v, {summary: long, reducedMotion: x => x === 'stage-final',
        stageDescriptions: a => array(a, long, 3, 3)})
});

const semantics = s => {
    if (s.viewBox[2] < 1 || s.viewBox[3] < 1) {
        return false;
    }
    const all = [...s.layers, ...s.paths, ...s.gradients, ...s.objects];
    if (new Set(all.map(o => o.id)).size !== all.length) {
        return false;
    }
    const objects = new Map(s.objects.map(o => [o.id, o]));
    const paths = new Map(s.paths.map(p => [p.id, p]));
    const layers = new Set(s.layers.map(l => l.id));
    const gradients = new Set(s.gradients.map(g => g.id));
    let coordinates = 0;
    for (const p of s.paths) {
        if (p.segments[0].command !== 'M' || p.segments.slice(1).some(seg => seg.command === 'M')) {
            return false;
        }
        for (let i = 0; i < p.segments.length; i++) {
            const seg = p.segments[i];
            if (seg.values.length !== {M: 2, L: 2, Q: 4, C: 6, Z: 0}[seg.command] ||
                    (seg.command === 'Z' && i !== p.segments.length - 1)) {
                return false;
            }
            coordinates += seg.values.length;
        }
        const pts = flattenPath(p);
        if (!pts.some(pt => Math.hypot(pt[0] - pts[0][0], pt[1] - pts[0][1]) > 0.000001)) {
            return false;
        }
    }
    if (coordinates > 8192 || s.gradients.some(g =>
        g.stops.some((stop, i) => i > 0 && stop.offset < g.stops[i - 1].offset))) {
        return false;
    }
    for (const o of s.objects) {
        if (!layers.has(o.layer) || !object(o.geometry,
            Object.fromEntries(shapeKeys[o.kind].map(k => [k, geometryFields[k]])))) {
            return false;
        }
        if (o.kind === 'polygon' && o.geometry.points.length < 3) {
            return false;
        }
        if (o.kind === 'path' && !paths.has(o.geometry.path)) {
            return false;
        }
        if (o.gradient && !gradients.has(o.gradient)) {
            return false;
        }
        if (o.gradient && ['group', 'line', 'arrow', 'connector'].includes(o.kind)) {
            return false;
        }
        if (o.parent) {
            const p = objects.get(o.parent);
            if (!p || p.kind !== 'group' || p.layer !== o.layer || p.role !== o.role) {
                return false;
            }
        }
        for (const key of ['clip', 'mask']) {
            if (o[key]) {
                const ref = objects.get(o[key]);
                if (!ref || ref.role !== key || ref.parent) {
                    return false;
                }
            }
        }
        if ((own(o.initial, 'value') && o.kind !== 'text') ||
                (own(o.initial, 'connected') && o.kind !== 'connector') ||
                (own(o.initial, 'drawProgress') && !['path', 'line', 'arrow', 'polygon', 'connector'].includes(o.kind)) ||
                (own(o.initial, 'flowOffset') && !['path', 'line', 'arrow', 'polygon'].includes(o.kind))) {
            return false;
        }
        if (o.kind === 'connector') {
            if (o.role !== 'draw' || o.geometry.from === o.geometry.to ||
                [o.geometry.from, o.geometry.to].some(k => !objects.has(k) ||
                    objects.get(k).kind === 'connector' || objects.get(k).role !== 'draw')) {
                return false;
            }
        }
    }
    // Resource dependencies include descendants: a group's own clip may not contain that group.
    const edges = new Map(s.objects.map(o => [o.id, [o.clip, o.mask].filter(Boolean)]));
    s.objects.forEach(o => {
        if (o.parent) {
            edges.get(o.parent).push(o.id);
        }
    });
    const walk = (key, stack, depth) => {
        if (depth > 8 || stack.has(key)) {
            return false;
        }
        const next = new Set(stack);
        next.add(key);
        return edges.get(key).every(ref => walk(ref, next, depth + 1));
    };
    if (s.objects.some(o => !walk(o.id, new Set(), 1))) {
        return false;
    }
    let actionCount = 0;
    const particleMax = new Map(s.objects.filter(o => o.kind === 'particles')
        .map(o => [o.id, o.geometry.points.length]));
    const particleCount = new Map(particleMax);
    const positions = new Map(s.objects.map(o => [o.id, {x: o.initial.x || 0, y: o.initial.y || 0}]));
    const currentPath = new Map(s.objects.filter(o => o.kind === 'path').map(o => [o.id, o.geometry.path]));
    for (const stage of s.stages) {
        actionCount += stage.actions.length;
        for (const a of stage.actions) {
            const op = a.operation;
            const target = objects.get(a.target);
            if (a.startMs + a.durationMs > stage.durationMs ||
                (['zoom', 'pan'].includes(op.type) ? a.target !== '@camera' : !target)) {
                return false;
            }
            if (op.type === 'morph' && (target.kind !== 'path' || !paths.has(op.final.path))) {
                return false;
            }
            if (op.type === 'follow_path') {
                const path = paths.get(op.params.path);
                if (!path) {
                    return false;
                }
                const end = samplePath(path, op.params.to);
                const angle = travelRotation(end.rotation, op.params.from, op.params.to);
                if (Math.abs(end.x - op.final.x) > 0.001 || Math.abs(end.y - op.final.y) > 0.001 ||
                        (op.params.orient && Math.abs(angle - op.final.rotation) > 0.001)) {
                    return false;
                }
            }
            if ((op.type === 'change_value' && target.kind !== 'text') ||
                (op.type === 'highlight' && target.kind === 'group') ||
                (op.type === 'change_colour' && ['group', 'line', 'arrow', 'connector'].includes(target.kind)) ||
                (['emit_particles', 'merge_particles', 'split_particles'].includes(op.type) &&
                    target.kind !== 'particles') ||
                (['connect', 'disconnect'].includes(op.type) && target.kind !== 'connector') ||
                (op.type === 'draw_path' && !['path', 'line', 'arrow', 'polygon', 'connector'].includes(target.kind)) ||
                (op.type === 'flow' && !['path', 'line', 'arrow', 'polygon'].includes(target.kind)) ||
                (op.type === 'reveal' && !op.final.visible) || (op.type === 'hide' && op.final.visible)) {
                return false;
            }
            if (op.final.positions) {
                if (op.type === 'split_particles' && op.final.positions.length < 2) {
                    return false;
                }
                particleMax.set(a.target, Math.max(particleMax.get(a.target), op.final.positions.length));
            }
        }
        for (let i = 0; i < stage.actions.length; i++) {
            const a = stage.actions[i];
            for (let j = i + 1; j < stage.actions.length; j++) {
                const b = stage.actions[j];
                if (a.target === b.target && a.startMs < b.startMs + b.durationMs &&
                    b.startMs < a.startMs + a.durationMs &&
                    actionChannels(a.operation).some(k => actionChannels(b.operation).includes(k))) {
                    return false;
                }
            }
        }
        for (const a of [...stage.actions].sort((a, b) => a.startMs - b.startMs)) {
            const op = a.operation;
            if (op.type === 'follow_path') {
                const start = samplePath(paths.get(op.params.path), op.params.from);
                const before = positions.get(a.target);
                if (Math.abs(start.x - before.x) > 0.001 || Math.abs(start.y - before.y) > 0.001) {
                    return false;
                }
            }
            if (['move', 'follow_path'].includes(op.type)) {
                positions.set(a.target, {x: op.final.x, y: op.final.y});
            }
            if (op.type === 'split_particles' && particleCount.get(a.target) !== 1) {
                return false;
            }
            if (op.type === 'merge_particles') {
                if (particleCount.get(a.target) < 2) {
                    return false;
                }
                particleCount.set(a.target, 1);
            }
            if (op.final.positions) {
                particleCount.set(a.target, op.final.positions.length);
            }
            if (a.operation.type === 'morph') {
                if (!compatiblePaths(paths.get(currentPath.get(a.target)), paths.get(a.operation.final.path))) {
                    return false;
                }
                currentPath.set(a.target, a.operation.final.path);
            }
        }
    }
    return actionCount <= 192 && [...particleMax.values()].reduce((a, b) => a + b, 0) <= 256;
};

/**
 * Strict boolean contract. Invalid data never throws through a rendering or import boundary.
 * @param {Object} spec
 */
export const validateScene = spec => {
    try {
        return safeJson(spec) && structure(spec) && semantics(spec);
    } catch (e) {
        return false;
    }
};
