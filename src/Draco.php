<?php
namespace Vanilla;

// reads the draco geometry inside roblox mesh version 6 and 7 files
// only what roblox writes is handled: sequential connectivity with raw indices, generic, integer and quantized attributes
final class Draco {
    private string $b;
    private int $p;
    private int $end;

    /** @return array{faces: array, attrs: array} attrs are [type, components, values] with one entry per point */
    static function decode(string $bytes, int $off, int $len): array {
        $d = new self();
        $d->b = $bytes;
        $d->p = $off;
        $d->end = $off + $len;
        return $d->run();
    }

    private function u8(): int {
        if ($this->p >= $this->end) {
            throw new \RuntimeException('draco: ran out of data');
        }
        return ord($this->b[$this->p++]);
    }

    private function i8(): int {
        $v = $this->u8();
        return $v > 127 ? $v - 256 : $v;
    }

    private function u16(): int {
        return $this->u8() | ($this->u8() << 8);
    }

    private function u32(): int {
        return $this->u16() | ($this->u16() << 16);
    }

    private function i32(): int {
        $v = $this->u32();
        return $v >= 0x80000000 ? $v - 0x100000000 : $v;
    }

    private function f32(): float {
        if ($this->p + 4 > $this->end) {
            throw new \RuntimeException('draco: ran out of data');
        }
        $v = unpack('g', $this->b, $this->p)[1];
        $this->p += 4;
        return $v;
    }

    private function varint(): int {
        $v = 0;
        $s = 0;
        while (true) {
            $c = $this->u8();
            $v |= ($c & 127) << $s;
            if (!($c & 128)) {
                return $v;
            }
            $s += 7;
            if ($s > 56) {
                throw new \RuntimeException('draco: bad varint');
            }
        }
    }

    private function run(): array {
        if (substr($this->b, $this->p, 5) !== 'DRACO') {
            throw new \RuntimeException('draco: bad header');
        }
        $this->p += 5;
        $major = $this->u8();
        $minor = $this->u8();
        $type = $this->u8();
        $method = $this->u8();
        $flags = $this->u16();
        if ($major !== 2 || $minor < 2) {
            throw new \RuntimeException("draco: version $major.$minor is not supported");
        }
        if ($type !== 1) {
            throw new \RuntimeException('draco: not a triangle mesh');
        }
        if ($method !== 0) {
            throw new \RuntimeException('draco: only sequential meshes are supported');
        }
        if ($flags & 0x8000) {
            throw new \RuntimeException('draco: metadata is not supported');
        }
        // connectivity
        $nf = $this->varint();
        $np = $this->varint();
        if ($nf > 2000000 || $np > 2000000) {
            throw new \RuntimeException('draco: a mesh is too large');
        }
        if ($this->u8() !== 1) {
            throw new \RuntimeException('draco: only raw indices are supported');
        }
        $faces = [];
        for ($i = 0; $i < $nf; $i++) {
            $f = [];
            for ($k = 0; $k < 3; $k++) {
                if ($np < 256) {
                    $f[] = $this->u8();
                } elseif ($np < 65536) {
                    $f[] = $this->u16();
                } elseif ($np < (1 << 21)) {
                    $f[] = $this->varint();
                } else {
                    $f[] = $this->u32();
                }
            }
            $faces[] = $f;
        }
        // attributes
        $nd = $this->u8();
        if ($nd !== 1) {
            throw new \RuntimeException('draco: unexpected attribute decoders');
        }
        $na = $this->varint();
        if ($na < 1 || $na > 32) {
            throw new \RuntimeException('draco: bad attribute count');
        }
        $A = [];
        for ($i = 0; $i < $na; $i++) {
            $A[] = ['type' => $this->u8(), 'dt' => $this->u8(), 'nc' => $this->u8(), 'norm' => $this->u8(), 'id' => $this->varint()];
        }
        foreach ($A as &$a) {
            $a['dec'] = $this->u8();
        }
        unset($a);
        // portable values, one attribute after another
        foreach ($A as &$a) {
            $a['q'] = $this->portable($a, $np);
        }
        unset($a);
        // what the quantized ones need to become floats again
        foreach ($A as &$a) {
            if ($a['dec'] === 2) {
                $min = [];
                for ($c = 0; $c < $a['nc']; $c++) {
                    $min[] = $this->f32();
                }
                $range = $this->f32();
                $bits = $this->u8();
                $mx = (1 << $bits) - 1;
                $delta = $mx > 0 ? $range / $mx : 0.0;
                $out = [];
                $q = $a['q'];
                for ($i = 0; $i < $np; $i++) {
                    $row = [];
                    for ($c = 0; $c < $a['nc']; $c++) {
                        $row[] = $q[$i * $a['nc'] + $c] * $delta + $min[$c];
                    }
                    $out[] = $row;
                }
                $a['v'] = $out;
            } else {
                $out = [];
                $q = $a['q'];
                for ($i = 0; $i < $np; $i++) {
                    $out[] = array_slice($q, $i * $a['nc'], $a['nc']);
                }
                $a['v'] = $out;
            }
            unset($a['q']);
        }
        unset($a);
        return ['faces' => $faces, 'attrs' => array_map(function ($a) {
            return ['type' => $a['type'], 'nc' => $a['nc'], 'v' => $a['v']];
        }, $A)];
    }

