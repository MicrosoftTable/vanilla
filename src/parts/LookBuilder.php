<?php
namespace Vanilla;

// turning shirts pants faces hats and gear into something drawable
trait LookBuilder {
    static function brick($n): array {
        $c = BrickColors::rgb($n);
        return [$c[0] / 255, $c[1] / 255, $c[2] / 255];
    }

    static function hex3(string $h): array {
        return [hexdec(substr($h, 1, 2)) / 255, hexdec(substr($h, 3, 2)) / 255, hexdec(substr($h, 5, 2)) / 255];
    }

    static function parseColor($v): array {
        if (is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/D', $v)) {
            return self::hex3($v);
        }
        if (is_int($v) && BrickColors::has($v)) {
            return self::brick($v);
        }
        throw new \InvalidArgumentException('Body colours need six colours, each written #rrggbb.');
    }

    private static function SHOULDER_R() {
        return self::CF([1, 0.5, 0], [0, 0, 1, 0, 1, 0, -1, 0, 0]);
    }

    private static function SHOULDER_R1() {
        return self::CF([-0.5, 0.5, 0], [0, 0, 1, 0, 1, 0, -1, 0, 0]);
    }

    private static function RIGHT_GRIP_C0() {
        return self::CF([0, -1, 0], [1, 0, 0, 0, 0, 1, 0, -1, 0]);
    }

    private static $BODY_ATT = [
        'Head' => ['hat' => [0, 0.6, 0], 'hair' => [0, 0.6, 0], 'facefront' => [0, 0, -0.6], 'facecenter' => [0, 0, 0]],
        'Torso' => ['neck' => [0, 1, 0], 'bodyfront' => [0, 0, -0.5], 'bodyback' => [0, 0, 0.5], 'leftcollar' => [-1, 1, 0], 'rightcollar' => [1, 1, 0], 'waistfront' => [0, -1, -0.5], 'waistcenter' => [0, -1, 0], 'waistback' => [0, -1, 0.5]],
        'Left Arm' => ['leftshoulder' => [0, 1, 0], 'leftgrip' => [0, -1, 0]],
        'Right Arm' => ['rightshoulder' => [0, 1, 0], 'rightgrip' => [0, -1, 0]],
        'Left Leg' => ['leftfoot' => [0, -1, 0]],
        'Right Leg' => ['rightfoot' => [0, -1, 0]],
    ];
    private static $ACC_TYPE_ATT = [
        1 => ['Head', 'hat'],
        2 => ['Head', 'hair'],
        3 => ['Head', 'facefront'],
        4 => ['Torso', 'neck'],
        5 => ['Left Arm', 'leftshoulder'],
        6 => ['Torso', 'bodyfront'],
        7 => ['Torso', 'bodyback'],
        8 => ['Torso', 'waistcenter'],
        15 => ['Left Leg', 'leftfoot'],
        16 => ['Right Leg', 'rightfoot'],
        18 => ['Head', 'facefront'],
        19 => ['Head', 'facefront']
    ];

    private static function partCFs(bool $raised, float $shoulderAngle = M_PI / 2): array {
        $torso = self::CF([0, 3, 0]);
        $cfs = [
            'Torso' => $torso,
            'Head' => self::CF([0, 4.5, 0]),
            'Left Arm' => self::CF([-1.5, 3, 0]),
            'Right Arm' => self::CF([1.5, 3, 0]),
            'Left Leg' => self::CF([-0.5, 1, 0]),
            'Right Leg' => self::CF([0.5, 1, 0])
        ];
        if ($raised) {
            $cfs['Right Arm'] = self::cfMul(
                self::cfMul(self::cfMul($torso, self::SHOULDER_R()), self::CF([0, 0, 0], self::rotZ($shoulderAngle))),
                self::cfInv(self::SHOULDER_R1())
            );
        }
        return $cfs;
    }

    private static function attachmentFor($name): ?array {
        $n = preg_replace('/[^a-z]/', '', preg_replace('/attachment$/', '', strtolower((string)$name)));
        foreach (self::$BODY_ATT as $pn => $a) {
            if (isset($a[$n])) {
                return [$pn, $n];
            }
        }
        return null;
    }

