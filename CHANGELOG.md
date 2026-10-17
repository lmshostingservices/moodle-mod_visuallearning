# Changelog

All notable changes to mod_visuallearning are documented here.

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
