<?php
namespace Vanilla;

// textures behave like webgl1 ones so power of two sizes get mipmaps
final class Texture {
    public $levels = [];
    public $repeat;
    public $mip;
    public $lod = false;
    public $maxLevel = 0;
    public $cut = true;
    public $hasAlpha = false;

    function __construct(Img $im, bool $hat = false) {
        if ($hat) {
            $tw = 1;
            while ($tw < $im->w) {
                $tw *= 2;
            }
            $th = 1;
            while ($th < $im->h) {
                $th *= 2;
            }
            $tw = min($tw, 2048);
            $th = min($th, 2048);
            if ($tw !== $im->w || $th !== $im->h) {
                $im = $im->resized($tw, $th);
            }
            foreach ($im->px as $c) {
                if ($c < 0xFF000000) {
                    $this->hasAlpha = true;
                    break;
                }
            }
        }
        $w = $im->w;
        $h = $im->h;
        $pot = ($w & ($w - 1)) === 0 && ($h & ($h - 1)) === 0;
        $this->repeat = $pot;
        $this->mip = $pot;
        $this->levels[0] = [$w, $h, $im->px];
        $m = 0;
        while ($w > 1 || $h > 1) {
            $w = max(1, $w >> 1);
            $h = max(1, $h >> 1);
            $m++;
        }
        $this->maxLevel = $m;
    }

    private function lvl(int $l): array {
        if (isset($this->levels[$l])) {
            return $this->levels[$l];
        }
        [$w, $h, $P] = $this->lvl($l - 1);
        $nw = max(1, $w >> 1);
        $nh = max(1, $h >> 1);
        $N = [];
        $w1 = $w > 1;
        $h1 = $h > 1;
        for ($y = 0; $y < $nh; $y++) {
            $ra = 2 * $y * $w;
            $rb = $h1 ? $ra + $w : $ra;
            for ($x = 0; $x < $nw; $x++) {
                $xa = 2 * $x;
                $xb = $w1 ? $xa + 1 : $xa;
                $c1 = $P[$ra + $xa];
                $c2 = $P[$ra + $xb];
                $c3 = $P[$rb + $xa];
                $c4 = $P[$rb + $xb];
                $e = (($c1 & 0x00FF00FF) + ($c2 & 0x00FF00FF) + ($c3 & 0x00FF00FF) + ($c4 & 0x00FF00FF) + 0x00020002) >> 2 & 0x00FF00FF;
                $o = (
                    (($c1 >> 8) & 0x00FF00FF) + (($c2 >> 8) & 0x00FF00FF) + (($c3 >> 8) & 0x00FF00FF) + (($c4 >> 8) & 0x00FF00FF) + 0x00020002
                ) >> 2 & 0x00FF00FF;
                $N[] = $e | ($o << 8);
            }
        }
        return $this->levels[$l] = [$nw, $nh, $N];
    }

    function sample(
        float $u,
        float $v,
        float $dudx = 0.0,
        float $dvdx = 0.0,
        float $dudy = 0.0,
        float $dvdy = 0.0
    ): array {
        if (!$this->mip && !$this->lod) {
            return $this->bilinear(0, $u, $v);
        }
        $w = $this->levels[0][0];
        $h = $this->levels[0][1];
        $rx = ($dudx * $w) ** 2 + ($dvdx * $h) ** 2;
        $ry = ($dudy * $w) ** 2 + ($dvdy * $h) ** 2;
        $rho2 = max($rx, $ry);
        if ($rho2 <= 1.0) {
            return $this->bilinear(0, $u, $v);
        }
        $lambda = 0.5 * log($rho2, 2);
        $max = $this->maxLevel;
        if ($lambda >= $max) {
            return $this->bilinear($max, $u, $v);
        }
        $l0 = (int)floor($lambda);
        $f = $lambda - $l0;
        $a = $this->bilinear($l0, $u, $v);
        if ($f <= 0) {
            return $a;
        }
        $b = $this->bilinear($l0 + 1, $u, $v);
        return [
            $a[0] + ($b[0] - $a[0]) * $f,
            $a[1] + ($b[1] - $a[1]) * $f,
            $a[2] + ($b[2] - $a[2]) * $f,
            $a[3] + ($b[3] - $a[3]) * $f
        ];
    }

    function bilinear(int $lvl, float $u, float $v): array {
        [$w, $h, $P] = $this->lvl($lvl);
        $x = $u * $w - 0.5;
        $y = $v * $h - 0.5;
        $x0 = (int)floor($x);
        $y0 = (int)floor($y);
        $fx = $x - $x0;
        $fy = $y - $y0;
        if ($this->repeat) {
            $xa = (($x0 % $w) + $w) % $w;
            $xb = ($xa + 1) % $w;
            $ya = (($y0 % $h) + $h) % $h;
            $yb = ($ya + 1) % $h;
        } else {
            $xa = $x0 < 0 ? 0 : ($x0 >= $w ? $w - 1 : $x0);
            $xb = $x0 + 1 < 0 ? 0 : ($x0 + 1 >= $w ? $w - 1 : $x0 + 1);
            $ya = $y0 < 0 ? 0 : ($y0 >= $h ? $h - 1 : $y0);
            $yb = $y0 + 1 < 0 ? 0 : ($y0 + 1 >= $h ? $h - 1 : $y0 + 1);
        }
        $c00 = $P[$ya * $w + $xa];
        $c10 = $P[$ya * $w + $xb];
        $c01 = $P[$yb * $w + $xa];
        $c11 = $P[$yb * $w + $xb];
        $w00 = (1 - $fx) * (1 - $fy) / 255;
        $w10 = $fx * (1 - $fy) / 255;
        $w01 = (1 - $fx) * $fy / 255;
        $w11 = $fx * $fy / 255;
        return [
            (($c00 >> 16) & 255) * $w00 + (($c10 >> 16) & 255) * $w10 + (($c01 >> 16) & 255) * $w01 + (($c11 >> 16) & 255) * $w11,
            (($c00 >> 8) & 255) * $w00 + (($c10 >> 8) & 255) * $w10 + (($c01 >> 8) & 255) * $w01 + (($c11 >> 8) & 255) * $w11,
            ($c00 & 255) * $w00 + ($c10 & 255) * $w10 + ($c01 & 255) * $w01 + ($c11 & 255) * $w11,
            (($c00 >> 24) & 255) * $w00 + (($c10 >> 24) & 255) * $w10 + (($c01 >> 24) & 255) * $w01 + (($c11 >> 24) & 255) * $w11,
        ];
    }

}
