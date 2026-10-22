# AI Visual Learning (mod_visuallearning)

A Moodle activity that teaches a topic **one concept per page**, as an illustrated, narrated lesson for adult learners.

Each page shows an AI illustration on the left and three colour-coded cards on the right:

| Card | Colour | What it says |
| --- | --- | --- |
| **The idea** | Indigo | The concept and why it matters |
| **Put it into action** | Teal | One concrete thing a person does at work to apply it (this is what the illustration shows) |
| **The outcome** | Amber | The result or consequence of that action |

Learners press **Listen** on each page. A natural AI voice reads each card in turn, and each card rises and glows while it is read. The **Next** button appears only when the whole page has been heard.

After every four concepts the lesson adds:

- a **Let's recap** slide, where four numbered summaries (with their four illustrations in an even 2 × 2 grid) animate in one at a time as they are read aloud;
- **one check question per page**, in four types the AI rotates: true or false, pick the picture (four square pictures or text cards), match them up and sort into groups. A wrong answer shows which page holds the answer; the learner presses **Go back and listen**, Next stays locked until they have heard the page again, and they come straight back to the same question. Feedback is shown and read aloud after a right answer.

Every page view, every listen, the time spent on each page and every answer is tracked. The finish screen gives each learner a green tick, confetti and their own results; little interface sounds (with a mute switch) make answering satisfying.

## Picture styles, callouts and animated diagrams (1.8.0)

The lesson settings are short and visual: **Your lesson** (title, what to teach, extra instructions and material,
who it is for and teaching style as cards, number of pages), **Pictures** (picture style, animation and callouts as
cards with examples), **Voice**, and everything else under **More options**. Saving a new lesson opens the
"Create my lesson" step: one confirmation and Claude writes the pages, then the pictures, animated diagrams and
narration are made automatically.

| Picture style | Made by | Best for |
| --- | --- | --- |
| Illustration | OpenAI GPT Image 2.5 Flare | people, places and workplace stories |
| Photo-realistic | OpenAI GPT Image 2.5 Flare | real equipment and settings |
| 3D diagram | OpenAI GPT Image 2.5 Flare | realistic 3D organs and machines with flow arrows |
| Technical model | OpenAI GPT Image 2.5 Flare | section views and schematics with exact labels |
| Hand-drawn sketch | OpenAI GPT Image 2.5 Flare | a teacher's sketchnote on graph paper |

Animated diagrams are drawn by Claude, as SVG.

- **Callouts** (tick box): pictures carry short labels and at most one small info card, written in the lesson's
  language. Without it, photographs and illustrations have no words at all.
- **Animate each page** (tick box): Claude draws one animated diagram per page with a stage for each card. While a
  card is read, its stage plays slowly in time with the voice; after the last card the learner can replay the
  whole animation at normal speed. Pages with nothing that moves keep their picture and cost nothing extra.
- Animated diagrams and technical models share one house style: shaded 3D drawing, label cards set in the
  bundled label font (Plus Jakarta Sans), colour that carries meaning, and parts that change shape or move the way
  the real thing does.
- Every SVG is cleaned by the plugin's safety filter before it is saved or shown.

## Requirements

- Moodle 4.4, 4.5, 5.0, 5.1, 5.2 or 5.3 (one code base; tested on 4.4, 4.5, 5.1 and 5.3).
- PHP 8.1 or later.
- Cron running: confirmed requests are sent to LMS Labs by background (ad hoc) tasks.
- An **LMS Labs** account. AI Visual Learning makes everything through LMS Labs and the site's shared LMS Labs credits;
  no Anthropic, OpenAI or Google key is needed or accepted. LMS Labs uses Anthropic Claude (page writing and animated
  diagrams), OpenAI GPT Image 2.5 Flare (every still picture) and Google Cloud Text-to-Speech Chirp 3 HD (voiceover).
- Recommended: **LMS Labs Central Config** (`local_aiconfig`) holding the site's LMS Labs Site ID and API key.
  Without it, both can be entered in the AI Visual Learning settings.

## Installation

1. Install the ZIP in *Site administration > Plugins > Install plugins*, or copy the `visuallearning` folder to
   `mod/visuallearning` (`public/mod/visuallearning` on Moodle 5.1 and later).
2. Complete the upgrade in *Site administration > Notifications*.
3. Open *Site administration > Plugins > Activity modules > AI Visual Learning*:
   - check that the credential line says the Site ID and API key are in use;
   - press **Check access** (free). This also reads the LMS Labs voice list and prices;
   - press **Unlock…** to activate the plugin for the site. The live one-time price is shown and confirmed first. A
     verified Moodle Marketplace purchase is recognised by LMS Labs and not charged again.

No Composer step or other build step is needed.

## Prices

AI Visual Learning charges LMS Labs credits only for what LMS Labs delivers, and never more than the price shown and
confirmed first. One credit costs the teacher US$0.10. Teachers never see this table: they see one total, in credits
and US dollars, on the lesson form and again on the confirmation screen.

| Operation | Unit | Approved price |
| --- | --- | --- |
| Activation (one time, per site) | site | 50 credits (US$5), free with a verified Marketplace purchase |
| New slide, whether AI-written, imported or manually added | slide | 10 credits, including text and first pictures and narration |
| Initial slide and cover pictures, question option pictures and narration | purchased slide | Included, no extra charge |
| Optional new picture after successful delivery | picture | 2 credits |
| Voice remake after successful delivery | clip | 2 credits for every deliberate remake |
| Animated diagram ("Animated diagrams" card) | page that has something that moves | 6 credits, and 6 credits for each deliberate remake |
| Pictures and voice for legacy v1.0 slides | picture or clip | 2 credits per generation, including every deliberate remake |

