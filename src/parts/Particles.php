<?php
namespace Vanilla;

// fire smoke and sparkles simulated from a fixed seed
trait Particles {
    private static $FX_TIME = 6;
    private static $FX_DT = 1 / 30;
    private static $FX_SEED = 2012;

    private static function fxProp(array $P, array $names) {
        foreach ($names as $n) {
            if (array_key_exists($n, $P)) {
                return $P[$n];
            }
        }
        return null;
    }

    private static function fxNum($v, float $d): float {
        return (is_int($v) || is_float($v)) && is_finite((float)$v) ? (float)$v : $d;
    }

    private static function fxCol($c, array $d): array {
        return is_array($c) && isset($c['r']) ? [(float)$c['r'], (float)$c['g'], (float)$c['b']] : $d;
    }

    private static function fxSystem(Inst $inst, array $pos): ?array {
        $P = $inst->props;
        if (($P['Enabled'] ?? true) === false) {
            return null;
        }
        if ($inst->cls === 'Sparkles') {
            $sc = self::fxCol($P['SparkleColor'] ?? $P['Color'] ?? null, [0.564706, 0.0980392, 1.0]);
            return ['mat' => 0, 'pos' => $pos, 'quota' => 40, 'rate' => 35, 'ttl' => [1.1, 1.1], 'vel' => [4, 8], 'angle' => 180, 'dir' => [0, -1, 0], 'box' => [0, 0, 0],
                'w' => 0.8, 'h' => 1.0, 'grow' => 0.0, 'rot' => [0, 360], 'spin' => [0, 360], 'col' => [$sc[0], $sc[1], $sc[2], 1.0], 'col1' => null, 'fade' => [0, 0, 0, -1], 'rise' => 0.0];
        }
        if ($inst->cls === 'Fire') {
            $c0 = self::fxCol($P['Color'] ?? null, [236 / 255, 139 / 255, 70 / 255]);
            $c1 = self::fxCol($P['SecondaryColor'] ?? null, [139 / 255, 80 / 255, 55 / 255]);
            $size = self::fxNum(self::fxProp($P, ['Size', 'size_xml', 'size']), 5);
            $heat = self::fxNum(self::fxProp($P, ['Heat', 'heat_xml', 'heat']), 9);
            $k = abs($heat) / 9;
            return ['mat' => 1, 'pos' => $pos, 'quota' => 40, 'rate' => 35, 'ttl' => [1, 1], 'vel' => [2.33 * $k, 7 * $k], 'angle' => 18, 'dir' => [0, $heat < 0 ? -1 : 1, 0], 'box' => [0, 0, 0],
                'w' => $size, 'h' => $size, 'grow' => -$size, 'rot' => [0, 365], 'spin' => [0, 100], 'col' => [$c0[0], $c0[1], $c0[2], 1.0], 'col1' => [$c1[0], $c1[1], $c1[2], 1.0], 'fade' => null, 'rise' => 0.0];
        }
        if ($inst->cls === 'Smoke') {
            $cs = self::fxCol($P['Color'] ?? null, [1.0, 1.0, 1.0]);
            $ss = self::fxNum(self::fxProp($P, ['Size', 'size_xml', 'size']), 1);
            $op = min(1, max(0, self::fxNum(self::fxProp($P, ['Opacity', 'opacity_xml', 'opacity']), 0.5)));
            return ['mat' => 2, 'pos' => $pos, 'quota' => 70, 'rate' => 6, 'ttl' => [4, 6], 'vel' => [0, 0.1], 'angle' => 180, 'dir' => [0, 1, 0], 'box' => [1, 1, 1],
                'w' => $ss, 'h' => $ss, 'grow' => 1.5 * $ss, 'rot' => [0, 0], 'spin' => [0, 0], 'col' => [$cs[0], $cs[1], $cs[2], $op], 'col1' => null,
                'fade' => [-0.11, -0.11, -0.11, -0.1], 'rise' => self::fxNum(self::fxProp($P, ['RiseVelocity', 'riseVelocity_xml', 'riseVelocity']), 1)];
        }
        return null;
    }

    // xorshift32 so the random numbers are identical on every server
    private static function fxRand(int &$s): float {
        $s ^= ($s << 13) & 0xFFFFFFFF;
        $s ^= $s >> 17;
        $s ^= ($s << 5) & 0xFFFFFFFF;
        return $s / 4294967296;
    }

