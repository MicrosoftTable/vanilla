<?php
namespace Vanilla;

// rendering one item on its own: hats and gear alone, heads and faces alone, clothing on a gray avatar, packages on a full avatar
trait ItemRender {
    private static function packageIds(string $body): array {
        $t = trim($body);
        if ($t === '' || strlen($t) > 4096 || $t[0] === '<' || !preg_match('/^[\x20-\x7e\s]+$/D', $t)) {
            return [];
        }
        $out = [];
        foreach (preg_split('/[;,|\s]+/', $t) as $piece) {
            $id = self::assetId($piece);
            if ($id !== null && !in_array($id, $out, true)) {
                $out[] = $id;
            }
        }
        return array_slice($out, 0, self::MAX_IDS);
    }

    private function renderItem(array $req): array {
        $id = self::parseId($req['item']);
        if ($id === null) {
            throw new \InvalidArgumentException('item is a number');
        }
        $size = self::SIZES[$req['size'] ?? '150x200'] ?? null;
        if (!$size) {
            throw new \InvalidArgumentException('Unknown size.');
        }
        $this->prefetch([$id]);
        $body = $this->fetch($id);
        if ($body === null) {
            throw new \InvalidArgumentException('item could not be fetched');
        }
        $ids = self::packageIds($body) ?: [$id];
        $this->loadWaves($ids);
        $look = self::emptyLook();
        foreach ($ids as $one) {
            $this->addAsset($look, $one);
        }
        $look['accessories'] = array_slice($look['accessories'], 0, self::MAX_ACCESSORIES);
        $body = (bool)($look['bodyMeshes'] || $look['headMesh']);
        $clothes = (bool)($look['shirt'] || $look['pants'] || $look['tshirt']);
        $worn = (bool)($look['accessories'] || $look['tool']);
        if ($worn && !$body && !$clothes && !$look['faceSet']) {
            $look['only'] = 'items';
        } elseif (!$worn && !$clothes && ($look['headMesh'] || $look['faceSet']) && !$look['bodyMeshes']) {
            $look['only'] = 'head';
        } elseif (!$worn && !$clothes && !$look['faceSet'] && !$look['headMesh'] && count($look['bodyMeshes']) === 1
            && in_array(array_key_first($look['bodyMeshes']), ['torso', 'rightArm', 'leftArm', 'rightLeg', 'leftLeg'], true)) {
            $look['only'] = 'part:' . array_key_first($look['bodyMeshes']);   // one body part: close and straight on
            $look['pkgview'] = true;
        } elseif ($clothes && !$body && !$worn && !$look['faceSet']) {
            $gray = self::brick(194);
            foreach ($look['colors'] as $k => $v) {
                $look['colors'][$k] = $gray;
            }
        } elseif (!$worn && !$clothes && !$body && !$look['faceSet']) {
            throw new \InvalidArgumentException('item is not a hat, gear, face, head, shirt, pants, t-shirt or package' . ($look['notes'] ? ' (' . $look['notes'][0] . ')' : ''));
        }
        // A package drawn on its own, like Roblox's catalog did: the parts the package brings are drawn with the
        // grey and blue checkered template (so the shape shows), and every other body part is plain white.
        if ($look['only'] === '' && $body && !$clothes) {
            $look['pkgview'] = true;
        }
        $SS = ($req['smooth'] ?? true) === false ? 1 : 4;
        $png = $this->draw($look, $size[0], $size[1], $SS, 'body');
        return ['png' => $png, 'notes' => $look['notes']];
    }

