<?php
namespace Vanilla;

final class Img {
    public $w;
    public $h;
    public $px;

    function __construct(int $w, int $h, ?array $px = null) {
        $this->w = $w;
        $this->h = $h;
        $this->px = $px ?? array_fill(0, $w * $h, 0);
    }

    static function toCache(Img $im): string {
        $raw = '';
        foreach (array_chunk($im->px, 65536) as $c) {
            $raw .= pack('N*', ...$c);
        }
        return pack('NN', $im->w, $im->h) . gzcompress($raw, 1);
    }

    static function fromCache(string $s): ?Img {
        if (strlen($s) < 12) {
            return null;
        }
        $h = unpack('Nw/Nh', $s);
        if ($h['w'] < 1 || $h['h'] < 1 || $h['w'] > 4096 || $h['h'] > 4096) {
            return null;
        }
        $raw = @gzuncompress(substr($s, 8), $h['w'] * $h['h'] * 4 + 1);
        if ($raw === false || strlen($raw) !== $h['w'] * $h['h'] * 4) {
            return null;
        }
        return new Img($h['w'], $h['h'], array_values(unpack('N*', $raw)));
    }

    static function isImageBytes(string $b): bool {
        if (strlen($b) < 12) {
            return false;
        }
        return substr($b, 0, 4) === "\x89PNG"
            || substr($b, 0, 3) === "\xff\xd8\xff"
            || (substr($b, 0, 4) === 'RIFF' && substr($b, 8, 4) === 'WEBP')
            || substr($b, 0, 4) === 'GIF8' || substr($b, 0, 2) === 'BM';
    }

    static function decode(string $b, int $maxSide = 2048, int $bigSide = 0): ?Img {
        if (!self::isImageBytes($b)) {
            return null;
        }
        $im = function_exists('imagecreatefromstring') ? self::decodeGD($b, $maxSide, max($bigSide, $maxSide)) : null;
        if ($im) {
            return $im;
        }
        if ($b[0] === "\x89") {
            $im = self::decodePNG($b, $maxSide);
            if ($im !== false && $im !== null) {
                return $im;
            }
        }
        return null;
    }

