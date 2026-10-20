# Changelog

All notable changes to mod_visuallearning are documented here.

## [1.5.5] - 2026-10-20

- Slides remain 10 credits including their first pictures and narration.
- Every deliberate picture or voice remake costs 2 credits; no free voice-remake allowance.
- Quotes use the server's current remake allowance rather than a hard-coded allowance.
- Simplified included-media wording; retries still cannot create duplicate charges.
- Requires the matching LMS Labs billing update. Existing receipts remain unchanged.

## [1.5.4] - 2026-10-20

- Striking pictures, for any topic. The prompt file now gives your AI assistant a recipe for every picture prompt.
  - Workplace stories:
    - a hero subject;
    - a deliberate camera viewpoint with depth;
    - light that carries meaning;
    - the moment of action, with small motion cues;
    - a subtle reveal when the idea is invisible (germs, heat, trust).
  - Scientific lessons:
    - the real object as the hero;
    - a cutaway, x-ray or magnified reveal of the mechanism inside it;
    - glow and colour as information;
    - motion cues (arrows, flowing particles, a faded "before" view);
    - a three-quarter camera view.
  - The worked examples and the AI's self-checks follow the recipe.
- Only the prompt file changes. The art style and rules wrapped around each picture prompt are unchanged, so no
  existing picture needs remaking.
- Pictures for scientific lessons can tell LMS Labs what they are for (`"teachingApproach": "science"`), so it can
  use the image model best at accurate technical illustration. This is sent only once LMS Labs lists it, and story
  pictures send exactly what they always did.

## [1.5.3] - 2026-10-20

- Every visit to a page starts again from the beginning, even on pages already heard:
  - the animated diagram rebuilds from card 1;
  - with one picture per card, card 1's picture shows first.

## [1.5.2] - 2026-10-20

- Animated diagrams now play in stages, card by card. Each card's movement plays once while that card is read,
  lasting exactly as long as its voiceover clip, and stops where the next card carries on. For example:
  - card 1: the voltage trace rises to its peak;
  - card 2: it falls through zero to the trough;
  - card 3: it returns to zero, completing the cycle.
- What stages can do, by diagram type:
  - waveform: trace a range of the wave;
  - phasor: turn the vectors to an angle;
  - circuit: make the current flow clockwise or anticlockwise, or stop;
  - process, cycle, forces and bars: highlight one step, force or bar.
- Each stage can carry a short caption ("Voltage rises to its peak"), shown under the diagram while its card is read.
- Pausing the narration pauses the movement. Replaying the page starts it again from card 1. Reduced motion jumps
  straight to each card's end state.
- The prompt file explains stages to your AI assistant. Stages are optional: diagrams without them move as in 1.5.0.

## [1.5.1] - 2026-10-20

- Document the validated JSON-only diagram form field for the release security checker.
- Preserve all 1.5.0 picture modes, whiteboard sketches and existing request contracts.

## [1.5.0] - 2026-10-19

- New lesson setting **Picture mode** (with a site default):
  - **One picture per page**: as before (default; existing lessons keep it).
  - **One picture per card (builds the scene)**: three pictures per page, one for each card, shown as that card is
    read. Card 2's picture is made by editing card 1's picture, and card 3's by editing card 2's, so the scene builds
    step by step. Each card picture is priced before anything is made (proposed 2 credits each). Card pictures are
    made only once LMS Labs offers them; until then the page picture shows and nothing else is held up. A new card 1
    picture makes cards 2 and 3 out of date, because they were edits of the old picture. If a card picture fails,
    the cards after it are cancelled before sending, so nothing is charged for them.
  - **Animated diagrams**: the player draws an accurate, moving SVG diagram from a small spec the AI writes, and
    builds it card by card in time with the narration. Types: waveforms (half-cycles, period, phase lag), phasors,
    circuits with flowing (or alternating) current, process steps, cycles, force arrows and bar charts. No credits.
    Movement stops for people who ask for reduced motion. Pages without a diagram keep their picture.
- The prompt file asks your AI assistant for `card_image_prompts` or a `diagram` only when that mode is chosen. Both
  can also be edited per page. Invalid specs are refused with a clear message.
- New visual style **Whiteboard sketch**: hand-drawn marker sketches on a white board, like a teacher explaining at
  the board.
- Scientific lessons show the cards as numbered steps: a coloured tile with 1, 2 or 3 in the card's own colour, next
  to the Key point heading.
- Every picture (page, card, cover and question pictures, and diagrams) now fades in and rises gently into place. It
  uses the same easing and length as the cards' entrance.
- Build step 3 shows a "Card pictures n/3" status per page and a "New card pictures" remake button.
- Exports include the card pictures.
- Story lessons in the illustration style send exactly the same requests and prompts as 1.4.0.

## [1.4.0] - 2026-10-19

