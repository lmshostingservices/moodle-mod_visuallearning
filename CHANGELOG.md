# Changelog

All notable changes to mod_visuallearning are documented here.

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
