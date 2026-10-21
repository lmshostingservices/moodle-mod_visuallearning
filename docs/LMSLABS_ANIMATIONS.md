# AI Visual Learning 1.7.0: animated SVG scenes (LMS Labs / Replit handover)

**Date:** 21 October 2026
**Plugin:** 1.7.0 (version 2026102100)
**What LMS Labs needs to build:** one new route, `POST /api/moodle/ai-visuallearning/v2/animations`, plus two
fields in the v2 capabilities. Nothing else changes.

## Summary

- Lessons get a new picture mode, **Animated scene per page**. For each page, Claude draws one animated SVG with a
  stage for each of the page's three cards.
- The plugin does all the playback work:
  - it switches the stages as the cards are read;
  - it plays each stage at a slow teaching pace, timed to the voiceover;
  - it offers "Play animation at normal speed" once the page has been heard.
- **LMS Labs' job:** take the plugin's brief, send it to Claude with the plugin's system prompt, and return the
  SVG. Charge 2 credits for each delivered scene.
- The plugin cleans every SVG before saving it (no scripts, handlers, links or external references) and scopes it
  to the page. LMS Labs should still check the basics before charging (section 4).
- Until LMS Labs publishes the capability below, the plugin shows "Waiting for LMS Labs" for scenes and never
  sends them. Everything else works as before.

## 1. Capabilities: `GET /api/moodle/ai-visuallearning/v2/capabilities`

Add one top-level field once the route is live:

```json
"animations": {"credits": 2, "contracts": ["svg-1"]}
```

| Field | Meaning | Missing means |
| --- | --- | --- |
| `animations.credits` | Price of one scene | Price unknown: scenes stay on hold |
| `animations.contracts` | The SVG contracts LMS Labs draws to | Scenes stay on hold until it lists `svg-1` |

**Price (owner approval needed):** the plugin's approved price is **2 credits per scene**, the same as a picture.
If LMS Labs publishes another number, teachers see "price changed" and nothing is sent.

## 2. `POST /api/moodle/ai-visuallearning/v2/animations`

### Headers

Headers are the same as for `/v2/images`:
- the usual site credentials;
- `Content-Type: application/json`;
- `Accept: image/svg+xml, application/json`;
- `Idempotency-Key` (a UUID, stored and reused on every retry).

### Body for a purchased slide (bundle-1)

```json
{
  "prompt": "Draw the animated SVG scene for one page of a narrated lesson.\n\nLESSON: …\nPAGE TITLE: …\nLANGUAGE OF EVERY LABEL AND CAPTION: English (Australia)\n\nTHE THREE CARDS …\nCARD 1 (Key point 1): …\nCARD 2 (Key point 2): …\nCARD 3 (Key point 3): …\n…\nAUDIENCE: …\nART DIRECTION: …\nCULTURAL SETTING: …",
  "contract": "svg-1",
  "language": "en-AU",
  "learnerLevel": "secondary",
  "teachingApproach": "science",
  "purpose": "animation",
  "bundleId": "…",
  "slideId": "…",
  "slot": "animation",
  "remake": false,
  "maxCredits": 2
}
```

- `prompt` is at most 12,000 characters. The plugin builds it in `prompt_builder::svg_prompt()`.
- `learnerLevel` is `primary`, `secondary`, `adult` or `advanced`. `teachingApproach` is `story` or `science`.
  Both are in the prompt too; the fields are for routing and reporting.
- A scene is an **extra of a purchased slide**, paid at `animations.credits`, like a card picture. There is no
  included slot and no free first delivery, and `remake` is always false: every scene, including a remake, costs
  the same.

### Body for an older slide (no bundle)

The body is the same without `bundleId`, `slideId`, `slot` and `remake`. Charge the site 2 credits in the usual way.

### Response

- **200** with either form:
  - the SVG itself, `Content-Type: image/svg+xml`; or
  - `application/json` with `{"svg": "<svg …</svg>"}`.
- **Headers:**
  - `X-Credits-Charged`;
  - `X-Credits-Balance` (optional);
  - **`X-Delivery-Id`, required whenever the body had a `bundleId`.** The plugin stores it as the slot receipt and
    treats a delivery without it as needing support.