    private static function handleOf(Inst $x): ?Inst {
        $parts = array_values(array_filter($x->children, function ($c) {
            return in_array($c->cls, ['Part', 'MeshPart', 'UnionOperation', 'WedgePart'], true);
        }));
        foreach ($parts as $c) {
            if ($c->name === 'Handle') {
                return $c;
            }
        }
        return $parts[0] ?? null;
    }

    private static function partSize(Inst $h): array {
        $P = $h->props;
        return self::v3($P['size'] ?? $P['Size'] ?? $P['size3'] ?? $P['Size3'] ?? null, [1, 1, 1]);
    }

    private static function partColor(Inst $h): array {
        $P = $h->props;
        $c = $P['Color3uint8'] ?? $P['Color'] ?? null;
        if (is_array($c) && isset($c['r'])) {
            return [$c['r'], $c['g'], $c['b']];
        }
        if (isset($P['BrickColor'])) {
            $b = $P['BrickColor'];
            if (is_array($b) && isset($b['color'])) {
                return [$b['color']['r'], $b['color']['g'], $b['color']['b']];
            }
            return self::brick(is_array($b) ? ($b['brick'] ?? 194) : $b);
        }
        return self::brick(194);
    }

    private static function accessoryCF(Inst $acc, Inst $h, array $cfs): array {
        $atts = array_values(array_filter($h->children, function ($c) {
            return $c->cls === 'Attachment';
        }));
        $hit = null;
        foreach ($atts as $a) {
            $m = self::attachmentFor($a->name);
            if ($m) {
                $hit = [$a, $m];
                break;
            }
        }
        $at = (int)($acc->props['AccessoryType'] ?? 0);
        if (!$hit && $atts && isset(self::$ACC_TYPE_ATT[$at])) {
            $hit = [$atts[0], self::$ACC_TYPE_ATT[$at]];
        }
        if ($hit) {
            $body = self::cfMul($cfs[$hit[1][0]], self::CF(self::$BODY_ATT[$hit[1][0]][$hit[1][1]]));
            return self::cfMul($body, self::cfInv(self::cfFrom($hit[0]->props['CFrame'] ?? null)));
        }
        $ap = self::cfFrom($acc->props['AttachmentPoint'] ?? null);
        return self::cfMul(self::cfMul($cfs['Head'], self::CF([0, 0.5, 0])), self::cfInv($ap));
    }

    private static function gripCF(Inst $tool): array {
        $P = $tool->props;
        if (isset($P['Grip']['m'])) {
            return self::cfFrom($P['Grip']);
        }
        $r = self::v3($P['GripRight'] ?? null, [1, 0, 0]);
        $u = self::v3($P['GripUp'] ?? null, [0, 1, 0]);
        $f = self::v3($P['GripForward'] ?? null, [0, 0, -1]);
        $p = self::v3($P['GripPos'] ?? null, [0, 0, 0]);
        return self::CF($p, [$r[0], $u[0], -$f[0], $r[1], $u[1], -$f[1], $r[2], $u[2], -$f[2]]);
    }

