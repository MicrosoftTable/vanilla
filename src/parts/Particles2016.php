<?php
namespace Vanilla;

// Fixed, unthrottled snapshot of ParticleEmitter.cpp/Emitter.cpp's legacy effects.
// These are Fire/Smoke/Sparkles, not the separately scripted ParticleEmitter class.
trait Particles2016 {
    private $particleTextures2016 = [];

    private function particleTexture2016(string $name): Texture {
        if (!isset($this->particleTextures2016[$name])) {
            $tex = new Texture(Img::decode(file_get_contents($this->dataDir . '/2016/' . $name . '.png')));
            $tex->repeat = false;
            $this->particleTextures2016[$name] = $tex;
        }
        return $this->particleTextures2016[$name];
    }

    private static function fxRecipes2016(Inst $inst): array {
        $P = $inst->props;
        if (($P['Enabled'] ?? true) === false) {
            return [];
        }
        $common = ['mat' => 0, 'rate' => 30, 'ttl' => [1.3, 1.3], 'speed' => [5, 5],
            'spread' => 100, 'box' => [0.4, 0.4, 0.4], 'offset' => [0, 0, 0],
            'size' => 0.37, 'grow' => 0.5, 'spin' => [40, 100], 'drag' => 0.2,
            'force' => [0, 1, 0], 'global' => [0, 0, 0], 'col' => [1, 1, 1, 0.9],
            'texture' => 'sparkles_main', 'strip' => 'sparkles_color', 'alpha' => 'common_alpha',
            'shader' => 'sparkles', 'blend' => 0.6];
        if ($inst->cls === 'Sparkles') {
            $c = self::fxCol($P['SparkleColor'] ?? $P['Color'] ?? null, [144 / 255, 25 / 255, 1]);
            $common['col'] = [$c[0], $c[1], $c[2], 0.9];
            return [$common, array_replace($common, ['rate' => 5, 'ttl' => [1.7, 1.7], 'speed' => [8, 8],
                'spread' => 150, 'offset' => [0, 4, 0], 'size' => 0.1, 'grow' => 0.2,
                'spin' => [-500, 500], 'drag' => 2, 'force' => [0, 0, 0]])];
        }
        if ($inst->cls === 'Smoke') {
            $size = max(0.1, min(100, self::fxNum(self::fxProp($P, ['Size', 'size_xml', 'size']), 1)));
            $rise = max(-25, min(25, self::fxNum(self::fxProp($P, ['RiseVelocity', 'riseVelocity_xml', 'riseVelocity']), 1)));
            $c = self::fxCol($P['Color'] ?? null, [1, 1, 1]);
            $opacity = max(0, min(1, self::fxNum(self::fxProp($P, ['Opacity', 'opacity_xml', 'opacity']), 0.5)));
            return [array_replace($common, ['mat' => 2, 'rate' => 7, 'ttl' => [5, 5],
                'speed' => [$rise * 0.9, $rise], 'spread' => 30, 'box' => [2, 2, 2],
                'size' => $size, 'grow' => 2, 'spin' => [-20, 20], 'drag' => 0.1,
                'force' => [0, 0, 0], 'global' => [0, 0, 0.4], 'col' => [$c[0], $c[1], $c[2], $opacity],
                'texture' => 'smoke_main', 'strip' => 'smoke_color', 'shader' => 'modulate', 'blend' => 1])];
        }
        if ($inst->cls === 'Fire') {
            $size = max(2, min(30, self::fxNum(self::fxProp($P, ['Size', 'size_xml', 'size']), 5))) / 3.5;
            $heat = max(-25, min(25, self::fxNum(self::fxProp($P, ['Heat', 'heat_xml', 'heat']), 9)));
            $c = self::fxCol($P['Color'] ?? null, [236 / 255, 139 / 255, 70 / 255]);
            $c1 = self::fxCol($P['SecondaryColor'] ?? null, [139 / 255, 80 / 255, 55 / 255]);
            $v = 0.4 * (0.2 * $size * $size + 0.2 * $heat);
            $fire = array_replace($common, ['mat' => 1, 'rate' => 65, 'ttl' => [1, 2], 'speed' => [$v, $v],
                'spread' => 10, 'box' => [$size / 4, $size / 4, $size / 4], 'size' => $size * 1.1,
                'grow' => -0.8 * $size, 'spin' => [100, 100], 'drag' => 0.4,
                'force' => [0, 0.5 * ($size * $size / 4 + 0.7 * $heat), 0],
                'col' => [4 * $c[0], 4 * $c[1], 4 * $c[2], 4], 'texture' => 'fire_main',
                'strip' => 'fire_color', 'alpha' => 'fire_alpha', 'shader' => 'crazy', 'blend' => 0.4]);
            $v1 = 0.4 * (0.1 * $size * $size + 0.2 * $heat);
            return [$fire, array_replace($fire, ['speed' => [$v1, $v1], 'box' => [$size / 2, $size / 2, $size / 2],
                'offset' => [0, 3 * (0.3 * $size + 0.05 * $heat), 0], 'size' => 0.2 * $size,
                'grow' => -0.1 * $size, 'col' => [$c1[0], $c1[1], $c1[2], 1],
                'texture' => 'fire_sparks_main', 'strip' => 'fire_sparks_color', 'alpha' => 'common_alpha',
                'shader' => 'add', 'blend' => 0])];
        }
        return [];
    }

