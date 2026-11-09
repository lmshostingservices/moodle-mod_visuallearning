# The living watershed

Eight authored, illustrated Water Cycle scenes for the **actual declarative Moodle SVG engine**.
This is a visual benchmark, not generated-provider acceptance, a production release, or a
replacement production site.

## Art direction

**Layout:** cinematic landscape with anchored scientific magnifications.
**Mood:** a luminous natural-history atlas.

The shared coastal watershed contains a golden sun, ocean, atmospheric mountain facets,
snow, forest silhouettes, individual sculpted trees, a winding river, a freshwater lake,
soil, pore spaces, permeable material and a saturated groundwater zone. The supplied
ten-colour palette is preserved in `manifest.json`; related tonal shades supply depth.
Bézier silhouettes, restrained highlights and gradients replace raster imagery and filters.
The inspection harness pairs Instrument Serif with DM Sans; the scene itself uses the
renderer-supported `sans-serif` font.

Motion language: smooth directed transport, connected magnification transitions, gentle
camera movements and progressive cutaway reveals. There are no arrows, flowchart boxes,
photos, injected SVG, emoji or decorative confetti.

## Scene inventory

| File | Mechanism across three stages |
| --- | --- |
| `01-sun.json` | Establish watershed → solar energy reaches water → warmed surface |
| `02-evaporation.json` | Surface magnification → selected H₂O symbols escape liquid → separated symbols fade into invisible vapour |
| `03-transpiration.json` | Root uptake → connected xylem transport → magnified epidermis, guard cells and vapour leaving a stoma |
| `04-rising-air.json` | Warm moist parcel → the same parcel rises and expands → linked cooling without an automatic cloud |
| `05-condensation.json` | Schematic vapour and nuclei → liquid droplets grow around nuclei → microscopic view resolves into a cloud |
| `06-precipitation.json` | Cloud droplet magnification → collision/coalescence → accelerating gravity-driven rain over the watershed |
| `07-groundwater.json` | Rain reaches a cutaway hillside → downhill surface runoff → infiltration and slower pore-space groundwater transport |
| `08-complete-cycle.json` | Energy and vapour pathways → rising/cooling air, cloud formation and rain → branching surface/underground pathways and a continuous finale |

Scenes 1–7 use version **1.0**. Scene 8 uses the main agent's version **1.1** contract:
stage three lasts 12,000 ms and loops over `[6000, 12000)`. All camera motion finishes
by 5,000 ms. Opaque scenery returns to its exact loop-boundary pose, dashed flow phases
wrap by whole periods, and transport cohorts reset only at zero opacity. The ordinary
production scene controller, not a harness-specific animation engine, owns playback.

## Scientific conventions

- Vapour is invisible. Enlarged H₂O symbols are explicitly described as a scientific model,
  never steam, smoke, boiling bubbles or visible plumes.
- Liquid-water molecules, vapour symbols, liquid cloud droplets and cloud nuclei are distinct.
- Condensation happens around airborne nuclei, which remain visible in the magnification.
- The coalescence example conserves approximate spherical volume:
  initial radii `10, 8, 9, 10` produce a combined radius of `cuberoot(3241)`.
- Rain is represented by small round/near-round drops, not oversized teardrops.
- The leaf detail links internal spaces and a lower-epidermal opening to the root/xylem journey.
- Surface runoff follows downhill terrain. Groundwater follows the saturated permeable zone
  towards the coast, not an empty underground pipe.
- Symbol size, magnification and instructional time are not literal physical scale.
  Groundwater is deliberately much slower than surface runoff. Routes branch: this is not
  a single mandatory route for every molecule.

## Reproduce

Run from the workspace root; no package installation is needed:

```sh
node moodle-plugin/visuallearning/examples/water-cycle/scripts/generate.mjs
node moodle-plugin/visuallearning/examples/water-cycle/scripts/validate.mjs
node moodle-plugin/visuallearning/examples/water-cycle/scripts/build-preview.mjs
node moodle-plugin/visuallearning/examples/water-cycle/scripts/review.mjs
```

For an inspection server after the browser checks:

```sh
node moodle-plugin/visuallearning/examples/water-cycle/scripts/review.mjs --serve
```

Open `http://127.0.0.1:5099/generated-artifacts/svg-showcase/index.html`.
The read-only test server serves only this benchmark directory. It does not alter the
application server or production routes. The harness fetches the actual JSON and bundles
the current production `scene.js`, `scene_timeline.js`, `scene_components.js`,
`scene_geometry.js` and `scene_validator.js` without substituting their logic.

The generic construction vocabulary is in `scripts/lib/illustration.mjs`. Its builders
know geometry, groups, layers, timing and camera operations, **not water-cycle subjects**.
All topic-specific authoring stays in this example's deterministic `scripts/generate.mjs`.
The deterministic random seed is used only for fixed soil/pore texture.

## Verification evidence

Under `generated-artifacts/svg-showcase/`:

- `validation.json`: all eight scenes passed the existing JavaScript and PHP validators.
  Additional evaluator assertions check three-stage continuity and the visible loop boundary.
- `browser-review.json`: real Chromium engine rendering at 1440 × 1120 and 820 × 1180;
  all 24 stages in both viewports, no page errors, stable pause, seeking and actual replay.
  Actual stage-three clock wrap and reduced-motion stillness are exercised in both viewports.
- `screenshots/`: 48 stage-final real-engine captures plus two finale-loop midpoint captures.
- `contact-sheet.png`: eight representative frames, assembled from those actual captures.
- `showcase-1440.png` / `showcase-820.png`: full inspection-harness views.

Frames were visually reviewed and iterated: cloud silhouettes were unified, the forest
was given layered depth, mountain ridges were refined, molecular symbols were enlarged,
the leaf cutaway and stoma were clarified, and magnifications were anchored to their source
locations instead of simply appearing in unrelated panels.

These are desktop Chromium **viewport** checks, not measurements on physical tablet
hardware. The local automated browser reports timing for 50 deterministic seeks per scene;
it is not a production-device performance certification. No signed-in Moodle session,
live model generation, provider billing, persistence acceptance, audio/narration
synchronisation or cross-browser device lab is claimed.

## Engine boundaries and scope

The supplied engine's bounded vocabulary was sufficient for these illustrations.
The benchmark stays below 160 objects, 32 paths, eight layers and the action/particle
budgets. There is no need to bypass validation or inject arbitrary SVG. Softness is
approximated with layered vectors and bounded gradients rather than unsupported blur.
Gradient orientation and typography use the existing supported vocabulary.

The only new capability consumed is the main agent's declared v1.1 finale loop. There are
no additional requested core/server/schema changes, no packages installed, no provider
calls, no production version bumps and no releases. Everything authored or generated by
this task remains inside `moodle-plugin/visuallearning/examples/water-cycle/`.