- New: Teaching approach and Visual style, two independent lesson settings (with site defaults).
  - Teaching approach decides what each page explains, what its three cards say and what its picture must show:
    - Workplace story: the original approach;
    - Scientific and technical explanation: the cards are Key point 1, 2 and 3, building in order (the core idea,
      how it works, the result), with no invented people or workplace stories. The picture is an accurate
      explanatory diagram of the mechanism, with characters optional.
  - Visual style decides only how pictures look: Illustration (the original) or Technical diagram.
  - Any approach works with any style.
- Scientific pictures use a dedicated master prompt: explain what happens, why, how and what changes, in the clearest
  format. The AI builds each picture from the concept, mechanism, relationships, format, style, accuracy constraints
  and exclusions, and is told never to invent mechanisms, values or equations. A few short labels are allowed.
- Card labels follow the approach everywhere: player, page editor, build page and exports.
- Approaches and styles live in two registries (classes/local/approaches.php with classes/local/approach/*, and
  classes/local/visual_styles.php), so more can be added without touching the rest of the code.
- Existing lessons become workplace stories in the illustration style. Their prompts and LMS Labs requests are
  unchanged, so no picture or clip needs making again.
- LMS Labs AI receives the approach and style as new fields once LMS Labs lists them, and as text until then.

## [1.3.1] - 2026-10-18

- Fixed: "custom_completion::get_sort_order() is missing one or more completion conditions" once the activity became
  graded. The display order now lists the standard grade conditions (receive a grade, receive a passing grade)
  alongside view, listen to every page and answer every question.

## [1.3.0] - 2026-10-17

- New cover page:
  - quick facts: pages, an estimated time and the number of check questions;
  - "What this lesson is about": the activity description, or a plain summary when there is none;
  - "What you will learn": every page by number, in its parts, each part followed by its recap and its questions.
    Pages already listened to are ticked; pages the learner may open are links;
  - "How this lesson works": Listen, Recap and Check. It explains that a wrong answer takes the learner straight back
    to the page to listen again and then back to the same question, with no penalty;
  - what is needed to pass, from the teacher's grade to pass (for example "answer at least 9 of the 12 questions
    correctly. That is the passing grade of 75%").
- Only the parts a lesson has are described (no recap or check text when they are switched off).
- Phones: picture, title, facts, about, Start, how it works, then the outline.

## [1.2.9] - 2026-10-17

- Fixed: the 1.2.8 upgrade stopped with "cannotexecduringupgrade". It wrote learners' grades during the upgrade, which
  updates completion, and Moodle does not allow that while an upgrade runs. The upgrade now only adds the grade setting
  and queues a one-off task ("regrade") that creates the grade items and fills in learners' grades straight after.
  A site stopped part-way through the 1.2.8 upgrade finishes cleanly with this version.

## [1.2.8] - 2026-10-17

- Gradebook: the activity now has a grade (default out of 100; points, a scale or none). The grade is the share of
  check questions answered correctly, on any attempt, so a learner who eventually gets every question right has 100%.
  A lesson without check questions is graded on pages listened to. Grade to pass (default 100%) and the core
  "Receive a grade" / "Receive a passing grade" completion conditions work as for any graded activity. Existing
  lessons get a grade item and their learners' grades on upgrade. Grades follow page and question edits, deletions,
  course reset and backup/restore (scales are remapped).
- Finish screen: "You passed!" with a green banner "You have passed this activity. You have achieved the passing grade
  of 75%. Your grade: 100%." (the teacher's own grade to pass). If not passed yet: "Almost there!", the grade, the
  passing grade, how many questions are left, a "Go to the question" button, and no confetti.
- Finish screen: "Go to next activity" button (with the activity's name) that opens the next activity in the course
  index the learner can access, including activities inside subsections. Not shown at the end of the course.

## [1.2.7] - 2026-10-17

- After a wrong answer the narrator reads a new per-page clip: "Not quite. Listen to “page title” again, then come
  straight back here to try once more." The clip ("retry", bundle slot `clip:retry`) is requested, priced and counted
  as missing only once LMS Labs lists `clip:retry` in its v2 capabilities; until then nothing changes. It is made only
  in languages the plugin has the sentence for (English for now); other lessons stay silent rather than read English.
- Question text is smaller (about 1.35rem on desktop, 1.1rem on phones).
- Sort chips that wrap onto two lines are left-aligned with proper padding.

## [1.2.6] - 2026-10-17

- Check questions no longer sit white on white: true and false cards are tinted green and red, picture and text
  options, match items and the sort tray have a grey or indigo tint with a clear edge, sort groups and the answer
  panels are stronger. Beside a picture, answers keep their natural height and the question is centred.

## [1.2.5] - 2026-10-17

- Build step 3 redesigned: one status panel (page pictures, page voiceover, cover, progress bar and what LMS Labs is
  doing now), a page-by-page grid with each page's picture and Picture/Voice status, and remakes on each page card.
  Only requests that need the teacher are listed in full; requests still running are collapsed into one line. The
  pricing detail moved under "How pricing works".

## [1.2.4] - 2026-10-17

- The price screen now says which pictures and clips are included in the slide price already paid, instead of
  listing them at 2 credits each (and calling included clips "free remakes") above a total of 0. When nothing is
  charged, the confirm button reads "Make them (no charge)".

## [1.2.3] - 2026-10-17

- Check questions show the page's illustration beside the question (above it on phones). Picture-pick questions with
  picture answers keep the full width and show no page illustration, so it cannot give the answer away.

## [1.2.2] - 2026-10-17

- Every page is now recapped and checked. A single page left over after the groups of four joins the group before it
  (5 pages make one recap of 5; 9 make 4 + 5) instead of being skipped. In existing lessons with 5, 9, 13... pages,
  the last page's recap narration needs regenerating, because its spoken number changes from "1." to "5.".

## [1.2.1] - 2026-10-17

- Recap slide spotlights the illustration for the concept being read aloud; the other illustrations frost over until
  their turn, and all clear when the recap finishes.

## [1.2.0] - 2026-10-17

- New slides use the approved 10-credit bundle, including first pictures and narration.
- Persist slide entitlements and delivery receipts, including backup and restore.
- Recover lost included media without another slide charge; retain legacy media pricing.
- Manual additions require price confirmation and append without deleting existing pages.
- Fixed question types preserve the media entitlements purchased with each slide.

## [1.1.0] - 2026-10-17

### Added
- One check question per page in four types: true or false, pick the picture (four square picture cards, or text
  cards), match them up and sort into groups. The AI rotates the types the teacher allows; every question can be edited.
- A wrong answer no longer jumps away: the learner sees where the answer is, presses "Go back and listen", hears the
  page again (Next stays locked, also after a reload) and comes straight back to the same question with their earlier
  choice crossed out.
- Feedback for every question, shown and read aloud after a right answer.
- Little interface sounds (hover, choose, match, page turn, right, not quite, recap, finish) with one remembered mute
  switch; a teacher setting turns them off.
- Finish screen with a green tick, confetti, a success sound and the learner's own results (tries per question).
- Class attempts grid in the report (learners x questions), attempts CSV, headline numbers and the pages to go over
  again.
- Download all content in one ZIP (questions as Moodle XML, GIFT and CSV; text as Word, Markdown and CSV; pictures;
  voiceover and transcript; an AI prompt pack) and Add questions to the question bank.
- Step-by-step build wizard with a "Your lesson is ready" step: slide filmstrip, preview, what to do next.

### Changed
- Option pictures are square (1:1) Nano Banana pictures at the picture price; question and feedback clips at the
  clip price. Every one is priced and confirmed first; option pictures wait for LMS Labs to publish 1:1 support
  without holding up anything else.
- New calm teacher look (no theme-coloured button clutter) and a refreshed learner player.

## [1.0.0] - 2026-10-17

### Added
- AI Visual Learning activity: one concept per page, with an illustration on the left and three colour-coded,
  narrated cards on the right (The idea, Put it into action, The outcome), each with an icon, a thin colour outline
  and a staggered entrance.
- Listen button on every slide; narration reads card content only, card by card. Optional autoplay. Next appears
  when the narration ends.
- Recap after every four concepts with four even, square illustrations (2 x 2; four in a row on phones) and
  numbered summaries narrated one at a time; true/false checks after each recap, where a wrong answer returns the
  learner to that concept's page. Both can be switched off per activity.
- Culturally matched examples and illustrations based on the country of the narration language, with an
  international / neutral option.
- All AI through LMS Labs with the site's LMS Labs credentials (Central Config first): pages written by LMS Labs AI
  or imported from the teacher's own AI assistant (the same price per page), illustrations (Google Nano Banana) and
  voiceover clips (Google Chirp 3 HD, voices from the LMS Labs catalogue). No Google or OpenAI key needed.
- One-time LMS Labs activation (Check access, Unlock with the live price; verified Marketplace purchases are not
  charged again).
- Every paid action priced and confirmed first; requests stored with their Idempotency-Key and exact body before
  sending, never retried automatically, checked again with the same key and body; uncertain, lost, conflicting and
  failed requests listed with plain-language reasons; new paid generation fails closed.
- Media named after what made it: an edit needs only the changed clip or picture, and existing media keeps playing
  until its replacement arrives. Stable clip identities (site, component, activity, page, part).
- Linear or free navigation, contents drawer, resume; tracking of views, time on page, listens and true/false
  answers; listening report with drill-down and CSV export; completion rules (listen to every page, answer every
  check correctly); course Activities overview (Moodle 5.0+).
- Capabilities, Privacy API (including data sent to LMS Labs), backup and restore, course reset, events, PHPUnit and
  Behat tests. Supports Moodle 4.4 to 5.3.
