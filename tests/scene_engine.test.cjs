/**
 * Run: node --test moodle-plugin/visuallearning/tests/scene_engine.test.cjs
 * No packages, network, Moodle runtime or provider calls.
 */
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

// Load trusted Moodle ES modules without changing the repository package type or installing a bundler.
const moduleCache = new Map();
const load = name => {
    const file = path.resolve(__dirname, '../amd/src', name + '.js');
    if (moduleCache.has(file)) {
        return moduleCache.get(file).exports;
    }
    let source = fs.readFileSync(file, 'utf8');
    const names = [...source.matchAll(/export const (\w+)/g)].map(m => m[1]);
    source = source.replace(/import \{([^}]+)\} from '([^']+)';/g,
        (_, bindings, dep) => `const {${bindings}} = require('${dep}');`).replace(/export const /g, 'const ');
    source += '\n' + names.map(n => `exports.${n} = ${n};`).join('\n');
    const module = {exports: {}};
    moduleCache.set(file, module);
    vm.runInThisContext(`(function(require,module,exports){${source}\n})`, {filename: file})(
        dep => load(dep.replace('./', '')), module, module.exports);
    return module.exports;
};
const {validateScene} = load('scene_validator');
const {evaluateScene, createEvaluator} = load('scene_timeline');
const {samplePath, compatiblePaths, matrix, inverse, transformPoint} = load('scene_geometry');
const {render} = load('scene');
const schema = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../schema/animated-scene.json'), 'utf8'));
const fixtures = Object.fromEntries(fs.readdirSync(path.join(__dirname, 'fixtures'))
    .filter(n => /^scene-.*\.json$/.test(n)).map(n =>
        [n.slice(6, -5), JSON.parse(fs.readFileSync(path.join(__dirname, 'fixtures', n), 'utf8'))]));
const clone = v => JSON.parse(JSON.stringify(v));
const close = (a, b, tolerance = 1e-8) => assert.ok(Math.abs(a - b) < tolerance, `${a} ≠ ${b}`);

