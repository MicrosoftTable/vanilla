<?php
namespace Vanilla;

require_once __DIR__ . '/BrickColors.php';

// reads xml and binary roblox models into one tree
final class Rbxm {
    const MAX_BYTES = 16777216, MAX_DEPTH = 200, MAX_ELEMENTS = 500000, MAX_INSTANCES = 100000;

    static function brickColor($n) {
        $c = BrickColors::rgb($n);
        return ['r' => $c[0] / 255, 'g' => $c[1] / 255, 'b' => $c[2] / 255];
    }

    static function parse(string $bytes): array {
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new \RuntimeException('model too large');
        }
        if (strlen($bytes) > 8 && substr($bytes, 0, 8) === '<roblox!') {
            return self::parseBinary($bytes);
        }
        return self::parseXML($bytes);
    }

    private static function unesc(string $s): string {
        return preg_replace_callback('/&(#x[0-9a-fA-F]+|#\d+|[a-z]+);/', function ($m) {
            $e = $m[1];
            if ($e[0] === '#') {
                if (strlen($e) > 9) {
                    return '';
                }
                $cp = $e[1] === 'x' ? hexdec(substr($e, 2)) : (int)substr($e, 1);
                if ($cp < 1 || $cp > 0x10FFFF || ($cp >= 0xD800 && $cp <= 0xDFFF)) {
                    return '';
                }
                return function_exists('mb_chr') ? (mb_chr($cp, 'UTF-8') ?: '') : html_entity_decode(
                    '&#' . $cp . ';',
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8'
                );
            }
            $ent = ['lt' => '<', 'gt' => '>', 'amp' => '&', 'quot' => '"', 'apos' => "'"];
            return $ent[$e] ?? $m[0];
        }, $s);
    }

    private static function xmlTree(string $src): array {
        $re = '/<!\[CDATA\[([\s\S]*?)\]\]>|<!--[\s\S]*?-->|<\?[\s\S]*?\?>|<!DOCTYPE[^>]*>|<(\/?)([A-Za-z_][\w.:-]*)((?:\s+[\w.:-]+\s*=\s*(?:"[^"]*"|\'[^\']*\'))*)\s*(\/?)>|([^<]+)/';
        // no dtds or entity declarations: nothing in a model needs them
        if (stripos($src, '<!DOCTYPE') !== false || stripos($src, '<!ENTITY') !== false) {
            throw new \RuntimeException('xml with a doctype is not accepted');
        }
        $root = ['tag' => '#root', 'attrs' => [], 'kids' => [], 'text' => ''];
        $stack = [&$root];
        if (preg_match_all($re, $src, $all, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL) === false) {
            throw new \RuntimeException('xml could not be read');
        }
        $count = 0;
        foreach ($all as $m) {
            $cur = &$stack[count($stack) - 1];
            if ($m[1] !== null) {
                $cur['text'] .= $m[1];
                unset($cur);
                continue;
            }
            if (($m[6] ?? null) !== null) {
                $cur['text'] .= self::unesc($m[6]);
                unset($cur);
                continue;
            }
            if (($m[3] ?? null) === null) {
                unset($cur);
                continue;
            }
            if ($m[2] === '/') {
                if (count($stack) > 1) {
                    array_pop($stack);
                }
                unset($cur);
                continue;
            }
            if (++$count > self::MAX_ELEMENTS) {
                throw new \RuntimeException('xml has too many elements');
            }
            $el = ['tag' => $m[3], 'attrs' => [], 'kids' => [], 'text' => ''];
            if (preg_match_all('/([\w.:-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/', $m[4] ?? '', $am, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL)) {
                foreach ($am as $a) {
                    $el['attrs'][$a[1]] = self::unesc($a[2] !== null ? $a[2] : ($a[3] ?? ''));
                }
            }
            $cur['kids'][] = $el;
            if (!$m[5]) {
                if (count($stack) > self::MAX_DEPTH) {
                    throw new \RuntimeException('xml is nested too deeply');
                }
                $stack[] = &$cur['kids'][count($cur['kids']) - 1];
            }
            unset($cur);
        }
        return $root;
    }

    private static function child(array $el, string $tag) {
        foreach ($el['kids'] as $k) {
            if ($k['tag'] === $tag) {
                return $k;
            }
        }
        return null;
    }

    private static function pf($t) {
        $t = trim((string)$t);
        if (preg_match('/^-?inf/i', $t)) {
            return $t[0] === '-' ? -INF : INF;
        }
        if (preg_match('/^-?nan/i', $t)) {
            return NAN;
        }
        return preg_match('/^\s*[-+]?(\d+\.?\d*|\.\d+)([eE][-+]?\d+)?/', $t, $m) ? (float)$m[0] : NAN;
    }

    private static function num(array $el, string $tag, $d) {
        $c = self::child($el, $tag);
        return $c ? self::pf($c['text']) : $d;
    }

    private static function xmlProp(array $el) {
        $t = $el['tag'];
        $txt = trim($el['text']);
        switch ($t) {
            case 'string':
            case 'ProtectedString':
                return $el['text'];
            case 'bool':
                return $txt === 'true';
            case 'int':
            case 'int64':
            case 'token':
            case 'float':
            case 'double':
                return self::pf($txt);
            case 'BrickColor':
                $n = (int)$txt;
                return ['brick' => $n, 'color' => self::brickColor($n)];
            case 'Color3':
                if (self::child($el, 'R')) {
                    return [
                        'r' => self::num($el, 'R', 0),
                        'g' => self::num($el, 'G', 0),
                        'b' => self::num($el, 'B', 0)
                    ];
                }
                $u = ((int)$txt) & 0xffffffff;
                return ['r' => (($u >> 16) & 255) / 255, 'g' => (($u >> 8) & 255) / 255, 'b' => ($u & 255) / 255];
            case 'Color3uint8':
                $v = ((int)$txt) & 0xffffffff;
                return ['r' => (($v >> 16) & 255) / 255, 'g' => (($v >> 8) & 255) / 255, 'b' => ($v & 255) / 255];
            case 'Vector3':
                return ['x' => self::num($el, 'X', 0), 'y' => self::num($el, 'Y', 0), 'z' => self::num($el, 'Z', 0)];
            case 'Vector2':
                return ['x' => self::num($el, 'X', 0), 'y' => self::num($el, 'Y', 0)];
            case 'CoordinateFrame':
            case 'CFrame':
                $m = [];
                foreach (['R00', 'R01', 'R02', 'R10', 'R11', 'R12', 'R20', 'R21', 'R22'] as $i => $k) {
                    $m[] = self::num($el, $k, $i % 4 === 0 ? 1 : 0);
                }
                return [
                    'x' => self::num($el, 'X', 0),
                    'y' => self::num($el, 'Y', 0),
                    'z' => self::num($el, 'Z', 0),
                    'm' => $m
                ];
            case 'Ref':
                return ['ref' => $txt];
            case 'Content':
            case 'url':
                $u2 = self::child($el, 'url') ?: self::child($el, 'uri');
                return ['url' => $u2 ? trim($u2['text']) : (count($el['kids']) ? '' : $txt)];
            default:
                return null;
        }
    }

    static function parseXML(string $src): array {
        $doc = self::xmlTree($src);
        $rt = self::child($doc, 'roblox');
        if (!$rt) {
            throw new \RuntimeException('not a Roblox XML model');
        }
        $byRef = [];
        $roots = [];
        $item = function (array $el, $parent) use (&$item, &$byRef) {
            $inst = new Inst($el['attrs']['class'] ?? '', $el['attrs']['referent'] ?? null);
            $inst->parent = $parent;
            $pr = self::child($el, 'Properties');
            if ($pr) {
                foreach ($pr['kids'] as $p) {
                    $v = self::xmlProp($p);
                    if ($v !== null && isset($p['attrs']['name'])) {
                        $inst->props[$p['attrs']['name']] = $v;
                    }
                }
            }
            self::aliasContent($inst->props);
            $inst->name = is_string($inst->props['Name'] ?? null) ? $inst->props['Name'] : $inst->cls;
            if ($inst->ref !== null) {
                $byRef[$inst->ref] = $inst;
            }
            foreach ($el['kids'] as $k) {
                if ($k['tag'] === 'Item') {
                    $inst->children[] = $item($k, $inst);
                }
            }
            return $inst;
        };
        foreach ($rt['kids'] as $k) {
            if ($k['tag'] === 'Item') {
                $roots[] = $item($k, null);
            }
        }
        return ['roots' => $roots, 'byRef' => $byRef];
    }

    private static function lz4(string $src, int $outLen): string {
        $out = '';
        $i = 0;
        $n = strlen($src);
        while ($i < $n) {
            $tok = ord($src[$i++]);
            $lit = $tok >> 4;
            if ($lit === 15) {
                do {
                    $k = ord($src[$i++]);
                    $lit += $k;
                } while ($k === 255);
            }
            $out .= substr($src, $i, $lit);
            $i += $lit;
            if ($i >= $n) {
                break;
            }
            $off = ord($src[$i]) | (ord($src[$i + 1]) << 8);
            $i += 2;
            $ml = $tok & 15;
            if ($ml === 15) {
                do {
                    $k = ord($src[$i++]);
                    $ml += $k;
                } while ($k === 255);
            }
            $ml += 4;
            $o = strlen($out);
            if ($off === 0 || $off > $o) {
                throw new \RuntimeException('bad LZ4 offset');
            }
            if ($off >= $ml) {
                $out .= substr($out, $o - $off, $ml);
            } else {
                for ($k = 0; $k < $ml; $k++) {
                    $out .= $out[strlen($out) - $off];
                }
            }
            if (strlen($out) > $outLen) {
                throw new \RuntimeException('LZ4 size mismatch');
            }
        }
        if (strlen($out) !== $outLen) {
            throw new \RuntimeException('LZ4 size mismatch');
        }
        return $out;
    }

    const ROT = [
        0x02 => [1, 0, 0, 0, 1, 0, 0, 0, 1], 0x03 => [1, 0, 0, 0, 0, -1, 0, 1, 0], 0x05 => [1, 0, 0, 0, -1, 0, 0, 0, -1], 0x06 => [1, 0, 0, 0, 0, 1, 0, -1, 0],
        0x07 => [0, 1, 0, 1, 0, 0, 0, 0, -1], 0x09 => [0, 0, 1, 1, 0, 0, 0, 1, 0], 0x0a => [0, -1, 0, 1, 0, 0, 0, 0, 1], 0x0c => [0, 0, -1, 1, 0, 0, 0, -1, 0],
        0x0d => [0, 1, 0, 0, 0, 1, 1, 0, 0], 0x0e => [0, 0, -1, 0, 1, 0, 1, 0, 0], 0x10 => [0, -1, 0, 0, 0, -1, 1, 0, 0], 0x11 => [0, 0, 1, 0, -1, 0, 1, 0, 0],
        0x14 => [-1, 0, 0, 0, 1, 0, 0, 0, -1], 0x15 => [-1, 0, 0, 0, 0, 1, 0, 1, 0], 0x17 => [-1, 0, 0, 0, -1, 0, 0, 0, 1], 0x18 => [-1, 0, 0, 0, 0, -1, 0, -1, 0],
        0x19 => [0, 1, 0, -1, 0, 0, 0, 0, 1], 0x1b => [0, 0, -1, -1, 0, 0, 0, 1, 0], 0x1c => [0, -1, 0, -1, 0, 0, 0, 0, -1], 0x1e => [0, 0, 1, -1, 0, 0, 0, -1, 0],
        0x1f => [0, 1, 0, 0, 0, -1, -1, 0, 0], 0x20 => [0, 0, 1, 0, 1, 0, -1, 0, 0], 0x22 => [0, -1, 0, 0, 0, 1, -1, 0, 0], 0x23 => [0, 0, -1, 0, -1, 0, -1, 0, 0]];
    const CONTENT_PROPS = ['MeshId' => 1, 'TextureId' => 1, 'TextureID' => 1, 'Texture' => 1, 'ColorMap' => 1, 'NormalMap' => 1, 'MetalnessMap' => 1,
        'RoughnessMap' => 1, 'ShirtTemplate' => 1, 'PantsTemplate' => 1, 'Graphic' => 1, 'SoundId' => 1, 'Image' => 1, 'SkyboxBk' => 1, 'SkyboxDn' => 1,
        'SkyboxFt' => 1, 'SkyboxLf' => 1, 'SkyboxRt' => 1, 'SkyboxUp' => 1, 'MoonTextureId' => 1, 'SunTextureId' => 1, 'AnimationId' => 1, 'Face' => 1];

    private static function aliasContent(array &$props): void {
        foreach (array_keys($props) as $k) {
            $v = $props[$k];
            if (strlen($k) > 7 && substr($k, -7) === 'Content' && is_array($v) && isset($v['url']) && is_string($v['url'])) {
                $base = substr($k, 0, -7);
                if (!isset($props[$base]['url'])) {
                    $props[$base] = $v;
                }
                if ($base === 'Texture' && !isset($props['TextureID'])) {
                    $props['TextureID'] = $v;
                }
                if ($base === 'Texture' && !isset($props['TextureId'])) {
                    $props['TextureId'] = $v;
                }
            }
        }
    }

    private static function u32le(string $d, int $q): int {
        return unpack('V', $d, $q)[1];
    }

    private static function readRaw(string $d, int $q, int $n, int $size): array {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $u = 0;
            for ($b = 0; $b < $size; $b++) {
                $u = $u * 256 + ord($d[$q + $b * $n + $i]);
            }
            $out[] = $u;
        }
        return $out;
    }

    private static function readInts(string $d, int $q, int $n, int $size): array {
        $r = self::readRaw($d, $q, $n, $size);
        foreach ($r as &$u) {
            $v = ($u >> 1) ^ -($u & 1);
            if ($v > 0x7fffffff) {
                $v -= 0x100000000;
            }
            $u = $v;
        }
        return $r;
    }

    private static function f32bits(int $u): float {
        return unpack('G', pack('N', $u & 0xffffffff))[1];
    }

    private static function readFloats(string $d, int $q, int $n): array {
        $r = self::readRaw($d, $q, $n, 4);
        foreach ($r as &$u) {
            $u = self::f32bits((($u >> 1) | (($u & 1) << 31)) & 0xffffffff);
        }
        return $r;
    }

    private static function readProp(string $data, int $q, int $n, int $ty) {
        $out = [];
        switch ($ty) {
            case 1:
                for ($i = 0; $i < $n; $i++) {
                    $l = self::u32le($data, $q);
                    $q += 4;
                    $out[] = substr($data, $q, $l);
                    $q += $l;
                }
                return $out;
            case 2:
                for ($i = 0; $i < $n; $i++) {
                    $out[] = ord($data[$q + $i]) !== 0;
                }
                return $out;
            case 3:
                return self::readInts($data, $q, $n, 4);
            case 4:
                return self::readFloats($data, $q, $n);
            case 5:
                for ($i = 0; $i < $n; $i++) {
                    $out[] = unpack('e', $data, $q + $i * 8)[1];
                }
                return $out;
            case 11:
                foreach (self::readRaw($data, $q, $n, 4) as $u) {
                    $out[] = ['brick' => $u, 'color' => self::brickColor($u)];
                }
                return $out;
            case 12:
                $R = self::readFloats($data, $q, $n);
                $G = self::readFloats($data, $q + $n * 4, $n);
                $B = self::readFloats($data, $q + $n * 8, $n);
                foreach ($R as $k => $r) {
                    $out[] = ['r' => $r, 'g' => $G[$k], 'b' => $B[$k]];
                }
                return $out;
            case 13:
                $X = self::readFloats($data, $q, $n);
                $Y = self::readFloats($data, $q + $n * 4, $n);
                foreach ($X as $k => $x) {
                    $out[] = ['x' => $x, 'y' => $Y[$k]];
                }
                return $out;
            case 14:
                $X = self::readFloats($data, $q, $n);
                $Y = self::readFloats($data, $q + $n * 4, $n);
                $Z = self::readFloats($data, $q + $n * 8, $n);
                foreach ($X as $k => $x) {
                    $out[] = ['x' => $x, 'y' => $Y[$k], 'z' => $Z[$k]];
                }
                return $out;
            case 16:
                $ms = [];
                for ($i = 0; $i < $n; $i++) {
                    $rid = ord($data[$q++]);
                    if ($rid === 0) {
                        $m9 = array_values(unpack('g9', $data, $q));
                        $q += 36;
                        $ms[] = $m9;
                    } else {
                        $ms[] = self::ROT[$rid] ?? self::ROT[2];
                    }
                }
                $PX = self::readFloats($data, $q, $n);
                $PY = self::readFloats($data, $q + $n * 4, $n);
                $PZ = self::readFloats($data, $q + $n * 8, $n);
                foreach ($ms as $k => $m) {
                    $out[] = ['x' => $PX[$k], 'y' => $PY[$k], 'z' => $PZ[$k], 'm' => $m];
                }
                return $out;
            case 18:
                return self::readRaw($data, $q, $n, 4);
            case 19:
                $a = 0;
                foreach (self::readInts($data, $q, $n, 4) as $v) {
                    $a += $v;
                    $out[] = ['__ref' => $a];
                }
                return $out;
            case 27:
                // Int64 (a CharacterMesh's MeshId, BaseTextureId and OverlayTextureId): 8 bytes interleaved, zigzag
                for ($i = 0; $i < $n; $i++) {
                    $u = 0;
                    for ($b = 0; $b < 8; $b++) {
                        $u = ($u << 8) | ord($data[$q + $b * $n + $i]);
                    }
                    $out[] = (($u >> 1) & 0x7fffffffffffffff) ^ -($u & 1);
                }
                return $out;
            case 26:
                for ($i = 0; $i < $n; $i++) {
                    $out[] = [
                        'r' => ord($data[$q + $i]) / 255,
                        'g' => ord($data[$q + $n + $i]) / 255,
                        'b' => ord($data[$q + 2 * $n + $i]) / 255
                    ];
                }
                return $out;
            case 0x22:
                $st = self::readRaw($data, $q, $n, 4);
                $q += $n * 4;
                $q += 4;
                for ($i = 0; $i < $n; $i++) {
                    if ($st[$i] === 1) {
                        $l = self::u32le($data, $q);
                        $q += 4;
                        $out[] = ['url' => substr($data, $q, $l)];
                        $q += $l;
                    } else {
                        $out[] = ['url' => ''];
                    }
                }
                return $out;
            default:
                return null;
        }
    }

    private static function wouldLoop(Inst $child, Inst $parent): bool {
        for ($x = $parent, $d = 0; $x !== null; $x = $x->parent, $d++) {
            if ($x === $child || $d > self::MAX_DEPTH) {
                return true;
            }
        }
        return false;
    }

    static function parseBinary(string $bytes): array {
        $p = 14 + 10 + 8;
        $len = strlen($bytes);
        $classes = [];
        $insts = [];
        $prnt = null;
        while ($p + 16 <= $len) {
            $name = rtrim(substr($bytes, $p, 4), "\0");
            $cl = self::u32le($bytes, $p + 4);
            $ul = self::u32le($bytes, $p + 8);
            $p += 16;
            if ($ul > 64 * 1024 * 1024) {
                throw new \RuntimeException('chunk too large');
            }
            if ($cl === 0) {
                $data = substr($bytes, $p, $ul);
            } elseif (substr($bytes, $p, 4) === "\x28\xb5\x2f\xfd") {
                if (!function_exists('zstd_uncompress')) {
                    throw new \RuntimeException('ZSTD-compressed model but no decoder');
                }
                $data = zstd_uncompress(substr($bytes, $p, $cl));
                if (!is_string($data) || strlen($data) !== $ul) {
                    throw new \RuntimeException('bad ZSTD chunk');
                }
            } else {
                $data = self::lz4(substr($bytes, $p, $cl), $ul);
            }
            $p += $cl === 0 ? $ul : $cl;
            if ($name === 'END') {
                break;
            }
            if ($name !== 'INST' && $name !== 'PROP' && $name !== 'PRNT') {
                continue;
            }
            $q = 0;
            if ($name === 'INST') {
                $id = self::u32le($data, $q);
                $q += 4;
                $ln = self::u32le($data, $q);
                $q += 4;
                $cn = substr($data, $q, $ln);
                $q += $ln;
                $q += 1;
                $n = self::u32le($data, $q);
                $q += 4;
                if ($n > self::MAX_INSTANCES || $q + $n * 4 > strlen($data) || count($insts) + $n > self::MAX_INSTANCES) {
                    throw new \RuntimeException('bad instance chunk');
                }
                $refs = self::readInts($data, $q, $n, 4);
                $q += $n * 4;
                $acc = 0;
                $list = [];
                for ($i = 0; $i < $n; $i++) {
                    $acc += $refs[$i];
                    $inst = new Inst($cn, (string)$acc);
                    $list[] = $inst;
                    $insts[$acc] = $inst;
                }
                $classes[$id] = ['name' => $cn, 'insts' => $list];
            } elseif ($name === 'PROP') {
                $cid = self::u32le($data, $q);
                $q += 4;
                $pl = self::u32le($data, $q);
                $q += 4;
                $pn = substr($data, $q, $pl);
                $q += $pl;
                $ty = ord($data[$q++]);
                $cls = $classes[$cid] ?? null;
                if (!$cls) {
                    continue;
                }
                $n = count($cls['insts']);
                $vals = self::readProp($data, $q, $n, $ty);
                if ($vals) {
                    for ($i = 0; $i < $n; $i++) {
                        if (isset($vals[$i])) {
                            $cls['insts'][$i]->props[$pn] = $vals[$i];
                        }
                    }
                }
            } elseif ($name === 'PRNT') {
                $q += 1;
                $n = self::u32le($data, $q);
                $q += 4;
                if ($n > self::MAX_INSTANCES || $q + $n * 8 > strlen($data)) {
                    throw new \RuntimeException('bad parent chunk');
                }
                $a = self::readInts($data, $q, $n, 4);
                $b = self::readInts($data, $q + $n * 4, $n, 4);
                $ca = 0;
                $pa = 0;
                $prnt = [];
                for ($i = 0; $i < $n; $i++) {
                    $ca += $a[$i];
                    $pa += $b[$i];
                    $prnt[] = [$ca, $pa];
                }
            }
        }
        $roots = [];
        $byRef = [];
        foreach ($insts as $k => $x) {
            $byRef[(string)$k] = $x;
            $x->name = is_string($x->props['Name'] ?? null) ? $x->props['Name'] : $x->cls;
        }
        $placed = [];
        foreach ($prnt ?: [] as $r) {
            $c = $insts[$r[0]] ?? null;
            $par = $r[1] < 0 ? null : ($insts[$r[1]] ?? null);
            if (!$c) {
                continue;
            }
            // a part can only have one parent and can never end up inside itself
            if (isset($placed[spl_object_id($c)])) {
                continue;
            }
            $placed[spl_object_id($c)] = true;
            if ($par && self::wouldLoop($c, $par)) {
                $par = null;
            }
            if ($par) {
                $c->parent = $par;
                $par->children[] = $c;
            } else {
                $roots[] = $c;
            }
        }
        foreach ($insts as $x) {
            foreach ($x->props as $k => $v) {
                if (is_array($v) && isset($v['__ref'])) {
                    $x->props[$k] = ['ref' => (string)$v['__ref']];
                } elseif (is_string($v) && (isset(self::CONTENT_PROPS[$k]) || substr($k, -7) === 'Content')) {
                    $x->props[$k] = ['url' => $v];
                }
            }
            self::aliasContent($x->props);
        }
        return ['roots' => $roots, 'byRef' => $byRef];
    }

}
