<?php
namespace Vanilla;

// the fast rasteriser: shades once per pixel and supersamples only edges
trait FastRaster {
    private static $LOD_K = 0.5;

    private static function avg4(int $c1, int $c2, int $c3, int $c4): int {
        $e = (($c1 & 0x00FF00FF) + ($c2 & 0x00FF00FF) + ($c3 & 0x00FF00FF) + ($c4 & 0x00FF00FF) + 0x00020002) >> 2 & 0x00FF00FF;
        $o = ((($c1 >> 8) & 0x00FF00FF) + (($c2 >> 8) & 0x00FF00FF) + (($c3 >> 8) & 0x00FF00FF) + (($c4 >> 8) & 0x00FF00FF) + 0x00020002) >> 2 & 0x00FF00FF;
        return $e | ($o << 8);
    }

    // shades each pixel once and only supersamples edge pixels
    private function rasteriseFast(array $tris, int $W, int $H, int $SS, Texture $stud, array $faceBox): string {
        $S2 = $SS * $SS;
        $N = $W * $H;
        $EPS = -1e-9;
        $doEdges = $SS > 1;
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
        $dk = $SS > 1 ? $SS * self::$LOD_K : 1.0;
        $stud->lod = true;
        foreach ($tris as $T) {
            if ($T['tex'] && $T['tex']->mip) {
                $T['tex']->lod = true;
            }
        }

        $shade = function (array $T, float $x, float $y) use (
            $amb,
            $key,
            $fill,
            $ksk,
            $fx1,
            $fy1,
            $fdx,
            $fdy,
            $dk,
            $stud
        ): int {
            $P = $T['P'];
            $mode = $T['mode'];
            $tex = $T['tex'];
            $color = $T['color'];
            $tint = $T['tint'];
            $iwA = $P[0][0];
            $iwB = $P[0][1];
            $w = 1 / ($iwA * $x + $iwB * $y + $P[0][2]);
            $g0 = ($P[2][0] * $x + $P[2][1] * $y + $P[2][2]) * $w;
            $g1 = ($P[3][0] * $x + $P[3][1] * $y + $P[3][2]) * $w;
            $g2 = ($P[4][0] * $x + $P[4][1] * $y + $P[4][2]) * $w;
            if ($mode === 1) {
                $u = ($P[5][0] * $x + $P[5][1] * $y + $P[5][2]) * $w;
                $v = ($P[6][0] * $x + $P[6][1] * $y + $P[6][2]) * $w;
                $t = $tex->sample(
                    $u,
                    $v,
                    ($P[5][0] - $u * $iwA) * $w * $dk,
                    ($P[6][0] - $v * $iwA) * $w * $dk,
                    ($P[5][1] - $u * $iwB) * $w * $dk,
                    ($P[6][1] - $v * $iwB) * $w * $dk
                );
                $ar = $t[0];
                $ag = $t[1];
                $ab = $t[2];
            } elseif ($mode === 3) {
                $u = ($P[5][0] * $x + $P[5][1] * $y + $P[5][2]) * $w;
                $v = ($P[6][0] * $x + $P[6][1] * $y + $P[6][2]) * $w;
                $t = $tex->sample(
                    $u,
                    $v,
                    ($P[5][0] - $u * $iwA) * $w * $dk,
                    ($P[6][0] - $v * $iwA) * $w * $dk,
                    ($P[5][1] - $u * $iwB) * $w * $dk,
                    ($P[6][1] - $v * $iwB) * $w * $dk
                );
                $ar = $t[0] * $tint[0];
                $ag = $t[1] * $tint[1];
                $ab = $t[2] * $tint[2];
            } elseif ($mode === 2) {
                $lx = ($P[5][0] * $x + $P[5][1] * $y + $P[5][2]) * $w;
                $ly = ($P[6][0] * $x + $P[6][1] * $y + $P[6][2]) * $w;
                $lnz = ($P[7][0] * $x + $P[7][1] * $y + $P[7][2]) * $w;
                $fu = ($fx1 - $lx) / $fdx;
                $fv = ($fy1 - $ly) / $fdy;
                $dlxdx = ($P[5][0] - $lx * $iwA) * $w * $dk;
                $dlxdy = ($P[5][1] - $lx * $iwB) * $w * $dk;
                $dlydx = ($P[6][0] - $ly * $iwA) * $w * $dk;
                $dlydy = ($P[6][1] - $ly * $iwB) * $w * $dk;
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
            if ($T['studs']) {
                $lny = ($P[10][0] * $x + $P[10][1] * $y + $P[10][2]) * $w;
                if ($lny > 0.99) {
                    $half = $T['half'];
                    $ly = ($P[8][0] * $x + $P[8][1] * $y + $P[8][2]) * $w;
                    if ($ly > $half[1] - 0.01) {
                        $lx = ($P[7][0] * $x + $P[7][1] * $y + $P[7][2]) * $w;
                        $lz = ($P[9][0] * $x + $P[9][1] * $y + $P[9][2]) * $w;
                        $st = $stud->sample(
                            $half[0] - $lx,
                            $half[2] - $lz,
                            -($P[7][0] - $lx * $iwA) * $w * $dk,
                            -($P[9][0] - $lz * $iwA) * $w * $dk,
                            -($P[7][1] - $lx * $iwB) * $w * $dk,
                            -($P[9][1] - $lz * $iwB) * $w * $dk
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
            return (255 << 24) | ($R8 << 16) | ($G8 << 8) | $B8;
        };
        $alphaOK = function (array $T, float $x, float $y): bool {
            $P = $T['P'];
            $iwA = $P[0][0];
            $iwB = $P[0][1];
            $w = 1 / ($iwA * $x + $iwB * $y + $P[0][2]);
            $u = ($P[5][0] * $x + $P[5][1] * $y + $P[5][2]) * $w;
            $v = ($P[6][0] * $x + $P[6][1] * $y + $P[6][2]) * $w;
            $t = $T['tex']->sample(
                $u,
                $v,
                ($P[5][0] - $u * $iwA) * $w,
                ($P[6][0] - $v * $iwA) * $w,
                ($P[5][1] - $u * $iwB) * $w,
                ($P[6][1] - $v * $iwB) * $w
            );
            return $t[3] >= 0.5;
        };

        $TA = [];
        $isCut = [];
        $isFx = [];
        $hasFx = false;
        foreach ($tris as $ti => $T) {
            [$a0, $b0, $c0, $a1, $b1, $c1] = $T['e'];
            $a2 = -($a0 + $a1);
            $b2 = -($b0 + $b1);
            $c2 = 1 - $c0 - $c1;
            $h0 = sqrt($a0 * $a0 + $b0 * $b0);
            $h1 = sqrt($a1 * $a1 + $b1 * $b1);
            $h2 = sqrt($a2 * $a2 + $b2 * $b2);
            $TA[$ti] = [$a0, $b0, $c0, $a1, $b1, $c1, $a2, $b2, $c2, $h0 > 0 ? 1 / $h0 : 0.0, $h1 > 0 ? 1 / $h1 : 0.0, $h2 > 0 ? 1 / $h2 : 0.0,
                $T['P'][1][0], $T['P'][1][1], $T['P'][1][2],
                max(0, intdiv($T['bx0'], $SS)), min($W - 1, intdiv($T['bx1'], $SS)), max(0, intdiv($T['by0'], $SS)), min($H - 1, intdiv($T['by1'], $SS))];
            $isFx[$ti] = $T['mode'] === 4;
            if ($isFx[$ti]) {
                $hasFx = true;
            }
            $isCut[$ti] = $T['mode'] === 3 && $T['tex']->cut && $T['tex']->hasAlpha;
        }
        $R = $SS * 0.7072 + 1e-6;

        // the picture is drawn in bands of rows to keep memory low
        $B = max(8, intdiv($hasFx ? 262144 : 1048576, $W * $S2));
        if ($B > $H) {
            $B = $H;
        }
        $nb = intdiv($H + $B - 1, $B);
        $bucket = array_fill(0, $nb, []);
        foreach ($tris as $ti => $T) {
            if ($T['bx1'] < $T['bx0']) {
                continue;
            }
            $Y0 = $TA[$ti][17];
            $Y1 = $TA[$ti][18];
            if ($Y1 < $Y0) {
                continue;
            }
            $k1 = min($nb - 1, intdiv($Y1 + 1, $B));
            for ($k = intdiv(max(0, $Y0 - 1), $B); $k <= $k1; $k++) {
                $bucket[$k][] = $ti;
            }
        }
        $sparkPerPixel = max($W, $H) > 420;
        $sz = [];
        $blendFx = function (array $T, array $c, int $X, int $Y, array &$s, int $k0) use (
            $SS,
            $EPS,
            &$sz,
            $sparkPerPixel
        ) {
            [$a0, $b0, $c0, $a1, $b1, $c1, , , , , , , $zA, $zB, $zC] = $c;
            $P = $T['P'];
            $mat = $T['mat'];
            $tex = $T['tex'];
            $color = $T['color'];
            $vr = $color[0];
            $vg = $color[1];
            $vb = $color[2];
            $va = $color[3];
            $iwA = $P[0][0];
            $iwB = $P[0][1];
            $iwC = $P[0][2];
            $blk = $mat === 0 && !$sparkPerPixel ? 1 : $SS;
            $half = $blk / 2;
            $bx = $X * $SS;
            $by = $Y * $SS;
            for ($oy = 0; $oy < $SS; $oy += $blk) {
                for ($ox = 0; $ox < $SS; $ox += $blk) {
                    $shaded = 0;
                    for ($j = $oy; $j < $oy + $blk; $j++) {
                        $y = $by + $j + 0.5;
                        for ($i = $ox; $i < $ox + $blk; $i++) {
                            $x = $bx + $i + 0.5;
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
                            $k = $j * $SS + $i;
                            if ($z > $sz[$k0 + $k] || $z < 0 || $z > 1) {
                                continue;
                            }
                            if ($shaded === 0) {
                                $xc = $bx + $ox + $half;
                                $yc = $by + $oy + $half;
                                $iw = $iwA * $xc + $iwB * $yc + $iwC;
                                $w = 1 / $iw;
                                $u = ($P[2][0] * $xc + $P[2][1] * $yc + $P[2][2]) * $w;
                                $v = ($P[3][0] * $xc + $P[3][1] * $yc + $P[3][2]) * $w;
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
                            }
                            if ($shaded === 2) {
                                break 2;
                            }
                            $dc = $s[$k];
                            $nr = (int)round($pr + (($dc >> 16) & 255) * $ia);
                            $ng = (int)round($pg + (($dc >> 8) & 255) * $ia);
                            $nb = (int)round($pb + ($dc & 255) * $ia);
                            $na = (int)round($pa + (($dc >> 24) & 255) * $ia);
                            $s[$k] = (min(255, $na) << 24) | (min(255, $nr) << 16) | (min(255, $ng) << 8) | min(255, $nb);
                        }
                    }
                }
            }
        };

        $png = new PngStream($W, $H);

        for ($band = 0; $band < $nb; $band++) {
            $Ya = $band * $B;
            $Yb = min($H, $Ya + $B);
            $E0 = max(0, $Ya - 1);
            $E1 = min($H - 1, $Yb);
            $NE = ($E1 - $E0 + 1) * $W;
            $btris = $bucket[$band];
            $rows = $E1 - $E0 + 1;

            $idA = array_fill(0, $NE, -1);
            $zbA = array_fill(0, $NE, 1.0);
            $edge = array_fill(0, $NE, 0);
            $elist = [];
            $rmin = array_fill(0, $rows, 1 << 30);
            $rmax = array_fill(0, $rows, -1);
            foreach ($btris as $ti) {
                if ($isFx[$ti]) {
                    continue;
                }
                $T = $tris[$ti];
                [
                    $a0,
                    $b0,
                    $c0,
                    $a1,
                    $b1,
                    $c1,
                    $a2,
                    $b2,
                    $c2,
                    $n0,
                    $n1,
                    $n2,
                    $zA,
                    $zB,
                    $zC,
                    $X0,
                    $X1,
                    $Y0,
                    $Y1
                ] = $TA[$ti];
                if ($Y0 < $E0) {
                    $Y0 = $E0;
                }
                if ($Y1 > $E1) {
                    $Y1 = $E1;
                }
                $cut = $isCut[$ti];
                for ($Y = $Y0; $Y <= $Y1; $Y++) {
                    $y = ($Y + 0.5) * $SS;
                    $xl = -1e18;
                    $xh = 1e18;
                    $t = $b0 * $y + $c0;
                    if ($a0 > 1e-12) {
                        $v = ($EPS - $t) / $a0;
                        if ($v > $xl) {
                            $xl = $v;
                        }
                    } elseif ($a0 < -1e-12) {
                        $v = ($EPS - $t) / $a0;
                        if ($v < $xh) {
                            $xh = $v;
                        }
                    } elseif ($t < $EPS) {
                        continue;
                    }
                    $t = $b1 * $y + $c1;
                    if ($a1 > 1e-12) {
                        $v = ($EPS - $t) / $a1;
                        if ($v > $xl) {
                            $xl = $v;
                        }
                    } elseif ($a1 < -1e-12) {
                        $v = ($EPS - $t) / $a1;
                        if ($v < $xh) {
                            $xh = $v;
                        }
                    } elseif ($t < $EPS) {
                        continue;
                    }
                    $t = $b2 * $y + $c2;
                    if ($a2 > 1e-12) {
                        $v = ($EPS - $t) / $a2;
                        if ($v > $xl) {
                            $xl = $v;
                        }
                    } elseif ($a2 < -1e-12) {
                        $v = ($EPS - $t) / $a2;
                        if ($v < $xh) {
                            $xh = $v;
                        }
                    } elseif ($t < $EPS) {
                        continue;
                    }
                    if ($xl > $xh) {
                        continue;
                    }
                    $Xa = (int)ceil($xl / $SS - 0.5);
                    if ($Xa < $X0) {
                        $Xa = $X0;
                    }
                    $Xb = (int)floor($xh / $SS - 0.5);
                    if ($Xb > $X1) {
                        $Xb = $X1;
                    }
                    if ($Xb < $Xa) {
                        continue;
                    }
                    $lr = $Y - $E0;
                    $row = $lr * $W;
                    $zy = $zB * $y + $zC;
                    $inBand = $Y >= $Ya && $Y < $Yb;
                    $any = false;
                    for ($X = $Xa; $X <= $Xb; $X++) {
                        $x = ($X + 0.5) * $SS;
                        $z = $zA * $x + $zy;
                        $p = $row + $X;
                        if ($z > $zbA[$p] || $z < 0 || $z > 1) {
                            continue;
                        }
                        if ($cut) {
                            $ok = $alphaOK($T, $x, $y);
                            if ($doEdges && $inBand && !$edge[$p]) {
                                $m = $SS * 0.35;
                                if (
                                    $alphaOK($T, $x - $m, $y - $m) !== $ok
                                        || $alphaOK($T, $x + $m, $y - $m) !== $ok
                                        || $alphaOK($T, $x - $m, $y + $m) !== $ok
                                        || $alphaOK($T, $x + $m, $y + $m) !== $ok
                                ) {
                                    $edge[$p] = 1;
                                    $elist[] = $p;
                                }
                            }
                            if (!$ok) {
                                continue;
                            }
                        }
                        $zbA[$p] = $z;
                        $idA[$p] = $ti;
                        $any = true;
                    }
                    if ($any || $cut) {
                        if ($Xa < $rmin[$lr]) {
                            $rmin[$lr] = $Xa;
                        }
                        if ($Xb > $rmax[$lr]) {
                            $rmax[$lr] = $Xb;
                        }
                    }
                }
            }

            if ($doEdges) {
                for ($lr = 0; $lr < $rows; $lr++) {
                    $Y = $E0 + $lr;
                    $lo = min(
                        $rmin[$lr],
                        $lr > 0 ? $rmin[$lr - 1] : 1 << 30,
                        $lr + 1 < $rows ? $rmin[$lr + 1] : 1 << 30
                    ) - 1;
                    $hi = max($rmax[$lr], $lr > 0 ? $rmax[$lr - 1] : -1, $lr + 1 < $rows ? $rmax[$lr + 1] : -1) + 1;
                    if ($hi < 0) {
                        continue;
                    }
                    if ($lo < 0) {
                        $lo = 0;
                    }
                    if ($hi > $W - 1) {
                        $hi = $W - 1;
                    }
                    $row = $lr * $W;
                    $down = $lr + 1 < $rows;
                    $mineP = $Y >= $Ya && $Y < $Yb;
                    $mineQ = $Y + 1 >= $Ya && $Y + 1 < $Yb;
                    if (!$mineP && !$mineQ) {
                        continue;
                    }
                    for ($X = $lo; $X <= $hi; $X++) {
                        $p = $row + $X;
                        $a = $idA[$p];
                        if ($mineP && $X + 1 < $W && $idA[$p + 1] !== $a) {
                            if (!$edge[$p]) {
                                $edge[$p] = 1;
                                $elist[] = $p;
                            }
                            if (!$edge[$p + 1]) {
                                $edge[$p + 1] = 1;
                                $elist[] = $p + 1;
                            }
                        }
                        if ($down) {
                            $q = $p + $W;
                            if ($idA[$q] !== $a) {
                                if ($mineP && !$edge[$p]) {
                                    $edge[$p] = 1;
                                    $elist[] = $p;
                                }
                                if ($mineQ && !$edge[$q]) {
                                    $edge[$q] = 1;
                                    $elist[] = $q;
                                }
                            }
                            if ($X + 1 < $W && $idA[$q + 1] !== $a) {
                                if ($mineP && !$edge[$p]) {
                                    $edge[$p] = 1;
                                    $elist[] = $p;
                                }
                                if ($mineQ && !$edge[$q + 1]) {
                                    $edge[$q + 1] = 1;
                                    $elist[] = $q + 1;
                                }
                            }
                            if ($X > 0 && $idA[$q - 1] !== $a) {
                                if ($mineP && !$edge[$p]) {
                                    $edge[$p] = 1;
                                    $elist[] = $p;
                                }
                                if ($mineQ && !$edge[$q - 1]) {
                                    $edge[$q - 1] = 1;
                                    $elist[] = $q - 1;
                                }
                            }
                        }
                    }
                }
            }

            $pl = [];
            if ($hasFx) {
                foreach ($btris as $ti) {
                    if (!$isFx[$ti]) {
                        continue;
                    }
                    [$a0, $b0, $c0, $a1, $b1, $c1, $a2, $b2, $c2, $n0, $n1, $n2, , , , $X0, $X1, $Y0, $Y1] = $TA[$ti];
                    if ($Y0 < $Ya) {
                        $Y0 = $Ya;
                    }
                    if ($Y1 > $Yb - 1) {
                        $Y1 = $Yb - 1;
                    }
                    for ($Y = $Y0; $Y <= $Y1; $Y++) {
                        $row = ($Y - $E0) * $W;
                        $cy = ($Y + 0.5) * $SS;
                        for ($X = $X0; $X <= $X1; $X++) {
                            $cx = ($X + 0.5) * $SS;
                            if (
                                (
                                    $a0 * $cx + $b0 * $cy + $c0
                                ) * $n0 < -$R || ($a1 * $cx + $b1 * $cy + $c1) * $n1 < -$R || ($a2 * $cx + $b2 * $cy + $c2) * $n2 < -$R
                            ) {
                                continue;
                            }
                            $p = $row + $X;
                            if (!$edge[$p]) {
                                $edge[$p] = 1;
                                $elist[] = $p;
                            }
                            $pl[$p][] = $ti;
                        }
                    }
                }
            }

            $ord = [];
            $sz = [];
            $sid = [];
            $nE = count($elist);
            if ($nE) {
                foreach ($elist as $k => $p) {
                    $ord[$p] = $k;
                }
                $sz = array_fill(0, $nE * $S2, 1.0);
                $sid = array_fill(0, $nE * $S2, -1);
                foreach ($btris as $ti) {
                    if ($isFx[$ti]) {
                        continue;
                    }
                    $T = $tris[$ti];
                    [
                        $a0,
                        $b0,
                        $c0,
                        $a1,
                        $b1,
                        $c1,
                        $a2,
                        $b2,
                        $c2,
                        $n0,
                        $n1,
                        $n2,
                        $zA,
                        $zB,
                        $zC,
                        $X0,
                        $X1,
                        $Y0,
                        $Y1
                    ] = $TA[$ti];
                    if ($Y0 < $Ya) {
                        $Y0 = $Ya;
                    }
                    if ($Y1 > $Yb - 1) {
                        $Y1 = $Yb - 1;
                    }
                    $cut = $isCut[$ti];
                    for ($Y = $Y0; $Y <= $Y1; $Y++) {
                        $row = ($Y - $E0) * $W;
                        $cy = ($Y + 0.5) * $SS;
                        for ($X = $X0; $X <= $X1; $X++) {
                            $p = $row + $X;
                            if (!$edge[$p]) {
                                continue;
                            }
                            $cx = ($X + 0.5) * $SS;
                            $l0 = $a0 * $cx + $b0 * $cy + $c0;
                            $d0 = $l0 * $n0;
                            if ($d0 < -$R) {
                                continue;
                            }
                            $l1 = $a1 * $cx + $b1 * $cy + $c1;
                            $d1 = $l1 * $n1;
                            if ($d1 < -$R) {
                                continue;
                            }
                            $l2 = $a2 * $cx + $b2 * $cy + $c2;
                            $d2 = $l2 * $n2;
                            if ($d2 < -$R) {
                                continue;
                            }
                            $all = $d0 > $R && $d1 > $R && $d2 > $R;
                            $k0 = $ord[$p] * $S2;
                            $bx = $X * $SS;
                            $by = $Y * $SS;
                            for ($j = 0; $j < $SS; $j++) {
                                $y = $by + $j + 0.5;
                                $zy = $zB * $y + $zC;
                                $ty0 = $b0 * $y + $c0;
                                $ty1 = $b1 * $y + $c1;
                                for ($i = 0; $i < $SS; $i++) {
                                    $x = $bx + $i + 0.5;
                                    if (!$all) {
                                        if ($a0 * $x + $ty0 < $EPS) {
                                            continue;
                                        }
                                        if ($a1 * $x + $ty1 < $EPS) {
                                            continue;
                                        }
                                        if (1 - ($a0 * $x + $ty0) - ($a1 * $x + $ty1) < $EPS) {
                                            continue;
                                        }
                                    }
                                    $z = $zA * $x + $zy;
                                    $k = $k0 + $j * $SS + $i;
                                    if ($z > $sz[$k] || $z < 0 || $z > 1) {
                                        continue;
                                    }
                                    if ($cut && !$alphaOK($T, $x, $y)) {
                                        continue;
                                    }
                                    $sz[$k] = $z;
                                    $sid[$k] = $ti;
                                }
                            }
                        }
                    }
                }
            }
            unset($zbA);

            $out = '';
            for ($Y = $Ya; $Y < $Yb; $Y++) {
                $row = ($Y - $E0) * $W;
                $line = [];
                for ($X = 0; $X < $W; $X++) {
                    $p = $row + $X;
                    if (!$edge[$p]) {
                        $id = $idA[$p];
                        if ($id < 0) {
                            $line[] = 0;
                            continue;
                        }
                        $line[] = (($shade($tris[$id], ($X + 0.5) * $SS, ($Y + 0.5) * $SS) & 0xFFFFFF) << 8) | 0xFF;
                        continue;
                    }
                    $k0 = $ord[$p] * $S2;
                    $bx = $X * $SS;
                    $by = $Y * $SS;
                    $cnt = [];
                    $sx = [];
                    $sy = [];
                    for ($k = 0; $k < $S2; $k++) {
                        $id = $sid[$k0 + $k];
                        if ($id < 0) {
                            continue;
                        }
                        if (isset($cnt[$id])) {
                            $cnt[$id]++;
                            $sx[$id] += $bx + $k % $SS + 0.5;
                            $sy[$id] += $by + intdiv($k, $SS) + 0.5;
                        } else {
                            $cnt[$id] = 1;
                            $sx[$id] = $bx + $k % $SS + 0.5;
                            $sy[$id] = $by + intdiv($k, $SS) + 0.5;
                        }
                    }
                    if (!$cnt && !isset($pl[$p])) {
                        $line[] = 0;
                        continue;
                    }
                    $cc = [];
                    foreach ($cnt as $id => $n) {
                        $cc[$id] = $shade($tris[$id], $sx[$id] / $n, $sy[$id] / $n);
                    }
                    $s = [];
                    for ($k = 0; $k < $S2; $k++) {
                        $id = $sid[$k0 + $k];
                        $s[$k] = $id < 0 ? 0 : $cc[$id];
                    }
                    if (isset($pl[$p])) {
                        foreach ($pl[$p] as $ti) {
                            $blendFx($tris[$ti], $TA[$ti], $X, $Y, $s, $k0);
                        }
                    }
                    if ($SS === 4) {
                        $c = self::avg4(
                            self::avg4($s[0], $s[1], $s[4], $s[5]),
                            self::avg4($s[2], $s[3], $s[6], $s[7]),
                            self::avg4($s[8], $s[9], $s[12], $s[13]),
                            self::avg4($s[10], $s[11], $s[14], $s[15])
                        );
                    } elseif ($SS === 2) {
                        $c = self::avg4($s[0], $s[1], $s[2], $s[3]);
                    } else {
                        $c = $s[0];
                    }
                    $a = ($c >> 24) & 255;
                    if ($a === 0) {
                        $line[] = 0;
                        continue;
                    }
                    $r = ($c >> 16) & 255;
                    $g = ($c >> 8) & 255;
                    $b = $c & 255;
                    if ($a < 255) {
                        $h = $a >> 1;
                        $r = min(255, intdiv($r * 255 + $h, $a));
                        $g = min(255, intdiv($g * 255 + $h, $a));
                        $b = min(255, intdiv($b * 255 + $h, $a));
                    }
                    $line[] = ($r << 24) | ($g << 16) | ($b << 8) | $a;
                }
                $png->row(pack('N*', ...$line));
            }
            unset($idA, $edge, $elist, $ord, $sid, $pl);
        }
        return $png->finish();
    }
}