/** Strict test-only portable subset interpreter: unknown schema keywords are errors, never bypasses. */
const schemaCheck = (value, rule, root = schema) => {
    const supported = new Set(['$schema', '$id', '$ref', '$defs', 'type', 'properties', 'required',
        'additionalProperties', 'items', 'minItems', 'maxItems', 'minLength', 'maxLength', 'enum',
        'minimum', 'maximum', 'pattern', 'anyOf']);
    Object.keys(rule).forEach(k => assert.ok(supported.has(k), 'Unsupported schema keyword: ' + k));
    if (rule.$ref) {
        assert.match(rule.$ref, /^#\/\$defs\/[A-Za-z0-9_]+$/);
        return schemaCheck(value, root.$defs[rule.$ref.split('/')[2]], root);
    }
    if (rule.anyOf && !rule.anyOf.some(r => schemaCheck(value, r, root))) {
        return false;
    }
    if (rule.enum && !rule.enum.includes(value)) {
        return false;
    }
    if (rule.type) {
        const type = Array.isArray(value) ? 'array' : value === null ? 'null' : typeof value;
        if (rule.type === 'integer' ? !Number.isInteger(value) : type !== rule.type) {
            return false;
        }
    }
    if (typeof value === 'number' && (!Number.isFinite(value) ||
        (rule.minimum !== undefined && value < rule.minimum) ||
        (rule.maximum !== undefined && value > rule.maximum))) {
        return false;
    }
    if (typeof value === 'string' && ((rule.minLength !== undefined && [...value].length < rule.minLength) ||
        (rule.maxLength !== undefined && [...value].length > rule.maxLength) ||
        (rule.pattern && !(new RegExp(rule.pattern)).test(value)))) {
        return false;
    }
    if (Array.isArray(value) && ((rule.minItems !== undefined && value.length < rule.minItems) ||
        (rule.maxItems !== undefined && value.length > rule.maxItems) ||
        (rule.items && !value.every(v => schemaCheck(v, rule.items, root))))) {
        return false;
    }
    if (value && typeof value === 'object' && !Array.isArray(value)) {
        if ((rule.required || []).some(k => !Object.hasOwn(value, k))) {
            return false;
        }
        for (const k of Object.keys(value)) {
            if (rule.properties && Object.hasOwn(rule.properties, k)) {
                if (!schemaCheck(value[k], rule.properties[k], root)) {
                    return false;
                }
            } else if (rule.additionalProperties === false) {
                return false;
            }
        }
    }
    return true;
};
const node = (id, kind, geometry, initial = {}) =>
    ({id, kind, geometry, initial, role: 'draw', layer: 'main', label: id});
const action = (target, type, params, final, startMs = 0, durationMs = 1000) =>
    ({target, startMs, durationMs, easing: 'linear', operation: {type, params, final}});
const simple = () => ({
    type: 'animated_scene', version: '1.0', title: 'Mechanism test', description: 'A schematic test.',
    representation: 'schematic', viewBox: [0, 0, 800, 520], aspectRatio: 'meet',
    style: {reference: 'Test palette', artDirection: 'Legible technical schematic.',
        background: '#f0eadd', foreground: '#354c56', accent: '#ad6447', font: 'sans-serif', fontSize: 18, strokeWidth: 2},
    layers: [{id: 'main', label: 'Main'}],
    gradients: [],
    paths: [
        {id: 'p0', segments: [{command: 'M', values: [0, 0]}, {command: 'L', values: [100, 0]}]},
        {id: 'p1', segments: [{command: 'M', values: [0, 0]}, {command: 'L', values: [100, 100]}]},
        {id: 'curve', segments: [{command: 'M', values: [0, 0]}, {command: 'Q', values: [50, 100, 100, 0]}]}
    ],
    objects: [
        node('a', 'circle', {radius: 10}, {fill: '#224466'}),
        node('b', 'circle', {radius: 10}, {x: 100, fill: '#557788'}),
        node('number', 'text', {}, {value: 2}),
        node('trace', 'path', {path: 'p0'}, {fill: 'none', drawProgress: 0}),
        node('cloud', 'particles', {radius: 4, points: [[0, 0], [20, 0]]}),
        node('link', 'connector', {from: 'a', to: 'b'}, {connected: false}),
        node('assembly', 'group', {})
    ],
    stages: [1, 2, 3].map(n => ({title: 'Stage ' + n, description: 'Explain stage ' + n,
        durationMs: 1000, actions: []})),
    accessibility: {summary: 'A deterministic mechanism test.', reducedMotion: 'stage-final',
        stageDescriptions: ['Concept state.', 'Action state.', 'Outcome state.']}
});
const withAction = a => {
    const spec = simple();
    spec.stages[1].actions = [a];
    return spec;
};

test('contract uses only the agreed portable JSON Schema subset', () => {
    assert.equal(Object.keys(fixtures).length, 8);
    const visit = rule => {
        // Interpreting even a nonmatching dummy catches keywords at this node.
        schemaCheck(null, rule);
        if (rule.properties) {
            Object.values(rule.properties).forEach(visit);
        }
        if (rule.$defs) {
            Object.values(rule.$defs).forEach(visit);
        }
        if (rule.items) {
            visit(rule.items);
        }
        (rule.anyOf || []).forEach(visit);
    };
    visit(schema);
    assert.throws(() => schemaCheck({}, {mysteryValidation: true}), /Unsupported/);
});

for (const [subject, spec] of Object.entries(fixtures)) {
    test(`${subject}: schema, bounded deterministic stages, accessibility, backward seek`, () => {
        assert.equal(schemaCheck(spec, schema), true);
        assert.equal(validateScene(spec), true);
        const before = JSON.stringify(spec);
        const evaluate = createEvaluator(spec);
        assert.ok(evaluate);
        for (let card = 1; card <= 3; card++) {
            const duration = spec.stages[card - 1].durationMs;
            const middle = evaluate(card, duration / 2);
            const final = evaluate(card, duration);
            assert.deepEqual(middle, evaluate(card, duration / 2));
            assert.deepEqual(final.objects, evaluate(card, duration * 2).objects);
            if (card < 3 && spec.stages[card].actions.every(a => a.startMs > 0)) {
                assert.deepEqual(final.objects, evaluate(card + 1, 0).objects);
            }
            assert.ok(spec.accessibility.stageDescriptions[card - 1].length > 20);
            assert.ok(Object.values(middle.objects).every(s => Number.isFinite(s.x) && Number.isFinite(s.rotation)));
            evaluate(3, 1e9);
            assert.deepEqual(middle, evaluate(card, duration / 2));
        }
        assert.equal(JSON.stringify(spec), before, 'pure evaluation must not mutate input');
    });
}

test('cross-subject educational relationships and directions are actually represented', () => {
    const blood2 = evaluateScene(fixtures.circulation, 2, 4000).objects.blood;
    assert.equal(blood2.x, 470);
    assert.equal(blood2.y, 290);
    assert.equal(blood2.fill, '#b85645');
    const blood3 = evaluateScene(fixtures.circulation, 3, 4200).objects.blood;
    assert.equal(blood3.x, 310);
    assert.equal(blood3.y, 290);
    assert.equal(blood3.fill, '#52758a');
    assert.ok(evaluateScene(fixtures.evaporation, 2, 3600).objects.escaping.particles.every(p => p.y < 243));
    const gears = evaluateScene(fixtures.gears, 2, 2400).objects;
    assert.equal(gears.input.rotation, 180);
    assert.equal(gears.output.rotation - 7.5, -90);
    assert.equal(gears.input.x, 260);
    assert.equal(gears.output.x, 470);
    assert.equal(evaluateScene(fixtures.ac, 2, 1000).objects.carriers.x, 25);
    assert.equal(evaluateScene(fixtures.ac, 2, 3000).objects.carriers.x, -25);
    assert.equal(evaluateScene(fixtures.ac, 2, 4000).objects.carriers.x, 0);
    close(evaluateScene(fixtures.ac, 2, 1000).objects.flowTrace.flowOffset, 24);
    close(evaluateScene(fixtures.ac, 2, 3000).objects.flowTrace.flowOffset, -24);
    const atoms = evaluateScene(fixtures.chemistry, 2, 3800).objects;
    assert.equal(atoms.HH.connected, false);
    assert.equal(atoms.ClCl.connected, false);
    assert.equal(atoms.HClA.connected, true);
    assert.equal(atoms.HClB.connected, true);
    assert.ok(['H1', 'H2', 'Cl1', 'Cl2'].every(id => atoms[id].visible));
    const graph = evaluateScene(fixtures.graphs, 2, 3000).objects;
    assert.equal(graph.slope.value, 2);
    assert.deepEqual(graph.relationship.segments[0].values, [180, 420]);
    assert.deepEqual(graph.relationship.segments[1].values, [580, 100]);
    assert.equal(evaluateScene(fixtures.approvals, 3, 2000).objects.status.visible, true);
    assert.equal(evaluateScene(fixtures.hazards, 2, 3600).objects.barrier.visible, true);
    assert.equal(evaluateScene(fixtures.hazards, 2, 3600).objects.spill.visible, false);
    assert.equal(evaluateScene(fixtures.hazards, 3, 2400).objects.barrier.visible, false);
});

test('all 21 actions have actual distinct intermediate and final behaviors', () => {
    const cases = [
        ['move', 'a', {}, {x: 80, y: 20}, s => close(s.a.x, 40), s => assert.equal(s.a.y, 20)],
        ['rotate', 'a', {}, {rotation: -180}, s => close(s.a.rotation, -90), s => assert.equal(s.a.rotation, -180)],
        ['scale', 'a', {}, {scaleX: 2, scaleY: 0.5}, s => close(s.a.scaleY, 0.75), s => assert.equal(s.a.scaleX, 2)],
        ['fade', 'a', {}, {opacity: 0.2}, s => close(s.a.opacity, 0.6), s => assert.equal(s.a.opacity, 0.2)],
        ['highlight', 'a', {colour: '#ff8800', width: 8}, {stroke: '#354c56', strokeWidth: 2},
            s => assert.equal(s.a.stroke, '#ff8800'), s => assert.equal(s.a.strokeWidth, 2)],
        ['draw_path', 'trace', {}, {drawProgress: 1}, s => close(s.trace.drawProgress, 0.5),
            s => assert.equal(s.trace.drawProgress, 1)],
        ['follow_path', 'a', {path: 'p1', from: 0, to: 1, orient: true}, {x: 100, y: 100, rotation: 45},
            s => {close(s.a.x, 50); close(s.a.rotation, 45);}, s => close(s.a.y, 100)],
        ['emit_particles', 'cloud', {origin: [0, 0], stagger: 0.5}, {positions: [[100, 0], [0, 100], [100, 100]]},
            s => {assert.equal(s.cloud.particles.length, 3); close(s.cloud.particles[0].x, 50);
                close(s.cloud.particles[2].opacity, 0);}, s => close(s.cloud.particles[2].x, 100)],
        ['merge_particles', 'cloud', {}, {point: [50, 40]},
            s => {assert.equal(s.cloud.particles.length, 2); close(s.cloud.particles[0].y, 20);},
            s => assert.deepEqual(s.cloud.particles, [{x: 50, y: 40, opacity: 1}])],
        ['split_particles', 'cloud', {}, {positions: [[-50, 0], [50, 0], [0, 80]]},
            s => {assert.equal(s.cloud.particles.length, 3); close(s.cloud.particles[2].y, 40);},
            s => close(s.cloud.particles[0].x, -50)],
        ['morph', 'trace', {}, {path: 'p1'}, s => close(s.trace.segments[1].values[1], 50),
            s => close(s.trace.segments[1].values[1], 100)],
        ['reveal', 'a', {}, {visible: true, opacity: 1},
            s => {assert.equal(s.a.visible, true); close(s.a.opacity, 0.5);}, s => assert.equal(s.a.visible, true)],
        ['hide', 'a', {}, {visible: false, opacity: 0},
            s => {assert.equal(s.a.visible, true); close(s.a.opacity, 0.5);}, s => assert.equal(s.a.visible, false)],
        ['flow', 'trace', {cycles: 2, alternate: false}, {flowOffset: 80},
            s => {close(s.trace.flowOffset, 40); assert.equal(s.trace.dashMode, 'flow');},
            s => close(s.trace.flowOffset, 80)],
        ['pulse', 'a', {amplitude: 0.2, cycles: 1}, {scaleX: 1, scaleY: 1},
            s => close(s.a.scaleX, 1), s => close(s.a.scaleX, 1)],
        ['zoom', '@camera', {}, {zoom: 2}, () => {}, () => {}],
        ['pan', '@camera', {}, {x: 60, y: -20}, () => {}, () => {}],
        ['change_colour', 'a', {}, {fill: '#6688aa'}, s => assert.equal(s.a.fill, '#446688'),
            s => assert.equal(s.a.fill, '#6688aa')],
        ['change_value', 'number', {}, {value: 18}, s => close(s.number.value, 10),
            s => close(s.number.value, 18)],
        ['connect', 'link', {}, {connected: true}, s => close(s.link.connectionProgress, 0.5),
            s => assert.equal(s.link.connected, true)],
        ['disconnect', 'link', {}, {connected: false}, s => close(s.link.connectionProgress, 0.5),
            s => assert.equal(s.link.connected, false)]
    ];
    for (const [type, target, params, final, middleCheck, finalCheck] of cases) {
        const spec = withAction(action(target, type, params, final));
        if (type === 'reveal') {
            spec.objects[0].initial.visible = false;
        }
        if (type === 'disconnect') {
            spec.objects.find(o => o.id === 'link').initial.connected = true;
        }
        if (type === 'split_particles') {
            spec.objects.find(o => o.id === 'cloud').geometry.points = [[0, 0]];
        }
        assert.equal(schemaCheck(spec, schema), true, type);
        assert.equal(validateScene(spec), true, type);
        const evaluate = createEvaluator(spec);
        const middle = evaluate(2, 500);
        const end = evaluate(2, 1000);
        middleCheck(middle.objects);
        finalCheck(end.objects);
        if (type === 'pulse') {
            close(evaluate(2, 250).objects.a.scaleX, 1.2);
            close(evaluate(2, 750).objects.a.scaleX, 0.8);
        }
        if (type === 'zoom') {
            close(middle.camera.zoom, 1.5);
            close(end.camera.zoom, 2);
        }
        if (type === 'pan') {
            close(middle.camera.x, 30);
            close(end.camera.y, -20);
        }
    }
    assert.equal(cases.length, 21);
});

test('stage inheritance, overlapping independent channels, sequential baselines and easing', () => {
    const spec = simple();
    spec.stages[0].actions = [action('a', 'move', {}, {x: 40, y: 0})];
    spec.stages[1].actions = [action('a', 'move', {}, {x: 80, y: 20}),
        action('a', 'rotate', {}, {rotation: 180})];
    spec.stages[2].actions = [action('a', 'move', {}, {x: 120, y: 30}, 200, 800)];
    assert.equal(validateScene(spec), true);
    close(evaluateScene(spec, 2, 500).objects.a.x, 60);
    close(evaluateScene(spec, 2, 500).objects.a.rotation, 90);
    close(evaluateScene(spec, 3, 0).objects.a.x, 80);
    close(evaluateScene(spec, 3, 600).objects.a.x, 100);
    for (const [easing, expected] of [['linear', 0.25], ['smooth', 0.15625], ['ease-in', 0.0625], ['ease-out', 0.4375]]) {
        const s = withAction(action('a', 'move', {}, {x: 100, y: 0}));
        s.stages[1].actions[0].easing = easing;
        close(evaluateScene(s, 2, 250).objects.a.x, 100 * expected);
    }
    const sequential = simple();
    sequential.stages[1].actions = [action('a', 'move', {}, {x: 40, y: 0}, 0, 500),
        action('a', 'move', {}, {x: 100, y: 0}, 500, 500)];
    assert.equal(validateScene(sequential), true);
    close(evaluateScene(sequential, 2, 750).objects.a.x, 70);
    assert.equal(evaluateScene(spec, -1, 0), null);
    assert.equal(evaluateScene(spec, 1, NaN), null);
});

test('strict schema and validator reject structural corruption and injection surfaces', () => {
    const mutations = [
        s => {s.type = 'waveform';}, s => {s.version = '2.0';}, s => {s.extra = 'ignored?';},
        s => {delete s.title;}, s => {s.title = '';}, s => {s.title = 'x'.repeat(181);},
        s => {s.style.font = 'url(https://evil.invalid)';}, s => {s.style.background = 'url(#external)';},
        s => {s.objects[0].onclick = 'alert(1)';}, s => {s.objects[0].kind = 'foreignObject';},
        s => {s.objects[0].geometry.href = 'https://evil.invalid/a.svg';},
        s => {s.stages.pop();}, s => {s.stages[0].durationMs = 0;},
        s => {s.stages[0].durationMs = 120001;}, s => {s.objects[0].initial.x = Infinity;},
        s => {s.objects[0].initial.scaleX = 0;}, s => {s.objects[0].initial.fill = 'red';},
        s => {s.stages[1].actions = [action('a', 'execute', {}, {})];},
        s => {s.stages[1].actions = [action('a', 'move', {script: 'bad'}, {x: 0, y: 0})];},
        s => {s.stages[1].actions = [action('a', 'move', {}, {x: 0, y: 0, rotation: 10})];},
        s => {s.stages[1].actions = [action('a', 'move', {}, {x: 0})];},
        s => {s.stages[1].actions = [action('a', 'move', {}, {x: 0, y: 0}, -1)];},
        s => {s.stages[1].actions = [action('a', 'move', {}, {x: 0, y: 0}, 0, 0)];},
        s => {s.objects[0].id = '__proto__';}, s => {s.accessibility.reducedMotion = 'loop';}
    ];
    mutations.forEach((mutate, i) => {
        const spec = simple();
        mutate(spec);
        assert.equal(schemaCheck(spec, schema), false, 'Schema mutation ' + i);
        assert.equal(validateScene(spec), false, 'Browser mutation ' + i);
    });
    for (const invalid of [null, 'not JSON', [], 17, '{"type":', {type: 'animated_scene'}]) {
        assert.equal(validateScene(invalid), false);
        assert.equal(evaluateScene(invalid, 1, 0), null);
    }
    const accessor = simple();
    Object.defineProperty(accessor, 'title', {enumerable: true, get() {throw new Error('do not invoke');}});
    assert.equal(validateScene(accessor), false);
    const cyclic = simple();
    cyclic.stages[0].actions.push(cyclic);
    assert.equal(validateScene(cyclic), false);
    const polluted = simple();
    Object.setPrototypeOf(polluted.objects[0], {onclick: 'bad'});
    assert.equal(validateScene(polluted), false);
    const protoKey = JSON.parse(JSON.stringify(simple()).replace('"title":', '"__proto__":{},"title":'));
    assert.equal(validateScene(protoKey), false);
    const nonEnumerable = simple();
    Object.defineProperty(nonEnumerable, 'title', {value: 42, enumerable: false});
    assert.equal(validateScene(nonEnumerable), false);
    const symbolic = simple();
    symbolic[Symbol('ignored')] = 'not JSON';
    assert.equal(validateScene(symbolic), false);
    const sparse = simple();
    sparse.objects[4].geometry.points = Array(3);
    assert.equal(validateScene(sparse), false);
    const inheritedCode = simple();
    const evilArray = class extends Array {
        toJSON() {throw new Error('not a JSON array');}
    };
    inheritedCode.objects[4].geometry.points = new evilArray([0, 0]);
    assert.equal(validateScene(inheritedCode), false);
});

test('semantic references, incompatible paths, conflicting writes and limits fail closed', () => {
    const mutations = [
        s => {s.viewBox[2] = 0;},
        s => {s.objects[1].id = s.objects[0].id;},
        s => {s.objects[0].layer = 'absent';},
        s => {s.objects[0].parent = 'absent';},
        s => {s.objects[0].parent = 'b';},
        s => {s.objects[0].mask = 'b';},
        s => {s.objects[0].gradient = 'absent';},
        s => {s.objects[3].geometry.path = 'absent';},
        s => {s.objects[0].geometry = {};},
        s => {s.objects[0].initial.value = 4;},
        s => {s.objects[0].initial.connected = true;},
        s => {s.objects[0].initial.flowOffset = 20;},
        s => {s.stages[0].actions = [action('assembly', 'change_colour', {}, {fill: '#aabbcc'})];},
        s => {s.stages[0].actions = [action('assembly', 'highlight', {colour: '#aabbcc', width: 4},
            {stroke: '#354c56', strokeWidth: 2})];},
        s => {s.objects[6].parent = 'assembly';},
        s => {s.objects[5].geometry.to = 'link';},
        s => {s.objects[5].geometry.to = 'absent';},
        s => {s.paths[0].segments[0].command = 'L';},
        s => {s.paths[0].segments[1].values = [100];},
        s => {s.paths[0].segments[1].values = [0, 0];},
        s => {s.paths[0].segments.push({command: 'M', values: [10, 10]});},
        s => {s.stages[0].actions = [action('missing', 'move', {}, {x: 0, y: 0})];},
        s => {s.stages[0].actions = [action('a', 'pan', {}, {x: 0, y: 0})];},
        s => {s.stages[0].actions = [action('@camera', 'move', {}, {x: 0, y: 0})];},
        s => {s.stages[0].actions = [action('a', 'move', {}, {x: 0, y: 0}, 10, 1000)];},
        s => {s.stages[0].actions = [action('trace', 'morph', {}, {path: 'curve'})];},
        s => {s.stages[0].actions = [action('a', 'morph', {}, {path: 'p1'})];},
        s => {s.stages[0].actions = [action('a', 'follow_path', {path: 'p0', from: 0, to: 1, orient: true},
            {x: 101, y: 0, rotation: 0})];},
        s => {s.stages[0].actions = [action('a', 'follow_path', {path: 'absent', from: 0, to: 1, orient: true},
            {x: 100, y: 0, rotation: 0})];},
        s => {s.stages[0].actions = [action('a', 'follow_path', {path: 'p0', from: 0.5, to: 1, orient: true},
            {x: 100, y: 0, rotation: 0})];},
        s => {s.stages[0].actions = [action('cloud', 'split_particles', {}, {positions: [[10, 0], [20, 0]]})];},
        s => {s.stages[0].actions = [action('a', 'change_value', {}, {value: 10})];},
        s => {s.stages[0].actions = [action('a', 'merge_particles', {}, {point: [0, 0]})];},
        s => {s.stages[0].actions = [action('a', 'connect', {}, {connected: true})];},
        s => {s.stages[0].actions = [action('a', 'draw_path', {}, {drawProgress: 1})];},
        s => {s.stages[0].actions = [action('link', 'flow', {cycles: 1, alternate: false}, {flowOffset: 10})];},
        s => {s.stages[0].actions = [action('a', 'reveal', {}, {visible: false, opacity: 1})];},
        s => {s.stages[0].actions = [action('a', 'hide', {}, {visible: true, opacity: 0})];},
        s => {s.stages[0].actions = [action('a', 'move', {}, {x: 10, y: 0}), action('a', 'move', {}, {x: 20, y: 0})];},
        s => {s.stages[0].actions = [action('a', 'fade', {}, {opacity: 1}),
            action('a', 'reveal', {}, {visible: true, opacity: 1})];},
        s => {s.stages[0].actions = [action('trace', 'draw_path', {}, {drawProgress: 1}),
            action('trace', 'flow', {cycles: 1, alternate: false}, {flowOffset: 10})];},
        s => {s.gradients = [{id: 'gradient', kind: 'linear', stops: [
            {offset: 1, colour: '#112233', opacity: 1}, {offset: 0, colour: '#112233', opacity: 1}]}];},
        s => {s.objects = Array.from({length: 161}, (_, i) => node('o' + i, 'circle', {radius: 2}));},
        s => {s.objects = Array.from({length: 5}, (_, i) => node('c' + i, 'particles',
            {radius: 2, points: Array.from({length: 64}, () => [0, 0])}));},
        s => {s.objects = Array.from({length: 10}, (_, i) => ({...node('g' + i, 'group', {}),
            ...(i ? {parent: 'g' + (i - 1)} : {})}));}
    ];
    mutations.forEach((mutate, i) => {
        const spec = simple();
        mutate(spec);
        assert.equal(validateScene(spec), false, 'Semantic mutation ' + i);
    });
    const resourceCycle = simple();
    resourceCycle.objects.push({...node('maskRoot', 'group', {}), role: 'mask'},
        {...node('maskChild', 'rect', {width: 100, height: 100}), role: 'mask',
            parent: 'maskRoot', mask: 'maskRoot'});
    assert.equal(validateScene(resourceCycle), false);
    const tooMany = simple();
    tooMany.objects = Array.from({length: 70}, (_, i) => node('o' + i, 'circle', {radius: 2}));
    tooMany.stages.forEach(stage => {
        stage.actions = tooMany.objects.map(o => action(o.id, 'move', {}, {x: 1, y: 0}));
    });
    assert.equal(validateScene(tooMany), false, '192 total action limit');
    const particleCap = simple();
    particleCap.stages[0].actions = [action('cloud', 'split_particles', {},
        {positions: Array.from({length: 65}, () => [0, 0])})];
    assert.equal(validateScene(particleCap), false);
});

test('curve sampling, reverse path direction, affine inverse and no mutation', () => {
    const spec = simple();
    const point = samplePath(spec.paths[2], 0.5);
    close(point.x, 50);
    close(point.y, 50);
    assert.equal(compatiblePaths(spec.paths[0], spec.paths[2]), false);
    const reversed = withAction(action('a', 'follow_path', {path: 'p0', from: 1, to: 0, orient: false},
        {x: 0, y: 0, rotation: 0}));
    reversed.objects[0].initial.x = 100;
    close(evaluateScene(reversed, 2, 250).objects.a.x, 75);
    reversed.stages[1].actions[0].operation.params.orient = true;
    reversed.stages[1].actions[0].operation.final.rotation = 180;
    close(evaluateScene(reversed, 2, 250).objects.a.rotation, 180);
    const m = matrix({x: 45, y: -32, scaleX: 1.4, scaleY: 0.7, rotation: 63});
    const roundTrip = transformPoint(inverse(m), transformPoint(m, [20, 30]));
    close(roundTrip[0], 20);
    close(roundTrip[1], 30);
});

// Tiny DOM harness exercises actual SVG construction and lifecycle without package installs.
class FakeNode {
    constructor(tag) {
        this.tagName = tag;
        this.children = [];
        this.attributes = {};
        this.style = {};
        this.listeners = {};
        this.textContent = '';
        this.parentNode = null;
    }
    get isConnected() {
        return this === global.document.documentElement || !!this.parentNode?.isConnected;
    }
    appendChild(node) {
        node.remove();
        this.children.push(node);
        node.parentNode = this;
        return node;
    }
    remove() {
        if (this.parentNode) {
            this.parentNode.children = this.parentNode.children.filter(c => c !== this);
            this.parentNode = null;
        }
    }
    setAttribute(k, v) {this.attributes[k] = String(v);}
    getAttribute(k) {return this.attributes[k] ?? null;}
    addEventListener(k, fn) {(this.listeners[k] ||= new Set()).add(fn);}
    removeEventListener(k, fn) {this.listeners[k]?.delete(fn);}
    dispatch(k) {this.listeners[k]?.forEach(fn => fn());}
    find(predicate) {
        if (predicate(this)) {
            return this;
        }
        for (const child of this.children) {
            const hit = child.find(predicate);
            if (hit) {
                return hit;
            }
        }
        return null;
    }
}
const environment = () => {
    const raf = new Map();
    let serial = 0;
    const intersections = [];
    const mutations = [];
    const media = new FakeNode('media');
    media.matches = false;
    const win = new FakeNode('window');
    win.requestAnimationFrame = fn => {raf.set(++serial, fn); return serial;};
    win.cancelAnimationFrame = id => raf.delete(id);
    win.matchMedia = () => media;
    win.IntersectionObserver = class {
        constructor(fn) {this.fn = fn; intersections.push(this);}
        observe() {}
        disconnect() {this.disconnected = true;}
    };
    win.MutationObserver = class {
        constructor(fn) {this.fn = fn; mutations.push(this);}
        observe() {}
        disconnect() {this.disconnected = true;}
    };
    const doc = new FakeNode('document');
    doc.documentElement = new FakeNode('html');
    doc.hidden = false;
    doc.createElement = tag => new FakeNode(tag);
    doc.createElementNS = (_, tag) => new FakeNode(tag);
    global.window = win;
    global.document = doc;
    const container = doc.documentElement.appendChild(new FakeNode('div'));
    const advance = now => {
        const callbacks = [...raf.values()];
        raf.clear();
        callbacks.forEach(fn => fn(now));
    };
    return {win, doc, container, raf, media, intersections, mutations, advance,
        cleanup: () => {delete global.window; delete global.document;}};
};

test('renderer never touches DOM on invalid input and leaves the fallback intact', () => {
    assert.equal(render(null, {type: 'animated_scene'}), null);
    const env = environment();
    try {
        const image = env.container.appendChild(new FakeNode('img'));
        assert.equal(render(env.container, {}), null);
        assert.equal(env.container.children[0], image);
    } finally {
        env.cleanup();
    }
});

test('renderer: narration aliases, pause, seek, NOOP retime, full replay and held final', () => {
    const env = environment();
    try {
        const spec = withAction(action('a', 'move', {}, {x: 100, y: 0}));
        const controller = render(env.container, spec, {controls: false});
        const a = env.container.find(n => n.attributes['data-scene-object'] === 'a');
        assert.ok(controller);
        assert.equal(env.raf.size, 0, 'initial card has no busy loop');
        controller.stage(2, 999999);
        env.advance(0);
        env.advance(400);
        assert.match(a.attributes.transform, /40 0\)$/);
        controller.pause(true);
        assert.equal(env.raf.size, 0);
        controller.pause(false);
        env.advance(5000);
        env.advance(5100);
        assert.match(a.attributes.transform, /50 0\)$/);
        controller.retime(1);
        env.advance(5200);
        assert.match(a.attributes.transform, /60 0\)$/);
        controller.seek(2, 200);
        assert.match(a.attributes.transform, /20 0\)$/);
        assert.equal(env.raf.size, 0, 'seek pauses');
        controller.finishStage();
        assert.match(a.attributes.transform, /100 0\)$/);
        controller.stage(1);
        controller.finish(2);
        assert.match(a.attributes.transform, /100 0\)$/);
        controller.replay();
        [10000, 11000, 12000, 13000, 14000, 15000].forEach(env.advance);
        assert.equal(env.raf.size, 0, 'complete replay stops after third stage');
        controller.destroy();
        controller.destroy();
        controller.setCard(2);
        assert.equal(env.raf.size, 0);
        assert.equal(env.container.children.length, 0);
        assert.ok(env.intersections.every(o => o.disconnected));
        assert.ok(env.mutations.every(o => o.disconnected));
    } finally {
        env.cleanup();
    }
});