    // 2012 package parts: the part meshes and textures from CharacterMesh and a head from a head asset
    // returns the base with the new geometry plus a texture for each replaced part and one for the head
    private function characterBase2012(array $base, array &$look): array {
        $tex = [];
        $headTex = null;
        $key = ['torso' => 'torso', 'rightArm' => 'rightArm', 'leftArm' => 'leftArm', 'rightLeg' => 'rightLeg', 'leftLeg' => 'leftLeg'];
        $pkg = !empty($look['pkgview']);
        $tpl = null;
        if ($pkg) {
            $f = __DIR__ . '/../../data/package-template.png';
            $tpl = is_file($f) ? Img::decode((string)file_get_contents($f)) : null;
        }
        $T = self::TEMPLATE;
        $region = ['torso' => $T['torso'], 'rightArm' => $T['right'], 'leftArm' => $T['left'], 'rightLeg' => $T['right'], 'leftLeg' => $T['left']];
        $dims = ['torso' => [1, 1, 0.5], 'rightArm' => [0.5, 1, 0.5], 'leftArm' => [0.5, 1, 0.5], 'rightLeg' => [0.5, 1, 0.5], 'leftLeg' => [0.5, 1, 0.5]];
        foreach ($key as $part => $name) {
            $cm = $look['bodyMeshes'][$name] ?? null;
            if (!$cm) {
                if ($pkg) {
                    $look['colors'][$name] = self::brick(1);   // not part of the package: plain white (the BrickColor "White")
                    $tex[$part] = false;
                }
                continue;
            }
            $mesh = $this->meshFrom($cm->props['MeshId'] ?? null);
            if (!$mesh) {
                $look['notes'][] = $name . ': the package mesh could not be loaded';
                continue;
            }
            $base[$part] = $mesh;
            if ($pkg) {
                $look['colors'][$name] = self::brick(24);   // the package's own part sits on skin yellow (what its texture was made for), not on the default torso blue
            }
            $img = $this->partImage($cm->props, $look['colors'][$name]);
            if ($img) {
                $tex[$part] = new Texture($img);
                $tex[$part]->repeat = false;
            } else {
                // no texture of its own: lay the mesh onto the standard template, the way a shirt lies on the normal part.
                // Worn, it then takes the body colour, shirt and pants; drawn as a package item it takes the checkered template.
                if ($pkg && $tpl) {
                    // a package picture: the checker is laid on by the shape itself (see checkerMesh), so it can not stretch
                    [$base[$part], $tile] = self::checkerMesh($mesh, $tpl);
                    $tex[$part] = new Texture($tile);
                } else {
                    [$hx, $hy, $hz] = $dims[$part];
                    $base[$part] = self::templateMesh($mesh, $hx, $hy, $hz, $region[$part]);
                }
            }
        }
        $shape = $look['headMesh'];
        $cm = $look['bodyMeshes']['head'] ?? null;
        if ($pkg && !$shape && !$cm) {
            $look['colors']['head'] = self::brick(1);   // a head the package does not bring is white too
        }
        if ($shape || $cm) {
            $P = $shape ? $shape->props : $cm->props;
            $scale = $shape ? self::v3($P['Scale'] ?? null, [1.25, 1.25, 1.25]) : [1.25, 1.25, 1.25];
            $off = $shape ? self::v3($P['Offset'] ?? null, [0, 0, 0]) : [0, 0, 0];
            $mesh = $this->meshFrom($P['MeshId'] ?? null);
            if ($mesh) {
                $k = $scale;
            } else {
                $mesh = $base['headRaw'];
                $k = [$scale[0] / 1.25, $scale[1] / 1.25, $scale[2] / 1.25];
                $off = [$off[0], $off[1], $off[2]];
            }
            $pos = [];
            foreach ($mesh['pos'] as $p) {
                $pos[] = [$p[0] * $k[0] + $off[0], $p[1] * $k[1] + $off[1], $p[2] * $k[2] + $off[2]];
            }
            $mesh['pos'] = $pos;
            $base['head'] = $mesh;
            $base['headBox'] = self::meshBounds($mesh);
            $url = $shape ? ($P['TextureId'] ?? null) : ($P['BaseTextureId'] ?? null);
            $img = $this->image(is_array($url) ? ($url['url'] ?? null) : $url);
            if ($img) {
                $headTex = new Texture($img, true);
            }
        }
        return [$base, $tex, $headTex];
    }

    /**
     * The checkered template laid onto any mesh without stretching it. Each triangle is flattened along the axis it
     * faces most, and the checker is a small tile that repeats in studs (one square is an eighth of a stud, the same as on
     * the template), so a square is never longer than about 1.4 times its width, whatever the shape.
     * $tpl only lends its two colours. Returns [mesh with uv, the tile as an Img].
     */
    private static function checkerMesh(array $m, Img $tpl): array {
        $c1 = $tpl->px[12 * $tpl->w + 235];
        $c2 = $tpl->px[12 * $tpl->w + 243];
        $px = [];
        for ($y = 0; $y < 16; $y++) {
            for ($x = 0; $x < 16; $x++) {
                $px[] = ((($x >> 3) ^ ($y >> 3)) & 1) ? $c2 : $c1;
            }
        }
        $uv = [];
        $n = count($m['pos']);
        for ($i = 0; $i + 2 < $n; $i += 3) {
            $a = $m['pos'][$i];
            $b = $m['pos'][$i + 1];
            $c = $m['pos'][$i + 2];
            $e1 = [$b[0] - $a[0], $b[1] - $a[1], $b[2] - $a[2]];
            $e2 = [$c[0] - $a[0], $c[1] - $a[1], $c[2] - $a[2]];
            $nx = abs($e1[1] * $e2[2] - $e1[2] * $e2[1]);
            $ny = abs($e1[2] * $e2[0] - $e1[0] * $e2[2]);
            $nz = abs($e1[0] * $e2[1] - $e1[1] * $e2[0]);
            foreach ([$a, $b, $c] as $p) {
                if ($nz >= $nx && $nz >= $ny) {
                    $uv[] = [$p[0] * 4, $p[1] * 4];
                } elseif ($nx >= $ny) {
                    $uv[] = [$p[2] * 4, $p[1] * 4];
                } else {
                    $uv[] = [$p[0] * 4, $p[2] * 4];
                }
            }
        }
        return [['pos' => $m['pos'], 'nrm' => $m['nrm'], 'uv' => $uv], new Img(16, 16, $px)];
    }

    private function meshFrom($value): ?array {
        $v = self::meshContent2016($value);
        $b = $v ? $this->bytes($v) : null;
        if ($b === null) {
            return null;
        }
        try {
            return self::cleanMesh(self::parseMesh($b));
        } catch (\Throwable $e) {
            return null;
        }
    }

    // a part texture: the body colour with the base texture and then the overlay on top
    private function partImage(array $props, array $color): ?Img {
        $get = function ($k) use ($props) {
            $v = self::meshContent2016($props[$k] ?? null);
            return $v ? $this->image($v) : null;
        };
        $base = $get('BaseTextureId');
        if (!$base) {
            return null;
        }
        $img = new Img($base->w, $base->h);
        $img->fillRect(0, 0, $img->w, $img->h, [(int)round($color[0] * 255), (int)round($color[1] * 255), (int)round($color[2] * 255)]);
        $img->drawOver($base);
        $over = $get('OverlayTextureId');
        if ($over) {
            $img->drawScaled($over, 0, 0, $img->w, $img->h);
        }
        return $img;
    }
}
