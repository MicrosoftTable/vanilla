<?php
namespace Vanilla;

// the slow rasteriser that samples every pixel fully
trait ReferenceRaster {
    private function rasterise(array $tris, int $W, int $H, int $SS, Texture $stud, array $faceBox): string {
        $Wss = $W * $SS;
        $Hss = $H * $SS;
        $BAND = $SS * 16;
        $amb = $this->lighting['ambient'];
        $key = $this->lighting['key'];
        $fill = $this->lighting['fill'];
        $ksk = $this->lighting['key'];
        $fx0 = $faceBox[0];
        $fx1 = $faceBox[1];
        $fy0 = $faceBox[2];
        $fy1 = $faceBox[3];
        $fdx = $fx1 - $fx0;
        $fdy = $fy1 - $fy0;
        $out = '';
        $EPS = -1e-9;
        for ($band = 0; $band < $Hss; $band += $BAND) {
            $bh = min($BAND, $Hss - $band);
            $col = array_fill(0, $Wss * $bh, 0);
            $zb = array_fill(0, $Wss * $bh, 1.0);
            foreach ($tris as $T) {
                if ($T['by1'] < $band || $T['by0'] >= $band + $bh) {
                    continue;
                }
                [$a0, $b0, $c0, $a1, $b1, $c1] = $T['e'];
                $P = $T['P'];
                $mode = $T['mode'];
                $tex = $T['tex'];
                $studs = $T['studs'];
                $half = $T['half'];
                $nP = count($P);
                $ry0 = max($T['by0'], $band);
                $ry1 = min($T['by1'], $band + $bh - 1);
                $bx0 = $T['bx0'];
                $bx1 = $T['bx1'];
                if ($bx1 < $bx0) {
                    continue;
                }
                $color = $T['color'];
                $tint = $T['tint'];
                $iwA = $P[0][0];
                $iwB = $P[0][1];
                $iwC = $P[0][2];
                $zA = $P[1][0];
                $zB = $P[1][1];
                $zC = $P[1][2];
                if ($mode === 4) {
                    $mat = $T['mat'];
                    $vr = $color[0];
                    $vg = $color[1];
                    $vb = $color[2];
                    $va = $color[3];
                    $blk = $mat === 0 ? 1 : $SS;
                    $half = $blk / 2;
                    for ($by = intdiv($ry0, $blk) * $blk; $by <= $ry1; $by += $blk) {
                        $sy0 = max($by, $ry0);
                        $sy1 = min($by + $blk - 1, $ry1);
                        $cyc = $by + $half;
                        for ($bxx = intdiv($bx0, $blk) * $blk; $bxx <= $bx1; $bxx += $blk) {
                            $sx0 = max($bxx, $bx0);
                            $sx1 = min($bxx + $blk - 1, $bx1);
                            $shaded = 0;
                            for ($py = $sy0; $py <= $sy1; $py++) {
                                $y = $py + 0.5;
                                $row = ($py - $band) * $Wss;
                                for ($px = $sx0; $px <= $sx1; $px++) {
                                    $x = $px + 0.5;
                                    $l0 = $a0 * $x + $b0 * $y + $c0;
                                    if ($l0 < $EPS) {
                                        continue;
                                    }
                                    $l1 = $a1 * $x + $b1 * $y + $c1;
                                    if ($l1 < $EPS) {
                                        continue;
                                    }
                                    if (1 - $l0 - $l1 < $EPS) {
                                        continue;
                                    }
                                    $z = $zA * $x + $zB * $y + $zC;
                                    $idx = $row + $px;
                                    if ($z > $zb[$idx] || $z < 0 || $z > 1) {
                                        continue;
                                    }
                                    if ($shaded === 0) {
                                        $xc = $bxx + $half;
                                        $iw = $iwA * $xc + $iwB * $cyc + $iwC;
                                        $w = 1 / $iw;
                                        $u = ($P[2][0] * $xc + $P[2][1] * $cyc + $P[2][2]) * $w;
                                        $v = ($P[3][0] * $xc + $P[3][1] * $cyc + $P[3][2]) * $w;
                                        if ($tex->mip) {
                                            $t = $tex->sample(
                                                $u,
                                                $v,
                                                ($P[2][0] - $u * $iwA) * $w,
                                                ($P[3][0] - $v * $iwA) * $w,
                                                ($P[2][1] - $u * $iwB) * $w,
                                                ($P[3][1] - $v * $iwB) * $w
                                            );
                                        } else {
                                            $t = $tex->sample($u, $v);
                                        }
                                        if (isset($T['fx2016'])) {
                                            [$sr, $sg, $sb, $sa] = self::fxShade2016($T, $t);
                                        } elseif ($mat === 0) {
                                            $sr = min(1, $t[0] + $vr);
                                            $sg = min(1, $t[1] + $vg);
                                            $sb = min(1, $t[2] + $vb);
                                            $sa = $t[3] * $va;
                                        } elseif ($mat === 1) {
                                            $sr = min(1, min(1, $t[0] * 0.3 + $t[0]) + $vr);
                                            $sg = min(1, min(1, $t[1] * 0.3 + $t[1]) + $vg);
                                            $sb = min(1, min(1, $t[2] * 0.3 + $t[2]) + $vb);
                                            $sa = $t[3];
                                        } else {
                                            $sr = max(0, min(1, $vr + $t[0] - 0.5));
                                            $sg = max(0, min(1, $vg + $t[1] - 0.5));
                                            $sb = max(0, min(1, $vb + $t[2] - 0.5));
                                            $sa = $t[3] * $va;
                                        }
                                        if ($sa > 1) {
                                            $sa = 1;
                                        }
                                        $shaded = ($sa > 0 || (isset($T['fx2016']) && max($sr, $sg, $sb) > 0)) ? 1 : 2;
                                        $ia = 1 - $sa;
                                        $pr = $sr * (isset($T['fx2016']) ? 1 : $sa) * 255;
                                        $pg = $sg * (isset($T['fx2016']) ? 1 : $sa) * 255;
                                        $pb = $sb * (isset($T['fx2016']) ? 1 : $sa) * 255;
                                        $pa = $sa * 255;
                                        $k = $ia / 255;
                                    }
                                    if ($shaded === 2) {
                                        break 2;
                                    }
                                    $dc = $col[$idx];
                                    $nr = (int)round($pr + (($dc >> 16) & 255) * $ia);
                                    $ng = (int)round($pg + (($dc >> 8) & 255) * $ia);
                                    $nb = (int)round($pb + ($dc & 255) * $ia);
                                    $na = (int)round($pa + (($dc >> 24) & 255) * $ia);
                                    $col[$idx] = (min(255, $na) << 24) | (min(255, $nr) << 16) | (min(255, $ng) << 8) | min(255, $nb);
                                }
                            }
                        }
                    }
                    continue;
                }
                for ($py = $ry0; $py <= $ry1; $py++) {
                    $y = $py + 0.5;
                    $row = ($py - $band) * $Wss;
                    for ($px = $bx0; $px <= $bx1; $px++) {
                        $x = $px + 0.5;
                        $l0 = $a0 * $x + $b0 * $y + $c0;
                        if ($l0 < $EPS) {
                            continue;
                        }
                        $l1 = $a1 * $x + $b1 * $y + $c1;
                        if ($l1 < $EPS) {
                            continue;
                        }
                        if (1 - $l0 - $l1 < $EPS) {
                            continue;
                        }
                        $z = $zA * $x + $zB * $y + $zC;
                        $idx = $row + $px;
                        if ($z > $zb[$idx] || $z < 0 || $z > 1) {
                            continue;
                        }
                        $iw = $iwA * $x + $iwB * $y + $iwC;
                        $w = 1 / $iw;
                        $g0 = ($P[2][0] * $x + $P[2][1] * $y + $P[2][2]) * $w;
                        $g1 = ($P[3][0] * $x + $P[3][1] * $y + $P[3][2]) * $w;
                        $g2 = ($P[4][0] * $x + $P[4][1] * $y + $P[4][2]) * $w;
                        if ($mode === 1) {
                            $u = ($P[5][0] * $x + $P[5][1] * $y + $P[5][2]) * $w;
                            $v = ($P[6][0] * $x + $P[6][1] * $y + $P[6][2]) * $w;
                            $t = $tex->sample($u, $v);
                            $ar = $t[0];
                            $ag = $t[1];
                            $ab = $t[2];
                        } elseif ($mode === 3) {
                            $u = ($P[5][0] * $x + $P[5][1] * $y + $P[5][2]) * $w;
                            $v = ($P[6][0] * $x + $P[6][1] * $y + $P[6][2]) * $w;
                            if ($tex->mip) {
                                $t = $tex->sample(
                                    $u,
                                    $v,
                                    ($P[5][0] - $u * $iwA) * $w,
                                    ($P[6][0] - $v * $iwA) * $w,
                                    ($P[5][1] - $u * $iwB) * $w,
                                    ($P[6][1] - $v * $iwB) * $w
                                );
                            } else {
                                $t = $tex->sample($u, $v);
                            }
                            if ($t[3] < 0.5 && $tex->cut) {
                                continue;
                            }
                            $ar = $t[0] * $tint[0];
                            $ag = $t[1] * $tint[1];
                            $ab = $t[2] * $tint[2];
                        } elseif ($mode === 2) {
                            $lx = ($P[5][0] * $x + $P[5][1] * $y + $P[5][2]) * $w;
                            $ly = ($P[6][0] * $x + $P[6][1] * $y + $P[6][2]) * $w;
                            $lnz = ($P[7][0] * $x + $P[7][1] * $y + $P[7][2]) * $w;
                            $fu = ($fx1 - $lx) / $fdx;
                            $fv = ($fy1 - $ly) / $fdy;
                            $dlxdx = ($P[5][0] - $lx * $iwA) * $w;
                            $dlxdy = ($P[5][1] - $lx * $iwB) * $w;
                            $dlydx = ($P[6][0] - $ly * $iwA) * $w;
                            $dlydy = ($P[6][1] - $ly * $iwB) * $w;
                            $cu = $fu < 0 || $fu > 1;
                            $cv = $fv < 0 || $fv > 1;
                            $t = $tex->sample($fu < 0 ? 0.0 : ($fu > 1 ? 1.0 : $fu), $fv < 0 ? 0.0 : ($fv > 1 ? 1.0 : $fv),
                                $cu ? 0.0 : -$dlxdx / $fdx, $cv ? 0.0 : -$dlydx / $fdy, $cu ? 0.0 : -$dlxdy / $fdx, $cv ? 0.0 : -$dlydy / $fdy);
                            $k = $lnz <= -0.05 ? $t[3] : 0.0;
                            $ar = $color[0] + ($t[0] - $color[0]) * $k;
                            $ag = $color[1] + ($t[1] - $color[1]) * $k;
                            $ab = $color[2] + ($t[2] - $color[2]) * $k;
                        } else {
                            $ar = $color[0];
                            $ag = $color[1];
                            $ab = $color[2];
                        }
                        $lit = $amb + $key * $g0 + $fill * $g1;
                        $r = $ar * $lit;
                        $gg = $ag * $lit;
                        $bb = $ab * $lit;
                        if ($studs) {
                            $lny = ($P[10][0] * $x + $P[10][1] * $y + $P[10][2]) * $w;
                            if ($lny > 0.99) {
                                $ly = ($P[8][0] * $x + $P[8][1] * $y + $P[8][2]) * $w;
                                if ($ly > $half[1] - 0.01) {
                                    $lx = ($P[7][0] * $x + $P[7][1] * $y + $P[7][2]) * $w;
                                    $lz = ($P[9][0] * $x + $P[9][1] * $y + $P[9][2]) * $w;
                                    $st = $stud->sample(
                                        $half[0] - $lx,
                                        $half[2] - $lz,
                                        -($P[7][0] - $lx * $iwA) * $w,
                                        -($P[9][0] - $lz * $iwA) * $w,
                                        -($P[7][1] - $lx * $iwB) * $w,
                                        -($P[9][1] - $lz * $iwB) * $w
                                    );
                                    $sa = $st[3];
                                    $r += ($st[0] - $r) * $sa;
                                    $gg += ($st[1] - $gg) * $sa;
                                    $bb += ($st[2] - $bb) * $sa;
                                }
                            }
                        }
                        $highlight = isset($T['lightingOffset']) ? $this->specular2016($T, $P, $x, $y, $w, $g2) : $g2;
                        $sp = $ksk * $T['specular'] * $highlight;
                        $r += $sp;
                        $gg += $sp;
                        $bb += $sp;
                        $R8 = $r >= 1 ? 255 : ($r <= 0 ? 0 : (int)($r * 255 + 0.5));
                        $G8 = $gg >= 1 ? 255 : ($gg <= 0 ? 0 : (int)($gg * 255 + 0.5));
                        $B8 = $bb >= 1 ? 255 : ($bb <= 0 ? 0 : (int)($bb * 255 + 0.5));
                        $zb[$idx] = $z;
                        $col[$idx] = (255 << 24) | ($R8 << 16) | ($G8 << 8) | $B8;
                    }
                }
            }
            $cw = $Wss;
            $ch = $bh;
            $buf = [];
            foreach ($col as $c) {
                $buf[] = [($c >> 16) & 255, ($c >> 8) & 255, $c & 255, ($c >> 24) & 255];
            }
            unset($col, $zb);
            $s = $SS;
            while ($s > 1) {
                $nw = $cw >> 1;
                $nh = $ch >> 1;
                $nb = [];
                for ($y = 0; $y < $nh; $y++) {
                    for ($x = 0; $x < $nw; $x++) {
                        $i = 2 * $y * $cw + 2 * $x;
                        $p = $buf[$i];
                        $q = $buf[$i + 1];
                        $r = $buf[$i + $cw];
                        $t = $buf[$i + $cw + 1];
                        $nb[] = [
                            ($p[0] + $q[0] + $r[0] + $t[0] + 2) >> 2,
                            ($p[1] + $q[1] + $r[1] + $t[1] + 2) >> 2,
                            ($p[2] + $q[2] + $r[2] + $t[2] + 2) >> 2,
                            ($p[3] + $q[3] + $r[3] + $t[3] + 2) >> 2
                        ];
                    }
                }
                $buf = $nb;
                $cw = $nw;
                $ch = $nh;
                $s >>= 1;
            }
            foreach ($buf as $p) {
                $a = $p[3];
                if ($a === 0) {
                    $out .= "\0\0\0\0";
                    continue;
                }
                if ($a === 255) {
                    $out .= chr($p[0]) . chr($p[1]) . chr($p[2]) . "\xff";
                    continue;
                }
                $out .= chr(
                    min(255, intdiv($p[0] * 255 + ($a >> 1), $a))
                ) . chr(min(255, intdiv($p[1] * 255 + ($a >> 1), $a))) . chr(min(255, intdiv($p[2] * 255 + ($a >> 1), $a))) . chr($a);
            }
        }
        return Img::encodePNG($W, $H, $out);
    }
}
