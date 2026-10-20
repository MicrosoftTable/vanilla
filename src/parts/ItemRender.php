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
        } elseif ($clothes && !$body && !$worn && !$look['faceSet']) {
            $gray = self::brick(194);
            foreach ($look['colors'] as $k => $v) {
                $look['colors'][$k] = $gray;
            }
        } elseif (!$worn && !$clothes && !$body && !$look['faceSet']) {
            throw new \InvalidArgumentException('item is not a hat, gear, face, head, shirt, pants, t-shirt or package' . ($look['notes'] ? ' (' . $look['notes'][0] . ')' : ''));
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
        foreach ($key as $part => $name) {
            $cm = $look['bodyMeshes'][$name] ?? null;
            if (!$cm) {
                continue;
            }
            $mesh = $this->meshFrom($cm->props['MeshId'] ?? null);
            if (!$mesh) {
                $look['notes'][] = $name . ': the package mesh could not be loaded';
                continue;
            }
            $base[$part] = $mesh;
            $img = $this->partImage($cm->props, $look['colors'][$name]);
            $tex[$part] = $img ? new Texture($img) : false;
            if ($tex[$part]) {
                $tex[$part]->repeat = false;
            }
        }
        $shape = $look['headMesh'];
        $cm = $look['bodyMeshes']['head'] ?? null;
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
