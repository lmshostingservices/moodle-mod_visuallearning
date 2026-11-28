<?php
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

namespace mod_visuallearning\local;

/**
 * Machine-readable "AI-generated" marking of the media LMS Labs makes (EU AI Act, Article 50(2)).
 *
 * Adds an IPTC Digital Source Type of "trainedAlgorithmicMedia" with provenance details to every picture, voice clip
 * and animated diagram before it is stored: XMP in PNG, JPEG and WebP, an ID3 tag in MP3, and RDF metadata in SVG.
 * Markings already in a file (a signed C2PA manifest, or XMP or ID3 from LMS Labs or the model provider) are never
 * removed or replaced: a file that already carries XMP or an ID3 tag is stored unchanged. This metadata layer
 * supports, and does not replace, the signed metadata and watermark layers applied by LMS Labs.
 *
 * @package    mod_visuallearning
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_marking {
    /** @var string IPTC Digital Source Type for media made by a trained AI model. */
    public const SOURCE_TYPE = 'http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia';

    /** @var string Name of the generating system, as written in the marking. */
    public const SYSTEM = 'AI Visual Learning (mod_visuallearning) with LMS Labs AI';

    /** @var string Provider of the generating system. */
    public const PROVIDER = 'LMS Hosting Services (LMS Labs)';

    /** @var string Generating system when the site's own AI made the media (Bring your own AI). */
    public const SYSTEM_OWNAI = 'AI Visual Learning (mod_visuallearning) with the site\'s own AI';

    /** @var string Provider when the site's own AI made the media. */
    public const PROVIDER_OWNAI = 'The site\'s own AI provider (LMS Labs Bring your own AI)';

    /** @var string Namespace of the provenance fields. */
    public const NS = 'https://lms-labs.com/ns/ai-provenance/1.0/';

    /**
     * Whether stored AI media is marked (site setting, on by default).
     *
     * @return bool
     */
    public static function enabled(): bool {
        $value = get_config('mod_visuallearning', 'aimarking');
        return $value === false || $value === null || $value === '' || (bool)$value;
    }

    /**
     * Provenance details written into the marking.
     *
     * @param string $kind picture, voice or animation
     * @return array
     */
    public static function provenance(string $kind): array {
        // With "Bring your own AI", the site's own provider makes this kind of media, not LMS Labs.
        $ownkind = ['picture' => 'image', 'voice' => 'voice', 'animation' => 'text'][$kind] ?? '';
        $ownai = $ownkind !== '' && \mod_visuallearning\byok::uses($ownkind);
        return [
            'kind' => $kind,
            'system' => $ownai ? self::SYSTEM_OWNAI : self::SYSTEM,
            'provider' => $ownai ? self::PROVIDER_OWNAI : self::PROVIDER,
            'created' => gmdate('Y-m-d\TH:i:s\Z'),
            'version' => (string)(\core_plugin_manager::instance()->get_plugin_info('mod_visuallearning')->release ?? ''),
        ];
    }

    /**
     * The file with an AI-generated marking added, or unchanged when it is already marked or the format is unknown.
     *
     * @param string $bytes File content
     * @param string $mime MIME type
     * @param array $provenance From {@see provenance()}
     * @return string
     */
    public static function mark(string $bytes, string $mime, array $provenance): string {
        if (self::has_c2pa($bytes)) {
            // A signed C2PA manifest binds the file's bytes: any change would break the signature.
            return $bytes;
        }
        try {
            switch ($mime) {
                case 'image/png':
                    return self::mark_png($bytes, $provenance);
                case 'image/jpeg':
                    return self::mark_jpeg($bytes, $provenance);
                case 'image/webp':
                    return self::mark_webp($bytes, $provenance);
                case 'audio/mpeg':
                    return self::mark_mp3($bytes, $provenance);
                case 'image/svg+xml':
                    return self::mark_svg($bytes, $provenance);
            }
        } catch (\Throwable $e) {
            debugging('AI Visual Learning could not mark a ' . $mime . ' file: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
        return $bytes;
    }

    /**
     * Whether a file carries an AI-generated marking (IPTC trainedAlgorithmicMedia) from any source.
     *
     * @param string $bytes
     * @return bool
     */
    public static function is_marked(string $bytes): bool {
        return str_contains($bytes, 'digitalsourcetype/trainedAlgorithmicMedia')
            || str_contains($bytes, 'digitalsourcetype/compositeWithTrainedAlgorithmicMedia');
    }

    /**
     * Whether a file carries a C2PA manifest (JUMBF box labelled c2pa, or the PNG caBX / WebP C2PA chunk).
     *
     * @param string $bytes
     * @return bool
     */
    public static function has_c2pa(string $bytes): bool {
        return str_contains($bytes, 'caBX') || str_contains($bytes, 'jumb') || stripos($bytes, 'c2pa') !== false;
    }

    /**
     * The XMP packet: IPTC Digital Source Type plus provenance.
     *
     * @param array $p Provenance
     * @return string
     */
    public static function xmp(array $p): string {
        $e = fn($v) => htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        return '<?xpacket begin="' . "\u{FEFF}" . '" id="W5M0MpCehiHzreSzNTczkc9d"?>'
            . '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
            . '<rdf:Description rdf:about=""'
            . ' xmlns:Iptc4xmpExt="http://iptc.org/std/Iptc4xmpExt/2008-02-29/"'
            . ' xmlns:xmp="http://ns.adobe.com/xap/1.0/"'
            . ' xmlns:dc="http://purl.org/dc/elements/1.1/"'
            . ' xmlns:lmslabs="' . self::NS . '"'
            . ' Iptc4xmpExt:DigitalSourceType="' . self::SOURCE_TYPE . '"'
            . ' xmp:CreatorTool="' . $e($p['system']) . '"'
            . ' xmp:CreateDate="' . $e($p['created']) . '"'
            . ' lmslabs:AIGenerated="true"'
            . ' lmslabs:Kind="' . $e($p['kind']) . '"'
            . ' lmslabs:Provider="' . $e($p['provider']) . '"'
            . ' lmslabs:PluginRelease="' . $e($p['version']) . '">'
            . '<dc:description><rdf:Alt><rdf:li xml:lang="x-default">' . $e(self::statement($p)) . '</rdf:li></rdf:Alt>'
            . '</dc:description></rdf:Description></rdf:RDF></x:xmpmeta><?xpacket end="r"?>';
    }

    /**
     * One plain sentence describing the marking.
     *
     * @param array $p Provenance
     * @return string
     */
    public static function statement(array $p): string {
        return 'AI-generated ' . $p['kind'] . ', made by ' . $p['system'] . ' (' . $p['provider'] . ').';
    }

    /**
     * PNG: an iTXt chunk with the XMP packet, after IHDR. Unchanged when the file already has XMP or a C2PA manifest.
     *
     * @param string $bytes
     * @param array $p
     * @return string
     */
    protected static function mark_png(string $bytes, array $p): string {
        if (substr($bytes, 0, 8) !== "\x89PNG\r\n\x1a\n" || substr($bytes, 12, 4) !== 'IHDR') {
            return $bytes;
        }
        if (str_contains($bytes, 'XML:com.adobe.xmp') || str_contains($bytes, 'caBX')) {
            return $bytes;
        }
        $ihdrend = 8 + 12 + unpack('N', substr($bytes, 8, 4))[1];
        $data = "XML:com.adobe.xmp\0\0\0\0\0" . self::xmp($p);
        $chunk = pack('N', strlen($data)) . 'iTXt' . $data . pack('N', crc32('iTXt' . $data));
        return substr($bytes, 0, $ihdrend) . $chunk . substr($bytes, $ihdrend);
    }

    /**
     * JPEG: an APP1 XMP segment after SOI and any APP0/APP1 (Exif) segments. Unchanged when XMP is already present.
     *
     * @param string $bytes
     * @param array $p
     * @return string
     */
    protected static function mark_jpeg(string $bytes, array $p): string {
        if (substr($bytes, 0, 2) !== "\xFF\xD8") {
            return $bytes;
        }
        if (str_contains($bytes, "http://ns.adobe.com/xap/1.0/\0")) {
            return $bytes;
        }
        $payload = "http://ns.adobe.com/xap/1.0/\0" . self::xmp($p);
        if (strlen($payload) + 2 > 65535) {
            return $bytes;
        }
        $segment = "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;
        $at = 2;
        while ($at + 4 <= strlen($bytes) && $bytes[$at] === "\xFF" && in_array($bytes[$at + 1], ["\xE0", "\xE1"], true)) {
            $at += 2 + unpack('n', substr($bytes, $at + 2, 2))[1];
        }
        return substr($bytes, 0, $at) . $segment . substr($bytes, $at);
    }

    /**
     * WebP: an "XMP " chunk, adding the extended (VP8X) header when needed. Unchanged when XMP is already present.
     *
     * @param string $bytes
     * @param array $p
     * @return string
     */
    protected static function mark_webp(string $bytes, array $p): string {
        if (substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 4) !== 'WEBP' || str_contains($bytes, 'XMP ')) {
            return $bytes;
        }
        $first = substr($bytes, 12, 4);
        $body = substr($bytes, 12);
        if ($first === 'VP8X') {
            $flags = ord($body[8]) | 0x04;
            $body = substr($body, 0, 8) . chr($flags) . substr($body, 9);
        } else if ($first === 'VP8 ' || $first === 'VP8L') {
            $size = getimagesizefromstring($bytes);
            if (!$size) {
                return $bytes;
            }
            $alpha = 0;
            if ($first === 'VP8L') {
                // Bit 28 of the VP8L header (after the signature byte) says whether the image uses alpha.
                $header = unpack('V', substr($bytes, 21, 4))[1];
                $alpha = ($header >> 28) & 1 ? 0x10 : 0;
            }
            $w = $size[0] - 1;
            $h = $size[1] - 1;
            $vp8x = 'VP8X' . pack('V', 10) . chr(0x04 | $alpha) . "\0\0\0"
                . chr($w & 0xFF) . chr(($w >> 8) & 0xFF) . chr(($w >> 16) & 0xFF)
                . chr($h & 0xFF) . chr(($h >> 8) & 0xFF) . chr(($h >> 16) & 0xFF);
            $body = $vp8x . $body;
        } else {
            return $bytes;
        }
        $xmp = self::xmp($p);
        $chunk = 'XMP ' . pack('V', strlen($xmp)) . $xmp . (strlen($xmp) % 2 ? "\0" : '');
        $body .= $chunk;
        return 'RIFF' . pack('V', 4 + strlen($body)) . 'WEBP' . $body;
    }

    /**
     * MP3: an ID3v2.3 tag in front with TXXX frames and a comment. Unchanged when the clip already has an ID3v2 tag.
     *
     * @param string $bytes
     * @param array $p
     * @return string
     */
    protected static function mark_mp3(string $bytes, array $p): string {
        if (substr($bytes, 0, 3) === 'ID3') {
            return $bytes;
        }
        $frame = function (string $id, string $data): string {
            return $id . pack('N', strlen($data)) . "\0\0" . $data;
        };
        // ID3v2.3 text encoding 0 (ISO-8859-1): every value written here is plain ASCII.
        $txxx = fn(string $name, string $value) => $frame('TXXX', "\0" . $name . "\0" . $value);
        $frames = $frame('TSSE', "\0" . $p['system'])
            . $txxx('AI_GENERATED', 'true')
            . $txxx('DIGITAL_SOURCE_TYPE', self::SOURCE_TYPE)
            . $txxx('AI_PROVIDER', $p['provider'])
            . $txxx('AI_CREATED', $p['created'])
            . $frame('COMM', "\0" . 'eng' . "\0" . self::statement($p));
        $size = strlen($frames);
        $syncsafe = chr(($size >> 21) & 0x7F) . chr(($size >> 14) & 0x7F) . chr(($size >> 7) & 0x7F) . chr($size & 0x7F);
        return 'ID3' . "\3\0\0" . $syncsafe . $frames . $bytes;
    }

    /**
     * SVG: an RDF metadata element as the first child of the root element. Unchanged when it already has one.
     *
     * @param string $bytes
     * @param array $p
     * @return string
     */
    protected static function mark_svg(string $bytes, array $p): string {
        if (self::is_marked($bytes) || !preg_match('/<svg\b[^>]*>/i', $bytes, $m, PREG_OFFSET_CAPTURE)) {
            return $bytes;
        }
        $e = fn($v) => htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $meta = '<metadata><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"'
            . ' xmlns:Iptc4xmpExt="http://iptc.org/std/Iptc4xmpExt/2008-02-29/" xmlns:lmslabs="' . self::NS . '">'
            . '<rdf:Description rdf:about="" Iptc4xmpExt:DigitalSourceType="' . self::SOURCE_TYPE . '"'
            . ' lmslabs:AIGenerated="true" lmslabs:Kind="' . $e($p['kind']) . '"'
            . ' lmslabs:Provider="' . $e($p['provider']) . '" lmslabs:Created="' . $e($p['created']) . '"/>'
            . '</rdf:RDF></metadata>';
        $end = $m[0][1] + strlen($m[0][0]);
        if (str_ends_with($m[0][0], '/>')) {
            return $bytes;
        }
        return substr($bytes, 0, $end) . $meta . substr($bytes, $end);
    }
}
