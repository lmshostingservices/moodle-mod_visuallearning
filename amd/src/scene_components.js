/**
 * Trusted SVG vocabulary, data-driven style and DOM updates. No arbitrary attributes.
 * @module mod_visuallearning/scene_components
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
import {pathData, matrix, multiply, inverse, transformPoint, lerp} from './scene_geometry';

const NS = 'http://www.w3.org/2000/svg';
const IDENTITY = [1, 0, 0, 1, 0, 0];
let instance = 0;
const element = (tag, attrs, parent) => {
    const node = document.createElementNS(NS, tag);
    Object.entries(attrs || {}).forEach(([key, val]) => node.setAttribute(key, String(val)));
    if (parent) {
        parent.appendChild(node);
    }
    return node;
};
const attr = (node, attrs) => Object.entries(attrs).forEach(([key, val]) => {
    const text = String(val);
    if (node.getAttribute(key) !== text) {
        node.setAttribute(key, text);
    }
});
const gearPoints = ({radius, teeth}) => Array.from({length: teeth * 4}, (_, i) => {
    const a = i / (teeth * 4) * 2 * Math.PI;
    const r = radius * (i % 4 === 0 || i % 4 === 3 ? 0.82 : 1);
    return [Math.cos(a) * r, Math.sin(a) * r].join(',');
}).join(' ');

/**
 * Build a scoped SVG tree; IDs cannot collide across mounted pages.
 * @param {Element} host
 * @param {Object} spec
 */
