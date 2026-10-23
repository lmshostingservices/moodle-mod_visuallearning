# Changelog

All notable changes to mod_visuallearning are documented here.

## [1.8.3] - 2026-10-23

- Fixed: labels in animated scenes ran past their cards, pills and legends ("Oxygen-poor blood" overflowing its
  legend). The player now fits every label in its card when the scene is shown: it widens the card where there is
  room (or moves a legend at the edge inwards), keeps 12-14px of padding, and only then makes the text a little
  smaller. The scene brief also gives exact widths for legends and asks for every card to be sized from its text.
- Page slides: the page number, title and Listen button are now one row across the top, so the three cards have the
  full height of the picture beside them. When cards would still run below the picture, their type and spacing
  tighten a little.
- Zoom: every page picture, animated scene, recap picture and question picture opens full screen with a click (or the
  magnifier button). Zoom in up to 400% with the buttons, the mouse wheel or a double click, and drag to move around.
  Esc closes it.
- Build the lesson: while LMS Labs works, step 3 now updates itself in place every 10 seconds (no more spinning until
  the page is refreshed by hand), and loads the finished page once everything has arrived.
- The Contents and sound buttons keep their dark text in themes that colour all buttons.
- Settings: with animation on, a note explains that the picture style is the style of the still pictures (cover,
  recap, question pictures), and the lesson summary shows "Still pictures: ...".

## [1.8.2] - 2026-10-23

- Fixed: lessons with "pick the picture" questions shown as text cards always failed to write. The writer was told
  to leave the option picture prompts empty, which LMS Labs' delivery check rejects. It now writes a short
  description, which is not used.
- Fixed: notes pasted from PowerPoint, Word or a PDF made LMS Labs refuse the whole lesson. Soft line breaks and page
  breaks are now sent as normal line breaks, and other invisible control characters are removed.
- The writer is told to keep each card to 230 characters, and to never use the characters < or >. LMS Labs rejects a
  whole lesson for one card over 250 characters or one of those characters.

## [1.8.1] - 2026-10-23

- **One click makes the whole lesson.** The teacher fills in a short form and presses "Create my lesson": Claude
  writes the pages, then the pictures, animated diagrams and narration are made automatically, within the price
  approved on one confirmation screen. A new lesson opens straight on this step.
- **Visual settings.** Who it is for, teaching style, picture style, animation and callouts are clickable cards. The
  picture-style cards show the same heart in each style (made with GPT Image), and the animated card beats.
- **"What to teach" stays short** (the topic and key points, with a grey example), and a separate box, **"Your notes,
  documents and instructions"**, takes up to 50,000 characters pasted from notes, a unit outline, a procedure, a PDF,
  Word document or PowerPoint. The writer uses it as a source of facts with its line breaks kept; short instructions
  also reach the drawings.
- **One price, shown twice.** The form shows the lesson's total live as the teacher chooses (pages, animation, card
  pictures), and the confirmation screen shows the same single total with a plain list of what will be made. No
  per-item prices anywhere; remakes also show one total. A built lesson's settings page shows no price (saving costs
  nothing).
- While Claude writes, step 1 shows a live "Claude is writing your lesson…" status and hides the button, so a lesson
  can never be bought twice. Once written, step 1 shows "Your pages are written" and keeps "Write the whole lesson
  again…" as a quiet secondary option.
- Removed the "Add questions to the question bank" button. The questions are still in the content download as Moodle
  XML, GIFT and CSV.
- Fixed: "New animated scene" in step 3 said nothing needed making instead of offering the remake.
- **The ChatGPT prompt file and paste-back are gone**, and so is pasting SVG from another AI: everything is made
  through LMS Labs (Claude for the pages and the animated diagrams; OpenAI GPT Image 2.5 Flare for every picture).
  Pages can still be added and edited by hand.
