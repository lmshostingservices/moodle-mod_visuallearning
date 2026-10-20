<?php
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
namespace mod_visuallearning\local;

/**
 * Animated diagrams drawn by the plugin (amd/src/diagrams.js) from a small specification the AI writes.
 *
 * The geometry is computed, not drawn by an image model, so phase angles, directions and proportions are exact, and
 * labels are real text in the lesson's language. Every element can name the card from which it appears (1 to 3), so
 * the diagram builds up while the narrator reads the cards.
 *
 * Types: waveform, phasor, circuit, process, cycle, forces, bars. clean() keeps only what the renderer understands,
 * within its limits, and returns null for anything it cannot draw (the page then keeps its picture).
 *
 * @package    mod_visuallearning
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class diagram {
    /** @var string[] Diagram types. */
    public const TYPES = ['waveform', 'phasor', 'circuit', 'process', 'cycle', 'forces', 'bars'];

    /** @var string[] Colour names (the renderer maps them to an accessible palette). */
    public const COLOURS = ['blue', 'red', 'green', 'orange', 'purple', 'teal', 'grey'];

    /** @var int Longest label, in characters. */
    public const MAX_LABEL = 32;

    /** @var int Longest detail line under a process step, in characters. */
    public const MAX_DETAIL = 60;

    /** @var int Longest caption of a stage (shown under the diagram while its card is read). */
    public const MAX_NOTE = 48;

    /** @var string[] Ways current can flow round a circuit during a stage. */
    public const FLOWS = ['clockwise', 'anticlockwise', 'stopped'];

    /** @var string[] Circuit components the renderer draws. */
    public const COMPONENTS = ['resistor', 'inductor', 'capacitor', 'lamp', 'switch', 'motor', 'meter'];

    /**
     * A specification from JSON text or an array, cleaned, or null when it cannot be drawn.
     *
     * @param mixed $spec
     * @return array|null
     */
    public static function clean($spec): ?array {
        if (is_string($spec)) {
            if (trim($spec) === '') {
                return null;
            }
            $spec = json_decode($spec, true);
        }
        if (!is_array($spec) || !in_array($spec['type'] ?? null, self::TYPES, true)) {
            return null;
        }
        $out = ['type' => $spec['type']];
        foreach (['title', 'xlabel', 'ylabel', 'unit'] as $key) {
            if (self::label($spec[$key] ?? '') !== '') {
                $out[$key] = self::label($spec[$key]);
            }
        }
        $method = 'clean_' . $spec['type'];
        $body = self::$method($spec);
        if ($body === null) {
            return null;
        }
        $stages = self::stages($spec['stages'] ?? null, $out + $body);
        return $out + $body + ($stages ? ['stages' => $stages] : []);
    }

    /**
     * What the diagram does while each card is read, card by card: the movement plays once over the card's clip and
     * stops where the next card carries on. All optional; without stages the diagram moves on its own.
     *
     * - every type: "note", a short caption shown while the card is read;
     * - waveform: "from" and "to", in degrees along the time axis (0 to 360 × cycles): the trace runs from one to the
     *   other ("from" defaults to where the card before stopped);
     * - phasor: "turn", the angle in degrees all vectors have turned to by the end of the card;
     * - circuit: "flow", clockwise, anticlockwise or stopped;
     * - process, cycle, forces, bars: "focus", the number of the step, force or bar to highlight (from 1).
     *
     * @param mixed $stages
     * @param array $spec the cleaned diagram
     * @return array card (1 to 3) => stage
     */
    protected static function stages($stages, array $spec): array {
        if (!is_array($stages)) {
            return [];
        }
        $out = [];
        $at = 0.0;
        $end = 360.0 * ($spec['cycles'] ?? 1);
        $lists = ['process' => 'steps', 'cycle' => 'steps', 'forces' => 'forces', 'bars' => 'bars'];
        for ($card = 1; $card <= picture_modes::CARDS; $card++) {
            $stage = $stages[$card] ?? $stages[(string)$card] ?? null;
            if (!is_array($stage)) {
                continue;
            }
            $clean = [];
            $note = self::label($stage['note'] ?? '', self::MAX_NOTE);
            if ($note !== '') {
                $clean['note'] = $note;
            }
            if ($spec['type'] === 'waveform' && isset($stage['to']) && is_numeric($stage['to'])) {
                $from = self::number($stage['from'] ?? $at, $at, 0, $end);
                $to = self::number($stage['to'], $from, 0, $end);
                $clean += ['from' => $from, 'to' => $to];
                $at = $to;
            } else if ($spec['type'] === 'phasor' && isset($stage['turn']) && is_numeric($stage['turn'])) {
                $clean['turn'] = self::number($stage['turn'], 0, -720, 720);
            } else if ($spec['type'] === 'circuit' && in_array($stage['flow'] ?? null, self::FLOWS, true)) {
                $clean['flow'] = $stage['flow'];
            } else if (isset($lists[$spec['type']]) && isset($stage['focus']) && is_numeric($stage['focus'])) {
                $focus = (int)$stage['focus'];
                if ($focus >= 1 && $focus <= count($spec[$lists[$spec['type']]])) {
                    $clean['focus'] = $focus;
                }
            }
            if ($clean) {
                $out[$card] = $clean;
            }
        }
        return $out;
    }

    /**
     * JSON of a cleaned specification, or null.
     *
     * @param mixed $spec
     * @return string|null
     */
    public static function json($spec): ?string {
        $clean = self::clean($spec);
        return $clean === null ? null : json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * A short label: plain text, one line, at most MAX_LABEL characters.
     *
     * @param mixed $text
     * @param int $max
     * @return string
     */
    public static function label($text, int $max = self::MAX_LABEL): string {
        if (!is_string($text) && !is_numeric($text)) {
            return '';
        }
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$text)));
        return \core_text::strlen($text) > $max ? rtrim(\core_text::substr($text, 0, $max - 1)) . '…' : $text;
    }

    /**
     * The card an element appears from (1 to 3).
     *
     * @param array $item
     * @return int
     */
    protected static function card(array $item): int {
        $card = (int)($item['card'] ?? 1);
        return max(1, min(picture_modes::CARDS, $card));
    }

    /**
     * A colour name, or the default.
     *
     * @param mixed $colour
     * @param string $default
     * @return string
     */
    protected static function colour($colour, string $default): string {
        return in_array($colour, self::COLOURS, true) ? $colour : $default;
    }

    /**
     * A number within limits.
     *
     * @param mixed $value
     * @param float $default
     * @param float $min
     * @param float $max
     * @return float
     */
    protected static function number($value, float $default, float $min, float $max): float {
        $n = is_numeric($value) ? (float)$value : $default;
        return round(max($min, min($max, $n)), 3);
    }

    /**
     * The list under a key, at most $max arrays.
     *
     * @param array $spec
     * @param string $key
     * @param int $max
     * @return array
     */
    protected static function items(array $spec, string $key, int $max): array {
        $list = is_array($spec[$key] ?? null) ? array_values(array_filter($spec[$key], 'is_array')) : [];
        return array_slice($list, 0, $max);
    }

    /**
     * Waveforms: up to three sine waves on one time axis, with phase, period and peak markers.
     *
     * @param array $spec
     * @return array|null
     */
    protected static function clean_waveform(array $spec): ?array {
        $waves = [];
        $default = ['blue', 'red', 'green'];
        foreach (self::items($spec, 'waves', 3) as $i => $w) {
            $waves[] = ['label' => self::label($w['label'] ?? ''), 'colour' => self::colour($w['colour'] ?? null, $default[$i]),
                'amplitude' => self::number($w['amplitude'] ?? 1, 1, 0.1, 1),
                'phase' => self::number($w['phase'] ?? 0, 0, -360, 360), 'card' => self::card($w)];
        }
        if (!$waves) {
            return null;
        }
        $markers = [];
        foreach (self::items($spec, 'markers', 4) as $m) {
            $kind = $m['kind'] ?? '';
            if (!in_array($kind, ['period', 'lag', 'peak', 'halfcycles'], true)) {
                continue;
            }
            $marker = ['kind' => $kind, 'label' => self::label($m['label'] ?? ''), 'card' => self::card($m)];
            if ($kind === 'lag') {
                $from = (int)($m['from'] ?? 0);
                $to = (int)($m['to'] ?? 1);
                if (!isset($waves[$from], $waves[$to]) || $from === $to) {
                    continue;
                }
                $marker += ['from' => $from, 'to' => $to];
            } else if ($kind === 'peak' || $kind === 'halfcycles') {
                $wave = (int)($m['wave'] ?? 0);
                if (!isset($waves[$wave])) {
                    continue;
                }
                $marker['wave'] = $wave;
            }
            $markers[] = $marker;
        }
        return ['waves' => $waves, 'cycles' => (int)self::number($spec['cycles'] ?? 2, 2, 1, 4), 'markers' => $markers];
    }

    /**
     * Phasors: up to four rotating vectors from one origin, with angle arcs.
     *
     * @param array $spec
     * @return array|null
     */
    protected static function clean_phasor(array $spec): ?array {
        $vectors = [];
        $default = ['blue', 'red', 'green', 'orange'];
        foreach (self::items($spec, 'vectors', 4) as $i => $v) {
            $vectors[] = ['label' => self::label($v['label'] ?? ''), 'colour' => self::colour($v['colour'] ?? null, $default[$i]),
                'length' => self::number($v['length'] ?? 1, 1, 0.2, 1),
                'angle' => self::number($v['angle'] ?? 0, 0, -360, 360), 'card' => self::card($v)];
        }
        if (!$vectors) {
            return null;
        }
        $arcs = [];
        foreach (self::items($spec, 'arcs', 3) as $a) {
            $from = (int)($a['from'] ?? 0);
            $to = (int)($a['to'] ?? 1);
            if (isset($vectors[$from], $vectors[$to]) && $from !== $to) {
                $arcs[] = ['from' => $from, 'to' => $to, 'label' => self::label($a['label'] ?? ''), 'card' => self::card($a)];
            }
        }
        return ['vectors' => $vectors, 'arcs' => $arcs, 'rotate' => !empty($spec['rotate'])];
    }

    /**
     * A series circuit: a source and up to five components round one loop, with moving current.
     *
     * @param array $spec
     * @return array|null
     */
    protected static function clean_circuit(array $spec): ?array {
        $source = is_array($spec['source'] ?? null) ? $spec['source'] : [];
        $kind = in_array($source['kind'] ?? '', ['ac', 'dc', 'battery'], true) ? $source['kind'] : 'dc';
        $components = [];
        foreach (self::items($spec, 'components', 5) as $c) {
            if (in_array($c['kind'] ?? '', self::COMPONENTS, true)) {
                $components[] = ['kind' => $c['kind'], 'label' => self::label($c['label'] ?? ''), 'card' => self::card($c)];
            }
        }
        if (!$components) {
            return null;
        }
        $current = is_array($spec['current'] ?? null) ? $spec['current'] : [];
        return [
            'source' => ['kind' => $kind, 'label' => self::label($source['label'] ?? ''), 'card' => self::card($source)],
            'components' => $components,
            'current' => [
                'show' => !array_key_exists('show', $current) || !empty($current['show']),
                'label' => self::label($current['label'] ?? ''),
                'colour' => self::colour($current['colour'] ?? null, 'red'),
                'clockwise' => ($current['direction'] ?? 'clockwise') !== 'anticlockwise',
                'alternating' => array_key_exists('alternating', $current) ? !empty($current['alternating']) : $kind === 'ac',
                'card' => self::card($current),
            ],
        ];
    }

    /**
     * Steps of a process in order (process) or round a loop (cycle), with optional inputs and outputs.
     *
     * @param array $spec
     * @param int $min
     * @return array|null
     */
    protected static function steps(array $spec, int $min): ?array {
        $steps = [];
        $default = ['blue', 'teal', 'green', 'orange', 'purple', 'red'];
        foreach (self::items($spec, 'steps', 6) as $i => $s) {
            $label = self::label($s['label'] ?? '');
            if ($label !== '') {
                $steps[] = ['label' => $label, 'detail' => self::label($s['detail'] ?? '', self::MAX_DETAIL),
                    'colour' => self::colour($s['colour'] ?? null, $default[$i]), 'card' => self::card($s)];
            }
        }
        if (count($steps) < $min) {
            return null;
        }
        $out = ['steps' => $steps];
        foreach (['inputs', 'outputs'] as $key) {
            $list = [];
            foreach (array_slice(is_array($spec[$key] ?? null) ? $spec[$key] : [], 0, 4) as $item) {
                $text = is_array($item) ? self::label($item['label'] ?? '') : self::label($item);
                if ($text !== '') {
                    $list[] = ['label' => $text, 'card' => is_array($item) ? self::card($item) : 1];
                }
            }
            $out[$key] = $list;
        }
        return $out;
    }

    /**
     * A process: 2 to 6 steps left to right.
     *
     * @param array $spec
     * @return array|null
     */
    protected static function clean_process(array $spec): ?array {
        return self::steps($spec, 2);
    }

    /**
     * A cycle: 3 to 6 steps round a loop.
     *
     * @param array $spec
     * @return array|null
     */
    protected static function clean_cycle(array $spec): ?array {
        $out = self::steps($spec, 3);
        return $out === null ? null : ['steps' => $out['steps']];
    }

    /**
     * Forces on one object: up to six arrows, with an optional resulting motion.
     *
     * @param array $spec
     * @return array|null
     */
    protected static function clean_forces(array $spec): ?array {
        $forces = [];
        $default = ['red', 'blue', 'green', 'orange', 'purple', 'teal'];
        foreach (self::items($spec, 'forces', 6) as $i => $f) {
            $forces[] = ['label' => self::label($f['label'] ?? ''), 'colour' => self::colour($f['colour'] ?? null, $default[$i]),
                'angle' => self::number($f['angle'] ?? 0, 0, -360, 360),
                'magnitude' => self::number($f['magnitude'] ?? 1, 1, 0.2, 1), 'card' => self::card($f)];
        }
        if (!$forces) {
            return null;
        }
        $out = ['object' => self::label($spec['object'] ?? ''), 'forces' => $forces];
        if (is_array($spec['motion'] ?? null)) {
            $out['motion'] = ['angle' => self::number($spec['motion']['angle'] ?? 0, 0, -360, 360),
                'label' => self::label($spec['motion']['label'] ?? ''), 'card' => self::card($spec['motion'])];
        }
        return $out;
    }

    /**
     * Bars comparing 2 to 6 values (only values the teaching content gives).
     *
     * @param array $spec
     * @return array|null
     */
    protected static function clean_bars(array $spec): ?array {
        $bars = [];
        $default = ['grey', 'blue', 'green', 'orange', 'purple', 'teal'];
        foreach (self::items($spec, 'bars', 6) as $i => $b) {
            if (!is_numeric($b['value'] ?? null) || (float)$b['value'] < 0) {
                continue;
            }
            $bars[] = ['label' => self::label($b['label'] ?? ''), 'value' => round((float)$b['value'], 3),
                'colour' => self::colour($b['colour'] ?? null, $default[$i]), 'card' => self::card($b)];
        }
        return count($bars) >= 2 ? ['bars' => $bars] : null;
    }
}
