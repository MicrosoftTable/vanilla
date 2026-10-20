<?php
namespace Vanilla;

// reading .mesh files and building the box and sphere meshes
trait Meshes {
    static function parseMesh(string $bytes): array {
        $head = substr($bytes, 0, 12);
        $pos = [];
        $nrm = [];
        $uv = [];
        if (!preg_match('/^version (\d+)\.(\d+)/', $head, $mv)) {
            throw new \RuntimeException('not a mesh');
        }
        $major = (int)$mv[1];
        if ($major === 1) {
            $sc = preg_match('/^version 1\.00/', $head) ? 0.5 : 1;
            preg_match_all('/\[([^\]]+)\]/', $bytes, $mm);
            $vs = array_map(function ($s) {
                return array_map(function ($t) {
                    return (float)trim($t);
                }, explode(',', $s));
            }, $mm[1]);
            for ($i = 0; $i + 2 < count($vs); $i += 3) {
                $pos[] = [$vs[$i][0] * $sc, $vs[$i][1] * $sc, $vs[$i][2] * $sc];
                $nrm[] = array_slice($vs[$i + 1], 0, 3) + [0, 0, 0];
                $uv[] = [$vs[$i + 2][0], 1 - ($vs[$i + 2][1] ?? 0)];
            }
            return ['pos' => $pos, 'nrm' => $nrm, 'uv' => $uv];
        }
        $at = 13;
        $len = strlen($bytes);
        $hs = unpack('v', $bytes, $at)[1];
        $readV = function ($o) use ($bytes) {
            $f = unpack('g8', $bytes, $o);
            return [[$f[1], $f[2], $f[3]], [$f[4], $f[5], $f[6]], [$f[7], $f[8]]];
        };
        if ($major === 2 || $major === 3) {
            $vsz = ord($bytes[$at + 2]);
            $fsz = ord($bytes[$at + 3]);
            if ($major === 3) {
                $nl = unpack('v', $bytes, $at + 6)[1];
                $nv = unpack('V', $bytes, $at + 8)[1];
                $nf = unpack('V', $bytes, $at + 12)[1];
            } else {
                $nl = 0;
                $nv = unpack('V', $bytes, $at + 4)[1];
                $nf = unpack('V', $bytes, $at + 8)[1];
            }
            $vo = $at + $hs;
            $fo = $vo + $nv * $vsz;
            if ($fo + $nf * $fsz > $len || $vsz < 32) {
                throw new \RuntimeException('truncated mesh');
            }
            $V = [];
            for ($i = 0; $i < $nv; $i++) {
                $V[] = $readV($vo + $i * $vsz);
            }
            $last = $nf;
            if ($major === 3 && $nl > 1) {
                $last = unpack('V', $bytes, $fo + $nf * $fsz + 4)[1];
            }
        } elseif ($major === 4 || $major === 5) {
            $nv = unpack('V', $bytes, $at + 4)[1];
            $nf = unpack('V', $bytes, $at + 8)[1];
            $nlod = unpack('v', $bytes, $at + 12)[1];
            $nbones = unpack('v', $bytes, $at + 14)[1];
            $vo = $at + $hs;
            $fsz = 12;
            $fo = $vo + $nv * 40 + ($nbones > 0 ? $nv * 8 : 0);
            if ($fo + $nf * 12 > $len) {
                throw new \RuntimeException('truncated mesh');
            }
            $V = [];
            for ($i = 0; $i < $nv; $i++) {
                $V[] = $readV($vo + $i * 40);
            }
            $last = $nf;
            if ($nlod > 1) {
                $l1 = unpack('V', $bytes, $fo + $nf * 12 + 4)[1];
                if ($l1 >= $nf * 0.25 && $l1 <= $nf) {
                    $last = $l1;
                }
            }
        } else {
            throw new \RuntimeException("mesh version $major is not supported here (2012 meshes are 1.x-2.x)");
        }
        if ($last * 3 > self::MAX_MESH_VERTS) {
            throw new \RuntimeException('a mesh is too large');
        }
        for ($i = 0; $i < $last; $i++) {
            $f = unpack('V3', $bytes, $fo + $i * $fsz);
            for ($k = 1; $k <= 3; $k++) {
                $v = $V[$f[$k]] ?? null;
                if (!$v) {
                    throw new \RuntimeException('bad mesh index');
                }
                $pos[] = $v[0];
                $nrm[] = $v[1];
                $uv[] = [$v[2][0], 1 - $v[2][1]];
            }
        }
        return ['pos' => $pos, 'nrm' => $nrm, 'uv' => $uv];
    }

    static function meshBounds(array $m): array {
        $lo = [1e9, 1e9, 1e9];
        $hi = [-1e9, -1e9, -1e9];
        foreach ($m['pos'] as $p) {
            for ($k = 0; $k < 3; $k++) {
                if ($p[$k] < $lo[$k]) {
                    $lo[$k] = $p[$k];
                }
                if ($p[$k] > $hi[$k]) {
                    $hi[$k] = $p[$k];
                }
            }
        }
        return ['lo' => $lo, 'hi' => $hi];
    }

