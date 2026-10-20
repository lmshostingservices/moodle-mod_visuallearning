# Animated scene engine v1.0 — integration contract

This is an additive AMD renderer, not a replacement for `diagrams.js`.
Existing images and seven legacy diagram types must keep their existing paths.
The authoritative portable structural contract is `schema/animated-scene.json`.
The browser imports no DOM globals until `scene.render()` is called.

## Boundary and naming (PHP/server implement both schema AND semantics)

Top level requires **all** of `type: animated_scene`, `version: "1.0"`, `title`,
`description`, `representation` (`schematic`, `proportional`, `metaphor`),
`viewBox: [minX,minY,width,height]`, `aspectRatio: meet|slice`, `style`, `layers`,
`paths`, `gradients`, `objects`, `stages`, `accessibility`. No additional keys.
Empty paths/gradients are permitted. Exactly three stages, indexed as cards 1–3.

`style`: reference, artDirection (plain descriptive text), background, foreground,
accent (six-digit hex), font (`sans-serif|serif|monospace`), fontSize, strokeWidth.
Style is scene data, never a topic switch or model-authored CSS.

`layers`: ordered `{id,label}`. `paths`: `{id,segments:[{command,values}]}`.
Only absolute **M,L,Q,C,Z**; arities 2,2,4,6,0. M occurs once at the start;
Z, if present, occurs only at the end. No zero-length paths. Curves use fixed
24-subdivision arc-length sampling shared by validation/evaluation. All referenced
paths are in the acting object's **parent-local** coordinate system.

`gradients`: `{id,kind:"linear"|"radial",stops:[{offset,colour,opacity}]}`.
Offsets must be ascending. Linear runs left to right; radial is centred.

`objects`: `{id,kind,role,layer,label,geometry,initial}` plus optional
`parent`, `clip`, `mask`, `gradient` IDs. Kinds: group, rect, circle, ellipse,
line, arrow, polygon, path, text, gear, particles, connector.
Roles: draw, clip, mask. Resource roots are not drawn directly; children share
parent role/layer. Only groups can be parents. Clip/mask references must name
parentless roots of the matching role. Gradient IDs are separate from paints.

Geometry has **exactly** these fields for each kind:

|kind|geometry|
|---|---|
|group/text|`{}`|
|rect|`{width,height}` (origin top left)|
|circle|`{radius}`|
|ellipse|`{rx,ry}`|
|line/arrow|`{x2,y2}` (starts at local origin)|
|polygon|`{points:[[x,y],...]}` minimum 3|
|path|`{path:ID}`|
|gear|`{radius,teeth}` schematic teeth, root radius 0.82 × radius|
|particles|`{radius,points:[[x,y],...]}` initial positions, minimum one|
|connector|`{from:objectID,to:objectID}` endpoints are transformed local origins|

`initial` is a sparse state: x,y (0), rotation (0 degrees, SVG clockwise),
scaleX/scaleY (1), opacity (1), visible (true), fill (style background),
stroke (style foreground), strokeWidth (style default), value (text only),
connected (connector only; default false), drawProgress (1; path-like only),
flowOffset (0; path-like except connector). Text uses label and, if supplied,
numeric value rounded to at most two decimals. All labels use `textContent`.
Transforms are translate → rotate → scale around local origin.
Construct organs, tools, graphs, fields and abstract processes using groups of
these primitives. No HTML/SVG markup, code, image URLs or arbitrary attributes.

Stages: `{title,description,durationMs,actions}`. Actions:
`{target,startMs,durationMs,easing,operation:{type,params,final}}`.
Target is an object ID except pan/zoom, which require `"@camera"`.
Easing: linear, smooth (smoothstep), ease-in (quadratic), ease-out (quadratic).
Every action's duration is positive; start + duration must fit its stage.
`params` and `final` are required, including empty params `{}`.