    // particles run for a fixed time from a fixed seed so a hat always gives the same picture
    private static function fxSimulate(array $sys, int &$seed): array {
        $ps = [];
        $rem = 0.0;
        $dt = self::$FX_DT;
        $steps = (int)round(self::$FX_TIME / self::$FX_DT);
        $dg = M_PI / 180;
        $d = self::norm($sys['dir']);
        $u0 = self::cross($d, [1, 0, 0]);
        if (self::dot($u0, $u0) < 1e-12) {
            $u0 = self::cross($d, [0, 1, 0]);
        }
        $u0 = self::norm($u0);
        $du = self::cross($d, $u0);
        for ($s = 0; $s < $steps; $s++) {
            $keep = [];
            foreach ($ps as $p) {
                if ($p['ttl'] < $dt) {
                    continue;
                }
                $p['ttl'] -= $dt;
                $keep[] = $p;
            }
            $ps = $keep;
            foreach ($ps as &$p) {
                if ($sys['fade']) {
                    for ($c = 0; $c < 4; $c++) {
                        $p['col'][$c] = min(1, max(0, $p['col'][$c] + $sys['fade'][$c] * $dt));
                    }
                }
                if ($sys['col1']) {
                    $f = 1 - $p['ttl'] / $p['life'];
                    for ($c = 0; $c < 4; $c++) {
                        $p['col'][$c] = $sys['col'][$c] + ($sys['col1'][$c] - $sys['col'][$c]) * $f;
                    }
                }
                if ($sys['grow']) {
                    $p['w'] += $sys['grow'] * $dt;
                    $p['h'] += $sys['grow'] * $dt;
                }
                $p['rot'] += $p['spin'] * $dt;
            }
            unset($p);
            foreach ($ps as &$p) {
                $p['x'] += $p['vx'] * $dt;
                $p['y'] += $p['vy'] * $dt;
                $p['z'] += $p['vz'] * $dt;
            }
            unset($p);
            $rem += $sys['rate'] * $dt;
            $n = (int)floor($rem);
            $rem -= $n;
            for ($j = 0; $j < $n; $j++) {
                if (count($ps) >= $sys['quota']) {
                    break;
                }
                $r = [];
                for ($c = 0; $c < 9; $c++) {
                    $r[] = self::fxRand($seed);
                }
                $th = $r[3] * $sys['angle'] * $dg;
                $ph = $r[4] * 2 * M_PI;
                $nu = [
                    $u0[0] * cos($ph) + $du[0] * sin($ph),
                    $u0[1] * cos($ph) + $du[1] * sin($ph),
                    $u0[2] * cos($ph) + $du[2] * sin($ph)
                ];
                $nx = self::cross($nu, $d);
                $ct = cos($th);
                $st = sin($th);
                $dir = [$d[0] * $ct + $nx[0] * $st, $d[1] * $ct + $nx[1] * $st, $d[2] * $ct + $nx[2] * $st];
                $v = $sys['vel'][0] + $r[5] * ($sys['vel'][1] - $sys['vel'][0]);
                $life = $sys['ttl'][0] + $r[6] * ($sys['ttl'][1] - $sys['ttl'][0]);
                $p = ['x' => $sys['pos'][0] + ($r[0] * 2 - 1) * $sys['box'][0] / 2, 'y' => $sys['pos'][1] + ($r[1] * 2 - 1) * $sys['box'][1] / 2, 'z' => $sys['pos'][2] + ($r[2] * 2 - 1) * $sys['box'][2] / 2,
                    'vx' => $dir[0] * $v, 'vy' => $dir[1] * $v + $sys['rise'], 'vz' => $dir[2] * $v, 'ttl' => $life, 'life' => $life, 'w' => $sys['w'], 'h' => $sys['h'], 'col' => $sys['col'],
                    'rot' => ($sys['rot'][0] + $r[7] * ($sys['rot'][1] - $sys['rot'][0])) * $dg, 'spin' => ($sys['spin'][0] + $r[8] * ($sys['spin'][1] - $sys['spin'][0])) * $dg];
                $tp = $j * $dt / $n;
                $p['x'] += $p['vx'] * $tp;
                $p['y'] += $p['vy'] * $tp;
                $p['z'] += $p['vz'] * $tp;
                $ps[] = $p;
            }
        }
        return $ps;
    }

    private static function fxBuild(array $items, array $eye): array {
        $seed = self::$FX_SEED;
        $list = [];
        foreach ($items as $it) {
            foreach ($it['fx'] ?? [] as $inst) {
                $sys = self::fxSystem($inst, $it['cf']['p']);
                if (!$sys) {
                    continue;
                }
                $sys['parts'] = self::fxSimulate($sys, $seed);
                $dd = self::sub($sys['pos'], $eye);
                $sys['d2'] = self::dot($dd, $dd);
                $sys['order'] = count($list);
                $list[] = $sys;
            }
        }
        usort($list, function ($a, $b) {
            return ($b['d2'] <=> $a['d2']) ?: ($a['order'] <=> $b['order']);
        });
        return $list;
    }

