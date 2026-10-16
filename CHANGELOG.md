# Changelog

All notable changes to mod_visuallearning are documented here.

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
