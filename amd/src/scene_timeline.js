/**
 * Deterministic independent stage clocks. Evaluation has no DOM or wall-clock dependencies.
 * @module mod_visuallearning/scene_timeline
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
import {validateScene} from './scene_validator';
import {clamp, lerp, interpolatePoint, samplePath, colourAt, travelRotation, measurePath} from './scene_geometry';

const clone = value => JSON.parse(JSON.stringify(value));
const ease = (p, type) => type === 'smooth' ? p * p * (3 - 2 * p) :
    type === 'ease-in' ? p * p : type === 'ease-out' ? p * (2 - p) : p;
const lerpPaint = (a, b, p) => a === 'none' || b === 'none' ? (p < 1 ? a : b) : colourAt(a, b, p);
const toParticles = positions => positions.map(([x, y]) => ({x, y, opacity: 1}));

const initialState = spec => ({
    card: 0, timeMs: 0, durationMs: 0, camera: {x: 0, y: 0, zoom: 1},
    objects: Object.fromEntries(spec.objects.map(o => [o.id, {
        x: 0, y: 0, rotation: 0, scaleX: 1, scaleY: 1, visible: true, opacity: 1,
        fill: spec.style.background, stroke: spec.style.foreground, strokeWidth: spec.style.strokeWidth,
        connected: false, drawProgress: 1, flowOffset: 0, dashMode: 'draw',
        ...o.initial,
        ...(o.geometry.path ? {segments: clone(spec.paths.find(p => p.id === o.geometry.path).segments)} : {}),
        ...(o.kind === 'particles' ? {particles: toParticles(o.geometry.points)} : {}),
        ...(o.kind === 'connector' ? {connectionProgress: o.initial.connected ? 1 : 0} : {}),
        ...(Object.prototype.hasOwnProperty.call(o.initial, 'flowOffset') ? {dashMode: 'flow', flowCycles: 1} : {})
    }]))
});

/** Apply only this action's write channels to state, deriving all intermediate values from its start snapshot. */
const apply = (state, plan, time, paths) => {
    const a = plan.action;
    if (time < a.startMs) {
        return;
    }
    const raw = clamp((time - a.startMs) / a.durationMs);
    const p = ease(raw, a.easing);
    const {type, params, final} = a.operation;
    const base = plan.base;
    const out = a.target === '@camera' ? state.camera : state.objects[a.target];
    if (['move', 'rotate', 'scale', 'fade', 'pan', 'zoom', 'change_value'].includes(type)) {
        Object.keys(final).forEach(k => {
            out[k] = lerp(base[k] === undefined ? 0 : base[k], final[k], p);
        });
    } else if (type === 'change_colour') {
        out.fill = lerpPaint(base.fill, final.fill, p);
        out.colourChanged = raw > 0 || !!base.colourChanged;
    } else if (type === 'highlight') {
        const envelope = raw === 0 || raw === 1 ? 0 : Math.sin(Math.PI * p);
        const resting = lerpPaint(base.stroke, final.stroke, p);
        out.stroke = envelope > 0.000001
            ? colourAt(resting === 'none' ? params.colour : resting, params.colour, envelope) : resting;
        out.strokeWidth = lerp(lerp(base.strokeWidth, final.strokeWidth, p), params.width, envelope);
    } else if (type === 'draw_path') {
        out.drawProgress = lerp(base.drawProgress, final.drawProgress, p);
        out.dashMode = 'draw';
    } else if (type === 'follow_path') {
        const at = samplePath(paths.get(params.path), lerp(params.from, params.to, p));
        out.x = at.x;
        out.y = at.y;
        out.rotation = params.orient ? travelRotation(at.rotation, params.from, params.to) :
            lerp(base.rotation, final.rotation, p);
        if (raw === 1) {
            Object.assign(out, final);
        }
    } else if (type === 'morph') {
        const end = paths.get(final.path).segments;
        out.segments = base.segments.map((s, i) => ({command: s.command,
            values: s.values.map((v, k) => lerp(v, end[i].values[k], p))}));
    } else if (type === 'reveal') {
        out.visible = true;
        out.opacity = lerp(base.visible ? base.opacity : 0, final.opacity, p);
    } else if (type === 'hide') {
        out.visible = raw < 1 ? base.visible : false;
        out.opacity = lerp(base.opacity, final.opacity, p);
    } else if (type === 'flow') {
        out.flowOffset = lerp(base.flowOffset, final.flowOffset, p) +
            (params.alternate && raw < 1 ? 24 * Math.sin(2 * Math.PI * params.cycles * p) : 0);
        out.flowCycles = params.cycles;
        out.dashMode = 'flow';
    } else if (type === 'pulse') {
        const factor = raw < 1 ? 1 + params.amplitude * Math.sin(2 * Math.PI * params.cycles * p) : 1;
        out.scaleX = lerp(base.scaleX, final.scaleX, p) * factor;
        out.scaleY = lerp(base.scaleY, final.scaleY, p) * factor;
    } else if (type === 'emit_particles') {
        out.particles = final.positions.map((end, i, all) => {
            const birth = all.length > 1 ? params.stagger * i / (all.length - 1) : 0;
            const progress = clamp((p - birth) / (1 - birth));
            const [x, y] = interpolatePoint(params.origin, end, progress);
            return {x, y, opacity: p >= birth ? Math.min(1, progress * 8) : 0};
        });
    } else if (type === 'merge_particles') {
        out.particles = raw === 1 ? toParticles([final.point]) : base.particles.map(start => {
            const [x, y] = interpolatePoint([start.x, start.y], final.point, p);
            return {x, y, opacity: start.opacity};
        });
    } else if (type === 'split_particles') {
        // Children diverge from one seed, rather than being independently born like an emitter.
        const seed = base.particles[0];
        out.particles = final.positions.map(end => {
            const [x, y] = interpolatePoint([seed.x, seed.y], end, p);
            return {x, y, opacity: 1};
        });
    } else if (type === 'connect') {
        out.connected = true;
        out.connectionProgress = lerp(base.connectionProgress, 1, p);
    } else if (type === 'disconnect') {
        out.connected = raw < 1 ? base.connected : false;
        out.connectionProgress = lerp(base.connectionProgress, 0, p);
    }
};

