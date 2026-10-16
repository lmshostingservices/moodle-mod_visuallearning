# AI Visual Learning: proposed LMS Labs server contract

Status: **PROPOSED, not yet agreed or implemented by LMS Labs.** The plugin fails closed until these routes are live
and publish the approved prices. It follows the conventions of the live AI Safety Hazards and AI Language Teacher
routes, so LMS Labs can reuse their handlers.

- Product: AI Visual Learning. Component: `mod_visuallearning`. Unlock plugin id: `visuallearning`. Route prefix:
  `/api/moodle/ai-visuallearning`.
- Host: `https://lms-labs.com` (fixed in the plugin). Redirects are never followed.
- Authentication: `X-Site-ID` and `X-API-Key` headers, from the Moodle server only.
- Every paid POST carries an `Idempotency-Key` header (a UUID). The plugin stores it, with the exact body, before
  the first send. It sends the same key and body again only when the teacher presses "Check again". It never
  retries on its own, except for a 202 answer, where it asks again with the same key after `Retry-After`.
- Every paid body carries `maxCredits`: the most the teacher confirmed. LMS Labs must refuse (`409 price_changed`,
  nothing made or charged) rather than charge more.
- Charges are reported as `X-Credits-Charged` and `X-Credits-Balance` headers (media) or as `creditsCharged` and
  `creditsBalance` fields (JSON). An unreported charge is shown as "not known" and never assumed.
- Error envelopes: `{"error": "CODE", "requestId": "…", "balance": n}` or
  `{"error": {"code": "…", "message": "…"}, "requestId": "…", "creditsBalance": n}`. `X-Request-Id` is read too.

## Existing licensing routes (unchanged)

| Route | Use |
| --- | --- |
| `POST /api/plugin-unlock/verify` `{pluginId: "visuallearning", siteId, apiKey}` | Free access check → `{unlocked, credits, unlockedAt, entitlementSource}` |
| `GET /api/plugin-versions` | Release catalogue: entry for `mod_visuallearning` with `sha256`, `creditsRequired`, `acquisitionMode: "credit-unlock"`, `status`, `zipExists` |
| `POST /api/plugin-unlock` `{pluginId, pluginComponent, siteId, apiKey, releaseSha256, expectedCredits}` | One-time unlock after the administrator confirmed the live price; verified Marketplace purchases → `creditsConsumed: 0`, `entitlementSource: "marketplace"` |
| `GET /api/credits?siteId=…` (`X-API-Key`) | Balance, for display only |

## New routes

### Capabilities (free, cached by the plugin for one hour)

`GET /pages/capabilities` → `{"tariff": {"page": 2}}`

`GET /images/capabilities` → `{"tariff": {"image": 2}, "model": "gemini-nano-banana-2.1"}`

`GET /speech/capabilities` →

```json
{
  "tariff": {"tts": 2, "clipRefSupported": true, "maxCreditsRequired": true,
             "ttsRemakeLimit": 10, "ttsRemakeWindowDays": 30},
  "locales": [{"locale": "en-AU", "ttsLanguageCode": "en-AU",
               "voices": [{"name": "en-AU-Chirp3-HD-Aoede"}, {"name": "en-AU-Chirp3-HD-Charon"}]}]
}
```

The plugin offers only the voices listed here. A missing or different price stops new paid generation of that kind.

### Pages written by LMS Labs AI

`POST /pages/draft` (Accept: application/json; the plugin waits up to 170 s)

```json
{"title": "How to promote teamwork", "industry": "Hospitality", "subindustry": "", "language": "th-TH",
 "culture": "Thailand", "imageStyle": "…", "maxPages": 12, "recap": true, "trueFalse": true,
 "content": "teacher's content, at most 60,000 characters", "maxCredits": 24}
```

