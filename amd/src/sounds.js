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
 * Little interface sounds for the AI Visual Learning player, made on the fly with Web Audio (no sound files).
 *
 * Every sound is short and quiet. One mute switch, remembered in this browser, silences them all; the
 * voiceover is never affected by it.
 *
 * @module     mod_visuallearning/sounds
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const STORE = 'mod_visuallearning_sounds_muted';
const MASTER = 0.5;

let context = null;
let enabled = true;
let muted = false;
let lastTick = 0;

try {
    muted = window.localStorage.getItem(STORE) === '1';
} catch (e) {
    muted = false;
}

/**
 * The audio context, created on first use (browsers allow it after a tap or click).
 *
 * @returns {AudioContext|null}
 */
const ctx = () => {
    if (!enabled || muted) {
        return null;
    }
    const Ctor = window.AudioContext || window.webkitAudioContext;
    if (!Ctor) {
        return null;
    }
    if (!context) {
        try {
            context = new Ctor();
        } catch (e) {
            return null;
        }
    }
    if (context.state === 'suspended') {
        context.resume().catch(() => null);
    }
    return context;
};

/**
 * One soft note.
 *
 * @param {AudioContext} ac
 * @param {number} freq Hz
 * @param {number} start seconds from now
 * @param {number} length seconds
 * @param {number} volume 0-1
 * @param {string} [type] oscillator type
 * @param {number} [slideTo] Hz to glide to
 */
const note = (ac, freq, start, length, volume, type = 'sine', slideTo = 0) => {
    const t = ac.currentTime + start;
    const osc = ac.createOscillator();
    const gain = ac.createGain();
    osc.type = type;
    osc.frequency.setValueAtTime(freq, t);
    if (slideTo) {
        osc.frequency.exponentialRampToValueAtTime(slideTo, t + length);
    }
    gain.gain.setValueAtTime(0.0001, t);
    gain.gain.exponentialRampToValueAtTime(volume * MASTER, t + 0.012);
    gain.gain.exponentialRampToValueAtTime(0.0001, t + length);
    osc.connect(gain).connect(ac.destination);
    osc.start(t);
    osc.stop(t + length + 0.05);
};

/**
 * A short filtered noise burst (paper and swoosh sounds).
 *
 * @param {AudioContext} ac
 * @param {number} length seconds
 * @param {number} volume 0-1
 * @param {number} from Hz
 * @param {number} to Hz
 */
const noise = (ac, length, volume, from, to) => {
    const t = ac.currentTime;
    const buffer = ac.createBuffer(1, Math.ceil(ac.sampleRate * length), ac.sampleRate);
    const data = buffer.getChannelData(0);
    for (let i = 0; i < data.length; i++) {
        data[i] = (Math.random() * 2 - 1) * (1 - i / data.length);
    }
    const src = ac.createBufferSource();
    src.buffer = buffer;
    const filter = ac.createBiquadFilter();
    filter.type = 'bandpass';
    filter.Q.value = 0.8;
    filter.frequency.setValueAtTime(from, t);
    filter.frequency.exponentialRampToValueAtTime(to, t + length);
    const gain = ac.createGain();
    gain.gain.setValueAtTime(0.0001, t);
    gain.gain.exponentialRampToValueAtTime(volume * MASTER, t + length * 0.3);
    gain.gain.exponentialRampToValueAtTime(0.0001, t + length);
    src.connect(filter).connect(gain).connect(ac.destination);
    src.start(t);
};

const sounds = {
    // Hovering or focusing an answer: barely there.
    tick: (ac) => note(ac, 1900, 0, 0.04, 0.05, 'triangle'),
    // Choosing an answer or dropping a card.
    pop: (ac) => note(ac, 520, 0, 0.09, 0.22, 'sine', 880),
    // Two halves of a matched pair.
    clack: (ac) => {
        note(ac, 660, 0, 0.07, 0.18, 'triangle');
        note(ac, 990, 0.07, 0.09, 0.18, 'triangle');
    },
    // Turning to the next page or card.
    swoosh: (ac) => noise(ac, 0.28, 0.35, 2200, 500),
    // Right answer: a bright two-note chime.
    chime: (ac) => {
        note(ac, 784, 0, 0.35, 0.28);
        note(ac, 1175, 0.11, 0.5, 0.26);
    },
    // Not quite: a soft, low bonk (never harsh).
    bonk: (ac) => note(ac, 220, 0, 0.28, 0.3, 'sine', 140),
    // The recap appears.
    sparkle: (ac) => [1319, 1568, 2093, 2637].forEach((f, i) => note(ac, f, i * 0.06, 0.22, 0.12)),
    // The lesson is finished.
    fanfare: (ac) => {
        [523, 659, 784].forEach((f, i) => note(ac, f, i * 0.12, 0.3, 0.24, 'triangle'));
        [523, 659, 784, 1047].forEach((f) => note(ac, f, 0.42, 0.9, 0.16, 'triangle'));
        [1568, 2093, 2637].forEach((f, i) => note(ac, f, 0.6 + i * 0.07, 0.3, 0.08));
    },
};

/**
 * Play one sound by name (nothing when muted, switched off by the teacher, or unsupported).
 *
 * @param {string} name tick, pop, clack, swoosh, chime, bonk, sparkle or fanfare
 */
export const play = (name) => {
    if (name === 'tick') {
        // Hover ticks are throttled so sweeping across answers is not noisy.
        const now = Date.now();
        if (now - lastTick < 70) {
            return;
        }
        lastTick = now;
    }
    const ac = ctx();
    if (ac && sounds[name]) {
        try {
            sounds[name](ac);
        } catch (e) {
            // Sound is a nicety: never let it break the player.
        }
    }
};

/**
 * Whether the teacher allows sounds in this lesson.
 *
 * @param {boolean} value
 */
export const setEnabled = (value) => {
    enabled = !!value;
};

/**
 * Whether sounds are muted in this browser.
 *
 * @returns {boolean}
 */
export const isMuted = () => muted;

/**
 * Mute or unmute the sounds (remembered in this browser when it allows it).
 *
 * @param {boolean} value
 */
export const setMuted = (value) => {
    muted = !!value;
    try {
        window.localStorage.setItem(STORE, muted ? '1' : '0');
    } catch (e) {
        // Not remembered: private browsing or blocked storage.
    }
};
