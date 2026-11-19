<?php
namespace Vanilla;

trait ItemRender {
    private $partImgCache = [];

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
            $look['only'] = 'part:' . array_key_first($look['bodyMeshes']);
            $look['pkgview'] = true;
        } elseif ($clothes && !$body && !$worn && !$look['faceSet']) {
            $gray = self::brick(194);
            foreach ($look['colors'] as $k => $v) {
                $look['colors'][$k] = $gray;
            }
        } elseif (!$worn && !$clothes && !$body && !$look['faceSet']) {
            throw new \InvalidArgumentException('item is not a hat, gear, face, head, shirt, pants, t-shirt or package' . ($look['notes'] ? ' (' . $look['notes'][0] . ')' : ''));
        }
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
                        $look['colors'][$name] = self::brick(1);
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
                $look['colors'][$name] = self::brick(24);
            }
            $img = $this->partImage($cm->props, $look['colors'][$name], $pkg);
            if ($img) {
                $tex[$part] = new Texture($img);
                $tex[$part]->repeat = false;
            } else {
                if ($pkg && $tpl) {
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
            $look['colors']['head'] = self::brick(1);
        }
        if ($shape || $cm) {
            $P = $shape ? $shape->props : $cm->props;
            $scale = $shape ? self::v3($P['Scale'] ?? null, [1.25, 1.25, 1.25]) : [1.25, 1.25, 1.25];
            $off = $shape ? self::v3($P['Offset'] ?? null, [0, 0, 0]) : [0, 0, 0];
            if ($shape) {
                $neck = self::neckRig($shape);
                if ($neck) {
                    $off = [$off[0] - $neck[0], $off[1] - ($neck[1] + 0.5), $off[2] - $neck[2]];
                }
            }
            $mesh = $this->meshFrom($P['MeshId'] ?? null);
            $ownMesh = (bool)$mesh;
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
                $img = $this->partImage($P, [1, 1, 1]);
            }
            if ($img) {
                $c = $look['colors']['head'];
                $flat = new Img($img->w, $img->h);
                $flat->fillRect(0, 0, $flat->w, $flat->h, [(int)round($c[0] * 255), (int)round($c[1] * 255), (int)round($c[2] * 255)]);
                $flat->drawOver($img);
                $face = $look['faceSet'] ? $look['face'] : (preg_match('/\bNoFace\b/', (string)($P['Tags'] ?? '')) ? null : ($base["face"] ?? null));
                if ($face && $shape) {
                    self::bakeFace($flat, $face);
                }
                $headTex = new Texture($flat, true);
            } elseif ($shape && self::meshContent2016($P['TextureId'] ?? null)) {
                $look['notes'][] = 'head: the texture ' . self::meshContent2016($P['TextureId']) . ' could not be loaded, so the head has no picture on it';
            }
            if (!$headTex && $shape && $ownMesh && !preg_match('/\bNoFace\b/', (string)($P['Tags'] ?? ''))) {
                $face = $look['faceSet'] ? $look['face'] : ($base['face'] ?? null);
                if ($face) {
                    $c = $look['colors']['head'];
                    $flat = new Img($face->w, $face->h);
                    $flat->fillRect(0, 0, $flat->w, $flat->h, [(int)round($c[0] * 255), (int)round($c[1] * 255), (int)round($c[2] * 255)]);
                    self::bakeFace($flat, $face);
                    $headTex = new Texture($flat, true);
                }
            }
            if (!$headTex && $shape && preg_match('/\bNoFace\b/', (string)($P['Tags'] ?? ''))) {
                $c = $look['colors']['head'];
                $flat = new Img(4, 4);
                $flat->fillRect(0, 0, 4, 4, [(int)round($c[0] * 255), (int)round($c[1] * 255), (int)round($c[2] * 255)]);
                $headTex = new Texture($flat, true);
            }
        }
        return [$base, $tex, $headTex];
    }

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

    private static function bakeFace(Img $tex, Img $face): void {
        $tex->drawScaled($face, 0, 0, $tex->w, $tex->h);
    }

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
            return self::cleanMesh(self::parseMesh2016($b));
        } catch (\Throwable $e) {
            return null;
        }
    }

    private $pkgAtlas = false;

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
        $first = $base ?: $over;
        $img = new Img($first->w, $first->h);
        $img->fillRect(0, 0, $img->w, $img->h, [(int)round($color[0] * 255), (int)round($color[1] * 255), (int)round($color[2] * 255)]);
        if ($base) {
            $img->drawOver($base);
        }
        if ($template) {
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