- **202:** still in progress. Use it when drawing takes longer than about 150 seconds. The plugin waits up to 170
  seconds per try and asks again with the same key and body. The same key and body must return the same scene and
  never charge twice.
- **Errors:** as for `/v2/images` (`insufficient_credits`, `price_changed`, `provider_failed`, …). Charge nothing
  for any error.
- **Unusable answers:** if what is delivered is not a usable SVG, the plugin marks the request as lost and keeps
  the page picture. So never charge for an SVG that fails the checks in section 4; return an error instead.

## 3. How to draw a scene with Claude

- **Model:** `claude-sonnet-5-5`. It draws these scenes well and is the cheapest good option.
  - Optional: use `claude-opus-5-5` for `learnerLevel: "advanced"` science pages, where precision matters most.
- **System prompt:** the plugin's `schema/svg-animation-system.md`, **verbatim**. It is the contract: format,
  stages, animation rules, house style, spacing, safety and accuracy, with a worked example.
  - Cache it with prompt caching (`cache_control: {"type": "ephemeral"}` on the system block). It is identical for
    every request, so after the first call its input cost is close to zero.
  - When the plugin updates the file, LMS Labs updates its copy. The `contract` field changes only if the format
    changes.
- **User message:** the request's `prompt`, unchanged.
- **Settings:** `max_tokens: 16000`, default temperature, no tools.
- **Answer:** Claude is told to answer with the SVG only. Take the text from the first `<svg` to the last `</svg>`.
  This also strips a stray code fence.

Rough cost per scene on Sonnet 5.5 ($2 input / $10 output per million tokens):
- about 1,000 uncached input tokens;
- the cached system prompt;
- about 4,000 to 6,000 output tokens.

That comes to about **5 US cents**, against a 2-credit (20 US cents) price. The Batch API halves the cost, but
teachers wait for scenes, so batching suits only bulk remakes.

## 4. Checks before charging

Retry once with the same prompt if any check fails. If the second answer also fails, return `502` with
`{"error": {"code": "animation_failed"}}` and charge nothing.

1. The answer contains `<svg` … `</svg>`, and the root has a `viewBox` with a positive width and height.
2. It is at most 40 KB (the plugin accepts up to 300 KB, but anything near that is a bad answer).
3. It contains no `<script`, `<foreignObject`, `<iframe`, `<image`, `javascript:` or `on…=` attributes. The
   plugin removes these anyway; their presence means the answer ignored the contract.
4. It parses as XML.

## 5. Nothing else changes

- Pages, pictures, voiceover, card pictures, bundles, remakes and idempotency work as before.
- The page picture is still made and paid as today. It is the cover, recap and question picture, and the fallback
  until the scene is ready.
- **1.6.0 fixes need nothing from LMS Labs.** The plugin no longer blocks page writing while
  `animatedSceneVersions` is missing, and picture lessons are no longer turned into animated ones.

## Acceptance checks

1. Without `animations` in the capabilities, a quote for a lesson in this mode leaves the scenes out ("Waiting for
   LMS Labs") and still quotes pictures and voiceover.
2. With `"animations": {"credits": 2, "contracts": ["svg-1"]}`, a quote for a 4-page lesson's scenes totals 8
   credits, and nothing is sent until the teacher confirms.
3. A confirmed scene for a purchased slide sends the body above with `slot: "animation"` and returns 200, the SVG,
   `X-Credits-Charged: 2` and an `X-Delivery-Id`. The plugin shows the page's "Animated scene" as Ready.
4. The same key and body return the same SVG and never charge twice. A 202 followed by a retry delivers once.
5. An answer that fails section 4 twice returns `animation_failed` and charges nothing.
6. A `primary` page comes back with at most three main parts and large labels. An `advanced` page comes back with
   textbook detail. Labels are in the lesson's language.
7. Played in the lesson: each card's stage plays while the card is read, at half speed (CPR-style scenes marked
   `data-realtime="true"` play at real speed). "Play animation at normal speed" appears after the last card.