    private function setupFx(
        array &$tris,
        array $sys,
        Texture $tex,
        array $vp,
        array $right,
        array $up,
        int $Wss,
        int $Hss
    ): void {
        $snap = 1 << $this->subpixelBits;
        foreach ($sys['parts'] as $p) {
            $hw = $p['w'] / (isset($sys['fx2016']) ? 1 : 2);
            $hh = $p['h'] / (isset($sys['fx2016']) ? 1 : 2);
            if (!($hw > 0 && $hh > 0)) {
                continue;
            }
            $cs = cos($p['rot']);
            $sn = sin($p['rot']);
            $C = [];
            foreach ([[-1, 1, 0, 0], [1, 1, 1, 0], [-1, -1, 0, 1], [1, -1, 1, 1]] as $q) {
                $ox = $q[0] * $hw;
                $oy = $q[1] * $hh;
                $rx = $ox * $cs - $oy * $sn;
                $ry = $ox * $sn + $oy * $cs;
                $wx = $p['x'] + $right[0] * $rx + $up[0] * $ry;
                $wy = $p['y'] + $right[1] * $rx + $up[1] * $ry;
                $wz = $p['z'] + $right[2] * $rx + $up[2] * $ry;
                $cx = $vp[0] * $wx + $vp[4] * $wy + $vp[8] * $wz + $vp[12];
                $cy = $vp[1] * $wx + $vp[5] * $wy + $vp[9] * $wz + $vp[13];
                $cz = $vp[2] * $wx + $vp[6] * $wy + $vp[10] * $wz + $vp[14];
                $cw = $vp[3] * $wx + $vp[7] * $wy + $vp[11] * $wz + $vp[15];
                $C[] = [$cx, $cy, $cz, $cw, [$q[2], $q[3]]];
            }
            foreach ([[0, 2, 1], [1, 2, 3]] as $tri) {
                $S = [];
                $bad = false;
                foreach ($tri as $k) {
                    $v = $C[$k];
                    if ($v[3] <= 1e-6) {
                        $bad = true;
                        break;
                    }
                    $iw = 1 / $v[3];
                    $sx = ($v[0] * $iw + 1) * 0.5 * $Wss;
                    $sy = (1 - ($v[1] * $iw + 1) * 0.5) * $Hss;
                    $sx = round($sx * $snap) / $snap;
                    $sy = round($sy * $snap) / $snap;
                    $S[] = [$sx, $sy, ($v[2] * $iw + 1) * 0.5, $iw, $v[4]];
                }
                if ($bad) {
                    continue;
                }
                [$x0, $y0] = $S[0];
                [$x1, $y1] = $S[1];
                [$x2, $y2] = $S[2];
                $area = ($x1 - $x0) * ($y2 - $y0) - ($x2 - $x0) * ($y1 - $y0);
                if ($area == 0) {
                    continue;
                }
                if ($area > 0) {
                    $t = $S[1];
                    $S[1] = $S[2];
                    $S[2] = $t;
                    [$x1, $y1] = $S[1];
                    [$x2, $y2] = $S[2];
                }
                $d0 = ($x2 - $x1) * ($y0 - $y1) - ($y2 - $y1) * ($x0 - $x1);
                $d1 = ($x0 - $x2) * ($y1 - $y2) - ($y0 - $y2) * ($x1 - $x2);
                if ($d0 == 0 || $d1 == 0) {
                    continue;
                }
                $a0 = -($y2 - $y1) / $d0;
                $b0 = ($x2 - $x1) / $d0;
                $c0 = (($y2 - $y1) * $x1 - ($x2 - $x1) * $y1) / $d0;
                $a1 = -($y0 - $y2) / $d1;
                $b1 = ($x0 - $x2) / $d1;
                $c1 = (($y0 - $y2) * $x2 - ($x0 - $x2) * $y2) / $d1;
                $plane = function ($f0, $f1, $f2) use ($a0, $b0, $c0, $a1, $b1, $c1) {
                    $e0 = $f0 - $f2;
                    $e1 = $f1 - $f2;
                    return [$a0 * $e0 + $a1 * $e1, $b0 * $e0 + $b1 * $e1, $f2 + $c0 * $e0 + $c1 * $e1];
                };
                $P = [$plane($S[0][3], $S[1][3], $S[2][3]), $plane($S[0][2], $S[1][2], $S[2][2])];
                for ($k = 0; $k < 2; $k++) {
                    $P[] = $plane($S[0][4][$k] * $S[0][3], $S[1][4][$k] * $S[1][3], $S[2][4][$k] * $S[2][3]);
                }
                $tris[] = [
                    'bx0' => max(0, (int)ceil(min($x0, $x1, $x2) - 0.5)), 'bx1' => min($Wss - 1, (int)floor(max($x0, $x1, $x2) - 0.5)),
                    'by0' => max(0, (int)ceil(min($y0, $y1, $y2) - 0.5)), 'by1' => min($Hss - 1, (int)floor(max($y0, $y1, $y2) - 0.5)),
                    'e' => [$a0, $b0, $c0, $a1, $b1, $c1], 'P' => $P, 'mode' => 4, 'color' => $p['col'], 'tex' => $tex, 'studs' => false, 'half' => null, 'tint' => null, 'mat' => $sys['mat'],
                    'fx2016' => $sys['fx2016'] ?? null, 'curve' => $p['curve'] ?? null,
                ];
            }
        }
    }
}