test('renderer: hidden/offscreen/reduced-motion/detach lifecycle, pacing, text safety and controls', () => {
    const env = environment();
    try {
        const spec = withAction(action('a', 'move', {}, {x: 100, y: 0}));
        spec.objects[2].label = '<script>alert("not markup")</script>';
        const controller = render(env.container, spec, {pacing: 'fast'});
        const a = env.container.find(n => n.attributes['data-scene-object'] === 'a');
        const text = env.container.find(n => n.tagName === 'text' && n.textContent.includes('<script>'));
        assert.ok(text, 'untrusted text is literally textContent');
        assert.equal(env.container.find(n => n.tagName === 'script'), null);
        controller.setCard(2);
        env.advance(0);
        env.advance(100);
        close(Number(a.attributes.transform.split(' ')[4]), 14);
        env.doc.hidden = true;
        env.doc.dispatch('visibilitychange');
        assert.equal(env.raf.size, 0);
        env.doc.hidden = false;
        env.doc.dispatch('visibilitychange');
        assert.equal(env.raf.size, 1);
        env.intersections[0].fn([{isIntersecting: false}]);
        assert.equal(env.raf.size, 0);
        env.intersections[0].fn([{isIntersecting: true}]);
        assert.equal(env.raf.size, 1);
        env.media.matches = true;
        env.media.dispatch('change');
        assert.equal(env.raf.size, 0);
        assert.match(a.attributes.transform, /100 0\)$/);
        env.media.matches = false;
        env.media.dispatch('change');
        assert.equal(env.raf.size, 0, 'turning reduced motion off does not jump backwards');
        env.media.matches = true;
        const complete = env.container.find(n => n.tagName === 'button' && n.textContent.includes('complete'));
        complete.dispatch('click');
        assert.match(a.attributes.transform, /100 0\)$/);
        env.container.children[0].remove();
        env.mutations[0].fn();
        assert.equal(env.raf.size, 0);
        assert.ok(env.intersections[0].disconnected);
    } finally {
        env.cleanup();
    }
});

test('renderer: scoped resources, masks, clips, gradients, connectors and all fixtures construct safely', () => {
    const env = environment();
    try {
        for (const scene of Object.values(fixtures)) {
            const controller = render(env.container, scene, {controls: false});
            assert.ok(controller);
            controller.seek(2, scene.stages[1].durationMs / 2);
            controller.finish(3);
            controller.destroy();
        }
        const spec = simple();
        spec.objects.push({...node('maskRoot', 'group', {}), role: 'mask'},
            {...node('maskShape', 'rect', {width: 50, height: 50}, {fill: '#ffffff'}),
                role: 'mask', parent: 'maskRoot'});
        spec.objects[0].mask = 'maskRoot';
        assert.equal(validateScene(spec), true);
        const first = render(env.container, spec);
        const second = render(env.container, spec);
        const resourceIds = [];
        const collect = n => {
            if (n.attributes.id) {
                resourceIds.push(n.attributes.id);
            }
            n.children.forEach(collect);
        };
        collect(env.container);
        assert.equal(new Set(resourceIds).size, resourceIds.length);
        assert.ok(env.container.find(n => n.tagName === 'mask'));
        first.destroy();
        second.destroy();
    } finally {
        env.cleanup();
    }
});
