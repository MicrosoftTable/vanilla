<?php
namespace Vanilla;

// rendering one item on its own: hats and gear alone, heads and faces alone, clothing on a gray avatar, packages on a full avatar
trait ItemRender {
    private $partImgCache = [];   // body part textures already put together in this run (parts often share one)

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
        $pkgIds = self::packageIds($body);
        $ids = $pkgIds ?: [$id];
        $this->loadWaves($ids);
        $look = self::emptyLook();
        foreach ($ids as $one) {
            $this->addAsset($look, $one);
        }
        $look['accessories'] = array_slice($look['accessories'], 0, self::MAX_ACCESSORIES);
        if (!empty($look['pkgColors'])) {
            // the package's own body colours (what its thumbnail was drawn with): head, torso, left arm, right arm, left leg, right leg
            foreach (['head', 'torso', 'leftArm', 'rightArm', 'leftLeg', 'rightLeg'] as $j => $k) {
                $look['colors'][$k] = self::parseColor($look['pkgColors'][$j]);
            }
        }
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
        if (!$pkgIds || empty($look['pkgColors'])) {
            unset($look['pkgColors']);
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
                    if (empty($look['pkgColors'])) {
                        $look['colors'][$name] = self::brick(1);   // not part of the package: plain white (the BrickColor "White")
                    }
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
            if ($pkg && empty($look['pkgColors'])) {
                $look['colors'][$name] = self::brick(24);   // the package's own part sits on skin yellow (what its texture was made for), not on the default torso blue
            }
            $img = $this->partImage($cm->props, $look['colors'][$name], $pkg);
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
        if ($pkg && !$shape && !$cm && empty($look['pkgColors'])) {
            $look['colors']['head'] = self::brick(1);   // a head the package does not bring is white too
        }
        if ($shape || $cm) {
            $P = $shape ? $shape->props : $cm->props;
            $scale = $shape ? self::v3($P['Scale'] ?? null, [1.25, 1.25, 1.25]) : [1.25, 1.25, 1.25];
            $off = $shape ? self::v3($P['Offset'] ?? null, [0, 0, 0]) : [0, 0, 0];
            if ($shape) {
                // a head that says where its neck is (NeckRigAttachment) sits on the body by that point, not by the middle of the head:
                // the classic head's neck is at (0, -0.5, 0), so a head whose neck is lower sits higher
                $neck = self::neckRig($shape);
                if ($neck) {
                    $off = [$off[0] - $neck[0], $off[1] - ($neck[1] + 0.5), $off[2] - $neck[2]];
                }
            }
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
            if ($shape) {
                $img = $this->image(self::meshContent2016($P['TextureId'] ?? null));
            } else {
                // a head from a CharacterMesh: its ids are numbers, a base texture with an overlay on top (like the other parts)
                $img = $this->partImage($P, [1, 1, 1]);
            }
            if ($img) {
                // a head texture with see-through parts sits on the head colour (the wearer's, or the package's), like a body part's
                $c = $look['colors']['head'];
                $flat = new Img($img->w, $img->h);
                $flat->fillRect(0, 0, $flat->w, $flat->h, [(int)round($c[0] * 255), (int)round($c[1] * 255), (int)round($c[2] * 255)]);
                $flat->drawOver($img);
                // the head's own face (a Decal in the head asset, or the standard face) goes on the front of the shape, the way a
                // face lies on a head: it is drawn into the head texture where the front of the mesh is
                $face = $look['faceSet'] ? $look['face'] : (preg_match('/\bNoFace\b/', (string)($P['Tags'] ?? '')) ? null : ($base["face"] ?? null));
                if ($face && $shape) {
                    self::bakeFace($flat, ['pos' => $pos, 'nrm' => $mesh['nrm'], 'uv' => $mesh['uv']], $face, $look['headSize'] ?: [2, 1, 1]);
                }
                $headTex = new Texture($flat, true);
            } elseif ($shape && self::meshContent2016($P['TextureId'] ?? null)) {
                $look['notes'][] = 'head: the texture ' . self::meshContent2016($P['TextureId']) . ' could not be loaded, so the head has no picture on it';
            }
            if (!$headTex && $shape && preg_match('/\bNoFace\b/', (string)($P['Tags'] ?? ''))) {
                // a head that carries its own face (tag NoFace) never gets the standard face drawn on it: plain head colour instead
                $c = $look['colors']['head'];
                $flat = new Img(4, 4);
                $flat->fillRect(0, 0, 4, 4, [(int)round($c[0] * 255), (int)round($c[1] * 255), (int)round($c[2] * 255)]);
                $headTex = new Texture($flat, true);
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

    /**
     * Draw a face decal into a head texture. The decal sits on the front of the head (the side facing -z) the way the standard
     * face does on the standard head (FACE_BOX, measured against the half width of the shape); every texel that belongs to a
     * front-facing part of the mesh takes the decal's colour there.
     */
    private static function bakeFace(Img $tex, array $mesh, Img $face, array $size): void {
        $n = count($mesh['pos']);
        if ($n < 3) {
            return;
        }
        // the decal is stretched over the front of the head: the width and height of the part of the shape that faces front
        $lox = $loy = 1e9;
        $hix = $hiy = -1e9;
        for ($i = 0; $i < $n; $i++) {
            if ($mesh['nrm'][$i][2] < -0.5) {
                $lox = min($lox, $mesh['pos'][$i][0]); $hix = max($hix, $mesh['pos'][$i][0]);
                $loy = min($loy, $mesh['pos'][$i][1]); $hiy = max($hiy, $mesh['pos'][$i][1]);
            }
        }
        if ($hix <= $lox || $hiy <= $loy) {
            $lox = -$size[0] / 2; $hix = $size[0] / 2; $loy = -$size[1] / 2; $hiy = $size[1] / 2;
        }
        // measured against the picture Roblox draws for this head: the face covers about 1.35 times the front's width (centred)
        // and 1.045 times its height, starting a little above the top
        $fdx = ($hix - $lox) * 1.35;
        $fdy = ($hiy - $loy) * 1.045;
        $fx1 = ($hix + $lox) / 2 + $fdx / 2;
        $fy1 = $hiy + ($hiy - $loy) * 0.042;
        $W = $tex->w;
        $H = $tex->h;
        $FW = $face->w;
        $FH = $face->h;
        for ($i = 0; $i + 2 < $n; $i += 3) {
            $nz = ($mesh['nrm'][$i][2] + $mesh['nrm'][$i + 1][2] + $mesh['nrm'][$i + 2][2]) / 3;
            if ($nz > -0.05) {
                continue;
            }
            $a = $mesh['uv'][$i];
            $b = $mesh['uv'][$i + 1];
            $c = $mesh['uv'][$i + 2];
            $ax = $a[0] * $W; $ay = $a[1] * $H;
            $bx = $b[0] * $W; $by = $b[1] * $H;
            $cx = $c[0] * $W; $cy = $c[1] * $H;
            $den = ($by - $cy) * ($ax - $cx) + ($cx - $bx) * ($ay - $cy);
            if (abs($den) < 1e-9) {
                continue;
            }
            $x0 = max(0, (int)floor(min($ax, $bx, $cx) - 1));
            $x1 = min($W - 1, (int)ceil(max($ax, $bx, $cx) + 1));
            $y0 = max(0, (int)floor(min($ay, $by, $cy) - 1));
            $y1 = min($H - 1, (int)ceil(max($ay, $by, $cy) + 1));
            $pa = $mesh['pos'][$i];
            $pb = $mesh['pos'][$i + 1];
            $pc = $mesh['pos'][$i + 2];
            for ($y = $y0; $y <= $y1; $y++) {
                for ($x = $x0; $x <= $x1; $x++) {
                    $px = $x + 0.5;
                    $py = $y + 0.5;
                    $l1 = (($by - $cy) * ($px - $cx) + ($cx - $bx) * ($py - $cy)) / $den;
                    $l2 = (($cy - $ay) * ($px - $cx) + ($ax - $cx) * ($py - $cy)) / $den;
                    $l3 = 1 - $l1 - $l2;
                    if ($l1 < -0.02 || $l2 < -0.02 || $l3 < -0.02) {
                        continue;
                    }
                    $lx = $l1 * $pa[0] + $l2 * $pb[0] + $l3 * $pc[0];
                    $ly = $l1 * $pa[1] + $l2 * $pb[1] + $l3 * $pc[1];
                    $fu = ($fx1 - $lx) / $fdx;
                    $fv = ($fy1 - $ly) / $fdy;
                    if ($fu < 0 || $fu > 1 || $fv < 0 || $fv > 1) {
                        continue;
                    }
                    $sx = min($FW - 1, (int)($fu * $FW));
                    $sy = min($FH - 1, (int)($fv * $FH));
                    $s = $face->px[$sy * $FW + $sx];
                    $al = (($s >> 24) & 255) / 255;
                    if ($al <= 0) {
                        continue;
                    }
                    $d = $tex->px[$y * $W + $x];
                    $r = (int)round(((($s >> 16) & 255) * $al) + ((($d >> 16) & 255) * (1 - $al)));
                    $g = (int)round(((($s >> 8) & 255) * $al) + ((($d >> 8) & 255) * (1 - $al)));
                    $bl = (int)round((($s & 255) * $al) + (($d & 255) * (1 - $al)));
                    $tex->px[$y * $W + $x] = (255 << 24) | ($r << 16) | ($g << 8) | $bl;
                }
            }
        }
    }

    // the NeckRigAttachment a head asset carries (a Vector3Value or an Attachment, below the mesh or below its part), or null
    private static function neckRig(Inst $mesh): ?array {
        foreach ($mesh->children as $c) {
            if ($c->name !== 'NeckRigAttachment') {
                continue;
            }
            $v = $c->props['Value'] ?? ($c->props['CFrame'] ?? ($c->props['Position'] ?? null));
            if (is_array($v) && isset($v['x'], $v['y'], $v['z'])) {
                return [(float)$v['x'], (float)$v['y'], (float)$v['z']];
            }
        }
        return null;
    }

    private function meshFrom($value): ?array {
        $v = self::meshContent2016($value);
        $b = $v ? $this->bytes($v) : null;
        if ($b === null) {
            return null;
        }
        try {
            // v1 meshes are stored V-flipped, newer ones are not (same rule as the 2016 loader), so a texture lines up
            return self::cleanMesh(self::parseMesh2016($b));
        } catch (\Throwable $e) {
            return null;
        }
    }

    // a part texture: the body colour with the base texture and then the overlay on top
    private $pkgAtlas = false;

    // the shirt and pants template of package pictures (grey and blue checks), keyed to see-through where it is plain grey
    private function packageTemplateImage(): ?Img {
        $f = __DIR__ . '/../../data/package-template.png';
        $tpl = is_file($f) ? Img::decode((string)file_get_contents($f)) : null;
        if (!$tpl) {
            return null;
        }
        foreach ($tpl->px as $i => $v) {
            if (($v & 0xFFFFFF) === 0x808080) {
                $tpl->px[$i] = 0;
            }
        }
        return $tpl;
    }

    // that template laid out like the body-part textures (a 1024x512 atlas, the same as the 2016 renderer builds)
    private function packageTemplateAtlas(): ?Img {
        if ($this->pkgAtlas === false) {
            $tpl = $this->packageTemplateImage();
            $this->pkgAtlas = null;
            if ($tpl) {
                $atlas = new Img(1024, 512);
                $this->compositeLayer2016($atlas, 'CompositPantsTemplate', $tpl, [1, 1, 1]);
                $this->compositeLayer2016($atlas, 'CompositShirtTemplate', $tpl, [1, 1, 1]);
                $this->pkgAtlas = $atlas;
            }
        }
        return $this->pkgAtlas;
    }

    private function partImage(array $props, array $color, bool $template = false): ?Img {
        $bid = self::meshContent2016($props['BaseTextureId'] ?? null);
        $oid = self::meshContent2016($props['OverlayTextureId'] ?? null);
        if (!$bid && !$oid) {
            return null;
        }
        $key = $bid . '|' . $oid . '|' . implode(',', $color) . ($template ? '|t' : '');
        if (array_key_exists($key, $this->partImgCache)) {
            return $this->partImgCache[$key];
        }
        // a body part fills a small piece of the picture, so its texture is read at 512 pixels at most: a 1024 texture would
        // be four times the work for no visible difference
        $get = function ($v) {
            if (!$v) {
                return null;
            }
            $id = self::assetId($v);
            $b = $id ? $this->fetch($id) : null;
            if ($b !== null && Img::isImageBytes($b)) {
                return Img::decode($b, 512, self::MAX_IMAGE_SIDE);
            }
            return $this->image($v);
        };
        $base = $get($bid);
        $over = $get($oid);
        if (!$base && !$over) {
            return $this->partImgCache[$key] = null;
        }
        // a part may carry only an overlay (or only a base): whichever it has is drawn on the body colour
        $first = $base ?: $over;
        $img = new Img($first->w, $first->h);
        $img->fillRect(0, 0, $img->w, $img->h, [(int)round($color[0] * 255), (int)round($color[1] * 255), (int)round($color[2] * 255)]);
        if ($base) {
            $img->drawOver($base);
        }
        if ($template) {
            // a package drawn on its own: the checkered shirt and pants template lies where clothes would go, over the part's
            // base texture and under its overlay (as Roblox's catalog picture does)
            $atlas = $this->packageTemplateAtlas();
            if ($atlas) {
                $w = $img->w;
                $h = $img->h;
                for ($y = 0; $y < $h; $y++) {
                    $row = (int)(($y + 0.5) / $h * 512) * 1024;
                    for ($x = 0; $x < $w; $x++) {
                        $t = $atlas->px[$row + (int)(($x + 0.5) / $w * 768)];
                        if ($t >> 24) {
                            $img->px[$y * $w + $x] = $t;
                        }
                    }
                }
            }
        }
        if ($over) {
            if ($over->w === $img->w && $over->h === $img->h) {
                $img->drawOver($over);
            } else {
                $img->drawScaled($over, 0, 0, $img->w, $img->h);
            }
        }
        return $this->partImgCache[$key] = $img;
    }
}