    private static function boxFaces(float $hx, float $hy, float $hz, array $region, float $b): array {
        $h = [$hx, $hy, $hz];
        $hi = [$hx - $b, $hy - $b, $hz - $b];
        $defs = [
            'F' => [[0, 0, -1], function ($s, $t) use ($h, $hi) {
            return [$hi[0] - 2 * $hi[0] * $s, $hi[1] - 2 * $hi[1] * $t, -$h[2]];
        }],
            'B' => [[0, 0, 1], function ($s, $t) use ($h, $hi) {
            return [-$hi[0] + 2 * $hi[0] * $s, $hi[1] - 2 * $hi[1] * $t, $h[2]];
        }],
            'R' => [[1, 0, 0], function ($s, $t) use ($h, $hi) {
            return [$h[0], $hi[1] - 2 * $hi[1] * $t, $hi[2] - 2 * $hi[2] * $s];
        }],
            'L' => [[-1, 0, 0], function ($s, $t) use ($h, $hi) {
            return [-$h[0], $hi[1] - 2 * $hi[1] * $t, -$hi[2] + 2 * $hi[2] * $s];
        }],
            'U' => [[0, 1, 0], function ($s, $t) use ($h, $hi) {
            return [$hi[0] - 2 * $hi[0] * $s, $h[1], $hi[2] - 2 * $hi[2] * $t];
        }],
            'D' => [[0, -1, 0], function ($s, $t) use ($h, $hi) {
            return [$hi[0] - 2 * $hi[0] * $s, -$h[1], -$hi[2] + 2 * $hi[2] * $t];
        }],
        ];
        $F = [];
        foreach ($defs as $k => $d) {
            $r = $region[$k];
            $at = $d[1];
            $P00 = $at(0, 0);
            $ds = self::sub($at(1, 0), $P00);
            $dt = self::sub($at(0, 1), $P00);
            $dd = self::dot($ds, $ds);
            $tt = self::dot($dt, $dt);
            $F[] = ['n' => $d[0], 'at' => $at, 'uvOf' => function ($p) use ($P00, $ds, $dt, $dd, $tt, $r) {
                $q = self::sub($p, $P00);
                $s = min(1, max(0, self::dot($q, $ds) / $dd));
                $t = min(1, max(0, self::dot($q, $dt) / $tt));
                return [($r[0] + 0.5 + $s * ($r[2] - 1)) / self::TPL_W, ($r[1] + 0.5 + $t * ($r[3] - 1)) / self::TPL_H];
            }];
        }
        return $F;
    }

    static function templateMesh(array $m, float $hx, float $hy, float $hz, array $region): array {
        $F = self::boxFaces($hx, $hy, $hz, $region, self::BEVEL);
        $faceOf = function ($n) use ($F) {
            $best = null;
            $bd = 0.5;
            foreach ($F as $i => $f) {
                $d = self::dot($n, $f['n']);
                if ($d > $bd) {
                    $bd = $d;
                    $best = $i;
                }
            }
            return $best;
        };
        $uv = [];
        for ($i = 0; $i + 2 < count($m['pos']); $i += 3) {
            $votes = [$faceOf($m['nrm'][$i]), $faceOf($m['nrm'][$i + 1]), $faceOf($m['nrm'][$i + 2])];
            $f = $votes[0];
            if ($votes[1] === $votes[2] && $votes[1] !== null) {
                $f = $votes[1];
            }
            if ($f === null) {
                $f = $faceOf(
                    self::norm(
                        [
                            $m['nrm'][$i][0] + $m['nrm'][$i + 1][0] + $m['nrm'][$i + 2][0],
                            $m['nrm'][$i][1] + $m['nrm'][$i + 1][1] + $m['nrm'][$i + 2][1],
                            $m['nrm'][$i][2] + $m['nrm'][$i + 1][2] + $m['nrm'][$i + 2][2]
                        ]
                    )
                );
                if ($f === null) {
                    $f = 0;
                }
            }
            for ($k = 0; $k < 3; $k++) {
                $uv[] = ($F[$f]['uvOf'])($m['pos'][$i + $k]);
            }
        }
        return ['pos' => $m['pos'], 'nrm' => $m['nrm'], 'uv' => $uv];
    }

    static function plainBox(float $hx, float $hy, float $hz): array {
        $out = ['pos' => [], 'nrm' => [], 'uv' => []];
        foreach (self::boxFaces($hx, $hy, $hz, self::TEMPLATE['torso'], 0) as $f) {
            $C = [($f['at'])(0, 0), ($f['at'])(1, 0), ($f['at'])(1, 1), ($f['at'])(0, 1)];
            foreach ([[0, 1, 2], [0, 2, 3]] as $t) {
                $a = $C[$t[0]];
                $b = $C[$t[1]];
                $c = $C[$t[2]];
                $list = self::dot(
                    self::cross(self::sub($b, $a), self::sub($c, $a)),
                    $f['n']
                ) >= 0 ? [$a, $b, $c] : [$a, $c, $b];
                foreach ($list as $p) {
                    $out['pos'][] = $p;
                    $out['nrm'][] = $f['n'];
                    $out['uv'][] = [0, 0];
                }
            }
        }
        return $out;
    }