`200` → `{"draft": {…}, "creditsCharged": 2 × pages delivered, "creditsBalance": n}`. `draft` uses exactly the JSON
schema of the plugin's prompt file (`book_title, industry, subindustry, language, style_guide, characters[],
cover_image_prompt, pages[page, title, concept, action, outcome, summary, tf_statement, tf_answer, image_prompt,
alt_text]`). The plugin's `prompt_builder::chatgpt_prompt()` is the reference prompt. Proposed: LMS Labs runs it
server-side with Gemini, so the prompt cannot be changed from a Moodle site. Rules:

- at most `maxPages` pages;
- cards at most 250 characters;
- image prompts in English, with culture taken from `culture`;
- an empty `industry` or `subindustry` is inferred.

Charge per page delivered, never above `maxCredits`. A same-key replay within 24 hours returns the same draft without
a second charge; after that, 410.

### Pages written with the teacher's own AI assistant

`POST /pages/import` `{"pageCount": 12, "titles": ["…"], "maxCredits": 24}` → `200`
`{"creditsCharged": 24, "creditsBalance": n}`.

This route only settles the charge: the pages themselves are never sent. The plugin creates them only after this
`200`. The price per page is the same as `/pages/draft` (owner direction: "whether they use ChatGPT or not it's the
same cost"). A same-key replay after success returns the same `200` without a second charge.

### Illustrations (Nano Banana through LMS Labs)

`POST /images` (Accept: image/png, image/webp, image/jpeg, application/json; the plugin waits up to 170 s)

```json
{"prompt": "at most 2,000 code points, English", "style": "illustration", "aspectRatio": "4:3", "maxCredits": 2}
```

`200` → raw image bytes (PNG, WebP or JPEG, at most 10 MiB), with `X-Credits-Charged` and `X-Credits-Balance`.
Owner direction: the provider is Google **Nano Banana** (`gemini-nano-banana-2.1`, 1K), not GPT Image 2. This differs
from the handover default and needs owner approval and a Gemini image route at LMS Labs. Not retained: a same-key
request after completion → `410 RESULT_NOT_RETAINED`, with no new generation and no new charge.

### Voiceover (Chirp 3 HD through LMS Labs)

`POST /speech/quote` `{"clipRef": "64 hex"}` → `{"credits": 0 | 2, "firstClip": bool, "freeRemakesRemaining": n}`
(free; nothing made, charged or reserved).

`POST /speech/tts` (Accept: audio/mpeg, application/json)

```json
{"text": "at most 300 characters", "locale": "en-AU", "voice": "en-AU-Chirp3-HD-Aoede",
 "speed": "slow | normal | fast", "clipRef": "64 hex", "maxCredits": 0}
```

`200` → MP3 bytes, with `X-Credits-Charged` and `X-Credits-Balance`.

`clipRef` = SHA-256 of `["mod_visuallearning", site identifier, book id, page id, part]`, where the part is concept,
action, outcome, summary or statement. It never changes when the text or the voice changes. It is never reused after
deletion, because page ids are never reused. Proposed billing, the Soft Skills reference:

- 2 credits per clip;
- 10 free re-makes per clip per rolling 30 days;
- failed synthesis does not use a free re-make;
- the server decides eligibility.

`maxCredits: 0` for a quoted free re-make: if it is no longer free → `409 price_changed`. Not retained: same-key
after completion → 410.

## Outcomes the plugin handles

| Answer | Plugin state | Teacher sees |
| --- | --- | --- |
| 200 with usable result | completed (media saved at once) | done, charge as reported or "not known" |
| 200, unusable bytes | lost | may have been charged; contact support with the reference |
| 202 | pending (asked again, same key, up to 40 times) | in progress |
| no answer, 5xx without a documented refusal code, `settlement_unconfirmed`, `deadline_exceeded` | uncertain | outcome not known; "Check again" |
| 402 / `insufficient_credits`, 401, 403, `no_entitlement`, 429, documented provider refusals | failed | nothing charged, and why |
| 404 | failed (`not_live`) | service not live yet, nothing charged |
| 409 | conflict (or failed for `price_changed`) | contact support / ask for the price again |
| 410 | lost (media) or expired (pages) | no longer available; a 410 is not proof of a charge |

## Retention and recovery

- LMS Labs keeps no pictures or audio. The plugin saves them the moment they arrive and does not promise recovery.
- Drafts: replay with the same key for 24 hours, as AI Safety Hazards scene drafts do.
- Imports: settlement replay only.

## Server work still required

1. Owner approval: product identity, the activation price, the per-operation prices, and Nano Banana (instead of
   GPT Image 2) for this product.
2. Register `mod_visuallearning` / `visuallearning` in the catalogue, with entitlement and the unlock price.
3. Implement the routes above:
   - draft, using the reference prompt and Gemini;
   - import settlement;
   - a Nano Banana image route;
   - Chirp 3 HD capabilities, quote and tts with clipRef remakes;
   - tariffs published in the capabilities.
4. Create the docs page `https://lms-labs.com/docs/ai-visual-learning` (Discussion tab: `?tab=discussion`), the
   catalogue metadata and the Quick Links entry.
5. Create the repository `LMSHostingServices/moodle-mod_visuallearning`.
