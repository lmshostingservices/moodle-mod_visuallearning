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

namespace mod_visuallearning\local\byok;

/**
 * The LMS Labs checks of a written lesson (validateBundleDraft), for lessons written on the site's own AI.
 *
 * The service checks a written lesson against a strict schema and then against the request. A lesson that fails is
 * rejected with the list of failing fields, which the writer gets back once to fix. This class makes the same
 * decisions and lists the same failures, in the same order: each check works on the decoded JSON exactly as the
 * service's schema library does (strict objects, then unknown keys; required, nullable and optional fields; unions
 * that keep the first partly valid branch).
 *
 * Values are JSON decoded as objects (stdClass) so that {} and [] stay apart.
 *
 * @package    mod_visuallearning
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class draft_check {
    /** Whitespace JavaScript's trim() removes. */
    const WS = '\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';

    /** Parse result: valid. */
    const VALID = 0;
    /** Parse result: failed a check but kept its shape (later refinements still run). */
    const DIRTY = 1;
    /** Parse result: the wrong shape (refinements above it do not run). */
    const ABORTED = 2;

    /** @var array Issues found so far: [path => string[], code => string]. */
    protected array $issues = [];

    /** @var array The request (bundleDraftInput). */
    protected array $input;

    /**
     * Check a written lesson.
     *
     * @param mixed $raw The decoded answer (objects as stdClass).
     * @param array $input The request.
     * @return mixed The lesson as the service keeps it.
     * @throws draft_error When it fails.
     */
    public static function validate($raw, array $input) {
        $check = new self();
        $check->input = $input;
        return $check->run($raw);
    }

    /**
     * The checks, in the service's order.
     *
     * @param mixed $raw
     * @return mixed
     */
    protected function run($raw) {
        $input = $this->input;
        if (is_object($raw) && is_array($raw->pages ?? null)) {
            $copy = clone $raw;
            $copy->pages = array_map(function ($p) use ($input) {
                if (
                    ($input['animationMode'] ?? null) !== 'animated' && is_object($p) && is_object($p->diagram ?? null)
                        && ($p->diagram->type ?? null) === 'animated_scene' && !self::scene_valid($p->diagram)
                ) {
                    $p = clone $p;
                    $p->diagram = null;
                }
                return $p;
            }, $raw->pages);
            $raw = $copy;
        }
        $this->issues = [];
        $this->book($raw, []);
        if ($this->issues) {
            $diagnostics = [];
            foreach ($this->issues as $issue) {
                $value = $raw;
                foreach ($issue['path'] as $key) {
                    if (is_object($value) && is_string($key)) {
                        $value = property_exists($value, $key) ? $value->$key : null;
                    } else if (is_array($value) && is_int($key)) {
                        $value = $value[$key] ?? null;
                    } else if (is_string($value) && is_int($key)) {
                        $value = null;
                    } else {
                        $value = null;
                    }
                }
                $entry = ['field' => implode('.', $issue['path']) ?: 'result', 'rule' => $issue['code']];
                if (is_string($value)) {
                    $entry['characters'] = self::cp($value);
                }
                $diagnostics[] = $entry;
            }
            throw new draft_error($diagnostics);
        }
        if ($raw->language !== $input['language'] || count($raw->pages) !== $input['maxPages']) {
            throw new draft_error();
        }
        $pages = $raw->pages;
        $science = ($input['teachingApproach'] ?? null) === 'science';
        if (!isset($input['writerBrief']) && ($science ? count($raw->characters) > 3 : count($raw->characters) < 2)) {
            throw new draft_error();
        }
        $mode = $input['pictureMode'] ?? null;
        $animation = $input['animationMode'] ?? null;
        $types = $input['questionTypes'];
        foreach ($pages as $i => $page) {
            if (property_exists($page, 'scene') && property_exists($page, 'scene_plan')) {
                throw new draft_error([['field' => "pages.$i.scene", 'rule' => 'scene_contract']]);
            }
            $scene = property_exists($page, 'scene') ? $page->scene : ($page->scene_plan ?? null);
            $hasscene = property_exists($page, 'scene') || property_exists($page, 'scene_plan');
            if ($mode === 'svg' && !self::truthy($scene) && !($hasscene && $scene === null)) {
                throw new draft_error([['field' => "pages.$i.scene", 'rule' => 'required']]);
            }
            $diagram = property_exists($page, 'diagram') ? $page->diagram : null;
            if ($mode === 'animated' && $animation === 'animated' && self::illustration_issues($diagram)) {
                throw new draft_error();
            }
            if (
                $mode === 'animated' && $animation === 'automatic' && self::truthy($diagram)
                    && self::illustration_issues($diagram)
            ) {
                throw new draft_error();
            }
            if ($mode === 'animated' && !property_exists($page, 'diagram')) {
                throw new draft_error();
            }
            if ($mode === 'sequence') {
                $prompts = $page->card_image_prompts ?? null;
                $bad = false;
                if (!$prompts) {
                    $bad = true;
                } else if (!isset($input['writerBrief'])) {
                    foreach (array_slice($prompts, 1) as $p) {
                        if (!str_starts_with($p, 'Same picture, same viewpoint and layout.')) {
                            $bad = true;
                        }
                    }
                }
                if ($bad) {
                    throw new draft_error();
                }
            }
            $q = $page->question;
            if (!isset($input['writerBrief']) && $q !== null && $q->type === 'pick') {
                foreach ($q->options as $j => $option) {
                    $words = count(preg_split('/[' . self::WS . ']+/u', self::trim($option->image_prompt)));
                    if ($words < 20 || $words > 50) {
                        throw new draft_error([['field' => "pages.$i.question.options.$j.image_prompt",
                            'rule' => 'legacy_word_count']]);
                    }
                }
            }
            $previous = $i > 0 ? ($pages[$i - 1]->question->type ?? null) : null;
            if (
                self::num($page->page) !== (float) ($i + 1)
                    || ($types ? ($q === null || !in_array($q->type, $types, true)) : $q !== null)
                    || ($i > 0 && count($types) > 1 && ($q->type ?? null) === $previous)
            ) {
                throw new draft_error();
            }
        }
        return $raw;
    }

    // Schema of the written lesson (bundleDraftOutput).

    /**
     * The lesson.
     *
     * @param mixed $v
     * @param array $path
     * @return int
     */
    protected function book($v, array $path): int {
        return $this->strict($v, $path, [
            'book_title' => fn($x, $p) => $this->ctext($x, $p, 200),
            'industry' => fn($x, $p) => $this->ctext($x, $p, 200),
            'subindustry' => fn($x, $p) => $this->ctext($x, $p, 200),
            'language' => fn($x, $p) => $this->ctext($x, $p, 32),
            'style_guide' => fn($x, $p) => $this->ctext($x, $p, 1500),
            'characters' => fn($x, $p) => $this->arr($x, $p, null, 5, null, fn($c, $cp) => $this->strict($c, $cp, [
                'name' => fn($y, $yp) => $this->ctext($y, $yp, 100),
                'description' => fn($y, $yp) => $this->ctext($y, $yp, 600),
            ])),
            'cover_image_prompt' => fn($x, $p) => $this->ctext($x, $p, 2000),
            'pages' => fn($x, $p) => $this->arr($x, $p, 1, 20, null, fn($y, $yp) => $this->page($y, $yp)),
            'scene_style' => fn($x, $p) => $this->optional($x, fn() => $this->strict($x, $p, [
                'palette' => fn($y, $yp) => $this->str($y, $yp, null, 300),
                'recurring' => fn($y, $yp) => $this->str($y, $yp, null, 300),
                'subject' => fn($y, $yp) => $this->enum($y, $yp, ['biology', 'physics', 'chemistry', 'mechanical', 'earth',
                    'maths', 'safety', 'business', '']),
            ])),
        ]);
    }

    /**
     * One page.
     *
     * @param mixed $v
     * @param array $path
     * @return int
     */
    protected function page($v, array $path): int {
        return $this->strict($v, $path, [
            'page' => fn($x, $p) => $this->int($x, $p, 1, 20),
            'title' => fn($x, $p) => $this->ctext($x, $p, 200),
            'concept' => fn($x, $p) => $this->ctext($x, $p, 250),
            'action' => fn($x, $p) => $this->ctext($x, $p, 250),
            'outcome' => fn($x, $p) => $this->ctext($x, $p, 250),
            'summary' => fn($x, $p) => $this->ctext($x, $p, 250),
            'image_prompt' => fn($x, $p) => $this->ctext($x, $p, 2000),
            'alt_text' => fn($x, $p) => $this->ctext($x, $p, 500),
            'question' => fn($x, $p) => $x === null ? self::VALID : $this->question($x, $p),
            'card_image_prompts' => fn($x, $p) => $this->optional($x, fn() => $this->arr(
                $x,
                $p,
                3,
                3,
                3,
                fn($y, $yp) => $this->btext($y, $yp, 600)
            )),
            'diagram' => fn($x, $p) => $this->optional($x, fn() => $x === null ? self::VALID : $this->union($x, $p, [
                fn($y, $yp) => $this->legacy_diagram($y, $yp),
                fn($y, $yp) => $this->scene($y, $yp),
            ])),
            'scene' => fn($x, $p) => $this->optional($x, fn() => $x === null ? self::VALID : $this->compact($x, $p)),
            'scene_plan' => fn($x, $p) => $this->optional($x, fn() => $this->union($x, $p, [
                fn($y, $yp) => $this->plan($y, $yp),
                fn($y, $yp) => $this->compact($y, $yp),
                fn($y, $yp) => $y === 'none' ? self::VALID : $this->issue($yp, 'invalid_literal'),
                fn($y, $yp) => $y === null ? self::VALID : $this->issue($yp, 'invalid_type'),
            ])),
        ]);
    }

    /**
     * A check question (discriminated union on "type", then the distinct-choices refinement).
     *
     * @param mixed $v
     * @param array $path
     * @return int
     */
    protected function question($v, array $path): int {
        if (!self::isobj($v)) {
            return $this->issue($path, 'invalid_type');
        }
        $q = fn($n) => fn($x, $p) => $this->btext($x, $p, $n);
        $shapes = [
            'truefalse' => ['type' => null, 'statement' => $q(160), 'answer' => fn($x, $p) => $this->bool($x, $p),
                'feedback' => $q(160)],
            'pick' => ['type' => null, 'prompt' => $q(160), 'correct' => fn($x, $p) => $this->int($x, $p, 0, 3),
                'feedback' => $q(160), 'options' => fn($x, $p) => $this->arr(
                    $x,
                    $p,
                    4,
                    4,
                    4,
                    fn($y, $yp) => $this->strict($y, $yp, ['label' => $q(60), 'image_prompt' => $q(2000),
                    'alt_text' => $q(500)])
                )],
            'match' => ['type' => null, 'prompt' => $q(160), 'feedback' => $q(160),
                'pairs' => fn($x, $p) => $this->arr($x, $p, 3, 4, null, fn($y, $yp) => $this->strict(
                    $y,
                    $yp,
                    ['left' => $q(60), 'right' => $q(60)]
                ))],
            'sort' => ['type' => null, 'prompt' => $q(160), 'feedback' => $q(160),
                'groups' => fn($x, $p) => $this->arr($x, $p, 2, 2, 2, $q(60)),
                'items' => fn($x, $p) => $this->arr($x, $p, 4, 6, null, fn($y, $yp) => $this->strict(
                    $y,
                    $yp,
                    ['text' => $q(60), 'group' => fn($z, $zp) => $this->int($z, $zp, 0, 1)]
                ))],
        ];
        $type = $v->type ?? null;
        if (!is_string($type) || !isset($shapes[$type])) {
            return $this->issue(array_merge($path, ['type']), 'invalid_union_discriminator');
        }
        $shape = $shapes[$type];
        $shape['type'] = fn($x, $p) => $x === $type ? self::VALID : $this->issue($p, 'invalid_literal');
        $status = $this->strict($v, $path, $shape);
        if ($status === self::ABORTED) {
            return $status;
        }
        $key = fn($s) => mb_strtolower(self::trim($s), 'UTF-8');
        $bad = false;
        if (
            $type === 'match' && (count(array_unique(array_map(fn($p) => $key($p->left), $v->pairs))) !== count($v->pairs)
                || count(array_unique(array_map(fn($p) => $key($p->right), $v->pairs))) !== count($v->pairs))
        ) {
            $bad = true;
        }
        if (
            $type === 'sort' && (count(array_unique($v->groups)) !== 2
                || count(array_unique(array_map(fn($i) => (string) self::num($i->group), $v->items))) !== 2)
        ) {
            $bad = true;
        }
        if ($type === 'pick' && count(array_unique(array_map(fn($o) => $key($o->label), $v->options))) !== 4) {
            $bad = true;
        }
        if ($bad) {
            $this->issue($path, 'custom');
            return self::DIRTY;
        }
        return $status;
    }

    /**
     * The compact scene of an animated SVG page.
     *
     * @param mixed $v
     * @param array $path
     * @return int
     */
    protected function compact($v, array $path): int {
        return $this->strict($v, $path, [
            'type' => fn($x, $p) => $this->optional($x, fn() => $this->enum($x, $p, ['flow', 'mechanism', 'cycle',
                'cause_effect', 'steps', 'compare', 'graph'])),
            'layout' => fn($x, $p) => $this->btext($x, $p, 480),
            'stages' => fn($x, $p) => $this->arr($x, $p, 3, 3, 3, fn($y, $yp) => $this->strict($y, $yp, [
                'do' => fn($z, $zp) => $this->btext($z, $zp, 480),
                'labels' => fn($z, $zp) => $this->arr($z, $zp, null, 3, null, fn($w, $wp) => $this->btext($w, $wp, 32)),
            ])),
            'must' => fn($x, $p) => $this->optional($x, fn() => $this->btext($x, $p, 480)),
            'colours' => fn($x, $p) => $this->optional($x, fn() => $this->btext($x, $p, 480)),
            'realtime' => fn($x, $p) => $this->optional($x, fn() => $this->bool($x, $p)),
        ]);
    }

    /**
     * The older full scene plan.
     *
     * @param mixed $v
     * @param array $path
     * @return int
     */
    protected function plan($v, array $path): int {
        $plain = fn($n, $empty = false) => fn($x, $p) => $this->plain($x, $p, $n, $empty);
        return $this->strict($v, $path, [
            'layout' => $plain(600),
            'stages' => fn($x, $p) => $this->arr($x, $p, 3, 3, 3, fn($y, $yp) => $this->strict($y, $yp, [
                'shows' => $plain(600), 'moves' => $plain(600),
                'labels' => fn($z, $zp) => $this->arr($z, $zp, null, 4, null, function ($w, $wp) {
                    $status = $this->plain($w, $wp, 32);
                    if ($status === self::ABORTED) {
                        return $status;
                    }
                    if (count(preg_split('/[' . self::WS . ']+/u', self::trim($w))) > 4) {
                        $this->issue($wp, 'custom');
                        return self::DIRTY;
                    }
                    return $status;
                }),
            ])),
            'all' => $plain(600), 'colours' => $plain(600),
            'realtime' => fn($x, $p) => $this->bool($x, $p),
            'accuracy' => $plain(600), 'exclude' => $plain(600, true),
        ]);
    }

    /**
     * An animated scene (z.custom: a failure stops the branch).
     *
     * @param mixed $v
     * @param array $path
     * @return int
     */
    protected function scene($v, array $path): int {
        if (self::isobj($v) && self::scene_valid($v)) {
            return self::VALID;
        }
        $this->issue($path, 'custom');
        return self::ABORTED;
    }

    /**
     * A classic animated diagram (legacyDiagramSchema).
     *
     * @param mixed $v
     * @param array $path
     * @return int
     */
    protected function legacy_diagram($v, array $path): int {
        if (!self::isobj($v)) {
            return $this->issue($path, 'invalid_type');
        }
        $label = fn($x, $p) => $this->label($x, $p, 32);
        $card = fn($x, $p) => $this->optional($x, fn() => $this->int($x, $p, 1, 3));
        $colour = fn($x, $p) => $this->optional($x, fn() => $this->enum(
            $x,
            $p,
            ['blue', 'red', 'green', 'orange', 'purple', 'teal', 'grey']
        ));
        $angle = fn($x, $p) => $this->number($x, $p, -360, 360);
        $unit = fn($x, $p) => $this->number($x, $p, 0.1, 1);
        $opt = fn($f) => fn($x, $p) => $this->optional($x, fn() => $f($x, $p));
        $stage = fn($x, $p) => $this->strict($x, $p, [
            'note' => $opt(fn($y, $yp) => $this->label($y, $yp, 48)),
            'to' => $opt(fn($y, $yp) => $this->number($y, $yp, 0, 1440)),
            'from' => $opt(fn($y, $yp) => $this->number($y, $yp, 0, 1440)),
            'turn' => $opt(fn($y, $yp) => $this->number($y, $yp, -720, 720)),
            'flow' => $opt(fn($y, $yp) => $this->enum($y, $yp, ['clockwise', 'anticlockwise', 'stopped'])),
            'focus' => $opt(fn($y, $yp) => $this->int($y, $yp, 1, 6)),
        ]);
        $base = ['title' => $opt($label), 'xlabel' => $opt($label), 'ylabel' => $opt($label), 'unit' => $opt($label),
            'stages' => $opt(fn($x, $p) => $this->strict($x, $p, ['1' => $opt($stage), '2' => $opt($stage),
                '3' => $opt($stage)]))];
        $step = fn($x, $p) => $this->strict($x, $p, ['label' => $label,
            'detail' => $opt(fn($y, $yp) => $this->label($y, $yp, 60)), 'card' => $card]);
        $marker = function ($x, $p) use ($label, $card) {
            $kinds = [
                'halfcycles' => ['kind' => null, 'wave' => fn($y, $yp) => $this->int($y, $yp, 0, 2), 'label' => $label,
                    'card' => $card],
                'peak' => ['kind' => null, 'wave' => fn($y, $yp) => $this->int($y, $yp, 0, 2), 'label' => $label,
                    'card' => $card],
                'period' => ['kind' => null, 'label' => $label, 'card' => $card],
                'lag' => ['kind' => null, 'from' => fn($y, $yp) => $this->int($y, $yp, 0, 2),
                    'to' => fn($y, $yp) => $this->int($y, $yp, 0, 2), 'label' => $label, 'card' => $card],
            ];
            return $this->tagged($x, $p, 'kind', $kinds);
        };
        $types = [
            'waveform' => $base + ['type' => null,
                'cycles' => $opt(fn($x, $p) => $this->int($x, $p, 1, 4)),
                'waves' => fn($x, $p) => $this->arr($x, $p, 1, 3, null, fn($y, $yp) => $this->strict($y, $yp, [
                    'label' => $label, 'colour' => $colour, 'phase' => $opt($angle), 'amplitude' => $opt($unit),
                    'card' => $card])),
                'markers' => $opt(fn($x, $p) => $this->arr($x, $p, null, 4, null, $marker))],
            'phasor' => $base + ['type' => null,
                'rotate' => $opt(fn($x, $p) => $this->bool($x, $p)),
                'vectors' => fn($x, $p) => $this->arr($x, $p, 1, 4, null, fn($y, $yp) => $this->strict($y, $yp, [
                    'label' => $label, 'colour' => $colour, 'angle' => $angle, 'length' => $opt($unit), 'card' => $card])),
                'arcs' => $opt(fn($x, $p) => $this->arr($x, $p, null, 3, null, fn($y, $yp) => $this->strict($y, $yp, [
                    'from' => fn($z, $zp) => $this->int($z, $zp, 0, 3), 'to' => fn($z, $zp) => $this->int($z, $zp, 0, 3),
                    'label' => $label, 'card' => $card])))],
            'circuit' => $base + ['type' => null,
                'source' => fn($x, $p) => $this->strict($x, $p, [
                    'kind' => fn($y, $yp) => $this->enum($y, $yp, ['ac', 'dc', 'battery']), 'label' => $label,
                    'card' => $card]),
                'components' => fn($x, $p) => $this->arr($x, $p, 1, 5, null, fn($y, $yp) => $this->strict($y, $yp, [
                    'kind' => fn($z, $zp) => $this->enum($z, $zp, ['resistor', 'inductor', 'capacitor', 'lamp', 'switch',
                        'motor', 'meter']), 'label' => $label, 'card' => $card])),
                'current' => $opt(fn($x, $p) => $this->strict($x, $p, [
                    'show' => fn($y, $yp) => $this->bool($y, $yp),
                    'direction' => fn($y, $yp) => $y === 'clockwise' ? self::VALID : $this->issue($yp, 'invalid_literal'),
                    'alternating' => fn($y, $yp) => $this->bool($y, $yp), 'label' => $label, 'card' => $card]))],
            'process' => $base + ['type' => null,
                'steps' => fn($x, $p) => $this->arr($x, $p, 2, 6, null, $step),
                'inputs' => $opt(fn($x, $p) => $this->arr($x, $p, null, 6, null, $label)),
                'outputs' => $opt(fn($x, $p) => $this->arr($x, $p, null, 6, null, $label))],
            'cycle' => $base + ['type' => null, 'steps' => fn($x, $p) => $this->arr($x, $p, 3, 6, null, $step)],
            'forces' => $base + ['type' => null, 'object' => $label,
                'forces' => fn($x, $p) => $this->arr($x, $p, 1, 6, null, fn($y, $yp) => $this->strict($y, $yp, [
                    'label' => $label, 'angle' => $angle, 'magnitude' => $unit, 'card' => $card])),
                'motion' => $opt(fn($x, $p) => $this->strict($x, $p, ['angle' => $angle, 'label' => $label,
                    'card' => $card]))],
            'bars' => $base + ['type' => null,
                'bars' => fn($x, $p) => $this->arr($x, $p, 2, 6, null, fn($y, $yp) => $this->strict($y, $yp, [
                    'label' => $label, 'value' => fn($z, $zp) => $this->number($z, $zp, null, null), 'card' => $card]))],
        ];
        $status = $this->tagged($v, $path, 'type', $types);
        if ($status === self::ABORTED) {
            return $status;
        }
        // The superRefine of legacyDiagramSchema.
        $d = $v;
        $before = count($this->issues);
        $stages = [];
        foreach (['1', '2', '3'] as $k) {
            if (isset($d->stages) && property_exists($d->stages, $k)) {
                $stages[] = $d->stages->$k;
            }
        }
        foreach ($stages as $s) {
            $field = $d->type === 'waveform' ? 'to' : ($d->type === 'phasor' ? 'turn' : ($d->type === 'circuit' ? 'flow'
                : 'focus'));
            $known = array_intersect(
                array_map('strval', array_keys(get_object_vars($s))),
                ['note', 'to', 'from', 'turn', 'flow', 'focus']
            );
            foreach ($known as $key) {
                if ($key !== 'note' && $key !== $field && !($d->type === 'waveform' && $key === 'from')) {
                    $this->issue($path, 'custom');
                    break;
                }
            }
            $cycles = 360 * (isset($d->cycles) ? self::num($d->cycles) : 2);
            if (
                $d->type === 'waveform' && ((isset($s->to) ? self::num($s->to) : 0) > $cycles
                    || (isset($s->from) ? self::num($s->from) : 0) > $cycles)
            ) {
                $this->issue($path, 'custom');
            }
            $count = $d->type === 'process' || $d->type === 'cycle' ? count($d->steps)
                : ($d->type === 'forces' ? count($d->forces) : ($d->type === 'bars' ? count($d->bars) : 0));
            if (isset($s->focus) && self::num($s->focus) > $count) {
                $this->issue($path, 'custom');
            }
        }
        if ($d->type === 'waveform' && isset($d->markers)) {
            $waves = count($d->waves);
            foreach ($d->markers as $m) {
                if (
                    property_exists($m, 'wave') ? self::num($m->wave) >= $waves
                        : (property_exists($m, 'from') ? self::num($m->from) >= $waves || self::num($m->to) >= $waves
                            || self::num($m->from) === self::num($m->to) : false)
                ) {
                    $this->issue($path, 'custom');
                    break;
                }
            }
        }
        if ($d->type === 'phasor' && isset($d->arcs)) {
            $vectors = count($d->vectors);
            foreach ($d->arcs as $a) {
                if (
                    self::num($a->from) >= $vectors || self::num($a->to) >= $vectors ||
                    self::num($a->from) === self::num($a->to)
                ) {
                    $this->issue($path, 'custom');
                    break;
                }
            }
        }
        return count($this->issues) > $before ? self::DIRTY : $status;
    }

    // Schema building blocks, as the service's schema library applies them.

    /**
     * Record an issue.
     *
     * @param array $path
     * @param string $code
     * @return int ABORTED
     */
    protected function issue(array $path, string $code): int {
        $this->issues[] = ['path' => $path, 'code' => $code];
        return self::ABORTED;
    }

    /**
     * A strict object: its keys in schema order, then unknown keys.
     *
     * @param mixed $v
     * @param array $path
     * @param array $shape key => fn(value, path): int; a missing key is checked as null-less "undefined".
     * @return int
     */
    protected function strict($v, array $path, array $shape): int {
        if (!self::isobj($v)) {
            return $this->issue($path, 'invalid_type');
        }
        $status = self::VALID;
        foreach ($shape as $key => $check) {
            $key = (string) $key;
            $here = array_merge($path, [$key]);
            if (!property_exists($v, $key)) {
                $result = $check(new missing(), $here);
            } else {
                $result = $check($v->$key, $here);
            }
            $status = max($status, $result);
        }
        $extra = array_diff(array_map('strval', array_keys(get_object_vars($v))), array_map('strval', array_keys($shape)));
        if ($extra) {
            $this->issue($path, 'unrecognized_keys');
            $status = max($status, self::DIRTY);
        }
        return $status;
    }

    /**
     * An object tagged by a discriminator, with a strict shape per tag.
     *
     * @param mixed $v
     * @param array $path
     * @param string $tag
     * @param array $shapes
     * @return int
     */
    protected function tagged($v, array $path, string $tag, array $shapes): int {
        if (!self::isobj($v)) {
            return $this->issue($path, 'invalid_type');
        }
        $value = property_exists($v, $tag) ? $v->$tag : null;
        if (!is_string($value) || !isset($shapes[$value])) {
            return $this->issue(array_merge($path, [$tag]), 'invalid_union_discriminator');
        }
        $shape = $shapes[$value];
        $shape[$tag] = fn($x, $p) => $x === $value ? self::VALID : $this->issue($p, 'invalid_literal');
        // The tag keeps its place in the shape (after the shared fields).
        return $this->strict($v, $path, $shape);
    }

    /**
     * A union: the first fully valid branch wins, else the first partly valid one (with its issues), else one issue.
     *
     * @param mixed $v
     * @param array $path
     * @param callable[] $branches
     * @return int
     */
    protected function union($v, array $path, array $branches): int {
        $saved = $this->issues;
        $dirty = null;
        foreach ($branches as $branch) {
            $this->issues = [];
            $status = $branch($v, $path);
            if ($status === self::VALID) {
                $this->issues = $saved;
                return self::VALID;
            }
            if ($status === self::DIRTY && $dirty === null) {
                $dirty = $this->issues;
            }
        }
        $this->issues = $saved;
        if ($dirty !== null) {
            array_push($this->issues, ...$dirty);
            return self::DIRTY;
        }
        return $this->issue($path, 'invalid_union');
    }

    /**
     * An optional field: absent passes.
     *
     * @param mixed $v
     * @param callable $check
     * @return int
     */
    protected function optional($v, callable $check): int {
        return $v instanceof missing ? self::VALID : $check();
    }

    /**
     * An array with length limits (checked before its items).
     *
     * @param mixed $v
     * @param array $path
     * @param int|null $min
     * @param int|null $max
     * @param int|null $exact
     * @param callable $item
     * @return int
     */
    protected function arr($v, array $path, ?int $min, ?int $max, ?int $exact, callable $item): int {
        if (!is_array($v)) {
            return $this->issue($path, 'invalid_type');
        }
        $status = self::VALID;
        $n = count($v);
        if ($exact !== null) {
            if ($n !== $exact) {
                $this->issue($path, $n < $exact ? 'too_small' : 'too_big');
                $status = self::DIRTY;
            }
        } else {
            if ($min !== null && $n < $min) {
                $this->issue($path, 'too_small');
                $status = self::DIRTY;
            }
            if ($max !== null && $n > $max) {
                $this->issue($path, 'too_big');
                $status = self::DIRTY;
            }
        }
        foreach ($v as $i => $value) {
            $status = max($status, $item($value, array_merge($path, [$i])));
        }
        return $status;
    }

    /**
     * Learner and writer text of the original picture book contract: not blank, at most $max code points, no angle
     * brackets or control characters other than tab and line breaks.
     *
     * @param mixed $v
     * @param array $path
     * @param int $max
     * @param bool $empty
     * @return int
     */
    protected function ctext($v, array $path, int $max, bool $empty = false): int {
        if (!is_string($v)) {
            return $this->issue($path, 'invalid_type');
        }
        if (
            !(($empty || self::trim($v) !== '') && self::cp($v) <= $max
                && !preg_match('/[<>\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}]/u', $v))
        ) {
            $this->issue($path, 'custom');
            return self::DIRTY;
        }
        return self::VALID;
    }

    /**
     * Text of the lesson contract: at least one character, not blank, at most $max code points, no angle brackets
     * or control characters.
     *
     * @param mixed $v
     * @param array $path
     * @param int $max
     * @return int
     */
    protected function btext($v, array $path, int $max): int {
        if (!is_string($v)) {
            return $this->issue($path, 'invalid_type');
        }
        $status = self::VALID;
        if (self::utf16($v) < 1) {
            $this->issue($path, 'too_small');
            $status = self::DIRTY;
        }
        if (!(self::trim($v) !== '' && self::cp($v) <= $max && !preg_match('/[<>\x{0000}-\x{001F}]/u', $v))) {
            $this->issue($path, 'custom');
            $status = self::DIRTY;
        }
        return $status;
    }

    /**
     * Plain scene-plan text.
     *
     * @param mixed $v
     * @param array $path
     * @param int $max
     * @param bool $empty
     * @return int
     */
    protected function plain($v, array $path, int $max, bool $empty = false): int {
        if (!is_string($v)) {
            return $this->issue($path, 'invalid_type');
        }
        if (!(($empty || self::trim($v) !== '') && self::cp($v) <= $max && !preg_match('/[<>\x{0000}-\x{001F}]/u', $v))) {
            $this->issue($path, 'custom');
            return self::DIRTY;
        }
        return self::VALID;
    }

    /**
     * A diagram label: at most $max UTF-16 units, no angle brackets or control characters.
     *
     * @param mixed $v
     * @param array $path
     * @param int $max
     * @return int
     */
    protected function label($v, array $path, int $max): int {
        if (!is_string($v)) {
            return $this->issue($path, 'invalid_type');
        }
        $status = self::VALID;
        if (self::utf16($v) > $max) {
            $this->issue($path, 'too_big');
            $status = self::DIRTY;
        }
        if (preg_match('/[<>\x{0000}-\x{001F}]/u', $v)) {
            $this->issue($path, 'invalid_string');
            $status = self::DIRTY;
        }
        return $status;
    }

    /**
     * A string with an optional maximum length in UTF-16 units.
     *
     * @param mixed $v
     * @param array $path
     * @param int|null $min
     * @param int|null $max
     * @return int
     */
    protected function str($v, array $path, ?int $min, ?int $max): int {
        if (!is_string($v)) {
            return $this->issue($path, 'invalid_type');
        }
        if ($max !== null && self::utf16($v) > $max) {
            $this->issue($path, 'too_big');
            return self::DIRTY;
        }
        return self::VALID;
    }

    /**
     * One of the listed strings.
     *
     * @param mixed $v
     * @param array $path
     * @param array $values
     * @return int
     */
    protected function enum($v, array $path, array $values): int {
        if (!is_string($v)) {
            return $this->issue($path, 'invalid_type');
        }
        return in_array($v, $values, true) ? self::VALID : $this->issue($path, 'invalid_enum_value');
    }

    /**
     * A boolean.
     *
     * @param mixed $v
     * @param array $path
     * @return int
     */
    protected function bool($v, array $path): int {
        return is_bool($v) ? self::VALID : $this->issue($path, 'invalid_type');
    }

    /**
     * A number within limits.
     *
     * @param mixed $v
     * @param array $path
     * @param float|null $min
     * @param float|null $max
     * @return int
     */
    protected function number($v, array $path, ?float $min, ?float $max): int {
        if (!is_int($v) && !is_float($v)) {
            return $this->issue($path, 'invalid_type');
        }
        $status = self::VALID;
        if ($min !== null && $v < $min) {
            $this->issue($path, 'too_small');
            $status = self::DIRTY;
        }
        if ($max !== null && $v > $max) {
            $this->issue($path, 'too_big');
            $status = self::DIRTY;
        }
        return $status;
    }

    /**
     * A whole number within limits (the whole-number check comes first, then the limits).
     *
     * @param mixed $v
     * @param array $path
     * @param int $min
     * @param int $max
     * @return int
     */
    protected function int($v, array $path, int $min, int $max): int {
        if (!is_int($v) && !is_float($v)) {
            return $this->issue($path, 'invalid_type');
        }
        $status = self::VALID;
        if (is_float($v) && floor($v) != $v) {
            $this->issue($path, 'invalid_type');
            $status = self::DIRTY;
        }
        if ($v < $min) {
            $this->issue($path, 'too_small');
            $status = self::DIRTY;
        }
        if ($v > $max) {
            $this->issue($path, 'too_big');
            $status = self::DIRTY;
        }
        return $status;
    }

    // Helpers.

    /**
     * Whether an animated scene passes the plugin's scene contract (the same validator the service uses).
     *
     * Version 1.1 scenes (released 1.1 clients) may also loop their finale from loopFromMs.
     *
     * @param mixed $scene
     * @return bool
     */
    public static function scene_valid($scene): bool {
        if (!is_object($scene)) {
            return false;
        }
        if (($scene->version ?? null) !== '1.1') {
            return \mod_visuallearning\local\scene_validator::clean($scene) !== null;
        }
        $stages = $scene->stages ?? null;
        if (!is_array($stages) || count($stages) !== 3) {
            return false;
        }
        $copy = json_decode(json_encode($scene));
        $copy->version = '1.0';
        foreach ($stages as $i => $stage) {
            if (!is_object($stage) || !property_exists($stage, 'loopFromMs')) {
                continue;
            }
            $loop = $stage->loopFromMs;
            if (
                !is_int($loop) || $loop < 0 || $loop > 119999 || $i !== 2 || !is_int($stage->durationMs ?? null)
                    || $stage->durationMs - $loop < 500
            ) {
                return false;
            }
            foreach ((array) ($stage->actions ?? []) as $a) {
                if (
                    is_object($a) && ($a->target ?? null) === '@camera'
                        && self::num($a->startMs ?? 0) + self::num($a->durationMs ?? 0) > $loop
                ) {
                    return false;
                }
            }
            unset($copy->stages[$i]->loopFromMs);
        }
        return \mod_visuallearning\local\scene_validator::clean($copy) !== null;
    }

    /**
     * The service's minimum bar for an illustrated scene (illustrationIssues): true when it has issues.
     *
     * @param mixed $scene
     * @return bool
     */
    public static function illustration_issues($scene): bool {
        if (!self::scene_valid($scene)) {
            return true;
        }
        $drawings = array_filter($scene->objects, fn($o) => $o->role === 'draw' && $o->kind !== 'text');
        if (
            !array_filter($drawings, fn($o) => in_array($o->kind, ['path', 'polygon', 'ellipse', 'circle', 'gear',
                'particles'], true))
        ) {
            return true;
        }
        $mechanism = ['move', 'rotate', 'scale', 'follow_path', 'emit_particles', 'merge_particles', 'split_particles',
            'morph', 'flow', 'change_colour', 'change_value', 'connect', 'disconnect', 'draw_path'];
        if (
            !array_filter($scene->stages[1]->actions, fn($a) => $a->target !== '@camera'
                && in_array($a->operation->type, $mechanism, true))
        ) {
            return true;
        }
        $finale = $scene->stages[2];
        if (
            property_exists($finale, 'loopFromMs') && !array_filter($finale->actions, fn($a) =>
                $a->startMs + $a->durationMs > $finale->loopFromMs && in_array($a->operation->type, $mechanism, true))
        ) {
            return true;
        }
        return false;
    }

    /**
     * A JSON object (not the marker of a missing key).
     *
     * @param mixed $v
     * @return bool
     */
    public static function isobj($v): bool {
        return is_object($v) && !($v instanceof missing);
    }

    /**
     * JavaScript's trim().
     *
     * @param string $s
     * @return string
     */
    public static function trim(string $s): string {
        return preg_replace('/^[' . self::WS . ']+|[' . self::WS . ']+$/u', '', $s);
    }

    /**
     * Code points (Array.from(s).length).
     *
     * @param string $s
     * @return int
     */
    public static function cp(string $s): int {
        return mb_strlen($s, 'UTF-8');
    }

    /**
     * UTF-16 code units (s.length).
     *
     * @param string $s
     * @return int
     */
    public static function utf16(string $s): int {
        return (int) (strlen(mb_convert_encoding($s, 'UTF-16LE', 'UTF-8')) / 2);
    }

    /**
     * A JSON number as a float, for comparisons.
     *
     * @param mixed $v
     * @return float
     */
    public static function num($v): float {
        return is_int($v) || is_float($v) ? (float) $v : NAN;
    }

    /**
     * JavaScript truthiness.
     *
     * @param mixed $v
     * @return bool
     */
    public static function truthy($v): bool {
        if (is_array($v) || is_object($v)) {
            return true;
        }
        return $v !== null && $v !== false && $v !== '' && $v !== 0 && $v !== 0.0;
    }
}