    static function decodeHat(string $b, int $cap = 512): ?Img {
        if (!self::isImageBytes($b)) {
            return null;
        }
        $info = @getimagesizefromstring($b);
        if (!$info || $info[0] < 1 || $info[1] < 1 || $info[0] > 4096 || $info[1] > 4096 || $info[0] * $info[1] > 16777216) {
            return null;
        }
        if (!self::memoryRoom((int)($info[0] * $info[1] * 5 + min($info[0], $cap) * min($info[1], $cap) * 24))) {
            return null;
        }
        $g = @imagecreatefromstring($b);
        if (!$g) {
            return null;
        }
        if (!imageistruecolor($g)) {
            imagepalettetotruecolor($g);
        }
        $w = imagesx($g);
        $h = imagesy($g);
        $tw = 1;
        while ($tw < $w) {
            $tw *= 2;
        }
        $th = 1;
        while ($th < $h) {
            $th *= 2;
        }
        $tw = min($tw, $cap);
        $th = min($th, $cap);
        if ($tw !== $w || $th !== $h) {
            imagealphablending($g, false);
            imagesavealpha($g, true);
            $s = @imagescale($g, $tw, $th, IMG_BILINEAR_FIXED);
            imagedestroy($g);
            if (!$s) {
                return null;
            }
            $g = $s;
            $w = $tw;
            $h = $th;
        }
        static $AL = null;
        if ($AL === null) {
            $AL = [];
            for ($a7 = 0; $a7 < 128; $a7++) {
                $AL[$a7] = ($a7 === 0 ? 255 : ($a7 === 127 ? 0 : (int)round((127 - $a7) * 255 / 127))) << 24;
            }
        }
        $px = [];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($g, $x, $y);
                $px[] = $AL[($c >> 24) & 127] | ($c & 0xffffff);
            }
        }
        imagedestroy($g);
        return new Img($w, $h, $px);
    }

    private static function memoryRoom(int $bytes): bool {
        $l = trim((string)ini_get('memory_limit'));
        if ($l === '' || $l === '-1') {
            return true;
        }
        $n = (float)$l;
        $u = strtolower(substr($l, -1));
        if ($u === 'g') {
            $n *= 1073741824;
        } elseif ($u === 'm') {
            $n *= 1048576;
        } elseif ($u === 'k') {
            $n *= 1024;
        }
        return $n - memory_get_usage() > $bytes;
    }

    // gd is the fast path and the php png reader is the fallback
    private static function decodeGD(string $b, int $maxSide, int $bigSide): ?Img {
        $info = @getimagesizefromstring($b);
        if (!$info || $info[0] < 1 || $info[1] < 1 || $info[0] > $bigSide || $info[1] > $bigSide || $info[0] * $info[1] > 16777216) {
            return null;
        }
        $big = $info[0] > $maxSide || $info[1] > $maxSide;
        $kept = min($info[0], $maxSide) * min($info[1], $maxSide);
        if (!self::memoryRoom((int)($info[0] * $info[1] * 5 + $kept * 24))) {
            return null;
        }
        $g = @imagecreatefromstring($b);
        if (!$g) {
            return null;
        }
        if ($big) {
            $s = @imagescale($g, min($info[0], $maxSide), min($info[1], $maxSide), IMG_BILINEAR_FIXED);
            imagedestroy($g);
            if (!$s) {
                return null;
            }
            $g = $s;
        }
        $w = imagesx($g);
        $h = imagesy($g);
        if (!imageistruecolor($g)) {
            imagepalettetotruecolor($g);
        }
        static $AL = null;
        if ($AL === null) {
            $AL = [];
            for ($a7 = 0; $a7 < 128; $a7++) {
                $AL[$a7] = ($a7 === 0 ? 255 : ($a7 === 127 ? 0 : (int)round((127 - $a7) * 255 / 127))) << 24;
            }
        }
        $px = [];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($g, $x, $y);
                $px[] = $AL[($c >> 24) & 127] | ($c & 0xffffff);
            }
        }
        imagedestroy($g);
        return new Img($w, $h, $px);
    }

    private static function decodePNG(string $b, int $maxSide) {
        $p = 8;
        $n = strlen($b);
        $idat = '';
        $plte = null;
        $trns = null;
        $ihdr = null;
        while ($p + 8 <= $n) {
            $len = unpack('N', $b, $p)[1];
            $type = substr($b, $p + 4, 4);
            if ($len > $n - $p - 12) {
                return false;
            }
            $d = substr($b, $p + 8, $len);
            $p += 12 + $len;
            if ($type === 'IHDR') {
                $ihdr = unpack('Nw/Nh/Cdepth/Cctype/Ccomp/Cfilter/Cinter', $d);
            } elseif ($type === 'PLTE') {
                $plte = $d;
            } elseif ($type === 'tRNS') {
                $trns = $d;
            } elseif ($type === 'IDAT') {
                $idat .= $d;
                if (strlen($idat) > 64 * 1024 * 1024) {
                    return false;
                }
            } elseif ($type === 'IEND') {
                break;
            }
        }
        if (!$ihdr) {
            return false;
        }
        $w = $ihdr['w'];
        $h = $ihdr['h'];
        $depth = $ihdr['depth'];
        $ct = $ihdr['ctype'];
        if ($w < 1 || $h < 1 || $w > $maxSide || $h > $maxSide || !self::memoryRoom($w * $h * 24)) {
            return null;
        }
        $chan = [0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4][$ct] ?? 0;
        if (!$chan || !in_array($depth, [1, 2, 4, 8, 16], true)) {
            return false;
        }
        // the most a valid image of this size can unpack to, so a compression bomb stops early
        $rowBytes = (int)ceil($w * $chan * $depth / 8);
        $limit = $h * ($rowBytes + 1) + ($ihdr['inter'] ? 7 * ($h + 8) * 2 : 0) + 64;
        $raw = @zlib_decode($idat, $limit + 1);
        if ($raw === false || strlen($raw) > $limit) {
            return false;
        }
        $bpp = max(1, ($chan * $depth) >> 3);
        $px = array_fill(0, $w * $h, 0);
        $passes = $ihdr['inter'] ? [
            [0, 0, 8, 8],
            [4, 0, 8, 8],
            [0, 4, 4, 8],
            [2, 0, 4, 4],
            [0, 2, 2, 4],
            [1, 0, 2, 2],
            [0, 1, 1, 2]
        ] : [[0, 0, 1, 1]];
        $off = 0;
        $pal = [];
        if ($ct === 3) {
            if ($plte === null) {
                return false;
            }
            for ($i = 0, $k = strlen($plte) / 3; $i < $k; $i++) {
                $pal[$i] = (ord($plte[$i * 3]) << 16) | (ord($plte[$i * 3 + 1]) << 8) | ord($plte[$i * 3 + 2]);
            }
        }
        $palA = [];
        if ($ct === 3 && $trns !== null) {
            for ($i = 0, $k = strlen($trns); $i < $k; $i++) {
                $palA[$i] = ord($trns[$i]);
            }
        }
        $keyG = null;
        $keyRGB = null;
        if ($trns !== null && $ct === 0 && strlen($trns) >= 2) {
            $keyG = unpack('n', $trns)[1];
        }
        if ($trns !== null && $ct === 2 && strlen($trns) >= 6) {
            $keyRGB = array_values(unpack('n3', $trns));
        }
        $maxv = (1 << $depth) - 1;
        foreach ($passes as $ps) {
            [$x0, $y0, $dx, $dy] = $ps;
            $pw = intdiv($w - $x0 + $dx - 1, $dx);
            $ph = intdiv($h - $y0 + $dy - 1, $dy);
            if ($pw <= 0 || $ph <= 0) {
                continue;
            }
            $stride = intdiv($pw * $chan * $depth + 7, 8);
            $prev = str_repeat("\0", $stride);
            for ($r = 0; $r < $ph; $r++) {
                if ($off + 1 + $stride > strlen($raw)) {
                    return false;
                }
                $f = ord($raw[$off]);
                $line = substr($raw, $off + 1, $stride);
                $off += 1 + $stride;
                $line = self::unfilter($f, $line, $prev, $bpp);
                if ($line === false) {
                    return false;
                }
                $prev = $line;
                $y = $y0 + $r * $dy;
                $bytes = array_values(unpack('C*', $line));
                for ($c = 0; $c < $pw; $c++) {
                    $x = $x0 + $c * $dx;
                    if ($depth === 8) {
                        $o = $c * $chan;
                        switch ($ct) {
                            case 6:
                                $v = ($bytes[$o + 3] << 24) | ($bytes[$o] << 16) | ($bytes[$o + 1] << 8) | $bytes[$o + 2];
                                break;
                            case 2:
                                $rgb = ($bytes[$o] << 16) | ($bytes[$o + 1] << 8) | $bytes[$o + 2];
                                $a = ($keyRGB && $bytes[$o] === $keyRGB[0] && $bytes[$o + 1] === $keyRGB[1] && $bytes[$o + 2] === $keyRGB[2]) ? 0 : 255;
                                $v = ($a << 24) | $rgb;
                                break;
                            case 4:
                                $g = $bytes[$o];
                                $v = ($bytes[$o + 1] << 24) | ($g << 16) | ($g << 8) | $g;
                                break;
                            case 0:
                                $g = $bytes[$o];
                                $v = (($keyG !== null && $g === $keyG) ? 0 : 255) << 24 | ($g << 16) | ($g << 8) | $g;
                                break;
                            case 3:
                                $i = $bytes[$o];
                                $v = (($palA[$i] ?? 255) << 24) | ($pal[$i] ?? 0);
                                break;
                        }
                    } elseif ($depth === 16) {
                        $o = $c * $chan * 2;
                        $s = [];
                        for ($k = 0; $k < $chan; $k++) {
                            $s[] = ($bytes[$o + 2 * $k] << 8) | $bytes[$o + 2 * $k + 1];
                        }
                        $q = function ($u) {
                            return ($u * 255 + 32767) >> 16 > 255 ? 255 : (int)round($u / 257);
                        };
                        switch ($ct) {
                            case 6:
                                $v = ($q($s[3]) << 24) | ($q($s[0]) << 16) | ($q($s[1]) << 8) | $q($s[2]);
                                break;
                            case 2:
                                $a = ($keyRGB && $s[0] === $keyRGB[0] && $s[1] === $keyRGB[1] && $s[2] === $keyRGB[2]) ? 0 : 255;
                                $v = ($a << 24) | ($q($s[0]) << 16) | ($q($s[1]) << 8) | $q($s[2]);
                                break;
                            case 4:
                                $g = $q($s[0]);
                                $v = ($q($s[1]) << 24) | ($g << 16) | ($g << 8) | $g;
                                break;
                            case 0:
                                $g = $q($s[0]);
                                $v = (($keyG !== null && $s[0] === $keyG) ? 0 : 255) << 24 | ($g << 16) | ($g << 8) | $g;
                                break;
                            default:
                                return false;
                        }
                    } else {
                        $bit = $c * $depth;
                        $byte = $bytes[$bit >> 3];
                        $u = ($byte >> (8 - $depth - ($bit & 7))) & $maxv;
                        if ($ct === 3) {
                            $v = (($palA[$u] ?? 255) << 24) | ($pal[$u] ?? 0);
                        } elseif ($ct === 0) {
                            $g = (int)round($u * 255 / $maxv);
                            $v = (($keyG !== null && $u === $keyG) ? 0 : 255) << 24 | ($g << 16) | ($g << 8) | $g;
                        } else {
                            return false;
                        }
                    }
                    $px[$y * $w + $x] = $v;
                }
            }
        }
        return new Img($w, $h, $px);
    }

    private static function unfilter(int $f, string $line, string $prev, int $bpp) {
        $n = strlen($line);
        if ($f === 0) {
            return $line;
        }
        $c = array_values(unpack('C*', $line));
        $p = array_values(unpack('C*', $prev));
        switch ($f) {
            case 1:
                for ($i = $bpp; $i < $n; $i++) {
                    $c[$i] = ($c[$i] + $c[$i - $bpp]) & 255;
                }
                break;
            case 2:
                for ($i = 0; $i < $n; $i++) {
                    $c[$i] = ($c[$i] + $p[$i]) & 255;
                }
                break;
            case 3:
                for ($i = 0; $i < $n; $i++) {
                    $c[$i] = ($c[$i] + (($i >= $bpp ? $c[$i - $bpp] : 0) + $p[$i] >> 1)) & 255;
                }
                break;
            case 4:
                for ($i = 0; $i < $n; $i++) {
                    $a = $i >= $bpp ? $c[$i - $bpp] : 0;
                    $b = $p[$i];
                    $cc = $i >= $bpp ? $p[$i - $bpp] : 0;
                    $pp = $a + $b - $cc;
                    $pa = abs($pp - $a);
                    $pb = abs($pp - $b);
                    $pc = abs($pp - $cc);
                    $c[$i] = ($c[$i] + (($pa <= $pb && $pa <= $pc) ? $a : ($pb <= $pc ? $b : $cc))) & 255;
                }
                break;
            default:
                return false;
        }
        return pack('C*', ...$c);
    }

    function fillRect(int $x, int $y, int $w, int $h, array $rgb): void {
        $v = (255 << 24) | ($rgb[0] << 16) | ($rgb[1] << 8) | $rgb[2];
        $x0 = max(0, $x);
        $y0 = max(0, $y);
        $x1 = min($this->w, $x + $w);
        $y1 = min($this->h, $y + $h);
        for ($yy = $y0; $yy < $y1; $yy++) {
            $o = $yy * $this->w;
            for ($xx = $x0; $xx < $x1; $xx++) {
                $this->px[$o + $xx] = $v;
            }
        }
    }

    function drawOver(Img $src, ?array $clipRects = null): void {
        $w = $this->w;
        $h = $this->h;
        $rows = [];
        if ($clipRects === null) {
            for ($y = 0; $y < $h; $y++) {
                $rows[$y] = [[0, $w]];
            }
        } else {
            foreach ($clipRects as $r) {
                for ($y = max(0, $r[1]); $y < min($h, $r[1] + $r[3]); $y++) {
                    $rows[$y][] = [max(0, $r[0]), min($w, $r[0] + $r[2])];
                }
            }
        }
        $done = [];
        foreach ($rows as $y => $spans) {
            foreach ($spans as $sp) {
                for ($x = $sp[0]; $x < $sp[1]; $x++) {
                    $i = $y * $w + $x;
                    if (isset($done[$i])) {
                        continue;
                    }
                    $done[$i] = 1;
                    $this->px[$i] = self::over($src->px[$i], $this->px[$i]);
                }
            }
        }
    }

    private static function over(int $s, int $d): int {
        $sa = ($s >> 24) & 255;
        if ($sa === 255) {
            return $s;
        }
        if ($sa === 0) {
            return $d;
        }
        $ia = 255 - $sa;
        $r = (int)(((($s >> 16) & 255) * $sa + 127) / 255) + (int)(((($d >> 16) & 255) * $ia + 127) / 255);
        $g = (int)(((($s >> 8) & 255) * $sa + 127) / 255) + (int)(((($d >> 8) & 255) * $ia + 127) / 255);
        $b = (int)((($s & 255) * $sa + 127) / 255) + (int)((($d & 255) * $ia + 127) / 255);
        return (255 << 24) | (min(255, $r) << 16) | (min(255, $g) << 8) | min(255, $b);
    }

    function drawScaled(Img $src, int $dx, int $dy, int $dw, int $dh, ?array $clip = null): void {
        $sw = $src->w;
        $sh = $src->h;
        $sp = $src->px;
        for ($y = 0; $y < $dh; $y++) {
            $ty = $dy + $y;
            if ($ty < 0 || $ty >= $this->h) {
                continue;
            }
            if ($clip && ($ty < $clip[1] || $ty >= $clip[1] + $clip[3])) {
                continue;
            }
            $fy = ($y + 0.5) * $sh / $dh - 0.5;
            $y0 = (int)floor($fy);
            $wy = $fy - $y0;
            $ya = max(0, min($sh - 1, $y0));
            $yb = max(0, min($sh - 1, $y0 + 1));
            for ($x = 0; $x < $dw; $x++) {
                $tx = $dx + $x;
                if ($tx < 0 || $tx >= $this->w) {
                    continue;
                }
                if ($clip && ($tx < $clip[0] || $tx >= $clip[0] + $clip[2])) {
                    continue;
                }
                $fx = ($x + 0.5) * $sw / $dw - 0.5;
                $x0 = (int)floor($fx);
                $wx = $fx - $x0;
                $xa = max(0, min($sw - 1, $x0));
                $xb = max(0, min($sw - 1, $x0 + 1));
                $acc = [0, 0, 0, 0];
                foreach ([[$xa, $ya, (1 - $wx) * (1 - $wy)], [$xb, $ya, $wx * (1 - $wy)], [$xa, $yb, (1 - $wx) * $wy], [$xb, $yb, $wx * $wy]] as $t) {
                    $c = $sp[$t[1] * $sw + $t[0]];
                    $a = ($c >> 24) & 255;
                    $k = $t[2];
                    $acc[0] += (($c >> 16) & 255) * $a / 255 * $k;
                    $acc[1] += (($c >> 8) & 255) * $a / 255 * $k;
                    $acc[2] += ($c & 255) * $a / 255 * $k;
                    $acc[3] += $a * $k;
                }
                $a = (int)round($acc[3]);
                $v = $a <= 0 ? 0 : ($a << 24) | (min(255, (int)round($acc[0] * 255 / $acc[3])) << 16) | (min(255, (int)round($acc[1] * 255 / $acc[3])) << 8) | min(
                    255,
                    (int)round($acc[2] * 255 / $acc[3])
                );
                $i = $ty * $this->w + $tx;
                $this->px[$i] = self::over($v, $this->px[$i]);
            }
        }
    }

    function resized(int $tw, int $th): Img {
        $sw = $this->w;
        $sh = $this->h;
        $sp = $this->px;
        $out = [];
        $xa = [];
        $xb = [];
        $xw = [];
        for ($x = 0; $x < $tw; $x++) {
            $f = ($x + 0.5) * $sw / $tw - 0.5;
            $x0 = (int)floor($f);
            $xw[$x] = $f - $x0;
            $xa[$x] = $x0 < 0 ? 0 : ($x0 >= $sw ? $sw - 1 : $x0);
            $xb[$x] = $x0 + 1 < 0 ? 0 : ($x0 + 1 >= $sw ? $sw - 1 : $x0 + 1);
        }
        for ($y = 0; $y < $th; $y++) {
            $f = ($y + 0.5) * $sh / $th - 0.5;
            $y0 = (int)floor($f);
            $wy = $f - $y0;
            $ra = ($y0 < 0 ? 0 : ($y0 >= $sh ? $sh - 1 : $y0)) * $sw;
            $rb = ($y0 + 1 < 0 ? 0 : ($y0 + 1 >= $sh ? $sh - 1 : $y0 + 1)) * $sw;
            for ($x = 0; $x < $tw; $x++) {
                $fx = $xw[$x];
                $i0 = $xa[$x];
                $i1 = $xb[$x];
                $c00 = $sp[$ra + $i0];
                $c10 = $sp[$ra + $i1];
                $c01 = $sp[$rb + $i0];
                $c11 = $sp[$rb + $i1];
                $w00 = (1 - $fx) * (1 - $wy);
                $w10 = $fx * (1 - $wy);
                $w01 = (1 - $fx) * $wy;
                $w11 = $fx * $wy;
                $out[] = (
                    (int)(((($c00 >> 24) & 255) * $w00 + (($c10 >> 24) & 255) * $w10 + (($c01 >> 24) & 255) * $w01 + (($c11 >> 24) & 255) * $w11) + 0.5) << 24
                )
                    | ((int)(((($c00 >> 16) & 255) * $w00 + (($c10 >> 16) & 255) * $w10 + (($c01 >> 16) & 255) * $w01 + (($c11 >> 16) & 255) * $w11) + 0.5) << 16)
                    | ((int)(((($c00 >> 8) & 255) * $w00 + (($c10 >> 8) & 255) * $w10 + (($c01 >> 8) & 255) * $w01 + (($c11 >> 8) & 255) * $w11) + 0.5) << 8)
                    | (int)((($c00 & 255) * $w00 + ($c10 & 255) * $w10 + ($c01 & 255) * $w01 + ($c11 & 255) * $w11) + 0.5);
            }
        }
        return new Img($tw, $th, $out);
    }

    static function encodePNG(int $w, int $h, string $rgba): string {
        $raw = '';
        for ($y = 0; $y < $h; $y++) {
            $raw .= "\0" . substr($rgba, $y * $w * 4, $w * 4);
        }
        $chunk = function ($t, $d) {
            return pack('N', strlen($d)) . $t . $d . pack('N', crc32($t . $d));
        };
        return "\x89PNG\r\n\x1a\n" . $chunk(
            'IHDR',
            pack('NNCCCCC', $w, $h, 8, 6, 0, 0, 0)
        ) . $chunk('IDAT', gzcompress($raw, 9)) . $chunk('IEND', '');
    }


    // formats avatar.php can send and their mime types
    const FORMATS = ['png' => 'image/png', 'webp' => 'image/webp', 'jpg' => 'image/jpeg'];

    // a rendered png as another format, jpg has no alpha so it is flattened onto bg

    static function encode(string $png, string $format, int $bg = 0xffffff): ?string {
        if ($format === 'png') {
            return $png;
        }
        if (!function_exists('imagecreatefromstring') || !function_exists('image' . ($format === 'jpg' ? 'jpeg' : $format))) {
            return null;
        }
        $src = @imagecreatefromstring($png);
        if (!$src) {
            return null;
        }
        $w = imagesx($src);
        $h = imagesy($src);
        ob_start();
        if ($format === 'webp') {
            imagealphablending($src, false);
            imagesavealpha($src, true);
            imagewebp($src, null, 90);
        } else {
            $dst = imagecreatetruecolor($w, $h);
            imagefill($dst, 0, 0, imagecolorallocate($dst, ($bg >> 16) & 255, ($bg >> 8) & 255, $bg & 255));
            imagealphablending($dst, true);
            imagecopy($dst, $src, 0, 0, 0, 0, $w, $h);
            imagejpeg($dst, null, 90);
            imagedestroy($dst);
        }
        imagedestroy($src);
        $out = (string)ob_get_clean();
        return $out === '' ? null : $out;
    }
}