export const buildScene = (host, spec) => {
    const prefix = 'vl-scene-' + (++instance) + '-';
    const svg = element('svg', {viewBox: spec.viewBox.join(' '),
        preserveAspectRatio: 'xMidYMid ' + spec.aspectRatio, role: 'img', focusable: 'false',
        'aria-labelledby': prefix + 'title ' + prefix + 'description',
        'font-family': spec.style.font, 'font-size': spec.style.fontSize}, host);
    svg.style.display = 'block';
    svg.style.width = '100%';
    svg.style.height = 'auto';
    svg.style.aspectRatio = spec.viewBox[2] + ' / ' + spec.viewBox[3];
    svg.style.maxHeight = '72vh';
    element('title', {id: prefix + 'title'}, svg).textContent = spec.title;
    const description = element('desc', {id: prefix + 'description'}, svg);
    description.textContent = spec.accessibility.summary;
    const defs = element('defs', {}, svg);
    spec.gradients.forEach(g => {
        const gradient = element(g.kind === 'linear' ? 'linearGradient' : 'radialGradient',
            {id: prefix + g.id}, defs);
        g.stops.forEach(stop => element('stop', {offset: stop.offset, 'stop-color': stop.colour,
            'stop-opacity': stop.opacity}, gradient));
    });
    const arrowId = prefix + 'arrow';
    const arrow = element('marker', {id: arrowId, viewBox: '0 0 10 10', refX: 9, refY: 5,
        markerWidth: 7, markerHeight: 7, orient: 'auto-start-reverse'}, defs);
    element('path', {d: 'M0 0 L10 5 L0 10 Z', fill: 'context-stroke'}, arrow);
    element('rect', {x: spec.viewBox[0], y: spec.viewBox[1], width: spec.viewBox[2], height: spec.viewBox[3],
        fill: spec.style.background}, svg);
    const camera = element('g', {}, svg);
    const layers = new Map(spec.layers.map(layer => [layer.id, element('g', {'aria-label': layer.label}, camera)]));
    const resourceRoots = new Map();
    spec.objects.filter(o => o.role !== 'draw' && !o.parent).forEach(o => {
        resourceRoots.set(o.id, element(o.role === 'clip' ? 'clipPath' : 'mask',
            {id: prefix + o.id, ...(o.role === 'clip' ? {clipPathUnits: 'userSpaceOnUse'} :
                {maskUnits: 'userSpaceOnUse', x: -10000, y: -10000, width: 20000, height: 20000,
                    'mask-type': 'luminance'})}, defs));
    });
    const nodes = new Map();
    const objects = new Map(spec.objects.map(o => [o.id, o]));
    const build = o => {
        if (nodes.has(o.id)) {
            return nodes.get(o.id);
        }
        const parent = o.parent ? build(objects.get(o.parent)).wrapper :
            o.role === 'draw' ? layers.get(o.layer) : resourceRoots.get(o.id);
        const wrapper = element('g', {'data-scene-object': o.id}, parent);
        if (o.clip) {
            wrapper.setAttribute('clip-path', 'url(#' + prefix + o.clip + ')');
        }
        if (o.mask) {
            wrapper.setAttribute('mask', 'url(#' + prefix + o.mask + ')');
        }
        if (o.label && o.kind !== 'text') {
            element('title', {}, wrapper).textContent = o.label;
        }
        const g = o.geometry;
        let shape;
        if (o.kind === 'group' || o.kind === 'particles') {
            shape = element('g', {}, wrapper);
        } else if (o.kind === 'rect') {
            shape = element('rect', {width: g.width, height: g.height}, wrapper);
        } else if (o.kind === 'circle' || o.kind === 'ellipse') {
            shape = element(o.kind, o.kind === 'circle' ? {r: g.radius} : {rx: g.rx, ry: g.ry}, wrapper);
        } else if (o.kind === 'line' || o.kind === 'arrow' || o.kind === 'connector') {
            shape = element('line', {x1: 0, y1: 0, x2: g.x2 || 0, y2: g.y2 || 0, pathLength: 1000}, wrapper);
            if (o.kind === 'arrow') {
                shape.setAttribute('marker-end', 'url(#' + arrowId + ')');
            }
        } else if (o.kind === 'polygon' || o.kind === 'gear') {
            shape = element('polygon', {points: o.kind === 'gear' ? gearPoints(g) :
                g.points.map(p => p.join(',')).join(' '), pathLength: 1000}, wrapper);
            if (o.kind === 'gear') {
                element('circle', {r: g.radius * 0.18, fill: spec.style.background,
                    stroke: spec.style.foreground, 'stroke-width': spec.style.strokeWidth}, wrapper);
            }
        } else if (o.kind === 'path') {
            shape = element('path', {pathLength: 1000}, wrapper);
        } else if (o.kind === 'text') {
            shape = element('text', {'dominant-baseline': 'middle'}, wrapper);
        }
        const result = {wrapper, shape, particles: []};
        nodes.set(o.id, result);
        return result;
    };
    spec.objects.forEach(build);
    // Building children on demand can change insertion order; enforce author order per parent.
    spec.objects.forEach(o => {
        const node = nodes.get(o.id).wrapper;
        node.parentNode.appendChild(node);
    });
    const update = state => {
        const world = new Map();
        const worldMatrix = id => {
            if (!world.has(id)) {
                const o = objects.get(id);
                world.set(id, multiply(o.parent ? worldMatrix(o.parent) : IDENTITY, matrix(state.objects[id])));
            }
            return world.get(id);
        };
        const [vx, vy, vw, vh] = spec.viewBox;
        const cx = vx + vw / 2;
        const cy = vy + vh / 2;
        attr(camera, {transform: `translate(${cx} ${cy}) scale(${state.camera.zoom}) ` +
            `translate(${-cx - state.camera.x} ${-cy - state.camera.y})`});
        spec.objects.forEach(o => {
            const s = state.objects[o.id];
            const {wrapper, shape, particles} = nodes.get(o.id);
            attr(wrapper, {transform: 'matrix(' + matrix(s).join(' ') + ')',
                opacity: s.opacity, display: s.visible ? 'inline' : 'none'});
            attr(shape, {fill: o.gradient && !s.colourChanged ? 'url(#' + prefix + o.gradient + ')' : s.fill,
                stroke: s.stroke, 'stroke-width': s.strokeWidth, 'stroke-linejoin': 'round',
                'stroke-linecap': 'round'});
            if (o.kind === 'text') {
                const value = Object.prototype.hasOwnProperty.call(s, 'value')
                    ? ' ' + String(Math.round(s.value * 100) / 100) : '';
                const text = o.label + value;
                if (shape.textContent !== text) {
                    shape.textContent = text;
                }
            }
            if (o.kind === 'path') {
                attr(shape, {d: pathData(s.segments)});
            }
            if (['path', 'line', 'arrow', 'polygon'].includes(o.kind)) {
                attr(shape, s.dashMode === 'flow'
                    ? {'stroke-dasharray': `${12 / s.flowCycles} ${10 / s.flowCycles}`, 'stroke-dashoffset': -s.flowOffset}
                    : {'stroke-dasharray': `${s.drawProgress * 1000} 1000`, 'stroke-dashoffset': 0});
            }
            if (o.kind === 'connector') {
                const local = inverse(worldMatrix(o.id));
                const a = transformPoint(local, transformPoint(worldMatrix(o.geometry.from), [0, 0]));
                const b = transformPoint(local, transformPoint(worldMatrix(o.geometry.to), [0, 0]));
                attr(shape, {x1: a[0], y1: a[1], x2: lerp(a[0], b[0], s.connectionProgress),
                    y2: lerp(a[1], b[1], s.connectionProgress), visibility: s.connected ? 'visible' : 'hidden',
                    'stroke-dasharray': `${s.drawProgress * 1000} 1000`});
            }
            if (o.kind === 'particles') {
                while (particles.length < s.particles.length) {
                    particles.push(element('circle', {r: o.geometry.radius}, shape));
                }
                particles.forEach((particle, i) => {
                    const p = s.particles[i];
                    attr(particle, p ? {cx: p.x, cy: p.y, opacity: p.opacity, display: 'inline'} : {display: 'none'});
                });
            }
        });
    };
    return {svg, update, setDescription: text => {
        description.textContent = text;
    }};
};