/**
 * Compile immutable action-start snapshots once, then evaluate in bounded linear time per frame.
 * Returns null for invalid input. Callers cannot mutate the original scene through the evaluator.
 */
export const createEvaluator = input => {
    if (!validateScene(input)) {
        return null;
    }
    const spec = clone(input);
    const paths = new Map(spec.paths.map(path => [path.id, {...path, metrics: measurePath(path)}]));
    const initial = initialState(spec);
    const stages = [];
    let previous = initial;
    spec.stages.forEach((stage, index) => {
        const start = clone(previous);
        const plans = [];
        [...stage.actions].sort((a, b) => a.startMs - b.startMs).forEach(action => {
            const atStart = clone(start);
            plans.forEach(plan => apply(atStart, plan, action.startMs, paths));
            plans.push({action, base: clone(action.target === '@camera'
                ? atStart.camera : atStart.objects[action.target])});
        });
        const evaluate = time => {
            const out = clone(start);
            out.card = index + 1;
            out.timeMs = clamp(time, 0, stage.durationMs);
            out.durationMs = stage.durationMs;
            plans.forEach(plan => apply(out, plan, out.timeMs, paths));
            return out;
        };
        stages.push(evaluate);
        previous = evaluate(stage.durationMs);
    });
    return (card, timeMs = 0) => {
        if (!Number.isInteger(card) || card < 0 || card > 3 || !Number.isFinite(timeMs)) {
            return null;
        }
        return card === 0 ? clone(initial) : stages[card - 1](timeMs);
    };
};

/** Pure exported testing and seeking API. Invalid scenes or invalid clock inputs return null. */
export const evaluateScene = (spec, card, timeMs = 0) => {
    const evaluator = createEvaluator(spec);
    return evaluator ? evaluator(card, timeMs) : null;
};