    private static function paintSheets(array $look, Img $inlet): array {
        $css = function ($c) {
            return [(int)round($c[0] * 255), (int)round($c[1] * 255), (int)round($c[2] * 255)];
        };
        $fillRegion = function (Img $g, array $region, $col) use ($css) {
            foreach ($region as $r) {
                $g->fillRect($r[0] - 1, $r[1] - 1, $r[2] + 2, $r[3] + 2, $css($col));
            }
        };
        $T = self::TEMPLATE;
        $C = $look['colors'];
        $up = new Img(self::TPL_W, self::TPL_H);
        $lo = new Img(self::TPL_W, self::TPL_H);
        $up->fillRect(0, 0, self::TPL_W, self::TPL_H, $css($C['torso']));
        $fillRegion($up, $T['right'], $C['rightArm']);
        $fillRegion($up, $T['left'], $C['leftArm']);
        $fillRegion($up, $T['torso'], $C['torso']);
        $k = 1 / (1 - 2 * self::BEVEL);
        $flip = new Img($inlet->w, $inlet->h);
        for ($y = 0; $y < $inlet->h; $y++) {
            for ($x = 0; $x < $inlet->w; $x++) {
                $flip->px[$y * $inlet->w + $x] = $inlet->px[$y * $inlet->w + ($inlet->w - 1 - $x)];
            }
        }
        foreach (['right', 'left'] as $side) {
            $d = $T[$side]['D'];
            $iw = (int)round($d[2] * $k);
            $ih = (int)round($d[3] * $k);
            $up->drawScaled(
                $flip,
                (int)round($d[0] + $d[2] / 2 - $iw / 2),
                (int)round($d[1] + $d[3] / 2 - $ih / 2),
                $iw,
                $ih,
                [$d[0], $d[1], $d[2], $d[3]]
            );
        }
        $lo->fillRect(0, 0, self::TPL_W, self::TPL_H, $css($C['rightLeg']));
        $fillRegion($lo, $T['right'], $C['rightLeg']);
        $fillRegion($lo, $T['left'], $C['leftLeg']);
        if ($look['pants']) {
            $clip = [];
            foreach ($T['torso'] as $r) {
                $clip[] = [$r[0] - 1, $r[1] - 1, $r[2] + 2, $r[3] + 2];
            }
            $up->drawOver($look['pants'], $clip);
            $lo->drawOver($look['pants']);
        }
        if ($look['shirt']) {
            $up->drawOver($look['shirt']);
        }
        if ($look['tshirt']) {
            $f = $T['torso']['F'];
            $up->drawScaled($look['tshirt'], $f[0], $f[1], $f[2], $f[3]);
        }
        return [new Texture($up), new Texture($lo)];
    }