The slide charge settles when its text is delivered. Included media remains owed if delivery fails; do not buy another
slide to recover it. Prices are confirmed against the live server before spending. Text editing and reordering existing
slides are free. New slides use the `bundle-1` contract; existing slides retain their original billing.

## How a teacher builds a lesson

1. **Add an AI Visual Learning** activity and fill in the short form: the title, what to teach (a grey example shows
   the idea), and optionally your notes, documents and instructions (up to 50,000 characters pasted from notes, a
   unit outline, a procedure, a PDF, Word document or PowerPoint). Then pick cards for who it is for, the teaching
   style, the picture style, animation and callouts, and choose the narration language and voice. The lesson's price
   is shown as one total above the save buttons and updates as you choose. Everything else is under More options.
2. Saving opens **Create my lesson**. The confirmation screen lists what will be made and shows the same one total;
   Claude then writes the pages and the pictures, animated diagrams and narration are made automatically. Step 1
   shows "Claude is writing your lesson…" and the screen refreshes itself as everything arrives.
3. **Check your pages**: read, edit, reorder, add or delete pages. Anything a change makes out of date can be made
   again in step 3; you always see one total price before confirming.
4. Preview and share. Only what changed needs making again: an edited card needs one new clip, and a new picture
   description needs one new picture. The old media keeps playing until the new media arrives.

### Charging, retries and media retention

- Every paid request is stored, with its own Idempotency-Key and its exact body, before it is sent.
- Nothing is sent again automatically after a timeout or an error. **Check again** asks LMS Labs about the same
  request with the same key and body, so it can never be made or charged twice. Asking for something again is a
  new request that is priced and confirmed separately.
- When an outcome is not known, it is shown as not known yet ("may still have been made and charged"). It is never
  shown as free or refunded. A charge is recorded only when LMS Labs reports one.
- LMS Labs does not keep pictures or audio. They are saved in Moodle the moment they arrive. When LMS Labs answers
  that a result is no longer available (HTTP 410), the request is shown as lost, with the LMS Labs reference for
  support. A 410 alone is not taken as proof of a charge.
- A failed request never removes media learners already have.
- Paid generation fails closed. It stops when:
  - credentials are missing;
  - the site is not activated;
  - the feature is switched off;
  - LMS Labs publishes no price, or a price that differs from the approved one;
  - the chosen voice is not in the LMS Labs voice list.
- Learners' pages never contact LMS Labs.

### Cultural setting

With **Match the country of the narration language** (the default), the language's country shapes the whole lesson. This applies both in the page writer's brief and in every picture prompt: character names and appearance, workplaces, food, clothing, customs and etiquette.

- Thai narration produces Thai settings, such as a team meal at a local Thai restaurant.
- Spanish (Spain) narration produces Spanish ones, such as paella with colleagues.
- Activities are adapted to local norms. For example, the prompt avoids alcohol where it is not customary.

Choose **International / neutral** for a mixed, location-neutral cast.

## Reusing the content

On the **Ready** step, **Download all content** gives one ZIP (free, nothing is sent to LMS Labs):

- `questions/`: the check questions as Moodle XML (imports into any question bank), GIFT and CSV. True or false
  becomes True/False, pick the picture becomes Multiple choice with its pictures, and match them up and sort into
  groups become Matching;
- `lesson/`: the text of every page as a Word document, Markdown and CSV;
- `pictures/`, `voiceover/` (with a transcript) and `prompts/` (ready-made prompts with the lesson's content inside, to
  make more quizzes, scenarios or flashcards with any AI assistant).


## Completion and reporting

Completion rules:

- **Listen to every page.** The full voiceover of every page must be heard.
- **Answer every check question correctly** (optional).

The **Listening report** shows, for each learner: pages listened, progress, questions right and attempts, time spent and last access, with a page-by-page drill-down and CSV export. The **Question attempts** grid shows the whole class against every question (1st try, 2 tries, 3 or more, not right yet), lists the pages to go over again, and downloads as CSV.

On Moodle 5.0 and later the activity also appears on the course **Activities** overview page.

## Privacy

- Only teacher-written content leaves the site, from the Moodle server to LMS Labs (lms-labs.com):
  - for pages written by LMS Labs AI: the title, industry, sub-industry, language, cultural setting, illustration
    style and the content to teach;
  - for a page added by hand: its title;
  - for illustrations: the illustration prompt, with the art style, cultural setting and recurring characters;
  - for voiceover: each clip's text, language, voice and speed, and a clip reference that contains no text.
- The Site ID and API key travel only in request headers. They never reach a browser, a URL or a log.
- No learner data is sent to any external service.
- Answers are checked on the server; right answers (and the feedback that explains them) are never sent to the browser before the learner answers.
- The plugin implements the Privacy API. It exports and deletes learner progress, and exports the teacher's link to
  the paid requests they confirmed. The requests themselves are kept for charge tracing. Backup and restore work
  with or without user data, and so does course reset.

## Support

- Documentation and discussion: <https://lms-labs.com/docs/ai-visual-learning> (Discussion: `?tab=discussion`) - page to be
  created by LMS Labs.
- Source and issues: <https://github.com/LMSHostingServices/moodle-mod_visuallearning> - repository to be created.
- Email: support@lmshostingservices.com

## Licence

GNU GPL v3 or later. © 2026 LMS Hosting Services.