    static function sphereMesh(float $rx, float $ry, float $rz): array {
        $out = ['pos' => [], 'nrm' => [], 'uv' => []];
        $S = 24;
        $T = 16;
        $pt = function ($i, $j) use ($S, $T, $rx, $ry, $rz) {
            $th = $i / $S * 2 * M_PI;
            $ph = $j / $T * M_PI;
            $n = [sin($ph) * cos($th), cos($ph), sin($ph) * sin($th)];
            return [[$n[0] * $rx, $n[1] * $ry, $n[2] * $rz], $n, [$i / $S, $j / $T]];
        };
        for ($i = 0; $i < $S; $i++) {
            for ($j = 0; $j < $T; $j++) {
                $a = $pt($i, $j);
                $b = $pt($i + 1, $j);
                $c = $pt($i + 1, $j + 1);
                $d = $pt($i, $j + 1);
                foreach ([[$a, $c, $b], [$a, $d, $c]] as $t) {
                    foreach ($t as $q) {
                        $out['pos'][] = $q[0];
                        $out['nrm'][] = $q[1];
                        $out['uv'][] = $q[2];
                    }
                }
            }
        }
        return $out;
    }

    static function cleanMesh(array $m): array {
        $pos = [];
        $nrm = [];
        $uv = [];
        $n = count($m['pos']);
        for ($t = 0; $t + 2 < $n; $t += 3) {
            $a = $m['pos'][$t];
            $b = $m['pos'][$t + 1];
            $c = $m['pos'][$t + 2];
            $sum = $a[0] + $a[1] + $a[2] + $b[0] + $b[1] + $b[2] + $c[0] + $c[1] + $c[2];
            if (!is_finite($sum)) {
                continue;
            }
            $fn = self::cross(self::sub($b, $a), self::sub($c, $a));
            $fl = sqrt($fn[0] * $fn[0] + $fn[1] * $fn[1] + $fn[2] * $fn[2]);
            if (!($fl > 1e-12)) {
                continue;
            }
            $fn = [$fn[0] / $fl, $fn[1] / $fl, $fn[2] / $fl];
            $ns = [];
            $good = [0, 0, 0];
            $ng = 0;
            for ($k = 0; $k < 3; $k++) {
                $q = $m['nrm'][$t + $k] ?? null;
                $l = $q ? sqrt($q[0] * $q[0] + $q[1] * $q[1] + $q[2] * $q[2]) : 0;
                if ($l > 0.1 && is_finite($l)) {
                    $ns[] = [$q[0] / $l, $q[1] / $l, $q[2] / $l];
                    $good[0] += $q[0] / $l;
                    $good[1] += $q[1] / $l;
                    $good[2] += $q[2] / $l;
                    $ng++;
                } else {
                    $ns[] = null;
                }
            }
            $fb = $fn;
            if ($ng) {
                $gl = sqrt($good[0] * $good[0] + $good[1] * $good[1] + $good[2] * $good[2]) ?: 1;
                $fb = [$good[0] / $gl, $good[1] / $gl, $good[2] / $gl];
            }
            for ($k = 0; $k < 3; $k++) {
                $pos[] = $m['pos'][$t + $k];
                $nrm[] = $ns[$k] ?? $fb;
                $u = $m['uv'][$t + $k] ?? [0, 0];
                $uv[] = [
                    is_finite($u[0]) && abs($u[0]) < 1e4 ? $u[0] : 0.0,
                    is_finite($u[1]) && abs($u[1]) < 1e4 ? $u[1] : 0.0
                ];
            }
        }
        return ['pos' => $pos, 'nrm' => $nrm, 'uv' => $uv];
    }

    // ignores a texture alpha when most of what the mesh samples is transparent so hats dont vanish
    static function alphaCutsOut(Img $img, array $uv): bool {
        $w = $img->w;
        $h = $img->h;
        $P = $img->px;
        $tot = 0;
        $clear = 0;
        $at = function ($u, $v) use ($w, $h, $P) {
            $u -= floor($u);
            $v -= floor($v);
            return ($P[min($h - 1, (int)($v * $h)) * $w + min($w - 1, (int)($u * $w))] >> 24) & 255;
        };
        for ($i = 0; $i + 2 < count($uv); $i += 3) {
            $a = $uv[$i];
            $b = $uv[$i + 1];
            $e = $uv[$i + 2];
            foreach ([$a, $b, $e, [($a[0] + $b[0] + $e[0]) / 3, ($a[1] + $b[1] + $e[1]) / 3]] as $q) {
                $tot++;
                if ($at($q[0], $q[1]) < 128) {
                    $clear++;
                }
            }
        }
        return $tot > 0 && $clear / $tot <= 0.9;
    }
}
