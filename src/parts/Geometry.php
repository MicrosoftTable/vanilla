<?php
namespace Vanilla;

// vectors and matrices plus the camera maths
trait Geometry {
    static function norm(array $v): array {
        $l = sqrt($v[0] * $v[0] + $v[1] * $v[1] + $v[2] * $v[2]) ?: 1;
        return [$v[0] / $l, $v[1] / $l, $v[2] / $l];
    }

    static function cross(array $a, array $b): array {
        return [$a[1] * $b[2] - $a[2] * $b[1], $a[2] * $b[0] - $a[0] * $b[2], $a[0] * $b[1] - $a[1] * $b[0]];
    }

    static function dot(array $a, array $b): float {
        return $a[0] * $b[0] + $a[1] * $b[1] + $a[2] * $b[2];
    }

    static function sub(array $a, array $b): array {
        return [$a[0] - $b[0], $a[1] - $b[1], $a[2] - $b[2]];
    }

    private static $I3 = [1, 0, 0, 0, 1, 0, 0, 0, 1];

    static function CF(array $p = [0, 0, 0], ?array $R = null): array {
        return ['p' => $p, 'R' => $R ?? self::$I3];
    }

    static function rmul(array $A, array $v): array {
        return [
            $A[0] * $v[0] + $A[1] * $v[1] + $A[2] * $v[2],
            $A[3] * $v[0] + $A[4] * $v[1] + $A[5] * $v[2],
            $A[6] * $v[0] + $A[7] * $v[1] + $A[8] * $v[2]
        ];
    }

    static function mm(array $A, array $B): array {
        $o = [];
        for ($r = 0; $r < 3; $r++) {
            for ($c = 0; $c < 3; $c++) {
                $o[] = $A[$r * 3] * $B[$c] + $A[$r * 3 + 1] * $B[3 + $c] + $A[$r * 3 + 2] * $B[6 + $c];
            }
        }
        return $o;
    }

    static function tr(array $A): array {
        return [$A[0], $A[3], $A[6], $A[1], $A[4], $A[7], $A[2], $A[5], $A[8]];
    }

    static function cfMul(array $a, array $b): array {
        $q = self::rmul($a['R'], $b['p']);
        return self::CF([$q[0] + $a['p'][0], $q[1] + $a['p'][1], $q[2] + $a['p'][2]], self::mm($a['R'], $b['R']));
    }

    static function cfInv(array $a): array {
        $Rt = self::tr($a['R']);
        $q = self::rmul($Rt, $a['p']);
        return self::CF([-$q[0], -$q[1], -$q[2]], $Rt);
    }

    static function cfApply(array $a, array $v): array {
        $q = self::rmul($a['R'], $v);
        return [$q[0] + $a['p'][0], $q[1] + $a['p'][1], $q[2] + $a['p'][2]];
    }

    static function cfFrom($v): array {
        return is_array($v) ? self::CF(
            [(float)($v['x'] ?? 0), (float)($v['y'] ?? 0), (float)($v['z'] ?? 0)],
            isset($v['m']) ? $v['m'] : self::$I3
        ) : self::CF();
    }

    static function rotZ(float $a): array {
        $c = cos($a);
        $s = sin($a);
        return [$c, -$s, 0, $s, $c, 0, 0, 0, 1];
    }

    static function v3($o, array $d): array {
        return is_array($o) && isset($o['x']) ? [(float)$o['x'], (float)$o['y'], (float)$o['z']] : $d;
    }

    static function perspective(float $fovy, float $aspect, float $near, float $far): array {
        $f = 1 / tan($fovy / 2);
        $nf = 1 / ($near - $far);
        return [$f / $aspect, 0, 0, 0, 0, $f, 0, 0, 0, 0, ($far + $near) * $nf, -1, 0, 0, 2 * $far * $near * $nf, 0];
    }

    static function lookAt(array $eye, array $target, array $up): array {
        $z = self::norm(self::sub($eye, $target));
        $x = self::norm(self::cross($up, $z));
        $y = self::cross($z, $x);
        return [
            $x[0],
            $y[0],
            $z[0],
            0,
            $x[1],
            $y[1],
            $z[1],
            0,
            $x[2],
            $y[2],
            $z[2],
            0,
            -self::dot($x, $eye),
            -self::dot($y, $eye),
            -self::dot($z, $eye),
            1
        ];
    }

    static function mul4(array $a, array $b): array {
        $o = [];
        for ($c = 0; $c < 4; $c++) {
            for ($r = 0; $r < 4; $r++) {
                $s = 0;
                for ($k = 0; $k < 4; $k++) {
                    $s += $a[$k * 4 + $r] * $b[$c * 4 + $k];
                }
                $o[$c * 4 + $r] = $s;
            }
        }
        ksort($o);
        return $o;
    }
}