    private function buildHandleDrawable(Inst $h, array $cf, array &$notes): ?array {
        if ($this->renderer === '2016') {
            foreach ($h->children as $effect) {
                if ($effect->cls === 'ParticleEmitter') {
                    $notes[] = '2016: custom ParticleEmitter curves are not supported yet';
                }
            }
        }
        $sm = null;
        foreach ($h->children as $c) {
            if (in_array($c->cls, ['SpecialMesh', 'FileMesh', 'BlockMesh'], true)) {
                $sm = $c;
                break;
            }
        }
        $size = self::partSize($h);
        $item = ['material' => $h->props['Material'] ?? 256, 'cf' => $cf, 'size' => $size, 'color' => self::partColor($h), 'tint' => [1, 1, 1], 'scale' => [1, 1, 1], 'off' => [0, 0, 0], 'fitTo' => null,
            'fx' => array_values(array_filter($h->children, function ($c) {
            return in_array($c->cls, ['Fire', 'Smoke', 'Sparkles'], true);
        }))];
        $P = $sm ? $sm->props : [];
        if ($sm) {
            $item['scale'] = self::v3($P['Scale'] ?? null, [1, 1, 1]);
            $item['off'] = self::v3($P['Offset'] ?? null, [0, 0, 0]);
            if (isset($P['VertexColor']['x'])) {
                $item['tint'] = [$P['VertexColor']['x'], $P['VertexColor']['y'], $P['VertexColor']['z']];
            }
        }
        $type = $sm ? ($P['MeshType'] ?? null) : null;
        if ($sm && $sm->cls === 'BlockMesh') {
            $type = 'block';
        }
        $meshUrl = $sm ? ($P['MeshId']['url'] ?? null) : null;
        $texUrl = $sm ? ($P['TextureId']['url'] ?? null) : null;
        if ($h->cls === 'MeshPart') {
            $hp = $h->props;
            $meshUrl = ($hp['MeshId'] ?? $hp['MeshID'] ?? [])['url'] ?? null;
            $texUrl = ($hp['TextureID'] ?? $hp['TextureId'] ?? [])['url'] ?? null;
            $item['fitTo'] = $size;
            foreach ($h->children as $c) {
                if ($c->cls === 'SurfaceAppearance') {
                    if (!empty($c->props['ColorMap']['url'])) {
                        $texUrl = $c->props['ColorMap']['url'];
                    }
                    break;
                }
            }
        }
        $m = null;
        try {
            if (
                $meshUrl && (
                    $type === null || (is_numeric($type) && (int)$type === 5) || (
                        is_string($type) && strcasecmp($type, 'FileMesh') === 0
                    ) || $h->cls === 'MeshPart'
                )
            ) {
                if ($this->renderer === '2016') {
                    $m = $this->loadMesh2016($meshUrl);
                } else {
                    $b = $this->bytes($meshUrl);
                    if ($b === null) { throw new \RuntimeException('a mesh could not be fetched'); }
                    $m = self::cleanMesh(self::parseMesh2016($b));   // newer (v2, v3) meshes carry their V the other way up
                }
            } elseif ((is_numeric($type) && (int)$type === 0)
                || (is_string($type) && strcasecmp($type, 'Head') === 0)) {
                $m = $this->base()['headRaw'];
                $k = min($size[0] / 2, $size[1], $size[2]) / 1.25;
                $item['scale'] = [$item['scale'][0] * $k, $item['scale'][1] * $k, $item['scale'][2] * $k];
            } elseif ((is_numeric($type) && (int)$type === 3) || $type === 'Sphere') {
                $m = self::sphereMesh($size[0] / 2, $size[1] / 2, $size[2] / 2);
            } else {
                $m = self::plainBox($size[0] / 2, $size[1] / 2, $size[2] / 2);
            }
        } catch (\Throwable $e) {
            $notes[] = 'an item mesh could not be loaded' . ($meshUrl ? ' (' . substr((string)$meshUrl, 0, 60) . ')' : '');
            return null;
        }
        $tex = $texUrl ? $this->image($texUrl, 0, true) : null;
        if ($item['fitTo']) {
            $bb = self::meshBounds($m);
            $sc = [];
            $of = [];
            for ($k = 0; $k < 3; $k++) {
                $e = $bb['hi'][$k] - $bb['lo'][$k];
                $sc[$k] = $e > 1e-6 ? $item['fitTo'][$k] / $e : 1;
                $of[$k] = -($bb['hi'][$k] + $bb['lo'][$k]) / 2 * $sc[$k];
            }
            $item['scale'] = $sc;
            $item['off'] = $of;
        }
        $s = $item['scale'];
        $o = $item['off'];
        $pos = [];
        $nrm = [];
        foreach ($m['pos'] as $p) {
            $pos[] = [$p[0] * $s[0] + $o[0], $p[1] * $s[1] + $o[1], $p[2] * $s[2] + $o[2]];
        }
        foreach ($m['nrm'] as $n) {
            $nrm[] = self::norm([$s[0] ? $n[0] / $s[0] : 0, $s[1] ? $n[1] / $s[1] : 0, $s[2] ? $n[2] / $s[2] : 0]);
        }
        $uv = $m['uv'];
        if ($s[0] * $s[1] * $s[2] < 0) {
            for ($t = 0; $t + 2 < count($pos); $t += 3) {
                foreach ([&$pos, &$nrm, &$uv] as &$a) {
                    $x = $a[$t + 1];
                    $a[$t + 1] = $a[$t + 2];
                    $a[$t + 2] = $x;
                }
            }
        }
        unset($a);
        $item['geom'] = ['pos' => $pos, 'nrm' => $nrm, 'uv' => $uv];
        $item['tex'] = null;
        if ($tex) {
            $cut = self::alphaCutsOut($tex, $uv);
            $item['tex'] = new Texture($tex, true);
            $item['tex']->cut = $cut;
        }
        return $item;
    }

    private static function emptyLook(): array {
        return ['colors' => ['head' => self::brick(24), 'torso' => self::brick(23), 'leftArm' => self::brick(24), 'rightArm' => self::brick(24), 'leftLeg' => self::brick(119), 'rightLeg' => self::brick(119)],
            'headMesh' => null, 'bodyMeshes' => [], 'shirt' => null, 'pants' => null, 'tshirt' => null, 'face' => null, 'faceSet' => false, 'headSize' => null, 'accessories' => [], 'tool' => null, 'only' => '', 'notes' => []];
    }