|type|params|final|
|---|---|---|
|move|`{}`|`{x,y}`|
|rotate|`{}`|`{rotation}`|
|scale|`{}`|`{scaleX,scaleY}`|
|fade|`{}`|`{opacity}`|
|highlight|`{colour,width}`|`{stroke,strokeWidth}`|
|draw_path|`{}`|`{drawProgress}`|
|follow_path|`{path,from,to,orient}` (fractions)|`{x,y,rotation}`|
|emit_particles|`{origin:[x,y],stagger:0..0.9}`|`{positions:[[x,y],...]}`|
|merge_particles|`{}`|`{point:[x,y]}`|
|split_particles|`{}`|`{positions:[[x,y],...]}` minimum 2|
|morph|`{}`|`{path:ID}`|
|reveal/hide|`{}`|`{visible,opacity}` (true/false respectively)|
|flow|`{cycles:1..32,alternate:boolean}`|`{flowOffset}`|
|pulse|`{amplitude:0..0.9,cycles:1..32}`|`{scaleX,scaleY}`|
|zoom|`{}`|`{zoom:0.1..10}`|
|pan|`{}`|`{x,y}`|
|change_colour|`{}`|`{fill:"#RRGGBB"}`|
|change_value|`{}`|`{value}`|
|connect/disconnect|`{}`|`{connected:true/false}` respectively|

Follow final x/y must equal the sampled endpoint within 0.001; if orient=true,
rotation must equal the travel tangent within 0.001 degrees. Reverse traversal
adds 180° to the forward tangent, normalised to [-180,180]. The sampled `from`
position must match the object's inherited x/y (within 0.001): reject accidental
teleports at action start. Otherwise the supplied
rotation is interpolated normally. Morph requires identical command sequences and
arities against the path left by the previous action/stage; geometry must not be
silently coerced. Change-value only targets text; particle operations target
particles; connection operations target connectors. Draw targets path, line,
arrow, polygon or connector; flow excludes connectors.

Split requires a single existing particle; merge requires at least two. Track
particle counts through time-ordered actions and previous stages. Emit replaces
its emitter cloud with explicitly born particles; split fans out from one seed;
merge preserves all incoming particles until they coalesce into a single result.
Use separate emitter objects for independent clouds rather than discarding
meaningful entities. Highlight cannot target a group; colour changes and
gradients cannot target groups, lines, arrows or connectors (no visible fill).
Gradient-filled shapes switch to solid paint when a colour-change action begins.
Group transforms and visibility affect all descendants. To highlight an
assembly, author a visible outline shape rather than a no-op group stroke.
Flow uses dashed stroke transport; `cycles` is dash density when unidirectional,
or the number of back-and-forth oscillations when alternating. `flowOffset`
is the held final stroke offset. Draw-path progressively exposes the stroke,
not a filled region. Use fade/reveal or masks for filled material.

Concurrent writes to the same object/property are invalid. Intervals are half
open, allowing sequential actions to share an exact boundary. Reveal/hide write
visible+opacity; draw_path/flow share one `dash` channel; particle operations share
one `particles` channel. Remaining channels are the keys in `operation.final`.
Different targets or non-overlapping channels can animate concurrently.

All IDs are globally unique across layers, paths, gradients, objects. Reject
missing refs, parent cycles, resource dependency cycles (group → children,
object → clip/mask), and dependency depth >8. Parent must be group in same
layer/role. Connector endpoints must be distinct non-connector draw objects.
The viewBox width/height must be >=1. Paths have <=8192 total numeric coordinates.
Caps: 8 layers, 32 paths, 128 segments/path, 16 gradients, 8 stops/gradient,
160 objects, 64 positions/particle object, **256 maximum simultaneous allocated
particle slots across all objects/actions**, 96 actions/stage, **192 total**,
120000 ms/stage, 262144 JSON characters, JSON depth <=32, 40000 visited values.
Coordinates ±10000, rotations ±36000°, values ±1e9; see schema for all bounds.

## Integration API