    private static function typeSize(int $dt): int {
        return [1 => 1, 2 => 1, 3 => 2, 4 => 2, 5 => 4, 6 => 4, 7 => 8, 8 => 8, 9 => 4, 10 => 8, 11 => 1][$dt] ?? 0;
    }

    /** a flat list of the numbers of one attribute (floats for generic ones, integers for the others) */
    private function portable(array $a, int $np): array {
        $nc = $a['nc'];
        $n = $np * $nc;
        if ($a['dec'] === 0) {
            $sz = self::typeSize($a['dt']);
            if (!$sz || $this->p + $n * $sz > $this->end) {
                throw new \RuntimeException('draco: bad generic attribute');
            }
            $fmt = [1 => 'c', 2 => 'C', 3 => 's', 4 => 'v', 5 => 'l', 6 => 'V', 9 => 'g', 10 => 'e'][$a['dt']] ?? null;
            if ($fmt === null) {
                throw new \RuntimeException('draco: unsupported attribute type');
            }
            $out = array_values(unpack($fmt . $n, $this->b, $this->p));
            $this->p += $n * $sz;
            return $out;
        }
        if ($a['dec'] !== 1 && $a['dec'] !== 2) {
            throw new \RuntimeException('draco: unsupported attribute encoding');
        }
        $method = $this->i8();
        $wrap = false;
        if ($method !== -2) {
            $tr = $this->i8();
            if ($method < -1 || $method > 6) {
                throw new \RuntimeException('draco: bad prediction method');
            }
            if ($tr === 1) {
                if ($method !== 0) {
                    throw new \RuntimeException('draco: mesh prediction is not supported');
                }
                $wrap = true;
            }
        }
        $v = $this->integers($n, $nc);
        if (!$wrap) {
            foreach ($v as $i => $s) {
                $v[$i] = ($s & 1) ? -($s >> 1) - 1 : ($s >> 1);
            }
            return $v;
        }
        foreach ($v as $i => $s) {
            $v[$i] = ($s & 1) ? -($s >> 1) - 1 : ($s >> 1);
        }
        $min = $this->i32();
        $max = $this->i32();
        if ($min > $max) {
            throw new \RuntimeException('draco: bad wrap range');
        }
        $dif = 1 + $max - $min;
        for ($i = 0; $i < $n; $i++) {
            $pr = $i < $nc ? 0 : $v[$i - $nc];
            $pr = $pr > $max ? $max : ($pr < $min ? $min : $pr);
            $x = $pr + $v[$i];
            if ($x > $max) {
                $x -= $dif;
            } elseif ($x < $min) {
                $x += $dif;
            }
            $v[$i] = $x;
        }
        return $v;
    }