    private function absorbTree(array &$look, array $tree, string $tag): void {
        $template = function (?Img $img, string $what) use (&$look, $tag) {
            if (!$img) {
                $look['notes'][] = "$tag: the $what image did not load";
                return null;
            }
            if ($img->w !== self::TPL_W || $img->h !== self::TPL_H) {
                $look['notes'][] = "$tag: the $what is not a " . self::TPL_W . '×' . self::TPL_H . ' template, so it was ignored';
                return null;
            }
            return $img;
        };
        $take = function (array $list, int $depth) use (&$take, &$look, $template, $tag) {
            if ($depth > 8) {
                return;
            }
            foreach ($list as $x) {
                $P = $x->props;
                // bundles carry an r6 and an r15 version and only r6 is drawn
                if (preg_match('/^r15/i', (string)$x->name) && !in_array($x->cls, ['Hat', 'Accessory', 'Accoutrement', 'Tool'], true)) {
                    continue;
                }
                if (in_array($x->cls, ['Part', 'MeshPart'], true) && strtolower((string)$x->name) === 'head' && !$look['headSize']) {
                    $look['headSize'] = self::v3($P['size'] ?? ($P['Size'] ?? null), [2, 1, 1]);
                }
                if ($x->cls === 'CharacterMesh') {
                    $names = [0 => 'head', 1 => 'torso', 2 => 'leftArm', 3 => 'rightArm', 4 => 'leftLeg', 5 => 'rightLeg',
                        'Head' => 'head', 'Torso' => 'torso', 'LeftArm' => 'leftArm', 'RightArm' => 'rightArm', 'LeftLeg' => 'leftLeg', 'RightLeg' => 'rightLeg'];
                    $key = $names[$P['BodyPart'] ?? 0] ?? null;
                    if ($key) { $look['bodyMeshes'][$key] = $x; }
                } elseif (in_array($x->cls, ['SpecialMesh', 'CylinderMesh', 'BlockMesh', 'FileMesh'], true)) {
                    $look['headMesh'] = $x;
                } elseif ($x->cls === 'Shirt') {
                    $im = $template($this->image($P['ShirtTemplate']['url'] ?? null), 'shirt');
                    if ($im) {
                        $look['shirt'] = $im;
                    }
                } elseif ($x->cls === 'Pants') {
                    $im = $template($this->image($P['PantsTemplate']['url'] ?? null), 'pants');
                    if ($im) {
                        $look['pants'] = $im;
                    }
                } elseif ($x->cls === 'ShirtGraphic') {
                    $im = $this->image($P['Graphic']['url'] ?? null);
                    if ($im) {
                        $look['tshirt'] = $im;
                    } else {
                        $look['notes'][] = "$tag: the t-shirt image did not load";
                    }
                } elseif ($x->cls === 'Decal') {
                    if (!$look['faceSet']) {
                        $look['faceSet'] = true;
                        $im = $this->image($P['Texture']['url'] ?? null);
                        if ($im) {
                            $look['face'] = $im;
                        } else {
                            $look['notes'][] = "$tag: the face image did not load";
                        }
                    }
                } elseif (in_array($x->cls, ['Hat', 'Accessory', 'Accoutrement'], true)) {
                    $look['accessories'][] = $x;
                } elseif ($x->cls === 'Tool') {
                    if (!$look['tool']) {
                        $look['tool'] = $x;
                    }
                } else {
                    $take($x->children, $depth + 1);
                }
            }
        };
        $take($tree['roots'], 0);
    }

    private function addAsset(array &$look, string $id): void {
        $b = $this->fetch($id);
        if ($b === null) {
            $look['notes'][] = "$id: could not be fetched";
            return;
        }
        if (Img::isImageBytes($b)) {
            $look['notes'][] = "$id: this is a bare image, not a shirt, pants, face, hat or gear asset, so it was refused";
            return;
        }
        // a package can carry body colours (a BodyColors asset in its list, like a charapp's bodycolors entry)
        if (strpos($b, 'BodyColors') !== false && ($bc = CharApp::bodyColors($b)) !== null) {
            $look['pkgColors'] = $bc;
            return;
        }
        try {
            $tree = Rbxm::parse($b);
        } catch (\Throwable $e) {
            $look['notes'][] = "$id: not a shirt, pants, face, hat or gear asset";
            return;
        }
        $this->absorbTree($look, $tree, $id);
    }
}
