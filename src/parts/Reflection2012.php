<?php
namespace Vanilla;

trait Reflection2012 {
    const FRESNEL_EXP_2012 = 3.55;
    private static $sky2012 = null;

    private static function reflectionParams2012(float $reflectance): array {
        $s = sqrt(max(0.0, min(1.0, $reflectance * 0.85)));
        return [(1 - $s) * 0.45 + $s, (1 - $s) * 0.05 + $s];
    }

    private function skyFaces2012(): array {
        if (self::$sky2012 === null) {
            self::$sky2012 = [];
            foreach (['up', 'dn', 'ft', 'bk', 'lf', 'rt'] as $f) {
                self::$sky2012[$f] = Img::decode(file_get_contents($this->dataDir . '/2012/sky/' . $f . '.png'));
            }
        }
        return self::$sky2012;
    }

    private static function skySample2012(Img $im, float $u, float $v): array {
        $x = max(0.0, min($im->w - 1.001, $u * $im->w - 0.5));
        $y = max(0.0, min($im->h - 1.001, $v * $im->h - 0.5));
        $x0 = (int)$x; $y0 = (int)$y; $fx = $x - $x0; $fy = $y - $y0;
        $o = [0.0, 0.0, 0.0];
        foreach ([[0, 0, (1 - $fx) * (1 - $fy)], [1, 0, $fx * (1 - $fy)], [0, 1, (1 - $fx) * $fy], [1, 1, $fx * $fy]] as [$dx, $dy, $wgt]) {
            $p = $im->px[($y0 + $dy) * $im->w + $x0 + $dx];
            $o[0] += (($p >> 16) & 255) * $wgt; $o[1] += (($p >> 8) & 255) * $wgt; $o[2] += ($p & 255) * $wgt;
        }
        return [$o[0] / 255, $o[1] / 255, $o[2] / 255];
    }

    private function skyColor2012(float $x, float $y, float $z): array {
        $F = $this->skyFaces2012();
        $ax = abs($x); $ay = abs($y); $az = abs($z);
        if ($ay >= $ax && $ay >= $az) {
            return $y > 0
                ? self::skySample2012($F['up'], ($z / $y + 1) / 2, ($x / $y + 1) / 2)
                : self::skySample2012($F['dn'], ($z / $ay + 1) / 2, (-$x / $ay + 1) / 2);
        }
        if ($az >= $ax) {
            return $z > 0
                ? self::skySample2012($F['ft'], (-$x / $z + 1) / 2, (1 - $y / $z) / 2)
                : self::skySample2012($F['bk'], ($x / $az + 1) / 2, (1 - $y / $az) / 2);
        }
        return $x > 0
            ? self::skySample2012($F['rt'], ($z / $x + 1) / 2, (1 - $y / $x) / 2)
            : self::skySample2012($F['lf'], (-$z / $ax + 1) / 2, (1 - $y / $ax) / 2);
    }

    private $envBlack2012 = false;

    private function reflect2012(array $T, array $P, float $x, float $y, float $w, float $r, float $g, float $b): array {
        $o = $T['reflOffset'];
        $v = [];
        for ($i = 0; $i < 6; $i++) {
            $pl = $P[$o + $i];
            $v[] = ($pl[0] * $x + $pl[1] * $y + $pl[2]) * $w;
        }
        $nl = sqrt($v[0] * $v[0] + $v[1] * $v[1] + $v[2] * $v[2]);
        $vl = sqrt($v[3] * $v[3] + $v[4] * $v[4] + $v[5] * $v[5]);
        if ($nl < 1e-9 || $vl < 1e-9) {
            return [$r, $g, $b];
        }
        $n = [$v[0] / $nl, $v[1] / $nl, $v[2] / $nl];
        $e = [$v[3] / $vl, $v[4] / $vl, $v[5] / $vl];
        $nv = $n[0] * $e[0] + $n[1] * $e[1] + $n[2] * $e[2];
        [$kr, $fmin] = $T['refl'];
        $fres = $kr + ($kr * $fmin - $kr) * pow(abs($nv), 1 / self::FRESNEL_EXP_2012);
        $R = [2 * $n[0] * $nv - $e[0], 2 * $n[1] * $nv - $e[1], 2 * $n[2] * $nv - $e[2]];
        $env = $this->envBlack2012 ? [0.0, 0.0, 0.0] : $this->skyColor2012($R[0], $R[1], $R[2]);
        $rc = [min(1.0, $kr * $env[0]), min(1.0, $kr * $env[1]), min(1.0, $kr * $env[2])];
        return [$r + ($rc[0] - $r) * $fres, $g + ($rc[1] - $g) * $fres, $b + ($rc[2] - $b) * $fres];
    }
}
