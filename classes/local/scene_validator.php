<?php
// This file is part of Moodle - https://moodle.org/
// Moodle is free software under the GNU General Public License v3 or later.
namespace mod_visuallearning\local;
defined('MOODLE_INTERNAL') || die();

/**
 * Strict portable scene schema and semantic validation; never repairs scientific geometry.
 *
 * @package mod_visuallearning
 * @copyright 2026 LMS Hosting Services
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scene_validator {
    /** Return a validated, JSON-preserving scene, or null for the existing image fallback. */
    public static function clean($input): ?array {
        try {
            $json = is_string($input) ? $input : json_encode($input, JSON_THROW_ON_ERROR);
            if (strlen($json) > 262144) {
                return null;
            }
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
            $schema = json_decode(file_get_contents(__DIR__ . '/../../schema/animated-scene.json'), true, 64, JSON_THROW_ON_ERROR);
            if (!self::matches($data, $schema, $schema) || !self::semantics($data)) {
                return null;
            }
            // Associative decoding loses empty JSON objects. Preserve them on the browser wire.
            foreach ($data['objects'] as &$object) {
                foreach (['geometry', 'initial'] as $key) {
                    if ($object[$key] === []) {
                        $object[$key] = new \stdClass();
                    }
                }
            }
            unset($object);
            foreach ($data['stages'] as &$stage) {
                foreach ($stage['actions'] as &$action) {
                    if ($action['operation']['params'] === []) {
                        $action['operation']['params'] = new \stdClass();
                    }
                }
                unset($action);
            }
            return $data;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Validate the portable JSON Schema subset used by the published contract. */
    private static function matches($value, array $rule, array $root): bool {
        if (isset($rule['$ref'])) {
            $ref = explode('/', $rule['$ref']);
            if (count($ref) !== 3 || $ref[0] !== '#' || $ref[1] !== '$defs' || !isset($root['$defs'][$ref[2]])) {
                return false;
            }
            return self::matches($value, $root['$defs'][$ref[2]], $root);
        }
        if (isset($rule['anyOf'])) {
            foreach ($rule['anyOf'] as $branch) {
                if (self::matches($value, $branch, $root)) {
                    return true;
                }
            }
            return false;
        }
        if (isset($rule['enum']) && !in_array($value, $rule['enum'], true)) {
            return false;
        }
        $type = $rule['type'] ?? null;
        if ($type === 'object') {
            if (!is_array($value) || ($value && array_is_list($value))) {
                return false;
            }
            foreach ($rule['required'] ?? [] as $key) {
                if (!array_key_exists($key, $value)) {
                    return false;
                }
            }
            foreach ($value as $key => $entry) {
                if (!isset($rule['properties'][$key]) || !self::matches($entry, $rule['properties'][$key], $root)) {
                    return false;
                }
            }
        } else if ($type === 'array') {
            if (!is_array($value) || !array_is_list($value) || count($value) < ($rule['minItems'] ?? 0)
                    || count($value) > ($rule['maxItems'] ?? 40000)) {
                return false;
            }
            foreach ($value as $entry) {
                if (!self::matches($entry, $rule['items'], $root)) {
                    return false;
                }
            }
        } else if ($type === 'string') {
            if (!is_string($value)) {
                return false;
            }
            $length = preg_match_all('/./us', $value);
            if ($length < ($rule['minLength'] ?? 0) || $length > ($rule['maxLength'] ?? 262144)
                    || (isset($rule['pattern']) && !preg_match('~' . str_replace('~', '\\~', $rule['pattern']) . '~uD', $value))) {
                return false;
            }
        } else if ($type === 'number' || $type === 'integer') {
            if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value)
                    || ($type === 'integer' && floor($value) !== (float)$value)
                    || $value < ($rule['minimum'] ?? -INF) || $value > ($rule['maximum'] ?? INF)) {
                return false;
            }
        } else if ($type === 'boolean' && !is_bool($value)) {
            return false;
        }
        return true;
    }

    /** Identical bounded curve subdivision to the browser's scene_geometry module. */
    private static function points(array $path): array {
        $points = [];
        $at = [0, 0];
        $first = $at;
        foreach ($path['segments'] as $segment) {
            $command = $segment['command'];
            $v = $segment['values'];
            $start = $at;
            if ($command === 'M') {
                $at = $v;
                $first = $at;
                $points[] = $at;
            } else if ($command === 'L' || $command === 'Z') {
                $at = $command === 'Z' ? $first : $v;
                $points[] = $at;
            } else {
                for ($i = 1; $i <= 24; $i++) {
                    $t = $i / 24;
                    $u = 1 - $t;
                    $point = [];
                    for ($k = 0; $k < 2; $k++) {
                        $point[] = $command === 'Q'
                            ? $u * $u * $start[$k] + 2 * $u * $t * $v[$k] + $t * $t * $v[$k + 2]
                            : $u ** 3 * $start[$k] + 3 * $u * $u * $t * $v[$k]
                                + 3 * $u * $t * $t * $v[$k + 2] + $t ** 3 * $v[$k + 4];
                    }
                    $points[] = $point;
                }
                $at = end($points);
            }
        }
        return $points;
    }

    /** Arc-length sampling including stable end tangent. */
    private static function sample(array $path, float $progress): array {
        $points = self::points($path);
        $lengths = [];
        for ($i = 1; $i < count($points); $i++) {
            $lengths[] = hypot($points[$i][0] - $points[$i - 1][0], $points[$i][1] - $points[$i - 1][1]);
        }
        $distance = $progress * array_sum($lengths);
        foreach ($lengths as $i => $length) {
            if ($distance <= $length || $i === count($lengths) - 1) {
                $t = $length ? $distance / $length : 0;
                $dx = $points[$i + 1][0] - $points[$i][0];
                $dy = $points[$i + 1][1] - $points[$i][1];
                return ['x' => $points[$i][0] + $dx * $t, 'y' => $points[$i][1] + $dy * $t,
                    'rotation' => atan2($dy, $dx) * 180 / M_PI];
            }
            $distance -= $length;
        }
        return ['x' => $points[0][0], 'y' => $points[0][1], 'rotation' => 0];
    }

    /** Property channels that cannot be written concurrently. */
    private static function channels(array $op): array {
        $map = ['reveal' => ['visible', 'opacity'], 'hide' => ['visible', 'opacity'],
            'draw_path' => ['dash'], 'flow' => ['dash'], 'emit_particles' => ['particles'],
            'merge_particles' => ['particles'], 'split_particles' => ['particles']];
        return $map[$op['type']] ?? array_keys($op['final']);
    }

    /** Validate relationships, topology, animation targets and resource budgets. */
    private static function semantics(array $s): bool {
        if ($s['viewBox'][2] < 1 || $s['viewBox'][3] < 1) {
            return false;
        }
        $all = array_merge($s['layers'], $s['paths'], $s['gradients'], $s['objects']);
        if (count(array_unique(array_column($all, 'id'))) !== count($all)) {
            return false;
        }
        $objects = array_column($s['objects'], null, 'id');
        $paths = array_column($s['paths'], null, 'id');
        $layers = array_column($s['layers'], null, 'id');
        $gradients = array_column($s['gradients'], null, 'id');
        $coordinates = 0;
        foreach ($paths as $p) {
            foreach ($p['segments'] as $i => $seg) {
                $command = $seg['command'];
                if (($i === 0 && $command !== 'M') || ($i > 0 && $command === 'M')
                        || ($command === 'Z' && $i !== count($p['segments']) - 1)
                        || count($seg['values']) !== ['M' => 2, 'L' => 2, 'Q' => 4, 'C' => 6, 'Z' => 0][$command]) {
                    return false;
                }
                $coordinates += count($seg['values']);
            }
            $points = self::points($p);
            $nonzero = false;
            foreach ($points as $point) {
                $nonzero = $nonzero || hypot($point[0] - $points[0][0], $point[1] - $points[0][1]) > 0.000001;
            }
            if (!$nonzero) {
                return false;
            }
        }
        if ($coordinates > 8192) {
            return false;
        }
        foreach ($gradients as $gradient) {
            $last = -1;
            foreach ($gradient['stops'] as $stop) {
                if ($stop['offset'] < $last) {
                    return false;
                }
                $last = $stop['offset'];
            }
        }
        $keys = ['group' => [], 'rect' => ['width', 'height'], 'circle' => ['radius'], 'ellipse' => ['rx', 'ry'],
            'line' => ['x2', 'y2'], 'arrow' => ['x2', 'y2'], 'polygon' => ['points'], 'path' => ['path'], 'text' => [],
            'gear' => ['radius', 'teeth'], 'particles' => ['radius', 'points'], 'connector' => ['from', 'to']];
        $edges = [];
        $particles = [];
        $currentpaths = [];
        foreach ($objects as $id => $o) {
            $actual = array_keys($o['geometry']);
            $expected = $keys[$o['kind']];
            sort($actual);
            sort($expected);
            if ($actual !== $expected || !isset($layers[$o['layer']])
                    || ($o['kind'] === 'polygon' && count($o['geometry']['points']) < 3)
                    || ($o['kind'] === 'path' && !isset($paths[$o['geometry']['path']]))
                    || (isset($o['gradient']) && !isset($gradients[$o['gradient']]))) {
                return false;
            }
            if (isset($o['parent'])) {
                $parent = $objects[$o['parent']] ?? [];
                if (($parent['kind'] ?? '') !== 'group' || $parent['layer'] !== $o['layer'] || $parent['role'] !== $o['role']) {
                    return false;
                }
            }
            $edges[$id] = [];
            foreach (['clip', 'mask'] as $key) {
                if (isset($o[$key])) {
                    $ref = $objects[$o[$key]] ?? [];
                    if (($ref['role'] ?? '') !== $key || isset($ref['parent'])) {
                        return false;
                    }
                    $edges[$id][] = $o[$key];
                }
            }
            foreach (['value' => ['text'], 'connected' => ['connector'],
                'drawProgress' => ['path', 'line', 'arrow', 'polygon', 'connector'],
                'flowOffset' => ['path', 'line', 'arrow', 'polygon']] as $field => $kinds) {
                if (isset($o['initial'][$field]) && !in_array($o['kind'], $kinds, true)) {
                    return false;
                }
            }
            if ($o['kind'] === 'connector') {
                if ($o['role'] !== 'draw' || $o['geometry']['from'] === $o['geometry']['to']) {
                    return false;
                }
                foreach (['from', 'to'] as $field) {
                    $ref = $objects[$o['geometry'][$field]] ?? [];
                    if (!$ref || $ref['kind'] === 'connector' || $ref['role'] !== 'draw') {
                        return false;
                    }
                }
            }
            if ($o['kind'] === 'particles') {
                $particles[$id] = count($o['geometry']['points']);
            }
            if ($o['kind'] === 'path') {
                $currentpaths[$id] = $o['geometry']['path'];
            }
        }
        foreach ($objects as $o) {
            if (isset($o['parent'])) {
                $edges[$o['parent']][] = $o['id'];
            }
        }
        $walk = function ($id, $stack = [], $depth = 1) use (&$walk, $edges): bool {
            if ($depth > 8 || isset($stack[$id])) {
                return false;
            }
            $stack[$id] = true;
            foreach ($edges[$id] as $ref) {
                if (!$walk($ref, $stack, $depth + 1)) {
                    return false;
                }
            }
            return true;
        };
        foreach (array_keys($objects) as $id) {
            if (!$walk($id)) {
                return false;
            }
        }
        $count = 0;
        $particlecount = $particles;
        $positions = [];
        foreach ($objects as $id => $object) {
            $positions[$id] = ['x' => $object['initial']['x'] ?? 0, 'y' => $object['initial']['y'] ?? 0];
        }
        foreach ($s['stages'] as $stage) {
            $count += count($stage['actions']);
            foreach ($stage['actions'] as $i => $a) {
                $op = $a['operation'];
                $type = $op['type'];
                $target = $objects[$a['target']] ?? null;
                if ($a['startMs'] + $a['durationMs'] > $stage['durationMs']
                        || (in_array($type, ['zoom', 'pan'], true) ? $a['target'] !== '@camera' : !$target)) {
                    return false;
                }
                if ($type === 'morph' && ($target['kind'] !== 'path' || !isset($paths[$op['final']['path']]))) {
                    return false;
                }
                if ($type === 'follow_path') {
                    if (!isset($paths[$op['params']['path']])) {
                        return false;
                    }
                    $end = self::sample($paths[$op['params']['path']], $op['params']['to']);
                    if ($op['params']['to'] < $op['params']['from']) {
                        $end['rotation'] += $end['rotation'] > 0 ? -180 : 180;
                    }
                    foreach (['x', 'y', 'rotation'] as $field) {
                        if (($field !== 'rotation' || $op['params']['orient']) && abs($end[$field] - $op['final'][$field]) > 0.001) {
                            return false;
                        }
                    }
                }
                $allowed = ['change_value' => ['text'], 'emit_particles' => ['particles'],
                    'merge_particles' => ['particles'], 'split_particles' => ['particles'],
                    'connect' => ['connector'], 'disconnect' => ['connector'],
                    'draw_path' => ['path', 'line', 'arrow', 'polygon', 'connector'],
                    'flow' => ['path', 'line', 'arrow', 'polygon']];
                if ((isset($allowed[$type]) && !in_array($target['kind'], $allowed[$type], true))
                        || ($type === 'highlight' && $target['kind'] === 'group')
                        || ($type === 'change_colour' && in_array($target['kind'], ['group', 'line', 'arrow', 'connector'], true))
                        || ($type === 'reveal' && !$op['final']['visible']) || ($type === 'hide' && $op['final']['visible'])) {
                    return false;
                }
                if (isset($op['final']['positions'])) {
                    $n = count($op['final']['positions']);
                    if ($type === 'split_particles' && $n < 2) {
                        return false;
                    }
                    $particles[$a['target']] = max($particles[$a['target']], $n);
                }
                foreach (array_slice($stage['actions'], $i + 1) as $b) {
                    if ($a['target'] === $b['target'] && $a['startMs'] < $b['startMs'] + $b['durationMs']
                            && $b['startMs'] < $a['startMs'] + $a['durationMs']
                            && array_intersect(self::channels($op), self::channels($b['operation']))) {
                        return false;
                    }
                }
            }
            $actions = $stage['actions'];
            usort($actions, fn($a, $b) => $a['startMs'] <=> $b['startMs']);
            foreach ($actions as $a) {
                $op = $a['operation'];
                if ($op['type'] === 'follow_path') {
                    $start = self::sample($paths[$op['params']['path']], $op['params']['from']);
                    $before = $positions[$a['target']];
                    if (abs($start['x'] - $before['x']) > 0.001 || abs($start['y'] - $before['y']) > 0.001) {
                        return false;
                    }
                }
                if (in_array($op['type'], ['move', 'follow_path'], true)) {
                    $positions[$a['target']] = ['x' => $op['final']['x'], 'y' => $op['final']['y']];
                }
                if ($op['type'] === 'split_particles' && $particlecount[$a['target']] !== 1) {
                    return false;
                }
                if ($op['type'] === 'merge_particles') {
                    if ($particlecount[$a['target']] < 2) {
                        return false;
                    }
                    $particlecount[$a['target']] = 1;
                }
                if (isset($op['final']['positions'])) {
                    $particlecount[$a['target']] = count($op['final']['positions']);
                }
                if ($a['operation']['type'] === 'morph') {
                    $from = $paths[$currentpaths[$a['target']]]['segments'];
                    $to = $paths[$a['operation']['final']['path']]['segments'];
                    if (array_column($from, 'command') !== array_column($to, 'command')) {
                        return false;
                    }
                    $currentpaths[$a['target']] = $a['operation']['final']['path'];
                }
            }
        }
        return $count <= 192 && array_sum($particles) <= 256;
    }
}
