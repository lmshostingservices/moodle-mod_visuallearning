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
 * Makes an AI-written animated SVG safe to place inside a Moodle page.
 *
 * Only drawing, text, gradient, filter and animation elements are kept, and only attributes that cannot run code or
 * load anything: no scripts, event handlers, foreign objects, images, links or external references of any kind
 * (every href and url() must point inside the SVG). Animations that would change a link or a handler are removed.
 *
 * The result is also scoped, so it cannot affect the rest of the page: the root gets the given id, every id inside
 * is prefixed with it, and every CSS rule and keyframes name in its style sheet is limited to it. The player switches
 * the stages by setting the root's class to stage-1, stage-2, stage-3 or stage-all.
 *
 * @package    mod_visuallearning
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class svg_sanitiser {
    /** @var int Largest SVG accepted (bytes). */
    public const MAX_BYTES = 300000;

    /** @var string SVG namespace. */
    protected const NS = 'http://www.w3.org/2000/svg';

    /** @var string XLink namespace. */
    protected const XLINK = 'http://www.w3.org/1999/xlink';

    /** @var string[] Elements kept. */
    public const ELEMENTS = ['svg', 'g', 'defs', 'symbol', 'use', 'title', 'desc', 'style', 'path', 'rect', 'circle',
        'ellipse', 'line', 'polyline', 'polygon', 'text', 'tspan', 'textPath', 'linearGradient', 'radialGradient', 'stop',
        'clipPath', 'mask', 'pattern', 'marker', 'filter', 'feDropShadow', 'feGaussianBlur', 'feOffset', 'feFlood',
        'feComposite', 'feMerge', 'feMergeNode', 'feBlend', 'feColorMatrix', 'feMorphology', 'animate',
        'animateTransform', 'animateMotion', 'mpath', 'set'];

    /** @var string[] Animation elements, whose target attribute is checked. */
    protected const ANIMATIONS = ['animate', 'animateTransform', 'animateMotion', 'set'];

    /** @var string[] Attributes that refer to ids by a space-separated list. */
    protected const IDLISTS = ['aria-labelledby', 'aria-describedby'];

    /**
     * Cleans and scopes an SVG.
     *
     * @param string $svg the SVG text (an XML declaration and surrounding whitespace are allowed; markdown fences too)
     * @param string $scope id for the root (letters, digits and hyphens, starting with a letter)
     * @return string|null the clean SVG, or null when it is not a usable SVG
     */
    public static function clean(string $svg, string $scope): ?string {
        if (!preg_match('/^[a-z][a-z0-9-]{0,40}$/i', $scope)) {
            throw new \coding_exception('Bad SVG scope');
        }
        $svg = trim($svg);
        // A model sometimes wraps its answer in a code fence; take what is inside.
        if (preg_match('/^```[a-z]*\s*(.*?)\s*```$/is', $svg, $m)) {
            $svg = trim($m[1]);
        }
        if ($svg === '' || strlen($svg) > self::MAX_BYTES) {
            return null;
        }
        // No document type: entities could expand or load other files.
        if (stripos($svg, '<!DOCTYPE') !== false || stripos($svg, '<!ENTITY') !== false) {
            return null;
        }
        $start = stripos($svg, '<svg');
        if ($start === false) {
            return null;
        }
        $svg = substr($svg, $start);
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $ok = $doc->loadXML($svg, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $ok ? $doc->documentElement : null;
        if (!$root || $root->localName !== 'svg' || $root->namespaceURI !== self::NS) {
            return null;
        }
        $viewbox = preg_split('/[\s,]+/', trim((string)$root->getAttribute('viewBox')));
        if (count($viewbox) !== 4 || !is_numeric($viewbox[2]) || !is_numeric($viewbox[3]) || $viewbox[2] <= 0
                || $viewbox[3] <= 0) {
            return null;
        }

        self::clean_node($root);

        // Scope the ids, and every reference to them.
        $ids = [];
        $xpath = new \DOMXPath($doc);
        foreach ($xpath->query('//*[@id]') as $el) {
            $old = (string)$el->getAttribute('id');
            if ($el === $root || !preg_match('/^[A-Za-z_][A-Za-z0-9_.:-]*$/', $old)) {
                $el->removeAttribute('id');
                continue;
            }
            $ids[$old] = $scope . '-' . $old;
            $el->setAttribute('id', $ids[$old]);
        }
        foreach ($xpath->query('//*') as $el) {
            foreach (iterator_to_array($el->attributes) as $attr) {
                $name = $attr->nodeName;
                $value = (string)$attr->nodeValue;
                if ($name === 'href' || $name === 'xlink:href') {
                    $target = substr($value, 1);
                    if (!isset($ids[$target])) {
                        $el->removeAttributeNode($attr);
                        continue;
                    }
                    $el->removeAttributeNode($attr);
                    $el->setAttribute('href', '#' . $ids[$target]);
                } else if (in_array($name, self::IDLISTS, true)) {
                    $list = array_filter(array_map(fn($id) => $ids[$id] ?? null, preg_split('/\s+/', trim($value))));
                    $el->setAttribute($name, implode(' ', $list));
                } else if ($name === 'begin' || $name === 'end') {
                    $el->setAttribute($name, preg_replace_callback('/(?<![\w.-])([A-Za-z_][\w-]*)\.(begin|end|repeat)/',
                        fn($m) => isset($ids[$m[1]]) ? $ids[$m[1]] . '.' . $m[2] : $m[0], $value));
                } else if (str_contains($value, 'url(')) {
                    $el->setAttribute($name, self::scope_urls($value, $ids));
                }
            }
        }

        // Scope the style sheet.
        $keyframes = [];
        foreach ($xpath->query('//*[local-name()="style"]') as $style) {
            if (preg_match_all('/@(?:-webkit-)?keyframes\s+([A-Za-z_][\w-]*)/', $style->textContent, $m)) {
                foreach ($m[1] as $name) {
                    $keyframes[$name] = $scope . '-' . $name;
                }
            }
        }
        foreach ($xpath->query('//*[local-name()="style"]') as $style) {
            $css = self::clean_css($style->textContent);
            $css = self::scope_urls($css, $ids);
            $css = self::rename_keyframes($css, $keyframes);
            $css = self::scope_css($css, $scope);
            while ($style->firstChild) {
                $style->removeChild($style->firstChild);
            }
            $style->appendChild($doc->createTextNode($css));
        }
        foreach ($xpath->query('//*[@style]') as $el) {
            $inline = self::rename_keyframes(self::scope_urls(self::clean_css((string)$el->getAttribute('style')), $ids),
                $keyframes);
            $el->setAttribute('style', $inline);
        }

        $root->setAttribute('id', $scope);
        $classes = preg_split('/\s+/', trim((string)$root->getAttribute('class')));
        if (!preg_grep('/^stage-/', $classes)) {
            $classes[] = 'stage-all';
        }
        $root->setAttribute('class', trim(implode(' ', $classes)));
        $root->removeAttribute('width');
        $root->removeAttribute('height');
        if (!$root->hasAttribute('role')) {
            $root->setAttribute('role', 'img');
        }
        $out = $doc->saveXML($root);
        return $out !== false && strlen($out) <= self::MAX_BYTES ? $out : null;
    }

    /**
     * Removes what is not allowed below (and on) one element.
     *
     * @param \DOMElement $el
     */
    protected static function clean_node(\DOMElement $el): void {
        foreach (iterator_to_array($el->attributes) as $attr) {
            if (!self::attribute_allowed($el, $attr)) {
                $el->removeAttributeNode($attr);
            }
        }
        foreach (iterator_to_array($el->childNodes) as $child) {
            if ($child instanceof \DOMElement) {
                $keep = $child->namespaceURI === self::NS && in_array($child->localName, self::ELEMENTS, true);
                if ($keep && in_array($child->localName, self::ANIMATIONS, true)) {
                    $target = strtolower(trim((string)$child->getAttribute('attributeName')));
                    $keep = !in_array($target, ['href', 'xlink:href', 'style', 'class', 'id'], true)
                        && !str_starts_with($target, 'on');
                }
                if ($keep) {
                    self::clean_node($child);
                } else {
                    $el->removeChild($child);
                }
            } else if ($child instanceof \DOMText || $child instanceof \DOMCdataSection) {
                if ($child instanceof \DOMCdataSection) {
                    $el->replaceChild($el->ownerDocument->createTextNode($child->data), $child);
                }
            } else {
                // Comments, processing instructions and the rest.
                $el->removeChild($child);
            }
        }
    }

    /**
     * Whether an attribute may stay.
     *
     * @param \DOMElement $el
     * @param \DOMAttr $attr
     * @return bool
     */
    protected static function attribute_allowed(\DOMElement $el, \DOMAttr $attr): bool {
        $name = $attr->nodeName;
        $local = strtolower($attr->localName);
        $value = (string)$attr->nodeValue;
        if ($attr->namespaceURI !== null && $attr->namespaceURI !== self::XLINK) {
            return $name === 'xml:space';
        }
        if ($attr->namespaceURI === self::XLINK && $local !== 'href') {
            return false;
        }
        if (str_starts_with($local, 'on')) {
            return false;
        }
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9:_-]*$/', $name)) {
            return false;
        }
        $flat = strtolower(preg_replace('/[\s\x00-\x1f]+/', '', html_entity_decode($value, ENT_QUOTES | ENT_HTML5)));
        foreach (['javascript:', 'vbscript:', 'data:', 'expression(', '<script'] as $bad) {
            if (str_contains($flat, $bad)) {
                return false;
            }
        }
        if ($local === 'href') {
            return preg_match('/^#[A-Za-z_][A-Za-z0-9_.:-]*$/', trim($value)) === 1;
        }
        if (str_contains($flat, 'url(') && preg_match('/url\(\s*[\'"]?(?!#)/i', $value)) {
            return false;
        }
        return strlen($value) <= 20000;
    }

    /**
     * Removes anything from CSS that could load or run something.
     *
     * @param string $css
     * @return string
     */
    public static function clean_css(string $css): string {
        $css = preg_replace('~/\*.*?\*/~s', '', $css);
        $css = str_replace('<', '', $css);
        // At-rules other than keyframes and media are dropped with their block or statement.
        $css = preg_replace('/@(?!(?:-webkit-)?keyframes\b|media\b)[a-z-]+[^;{]*(;|\{[^{}]*(\{[^{}]*\}[^{}]*)*\})/i', '', $css);
        // Any url() that does not point inside the SVG, and old IE script hooks.
        $css = preg_replace('/url\(\s*[\'"]?(?!#)[^)]*\)/i', 'none', $css);
        $css = preg_replace('/(expression|javascript|vbscript|behavior|-moz-binding)\s*[:(]/i', 'x-blocked(', $css);
        return trim($css);
    }

    /**
     * Points url(#id) references at the scoped ids (others become none).
     *
     * @param string $text
     * @param array $ids old id => new id
     * @return string
     */
    protected static function scope_urls(string $text, array $ids): string {
        return preg_replace_callback('/url\(\s*[\'"]?#([^\'")\s]+)[\'"]?\s*\)/', fn($m) => isset($ids[$m[1]])
            ? 'url(#' . $ids[$m[1]] . ')' : 'none', $text);
    }

    /**
     * Renames keyframes and their uses.
     *
     * @param string $css
     * @param array $names old => new
     * @return string
     */
    protected static function rename_keyframes(string $css, array $names): string {
        if (!$names) {
            return $css;
        }
        $css = preg_replace_callback('/@((?:-webkit-)?keyframes)\s+([A-Za-z_][\w-]*)/',
            fn($m) => '@' . $m[1] . ' ' . ($names[$m[2]] ?? $m[2]), $css);
        return preg_replace_callback('/(animation(?:-name)?\s*:)([^;}]*)/i', function ($m) use ($names) {
            return $m[1] . preg_replace_callback('/(?<![\w-])([A-Za-z_][\w-]*)(?![\w-])/',
                fn($w) => $names[$w[1]] ?? $w[1], $m[2]);
        }, $css);
    }

    /**
     * Limits every rule of a style sheet to the SVG with this id. Selectors that start with a stage class
     * (".stage-1 .x") are about the root itself and become "#scope.stage-1 .x".
     *
     * @param string $css
     * @param string $scope
     * @return string
     */
    public static function scope_css(string $css, string $scope): string {
        $out = '';
        $len = strlen($css);
        $i = 0;
        while ($i < $len) {
            $open = strpos($css, '{', $i);
            if ($open === false) {
                break;
            }
            $prelude = trim(substr($css, $i, $open - $i));
            // The matching closing brace.
            $depth = 0;
            for ($j = $open; $j < $len; $j++) {
                if ($css[$j] === '{') {
                    $depth++;
                } else if ($css[$j] === '}') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                }
            }
            $body = substr($css, $open + 1, max(0, $j - $open - 1));
            $i = $j + 1;
            if ($prelude === '') {
                continue;
            }
            if (preg_match('/^@(-webkit-)?keyframes\b/i', $prelude)) {
                $out .= $prelude . '{' . $body . "}\n";
            } else if (preg_match('/^@media\b/i', $prelude)) {
                $out .= $prelude . '{' . self::scope_css($body, $scope) . "}\n";
            } else if ($prelude[0] !== '@') {
                $selectors = [];
                foreach (explode(',', $prelude) as $sel) {
                    $sel = trim($sel);
                    if ($sel === '') {
                        continue;
                    }
                    if (preg_match('/^(svg|:root)\b/i', $sel)) {
                        $sel = preg_replace('/^(svg|:root)/i', '#' . $scope, $sel);
                    } else if (preg_match('/^\.stage-[\w-]+/', $sel)) {
                        $sel = '#' . $scope . $sel;
                    } else {
                        $sel = '#' . $scope . ' ' . $sel;
                    }
                    $selectors[] = $sel;
                }
                if ($selectors) {
                    $out .= implode(',', $selectors) . '{' . $body . "}\n";
                }
            }
        }
        return trim($out);
    }
}