    /** the entropy coded integers of an attribute, as raw symbols */
    private function integers(int $n, int $nc): array {
        $compressed = $this->u8();
        if (!$compressed) {
            $nb = $this->u8();
            $out = [];
            for ($i = 0; $i < $n; $i++) {
                $v = 0;
                for ($k = 0; $k < $nb; $k++) {
                    $v |= $this->u8() << (8 * $k);
                }
                $out[] = $v;
            }
            return $out;
        }
        $scheme = $this->u8();
        if ($scheme === 0) {
            $tags = $this->rans(5);
            $bitStart = $this->p;
            $bit = 0;
            $out = [];
            for ($i = 0; $i < $n; $i += $nc) {
                $len = $tags();
                for ($j = 0; $j < $nc; $j++) {
                    $val = 0;
                    for ($k = 0; $k < $len; $k++) {
                        $o = $bit++;
                        $byte = $bitStart + ($o >> 3);
                        if ($byte < $this->end) {
                            $val |= ((ord($this->b[$byte]) >> ($o & 7)) & 1) << $k;
                        }
                    }
                    $out[] = $val;
                }
            }
            $this->p = $bitStart + (($bit + 7) >> 3);
            return $out;
        }
        if ($scheme === 1) {
            $maxBits = $this->u8();
            if ($maxBits < 1 || $maxBits > 18) {
                throw new \RuntimeException('draco: bad symbol width');
            }
            $next = $this->rans($maxBits);
            $out = [];
            for ($i = 0; $i < $n; $i++) {
                $out[] = $next();
            }
            return $out;
        }
        throw new \RuntimeException('draco: unknown symbol coding');
    }

    /** reads a probability table and the rans data behind it, and gives back a function that returns the next symbol */
    private function rans(int $bits): \Closure {
        $ns = $this->varint();
        if ($ns < 1 || $ns > 1 << 20) {
            throw new \RuntimeException('draco: bad symbol table');
        }
        $prob = array_fill(0, $ns, 0);
        for ($i = 0; $i < $ns; $i++) {
            $pd = $this->u8();
            $token = $pd & 3;
            if ($token === 3) {
                $off = $pd >> 2;
                if ($i + $off >= $ns) {
                    throw new \RuntimeException('draco: bad symbol table');
                }
                $i += $off;
            } else {
                $pr = $pd >> 2;
                for ($k = 0; $k < $token; $k++) {
                    $pr |= $this->u8() << (8 * ($k + 1) - 2);
                }
                $prob[$i] = $pr;
            }
        }
        $pb = max(12, min(20, intdiv(3 * $bits, 2)));
        $prec = 1 << $pb;
        $lut = [];
        $cum = [];
        $acc = 0;
        for ($i = 0; $i < $ns; $i++) {
            $cum[$i] = $acc;
            $acc += $prob[$i];
            if ($acc > $prec) {
                throw new \RuntimeException('draco: bad symbol table');
            }
            for ($j = $cum[$i]; $j < $acc; $j++) {
                $lut[$j] = $i;
            }
        }
        if ($acc !== $prec) {
            throw new \RuntimeException('draco: bad symbol table');
        }
        $nbytes = $this->varint();
        if ($nbytes < 1 || $this->p + $nbytes > $this->end) {
            throw new \RuntimeException('draco: bad rans size');
        }
        $buf = substr($this->b, $this->p, $nbytes);
        $this->p += $nbytes;
        $base = $prec * 4;
        $x = ord($buf[$nbytes - 1]) >> 6;
        $off = $nbytes - 1 - $x;
        if ($off < 0) {
            throw new \RuntimeException('draco: bad rans data');
        }
        $state = 0;
        for ($k = $x; $k >= 0; $k--) {
            $state = ($state << 8) | ord($buf[$off + $k]);
        }
        $state &= (1 << (6 + 8 * $x)) - 1;
        $state += $base;
        return function () use (&$state, &$off, $buf, $base, $prec, $lut, $prob, $cum): int {
            while ($state < $base && $off > 0) {
                $state = $state * 256 + ord($buf[--$off]);
            }
            $rem = $state % $prec;
            $quo = intdiv($state, $prec);
            $s = $lut[$rem];
            $state = $quo * $prob[$s] + $rem - $cum[$s];
            return $s;
        };
    }
}
