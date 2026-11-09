"use strict";
(() => {
  // moodle-plugin/visuallearning/amd/src/scene_geometry.js
  var lerp = (a, b, t) => t === 0 ? a : t === 1 ? b : a + (b - a) * t;
  var clamp = (n, lo = 0, hi = 1) => Math.max(lo, Math.min(hi, n));
  var interpolatePoint = (a, b, t) => [lerp(a[0], b[0], t), lerp(a[1], b[1], t)];
  var pathData = (segments) => segments.map((s) => s.command + s.values.join(" ")).join(" ");
  var flattenPath = (path) => {
    const points2 = [];
    let at = [0, 0];
    let first = at;
    path.segments.forEach(({ command, values: v }) => {
      const start = at;
      if (command === "M") {
        at = v.slice();
        first = at;
        points2.push(at);
      } else if (command === "L" || command === "Z") {
        at = command === "Z" ? first : v.slice();
        points2.push(at);
      } else {
        for (let i = 1; i <= 24; i++) {
          const t = i / 24;
          const u = 1 - t;
          points2.push(command === "Q" ? [0, 1].map((k) => u * u * start[k] + 2 * u * t * v[k] + t * t * v[k + 2]) : [0, 1].map((k) => u ** 3 * start[k] + 3 * u * u * t * v[k] + 3 * u * t * t * v[k + 2] + t ** 3 * v[k + 4]));
        }
        at = points2[points2.length - 1];
      }
    });
    return points2;
  };
  var measurePath = (path) => {
    const points2 = flattenPath(path);
    const lengths = points2.slice(1).map((p, i) => Math.hypot(p[0] - points2[i][0], p[1] - points2[i][1]));
    return { points: points2, lengths, total: lengths.reduce((a, b) => a + b, 0) };
  };
  var samplePath = (path, progress) => {
    const { points: points2, lengths, total } = path.metrics || measurePath(path);
    let distance = clamp(progress) * total;
    for (let i = 0; i < lengths.length; i++) {
      if (distance <= lengths[i] || i === lengths.length - 1) {
        const ratio = progress >= 1 && i === lengths.length - 1 ? 1 : clamp(lengths[i] ? distance / lengths[i] : 0);
        const p = interpolatePoint(points2[i], points2[i + 1], ratio);
        return {
          x: p[0],
          y: p[1],
          rotation: Math.atan2(points2[i + 1][1] - points2[i][1], points2[i + 1][0] - points2[i][0]) * 180 / Math.PI
        };
      }
      distance -= lengths[i];
    }
    return { x: points2[0][0], y: points2[0][1], rotation: 0 };
  };
  var compatiblePaths = (a, b) => a.segments.length === b.segments.length && a.segments.every((s, i) => s.command === b.segments[i].command && s.values.length === b.segments[i].values.length);
  var travelRotation = (angle, from, to) => from <= to ? angle : angle > 0 ? angle - 180 : angle + 180;
  var colourAt = (a, b, t) => "#" + [1, 3, 5].map((i) => Math.round(lerp(parseInt(a.slice(i, i + 2), 16), parseInt(b.slice(i, i + 2), 16), t)).toString(16).padStart(2, "0")).join("");
  var multiply = (a, b) => [
    a[0] * b[0] + a[2] * b[1],
    a[1] * b[0] + a[3] * b[1],
    a[0] * b[2] + a[2] * b[3],
    a[1] * b[2] + a[3] * b[3],
    a[0] * b[4] + a[2] * b[5] + a[4],
    a[1] * b[4] + a[3] * b[5] + a[5]
  ];
  var matrix = (s) => {
    const r = s.rotation * Math.PI / 180;
    return [
      Math.cos(r) * s.scaleX,
      Math.sin(r) * s.scaleX,
      -Math.sin(r) * s.scaleY,
      Math.cos(r) * s.scaleY,
      s.x,
      s.y
    ];
  };
  var inverse = (m) => {
    const d = m[0] * m[3] - m[1] * m[2];
    return [
      m[3] / d,
      -m[1] / d,
      -m[2] / d,
      m[0] / d,
      (m[2] * m[5] - m[3] * m[4]) / d,
      (m[1] * m[4] - m[0] * m[5]) / d
    ];
  };
  var transformPoint = (m, p) => [m[0] * p[0] + m[2] * p[1] + m[4], m[1] * p[0] + m[3] * p[1] + m[5]];

  // moodle-plugin/visuallearning/amd/src/scene_validator.js
  var own = (o, k) => Object.prototype.hasOwnProperty.call(o, k);
  var number = (v, lo, hi) => typeof v === "number" && Number.isFinite(v) && v >= lo && v <= hi;
  var integer = (v, lo, hi) => number(v, lo, hi) && Number.isInteger(v);
  var string = (v, lo, hi) => typeof v === "string" && [...v].length >= lo && [...v].length <= hi;
  var id = (v) => string(v, 1, 48) && /^[A-Za-z][A-Za-z0-9_-]*$/.test(v);
  var colour = (v) => typeof v === "string" && /^#[0-9a-fA-F]{6}$/.test(v);
  var paint = (v) => v === "none" || colour(v);
  var coord = (v) => number(v, -1e4, 1e4);
  var unit = (v) => number(v, 0, 1);
  var bool = (v) => typeof v === "boolean";
  var short = (v) => string(v, 1, 180);
  var long = (v) => string(v, 1, 2400);
  var enumeration = (values) => (v) => values.includes(v);
  var array = (v, test, lo, hi) => Array.isArray(v) && v.length >= lo && v.length <= hi && v.every(test);
  var point = (v) => array(v, coord, 2, 2);
  var points = (v) => array(v, point, 1, 64);
  var scale = (v) => number(v, 0.01, 20);
  var rotation = (v) => number(v, -36e3, 36e3);
  var width = (v) => number(v, 0, 16);
  var value = (v) => number(v, -1e9, 1e9);
  var positive = (v) => number(v, 0.1, 1e4);
  var isObject = (v) => v !== null && typeof v === "object" && !Array.isArray(v);
  var object = (v, fields, required = Object.keys(fields)) => isObject(v) && Object.keys(v).every((k) => own(fields, k) && fields[k](v[k])) && required.every((k) => own(v, k));
  var stateFields = {
    x: coord,
    y: coord,
    rotation,
    scaleX: scale,
    scaleY: scale,
    opacity: unit,
    visible: bool,
    fill: paint,
    stroke: paint,
    strokeWidth: width,
    value,
    connected: bool,
    drawProgress: unit,
    flowOffset: coord
  };
  var geometryFields = {
    width: positive,
    height: positive,
    radius: positive,
    rx: positive,
    ry: positive,
    x2: coord,
    y2: coord,
    points,
    path: id,
    teeth: (v) => integer(v, 6, 64),
    from: id,
    to: id
  };
  var kinds = [
    "group",
    "rect",
    "circle",
    "ellipse",
    "line",
    "arrow",
    "polygon",
    "path",
    "text",
    "gear",
    "particles",
    "connector"
  ];
  var shapeKeys = {
    group: [],
    rect: ["width", "height"],
    circle: ["radius"],
    ellipse: ["rx", "ry"],
    line: ["x2", "y2"],
    arrow: ["x2", "y2"],
    polygon: ["points"],
    path: ["path"],
    text: [],
    gear: ["radius", "teeth"],
    particles: ["radius", "points"],
    connector: ["from", "to"]
  };
  var xy = { x: coord, y: coord };
  var scales = { scaleX: scale, scaleY: scale };
  var visibility = { visible: bool, opacity: unit };
  var cycles = (v) => integer(v, 1, 32);
  var operations = {
    move: [{}, xy],
    rotate: [{}, { rotation }],
    scale: [{}, scales],
    fade: [{}, { opacity: unit }],
    highlight: [{ colour, width }, { stroke: paint, strokeWidth: width }],
    draw_path: [{}, { drawProgress: unit }],
    follow_path: [{ path: id, from: unit, to: unit, orient: bool }, { ...xy, rotation }],
    emit_particles: [{ origin: point, stagger: (v) => number(v, 0, 0.9) }, { positions: points }],
    merge_particles: [{}, { point }],
    split_particles: [{}, { positions: points }],
    morph: [{}, { path: id }],
    reveal: [{}, visibility],
    hide: [{}, visibility],
    flow: [{ cycles, alternate: bool }, { flowOffset: coord }],
    pulse: [{ amplitude: (v) => number(v, 0, 0.9), cycles }, scales],
    zoom: [{}, { zoom: (v) => number(v, 0.1, 10) }],
    pan: [{}, xy],
    change_colour: [{}, { fill: colour }],
    change_value: [{}, { value }],
    connect: [{}, { connected: (v) => v === true }],
    disconnect: [{}, { connected: (v) => v === false }]
  };
  var operation = (v) => object(v, { type: enumeration(Object.keys(operations)), params: isObject, final: isObject }) && object(v.params, operations[v.type][0]) && object(v.final, operations[v.type][1]);
  var action = (v) => object(v, {
    target: (x) => x === "@camera" || id(x),
    startMs: (x) => integer(x, 0, 12e4),
    durationMs: (x) => integer(x, 1, 12e4),
    easing: enumeration(["linear", "smooth", "ease-in", "ease-out"]),
    operation
  });
  var actionChannels = (op) => {
    if (["reveal", "hide"].includes(op.type)) {
      return ["visible", "opacity"];
    }
    if (["draw_path", "flow"].includes(op.type)) {
      return ["dash"];
    }
    if (["emit_particles", "merge_particles", "split_particles"].includes(op.type)) {
      return ["particles"];
    }
    return Object.keys(op.final);
  };
  var safeJson = (spec) => {
    let count = 0;
    let textBytes = 0;
    const seen = /* @__PURE__ */ new Set();
    const visit = (v, depth) => {
      if (++count > 4e4 || depth > 32) {
        return false;
      }
      if (v === null || typeof v === "boolean") {
        return true;
      }
      if (typeof v === "number") {
        return Number.isFinite(v);
      }
      if (typeof v === "string") {
        textBytes += v.length * 3;
        return textBytes <= 786432;
      }
      if (typeof v !== "object" || seen.has(v)) {
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
      if (keys.length > 4e4 || (Array.isArray(v) ? v.length > 4e4 || keys.length !== v.length || Object.getOwnPropertyNames(v).length !== keys.length + 1 || keys.some((key, i) => key !== String(i)) : Object.getOwnPropertyNames(v).length !== keys.length)) {
        return false;
      }
      const ok = keys.every((k) => !["__proto__", "prototype", "constructor"].includes(k) && own(Object.getOwnPropertyDescriptor(v, k), "value") && visit(v[k], depth + 1));
      seen.delete(v);
      return ok;
    };
    return visit(spec, 0) && JSON.stringify(spec).length <= 262144;
  };
  var structure = (s) => object(s, {
    type: (v) => v === "animated_scene",
    version: enumeration(["1.0", "1.1"]),
    title: short,
    description: long,
    representation: enumeration(["schematic", "proportional", "metaphor"]),
    viewBox: (v) => array(v, coord, 4, 4),
    aspectRatio: enumeration(["meet", "slice"]),
    style: (v) => object(v, {
      reference: short,
      artDirection: long,
      background: colour,
      foreground: colour,
      accent: colour,
      font: enumeration(["sans-serif", "serif", "monospace"]),
      fontSize: (x) => number(x, 10, 72),
      strokeWidth: width
    }),
    layers: (v) => array(v, (x) => object(x, { id, label: short }), 1, 8),
    paths: (v) => array(v, (x) => object(x, { id, segments: (a) => array(a, (seg) => object(seg, {
      command: enumeration(["M", "L", "Q", "C", "Z"]),
      values: (a2) => array(a2, coord, 0, 6)
    }), 2, 128) }), 0, 32),
    gradients: (v) => array(v, (x) => object(x, {
      id,
      kind: enumeration(["linear", "radial"]),
      stops: (a) => array(a, (stop) => object(stop, { offset: unit, colour, opacity: unit }), 2, 8)
    }), 0, 16),
    objects: (v) => array(v, (x) => object(x, {
      id,
      kind: enumeration(kinds),
      role: enumeration(["draw", "clip", "mask"]),
      layer: id,
      label: (a) => string(a, 0, 180),
      parent: id,
      clip: id,
      mask: id,
      gradient: id,
      geometry: (g) => object(g, geometryFields, []),
      initial: (state) => object(state, stateFields, [])
    }, ["id", "kind", "role", "layer", "label", "geometry", "initial"]), 1, 160),
    stages: (v) => array(v, (stage) => object(stage, {
      title: short,
      description: long,
      durationMs: (x) => integer(x, 1, 12e4),
      loopFromMs: (x) => integer(x, 0, 119999),
      actions: (a) => array(a, action, 0, 96)
    }, ["title", "description", "durationMs", "actions"]), 3, 3),
    accessibility: (v) => object(v, {
      summary: long,
      reducedMotion: (x) => x === "stage-final",
      stageDescriptions: (a) => array(a, long, 3, 3)
    })
  });
  var semantics = (s) => {
    if (s.viewBox[2] < 1 || s.viewBox[3] < 1) {
      return false;
    }
    const all = [...s.layers, ...s.paths, ...s.gradients, ...s.objects];
    if (new Set(all.map((o) => o.id)).size !== all.length) {
      return false;
    }
    const objects = new Map(s.objects.map((o) => [o.id, o]));
    const paths = new Map(s.paths.map((p) => [p.id, p]));
    const layers = new Set(s.layers.map((l) => l.id));
    const gradients = new Set(s.gradients.map((g) => g.id));
    let coordinates = 0;
    for (const p of s.paths) {
      if (p.segments[0].command !== "M" || p.segments.slice(1).some((seg) => seg.command === "M")) {
        return false;
      }
      for (let i = 0; i < p.segments.length; i++) {
        const seg = p.segments[i];
        if (seg.values.length !== { M: 2, L: 2, Q: 4, C: 6, Z: 0 }[seg.command] || seg.command === "Z" && i !== p.segments.length - 1) {
          return false;
        }
        coordinates += seg.values.length;
      }
      const pts = flattenPath(p);
      if (!pts.some((pt) => Math.hypot(pt[0] - pts[0][0], pt[1] - pts[0][1]) > 1e-6)) {
        return false;
      }
    }
    if (coordinates > 8192 || s.gradients.some((g) => g.stops.some((stop, i) => i > 0 && stop.offset < g.stops[i - 1].offset))) {
      return false;
    }
    for (const o of s.objects) {
      if (!layers.has(o.layer) || !object(
        o.geometry,
        Object.fromEntries(shapeKeys[o.kind].map((k) => [k, geometryFields[k]]))
      )) {
        return false;
      }
      if (o.kind === "polygon" && o.geometry.points.length < 3) {
        return false;
      }
      if (o.kind === "path" && !paths.has(o.geometry.path)) {
        return false;
      }
      if (o.gradient && !gradients.has(o.gradient)) {
        return false;
      }
      if (o.gradient && ["group", "line", "arrow", "connector"].includes(o.kind)) {
        return false;
      }
      if (o.parent) {
        const p = objects.get(o.parent);
        if (!p || p.kind !== "group" || p.layer !== o.layer || p.role !== o.role) {
          return false;
        }
      }
      for (const key of ["clip", "mask"]) {
        if (o[key]) {
          const ref = objects.get(o[key]);
          if (!ref || ref.role !== key || ref.parent) {
            return false;
          }
        }
      }
      if (own(o.initial, "value") && o.kind !== "text" || own(o.initial, "connected") && o.kind !== "connector" || own(o.initial, "drawProgress") && !["path", "line", "arrow", "polygon", "connector"].includes(o.kind) || own(o.initial, "flowOffset") && !["path", "line", "arrow", "polygon"].includes(o.kind)) {
        return false;
      }
      if (o.kind === "connector") {
        if (o.role !== "draw" || o.geometry.from === o.geometry.to || [o.geometry.from, o.geometry.to].some((k) => !objects.has(k) || objects.get(k).kind === "connector" || objects.get(k).role !== "draw")) {
          return false;
        }
      }
    }
    const edges = new Map(s.objects.map((o) => [o.id, [o.clip, o.mask].filter(Boolean)]));
    s.objects.forEach((o) => {
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
      return edges.get(key).every((ref) => walk(ref, next, depth + 1));
    };
    if (s.objects.some((o) => !walk(o.id, /* @__PURE__ */ new Set(), 1))) {
      return false;
    }
    let actionCount = 0;
    const particleMax = new Map(s.objects.filter((o) => o.kind === "particles").map((o) => [o.id, o.geometry.points.length]));
    const particleCount = new Map(particleMax);
    const positions = new Map(s.objects.map((o) => [o.id, { x: o.initial.x || 0, y: o.initial.y || 0 }]));
    const currentPath = new Map(s.objects.filter((o) => o.kind === "path").map((o) => [o.id, o.geometry.path]));
    for (const stage of s.stages) {
      if (own(stage, "loopFromMs") && (s.version !== "1.1" || stage !== s.stages[2] || stage.durationMs - stage.loopFromMs < 500 || stage.actions.some((a) => a.target === "@camera" && a.startMs + a.durationMs > stage.loopFromMs))) {
        return false;
      }
      actionCount += stage.actions.length;
      for (const a of stage.actions) {
        const op = a.operation;
        const target = objects.get(a.target);
        if (a.startMs + a.durationMs > stage.durationMs || (["zoom", "pan"].includes(op.type) ? a.target !== "@camera" : !target)) {
          return false;
        }
        if (op.type === "morph" && (target.kind !== "path" || !paths.has(op.final.path))) {
          return false;
        }
        if (op.type === "follow_path") {
          const path = paths.get(op.params.path);
          if (!path) {
            return false;
          }
          const end = samplePath(path, op.params.to);
          const angle = travelRotation(end.rotation, op.params.from, op.params.to);
          if (Math.abs(end.x - op.final.x) > 1e-3 || Math.abs(end.y - op.final.y) > 1e-3 || op.params.orient && Math.abs(angle - op.final.rotation) > 1e-3) {
            return false;
          }
        }
        if (op.type === "change_value" && target.kind !== "text" || op.type === "highlight" && target.kind === "group" || op.type === "change_colour" && ["group", "line", "arrow", "connector"].includes(target.kind) || ["emit_particles", "merge_particles", "split_particles"].includes(op.type) && target.kind !== "particles" || ["connect", "disconnect"].includes(op.type) && target.kind !== "connector" || op.type === "draw_path" && !["path", "line", "arrow", "polygon", "connector"].includes(target.kind) || op.type === "flow" && !["path", "line", "arrow", "polygon"].includes(target.kind) || op.type === "reveal" && !op.final.visible || op.type === "hide" && op.final.visible) {
          return false;
        }
        if (op.final.positions) {
          if (op.type === "split_particles" && op.final.positions.length < 2) {
            return false;
          }
          particleMax.set(a.target, Math.max(particleMax.get(a.target), op.final.positions.length));
        }
      }
      for (let i = 0; i < stage.actions.length; i++) {
        const a = stage.actions[i];
        for (let j = i + 1; j < stage.actions.length; j++) {
          const b = stage.actions[j];
          if (a.target === b.target && a.startMs < b.startMs + b.durationMs && b.startMs < a.startMs + a.durationMs && actionChannels(a.operation).some((k) => actionChannels(b.operation).includes(k))) {
            return false;
          }
        }
      }
      for (const a of [...stage.actions].sort((a2, b) => a2.startMs - b.startMs)) {
        const op = a.operation;
        if (op.type === "follow_path") {
          const start = samplePath(paths.get(op.params.path), op.params.from);
          const before = positions.get(a.target);
          if (Math.abs(start.x - before.x) > 1e-3 || Math.abs(start.y - before.y) > 1e-3) {
            return false;
          }
        }
        if (["move", "follow_path"].includes(op.type)) {
          positions.set(a.target, { x: op.final.x, y: op.final.y });
        }
        if (op.type === "split_particles" && particleCount.get(a.target) !== 1) {
          return false;
        }
        if (op.type === "merge_particles") {
          if (particleCount.get(a.target) < 2) {
            return false;
          }
          particleCount.set(a.target, 1);
        }
        if (op.final.positions) {
          particleCount.set(a.target, op.final.positions.length);
        }
        if (a.operation.type === "morph") {
          if (!compatiblePaths(paths.get(currentPath.get(a.target)), paths.get(a.operation.final.path))) {
            return false;
          }
          currentPath.set(a.target, a.operation.final.path);
        }
      }
    }
    return actionCount <= 192 && [...particleMax.values()].reduce((a, b) => a + b, 0) <= 256;
  };
  var validateScene = (spec) => {
    try {
      return safeJson(spec) && structure(spec) && semantics(spec);
    } catch (e) {
      return false;
    }
  };

  // moodle-plugin/visuallearning/amd/src/scene_timeline.js
  var clone = (value2) => JSON.parse(JSON.stringify(value2));
  var ease = (p, type) => type === "smooth" ? p * p * (3 - 2 * p) : type === "ease-in" ? p * p : type === "ease-out" ? p * (2 - p) : p;
  var lerpPaint = (a, b, p) => a === "none" || b === "none" ? p < 1 ? a : b : colourAt(a, b, p);
  var toParticles = (positions) => positions.map(([x, y]) => ({ x, y, opacity: 1 }));
  var initialState = (spec) => ({
    card: 0,
    timeMs: 0,
    durationMs: 0,
    camera: { x: 0, y: 0, zoom: 1 },
    objects: Object.fromEntries(spec.objects.map((o) => [o.id, {
      x: 0,
      y: 0,
      rotation: 0,
      scaleX: 1,
      scaleY: 1,
      visible: true,
      opacity: 1,
      fill: spec.style.background,
      stroke: spec.style.foreground,
      strokeWidth: spec.style.strokeWidth,
      connected: false,
      drawProgress: 1,
      flowOffset: 0,
      dashMode: "draw",
      ...o.initial,
      ...o.geometry.path ? { segments: clone(spec.paths.find((p) => p.id === o.geometry.path).segments) } : {},
      ...o.kind === "particles" ? { particles: toParticles(o.geometry.points) } : {},
      ...o.kind === "connector" ? { connectionProgress: o.initial.connected ? 1 : 0 } : {},
      ...Object.prototype.hasOwnProperty.call(o.initial, "flowOffset") ? { dashMode: "flow", flowCycles: 1 } : {}
    }]))
  });
  var apply = (state, plan, time, paths) => {
    const a = plan.action;
    if (time < a.startMs) {
      return;
    }
    const raw = clamp((time - a.startMs) / a.durationMs);
    const p = ease(raw, a.easing);
    const { type, params, final } = a.operation;
    const base = plan.base;
    const out = a.target === "@camera" ? state.camera : state.objects[a.target];
    if (["move", "rotate", "scale", "fade", "pan", "zoom", "change_value"].includes(type)) {
      Object.keys(final).forEach((k) => {
        out[k] = lerp(base[k] === void 0 ? 0 : base[k], final[k], p);
      });
    } else if (type === "change_colour") {
      out.fill = lerpPaint(base.fill, final.fill, p);
      out.colourChanged = raw > 0 || !!base.colourChanged;
    } else if (type === "highlight") {
      const envelope = raw === 0 || raw === 1 ? 0 : Math.sin(Math.PI * p);
      const resting = lerpPaint(base.stroke, final.stroke, p);
      out.stroke = envelope > 1e-6 ? colourAt(resting === "none" ? params.colour : resting, params.colour, envelope) : resting;
      out.strokeWidth = lerp(lerp(base.strokeWidth, final.strokeWidth, p), params.width, envelope);
    } else if (type === "draw_path") {
      out.drawProgress = lerp(base.drawProgress, final.drawProgress, p);
      out.dashMode = "draw";
    } else if (type === "follow_path") {
      const at = samplePath(paths.get(params.path), lerp(params.from, params.to, p));
      out.x = at.x;
      out.y = at.y;
      out.rotation = params.orient ? travelRotation(at.rotation, params.from, params.to) : lerp(base.rotation, final.rotation, p);
      if (raw === 1) {
        Object.assign(out, final);
      }
    } else if (type === "morph") {
      const end = paths.get(final.path).segments;
      out.segments = base.segments.map((s, i) => ({
        command: s.command,
        values: s.values.map((v, k) => lerp(v, end[i].values[k], p))
      }));
    } else if (type === "reveal") {
      out.visible = true;
      out.opacity = lerp(base.visible ? base.opacity : 0, final.opacity, p);
    } else if (type === "hide") {
      out.visible = raw < 1 ? base.visible : false;
      out.opacity = lerp(base.opacity, final.opacity, p);
    } else if (type === "flow") {
      out.flowOffset = lerp(base.flowOffset, final.flowOffset, p) + (params.alternate && raw < 1 ? 24 * Math.sin(2 * Math.PI * params.cycles * p) : 0);
      out.flowCycles = params.cycles;
      out.dashMode = "flow";
    } else if (type === "pulse") {
      const factor = raw < 1 ? 1 + params.amplitude * Math.sin(2 * Math.PI * params.cycles * p) : 1;
      out.scaleX = lerp(base.scaleX, final.scaleX, p) * factor;
      out.scaleY = lerp(base.scaleY, final.scaleY, p) * factor;
    } else if (type === "emit_particles") {
      out.particles = final.positions.map((end, i, all) => {
        const birth = all.length > 1 ? params.stagger * i / (all.length - 1) : 0;
        const progress = clamp((p - birth) / (1 - birth));
        const [x, y] = interpolatePoint(params.origin, end, progress);
        return { x, y, opacity: p >= birth ? Math.min(1, progress * 8) : 0 };
      });
    } else if (type === "merge_particles") {
      out.particles = raw === 1 ? toParticles([final.point]) : base.particles.map((start) => {
        const [x, y] = interpolatePoint([start.x, start.y], final.point, p);
        return { x, y, opacity: start.opacity };
      });
    } else if (type === "split_particles") {
      const seed = base.particles[0];
      out.particles = final.positions.map((end) => {
        const [x, y] = interpolatePoint([seed.x, seed.y], end, p);
        return { x, y, opacity: 1 };
      });
    } else if (type === "connect") {
      out.connected = true;
      out.connectionProgress = lerp(base.connectionProgress, 1, p);
    } else if (type === "disconnect") {
      out.connected = raw < 1 ? base.connected : false;
      out.connectionProgress = lerp(base.connectionProgress, 0, p);
    }
  };
  var createEvaluator = (input) => {
    if (!validateScene(input)) {
      return null;
    }
    const spec = clone(input);
    const paths = new Map(spec.paths.map((path) => [path.id, { ...path, metrics: measurePath(path) }]));
    const initial = initialState(spec);
    const stages2 = [];
    let previous = initial;
    spec.stages.forEach((stage, index) => {
      const start = clone(previous);
      const plans = [];
      [...stage.actions].sort((a, b) => a.startMs - b.startMs).forEach((action2) => {
        const atStart = clone(start);
        plans.forEach((plan) => apply(atStart, plan, action2.startMs, paths));
        plans.push({ action: action2, base: clone(action2.target === "@camera" ? atStart.camera : atStart.objects[action2.target]) });
      });
      const evaluate = (time) => {
        const out = clone(start);
        out.card = index + 1;
        out.timeMs = clamp(time, 0, stage.durationMs);
        out.durationMs = stage.durationMs;
        plans.forEach((plan) => apply(out, plan, out.timeMs, paths));
        return out;
      };
      stages2.push(evaluate);
      previous = evaluate(stage.durationMs);
    });
    return (card2, timeMs = 0) => {
      if (!Number.isInteger(card2) || card2 < 0 || card2 > 3 || !Number.isFinite(timeMs)) {
        return null;
      }
      return card2 === 0 ? clone(initial) : stages2[card2 - 1](timeMs);
    };
  };

  // moodle-plugin/visuallearning/amd/src/scene_components.js
  var NS = "http://www.w3.org/2000/svg";
  var IDENTITY = [1, 0, 0, 1, 0, 0];
  var instance = 0;
  var element = (tag, attrs, parent) => {
    const node = document.createElementNS(NS, tag);
    Object.entries(attrs || {}).forEach(([key, val]) => node.setAttribute(key, String(val)));
    if (parent) {
      parent.appendChild(node);
    }
    return node;
  };
  var attr = (node, attrs) => Object.entries(attrs).forEach(([key, val]) => {
    const text = String(val);
    if (node.getAttribute(key) !== text) {
      node.setAttribute(key, text);
    }
  });
  var gearPoints = ({ radius, teeth }) => Array.from({ length: teeth * 4 }, (_, i) => {
    const a = i / (teeth * 4) * 2 * Math.PI;
    const r = radius * (i % 4 === 0 || i % 4 === 3 ? 0.82 : 1);
    return [Math.cos(a) * r, Math.sin(a) * r].join(",");
  }).join(" ");
  var buildScene = (host2, spec) => {
    const prefix = "vl-scene-" + ++instance + "-";
    const svg = element("svg", {
      viewBox: spec.viewBox.join(" "),
      preserveAspectRatio: "xMidYMid " + spec.aspectRatio,
      role: "img",
      focusable: "false",
      "aria-labelledby": prefix + "title " + prefix + "description",
      "font-family": spec.style.font,
      "font-size": spec.style.fontSize
    }, host2);
    svg.style.display = "block";
    svg.style.width = "100%";
    svg.style.height = "auto";
    svg.style.aspectRatio = spec.viewBox[2] + " / " + spec.viewBox[3];
    svg.style.maxHeight = "72vh";
    element("title", { id: prefix + "title" }, svg).textContent = spec.title;
    const description = element("desc", { id: prefix + "description" }, svg);
    description.textContent = spec.accessibility.summary;
    const defs = element("defs", {}, svg);
    spec.gradients.forEach((g) => {
      const gradient = element(
        g.kind === "linear" ? "linearGradient" : "radialGradient",
        { id: prefix + g.id },
        defs
      );
      g.stops.forEach((stop) => element("stop", {
        offset: stop.offset,
        "stop-color": stop.colour,
        "stop-opacity": stop.opacity
      }, gradient));
    });
    const arrowId = prefix + "arrow";
    const arrow = element("marker", {
      id: arrowId,
      viewBox: "0 0 10 10",
      refX: 9,
      refY: 5,
      markerWidth: 7,
      markerHeight: 7,
      orient: "auto-start-reverse"
    }, defs);
    element("path", { d: "M0 0 L10 5 L0 10 Z", fill: "context-stroke" }, arrow);
    element("rect", {
      x: spec.viewBox[0],
      y: spec.viewBox[1],
      width: spec.viewBox[2],
      height: spec.viewBox[3],
      fill: spec.style.background
    }, svg);
    const camera = element("g", {}, svg);
    const layers = new Map(spec.layers.map((layer) => [layer.id, element("g", { "aria-label": layer.label }, camera)]));
    const resourceRoots = /* @__PURE__ */ new Map();
    spec.objects.filter((o) => o.role !== "draw" && !o.parent).forEach((o) => {
      resourceRoots.set(o.id, element(
        o.role === "clip" ? "clipPath" : "mask",
        { id: prefix + o.id, ...o.role === "clip" ? { clipPathUnits: "userSpaceOnUse" } : {
          maskUnits: "userSpaceOnUse",
          x: -1e4,
          y: -1e4,
          width: 2e4,
          height: 2e4,
          "mask-type": "luminance"
        } },
        defs
      ));
    });
    const nodes = /* @__PURE__ */ new Map();
    const objects = new Map(spec.objects.map((o) => [o.id, o]));
    const build = (o) => {
      if (nodes.has(o.id)) {
        return nodes.get(o.id);
      }
      const parent = o.parent ? build(objects.get(o.parent)).wrapper : o.role === "draw" ? layers.get(o.layer) : resourceRoots.get(o.id);
      const wrapper = element("g", { "data-scene-object": o.id }, parent);
      if (o.clip) {
        wrapper.setAttribute("clip-path", "url(#" + prefix + o.clip + ")");
      }
      if (o.mask) {
        wrapper.setAttribute("mask", "url(#" + prefix + o.mask + ")");
      }
      if (o.label && o.kind !== "text") {
        element("title", {}, wrapper).textContent = o.label;
      }
      const g = o.geometry;
      let shape;
      if (o.kind === "group" || o.kind === "particles") {
        shape = element("g", {}, wrapper);
      } else if (o.kind === "rect") {
        shape = element("rect", { width: g.width, height: g.height }, wrapper);
      } else if (o.kind === "circle" || o.kind === "ellipse") {
        shape = element(o.kind, o.kind === "circle" ? { r: g.radius } : { rx: g.rx, ry: g.ry }, wrapper);
      } else if (o.kind === "line" || o.kind === "arrow" || o.kind === "connector") {
        shape = element("line", { x1: 0, y1: 0, x2: g.x2 || 0, y2: g.y2 || 0, pathLength: 1e3 }, wrapper);
        if (o.kind === "arrow") {
          shape.setAttribute("marker-end", "url(#" + arrowId + ")");
        }
      } else if (o.kind === "polygon" || o.kind === "gear") {
        shape = element("polygon", { points: o.kind === "gear" ? gearPoints(g) : g.points.map((p) => p.join(",")).join(" "), pathLength: 1e3 }, wrapper);
        if (o.kind === "gear") {
          element("circle", {
            r: g.radius * 0.18,
            fill: spec.style.background,
            stroke: spec.style.foreground,
            "stroke-width": spec.style.strokeWidth
          }, wrapper);
        }
      } else if (o.kind === "path") {
        shape = element("path", { pathLength: 1e3 }, wrapper);
      } else if (o.kind === "text") {
        shape = element("text", { "dominant-baseline": "middle" }, wrapper);
      }
      const result = { wrapper, shape, particles: [] };
      nodes.set(o.id, result);
      return result;
    };
    spec.objects.forEach(build);
    spec.objects.forEach((o) => {
      const node = nodes.get(o.id).wrapper;
      node.parentNode.appendChild(node);
    });
    const update = (state) => {
      const world = /* @__PURE__ */ new Map();
      const worldMatrix = (id2) => {
        if (!world.has(id2)) {
          const o = objects.get(id2);
          world.set(id2, multiply(o.parent ? worldMatrix(o.parent) : IDENTITY, matrix(state.objects[id2])));
        }
        return world.get(id2);
      };
      const [vx, vy, vw, vh] = spec.viewBox;
      const cx = vx + vw / 2;
      const cy = vy + vh / 2;
      attr(camera, { transform: `translate(${cx} ${cy}) scale(${state.camera.zoom}) translate(${-cx - state.camera.x} ${-cy - state.camera.y})` });
      spec.objects.forEach((o) => {
        const s = state.objects[o.id];
        const { wrapper, shape, particles } = nodes.get(o.id);
        attr(wrapper, {
          transform: "matrix(" + matrix(s).join(" ") + ")",
          opacity: s.opacity,
          display: s.visible ? "inline" : "none"
        });
        attr(shape, {
          fill: o.gradient && !s.colourChanged ? "url(#" + prefix + o.gradient + ")" : s.fill,
          stroke: s.stroke,
          "stroke-width": s.strokeWidth,
          "stroke-linejoin": "round",
          "stroke-linecap": "round"
        });
        if (o.kind === "text") {
          const value2 = Object.prototype.hasOwnProperty.call(s, "value") ? " " + String(Math.round(s.value * 100) / 100) : "";
          const text = o.label + value2;
          if (shape.textContent !== text) {
            shape.textContent = text;
          }
        }
        if (o.kind === "path") {
          attr(shape, { d: pathData(s.segments) });
        }
        if (["path", "line", "arrow", "polygon"].includes(o.kind)) {
          attr(shape, s.dashMode === "flow" ? { "stroke-dasharray": `${12 / s.flowCycles} ${10 / s.flowCycles}`, "stroke-dashoffset": -s.flowOffset } : { "stroke-dasharray": `${s.drawProgress * 1e3} 1000`, "stroke-dashoffset": 0 });
        }
        if (o.kind === "connector") {
          const local = inverse(worldMatrix(o.id));
          const a = transformPoint(local, transformPoint(worldMatrix(o.geometry.from), [0, 0]));
          const b = transformPoint(local, transformPoint(worldMatrix(o.geometry.to), [0, 0]));
          attr(shape, {
            x1: a[0],
            y1: a[1],
            x2: lerp(a[0], b[0], s.connectionProgress),
            y2: lerp(a[1], b[1], s.connectionProgress),
            visibility: s.connected ? "visible" : "hidden",
            "stroke-dasharray": `${s.drawProgress * 1e3} 1000`
          });
        }
        if (o.kind === "particles") {
          while (particles.length < s.particles.length) {
            particles.push(element("circle", { r: o.geometry.radius }, shape));
          }
          particles.forEach((particle, i) => {
            const p = s.particles[i];
            attr(particle, p ? { cx: p.x, cy: p.y, opacity: p.opacity, display: "inline" } : { display: "none" });
          });
        }
      });
    };
    return { svg, update, setDescription: (text) => {
      description.textContent = text;
    } };
  };

  // moodle-plugin/visuallearning/amd/src/scene.js
  var render = (container, input, options = {}) => {
    const evaluate = createEvaluator(input);
    if (!evaluate || !container || typeof container.appendChild !== "function") {
      return null;
    }
    const spec = JSON.parse(JSON.stringify(input));
    const host2 = document.createElement("section");
    host2.className = "visuallearning-animated-scene";
    host2.style.position = "relative";
    host2.style.display = "flex";
    host2.style.flexDirection = "column";
    host2.style.height = "100%";
    host2.style.minHeight = "0";
    host2.style.color = spec.style.foreground;
    host2.style.background = spec.style.background;
    let view;
    try {
      view = buildScene(host2, spec);
      const svg = view.svg;
      svg.style.flex = "1 1 0";
      svg.style.minHeight = "0";
      svg.style.maxHeight = "none";
    } catch (error) {
      return null;
    }
    const caption = document.createElement("p");
    caption.style.margin = "0.75rem 1rem";
    caption.style.fontFamily = spec.style.font;
    caption.style.fontSize = "0.9rem";
    caption.style.maxHeight = "5em";
    caption.style.overflow = "auto";
    caption.style.flexShrink = "0";
    caption.setAttribute("aria-live", "polite");
    caption.setAttribute("aria-atomic", "true");
    caption.textContent = spec.accessibility.summary;
    host2.appendChild(caption);
    let card2 = 0;
    let time = 0;
    let paused = false;
    let fullReplay2 = false;
    let offscreen = false;
    let dead = false;
    let frame = null;
    let last2 = null;
    const rate = { slow: 0.7, normal: 1, fast: 1.4 }[options.pacing] || 1;
    const media = typeof window.matchMedia === "function" ? window.matchMedia("(prefers-reduced-motion: reduce)") : null;
    const reduced = () => typeof options.reducedMotion === "boolean" ? options.reducedMotion : !!(media && media.matches);
    const duration = () => card2 ? spec.stages[card2 - 1].durationMs : 0;
    const loopStart = () => card2 === 3 && spec.version === "1.1" && Number.isFinite(spec.stages[2].loopFromMs) ? spec.stages[2].loopFromMs : null;
    const stop = () => {
      if (frame !== null) {
        window.cancelAnimationFrame(frame);
      }
      frame = null;
      last2 = null;
    };
    const paint2 = () => {
      if (dead) {
        return;
      }
      view.update(evaluate(card2, reduced() && card2 ? duration() : time));
      const text = card2 ? spec.accessibility.stageDescriptions[card2 - 1] : spec.accessibility.summary;
      if (caption.textContent !== text) {
        caption.textContent = text;
        view.setDescription(spec.accessibility.summary + " " + text);
      }
    };
    const runnable = () => !dead && !paused && !offscreen && !document.hidden && host2.isConnected && card2 > 0 && (time < duration() || loopStart() !== null) && !reduced();
    const schedule = () => {
      if (frame === null && runnable()) {
        frame = window.requestAnimationFrame(tick2);
      }
    };
    const tick2 = (now) => {
      frame = null;
      if (!runnable()) {
        last2 = null;
        return;
      }
      if (last2 !== null) {
        const next = time + Math.max(0, now - last2) * rate;
        const loop = loopStart();
        time = loop !== null && next >= duration() ? loop + (next - loop) % (duration() - loop) : Math.min(duration(), next);
      }
      last2 = now;
      paint2();
      if (time === duration()) {
        last2 = null;
        if (fullReplay2 && card2 < 3) {
          card2++;
          time = 0;
          paint2();
        } else {
          fullReplay2 = false;
        }
      }
      schedule();
    };
    const lifecycle = () => {
      stop();
      if (!dead) {
        if (reduced() && card2) {
          time = duration();
          fullReplay2 = false;
        }
        paint2();
        schedule();
      }
    };
    const select = (next, at = 0, shouldPause = false) => {
      if (dead || !Number.isInteger(next) || next < 0 || next > 3 || !Number.isFinite(at)) {
        return;
      }
      stop();
      card2 = next;
      time = Math.max(0, Math.min(duration(), at));
      if (reduced() && card2) {
        time = duration();
      }
      paused = shouldPause;
      fullReplay2 = false;
      paint2();
      schedule();
    };
    const controller2 = {
      setCard: (next) => select(next),
      stage: (next) => select(next),
      pause: (value2) => {
        if (!dead) {
          paused = !!value2;
          lifecycle();
        }
      },
      retime: () => {
      },
      seek: (next, milliseconds) => select(next, milliseconds, true),
      finishStage: () => loopStart() !== null && !reduced() ? select(card2, loopStart(), false) : select(card2, duration(), true),
      finish: (next) => {
        if (Number.isInteger(next) && next >= 1 && next <= 3) {
          select(next, spec.stages[next - 1].durationMs, true);
        }
      },
      replay: () => {
        select(1);
        if (reduced()) {
          select(3, spec.stages[2].durationMs, true);
        } else {
          fullReplay2 = true;
        }
      },
      destroy: () => {
        if (dead) {
          return;
        }
        dead = true;
        stop();
        if (observer) {
          observer.disconnect();
        }
        if (detachObserver) {
          detachObserver.disconnect();
        }
        document.removeEventListener("visibilitychange", lifecycle);
        window.removeEventListener("pagehide", onPageHide);
        window.removeEventListener("pageshow", lifecycle);
        if (media && media.removeEventListener) {
          media.removeEventListener("change", lifecycle);
        } else if (media && media.removeListener) {
          media.removeListener(lifecycle);
        }
        host2.remove();
      }
    };
    if (options.controls !== false) {
      const controls = document.createElement("div");
      controls.style.cssText = "display:flex;flex-wrap:wrap;gap:0.5rem;padding:0 1rem 1rem;flex-shrink:0";
      const strings = options.strings || {};
      [
        [strings.replay || "Replay complete animation", () => options.onReplay ? options.onReplay() : controller2.replay()],
        [strings.stageReplay || "Replay selected stage", () => options.onStageReplay ? options.onStageReplay(card2 || 1) : select(card2 || 1)]
      ].forEach(([label, handler]) => {
        const button = document.createElement("button");
        button.type = "button";
        button.className = "btn btn-outline-secondary btn-sm";
        button.textContent = label;
        button.style.color = spec.style.foreground;
        button.style.borderColor = spec.style.foreground;
        button.addEventListener("click", handler);
        controls.appendChild(button);
      });
      host2.appendChild(controls);
    }
    container.appendChild(host2);
    const observer = typeof window.IntersectionObserver === "function" ? new window.IntersectionObserver((entries) => {
      offscreen = !entries.some((entry) => entry.isIntersecting);
      lifecycle();
    }, { threshold: 0 }) : null;
    if (observer) {
      observer.observe(host2);
    }
    const detachObserver = typeof window.MutationObserver === "function" ? new window.MutationObserver(() => {
      if (!host2.isConnected) {
        controller2.destroy();
      }
    }) : null;
    if (detachObserver) {
      detachObserver.observe(document.documentElement, { childList: true, subtree: true });
    }
    const onPageHide = () => stop();
    document.addEventListener("visibilitychange", lifecycle);
    window.addEventListener("pagehide", onPageHide);
    window.addEventListener("pageshow", lifecycle);
    if (media && media.addEventListener) {
      media.addEventListener("change", lifecycle);
    } else if (media && media.addListener) {
      media.addListener(lifecycle);
    }
    paint2();
    return controller2;
  };

  // moodle-plugin/visuallearning/examples/water-cycle/scripts/preview-entry.mjs
  var host = document.getElementById("mount");
  var nav = document.getElementById("chapters");
  var stages = document.getElementById("stages");
  var seek = document.getElementById("seek");
  var toggle = document.getElementById("toggle");
  var scenes = [];
  var controller = null;
  var current = 0;
  var card = 1;
  var playing = false;
  var clock = 0;
  var last = 0;
  var fullReplay = false;
  var names = ["Sunlight", "Evaporation", "Transpiration", "Rising air", "Condensation", "Rainfall", "Groundwater", "The whole cycle"];
  function selectStage(index, time = 0) {
    card = index;
    clock = time;
    playing = false;
    fullReplay = false;
    toggle.textContent = "Play stage";
    controller.seek(index, time);
    seek.max = scenes[current].stages[index - 1].durationMs;
    seek.value = time;
    document.getElementById("time").textContent = (time / 1e3).toFixed(1) + "s";
    [...stages.children].forEach((button, i) => button.classList.toggle("active", i + 1 === card));
  }
  function open(index) {
    current = index;
    controller?.destroy();
    host.replaceChildren();
    controller = render(host, scenes[index], { controls: true, onReplay: () => {
      selectStage(1);
      controller.replay();
      fullReplay = true;
      startClock();
    }, onStageReplay: (index2) => {
      selectStage(index2);
      controller.stage(index2);
      startClock();
    } });
    if (!controller) throw new Error("Actual renderer rejected scene " + index);
    stages.replaceChildren();
    scenes[index].stages.forEach((s, i) => {
      const button = document.createElement("button");
      const small = document.createElement("small");
      small.textContent = `STAGE 0${i + 1}`;
      const strong = document.createElement("strong");
      strong.textContent = s.title;
      button.append(small, strong);
      button.onclick = () => selectStage(i + 1);
      stages.append(button);
    });
    [...nav.children].forEach((b, i) => b.classList.toggle("active", i === index));
    document.getElementById("science").textContent = scenes[index].description;
    selectStage(1);
  }
  function startClock() {
    if (matchMedia("(prefers-reduced-motion: reduce)").matches) {
      clock = Number(seek.max);
      seek.value = clock;
      playing = false;
      toggle.textContent = "Play stage";
      return;
    }
    playing = true;
    last = performance.now();
    toggle.textContent = "Pause";
  }
  toggle.onclick = () => {
    if (playing) {
      controller.pause(true);
      playing = false;
      toggle.textContent = "Play stage";
    } else {
      if (clock >= Number(seek.max)) {
        clock = 0;
        controller.seek(card, 0);
      }
      controller.pause(false);
      startClock();
    }
  };
  seek.oninput = () => selectStage(card, Number(seek.value));
  document.getElementById("end").onclick = () => selectStage(card, Number(seek.max));
  function tick(t) {
    if (playing) {
      clock += Math.max(0, t - last);
      last = t;
      const max = Number(seek.max);
      if (clock >= max) {
        const loop = scenes[current].stages[card - 1].loopFromMs;
        if (loop !== void 0) clock = loop + (clock - loop) % (max - loop);
        else if (fullReplay && card < 3) {
          card++;
          clock = 0;
          seek.max = scenes[current].stages[card - 1].durationMs;
          [...stages.children].forEach((button, i) => button.classList.toggle("active", i + 1 === card));
        } else {
          clock = max;
          playing = false;
          toggle.textContent = "Play stage";
        }
      }
      seek.value = clock;
      document.getElementById("time").textContent = (clock / 1e3).toFixed(1) + "s";
    }
    requestAnimationFrame(tick);
  }
  document.addEventListener("visibilitychange", () => {
    last = performance.now();
  });
  async function init() {
    const response = await fetch("../../manifest.json");
    if (!response.ok) throw new Error("Could not load benchmark manifest");
    const manifest = await response.json();
    scenes = await Promise.all(manifest.scenes.map(async (item) => {
      const r = await fetch("../../" + item.file);
      if (!r.ok) throw new Error("Could not load " + item.file);
      const scene = await r.json();
      if (!validateScene(scene)) throw new Error("Invalid scene " + item.file);
      return scene;
    }));
    names.forEach((name, i) => {
      const button = document.createElement("button");
      button.textContent = `0${i + 1} / ${name}`;
      button.onclick = () => open(i);
      nav.append(button);
    });
    open(0);
    requestAnimationFrame(tick);
    window.showcase = { open, selectStage, get controller() {
      return controller;
    }, get scenes() {
      return scenes;
    } };
  }
  init().catch((error) => {
    host.replaceChildren();
    const box = document.createElement("div");
    box.className = "error";
    box.textContent = error.message + " ";
    const retry = document.createElement("button");
    retry.textContent = "Retry";
    retry.onclick = () => location.reload();
    box.append(retry);
    host.append(box);
  });
})();
/**
 * Bounded, deterministic vector mathematics; no DOM, SVG parser or executable data.
 * @module mod_visuallearning/scene_geometry
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
/**
 * Portable animated_scene v1.0 boundary validation. No browser globals.
 * Structural rules mirror schema/animated-scene.json; semantic rules are mandatory too.
 * @module mod_visuallearning/scene_validator
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
/**
 * Deterministic independent stage clocks. Evaluation has no DOM or wall-clock dependencies.
 * @module mod_visuallearning/scene_timeline
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
/**
 * Trusted SVG vocabulary, data-driven style and DOM updates. No arbitrary attributes.
 * @module mod_visuallearning/scene_components
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
/**
 * Universal scene renderer and narration/lifecycle adapter. Legacy diagrams remain untouched.
 * @module mod_visuallearning/scene
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