    private function fxBuild2016(array $items, array $eye): array {
        $seed = 2016;
        $list = [];
        foreach ($items as $it) {
            foreach ($it['fx'] ?? [] as $inst) {
                foreach (self::fxRecipes2016($inst) as $recipe) {
                    $frame = self::cfMul($it['cf'], self::CF($recipe['offset']));
                    $sys = $recipe;
                    $sys['fx2016'] = ['shader' => $recipe['shader'], 'blend' => $recipe['blend']];
                    $sys['pos'] = $frame['p'];
                    $sys['texture'] = $this->particleTexture2016($recipe['texture']);
                    $strip = $this->particleTexture2016($recipe['strip']);
                    $alpha = $this->particleTexture2016($recipe['alpha']);
                    $sys['parts'] = self::fxSimulate2016($recipe, $frame, $seed, $strip, $alpha);
                    // The engine sorts particles by view depth inside each emitter.
                    $view = self::norm(self::sub($frame['p'], $eye));
                    usort($sys['parts'], function ($a, $b) use ($view) {
                        return self::dot([$b['x'], $b['y'], $b['z']], $view) <=> self::dot([$a['x'], $a['y'], $a['z']], $view);
                    });
                    $d = self::sub($frame['p'], $eye);
                    $sys['d2'] = self::dot($d, $d);
                    $sys['order'] = count($list);
                    $list[] = $sys;
                }
            }
        }
        usort($list, function ($a, $b) { return ($b['d2'] <=> $a['d2']) ?: ($a['order'] <=> $b['order']); });
        return $list;
    }

    private static function fxSimulate2016(array $r, array $frame, int &$seed, Texture $strip, Texture $alpha): array {
        $particles = [];
        $dt = 1 / 30;
        $rem = 0;
        $accel = self::rmul($frame['R'], $r['force']);
        for ($k = 0; $k < 3; $k++) { $accel[$k] += $r['global'][$k]; }
        for ($step = 0; $step < 180; $step++) {
            $keep = [];
            foreach ($particles as $p) {
                $p['ttl'] = max(0, $p['ttl'] - $dt);
                if ($p['ttl'] <= 0) { continue; }
                foreach (['x', 'y', 'z'] as $k => $axis) {
                    $p[$axis] += $p['v'][$k] * $dt + 0.5 * $accel[$k] * $dt * $dt;
                    // EnableParticleDrag defaults false in this source revision.
                    $p['v'][$k] = ($p['v'][$k] + $accel[$k] * $dt) * (1 - $r['drag'] * $dt);
                }
                $p['w'] += $r['grow'] * $dt;
                $p['h'] += $r['grow'] * $dt;
                $keep[] = $p;
            }
            $particles = $keep;
            $rem += $r['rate'] * $dt;
            $count = (int)floor($rem);
            $rem -= $count;
            for ($j = 0; $j < $count; $j++) {
                $v = [];
                for ($k = 0; $k < 10; $k++) { $v[] = self::fxRand($seed); }
                $pos = self::cfApply($frame, [($v[0] - 0.5) * $r['box'][0], ($v[1] - 0.5) * $r['box'][1], ($v[2] - 0.5) * $r['box'][2]]);
                $theta = M_PI / 2 + (2 * $v[3] - 1) * $r['spread'] / 57.3;
                $phi = M_PI / 2 + (2 * $v[4] - 1) * $r['spread'] / 57.3;
                $speed = $r['speed'][0] + $v[5] * ($r['speed'][1] - $r['speed'][0]);
                $vel = self::rmul($frame['R'], [$speed * sin($phi) * cos($theta), $speed * sin($phi) * sin($theta), $speed * cos($phi)]);
                $life = $r['ttl'][0] + $v[6] * ($r['ttl'][1] - $r['ttl'][0]);
                $particles[] = ['x' => $pos[0], 'y' => $pos[1], 'z' => $pos[2], 'v' => $vel, 'ttl' => $life, 'life' => $life,
                    'w' => $r['size'], 'h' => $r['size'], 'rotation' => (2 * $v[7] - 1) * 90 / 57.3,
                    'spin' => ($r['spin'][0] + $v[8] * ($r['spin'][1] - $r['spin'][0])) / 57.3, 'line' => $v[9]];
            }
        }
        foreach ($particles as &$p) {
            $age = 1 - $p['ttl'] / $p['life'];
            $p['rot'] = $p['rotation'] + $p['spin'] * $p['ttl'];
            $p['col'] = $r['col'];
            $p['curve'] = $strip->sample($age, $p['line']);
            $p['curve'][3] = $alpha->sample($age, $p['line'])[0];
        }
        unset($p);
        return $particles;
    }

    // Returns premultiplied RGB and blend alpha, matching the four legacy shaders.
    private static function fxShade2016(array $T, array $t): array {
        $c = $T['color'];
        $v = $T['curve'];
        $shader = $T['fx2016']['shader'];
        $a = $t[3] * $v[3];
        $rgb = [];
        for ($k = 0; $k < 3; $k++) {
            if ($shader === 'sparkles') {
                $base = $v[$k] * $c[$k];
                $rgb[] = ($t[3] < 0.5 ? $base * (2 * $t[3]) : $base + ($t[$k] - $base) * (2 * $t[3] - 1)) * $v[3];
            } elseif ($shader === 'crazy') {
                $rgb[] = $t[$k] * $c[$k] * $a;
            } elseif ($shader === 'add') {
                $rgb[] = ($t[$k] + $v[$k]) * $c[$k] * $a;
            } else {
                $rgb[] = $t[$k] * $v[$k] * $c[$k] * $a * $c[3];
            }
        }
        $blendAlpha = $shader === 'modulate' ? $a * $c[3] : $a * $T['fx2016']['blend'];
        return [$rgb[0], $rgb[1], $rgb[2], max(0, min(1, $blendAlpha))];
    }
}
