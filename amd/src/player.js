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
 * The book is a sequence of steps: concept pages, then (after every four concepts) a narrated
 * recap slide and a true/false check. Each slide is narrated clip by clip when the learner
 * presses Listen (or automatically, if the teacher enabled autoplay).
 *
 * @module     mod_visuallearning/player
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';

const HEARTBEAT_MS = 15000;
const WORDS_PER_SECOND = 2.6;
const NAV_FREE = 1;

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
        this.steps = this.buildSteps();
        this.current = -1; // Cover is -1, the end screen is steps.length.
        this.narrating = false;
        this.paused = false;
        this.slideDone = false;
        this.token = 0;
        this.heartbeat = null;
        this.fallbackTimer = null;
        this.returnTo = null; // Quiz step to return to after revisiting a page.
        this.recapHeard = new Set();
        this.audio = root.querySelector('.pb-audio');
        this.clipResolve = null;
        this.build();
    }

    /**
     * Turn pages into steps: page, page, ... then recap and quiz after every group.
     *
     * @returns {Array<Object>}
     */
    buildSteps() {
        const steps = [];
        const size = this.data.groupsize || 4;
        for (let start = 0; start < this.pages.length; start += size) {
            const group = [];
            for (let i = start; i < Math.min(start + size, this.pages.length); i++) {
                steps.push({type: 'page', page: i});
                group.push(i);
            }
            // A full group always gets a review; a final partial group only if it has two or more concepts.
            if (group.length >= 2) {
                const range = {from: group[0] + 1, to: group[group.length - 1] + 1};
                if (this.data.recap) {
                    steps.push({type: 'recap', group, ...range});
                }
                const questions = group.filter((i) => this.pages[i].statement);
                if (this.data.quiz && questions.length) {
                    steps.push({type: 'quiz', group: questions, current: null, ...range});
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
        this.root.querySelectorAll('.pb-answer--true .pb-answer-label').forEach((el) => {
            el.textContent = this.str('true');
        });
        this.root.querySelectorAll('.pb-answer--false .pb-answer-label').forEach((el) => {
            el.textContent = this.str('false');
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
        if (this.data.intro) {
            this.q('.pb-cover-intro').innerHTML = this.data.intro;
        } else {
            this.q('.pb-cover-intro').hidden = true;
        }
        this.q('.pb-cover-meta').textContent = this.str('pagescount', this.pages.length);
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
        this.root.querySelectorAll('.pb-answer').forEach((btn) => {
            btn.addEventListener('click', () => this.answer(btn.dataset.answer === '1'));
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
        if (step.type === 'quiz') {
            return step.group.every((i) => this.pages[i].passed);
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
        if (this.data.navmode === NAV_FREE) {
            return true;
        }
        if (step.type === 'recap') {
            return this.slideDone || this.recapHeard.has(this.current);
        }
        return this.isComplete(step);
    }

    /**
     * Next button: normally the next step; after revisiting a page, back to the true/false statement.
     */
    next() {
        if (!this.canAdvance()) {
            return;
        }
        const step = this.step();
        if (this.returnTo !== null && step && step.type === 'page') {
            const target = this.returnTo;
            this.returnTo = null;
            this.go(target);
            return;
        }
        this.go(this.current + 1);
    }

    /**
     * Keep the whole slide (image, cards and buttons) above the fold, whatever the theme chrome.
     */
    fitToViewport() {
        // Stack image above text when the book itself is narrow (phones, or Moodle's drawers open on a tablet).
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
    }

    /**
     * Scroll so the book sits directly under the navbar.
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
        return this.str(step.type === 'recap' ? 'recaptoc' : 'quiztoc', {from: step.from, to: step.to});
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
        ['.pb-cover', '.pb-spread', '.pb-recap', '.pb-quiz', '.pb-end'].forEach((sel) => {
            this.q(sel).hidden = true;
        });
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
        this.renderCoverActions();
        this.fitToViewport();
    }

    /**
     * Show the "well done" screen.
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
        this.q('.pb-end-title').textContent = this.str('finishedtitle');
        this.q('.pb-end-text').textContent = this.str('finishedtext', this.pages.length);
        const actions = this.q('.pb-end-actions');
        actions.innerHTML = '';
        actions.appendChild(this.button(this.str('readagain'), 'pb-btn pb-btn-ghost', () => this.go(0)));
        const back = document.createElement('a');
        back.className = 'pb-btn pb-btn-primary';
        back.href = this.data.courseurl;
        back.textContent = this.str('backtocourse');
        actions.appendChild(back);
        this.q('.pb-pageindicator').textContent = '';
        this.scrollIntoPlace();
    }

    /**
     * Stop narration and the page clock before leaving a slide.
     */
    leaveSlide() {
        this.stopAudio();
        this.stopHeartbeat();
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
        this.current = index;
        this.slideDone = false;
        this.hideSections();
        this.q('.pb-controls').hidden = false;

        const step = this.step();
        let section;
        if (step.type === 'page') {
            section = this.renderPage(step);
        } else if (step.type === 'recap') {
            section = this.renderRecap(step);
        } else {
            section = this.renderQuiz(step);
        }
        section.hidden = false;
        section.classList.remove('is-turning-forward', 'is-turning-back');
        section.getBoundingClientRect();
        section.classList.add(forward ? 'is-turning-forward' : 'is-turning-back');

        let nextLabel = index === this.steps.length - 1 ? this.str('finish') : this.str('next');
        if (this.returnTo !== null && step.type === 'page') {
            nextLabel = this.str('backtoquiz');
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
        // True/false statements are read straight away; other slides wait for Listen unless autoplay is on.
        if ((step.type === 'quiz' && step.current !== null) || (step.type !== 'quiz' && this.data.autoplay)) {
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
        const img = this.q('.pb-page-image');
        const placeholder = this.q('.pb-image-placeholder');
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
        const nextPage = this.pages[step.page + 1];
        if (nextPage && nextPage.image) {
            (new Image()).src = nextPage.image;
        }
        const banner = this.q('.pb-banner');
        banner.hidden = this.returnTo === null;
        banner.textContent = this.returnTo === null ? '' : this.str('revisit');

        this.q('.pb-spread .pb-page-label').textContent = this.str('pageof', {page: step.page + 1, total: this.pages.length});
        this.q('.pb-spread .pb-page-title').innerHTML = page.title;
        const cards = this.q('.pb-cards');
        cards.innerHTML = '';
        page.cards.forEach((card, i) => {
            const el = document.createElement('article');
            el.className = 'pb-card pb-card--' + card.key + ' is-waiting';
            // Drives the staggered entrance delay in CSS.
            el.style.setProperty('--pb-i', i);
            el.innerHTML = '<div class="pb-card-head">' +
                '<span class="pb-card-icon" aria-hidden="true">' + cardIcon(card.key) + '</span>' +
                '<span class="pb-card-label">' + card.label + '</span>' +
                '<span class="pb-card-eq" aria-hidden="true"><i></i><i></i><i></i><i></i></span>' +
                '</div><p class="pb-card-text">' + card.text + '</p>';
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
        this.q('.pb-recap-label').textContent = this.str('recapsubtitle', {from: step.from, to: step.to});
        this.q('.pb-recap-title').textContent = this.str('recaptitle');
        const mosaic = this.q('.pb-recap-mosaic');
        mosaic.innerHTML = '';
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
     * Render the current true/false statement of a quiz step.
     *
     * @param {Object} step
     * @returns {HTMLElement}
     */
    renderQuiz(step) {
        const remaining = step.group.filter((i) => !this.pages[i].passed);
        const dots = this.q('.pb-quiz-dots');
        dots.innerHTML = '';
        step.group.forEach((i) => {
            const dot = document.createElement('span');
            dot.className = 'pb-quiz-dot' + (this.pages[i].passed ? ' is-passed' : '');
            dots.appendChild(dot);
        });
        this.q('.pb-quiz-title').textContent = this.str('quiztitle');
        const feedback = this.q('.pb-quiz-feedback');
        feedback.textContent = '';
        feedback.className = 'pb-quiz-feedback';
        const statement = this.q('.pb-quiz-statement');
        statement.className = 'pb-quiz-statement';
        const answers = this.q('.pb-quiz-answers');
        const img = this.q('.pb-quiz-image');

        if (!remaining.length) {
            step.current = null;
            this.q('.pb-quiz-label').textContent = this.str('quiztoc', {from: step.from, to: step.to});
            statement.textContent = this.str('quizdone');
            statement.classList.add('is-done');
            answers.hidden = true;
            const last = this.pages[step.group[step.group.length - 1]];
            img.hidden = !last.image;
            img.src = last.image || '';
            img.alt = last.alt || '';
            return this.q('.pb-quiz');
        }
        step.current = remaining[0];
        const page = this.pages[step.current];
        const n = step.group.indexOf(step.current) + 1;
        this.q('.pb-quiz-label').textContent = this.str('quizof', {n, total: step.group.length});
        statement.innerHTML = page.statement;
        statement.style.setProperty('--pb-i', 0);
        answers.hidden = false;
        answers.querySelectorAll('button').forEach((b) => {
            b.disabled = false;
        });
        img.hidden = !page.image;
        img.src = page.image || '';
        img.alt = page.alt || '';
        return this.q('.pb-quiz');
    }

    /**
     * The learner answered the current statement.
     *
     * @param {boolean} value
     */
    async answer(value) {
        const step = this.step();
        if (!step || step.type !== 'quiz' || step.current === null) {
            return;
        }
        const stepIndex = this.current;
        const pageIndex = step.current;
        const page = this.pages[pageIndex];
        const buttons = this.q('.pb-quiz-answers').querySelectorAll('button');
        buttons.forEach((b) => {
            b.disabled = true;
        });
        this.stopAudio();
        this.setListenState('idle');
        let result;
        try {
            result = await Ajax.call([{
                methodname: 'mod_visuallearning_answer_statement',
                args: {cmid: this.data.cmid, pageid: page.id, answer: value},
            }])[0];
        } catch (err) {
            Notification.exception(err);
            buttons.forEach((b) => {
                b.disabled = false;
            });
            return;
        }
        const statement = this.q('.pb-quiz-statement');
        const feedback = this.q('.pb-quiz-feedback');
        if (result.correct) {
            page.passed = true;
            statement.classList.add('is-correct');
            feedback.textContent = this.str('correct');
            feedback.className = 'pb-quiz-feedback is-correct';
            this.q('.pb-quiz-dots').children[step.group.indexOf(pageIndex)].classList.add('is-passed');
            await wait(1300);
            if (this.current !== stepIndex) {
                return;
            }
            this.renderQuiz(step);
            if (step.current !== null) {
                this.narrate(300);
            } else {
                this.slideDone = true;
                this.setListenState('done');
                this.pulseNext();
            }
            this.updateNext();
        } else {
            statement.classList.add('is-wrong');
            feedback.textContent = this.str('incorrect');
            feedback.className = 'pb-quiz-feedback is-wrong';
            await wait(1900);
            if (this.current !== stepIndex) {
                return;
            }
            // Revisit the concept page, then come back to this statement.
            this.returnTo = stepIndex;
            this.go(this.steps.findIndex((s) => s.type === 'page' && s.page === pageIndex));
        }
    }

    /**
     * Show or hide the Next button and the hint beside it.
     */
    updateNext() {
        const nextBtn = this.q('.pb-next');
        const hint = this.q('.pb-listen-hint');
        const step = this.step();
        const show = this.canAdvance();
        nextBtn.classList.toggle('is-visible', show);
        nextBtn.disabled = !show;
        nextBtn.setAttribute('aria-hidden', show ? 'false' : 'true');
        let hintText = '';
        if (!show && step && step.type !== 'quiz') {
            hintText = this.narrating ? this.str('listenfirst') : this.str('pressplay');
        }
        hint.textContent = hintText;
        hint.hidden = hintText === '';
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
            return this.pages[step.page].cards.map((card, i) => ({el: els[i], urls: [card.audio], text: card.text}));
        }
        if (step.type === 'recap') {
            const els = this.root.querySelectorAll('.pb-recap-item');
            return step.group.map((i, n) => ({
                el: els[n],
                urls: [this.data.numbers[n] || '', this.pages[i].summaryaudio],
                text: this.pages[i].summary,
            }));
        }
        if (step.current === null) {
            return [];
        }
        const page = this.pages[step.current];
        return [{el: this.q('.pb-quiz-statement'), urls: [page.statementaudio], text: page.statement}];
    }

    /**
     * Narrate the current slide from its first clip.
     *
     * @param {number} [delay] Milliseconds to wait first (lets the entrance animation land).
     */
    async narrate(delay = 150) {
        this.stopAudio();
        const token = ++this.token;
        const step = this.step();
        const clips = this.clips();
        if (!step || !clips.length) {
            return;
        }
        if (step.type !== 'quiz') {
            clips.forEach((c) => {
                c.el.classList.remove('is-speaking', 'is-done');
                c.el.classList.add('is-waiting');
            });
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
            this.onSlideNarrated();
        }
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
            if (!page.listened) {
                page.listened = true;
                this.track('listened');
                this.updateProgress();
            }
        } else if (step.type === 'recap') {
            this.recapHeard.add(this.current);
        }
        this.updateNext();
        if (step.type !== 'quiz') {
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
            window.clearTimeout(this.fallbackTimer);
            this.setListenState('paused');
            return;
        }
        this.paused = false;
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
