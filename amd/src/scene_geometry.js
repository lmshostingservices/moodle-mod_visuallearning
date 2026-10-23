/**
 * Bounded, deterministic vector mathematics; no DOM, SVG parser or executable data.
 * @module mod_visuallearning/scene_geometry
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
/**
 * Linear interpolation.
 * @param {number} a
 * @param {number} b
 * @param {number} t
 * @returns {number}
 */
export const lerp = (a, b, t) => t === 0 ? a : t === 1 ? b : a + (b - a) * t;
export const clamp = (n, lo = 0, hi = 1) => Math.max(lo, Math.min(hi, n));
export const interpolatePoint = (a, b, t) => [lerp(a[0], b[0], t), lerp(a[1], b[1], t)];
export const pathData = segments => segments.map(s => s.command + s.values.join(' ')).join(' ');

/**
 * Fixed subdivision gives identical geometry in browser, PHP parity tests and Node.
 * @param {Object} path
 */
export const flattenPath = path => {
    const points = [];
    let at = [0, 0];
    let first = at;
    path.segments.forEach(({command, values: v}) => {
        const start = at;
        if (command === 'M') {
            at = v.slice();
            first = at;
            points.push(at);
        } else if (command === 'L' || command === 'Z') {
            at = command === 'Z' ? first : v.slice();
            points.push(at);
        } else {
            for (let i = 1; i <= 24; i++) {
                const t = i / 24;
                const u = 1 - t;
                points.push(command === 'Q'
                    ? [0, 1].map(k => u * u * start[k] + 2 * u * t * v[k] + t * t * v[k + 2])
                    : [0, 1].map(k => u ** 3 * start[k] + 3 * u * u * t * v[k] +
                        3 * u * t * t * v[k + 2] + t ** 3 * v[k + 4]));
            }
            at = points[points.length - 1];
        }
    });
    return points;
};

/**
 * Precompute polyline metrics once per compiled scene, never once per animation frame.
 * @param {Object} path
 */
export const measurePath = path => {
    const points = flattenPath(path);
    const lengths = points.slice(1).map((p, i) => Math.hypot(p[0] - points[i][0], p[1] - points[i][1]));
    return {points, lengths, total: lengths.reduce((a, b) => a + b, 0)};
};

/**
 * Arc-length position, including a stable tangent in SVG (downwards-y) coordinates.
 * @param {Object} path
 * @param {number} progress
 */
export const samplePath = (path, progress) => {
    const {points, lengths, total} = path.metrics || measurePath(path);
    let distance = clamp(progress) * total;
    for (let i = 0; i < lengths.length; i++) {
        if (distance <= lengths[i] || i === lengths.length - 1) {
            const ratio = progress >= 1 && i === lengths.length - 1 ? 1 : clamp(lengths[i] ? distance / lengths[i] : 0);
            const p = interpolatePoint(points[i], points[i + 1], ratio);
            return {x: p[0], y: p[1],
                rotation: Math.atan2(points[i + 1][1] - points[i][1], points[i + 1][0] - points[i][0]) * 180 / Math.PI};
        }
        distance -= lengths[i];
    }
    return {x: points[0][0], y: points[0][1], rotation: 0};
};

export const compatiblePaths = (a, b) => a.segments.length === b.segments.length &&
    a.segments.every((s, i) => s.command === b.segments[i].command && s.values.length === b.segments[i].values.length);

/**
 * Reverse traversal faces opposite the path's forward tangent. Keep the result in [-180, 180].
 * @param {number} angle
 * @param {number} from
 * @param {number} to
 */
export const travelRotation = (angle, from, to) => from <= to ? angle : angle > 0 ? angle - 180 : angle + 180;

export const colourAt = (a, b, t) => '#' + [1, 3, 5].map(i =>
    Math.round(lerp(parseInt(a.slice(i, i + 2), 16), parseInt(b.slice(i, i + 2), 16), t))
        .toString(16).padStart(2, '0')).join('');

/**
 * Affine matrices used by connectors to follow nested transforms without browser measurements.
 * @param {number[]} a
 * @param {number[]} b
 */
export const multiply = (a, b) => [
    a[0] * b[0] + a[2] * b[1], a[1] * b[0] + a[3] * b[1],
    a[0] * b[2] + a[2] * b[3], a[1] * b[2] + a[3] * b[3],
    a[0] * b[4] + a[2] * b[5] + a[4], a[1] * b[4] + a[3] * b[5] + a[5]
];
export const matrix = s => {
    const r = s.rotation * Math.PI / 180;
    return [Math.cos(r) * s.scaleX, Math.sin(r) * s.scaleX,
        -Math.sin(r) * s.scaleY, Math.cos(r) * s.scaleY, s.x, s.y];
};
export const inverse = m => {
    const d = m[0] * m[3] - m[1] * m[2];
    return [m[3] / d, -m[1] / d, -m[2] / d, m[0] / d,
        (m[2] * m[5] - m[3] * m[4]) / d, (m[1] * m[4] - m[0] * m[5]) / d];
};
export const transformPoint = (m, p) => [m[0] * p[0] + m[2] * p[1] + m[4], m[1] * p[0] + m[3] * p[1] + m[5]];