`validateScene(spec) -> boolean` in `mod_visuallearning/scene_validator`.
`evaluateScene(spec, card, timeMs)` in `mod_visuallearning/scene_timeline` is pure.
`render(container,spec,options={}) -> null|controller` in `mod_visuallearning/scene`.
Invalid data returns null and **does not clear the fallback container**.

Controller: `setCard(card, ignoredAudioMs)`, `stage(card, ignoredAudioMs)` alias,
`pause(boolean)`, `retime(ignoredAudioMs)` NOOP, `destroy()`, `replay()` (all
three stages), `seek(card,timeMs)` (pauses), `finishStage()`, `finish(card)` alias.
Stage replay is `setCard(card)`. Cards are 1–3; card 0 shows initial state and waits.
Options: pacing `slow|normal|fast` (0.7×/1×/1.4×), reducedMotion boolean override,
controls boolean (default true), strings `{replay,stageReplay}` for localisation.
Rendering is paused on explicit pause, offscreen intersection, hidden document,
disconnected host and pagehide; no frame loop runs at a held stage end.
Full replay alone auto-advances stages; narration-driven stages hold.

Reduced motion, including live media-query changes, shows the selected stage's
final state and description. Never scale clocks to guessed or actual audio length.
Main player owns narration integration, fallback image, lesson settings,
generation prompts, PHP/server parity and compiled AMD build delivery.

## Tests and verification boundaries

Run `node --test moodle-plugin/visuallearning/tests/scene_engine.test.cjs`
from the workspace root. No installs or network requests. This covers the eight
standalone JSON fixtures under `tests/fixtures/scene-*.json`, all 21 actions at
intermediate and final times, fixed-axis/proportion and movement assertions,
schema subset rejection, invalid structures, injection surfaces, references,
cycles, concurrent writes, complexity caps, deterministic stage inheritance and
backward seeking. A small DOM/rAF harness exercises the actual component builder,
controller, replay controls, scoped resource IDs, offscreen/hidden/disconnected
cleanup, reduced-motion changes, audio-retime NOOP and pacing.

The portable structural schema and browser structural rules are maintained
separately (Moodle AMD cannot assume a JSON-module loader). Tests validate the
same corpus against both. Keep both in sync; server/PHP must implement the
documented semantic checks as well, not just the structural schema.

These are deterministic engine tests, **not** independent scientific review or
real-browser pixel/performance verification. Parent integration must verify SVG
rendering in supported browsers and tablets, keyboard/screen-reader behaviour,
actual audio pause/navigation, and AMD compilation with the Moodle toolchain.
No backend, provider, billing or legacy-renderer files were changed by this
engine implementation. No live paid generation was run.

Intentional portable-v1 limitations: no SVG arcs, relative commands, multiple
subpaths or arbitrary stroke/fill attribute maps; no raster/external resources;
no real physics, collision solver, molecular simulation or anatomically exact
component templates; no automatic label wrapping or collision avoidance.
Compose curves/groups for unfamiliar topics, or use the existing diagram/image
fallback when the representation would be inaccurate. Flow is a directional
dashed-stroke mechanism, not a fluid solver. Merge/split are schematic count
operations and must not be used to imply conservation of physical mass or charge
unless the source content and authored composition establish it.

## Accuracy and extension

The engine cannot establish scientific truth from JSON. Author scenes against
the teaching source, describe schematic/non-proportional conventions, and use a
static image or existing diagram when accuracy cannot be established. Gear teeth,
particles and organ outlines are schematic, not physical simulation.
No closed topic list exists in production: eight fixtures are examples only.

To add a reusable primitive: add an allowlisted kind and exact geometry schema,
the matching structural/semantic validator rule, trusted SVG creation/update
code, and tests. For an action, add a strict tagged operation schema, meaningful
pure evaluation, property-write channels and target/reference checks. Update
PHP/server parity before accepting the new version. Never accept arbitrary SVG
attributes as an extension shortcut. Bump the scene schema version for a changed
contract; do not reinterpret previously saved scenes.