- **Five picture styles**, all made by **OpenAI GPT Image 2.5 Flare**: Illustration, Photo-realistic, 3D diagram,
  Technical model and Hand-drawn sketch. Side-by-side tests showed it far ahead of Nano Banana, above all for exact
  labels.
- **Callouts**: labels and a short info card on pictures, or no words at all.
- New picture prompts, tuned for GPT Image:
  - sharper art direction and a specific "avoid" list for each style;
  - one scene per picture;
  - science pictures show only the page's step (a pathway stops where the page says) with arrows pointing the real
    way, and only the colours that page needs;
  - the lesson's fixed layout ("same on every page") reaches every picture, so the pages match;
  - labels are exactly the quoted words and nothing else, and with callouts off the picture is completely text-free;
  - workplace pictures keep hands outside machines and show the right protective gear;
  - the page writer states where each pathway starts and stops, which way its arrows point and what is not shown;
  - each page picture names what the page teaches (its title), and card-picture edits list what must stay unchanged
    (camera, people's appearance, colours, structures).
- From the first real GPT Image 2.5 Flare test (about US$0.015 a picture, 11 to 14 seconds):
  - people in explanation pictures are fully clothed, with anatomy as a see-through overlay on the clothing (never a
    bare chest);
  - square answer-option pictures no longer get the page layout, and each colour of the colour code appears only
    where the picture shows that part;
  - card-picture edits keep every object, above all locks, tags and guards, exactly as before;
  - tighter rule wording, so less of each lesson's own content is cut while LMS Labs caps prompts at 2,000
    characters, and the style's name is never cut mid-word.
- Recap: the four pictures keep their 4:3 shape, so nothing is cropped (labels at the edges were cut off), and the
  pages not being read fade slightly instead of being blurred, so every picture stays sharp. The grid is also larger.
- Each delivered picture's request keeps a receipt: the model, its token use and cost when LMS Labs reports them, the
  size and the file type, so image models can be compared.
- Fixed: the default illustration look said "for adult learners" in every lesson, school lessons included. Lessons
  and sites holding the old wording get the new one on upgrade, and existing pictures are kept.
- Fixed: shortening a long picture prompt could drop the characters' descriptions and the whole look. The look and
  the start of each named character's description now always stay; picture prompts can be up to 6,000 characters
  once LMS Labs publishes `maxPromptChars`.
- Animated diagrams in a new house style: realistic shaded drawing, parts that change shape, label cards in the
  bundled "VL Sans" font (Plus Jakarta Sans, OFL), and a wider scene on the page. Scene plans v3 and a lesson-wide
  scene style with subject rule packs.
- Optional learning objectives, prior knowledge and unit code under More options.
- Prices (1 credit = US$0.10, every price at least twice its cost): animated diagram 6 credits a page; page 10
  credits including its first picture and narration; picture or voice remake 2 credits. LMS Labs must publish the
  same prices before they can be bought.
- Upgrading keeps every picture already made: stored pictures are renamed to their new prompt fingerprints.

## [1.7.2] - 2026-10-21

- Animated SVG lessons use the supplied three-stage scene-plan brief and exact house-style art direction.
- Import, validate, store and edit each page's scene plan; pass it to Claude after the three cards.
- Include scene plans in Moodle backups and restore older lessons without requiring a plan.
- Request scene plans from the LMS Labs page writer once its SVG draft capability is available.
- Clarify that AI-drawn scenes cost 2 credits per generation or deliberate remake; playback is free.
- Redesign the media workspace with larger previews, at most four cards across, clearer remake controls and a spacious price-confirmation screen.
- Preserve other picture modes, existing narration playback, the svg-1 system prompt and delivery retry protection.

## [1.7.1] - 2026-10-21

- Release-pipeline compliance: place the existing SVG sanitisation annotation beside the raw SVG input declaration.
- Correct anonymous-function spacing in the SVG sanitiser; animation behaviour and pricing are unchanged.
- Includes all animated-scene features and fixes documented in 1.7.0 below.

## [1.7.0] - 2026-10-21

- **New picture mode: Animated scene per page.** Claude draws one animated SVG scene for each page, through LMS
  Labs, with a stage for each card.
  - While a card is read aloud, its stage plays at a slow teaching pace (half speed by default; the lesson's
    animation pacing sets slow, normal or fast). Scenes that teach a real rhythm, such as CPR at 100 to 120 a
    minute, mark themselves to always play at real speed.
  - Once the last card has been read, a "Play animation at normal speed" button appears under the scene and plays
    the whole animation at full speed. Tapping a card shows its stage at normal speed.
  - Scenes are drawn for the lesson's learner level, language, teaching approach and art direction.
  - Price: 2 credits per scene, quoted and confirmed first, like every other paid request. Pages keep their picture
    for the cover, recap and questions, and show it until their scene is ready.
  - Teachers can also make a scene with their own AI assistant: download the prompt from the page's edit form and
    paste the SVG back (free).
- **Safety:** every scene is cleaned before it is saved: no scripts, event handlers, links, images, foreign content
  or external references. Its ids, styles and keyframes are scoped, so it cannot change the rest of the Moodle page.
- **Fixes to 1.6.0:**
  - New lessons defaulted to the "Automatic" animation engine. That turned picture lessons into animated ones and
    blocked AI page writing ("The AI server has not enabled the universal animation engine yet") until LMS Labs
    shipped the engine. The engine settings now apply only to animated-diagram lessons. New lessons default to
    "Keep existing visual mode", and the upgrade moves lessons created under 1.6.0 back to their own picture mode.
  - While the scene engine is not live on LMS Labs, animated-diagram lessons ask for the classic animated
    diagrams instead of being blocked.
  - The player asked for two missing strings (player_animationreplay, player_animationstagereplay).
- Backup, restore, page deletion and full re-imports include the scenes.

## [1.6.0] - 2026-10-20

- Add a versioned universal animated-scene engine, strict scene validation, declarative
  vector composition, deterministic narration stages and all 21 animation actions.
- Add automatic/static/animated preferences, complexity and pacing, preserving existing lessons.
- Preserve legacy diagrams and image fallback; add reduced-motion descriptions and replay controls.
- Extend generated and downloadable lesson briefs without changing acquisition or usage pricing.

## [1.5.7] - 2026-10-20

- Includes Replit's 1.5.5 and 1.5.6 billing changes (below).
- **Learner level** (new setting, with a site default): Primary school (about 5 to 11), Secondary school (about
  12 to 17), Adult and vocational learners (as before), and University and professional.
  - It sets how the cards, recaps and questions are written.
  - It sets how every picture is drawn: page, cover, option and card pictures, and card edits. Young learners get
    very simple, bright pictures with at most three main parts. University and professional learners get detailed,
    textbook-quality figures.
  - It sets how detailed animated diagrams are.
  - Adult lessons send exactly the same picture prompts as before.
  - LMS Labs AI gets the level as a field once it lists it, and as text until then.
- **Pages come in 4, 8 or 12** ("Number of pages"), so every recap shows four even, square pictures.
  - The AI is asked for exactly that many pages.
  - Existing settings move to the nearest count; existing pages are kept.
  - A short group left at the end by pages added by hand is recapped and checked on its own, with square pictures.
    It is no longer merged into a squashed five-picture recap.
- **Animated diagrams are now a Visual style choice**: "Animated diagrams (drawn live with the narration, no
  credits)". Picture mode (one picture per page or per card) is hidden when it is chosen.
- Activity settings: Teaching approach, Visual style, Picture mode and the art direction have their own section,
  "Teaching approach and pictures" (open by default). The voice section becomes "Voice and playback".
- The build page shows the picture mode and the learner level.

## [1.5.6] - 2026-10-20

- Align the packaged pricing table with the approved 2-credit charge for every deliberate
  picture or voice remake, including legacy clips. No change to the 10-credit inclusive slide price.

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
