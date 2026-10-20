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

## Requirements

- Moodle 4.4, 4.5, 5.0, 5.1, 5.2 or 5.3 (one code base; tested on 4.4, 4.5, 5.1 and 5.3).
- PHP 8.1 or later.
- Cron running: confirmed requests are sent to LMS Labs by background (ad hoc) tasks.
- An **LMS Labs** account. AI Visual Learning makes everything through LMS Labs and the site's shared LMS Labs credits;
  no Google or OpenAI key is needed or accepted. LMS Labs uses Google Gemini (page writing), Google Nano Banana
  (illustrations) and Google Cloud Text-to-Speech Chirp 3 HD (voiceover).
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
confirmed first.

| Operation | Unit | Approved price |
| --- | --- | --- |
| Activation (one time, per site) | site | 50 credits (US$5), free with a verified Marketplace purchase |
| New slide, whether AI-written, imported or manually added | slide | 10 credits, including text and first pictures and narration |
| Initial slide and cover pictures, question option pictures and narration | purchased slide | Included, no extra charge |
| Optional new picture after successful delivery | picture | 2 credits |
| Voice remake after successful delivery | clip | 2 credits for every deliberate remake |
| Pictures and voice for legacy v1.0 slides | picture or clip | 2 credits per generation, including every deliberate remake |

The slide charge settles when its text is delivered. Included media remains owed if delivery fails; do not buy another
slide to recover it. Prices are confirmed against the live server before spending. Text editing and reordering existing
slides are free. New slides use the `bundle-1` contract; existing slides retain their original billing.

## How a teacher builds a lesson

1. **Add an AI Visual Learning** activity and fill in the form:
   - title, industry and sub-industry (leave either blank to let the AI choose);
   - the content to teach, and the maximum number of pages (up to 20);
   - narration language, and a voice from the voices LMS Labs offers;
   - cultural setting, illustration style and navigation mode;
   - recap and check-question options (question types, pictures for picture questions), sounds, and completion rules.
2. On **Build the lesson**, write the pages in one of two ways:
   - **Write the pages with LMS Labs AI**: confirm the price, and the pages appear when LMS Labs delivers them.
   - Or **download the prompt file**, run it in your own AI assistant, and paste its whole reply (JSON) into step 2.
     The reply is checked for free and its pages and price are shown. The pages are created once you confirm and
     LMS Labs confirms the charge.
3. **Create the missing illustrations and voiceover**: the screen lists every picture and clip with the total, and
   the balance. After you confirm, LMS Labs makes them in the background and the screen refreshes itself.
4. Edit any page, reorder or delete pages, then preview the lesson. Only what changed needs making again: an edited
   card needs one new clip, and a new prompt needs one new picture. The old media keeps playing until the new media
   arrives.

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

With **Match the country of the narration language** (the default), the language's country shapes the whole lesson. This applies both in the ChatGPT brief and in every image prompt: character names and appearance, workplaces, food, clothing, customs and etiquette.

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

**Add questions to the question bank** puts the questions straight into the course question bank (on Moodle 5.0+, the
course's own question bank activity), in a category named after the lesson, ready for a Moodle Quiz.

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
  - for pages from your own AI assistant: the page count and the page titles;
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
