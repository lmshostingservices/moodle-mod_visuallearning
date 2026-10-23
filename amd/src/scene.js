/**
 * Universal scene renderer and narration/lifecycle adapter. Legacy diagrams remain untouched.
 * @module mod_visuallearning/scene
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
import {createEvaluator} from './scene_timeline';
import {buildScene} from './scene_components';

/**
 * Invalid input leaves the existing image/container contents intact and returns null.
 * @param {HTMLElement} container
 * @param {Object} input
 * @param {Object} options
 */
export const render = (container, input, options = {}) => {
    const evaluate = createEvaluator(input);
    if (!evaluate || !container || typeof container.appendChild !== 'function') {
        return null;
    }
    const spec = JSON.parse(JSON.stringify(input));
    const host = document.createElement('section');
    host.className = 'visuallearning-animated-scene';
    host.style.position = 'relative';
    host.style.display = 'flex';
    host.style.flexDirection = 'column';
    host.style.height = '100%';
    host.style.minHeight = '0';
    host.style.color = spec.style.foreground;
    host.style.background = spec.style.background;
    let view;
    try {
        view = buildScene(host, spec);
        const svg = view.svg;
        svg.style.flex = '1 1 0';
        svg.style.minHeight = '0';
        svg.style.maxHeight = 'none';
    } catch (error) {
        return null;
    }
    const caption = document.createElement('p');
    caption.style.margin = '0.75rem 1rem';
    caption.style.fontFamily = spec.style.font;
    caption.style.fontSize = '0.9rem';
    caption.style.maxHeight = '5em';
    caption.style.overflow = 'auto';
    caption.style.flexShrink = '0';
    caption.setAttribute('aria-live', 'polite');
    caption.setAttribute('aria-atomic', 'true');
    caption.textContent = spec.accessibility.summary;
    host.appendChild(caption);
    let card = 0;
    let time = 0;
    let paused = false;
    let fullReplay = false;
    let offscreen = false;
    let dead = false;
    let frame = null;
    let last = null;
    const rate = {slow: 0.7, normal: 1, fast: 1.4}[options.pacing] || 1;
    const media = typeof window.matchMedia === 'function'
        ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
    const reduced = () => typeof options.reducedMotion === 'boolean' ? options.reducedMotion : !!(media && media.matches);
    const duration = () => card ? spec.stages[card - 1].durationMs : 0;
    const stop = () => {
        if (frame !== null) {
            window.cancelAnimationFrame(frame);
        }
        frame = null;
        last = null;
    };
    const paint = () => {
        if (dead) {
            return;
        }
        view.update(evaluate(card, reduced() && card ? duration() : time));
        const text = card ? spec.accessibility.stageDescriptions[card - 1] : spec.accessibility.summary;
        if (caption.textContent !== text) {
            caption.textContent = text;
            view.setDescription(spec.accessibility.summary + ' ' + text);
        }
    };
    const runnable = () => !dead && !paused && !offscreen && !document.hidden &&
        host.isConnected && card > 0 && time < duration() && !reduced();
    const schedule = () => {
        if (frame === null && runnable()) {
            frame = window.requestAnimationFrame(tick);
        }
    };
    const tick = now => {
        frame = null;
        if (!runnable()) {
            last = null;
            return;
        }
        if (last !== null) {
            time = Math.min(duration(), time + Math.max(0, now - last) * rate);
        }
        last = now;
        paint();
        if (time === duration()) {
            last = null;
            if (fullReplay && card < 3) {
                card++;
                time = 0;
                paint();
            } else {
                fullReplay = false;
            }
        }
        schedule();
    };
    const lifecycle = () => {
        stop();
        if (!dead) {
            if (reduced() && card) {
                time = duration();
                fullReplay = false;
            }
            paint();
            schedule();
        }
    };
    const select = (next, at = 0, shouldPause = false) => {
        if (dead || !Number.isInteger(next) || next < 0 || next > 3 || !Number.isFinite(at)) {
            return;
        }
        stop();
        card = next;
        time = Math.max(0, Math.min(duration(), at));
        if (reduced() && card) {
            time = duration();
        }
        paused = shouldPause;
        fullReplay = false;
        paint();
        schedule();
    };
    const controller = {
        setCard: next => select(next),
        stage: next => select(next),
        pause: value => {
            if (!dead) {
                paused = !!value;
                lifecycle();
            }
        },
        retime: () => {},
        seek: (next, milliseconds) => select(next, milliseconds, true),
        finishStage: () => select(card, duration(), true),
        finish: next => {
            if (Number.isInteger(next) && next >= 1 && next <= 3) {
                select(next, spec.stages[next - 1].durationMs, true);
            }
        },
        replay: () => {
            select(1);
            if (reduced()) {
                // A full reduced-motion replay presents the final outcome, not a rapid sequence.
                select(3, spec.stages[2].durationMs, true);
            } else {
                fullReplay = true;
            }
        },
        destroy: () => {
            if (dead) {
                return;
            }
            dead = true;
            stop();
            if (observer) {
                observer.disconnect();
            }
            if (detachObserver) {
                detachObserver.disconnect();
            }
            document.removeEventListener('visibilitychange', lifecycle);
            window.removeEventListener('pagehide', onPageHide);
            window.removeEventListener('pageshow', lifecycle);
            if (media && media.removeEventListener) {
                media.removeEventListener('change', lifecycle);
            } else if (media && media.removeListener) {
                media.removeListener(lifecycle);
            }
            host.remove();
        }
    };
    if (options.controls !== false) {
        const controls = document.createElement('div');
        controls.style.cssText = 'display:flex;flex-wrap:wrap;gap:0.5rem;padding:0 1rem 1rem;flex-shrink:0';
        const strings = options.strings || {};
        [[strings.replay || 'Replay complete animation', () => options.onReplay ? options.onReplay() : controller.replay()],
            [strings.stageReplay || 'Replay selected stage', () => options.onStageReplay
                ? options.onStageReplay(card || 1) : select(card || 1)]].forEach(([label, handler]) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-outline-secondary btn-sm';
            button.textContent = label;
            button.style.color = spec.style.foreground;
            button.style.borderColor = spec.style.foreground;
            button.addEventListener('click', handler);
            controls.appendChild(button);
        });
        host.appendChild(controls);
    }
    container.appendChild(host);
    const observer = typeof window.IntersectionObserver === 'function' ? new window.IntersectionObserver(entries => {
        offscreen = !entries.some(entry => entry.isIntersecting);
        lifecycle();
    }, {threshold: 0}) : null;
    if (observer) {
        observer.observe(host);
    }
    const detachObserver = typeof window.MutationObserver === 'function' ? new window.MutationObserver(() => {
        if (!host.isConnected) {
            controller.destroy();
        }
    }) : null;
    if (detachObserver) {
        detachObserver.observe(document.documentElement, {childList: true, subtree: true});
    }
    const onPageHide = () => stop();
    document.addEventListener('visibilitychange', lifecycle);
    window.addEventListener('pagehide', onPageHide);
    window.addEventListener('pageshow', lifecycle);
    if (media && media.addEventListener) {
        media.addEventListener('change', lifecycle);
    } else if (media && media.addListener) {
        media.addListener(lifecycle);
    }
    paint();
    return controller;
};
