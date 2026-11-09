// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * A teacher's own HTML in place of a page picture (an animated chart, a count-up, a drill-down made in Claude).
 *
 * The HTML runs in a sandboxed frame with an origin of its own (the file is also served with a CSP sandbox header), so
 * it cannot reach Moodle, the learner's session or the page around it. The player talks to it with messages only:
 *
 *   {source: 'visuallearning', type: 'vl:card', card: 1..3, ms}   card n is being read aloud, for ms milliseconds
 *   {source: 'visuallearning', type: 'vl:finish', card: 0..4}       show everything up to card n at once
 *   {source: 'visuallearning', type: 'vl:pause', paused: true|false}
 *   {source: 'visuallearning', type: 'vl:reset'}                    the page starts again
 *
 * Every message also carries reducedMotion. HTML that ignores the messages simply plays as it was made.
 *
 * @module     mod_visuallearning/htmlembed
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const reduced = () => !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

/**
 * The sandbox the frame runs in: scripts, forms and pop-ups (links open in a new tab), never same-origin, never the top
 * window.
 */
export const SANDBOX = 'allow-scripts allow-forms allow-popups allow-popups-to-escape-sandbox allow-modals';

/**
 * Draw the frame.
 *
 * @param {HTMLElement} container
 * @param {Object} spec {type: 'html_embed', url, title}
 * @param {Object} options {label, strings: {close}}
 * @returns {Object|null} the renderer the player drives
 */
export const render = (container, spec, options = {}) => {
    if (!spec || typeof spec.url !== 'string' || spec.url === '') {
        return null;
    }
    container.innerHTML = '';
    container.classList.add('is-htmlembed');
    const frame = document.createElement('iframe');
    frame.className = 'vl-htmlembed';
    frame.setAttribute('sandbox', SANDBOX);
    frame.setAttribute('referrerpolicy', 'no-referrer');
    frame.setAttribute('allow', 'fullscreen');
    frame.setAttribute('loading', 'eager');
    frame.title = options.label || spec.title || '';
    frame.src = spec.url;
    container.appendChild(frame);

    let ready = false;
    let last = {type: 'vl:finish', card: 0};
    let paused = false;
    const send = (msg) => {
        last = msg.type === 'vl:pause' ? last : msg;
        if (!frame.contentWindow) {
            return;
        }
        // The frame's origin is opaque ("null"), so it can only be addressed with "*". Nothing private is ever sent.
        // pipeline-ignore: postMessage '*' — the sandboxed frame has an opaque origin, and the message holds no private data.
        frame.contentWindow.postMessage(Object.assign({source: 'visuallearning', reducedMotion: reduced()}, msg), '*');
    };
    // Once the HTML has loaded, tell it where the page is up to (a message sent before then would be lost).
    frame.addEventListener('load', () => {
        ready = true;
        send(last);
        if (paused) {
            send({type: 'vl:pause', paused: true});
        }
    });

    // Full screen: the frame fills the window (works on phones without the Fullscreen API too).
    let close = null;
    const onKey = (e) => {
        if (e.key === 'Escape') {
            collapse();
        }
    };
    const collapse = () => {
        document.removeEventListener('fullscreenchange', onFull);
        if (document.fullscreenElement === container && document.exitFullscreen) {
            document.exitFullscreen().catch(() => null);
        }
        container.classList.remove('is-expanded');
        document.documentElement.classList.remove('vl-htmlembed-open');
        if (close) {
            close.remove();
            close = null;
        }
        document.removeEventListener('keydown', onKey);
    };
    const onFull = () => {
        if (!document.fullscreenElement) {
            collapse();
        }
    };
    const expand = () => {
        if (container.classList.contains('is-expanded')) {
            collapse();
            return;
        }
        container.classList.add('is-expanded');
        // The Fullscreen API where there is one (a fixed overlay inside the rising page figure would be clipped).
        if (container.requestFullscreen) {
            container.requestFullscreen().then(() => {
                document.addEventListener('fullscreenchange', onFull);
                return true;
            }).catch(() => null);
        }
        document.documentElement.classList.add('vl-htmlembed-open');
        close = document.createElement('button');
        close.type = 'button';
        close.className = 'vl-htmlembed-close';
        close.textContent = '×';
        close.setAttribute('aria-label', (options.strings && options.strings.close) || 'Close');
        close.addEventListener('click', (e) => {
            e.stopPropagation();
            collapse();
        });
        container.appendChild(close);
        close.focus();
        document.addEventListener('keydown', onKey);
    };

    return {
        frame,
        isHtml: true,
        stage: (card, ms) => send({type: 'vl:card', card, ms: Math.round(ms || 0)}),
        retime: (ms) => {
            if (last.type === 'vl:card') {
                send(Object.assign({}, last, {ms: Math.round(ms)}));
            }
        },
        finish: (card) => send({type: 'vl:finish', card: card || 0}),
        finishStage: () => {
            if (last.type === 'vl:card') {
                send({type: 'vl:finish', card: last.card});
            }
        },
        narrated: () => send({type: 'vl:finish', card: 4}),
        pause: (p) => {
            paused = !!p;
            if (ready) {
                send({type: 'vl:pause', paused});
            }
        },
        reset: () => send({type: 'vl:reset'}),
        expand,
        destroy: () => {
            collapse();
            frame.remove();
            container.classList.remove('is-htmlembed');
        },
    };
};
