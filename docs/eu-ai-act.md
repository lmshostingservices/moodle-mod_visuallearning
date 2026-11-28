# AI Visual Learning and the EU AI Act

Release 1.10.0 · checked against the law on 26 November 2026.

This is LMS Hosting Services' technical analysis as the provider of AI Visual Learning (mod_visuallearning) with
LMS Labs AI. It is not legal advice. Each organisation using the plugin should confirm its own position.

## 1. What the plugin does with AI

A teacher gives the plugin their content (a topic, notes or a document). LMS Labs AI then:

- writes the pages, cards, summaries and check questions;
- makes the pictures, the animated diagrams and the narration.

The teacher can read, edit, re-make or delete every page before learners see it. Check questions are marked by fixed
rules in Moodle (the right answer is stored on the page), not by AI. AI does not grade learners, decide who gets into
a course, choose what a learner sees next, or watch learners during tests.

## 2. Classification

| Use | Classification | Reason |
|---|---|---|
| Writing lesson text and questions | Article 50 (transparency) | Generates synthetic text for a teacher to check and publish. |
| Pictures, animated diagrams, narration | Article 50(2) (machine-readable marking) | Generates synthetic images, video-like animation and audio. |
| Marking check questions, completion, grades | Not an AI system | Fixed rules compare the answer with the stored one. |
| Rating Moodle learning outcomes (optional) | Not Annex III 3(b) as built | A fixed formula from rule-marked answers, and only after a teacher has checked the lesson. |
| Admission, placement, adaptive steering, proctoring | Not done | The plugin has none of these (Annex III 3(a), 3(c), 3(d)). |
| Emotion recognition of learners | Not done (prohibited, Article 5(1)(f)) | Never built. |

**Result:** AI Visual Learning is a content generator with Article 50 transparency duties. It is not a high-risk
system under Annex III point 3 as built. If a future version used AI to mark answers or rate learners, that part would
need re-classifying as high-risk before release.

## 3. Dates

| Duty | Applies from |
|---|---|
| Prohibited practices; AI literacy (Article 4) | 2 February 2025 |
| General-purpose AI model duties (model suppliers) | 2 August 2025 |
| Article 50 transparency (new systems) | 2 August 2026 |
| Article 50(2) machine-readable marking for systems already on the market | 2 December 2026 |
| Annex III high-risk duties (if ever relevant) | 2 December 2027 |

Dates follow Regulation (EU) 2024/1689 as amended by the Digital Omnibus on AI. Re-check before each release.

## 4. Who does what

**Provider: LMS Hosting Services (LMS Labs).** Makes the generating system available under its name.

- Article 50(2): mark AI-generated pictures, audio and video in a machine-readable, detectable way that is as
  effective, robust and reliable as technically feasible.
- Give deployers the information they need (this document) and keep the marking switched on by default.

**Deployer: the school, RTO or company running Moodle.** Uses the plugin with its learners.

- Article 4: take measures so staff using AI have enough AI literacy.
- Article 50(4): disclose deepfakes and AI text published to inform the public on matters of public interest. Lessons
  are usually neither, but the learner notice covers AI content in every lesson anyway.
- Use the plugin as these instructions describe, and keep the teacher check in place.

**Model suppliers** (the AI models LMS Labs uses) carry the general-purpose AI model duties (Articles 53 and 55).

## 5. What the plugin does for each duty

| Duty | Article | Feature (1.10.0) |
|---|---|---|
| Machine-readable marking of AI media | 50(2) | Every picture, voice clip and animated diagram is marked when saved: IPTC digital source type `trainedAlgorithmicMedia` with provenance in XMP (PNG, JPEG, WebP), an ID3 tag (MP3) and RDF metadata (SVG). Existing markings, such as a signed C2PA manifest, are never removed. Setting: *Mark AI media* (on). |
| Telling learners | 50 | A "Made with AI" note on each lesson's opening page says which parts AI made, who checked the lesson and when, and to tell the teacher if something looks wrong. Setting: *Tell learners about AI* (on). The note carries `data-ai-generated="true"`. |
| Provenance record | 50(2) | *Download all content* includes `ai-provenance.json`: what AI made, the files involved, the marking used and the teacher check. |
| Human oversight / editorial responsibility | 50(4), good practice | *Teacher check* on the Ready step: the teacher marks the lesson as checked, and the check is cleared whenever AI writes new pages. Setting: *Teacher check before learners* (off) blocks learners until the check is done. |
| No automated rating without a check | Good practice | Learning outcomes are only rated after a teacher has checked the lesson. |
| Pause | 26(5) good practice | *Pause AI generation* stops all new AI work on the site at once. Learners keep existing lessons; teachers can still edit by hand. |
| AI literacy | 4 | *Using AI responsibly* panel on the Ready step: what AI made, its limits, the teacher's part, what learners see and how to report problems. |
| Privacy | GDPR | The Privacy API declares the teacher who checked a lesson, exports it and removes it on request (the lesson stays checked). Learners' names are never sent to LMS Labs. |

## 6. Instructions for use

**Intended purpose.** Turning a teacher's own content into short illustrated, narrated lessons with check questions,
for education and workplace training.

**Not intended for.** Grading or ranking learners by AI, deciding admission or placement, monitoring learners during
tests, or teaching safety-critical procedures without a qualified person checking the content.

**Known limits.**
- AI can be wrong, out of date or biased, and can miss local rules and standards (for example Australian standards).
- Pictures can show details that are not accurate (labels, equipment, people's protective gear).
- Narration can mispronounce names and technical terms.
- Text may read less well for learners using a second language; keep the reading level setting suitable.

**Human oversight steps.**
1. Read every page, picture and question before learners see the lesson.
2. Fix or delete anything wrong; re-make a picture or voice clip if needed.
3. Mark the lesson as checked on the Ready step. If AI writes new pages, check again.
4. Consider switching on *Teacher check before learners* for the whole site.

**Suspending use.** An administrator ticks *Pause AI generation* in the plugin settings. Report the problem to LMS Labs.

**Telling learners.** Keep *Tell learners about AI* on. Do not remove the AI markings from downloaded files.

## 7. Provider to-do list (LMS Labs)

| Item | Status |
|---|---|
| Plugin: metadata marking of all stored AI media | Done (1.10.0) |
| LMS Labs server: signed C2PA manifest on every picture, audio clip and animation it returns | To do by 2 December 2026 |
| LMS Labs server: imperceptible watermark on pictures and audio (second marking layer) | To do by 2 December 2026 |
| LMS Labs: public way to check whether a file was made by LMS Labs (detection) | To do |
| LMS Labs terms of service: forbid removing AI markings | To do |
| Model supplier information (Article 53(1)(b)) on file for each model used | To do |
| Re-check the Commission's code of practice on marking and labelling once final | Ongoing |

## 8. Sources

- Regulation (EU) 2024/1689 (Artificial Intelligence Act), EUR-Lex: https://eur-lex.europa.eu/eli/reg/2024/1689/oj
- European Commission, AI Act Service Desk: https://ai-act-service-desk.ec.europa.eu/
- European Commission, Code of Practice on marking and labelling of AI-generated content:
  https://digital-strategy.ec.europa.eu/en/policies/code-practice-ai-generated-content
- IPTC Digital Source Type vocabulary: https://cv.iptc.org/newscodes/digitalsourcetype/
- C2PA technical specification: https://c2pa.org/specifications/
