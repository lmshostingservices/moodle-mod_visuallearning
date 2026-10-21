// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * AI Visual Learning player.
 *
 * The lesson is a sequence of steps: concept pages, then (after every four concepts) a narrated
 * recap slide and one check question per page (true or false, pick the picture, match them up or
 * sort into groups). Each slide is narrated clip by clip when the learner presses Listen (or
 * automatically, if the teacher enabled autoplay).
 *
 * A wrong answer never jumps anywhere by itself: the learner is told where the answer is and presses
 * "Go back and listen". Next stays locked until they have listened to that page again, then they are
 * brought straight back to the same question.
 *
 * @module     mod_visuallearning/player
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import * as Sounds from 'mod_visuallearning/sounds';
import * as Diagrams from 'mod_visuallearning/diagrams';
import * as Scenes from 'mod_visuallearning/scene';
import * as SvgScenes from 'mod_visuallearning/svgscene';

const HEARTBEAT_MS = 15000;
const WORDS_PER_SECOND = 2.6;
const NAV_FREE = 1;

const TICK_SVG = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5" fill="none" ' +
    'stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
const CROSS_SVG = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6.5 6.5l11 11M17.5 6.5l-11 11" fill="none" ' +
    'stroke="currentColor" stroke-width="2.6" stroke-linecap="round"/></svg>';
const HEADPHONES_SVG = '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" ' +
    'stroke-linecap="round" stroke-linejoin="round"><path d="M3 18v-6a9 9 0 0 1 18 0v6"/>' +
    '<path d="M21 19a2 2 0 0 1-2 2h-1v-6h3zM3 19a2 2 0 0 0 2 2h1v-6H3z"/></svg>';
const BACK_SVG = '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.4" ' +
    'stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M11 6l-6 6 6 6"/></svg>';
const CLOCK_SVG = '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" ' +
    'stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></svg>';
const REPLAY_SVG = '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" ' +
    'stroke-linecap="round" stroke-linejoin="round"><path d="M4 12a8 8 0 1 0 2.4-5.7"/><path d="M4 4v4.5h4.5"/></svg>';
const QUESTION_SVG = '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" ' +
    'stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="4" width="16" height="16" rx="4"/>' +
    '<path d="M8.5 12.2l2.4 2.4 4.6-4.8"/></svg>';
const TROPHY_SVG = '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" ' +
    'stroke-linecap="round" stroke-linejoin="round"><path d="M8 4h8v5a4 4 0 0 1-8 0V4z"/><path d="M8 6H5a3 3 0 0 0 3 4"/>' +
    '<path d="M16 6h3a3 3 0 0 1-3 4"/><path d="M12 13v4M8.5 20h7"/></svg>';
const LOCK_SVG = '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.2" ' +
    'stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="11" width="14" height="10" rx="2"/>' +
    '<path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>';
const TYPE_ICONS = {
    truefalse: '<path d="M4 12l4 4 8-9"/><path d="M15 15l5 5M20 15l-5 5"/>',
    pick: '<rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/>' +
        '<rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/>',
    match: '<circle cx="5" cy="6" r="2.5"/><circle cx="19" cy="18" r="2.5"/><path d="M7.5 6h3a3 3 0 0 1 3 3v6a3 3 0 0 0 3 3"/>',
    sort: '<rect x="3" y="4" width="8" height="16" rx="2"/><rect x="13" y="4" width="8" height="16" rx="2"/>',
};
const PAIR_COLOURS = 4;

/**
 * Escape text for HTML.
 *
 * @param {string} text
 * @returns {string}
 */
const esc = (text) => String(text === undefined || text === null ? '' : text).replace(/[&<>"']/g,
    (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));

const CARD_ICONS = {
    concept: '<path d="M9 18h6M10 21h4M12 3a6 6 0 0 0-3.5 10.9c.6.4 1 1.1 1 1.8V16h5v-.3c0-.7.4-1.4 1-1.8A6 6 0 0 0 12 3z"/>',
    action: '<path d="M13 3L5 14h6l-1 7 8-11h-6z"/>',
    outcome: '<path d="M12 3l2.6 5.6 6 .7-4.5 4.1 1.2 6L12 16.4 6.7 19.4l1.2-6L3.4 9.3l6-.7z"/>',
};

/**
 * Line icon for a card type.
 *
 * @param {string} key concept|action|outcome
 * @returns {string} SVG markup
 */
const cardIcon = (key) => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" ' +
    'stroke-linecap="round" stroke-linejoin="round">' + (CARD_ICONS[key] || '') + '</svg>';

/**
 * Numbered icon for a card that is a step (key point 1, 2, 3): the same coloured tile, with its number.
 *
 * @param {string} key concept|action|outcome
 * @returns {string} markup
 */
const cardNumber = (key) => '<span class="pb-card-num">' + (['concept', 'action', 'outcome'].indexOf(key) + 1) + '</span>';

/**
 * Height of Moodle's fixed chrome (top navbar, sticky footer) that covers the page.
 *
 * @returns {{top: number, bottom: number}}
 */
const chrome = () => {
    const nav = document.querySelector('nav.fixed-top, .navbar.fixed-top');
    const footer = document.getElementById('sticky-footer');
    let bottom = 0;
    if (footer && footer.offsetHeight && getComputedStyle(footer).display !== 'none'
            && getComputedStyle(footer).visibility !== 'hidden') {
        // Only the part of the footer actually covering the viewport counts.
        const rect = footer.getBoundingClientRect();
        bottom = Math.max(0, Math.min(footer.offsetHeight, window.innerHeight - rect.top));
    }
    return {top: nav ? nav.offsetHeight : 0, bottom};
};

/**
 * Wait helper.
 *
 * @param {number} ms
 * @returns {Promise}
 */
const wait = (ms) => new Promise((resolve) => window.setTimeout(resolve, ms));

/**
 * Words in an HTML snippet.
 *
 * @param {string} html
 * @returns {number}
 */
const wordCount = (html) => (html || '').replace(/<[^>]+>/g, ' ').split(/\s+/).filter(Boolean).length;

class Player {
    /**
     * Constructor.
     *
     * @param {HTMLElement} root
     */
    constructor(root) {
        this.root = root;
        this.data = JSON.parse(root.querySelector('.pb-data').textContent);
        this.pages = this.data.pages;
        // The animated diagram on the page shown (if any), and the timer that tidies the picture layers.
        this.diagram = null;
        this.layerTimer = 0;
        this.steps = this.buildSteps();
        this.current = -1; // Cover is -1, the end screen is steps.length.
        this.narrating = false;
        this.paused = false;
        this.slideDone = false;
        this.token = 0;
        this.heartbeat = null;
        this.fallbackTimer = null;
        this.returnTo = null; // Question step to return to after listening to its page again.
        this.revisitHeard = false; // The page was heard in full during this revisit.
        this.welcomeBack = false; // Just came back from listening again.
        this.wrong = {}; // Page id => choices already tried and wrong (crossed out on the next try, also after a reload).
        try {
            this.wrong = JSON.parse(window.sessionStorage.getItem('mod_visuallearning_tried_' + this.data.cmid) || '{}') || {};
        } catch (e) {
            this.wrong = {};
        }
        this.response = null; // The learner's answer being built on the current question.
        this.answered = false; // The current question has just been answered right.
        this.recapHeard = new Set();
        Sounds.setEnabled(this.data.sounds);
        this.audio = root.querySelector('.pb-audio');
        this.clipResolve = null;
        this.build();
    }

    /**
     * The pages in recap groups (lists of page indexes).
     *
     * @returns {Array<Array<number>>}
     */
    pageGroups() {
        const size = this.data.groupsize || 4;
        const total = this.pages.length;
        // Groups of four (lessons are written with 4, 8 or 12 pages). A smaller group left at the end by pages added
        // by hand is recapped and checked on its own. Must match visuallearning_recap_number() in lib.php.
        const ends = [];
        for (let end = size; end < total; end += size) {
            ends.push(end);
        }
        ends.push(total);
        const groups = [];
        let start = 0;
        for (const end of ends) {
            const group = [];
            for (let i = start; i < end; i++) {
                group.push(i);
            }
            if (group.length) {
                groups.push(group);
            }
            start = end;
        }
        return groups;
    }

    /**
     * Turn pages into steps: page, page, ... then recap and quiz after every group.
     *
     * @returns {Array<Object>}
     */
    buildSteps() {
        const steps = [];
        let check = 0;
        for (const group of this.pageGroups()) {
            for (const i of group) {
                steps.push({type: 'page', page: i});
            }
            // Every group is recapped and checked, so every page is.
            if (group.length) {
                const range = {from: group[0] + 1, to: group[group.length - 1] + 1};
                if (this.data.recap) {
                    steps.push({type: 'recap', group, ...range});
                }
                const questions = group.filter((i) => this.pages[i].question);
                if (this.data.quiz && questions.length) {
                    check++;
                    for (let n = 0; n < questions.length; n++) {
                        steps.push({type: 'question', page: questions[n], n: n + 1, total: questions.length, check, ...range});
                    }
                }
            }
        }
        return steps;
    }

    /**
     * Query inside the player.
     *
     * @param {string} selector
     * @returns {HTMLElement}
     */
    q(selector) {
        return this.root.querySelector(selector);
    }

    /**
     * Get a language string supplied by the server, substituting {$a} placeholders.
     *
     * @param {string} key
     * @param {string|number|object} [a]
     * @returns {string}
     */
    str(key, a) {
        let text = this.data.strings[key] || key;
        if (a !== undefined && a !== null && typeof a === 'object') {
            Object.keys(a).forEach((k) => {
                text = text.split('{$a->' + k + '}').join(a[k]);
            });
        } else if (a !== undefined) {
            text = text.split('{$a}').join(a);
        }
        return text;
    }

    /**
     * Build the static parts of the player and wire up events.
     */
    build() {
        this.q('.pb-booktitle').textContent = this.data.title;
        this.q('.pb-toc-toggle-label').textContent = this.str('contents');
        this.q('.pb-toc-title').textContent = this.str('contents');
        this.q('.pb-toc-close').setAttribute('aria-label', this.str('close'));
        this.q('.pb-prev-label').textContent = this.str('previous');
        this.q('.pb-replay').setAttribute('aria-label', this.str('replay'));
        this.q('.pb-replay').title = this.str('replay');
        const soundBtn = this.q('.pb-sound-toggle');
        if (this.data.sounds) {
            this.updateSoundToggle();
            soundBtn.addEventListener('click', () => {
                Sounds.setMuted(!Sounds.isMuted());
                this.updateSoundToggle();
                Sounds.play('pop');
            });
        } else {
            soundBtn.hidden = true;
        }
        // A barely-there tick when the pointer or focus reaches an answer.
        const stage = this.q('.pb-stage');
        stage.addEventListener('pointerover', (e) => {
            const target = e.target.closest('[data-tick]');
            if (target && !target.contains(e.relatedTarget) && !target.disabled && e.pointerType === 'mouse') {
                Sounds.play('tick');
            }
        });
        stage.addEventListener('focusin', (e) => {
            if (e.target.closest('[data-tick]') && e.target.matches(':focus-visible')) {
                Sounds.play('tick');
            }
        });

        // Cover.
        const coverSrc = this.data.cover || this.firstImage();
        const coverImg = this.q('.pb-cover-image');
        if (coverSrc) {
            coverImg.src = coverSrc;
            coverImg.alt = this.data.coveralt;
        } else {
            coverImg.hidden = true;
            this.q('.pb-cover-figure').classList.add('is-empty');
        }
        this.q('.pb-cover-title').innerHTML = this.data.title;
        this.renderCoverInfo();
        this.q('.pb-cover-text').addEventListener('scroll', () => this.updateCoverScroll(), {passive: true});
        const chips = this.q('.pb-chips');
        this.data.chips.forEach((chip) => {
            const span = document.createElement('span');
            span.className = 'pb-chip';
            span.innerHTML = chip;
            chips.appendChild(span);
        });

        // Contents drawer.
        this.q('.pb-toc-toggle').addEventListener('click', () => this.toggleToc(true));
        this.q('.pb-toc-close').addEventListener('click', () => this.toggleToc(false));
        this.q('.pb-toc-backdrop').addEventListener('click', () => this.toggleToc(false));

        // Controls.
        this.q('.pb-prev').addEventListener('click', () => {
            this.returnTo = null;
            this.go(this.current - 1);
        });
        this.q('.pb-next').addEventListener('click', () => this.next());
        this.q('.pb-playpause').addEventListener('click', () => this.togglePlay());
        this.q('.pb-replay').addEventListener('click', () => this.narrate());
        this.root.querySelectorAll('.pb-listen').forEach((btn) => {
            btn.addEventListener('click', () => this.togglePlay());
        });

        this.audio.addEventListener('ended', () => this.finishClip());
        this.audio.addEventListener('error', () => {
            if (this.audio.getAttribute('src')) {
                this.finishClip();
            }
        });

        document.addEventListener('keydown', (e) => this.onKey(e));
        window.addEventListener('resize', () => this.fitToViewport());
        // Moodle's sticky footer slides away on scroll on phones: keep our bar sitting just above it.
        let ticking = false;
        window.addEventListener('scroll', () => {
            if (!ticking) {
                ticking = true;
                window.requestAnimationFrame(() => {
                    ticking = false;
                    this.root.style.setProperty('--pb-footer', chrome().bottom + 'px');
                });
            }
        }, {passive: true});
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') {
                this.track('resume');
            }
        });
        window.addEventListener('pagehide', () => this.track('ping'));

        this.updateProgress();
        this.showCover();
    }

    /**
     * Keyboard shortcuts: arrows move between slides, space/k plays or pauses, Escape closes the contents.
     *
     * @param {KeyboardEvent} e
     */
    onKey(e) {
        if (!this.root.isConnected || e.target.closest('input, textarea, select, [contenteditable]')) {
            return;
        }
        if (e.key === 'ArrowRight' && this.canAdvance()) {
            this.next();
        } else if (e.key === 'ArrowLeft' && this.current > 0) {
            this.returnTo = null;
            this.go(this.current - 1);
        } else if ((e.key === ' ' || e.key === 'k') && this.isSlide() && !e.target.closest('button, a')) {
            e.preventDefault();
            this.togglePlay();
        } else if (e.key === 'Escape') {
            this.toggleToc(false);
        } else if (this.step() && this.step().type === 'question' && !e.ctrlKey && !e.metaKey && !e.altKey) {
            // Letters choose answers: A to D on a picture pick, T or F on true or false.
            const type = this.pages[this.step().page].question.type;
            const key = e.key.toLowerCase();
            let target = null;
            if (type === 'pick' && 'abcd'.includes(key) && key.length === 1) {
                target = this.q('.pb-pick[data-choice="' + 'abcd'.indexOf(key) + '"]');
            } else if (type === 'truefalse' && (key === 't' || key === 'f')) {
                target = this.q('.pb-tf--' + (key === 't' ? 'true' : 'false'));
            }
            if (target && !target.disabled) {
                e.preventDefault();
                target.click();
            }
        }
    }

    /**
     * The current step, or null on the cover / end screen.
     *
     * @returns {Object|null}
     */
    step() {
        return this.steps[this.current] || null;
    }

    /**
     * Are we on a slide (not the cover or the end screen)?
     *
     * @returns {boolean}
     */
    isSlide() {
        return this.current >= 0 && this.current < this.steps.length;
    }

    /**
     * First available illustration, used as the cover when no cover was generated.
     *
     * @returns {string}
     */
    firstImage() {
        const page = this.pages.find((p) => p.image);
        return page ? page.image : '';
    }

    /**
     * Is a step complete (for unlocking and resuming)?
     *
     * @param {Object} step
     * @returns {boolean}
     */
    isComplete(step) {
        if (step.type === 'page') {
            return this.pages[step.page].listened;
        }
        if (step.type === 'question') {
            return this.pages[step.page].passed;
        }
        return true;
    }

    /**
     * Index of the first incomplete step, or -1.
     *
     * @returns {number}
     */
    firstIncomplete() {
        return this.steps.findIndex((s) => !this.isComplete(s));
    }

    /**
     * Can the learner open this step?
     *
     * @param {number} index
     * @returns {boolean}
     */
    isUnlocked(index) {
        if (this.data.navmode === NAV_FREE) {
            return true;
        }
        const first = this.firstIncomplete();
        return first === -1 || index <= first;
    }

    /**
     * Can the learner move past the current step?
     *
     * @returns {boolean}
     */
    canAdvance() {
        const step = this.step();
        if (!step) {
            return false;
        }
        if (step.type === 'question' && this.pages[step.page].relisten && !this.pages[step.page].passed) {
            // Locked after a wrong answer until the page has been listened to again, whatever the navigation mode.
            return false;
        }
        if (this.returnTo !== null && step.type === 'page') {
            // Listening again: back to the question only once the page has been heard in full.
            return this.revisitHeard;
        }
        if (this.data.navmode === NAV_FREE) {
            return true;
        }
        if (step.type === 'recap') {
            return this.slideDone || this.recapHeard.has(this.current);
        }
        return this.isComplete(step);
    }

    /**
     * Next button: normally the next step; after listening to a page again, back to its question.
     */
    next() {
        if (!this.canAdvance()) {
            return;
        }
        const step = this.step();
        if (this.returnTo !== null && step && step.type === 'page') {
            this.backToQuestion();
            return;
        }
        Sounds.play('swoosh');
        this.go(this.current + 1);
    }

    /**
     * Leave the page being listened to again and return to its question.
     */
    backToQuestion() {
        if (this.returnTo === null || !this.revisitHeard) {
            return;
        }
        const target = this.returnTo;
        this.returnTo = null;
        this.welcomeBack = true;
        Sounds.play('swoosh');
        this.go(target);
    }

    /**
     * Show the mute state on the sound button.
     */
    updateSoundToggle() {
        const btn = this.q('.pb-sound-toggle');
        const muted = Sounds.isMuted();
        btn.classList.toggle('is-muted', muted);
        btn.setAttribute('aria-pressed', muted ? 'true' : 'false');
        const label = muted ? this.str('soundoff') : this.str('soundon');
        btn.setAttribute('aria-label', label);
        btn.title = label;
    }

    /**
     * Keep the whole slide (image, cards and buttons) above the fold, whatever the theme chrome.
     */
    fitToViewport() {
        // Stack image above text when the lesson itself is narrow (phones, or Moodle's drawers open on a tablet).
        this.root.classList.toggle('is-narrow', this.root.clientWidth < 760);
        this.root.classList.toggle('is-xs', this.root.clientWidth < 400);
        const c = chrome();
        const stage = this.q('.pb-stage');
        const cs = getComputedStyle(stage);
        const stagePad = parseFloat(cs.paddingTop) + parseFloat(cs.paddingBottom) + 2;
        const topbar = this.q('.pb-topbar');
        const topbarH = topbar.offsetHeight + parseFloat(getComputedStyle(topbar).marginBottom || 0);
        const controls = this.q('.pb-controls');
        const controlsH = controls.hidden ? 64 : controls.offsetHeight + parseFloat(getComputedStyle(controls).marginTop || 0);
        let avail = window.innerHeight - c.top - c.bottom - topbarH - controlsH - stagePad - 20;
        avail = Math.max(300, Math.min(avail, 860));
        this.root.style.setProperty('--pb-avail', avail + 'px');
        // On phones the controls stick to the bottom of the screen, above Moodle's own sticky footer.
        this.root.style.setProperty('--pb-footer', c.bottom + 'px');
        this.root.classList.toggle('is-short', avail < 600);
        this.root.classList.toggle('is-tiny', avail < 480);
        this.updateCoverScroll();
    }

    /**
     * Scroll so the lesson sits directly under the navbar.
     */
    scrollIntoPlace() {
        const c = chrome();
        const top = this.root.getBoundingClientRect().top + window.scrollY - c.top - 8;
        if (Math.abs(window.scrollY - top) > 4) {
            window.scrollTo({top: Math.max(0, top), behavior: 'smooth'});
        }
    }

    /**
     * Build a button.
     *
     * @param {string} label
     * @param {string} className
     * @param {Function} onClick
     * @returns {HTMLButtonElement}
     */
    button(label, className, onClick) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = className;
        btn.textContent = label;
        btn.addEventListener('click', onClick);
        return btn;
    }

    /**
     * The fixed parts of the cover: quick facts, what the lesson is about, and how it works.
     */
    renderCoverInfo() {
        const pages = this.pages.length;
        const asked = this.data.quiz ? this.pages.filter((p) => p.question) : [];
        const groups = this.pageGroups();
        const reviewed = groups.filter((g) => g.length >= 2);
        const recaps = this.data.recap ? reviewed.length : 0;
        const checks = asked.length && reviewed.length;

        // Quick facts: pages, an honest reading time, questions.
        const words = this.pages.reduce((sum, p) => sum + p.cards.reduce((w, c) => w + wordCount(c.text), 0) +
            (this.data.recap ? wordCount(p.summary) : 0) + (p.question ? wordCount(p.question.prompt) : 0), 0);
        const minutes = Math.max(1, Math.round(words / WORDS_PER_SECOND / 60 + pages * 0.25 + asked.length * 0.4));
        const facts = [[HEADPHONES_SVG, this.str(pages === 1 ? 'factpage' : 'factpages', pages)],
            [CLOCK_SVG, this.str('factminutes', minutes)]];
        if (checks) {
            facts.push([QUESTION_SVG, this.str(asked.length === 1 ? 'factquestion' : 'factquestions', asked.length)]);
        }
        this.q('.pb-cover-facts').innerHTML = facts.map(([icon, text]) =>
            '<span class="pb-fact">' + icon + '<span>' + esc(text) + '</span></span>').join('');

        // What the lesson is about: the teacher's description, or a plain summary of what follows.
        this.q('.pb-cover-aboutheading').textContent = this.str('coverabout');
        const intro = this.q('.pb-cover-intro');
        if (this.data.intro) {
            intro.innerHTML = this.data.intro;
        } else {
            const title = this.data.title.replace(/<[^>]+>/g, '').trim();
            intro.innerHTML = '<p>' + esc(this.str(pages === 1 ? 'coveraboutautoone' : 'coveraboutauto', {title, pages})) +
                '</p>';
        }
        this.q('.pb-cover-learnheading').textContent = this.str('coverlearn');

        // How it works: listen, recap, check (only the parts this lesson has).
        const how = [];
        how.push(['is-listen', HEADPHONES_SVG, this.str('steplisten'),
            this.str(this.data.navmode === NAV_FREE ? 'steplistenfree' : 'steplistenlinear')]);
        const once = reviewed.length === 1;
        if (recaps) {
            how.push(['is-recap', REPLAY_SVG, this.str('steprecap'), this.str(once ? 'steprecaponce' : 'steprecaptext')]);
        }
        if (checks) {
            how.push(['is-check', QUESTION_SVG, this.str('stepcheck'), this.str(once ? 'stepcheckonce' : 'stepchecktext')]);
        }
        let html = '<h3 class="pb-cover-heading">' + esc(this.str('coverhow')) + '</h3><ol class="pb-how">';
        how.forEach(([cls, icon, title, text], n) => {
            html += '<li class="pb-how-step ' + cls + '"><span class="pb-how-icon">' + icon +
                (how.length > 1 ? '<span class="pb-how-num">' + (n + 1) + '</span>' : '') + '</span><span class="pb-how-body"><strong>' + esc(title) +
                '</strong><span>' + esc(text) + '</span></span></li>';
        });
        html += '</ol>';
        const pass = this.passRequirement(asked.length);
        if (checks && pass) {
            html += '<p class="pb-how-pass">' + TROPHY_SVG + '<span>' + esc(pass) + '</span></p>';
        }
        this.q('.pb-cover-how').innerHTML = html;
    }

    /**
     * What a learner needs to pass, in words, from the teacher's grade to pass (or null when there is none).
     *
     * @param {number} total Check questions in the lesson
     * @returns {string|null}
     */
    passRequirement(total) {
        const g = this.data.grade;
        if (!g || !(g.pass > 0) || !total) {
            return null;
        }
        let fraction;
        let label;
        if (g.scale.length) {
            const item = Math.min(g.scale.length, Math.ceil(g.pass));
            fraction = g.scale.length > 1 ? (item - 1) / (g.scale.length - 1) : 1;
            label = g.scale[item - 1];
        } else {
            fraction = Math.min(1, g.pass / g.max);
            label = (Math.round(fraction * 1000) / 10) + '%';
        }
        const need = Math.min(total, Math.max(1, Math.ceil(fraction * total - 1e-9)));
        return need >= total ? this.str('coverpassall', label)
            : this.str('coverpasssome', {need, total, pass: label});
    }

    /**
     * What the learner will learn: every page by number, in its parts, with each part's recap and check.
     * Pages already listened to are ticked; pages the learner may open are buttons.
     */
    renderCoverOutline() {
        const wrap = this.q('.pb-cover-parts');
        wrap.innerHTML = '';
        const reviews = this.data.recap || (this.data.quiz && this.pages.some((p) => p.question));
        const groups = reviews ? this.pageGroups() : [this.pages.map((p, i) => i)];
        wrap.classList.toggle('is-single', groups.length === 1);
        groups.forEach((group, g) => {
            const part = document.createElement('section');
            part.className = 'pb-part';
            const from = group[0] + 1;
            const to = group[group.length - 1] + 1;
            const done = group.every((i) => this.pages[i].listened);
            if (groups.length > 1) {
                part.insertAdjacentHTML('beforeend', '<div class="pb-part-head"><span class="pb-part-name">' +
                    esc(this.str('coverpart', g + 1)) + '</span><span class="pb-part-range">' +
                    esc(from === to ? this.str('coverpage', from) : this.str('coverpages', {from, to})) + '</span>' +
                    (done ? '<span class="pb-part-done">' + TICK_SVG + '</span>' : '') + '</div>');
            }
            const list = document.createElement('ol');
            list.className = 'pb-part-list';
            list.start = from;
            group.forEach((i) => {
                const page = this.pages[i];
                const li = document.createElement('li');
                const index = this.steps.findIndex((s) => s.type === 'page' && s.page === i);
                const open = index >= 0 && this.isUnlocked(index);
                const inner = '<span class="pb-part-num">' + (page.listened ? TICK_SVG : String(i + 1)) + '</span>' +
                    '<span class="pb-part-title">' + page.title + '</span>';
                if (open) {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'pb-part-item' + (page.listened ? ' is-done' : '');
                    btn.innerHTML = inner;
                    btn.setAttribute('aria-label', this.str('coverpage', i + 1) + ': ' + page.title.replace(/<[^>]+>/g, '') +
                        (page.listened ? ' (' + this.str('listenedstatus') + ')' : ''));
                    btn.addEventListener('click', () => this.go(index));
                    li.appendChild(btn);
                } else {
                    li.innerHTML = '<span class="pb-part-item is-locked">' + inner + '</span>';
                }
                list.appendChild(li);
            });
            part.appendChild(list);
            // The review that follows this part.
            if (group.length >= 2) {
                const asked = this.data.quiz ? group.filter((i) => this.pages[i].question).length : 0;
                let review = '';
                if (this.data.recap) {
                    review += '<span class="pb-part-tag is-recap">' + REPLAY_SVG + esc(this.str('coverrecap')) + '</span>';
                }
                if (asked) {
                    review += '<span class="pb-part-tag is-check">' + QUESTION_SVG +
                        esc(this.str(asked === 1 ? 'coverquestion' : 'coverquestions', asked)) + '</span>';
                }
                if (review) {
                    part.insertAdjacentHTML('beforeend', '<div class="pb-part-review"><span class="pb-part-then">' +
                        esc(this.str('coverthen')) + '</span>' + review + '</div>');
                }
            }
            wrap.appendChild(part);
        });
        this.updateCoverScroll();
    }

    /**
     * Fade the bottom of the cover's text column while more of the outline is below.
     */
    updateCoverScroll() {
        const col = this.q('.pb-cover-text');
        if (!col) {
            return;
        }
        const more = col.scrollHeight - col.scrollTop - col.clientHeight > 6;
        col.classList.toggle('has-more', more);
    }

    /**
     * Start / continue buttons on the cover.
     */
    renderCoverActions() {
        const wrap = this.q('.pb-cover-actions');
        wrap.innerHTML = '';
        const first = this.firstIncomplete();
        const anyListened = this.pages.some((p) => p.listened);
        if (anyListened && first > 0) {
            const step = this.steps[first];
            const label = step.type === 'page' ? this.str('continuefrom', step.page + 1) : this.str('continue');
            wrap.appendChild(this.button(label, 'pb-btn pb-btn-primary pb-btn-large', () => this.go(first)));
            wrap.appendChild(this.button(this.str('startover'), 'pb-btn pb-btn-ghost', () => this.go(0)));
        } else {
            wrap.appendChild(this.button(this.str('start'), 'pb-btn pb-btn-primary pb-btn-large', () => this.go(0)));
        }
    }

    /**
     * Title of a step for the contents list.
     *
     * @param {Object} step
     * @returns {string}
     */
    stepTitle(step) {
        if (step.type === 'page') {
            return this.pages[step.page].title;
        }
        if (step.type === 'question') {
            return this.str('checkx', step.check) + ' · ' + this.str('questionof', {n: step.n, total: step.total});
        }
        return step.from === step.to ? this.str('recaptocone', step.from) : this.str('recaptoc', {from: step.from, to: step.to});
    }

    /**
     * Contents list with listened / locked states.
     */
    renderToc() {
        const list = this.q('.pb-toc-list');
        list.innerHTML = '';
        this.steps.forEach((step, i) => {
            const li = document.createElement('li');
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'pb-toc-item pb-toc-item--' + step.type;
            const unlocked = this.isUnlocked(i);
            const complete = step.type !== 'recap' && this.isComplete(step);
            if (i === this.current) {
                btn.classList.add('is-current');
                btn.setAttribute('aria-current', 'step');
            }
            if (complete) {
                btn.classList.add('is-listened');
            }
            if (!unlocked) {
                btn.classList.add('is-locked');
                btn.disabled = true;
            }
            let status = '';
            if (complete) {
                status = this.str('listenedstatus');
            } else if (!unlocked) {
                status = this.str('locked');
            } else if (i === this.current) {
                status = this.str('nowreading');
            } else if (step.type === 'page') {
                status = this.str('notlistened');
            }
            let icon = '';
            if (complete) {
                icon = '&#10003;';
            } else if (!unlocked) {
                icon = '&#128274;';
            }
            let num = '?';
            if (step.type === 'page') {
                num = String(step.page + 1);
            } else if (step.type === 'recap') {
                num = '&#8635;';
            }
            btn.innerHTML = '<span class="pb-toc-num">' + num + '</span>' +
                '<span class="pb-toc-text"><span class="pb-toc-name">' + this.stepTitle(step) + '</span>' +
                (status ? '<span class="pb-toc-status">' + status + '</span>' : '') + '</span>' +
                '<span class="pb-toc-icon" aria-hidden="true">' + icon + '</span>';
            btn.addEventListener('click', () => {
                this.toggleToc(false);
                this.returnTo = null;
                this.welcomeBack = false;
                this.go(i);
            });
            li.appendChild(btn);
            list.appendChild(li);
        });
    }

    /**
     * Open or close the contents drawer.
     *
     * @param {boolean} open
     */
    toggleToc(open) {
        const toc = this.q('.pb-toc');
        if (open) {
            this.renderToc();
        }
        toc.classList.toggle('is-open', open);
        toc.setAttribute('aria-hidden', open ? 'false' : 'true');
        this.q('.pb-toc-backdrop').hidden = !open;
        this.q('.pb-toc-toggle').setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) {
            toc.focus();
        }
    }

    /**
     * Update the progress bar.
     */
    updateProgress() {
        const listened = this.pages.filter((p) => p.listened).length;
        const total = this.pages.length;
        const pct = total ? Math.round(listened / total * 100) : 0;
        this.q('.pb-progress-fill').style.width = pct + '%';
        this.q('.pb-progress').setAttribute('aria-valuenow', pct);
        this.q('.pb-progress-label').textContent = this.str('listenedof', {done: listened, total});
    }

    /**
     * Hide every slide section.
     */
    hideSections() {
        ['.pb-cover', '.pb-spread', '.pb-recap', '.pb-question', '.pb-end'].forEach((sel) => {
            this.q(sel).hidden = true;
        });
    }

    /**
     * The learner's grade as the gradebook works it out: the share of check questions answered correctly in the end
     * (all right = full marks), or of pages listened to when the lesson has no check questions.
     *
     * @param {Array<Object>} asked Pages with a check question
     * @returns {Object|null} {percent, label, passlabel, pass, passed, left} or null when the lesson is not graded
     */
    gradeResult(asked) {
        const g = this.data.grade;
        if (!g) {
            return null;
        }
        const graded = this.data.quiz ? asked : [];
        const total = graded.length || this.pages.length;
        const done = graded.length ? graded.filter((p) => p.passed).length : this.pages.filter((p) => p.listened).length;
        const fraction = total ? done / total : 0;
        const pct = (v) => (Math.round(v * 10) / 10) + '%';
        let value;
        let label;
        let passlabel = '';
        if (g.scale.length) {
            value = 1 + Math.round(fraction * (g.scale.length - 1));
            label = g.scale[value - 1];
            passlabel = g.pass > 0 ? g.scale[Math.min(g.scale.length, Math.ceil(g.pass)) - 1] : '';
        } else {
            value = fraction * g.max;
            label = pct(fraction * 100);
            passlabel = g.pass > 0 ? pct(g.pass / g.max * 100) : '';
        }
        return {
            label,
            passlabel,
            pass: g.pass > 0,
            passed: g.pass > 0 && value + 1e-6 >= g.pass,
            left: total - done,
            byquestions: graded.length > 0,
        };
    }

    /**
     * The grade banner on the finish screen: passed (with the passing grade the teacher set), or what is left to do.
     *
     * @param {Object|null} grade From gradeResult()
     */
    renderGrade(grade) {
        const box = this.q('.pb-end-grade');
        box.innerHTML = '';
        box.hidden = !grade;
        if (!grade) {
            return;
        }
        let html;
        if (!grade.pass) {
            box.className = 'pb-end-grade is-plain';
            html = '<p class="pb-end-grade-text">' + esc(this.str('gradeonly', grade.label)) + '</p>';
        } else if (grade.passed) {
            box.className = 'pb-end-grade is-passed';
            const text = grade.label === grade.passlabel
                ? this.str('gradepassedfull', grade.passlabel)
                : this.str('gradepassed', {pass: grade.passlabel, grade: grade.label});
            html = '<span class="pb-end-grade-icon">' + TICK_SVG + '</span><div><strong class="pb-end-grade-head">' +
                esc(this.str('gradeheadpassed')) + '</strong><p class="pb-end-grade-text">' + esc(text) + '</p></div>';
        } else {
            box.className = 'pb-end-grade is-notyet';
            html = '<span class="pb-end-grade-icon">' + LOCK_SVG + '</span><div><strong class="pb-end-grade-head">' +
                esc(this.str('gradeheadnotyet')) + '</strong><p class="pb-end-grade-text">' +
                esc(this.str(grade.byquestions ? 'gradenotyet' : 'gradenotyetpages',
                    {pass: grade.passlabel, grade: grade.label, left: grade.left})) + '</p></div>';
        }
        box.innerHTML = html;
        if (grade.pass && !grade.passed && grade.byquestions) {
            const first = this.steps.findIndex((s) => s.type === 'question' && !this.pages[s.page].passed);
            if (first >= 0) {
                box.appendChild(this.button(this.str('gotoquestion'), 'pb-btn pb-btn-primary pb-end-grade-go',
                    () => this.go(first)));
            }
        }
    }

    /**
     * Show the cover.
     */
    showCover() {
        this.leaveSlide();
        this.current = -1;
        this.hideSections();
        this.q('.pb-cover').hidden = false;
        this.q('.pb-controls').hidden = true;
        this.q('.pb-pageindicator').textContent = '';
        this.renderCoverOutline();
        this.renderCoverActions();
        this.fitToViewport();
    }

    /**
     * The finish screen: a big green tick, confetti, a success sound and the learner's own results.
     */
    showEnd() {
        this.leaveSlide();
        this.current = this.steps.length;
        this.hideSections();
        this.q('.pb-controls').hidden = true;
        const end = this.q('.pb-end');
        end.hidden = false;
        end.classList.remove('is-in');
        end.getBoundingClientRect();
        end.classList.add('is-in');

        const asked = this.pages.filter((p) => p.question);
        const first = asked.filter((p) => p.passed && p.attempts <= 1).length;
        const answers = asked.reduce((sum, p) => sum + (p.attempts || 0), 0);
        const minutes = Math.max(1, Math.round(this.pages.reduce((sum, p) => sum + (p.timespent || 0), 0) / 60));
        const allright = asked.every((p) => p.passed);

        const grade = this.gradeResult(asked);
        this.q('.pb-end-title').textContent = grade && grade.pass
            ? this.str(grade.passed ? 'passedtitle' : 'notpassedtitle') : this.str('finishedtitle');
        this.q('.pb-end-text').textContent = asked.length && allright
            ? this.str('finishedall', {pages: this.pages.length, questions: asked.length}) + ' ' + this.str('teachersees')
            : this.str('finishedtext', this.pages.length);
        this.renderGrade(grade);
        end.classList.toggle('is-notpassed', !!(grade && grade.pass && !grade.passed));
        const stats = this.q('.pb-end-stats');
        stats.innerHTML = '';
        if (asked.length) {
            [[first + '/' + asked.length, this.str('rightfirst'), 'is-green'],
                [String(answers), this.str('answerstotal'), 'is-indigo'],
                [this.str('timespent', minutes), this.str('timelabel'), 'is-grey']].forEach(([big, small, cls]) => {
                stats.insertAdjacentHTML('beforeend', '<div class="pb-end-stat ' + cls + '"><strong>' + esc(big) +
                    '</strong>' + (small ? '<span>' + esc(small) + '</span>' : '') + '</div>');
            });
        }
        const actions = this.q('.pb-end-actions');
        actions.innerHTML = '';
        actions.appendChild(this.button(this.str('readagain'), 'pb-btn pb-btn-ghost', () => this.go(0)));
        const next = this.data.nextactivity;
        const back = document.createElement('a');
        back.className = 'pb-btn ' + (next ? 'pb-btn-ghost' : 'pb-btn-primary');
        back.href = this.data.courseurl;
        back.textContent = this.str('backtocourse');
        actions.appendChild(back);
        if (next) {
            // The next activity in the course index.
            const go = document.createElement('a');
            go.className = 'pb-btn pb-btn-primary pb-end-next';
            go.href = next.url;
            go.title = next.name;
            go.innerHTML = '<span class="pb-end-next-label">' + esc(this.str('nextactivity')) + '</span>' +
                '<span class="pb-end-next-name">' + esc(next.name) + '</span>';
            actions.appendChild(go);
        }

        const results = this.q('.pb-end-results');
        results.hidden = !asked.length;
        if (asked.length) {
            let rows = '';
            asked.forEach((p) => {
                const num = this.pages.indexOf(p) + 1;
                const tries = Math.max(1, p.attempts || 0);
                let label = this.str('notyet');
                let cls = 'is-notyet';
                if (p.passed) {
                    label = tries === 1 ? this.str('firsttry') : this.str('ntries', tries);
                    cls = tries === 1 ? 'is-first' : (tries === 2 ? 'is-second' : 'is-more');
                }
                let dots = '';
                for (let d = 0; d < Math.min(tries, 5); d++) {
                    const last = d === Math.min(tries, 5) - 1;
                    dots += '<i class="' + (last && p.passed ? cls : '') + '"></i>';
                }
                rows += '<li class="pb-result"><span class="pb-result-num">' + num + '</span>' +
                    '<span class="pb-result-name"><strong>' + p.title + '</strong><span>' +
                    esc(this.str('qtype_' + p.question.type)) + '</span></span>' +
                    '<span class="pb-result-dots" aria-hidden="true">' + dots + '</span>' +
                    '<span class="pb-result-label ' + cls + '">' + esc(label) + '</span></li>';
            });
            results.innerHTML = '<div class="pb-results-head"><h3>' + esc(this.str('yourresults')) + '</h3></div>' +
                '<ol class="pb-results-list">' + rows + '</ol>';
        }

        // Confetti (skipped when the learner prefers less motion) and the success sound.
        const confetti = this.q('.pb-confetti');
        confetti.innerHTML = '';
        const still = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        // Not passed yet: no party.
        const notpassed = !!(grade && grade.pass && !grade.passed);
        if (!still && !notpassed) {
            const colours = ['#4338CA', '#12B76A', '#F79009', '#F04438', '#0BA5EC', '#EE46BC', '#FDB022'];
            for (let c = 0; c < 70; c++) {
                const bit = document.createElement('i');
                bit.style.left = (Math.random() * 100).toFixed(1) + '%';
                bit.style.background = colours[c % colours.length];
                bit.style.animationDelay = (Math.random() * 1.4).toFixed(2) + 's';
                bit.style.animationDuration = (2.6 + Math.random() * 1.8).toFixed(2) + 's';
                bit.style.setProperty('--pb-drift', ((Math.random() - 0.5) * 160).toFixed(0) + 'px');
                bit.className = c % 3 === 0 ? 'is-round' : '';
                confetti.appendChild(bit);
            }
        }
        if (!notpassed) {
            window.setTimeout(() => Sounds.play('fanfare'), 250);
        }
        this.q('.pb-pageindicator').textContent = '';
        this.scrollIntoPlace();
    }

    /**
     * Stop narration and the page clock before leaving a slide.
     */
    leaveSlide() {
        this.stopAudio();
        this.stopHeartbeat();
        this.clearFigure();
    }

    /**
     * Remove the card pictures and the animated diagram of the page being left.
     */
    clearFigure() {
        if (this.diagram) {
            this.diagram.destroy();
            this.diagram = null;
        }
        window.clearTimeout(this.layerTimer);
        const figure = this.q('.pb-page-figure');
        if (!figure) {
            return;
        }
        figure.querySelectorAll('.pb-card-image, .pb-diagram').forEach((el) => el.remove());
        figure.classList.remove('has-layers', 'has-diagram');
        figure.querySelectorAll('.is-shown, .is-under').forEach((el) => el.classList.remove('is-shown', 'is-under'));
    }

    /**
     * Show one picture of the page figure (the page picture, a card picture or the diagram): it rises gently into
     * place over the one before, with the same timing as the cards.
     *
     * @param {HTMLElement|null} layer
     */
    showLayer(layer) {
        const figure = this.q('.pb-page-figure');
        if (!layer || !figure || layer.classList.contains('is-shown')) {
            return;
        }
        figure.querySelectorAll('.is-under').forEach((el) => el.classList.remove('is-under'));
        figure.querySelectorAll('.is-shown').forEach((el) => {
            el.classList.remove('is-shown');
            el.classList.add('is-under');
        });
        layer.getBoundingClientRect();
        layer.classList.add('is-shown');
        window.clearTimeout(this.layerTimer);
        this.layerTimer = window.setTimeout(() => {
            figure.querySelectorAll('.is-under').forEach((el) => el.classList.remove('is-under'));
        }, 650);
    }

    /**
     * Draw the page's animated diagram afresh, showing what appears from the given card on.
     *
     * @param {Object} page
     * @param {number} card
     */
    drawDiagram(page, card) {
        const figure = this.q('.pb-page-figure');
        if (this.diagram) {
            this.diagram.destroy();
        }
        let box = figure.querySelector('.pb-diagram');
        if (!box) {
            box = document.createElement('div');
            box.className = 'pb-diagram';
            figure.appendChild(box);
        }
        const renderer = page.diagram.type === 'animated_scene' ? Scenes
            : (page.diagram.type === 'svg_scene' ? SvgScenes : Diagrams);
        this.diagram = renderer.render(box, page.diagram, {
            label: this.str('diagramlabel', page.alt || page.title),
            pacing: (this.data.animationsettings || {}).animationpacing || 'normal',
            strings: {replay: this.str('animationreplay'), stageReplay: this.str('animationstagereplay'),
                normal: this.str('animationnormal')},
            onReplay: () => this.narrate(0),
            onStageReplay: stage => this.narrate(0, stage - 1),
        });
        if (this.diagram) {
            this.diagram.finish(card);
        } else {
            // Invalid/unsupported scene: keep the existing page picture rather than a blank figure.
            box.remove();
            figure.classList.remove('has-diagram');
            return page.image ? figure.querySelector('.pb-page-image') : figure.querySelector('.pb-image-placeholder');
        }
        return box;
    }

    /**
     * What the figure shows while a card is read (or tapped): its card picture, or its part of the diagram.
     *
     * @param {number} i card position
     */
    showCard(i, ms = 0) {
        if (this.diagram) {
            if (ms) {
                // Read aloud: the card's part of the diagram plays over the length of its clip.
                this.diagram.stage(i + 1, ms);
            } else {
                this.diagram.finish(i + 1);
            }
            return;
        }
        this.showLayer(this.q('.pb-page-figure .pb-card-image[data-card="' + i + '"]'));
    }

    /**
     * Go to a step.
     *
     * @param {number} index
     */
    go(index) {
        if (index < 0) {
            this.showCover();
            return;
        }
        if (index >= this.steps.length) {
            this.showEnd();
            return;
        }
        if (!this.isUnlocked(index)) {
            return;
        }
        const forward = index >= this.current;
        this.leaveSlide();
        if (this.returnTo !== null && index !== this.current) {
            this.revisitHeard = false;
        }
        this.current = index;
        this.slideDone = false;
        this.answered = false;
        this.feedbackClip = null;
        this.hideSections();
        this.q('.pb-controls').hidden = false;

        const step = this.step();
        let section;
        if (step.type === 'page') {
            section = this.renderPage(step);
        } else if (step.type === 'recap') {
            section = this.renderRecap(step);
        } else {
            section = this.renderQuestion(step);
        }
        section.hidden = false;
        section.classList.remove('is-turning-forward', 'is-turning-back');
        section.getBoundingClientRect();
        section.classList.add(forward ? 'is-turning-forward' : 'is-turning-back');

        let nextLabel = index === this.steps.length - 1 ? this.str('finish') : this.str('next');
        const following = this.steps[index + 1];
        if (step.type === 'recap' && following && following.type === 'question') {
            nextLabel = this.str('startcheck');
        } else if (step.type === 'question' && following && following.type === 'question') {
            nextLabel = this.str('nextquestion');
        }
        if (this.returnTo !== null && step.type === 'page') {
            nextLabel = this.str('backtoquestion', this.steps[this.returnTo].n);
        }
        this.q('.pb-next-label').textContent = nextLabel;
        this.q('.pb-pageindicator').textContent = step.type === 'page'
            ? this.str('pageof', {page: step.page + 1, total: this.pages.length}) : this.stepTitle(step);
        this.setListenState('idle');
        this.updateNext();
        this.fitToViewport();
        this.scrollIntoPlace();

        if (step.type === 'page') {
            this.track('view');
            this.startHeartbeat();
        }
        // Questions are read straight away, and so is a page the learner chose to listen to again; other slides
        // wait for Listen unless autoplay is on.
        const page = step.type === 'question' ? this.pages[step.page] : null;
        const revisit = this.returnTo !== null && step.type === 'page';
        if ((page && !page.passed && !page.relisten) || revisit || (step.type !== 'question' && this.data.autoplay)) {
            this.narrate(650);
        }
    }

    /**
     * Render a concept page.
     *
     * @param {Object} step
     * @returns {HTMLElement}
     */
    renderPage(step) {
        const page = this.pages[step.page];
        const figure = this.q('.pb-page-figure');
        const img = this.q('.pb-page-image');
        const placeholder = this.q('.pb-image-placeholder');
        this.clearFigure();
        if (page.image) {
            img.hidden = false;
            img.src = page.image;
            img.alt = page.alt || '';
            placeholder.hidden = true;
        } else {
            img.hidden = true;
            img.removeAttribute('src');
            placeholder.hidden = false;
            placeholder.textContent = this.str('imagecoming');
        }
        let first = page.image ? img : placeholder;
        if (page.diagram) {
            // Animated diagram: drawn here, building up card by card (all of it once the page has been heard).
            figure.classList.add('has-layers', 'has-diagram');
            // Every visit starts the diagram from the beginning: it builds again as the cards are read.
            first = this.drawDiagram(page, 0);
        } else if (page.cards.some((card) => card.image)) {
            // One picture per card: stacked, each rising into place as its card is read.
            figure.classList.add('has-layers');
            page.cards.forEach((card, i) => {
                if (!card.image) {
                    return;
                }
                const layer = document.createElement('img');
                layer.className = 'pb-card-image';
                layer.dataset.card = i;
                layer.src = card.image;
                layer.alt = this.str('cardpicture', card.label);
                figure.appendChild(layer);
                if (i === 0) {
                    first = layer;
                }
            });
        }
        // Every picture rises gently into place with the cards (same easing and length as their entrance).
        first.getBoundingClientRect();
        first.classList.add('is-shown');
        const nextPage = this.pages[step.page + 1];
        if (nextPage && nextPage.image) {
            (new Image()).src = nextPage.image;
        }
        if (nextPage) {
            nextPage.cards.forEach((card) => {
                if (card.image) {
                    (new Image()).src = card.image;
                }
            });
        }
        this.renderRevisitBanner();

        this.q('.pb-spread .pb-page-label').textContent = this.str('pageof', {page: step.page + 1, total: this.pages.length});
        this.q('.pb-spread .pb-page-title').innerHTML = page.title;
        const cards = this.q('.pb-cards');
        cards.innerHTML = '';
        page.cards.forEach((card, i) => {
            const el = document.createElement('article');
            el.className = 'pb-card pb-card--' + card.key + ' is-waiting' + (this.data.numbered ? ' is-numbered' : '');
            // Drives the staggered entrance delay in CSS.
            el.style.setProperty('--pb-i', i);
            el.innerHTML = '<div class="pb-card-head">' +
                '<span class="pb-card-icon" aria-hidden="true">' +
                (this.data.numbered ? cardNumber(card.key) : cardIcon(card.key)) + '</span>' +
                '<span class="pb-card-label">' + card.label + '</span>' +
                '<span class="pb-card-eq" aria-hidden="true"><i></i><i></i><i></i><i></i></span>' +
                '</div><p class="pb-card-text">' + card.text + '</p>';
            if (card.image) {
                // Tapping a card shows its picture again.
                el.classList.add('has-picture');
                el.addEventListener('click', () => this.showCard(i));
            }
            if (page.diagram && page.diagram.type === 'svg_scene') {
                // Tapping a card shows its part of the scene at normal speed.
                el.classList.add('has-picture');
                el.setAttribute('role', 'button');
                el.setAttribute('tabindex', '0');
                el.addEventListener('click', () => this.showCard(i));
                el.addEventListener('keydown', event => {
                    if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        this.showCard(i);
                    }
                });
            }
            if (page.diagram && page.diagram.type === 'animated_scene') {
                el.setAttribute('role', 'button');
                el.setAttribute('tabindex', '0');
                el.setAttribute('aria-label', this.str('animationstagereplay') + ': ' + card.label);
                el.addEventListener('click', () => this.narrate(0, i));
                el.addEventListener('keydown', event => {
                    if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        this.narrate(0, i);
                    }
                });
            }
            cards.appendChild(el);
        });
        return this.q('.pb-spread');
    }

    /**
     * Render a recap slide.
     *
     * @param {Object} step
     * @returns {HTMLElement}
     */
    renderRecap(step) {
        Sounds.play('sparkle');
        this.q('.pb-recap-label').textContent = step.from === step.to ? this.str('recapsubtitleone', step.from)
            : this.str('recapsubtitle', {from: step.from, to: step.to});
        this.q('.pb-recap-title').textContent = this.str('recaptitle');
        const mosaic = this.q('.pb-recap-mosaic');
        mosaic.innerHTML = '';
        mosaic.classList.remove('is-focusing');
        mosaic.dataset.count = step.group.length;
        step.group.forEach((i, n) => {
            const tile = document.createElement('div');
            tile.className = 'pb-recap-tile';
            tile.style.setProperty('--pb-i', n);
            if (this.pages[i].image) {
                tile.style.backgroundImage = 'url("' + this.pages[i].image + '")';
            }
            tile.innerHTML = '<span>' + (n + 1) + '</span>';
            mosaic.appendChild(tile);
        });
        const list = this.q('.pb-recap-list');
        list.innerHTML = '';
        step.group.forEach((i, n) => {
            const li = document.createElement('li');
            li.className = 'pb-recap-item is-waiting';
            li.innerHTML = '<span class="pb-recap-num" aria-hidden="true">' + (n + 1) + '</span>' +
                '<span class="pb-recap-body"><span class="pb-recap-name">' + this.pages[i].title + '</span>' +
                '<span class="pb-recap-text">' + this.pages[i].summary + '</span></span>';
            list.appendChild(li);
        });
        return this.q('.pb-recap');
    }

    /**
     * The banner on a page the learner is listening to again: why they are here, and the way back.
     */
    renderRevisitBanner() {
        const banner = this.q('.pb-banner');
        if (this.returnTo === null) {
            banner.hidden = true;
            banner.innerHTML = '';
            return;
        }
        const n = this.steps[this.returnTo].n;
        const ready = this.revisitHeard;
        banner.hidden = false;
        banner.classList.toggle('is-ready', ready);
        banner.innerHTML = '<span class="pb-banner-icon">' + HEADPHONES_SVG + '</span>' +
            '<span class="pb-banner-text"><strong>' + esc(this.str('relistentitle', n)) + '</strong>' +
            '<span>' + esc(this.str('relistentext')) + '</span></span>';
        const back = this.button('', 'pb-btn pb-banner-back', () => this.backToQuestion());
        back.innerHTML = (ready ? BACK_SVG : LOCK_SVG) + '<span>' + esc(this.str('backtoquestion', n)) + '</span>';
        back.disabled = !ready;
        banner.appendChild(back);
    }

    /**
     * Render the check question of one page.
     *
     * @param {Object} step
     * @returns {HTMLElement}
     */
    renderQuestion(step) {
        const section = this.q('.pb-question');
        const page = this.pages[step.page];
        const q = page.question;
        section.dataset.type = q.type;

        // The page's own illustration sits beside the question, except when the answers are pictures themselves
        // (it would sit next to them and could give the answer away).
        const figure = this.q('.pb-q-figure');
        const pictureAnswers = q.type === 'pick' && q.options.some((o) => o.image);
        const showFigure = !!page.image && !pictureAnswers;
        figure.hidden = !showFigure;
        section.classList.toggle('has-figure', showFigure);
        if (showFigure) {
            const img = figure.querySelector('img');
            img.src = page.image;
            img.alt = page.alt || '';
        }

        const banner = this.q('.pb-q-banner');
        banner.hidden = !this.welcomeBack;
        banner.innerHTML = this.welcomeBack ? BACK_SVG + '<span>' + esc(this.str('welcomeback', step.page + 1)) + '</span>' : '';
        this.welcomeBack = false;

        this.q('.pb-q-pills').innerHTML = '<span class="pb-q-pill pb-q-pill--type"><svg viewBox="0 0 24 24" aria-hidden="true" ' +
            'fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">' +
            TYPE_ICONS[q.type] + '</svg>' + esc(this.str('qtype_' + q.type)) + '</span>' +
            '<span class="pb-q-pill">' + esc(this.str('frompage', step.page + 1)) + ' · ' + page.title + '</span>';
        this.q('.pb-q-label').textContent = this.str('checkx', step.check) + ' · ' +
            this.str('questionof', {n: step.n, total: step.total});
        const segs = this.q('.pb-q-segs');
        segs.innerHTML = '';
        this.steps.filter((s) => s.type === 'question' && s.check === step.check).forEach((s) => {
            const seg = document.createElement('span');
            const p = this.pages[s.page];
            let state = 'todo';
            if (p.passed) {
                state = 'done';
            } else if (s === step) {
                state = p.relisten ? 'retry' : 'now';
            } else if (p.relisten) {
                state = 'retry';
            }
            seg.className = 'pb-q-seg is-' + state;
            segs.appendChild(seg);
        });

        const prompt = this.q('.pb-q-prompt');
        prompt.className = 'pb-q-prompt' + (q.type === 'truefalse' ? ' pb-q-prompt--statement' : '');
        prompt.innerHTML = q.prompt;
        this.q('.pb-q-ask').textContent = q.type === 'truefalse' ? this.str('qtype_truefalse') + '?' : '';
        this.q('.pb-q-ask').hidden = q.type !== 'truefalse';

        const body = this.q('.pb-q-body');
        body.innerHTML = '';
        body.className = 'pb-q-body pb-q-body--' + q.type;
        this.response = null;
        this.selected = null;
        if (q.type === 'truefalse') {
            this.renderTrueFalse(body, page);
        } else if (q.type === 'pick') {
            this.renderPick(body, page);
        } else if (q.type === 'match') {
            this.response = {pairs: {}};
            this.renderMatch(body, page);
        } else {
            this.response = {items: {}};
            this.renderSort(body, page);
        }

        const check = this.q('.pb-q-check');
        check.textContent = this.str('checkanswer');
        check.hidden = q.type === 'truefalse';
        check.onclick = () => this.submit();
        this.q('.pb-q-panel').hidden = true;
        this.q('.pb-q-panel').innerHTML = '';
        this.updateCheck();

        if (page.passed) {
            this.lockAnswers(true);
            check.hidden = true;
            this.showPanel('done', page);
        } else if (page.relisten) {
            this.lockAnswers(true);
            check.hidden = true;
            this.showPanel('wrong', page);
        }
        return section;
    }

    /**
     * True or false: two big buttons that answer straight away.
     *
     * @param {HTMLElement} body
     * @param {Object} page
     */
    renderTrueFalse(body, page) {
        const tried = this.wrong[page.id] || [];
        [[true, 'true', TICK_SVG], [false, 'false', CROSS_SVG]].forEach(([value, key, icon]) => {
            const btn = this.button('', 'pb-tf pb-tf--' + key, () => {
                Sounds.play('pop');
                btn.classList.add('is-chosen');
                this.response = {value};
                this.submit(btn);
            });
            btn.dataset.tick = '1';
            btn.dataset.value = key;
            btn.innerHTML = '<span class="pb-tf-icon">' + icon + '</span><span>' + esc(this.str(key)) + '</span>';
            if (tried.includes(key)) {
                btn.classList.add('is-tried');
                btn.disabled = true;
            }
            body.appendChild(btn);
        });
    }

    /**
     * Pick the picture: four cards (pictures, or text cards when the lesson has no option pictures).
     *
     * @param {HTMLElement} body
     * @param {Object} page
     */
    renderPick(body, page) {
        const tried = this.wrong[page.id] || [];
        const pictures = page.question.options.some((o) => o.image);
        body.classList.toggle('is-text', !pictures);
        page.question.options.forEach((option, i) => {
            const card = this.button('', 'pb-pick', () => {
                if (card.disabled) {
                    return;
                }
                Sounds.play('pop');
                body.querySelectorAll('.pb-pick').forEach((c) => {
                    c.classList.toggle('is-selected', c === card);
                    c.setAttribute('aria-pressed', c === card ? 'true' : 'false');
                });
                this.response = {choice: i};
                this.updateCheck();
            });
            card.dataset.tick = '1';
            card.dataset.choice = String(i);
            card.setAttribute('aria-pressed', 'false');
            card.style.setProperty('--pb-i', i);
            const key = String.fromCharCode(65 + i);
            let media = '';
            if (pictures) {
                media = option.image ? '<img class="pb-pick-img" src="' + esc(option.image) + '" alt="' + option.alt + '">'
                    : '<span class="pb-pick-img is-empty" aria-hidden="true"></span>';
            }
            card.innerHTML = media + '<span class="pb-pick-label"><span class="pb-pick-key">' + key + '</span>' +
                '<span>' + option.label + '</span></span>';
            if (tried.includes(i)) {
                card.classList.add('is-tried');
                card.disabled = true;
            }
            body.appendChild(card);
        });
    }

    /**
     * Match them up: tap a left item, then its partner on the right.
     *
     * @param {HTMLElement} body
     * @param {Object} page
     */
    renderMatch(body, page) {
        const q = page.question;
        const left = document.createElement('div');
        left.className = 'pb-match-col';
        const right = document.createElement('div');
        right.className = 'pb-match-col';
        q.lefts.forEach((text, i) => {
            const btn = this.button('', 'pb-match-item pb-match-left', () => this.matchLeft(i));
            btn.dataset.tick = '1';
            btn.dataset.left = String(i);
            btn.innerHTML = '<span class="pb-match-badge" aria-hidden="true"></span><span>' + text + '</span>';
            left.appendChild(btn);
        });
        q.rights.forEach((item) => {
            const btn = this.button('', 'pb-match-item pb-match-right', () => this.matchRight(item.key));
            btn.dataset.tick = '1';
            btn.dataset.key = item.key;
            btn.innerHTML = '<span class="pb-match-badge" aria-hidden="true"></span><span>' + item.text + '</span>';
            right.appendChild(btn);
        });
        body.appendChild(left);
        body.appendChild(right);
        this.paintMatch();
    }

    /**
     * A left match item was tapped.
     *
     * @param {number} i
     */
    matchLeft(i) {
        const pairs = this.response.pairs;
        if (pairs[i] !== undefined) {
            delete pairs[i];
            this.selected = i;
        } else {
            this.selected = this.selected === i ? null : i;
        }
        Sounds.play('pop');
        this.paintMatch();
    }

    /**
     * A right match item was tapped.
     *
     * @param {string} key
     */
    matchRight(key) {
        const pairs = this.response.pairs;
        const owner = Object.keys(pairs).find((l) => pairs[l] === key);
        if (this.selected === null) {
            if (owner !== undefined) {
                delete pairs[owner];
                this.selected = Number(owner);
                Sounds.play('pop');
                this.paintMatch();
            }
            return;
        }
        if (owner !== undefined) {
            delete pairs[owner];
        }
        pairs[this.selected] = key;
        this.selected = null;
        Sounds.play('clack');
        this.paintMatch();
    }

    /**
     * Show the matched pairs (same colour and number on both sides) and the selected item.
     */
    paintMatch() {
        const body = this.q('.pb-q-body');
        const pairs = this.response.pairs;
        body.querySelectorAll('.pb-match-left').forEach((btn) => {
            const i = Number(btn.dataset.left);
            const paired = pairs[i] !== undefined;
            btn.className = 'pb-match-item pb-match-left' + (paired ? ' is-paired pb-pair-' + (i % PAIR_COLOURS) : '') +
                (this.selected === i ? ' is-selected' : '');
            btn.setAttribute('aria-pressed', this.selected === i ? 'true' : 'false');
            btn.querySelector('.pb-match-badge').textContent = paired ? String(i + 1) : '';
        });
        body.querySelectorAll('.pb-match-right').forEach((btn) => {
            const owner = Object.keys(pairs).find((l) => pairs[l] === btn.dataset.key);
            const paired = owner !== undefined;
            const pair = paired ? ' is-paired pb-pair-' + (Number(owner) % PAIR_COLOURS) : '';
            btn.className = 'pb-match-item pb-match-right' + pair +
                (this.selected !== null && !paired ? ' is-target' : '');
            btn.querySelector('.pb-match-badge').textContent = paired ? String(Number(owner) + 1) : '';
        });
        this.updateCheck();
    }

    /**
     * Sort into groups: tap (or drag) a card, then tap the group it belongs in.
     *
     * @param {HTMLElement} body
     * @param {Object} page
     */
    renderSort(body, page) {
        const q = page.question;
        const tray = document.createElement('div');
        tray.className = 'pb-sort-tray';
        tray.innerHTML = '<span class="pb-sort-traylabel">' + esc(this.str('stilltosort')) + '</span>';
        tray.addEventListener('click', (e) => {
            if (!e.target.closest('.pb-chip-item') && this.selected !== null) {
                this.place(this.selected, null);
            }
        });
        this.dropTarget(tray, null);
        body.appendChild(tray);
        const groups = document.createElement('div');
        groups.className = 'pb-sort-groups';
        q.groups.forEach((name, g) => {
            const bucket = document.createElement('div');
            bucket.className = 'pb-bucket pb-bucket--' + g;
            bucket.dataset.group = String(g);
            const head = this.button('', 'pb-bucket-head', () => {
                if (this.selected !== null) {
                    this.place(this.selected, g);
                }
            });
            head.dataset.tick = '1';
            head.innerHTML = '<span class="pb-bucket-name">' + name + '</span><span class="pb-bucket-drop">' +
                esc(this.str('dropin')) + '</span>';
            bucket.appendChild(head);
            const list = document.createElement('div');
            list.className = 'pb-bucket-list';
            bucket.appendChild(list);
            bucket.addEventListener('click', (e) => {
                if (!e.target.closest('.pb-chip-item, .pb-bucket-head') && this.selected !== null) {
                    this.place(this.selected, g);
                }
            });
            this.dropTarget(bucket, g);
            groups.appendChild(bucket);
        });
        body.appendChild(groups);
        q.items.forEach((item) => {
            const chip = this.button('', 'pb-chip-item', () => {
                this.selected = this.selected === item.index ? null : item.index;
                Sounds.play('pop');
                this.paintSort();
            });
            chip.dataset.tick = '1';
            chip.dataset.item = String(item.index);
            chip.draggable = true;
            chip.innerHTML = '<span class="pb-chip-grip" aria-hidden="true">⋮⋮</span><span>' + item.text + '</span>';
            chip.addEventListener('dragstart', (e) => {
                this.selected = item.index;
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', String(item.index));
                this.paintSort();
            });
            tray.appendChild(chip);
        });
        this.paintSort();
    }

    /**
     * Let cards be dropped on a group (or back on the tray, group null).
     *
     * @param {HTMLElement} el
     * @param {number|null} group
     */
    dropTarget(el, group) {
        el.addEventListener('dragover', (e) => {
            e.preventDefault();
            el.classList.add('is-over');
        });
        el.addEventListener('dragleave', () => el.classList.remove('is-over'));
        el.addEventListener('drop', (e) => {
            e.preventDefault();
            el.classList.remove('is-over');
            const index = Number(e.dataTransfer.getData('text/plain'));
            if (!Number.isNaN(index)) {
                this.place(index, group);
            }
        });
    }

    /**
     * Put a card in a group (or back on the tray).
     *
     * @param {number} index
     * @param {number|null} group
     */
    place(index, group) {
        if (group === null) {
            delete this.response.items[index];
        } else {
            this.response.items[index] = group;
        }
        this.selected = null;
        Sounds.play('pop');
        this.paintSort();
    }

    /**
     * Move every card to where it has been put.
     */
    paintSort() {
        const body = this.q('.pb-q-body');
        const tray = body.querySelector('.pb-sort-tray');
        body.querySelectorAll('.pb-chip-item').forEach((chip) => {
            const i = Number(chip.dataset.item);
            const g = this.response.items[i];
            const home = g === undefined ? tray : body.querySelector('.pb-bucket--' + g + ' .pb-bucket-list');
            if (chip.parentNode !== home) {
                home.appendChild(chip);
            }
            chip.classList.toggle('is-selected', this.selected === i);
            chip.setAttribute('aria-pressed', this.selected === i ? 'true' : 'false');
        });
        body.querySelectorAll('.pb-bucket').forEach((b) => b.classList.toggle('is-target', this.selected !== null));
        tray.classList.toggle('is-empty', !tray.querySelector('.pb-chip-item'));
        this.updateCheck();
    }

    /**
     * Enable "Check answer" once the answer is complete, and say what is left to do.
     */
    updateCheck() {
        const step = this.step();
        if (!step || step.type !== 'question') {
            return;
        }
        const q = this.pages[step.page].question;
        let ready = false;
        let hint = '';
        if (q.type === 'pick') {
            ready = this.response !== null;
            hint = this.str('pickhint');
        } else if (q.type === 'match') {
            const done = Object.keys(this.response.pairs).length;
            ready = done === q.lefts.length;
            hint = this.str('tapmatch') + ' · ' + this.str('matchedof', {done, total: q.lefts.length});
        } else if (q.type === 'sort') {
            ready = Object.keys(this.response.items).length === q.items.length;
            hint = this.str('tapsort');
        }
        const check = this.q('.pb-q-check');
        check.disabled = !ready;
        this.q('.pb-q-hint').textContent = hint;
    }

    /**
     * Stop (or allow) any further answering on the current question.
     *
     * @param {boolean} locked
     */
    lockAnswers(locked) {
        this.q('.pb-q-body').querySelectorAll('button').forEach((b) => {
            if (locked) {
                b.disabled = true;
            } else if (!b.classList.contains('is-tried')) {
                b.disabled = false;
            }
        });
        this.q('.pb-q-check').disabled = locked;
    }

    /**
     * Send the answer to be checked on the server.
     *
     * @param {HTMLElement} [chosen] the true/false button pressed
     */
    async submit(chosen) {
        const step = this.step();
        if (!step || step.type !== 'question' || this.response === null) {
            return;
        }
        const stepIndex = this.current;
        const page = this.pages[step.page];
        const response = this.response;
        this.lockAnswers(true);
        this.stopAudio();
        this.setListenState('idle');
        let result;
        try {
            result = await Ajax.call([{
                methodname: 'mod_visuallearning_answer_question',
                args: {cmid: this.data.cmid, pageid: page.id, response: JSON.stringify(response)},
            }])[0];
        } catch (err) {
            Notification.exception(err);
            this.lockAnswers(false);
            return;
        }
        if (this.current !== stepIndex) {
            return;
        }
        page.attempts = result.attempts;
        const body = this.q('.pb-q-body');
        const type = page.question.type;
        let el = chosen || null;
        if (type === 'pick') {
            el = body.querySelector('.pb-pick[data-choice="' + response.choice + '"]');
        }
        this.q('.pb-q-check').hidden = true;
        if (result.correct) {
            page.passed = true;
            page.relisten = false;
            this.answered = true;
            Sounds.play('chime');
            body.classList.add('is-correct');
            if (el) {
                el.classList.add('is-right');
            }
            this.renderSegments(step);
            this.showPanel('right', page, result);
            this.feedbackClip = result.feedback ? {el: this.q('.pb-q-panel'), urls: [result.feedbackaudio],
                text: result.feedback} : null;
            this.updateNext();
            if (this.feedbackClip && result.feedbackaudio) {
                this.narrate(500);
            } else {
                this.slideDone = true;
                this.pulseNext();
            }
            return;
        }
        Sounds.play('bonk');
        page.relisten = result.relisten;
        const tried = this.wrong[page.id] || [];
        if (type === 'pick') {
            tried.push(response.choice);
        } else if (type === 'truefalse') {
            tried.push(response.value ? 'true' : 'false');
        }
        this.wrong[page.id] = tried;
        this.saveTried();
        body.classList.add('is-wrong');
        if (el) {
            el.classList.add('is-wrongchoice');
        }
        this.renderSegments(step);
        this.showPanel('wrong', page, result);
        this.updateNext();
        if (result.retryaudio) {
            // The narrator sends the learner back to the page.
            this.feedbackClip = {el: this.q('.pb-q-panel'), urls: [result.retryaudio], text: ''};
            this.narrate(500);
        }
    }

    /**
     * Remember the choices already tried in this browser tab (a nicety: nothing depends on it).
     */
    saveTried() {
        try {
            window.sessionStorage.setItem('mod_visuallearning_tried_' + this.data.cmid, JSON.stringify(this.wrong));
        } catch (e) {
            // Not remembered: storage blocked.
        }
    }

    /**
     * Repaint the question progress segments of the current check.
     *
     * @param {Object} step
     */
    renderSegments(step) {
        const segs = this.q('.pb-q-segs').children;
        this.steps.filter((s) => s.type === 'question' && s.check === step.check).forEach((s, i) => {
            const p = this.pages[s.page];
            let state = 'todo';
            if (p.passed) {
                state = 'done';
            } else if (p.relisten) {
                state = 'retry';
            } else if (s === step) {
                state = 'now';
            }
            if (segs[i]) {
                segs[i].className = 'pb-q-seg is-' + state;
            }
        });
    }

    /**
     * The panel beside a question: right (feedback), wrong (go back and listen) or done (answered earlier).
     *
     * @param {string} kind right, wrong or done
     * @param {Object} page
     * @param {Object} [result]
     */
    showPanel(kind, page, result) {
        const panel = this.q('.pb-q-panel');
        panel.className = 'pb-q-panel is-' + kind;
        panel.hidden = false;
        const num = this.pages.indexOf(page) + 1;
        if (kind === 'wrong') {
            panel.setAttribute('role', 'alert');
            const thumb = page.image ? '<img src="' + esc(page.image) + '" alt="">' : '<span class="is-empty"></span>';
            panel.innerHTML = '<div class="pb-q-panel-head"><span class="pb-q-panel-icon">' + HEADPHONES_SVG + '</span>' +
                '<strong>' + esc(this.str('notquite')) + '</strong></div>' +
                '<p>' + this.str('notquitetext', {page: num, title: page.title}) + '</p>' +
                '<div class="pb-q-thumb">' + thumb + '<span><strong>' + esc(this.str('frompage', num)) + '</strong>' +
                '<span>' + page.title + '</span></span></div>';
            const go = this.button('', 'pb-btn pb-btn-primary pb-q-golisten', () => this.goListen());
            go.innerHTML = BACK_SVG + '<span>' + esc(this.str('golisten', num)) + '</span>';
            panel.appendChild(go);
            const note = document.createElement('span');
            note.className = 'pb-q-note';
            note.textContent = this.str('attemptx', page.attempts) + ' · ' + this.str('nopenalty');
            panel.appendChild(note);
            window.setTimeout(() => go.focus({preventScroll: true}), 350);
            return;
        }
        panel.setAttribute('role', 'status');
        const tries = page.attempts <= 1 ? this.str('gotfirst') : this.str('gotnth', page.attempts);
        const feedback = result && result.feedback ? '<p>' + result.feedback + '</p>' : '';
        panel.innerHTML = '<div class="pb-q-panel-head"><span class="pb-q-panel-icon is-tick">' + TICK_SVG + '</span>' +
            '<strong>' + esc(kind === 'right' ? this.str('thatsit') : this.str('correct')) + '</strong></div>' +
            feedback + '<span class="pb-q-note">' + esc(tries) + '</span>';
    }

    /**
     * "Go back and listen": open the question's page, read it again, and come back here.
     */
    goListen() {
        const step = this.step();
        if (!step || step.type !== 'question') {
            return;
        }
        this.returnTo = this.current;
        this.revisitHeard = false;
        Sounds.play('swoosh');
        this.go(this.steps.findIndex((s) => s.type === 'page' && s.page === step.page));
    }

    /**
     * Show or hide the Next button and the hint beside it.
     */
    updateNext() {
        const nextBtn = this.q('.pb-next');
        const hint = this.q('.pb-listen-hint');
        const step = this.step();
        const show = this.canAdvance();
        // After a wrong answer Next stays on screen, locked, so the learner sees it is waiting for them.
        const locked = !show && !!step && step.type === 'question' && this.pages[step.page].relisten;
        nextBtn.classList.toggle('is-visible', show || locked);
        nextBtn.disabled = !show;
        nextBtn.setAttribute('aria-hidden', show ? 'false' : 'true');
        let hintText = '';
        if (!show && step && step.type === 'question' && this.pages[step.page].relisten) {
            hintText = this.str('nextlocked', step.page + 1);
        } else if (!show && step && step.type !== 'question') {
            hintText = this.narrating ? this.str('listenfirst') : this.str('pressplay');
        }
        hint.innerHTML = hintText && step.type === 'question' ? LOCK_SVG + '<span>' + esc(hintText) + '</span>'
            : esc(hintText);
        hint.classList.toggle('is-locked', !!hintText && step.type === 'question');
        hint.hidden = hintText === '';
        nextBtn.classList.toggle('is-locked', locked);
        nextBtn.setAttribute('aria-hidden', show || locked ? 'false' : 'true');
    }

    /**
     * Pulse the Next button and focus it.
     */
    async pulseNext() {
        const nextBtn = this.q('.pb-next');
        nextBtn.classList.remove('is-pulse');
        nextBtn.getBoundingClientRect();
        nextBtn.classList.add('is-pulse');
        await wait(300);
        if (nextBtn.classList.contains('is-visible')) {
            nextBtn.focus({preventScroll: true});
        }
    }

    /**
     * Update the Listen buttons and the play/pause control.
     *
     * @param {string} state idle|playing|paused|done
     */
    setListenState(state) {
        const labels = {idle: 'listen', playing: 'listening', paused: 'play', done: 'listenagain'};
        this.root.querySelectorAll('.pb-listen').forEach((btn) => {
            btn.dataset.state = state;
            btn.querySelector('.pb-listen-label').textContent = this.str(labels[state]);
        });
        const btn = this.q('.pb-playpause');
        const playing = state === 'playing';
        btn.classList.toggle('is-playing', playing);
        const label = playing ? this.str('pause') : this.str('play');
        btn.setAttribute('aria-label', label);
        btn.title = label;
    }

    /**
     * The clips to play on the current slide, each with the element it highlights.
     *
     * @returns {Array<Object>}
     */
    clips() {
        const step = this.step();
        if (!step) {
            return [];
        }
        if (step.type === 'page') {
            const els = this.root.querySelectorAll('.pb-card');
            return this.pages[step.page].cards.map((card, i) => ({el: els[i], urls: [card.audio], text: card.text,
                start: () => this.showCard(i, Math.max(1800, Math.round(wordCount(card.text) / WORDS_PER_SECOND * 1000)))}));
        }
        if (step.type === 'recap') {
            const els = this.root.querySelectorAll('.pb-recap-item');
            const tiles = this.root.querySelectorAll('.pb-recap-tile');
            return step.group.map((i, n) => ({
                el: els[n],
                tile: tiles[n],
                urls: [this.data.numbers[n] || '', this.pages[i].summaryaudio],
                text: this.pages[i].summary,
            }));
        }
        const page = this.pages[step.page];
        if (this.feedbackClip && (this.answered || page.relisten)) {
            return [this.feedbackClip];
        }
        return [{el: this.q('.pb-q-prompt'), urls: [page.questionaudio], text: page.question.prompt}];
    }

    /**
     * Narrate the current slide from its first clip.
     *
     * @param {number} [delay] Milliseconds to wait first (lets the entrance animation land).
     */
    async narrate(delay = 150, fromCard = 0) {
        this.stopAudio();
        const token = ++this.token;
        const step = this.step();
        const clips = this.clips().slice(fromCard);
        if (!step || !clips.length) {
            return;
        }
        if (step.type !== 'question') {
            clips.forEach((c) => {
                c.el.classList.remove('is-speaking', 'is-done');
                c.el.classList.add('is-waiting');
            });
            this.focusRecapTile(null);
        }
        if (step.type === 'page' && this.pages[step.page].diagram && this.diagram) {
            // Read from the start: the diagram builds up again from card 1, its movement waiting for card 1's clip.
            this.drawDiagram(this.pages[step.page], fromCard);
        }
        this.narrating = true;
        this.paused = false;
        this.setListenState('playing');
        this.updateNext();
        await wait(delay);
        for (const clip of clips) {
            if (token !== this.token) {
                return;
            }
            clip.el.classList.remove('is-waiting');
            clip.el.classList.add('is-speaking');
            if (clip.start) {
                clip.start();
            }
            if (clip.tile) {
                this.focusRecapTile(clip.tile);
            }
            const last = clip.urls.length - 1;
            for (let u = 0; u <= last; u++) {
                if (token !== this.token) {
                    return;
                }
                // A missing lead-in clip (e.g. the spoken number) is skipped; missing content falls back to a pause.
                if (clip.urls[u] || u === last) {
                    await this.playClip(clip.urls[u], clip.text, token);
                }
            }
            if (token !== this.token) {
                return;
            }
            clip.el.classList.remove('is-speaking');
            clip.el.classList.add('is-done');
            await wait(420);
        }
        if (token === this.token) {
            this.focusRecapTile(null);
            if (fromCard > 0 && step.type === 'page' && !this.pages[step.page].listened) {
                // Replaying a later stage must not falsely mark earlier narration as heard.
                this.narrating = false;
                this.paused = false;
                this.setListenState('idle');
                this.updateNext();
            } else {
                this.onSlideNarrated();
            }
        }
    }

    /**
     * Spotlight one recap illustration while its concept is read; the others frost over.
     *
     * @param {HTMLElement|null} tile The tile to spotlight, or null to clear every tile.
     */
    focusRecapTile(tile) {
        const mosaic = this.q('.pb-recap-mosaic');
        if (!mosaic) {
            return;
        }
        mosaic.querySelectorAll('.pb-recap-tile.is-focus').forEach((t) => t.classList.remove('is-focus'));
        if (tile) {
            tile.classList.add('is-focus');
        }
        mosaic.classList.toggle('is-focusing', !!tile);
    }

    /**
     * Play one clip; resolves when it ends, or after a reading-time pause if the audio is missing.
     *
     * @param {string} url
     * @param {string} text Used to estimate the pause when there is no audio.
     * @param {number} token
     * @returns {Promise}
     */
    playClip(url, text, token) {
        return new Promise((resolve) => {
            this.clipResolve = resolve;
            if (!url) {
                const ms = Math.max(1800, Math.round(wordCount(text) / WORDS_PER_SECOND * 1000));
                this.fallbackTimer = window.setTimeout(() => this.finishClip(), ms);
                return;
            }
            this.audio.src = url;
            this.audio.addEventListener('loadedmetadata', () => {
                // The diagram's movement for this card lasts exactly as long as the clip.
                if (token === this.token && this.diagram) {
                    this.diagram.retime(this.audio.duration * 1000);
                }
            }, {once: true});
            const promise = this.audio.play();
            if (promise) {
                promise.catch((err) => {
                    if (token !== this.token) {
                        return;
                    }
                    if (err && err.name === 'NotAllowedError') {
                        // The browser blocked autoplay: wait for the learner to press Listen.
                        this.stopAudio();
                        this.setListenState('idle');
                        this.updateNext();
                    } else if (err && err.name !== 'AbortError') {
                        this.finishClip();
                    }
                });
            }
        });
    }

    /**
     * The current clip ended (or failed).
     */
    finishClip() {
        if (this.diagram && this.diagram.finishStage) {
            this.diagram.finishStage();
        }
        window.clearTimeout(this.fallbackTimer);
        if (this.clipResolve) {
            const resolve = this.clipResolve;
            this.clipResolve = null;
            resolve();
        }
    }

    /**
     * Every clip on the slide has been read.
     */
    onSlideNarrated() {
        this.narrating = false;
        this.slideDone = true;
        this.setListenState('done');
        const step = this.step();
        if (step.type === 'page') {
            const page = this.pages[step.page];
            if (this.diagram && this.diagram.narrated) {
                // Heard in full: the whole animation can now be replayed at normal speed.
                this.diagram.narrated();
            }
            if (this.returnTo !== null) {
                // Heard again in full: its question is unlocked (on the server too).
                this.revisitHeard = true;
                const question = this.pages[this.steps[this.returnTo].page];
                question.relisten = false;
                page.listened = true;
                this.track('listened');
                this.updateProgress();
                this.renderRevisitBanner();
                Sounds.play('chime');
            } else if (!page.listened) {
                page.listened = true;
                this.track('listened');
                this.updateProgress();
            }
        } else if (step.type === 'recap') {
            this.recapHeard.add(this.current);
        }
        this.updateNext();
        if (step.type !== 'question' || this.answered) {
            this.pulseNext();
        }
    }

    /**
     * Listen / play / pause: start narration, or pause and resume it.
     */
    togglePlay() {
        if (!this.isSlide()) {
            return;
        }
        if (!this.narrating) {
            this.narrate();
            return;
        }
        if (!this.paused) {
            this.paused = true;
            this.audio.pause();
            if (this.diagram) {
                this.diagram.pause(true);
            }
            window.clearTimeout(this.fallbackTimer);
            this.setListenState('paused');
            return;
        }
        this.paused = false;
        if (this.diagram) {
            this.diagram.pause(false);
        }
        this.setListenState('playing');
        if (this.audio.getAttribute('src') && !this.audio.ended) {
            this.audio.play().catch(() => this.finishClip());
        } else {
            this.finishClip();
        }
    }

    /**
     * Stop any narration in progress.
     */
    stopAudio() {
        this.token++;
        window.clearTimeout(this.fallbackTimer);
        this.narrating = false;
        this.paused = false;
        this.audio.pause();
        this.audio.removeAttribute('src');
        // Release any waiting clip without advancing: its token is now stale.
        this.finishClip();
    }

    /**
     * Start the time-on-page heartbeat.
     */
    startHeartbeat() {
        if (this.heartbeat) {
            window.clearInterval(this.heartbeat);
        }
        this.heartbeat = window.setInterval(() => {
            if (document.visibilityState === 'visible') {
                this.track('ping');
            }
        }, HEARTBEAT_MS);
    }

    /**
     * Stop the heartbeat, crediting the last seconds on the page being left.
     */
    stopHeartbeat() {
        if (this.heartbeat) {
            this.track('ping');
            window.clearInterval(this.heartbeat);
            this.heartbeat = null;
        }
    }

    /**
     * Send a tracking event for the current concept page.
     *
     * @param {string} action view|resume|ping|listened
     */
    track(action) {
        const step = this.step();
        if (!step || step.type !== 'page') {
            return;
        }
        const page = this.pages[step.page];
        Ajax.call([{
            methodname: 'mod_visuallearning_track_page',
            args: {cmid: this.data.cmid, pageid: page.id, action},
        }])[0].then((result) => {
            if (action === 'listened' && result.allcomplete) {
                this.root.classList.add('is-complete');
            }
            return result;
        }).catch((err) => {
            if (action === 'listened') {
                Notification.exception(err);
            }
        });
    }
}

/**
 * Initialise a player.
 *
 * @param {string} id Root element id.
 */
export const init = (id) => {
    const root = document.getElementById(id);
    if (root && !root.dataset.pbReady) {
        root.dataset.pbReady = '1';
        new Player(root);
    }
};
