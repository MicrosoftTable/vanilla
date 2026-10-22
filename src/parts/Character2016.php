<?php
namespace Vanilla;

trait Character2016 {
    private static $characterMeshes2016 = [];
    private $defaultFace2016 = null;

    // V1 meshes are V-flipped by the original loader; V2 meshes are not.
    private static function parseMesh2016(string $bytes): array {
        $mesh = self::parseMesh($bytes);
        if (substr($bytes, 0, 10) !== 'version 1.') {
            foreach ($mesh['uv'] as &$uv) { $uv[1] = 1 - $uv[1]; }
            unset($uv);
        }
        return $mesh;
    }

    private function characterMesh2016(string $name): array {
        $path = $this->dataDir . '/2016/character/' . $name . '.mesh';
        if (!isset(self::$characterMeshes2016[$path])) {
            self::$characterMeshes2016[$path] = self::parseMesh2016(file_get_contents($path));
        }
        return self::$characterMeshes2016[$path];
    }

    private function loadMesh2016(string $id): array {
        if (preg_match('~^rbxasset://fonts/(head[A-P]?|torso|leftarm|rightarm|leftleg|rightleg)\.mesh$~D', $id, $match)) {
            return $this->characterMesh2016($match[1]);
        }
        $bytes = $this->bytes($id);
        if ($bytes === null) { throw new \RuntimeException('mesh could not be fetched'); }
        return self::cleanMesh(self::parseMesh2016($bytes));
    }

    private function characterBase2016(array $base, array &$look): array {
        if ($this->defaultFace2016 === null) {
            $this->defaultFace2016 = Img::decode(file_get_contents($this->dataDir . '/2016/face.png'));
        }
        $base['face'] = $this->defaultFace2016;
        foreach (['torso' => 'torso', 'leftArm' => 'leftarm', 'rightArm' => 'rightarm',
            'leftLeg' => 'leftleg', 'rightLeg' => 'rightleg'] as $key => $file) {
            $mesh = $this->characterMesh2016($file);
            if (isset($look['bodyMeshes'][$key])) {
                $id = self::meshContent2016($look['bodyMeshes'][$key]->props['MeshId'] ?? null);
                if ($id) {
                    try {
                        $mesh = $this->loadMesh2016($id);
                    } catch (\Throwable $e) { $look['notes'][] = '2016: ' . $key . ' package mesh could not be loaded'; }
                }
            }
            // MaterialGenerator's 1024x512 composite reserves its right quarter for extras.
            foreach ($mesh['uv'] as &$uv) { $uv[0] *= 0.75; }
            unset($uv);
            $base[$key] = $mesh;
        }
        // character3.rbxm: size 2,1,1 and SpecialMesh Scale 1.25,1.25,1.25.
        // GeometryGenerator::getMeshScale divides those values by 1.25: net scale 1.
        $base['headRaw'] = $this->characterMesh2016('head');
        $base['head'] = $this->headGeometry2016($look);
        $base['headBox'] = self::meshBounds($base['head']);
        return $base;
    }

    private static function meshContent2016($value): ?string {
        $value = is_array($value) ? ($value['url'] ?? null) : $value;
        if (is_float($value) && is_finite($value) && $value === floor($value)) { $value = sprintf('%.0f', $value); }
        if (!is_string($value) && !is_int($value)) { return null; }
        $value = (string)$value;
        return $value === '' || $value === '0' ? null : $value;
    }

    private function headGeometry2016(array &$look): array {
        $shape = $look['headMesh'];
        if (!$shape) { return $this->characterMesh2016('head'); }
        $p = $shape->props;
        $type = $p['MeshType'] ?? 0;
        if (is_numeric($type)) { $type = (int)$type; }
        $fileId = self::meshContent2016($p['MeshId'] ?? null);
        $scale = self::v3($p['Scale'] ?? null, [1, 1, 1]);
        $offset = self::v3($p['Offset'] ?? null, [0, 0, 0]);
        $name = 'head';
        if ($shape->cls === 'CylinderMesh' || $shape->cls === 'BlockMesh') {
            $b = (float)($p['Bevel'] ?? 0); $r = (float)($p['Roundness'] ?? 0); $u = (float)($p['Bulge'] ?? 0);
            $variants = $shape->cls === 'CylinderMesh'
                ? ['A' => [0, 0, 0.5], 'C' => [0.1, 0, 0.5], 'D' => [0.4, 0, 0], 'E' => [0.66, 0, 0.5],
                    'F' => [0, 0, 0], 'G' => [0, 0, 1], 'H' => [0.2, 0, 1], 'N' => [0.4, 1, 0], 'O' => [0.2, 0, 0.5], 'P' => [0.1, 0, 0]]
                : ['B' => [0, 0, 0], 'I' => [0.5, 0, 0], 'J' => [0.3, 0, 0], 'M' => [0.05, 0, 0]];
            foreach ($variants as $suffix => $v) {
                if (max(abs($b - $v[0]), abs($r - $v[1]), abs($u - $v[2])) < 1e-5) { $name .= $suffix; break; }
            }
            // Baked legacy variants already include their bevel/bulge/custom scale.
            $scale = [1, 1, 1];
        } elseif ($type === 3 || $type === 'Sphere') {
            $name = 'headL';
            $scale = [2 * $scale[0] / 1.5, $scale[1] / 1.45, $scale[2] / 1.45];
        } elseif (!$fileId || !($shape->cls === 'FileMesh' || $type === 5 || $type === 'FileMesh')) {
            $xz = min(2 * $scale[0], $scale[2]) / 1.25;
            $scale = [$xz, $scale[1] / 1.25, $xz];
        }
        try {
            if ($fileId && ($shape->cls === 'FileMesh' || $type === 5 || $type === 'FileMesh')) {
                $mesh = $this->loadMesh2016($fileId);
            } else { $mesh = $this->characterMesh2016($name); }
            foreach ($mesh['pos'] as &$v) {
                $y = $name === 'head' && !$fileId
                    ? $v[1] * $scale[0] + ($v[1] > 0 ? 1 : -1) * 0.625 * ($scale[1] - $scale[0])
                    : $v[1] * $scale[1];
                $v = [$v[0] * $scale[0] + $offset[0], $y + $offset[1], $v[2] * $scale[2] + $offset[2]];
            }
            unset($v);
            return $mesh;
        } catch (\Throwable $e) {
            $look['notes'][] = '2016: custom head mesh could not be loaded';
            return $this->characterMesh2016('head');
        }
    }

    private $tex2016Cache = [];   // the last textures put together, reused when the next picture (the headshot) shows the same

    private function characterTextures2016(array $look, Img $face, ?array $headMesh = null): array {
        $sig = function ($im) { return $im ? md5($im->w . 'x' . $im->h . ':' . implode(',', $im->px)) : '-'; };
        $ids = [];
        foreach ($look['bodyMeshes'] as $k => $m) {
            $ids[] = $k . '=' . self::meshContent2016($m->props['BaseTextureId'] ?? null) . '/' . self::meshContent2016($m->props['OverlayTextureId'] ?? null);
        }
        $hp = $look['headMesh'] ? $look['headMesh']->props : [];
        $key = md5(json_encode([!empty($look['pkgview']), $look['colors'], $ids, self::meshContent2016($hp['TextureId'] ?? null), self::meshContent2016($hp['MeshId'] ?? null),
            $hp['Scale'] ?? null, $hp['Offset'] ?? null, $hp['Tags'] ?? null, $look['headSize'] ?? null,
            $sig($look['shirt']), $sig($look['pants']), $sig($look['tshirt']), $sig($face)]));
        if (isset($this->tex2016Cache[$key])) {
            return $this->tex2016Cache[$key];
        }
        $this->tex2016Cache = [$key => $this->buildTextures2016($look, $face, $headMesh)];
        return $this->tex2016Cache[$key];
    }

    private function buildTextures2016(array $look, Img $face, ?array $headMesh): array {
        $background = new Img(1024, 512, array_fill(0, 1024 * 512, 0x00808080));
        $parts = ['torso' => 'Torso', 'leftArm' => 'LeftArm', 'rightArm' => 'RightArm', 'leftLeg' => 'LeftLeg', 'rightLeg' => 'RightLeg'];
        foreach ($parts as $key => $name) {
            $this->compositeLayer2016($background, 'Composit' . $name . 'Base', null, $look['colors'][$key]);
        }
        $atlases = []; $bodyTextures = [];
        foreach ($parts as $key => $name) {
            $props = isset($look['bodyMeshes'][$key]) ? $look['bodyMeshes'][$key]->props : [];
            $baseId = self::meshContent2016($props['BaseTextureId'] ?? null);
            $overlayId = self::meshContent2016($props['OverlayTextureId'] ?? null);
            // a package drawn on its own: its parts carry the checkered shirt and pants template (between base and overlay)
            $tplLayer = !empty($look['pkgview']) && isset($look['bodyMeshes'][$key]) ? $this->packageTemplateImage() : null;
            $cacheKey = ($baseId ?? '') . '|' . ($overlayId ?? '') . ($tplLayer ? '|t' : '');
            if (!isset($atlases[$cacheKey])) {
                $atlas = clone $background;
                if ($baseId && ($image = $this->image($baseId))) {
                    $this->compositeLayer2016($atlas, 'CompositFullAtlasBaseTexture', $image, [1, 1, 1]);
                }
                foreach (['pants' => 'CompositPantsTemplate', 'shirt' => 'CompositShirtTemplate', 'tshirt' => 'CompositTShirt'] as $layer => $mesh) {
                    $image = $look[$layer] ?: ($tplLayer && $layer !== 'tshirt' ? $tplLayer : null);
                    if ($image) { $this->compositeLayer2016($atlas, $mesh, $image, [1, 1, 1]); }
                }
                if ($overlayId && ($image = $this->image($overlayId))) {
                    $this->compositeLayer2016($atlas, 'CompositFullAtlasOverlayTexture', $image, [1, 1, 1]);
                }
                $atlases[$cacheKey] = new Texture($atlas);
                $atlases[$cacheKey]->repeat = false;
            }
            $bodyTextures[$key] = $atlases[$cacheKey];
        }
        // The head's native UVs already include the front decal and a blank border for caps/back.
        $head = new Img($face->w, $face->h);
        $rgb = array_map(function ($c) { return (int)round($c * 255); }, $look['colors']['head']);
        $head->fillRect(0, 0, $head->w, $head->h, $rgb);
        $head->drawOver($face);
        $headTexture = new Texture($head);
        $headTexture->repeat = false;
        if ($look['headMesh']) {
            $textureId = self::meshContent2016($look['headMesh']->props['TextureId'] ?? null);
            if ($textureId && ($image = $this->image($textureId))) {
                // the head's own texture sits on the head colour, and its face goes on the front of the shape (as in 2012)
                $flat = new Img($image->w, $image->h);
                $flat->fillRect(0, 0, $flat->w, $flat->h, $rgb);
                $flat->drawOver($image);
                if ($headMesh && !preg_match('/\bNoFace\b/', (string)($look['headMesh']->props['Tags'] ?? ''))) {
                    self::bakeFace($flat, $headMesh, $face, $look['headSize'] ?: [2, 1, 1]);
                }
                $headTexture = new Texture($flat);
                $headTexture->repeat = false;
            }
        }
        return [$bodyTextures, $headTexture];
    }

    // Rasterize the original 2D compositing meshes, rather than guessing face rectangles.
    private function compositeLayer2016(Img $out, string $name, ?Img $image, array $color): void {
        $mesh = $this->characterMesh2016($name);
        $texture = $image ? new Texture($image) : null;
        if ($texture) { $texture->repeat = false; }
        $visited = str_repeat("\0", $out->w * $out->h);
        $solid = (255 << 24) | ((int)round($color[0] * 255) << 16) | ((int)round($color[1] * 255) << 8) | (int)round($color[2] * 255);
        for ($i = 0, $n = count($mesh['pos']); $i + 2 < $n; $i += 3) {
            $p = [];
            for ($j = 0; $j < 3; $j++) {
                $v = $mesh['pos'][$i + $j];
                $p[] = [$v[0] * 0.75, 512 - $v[1]];
            }
            [[$ax, $ay], [$bx, $by], [$cx, $cy]] = $p;
            $den = ($by - $cy) * ($ax - $cx) + ($cx - $bx) * ($ay - $cy);
            if (abs($den) < 1e-10) { continue; }
            $x0 = max(0, (int)ceil(min($ax, $bx, $cx) - 0.5));
            $x1 = min($out->w - 1, (int)floor(max($ax, $bx, $cx) - 0.5));
            $y0 = max(0, (int)ceil(min($ay, $by, $cy) - 0.5));
            $y1 = min($out->h - 1, (int)floor(max($ay, $by, $cy) - 0.5));
            for ($y = $y0; $y <= $y1; $y++) {
                for ($x = $x0; $x <= $x1; $x++) {
                    $index = $y * $out->w + $x;
                    if ($visited[$index] !== "\0") { continue; }
                    $a = (($by - $cy) * ($x + 0.5 - $cx) + ($cx - $bx) * ($y + 0.5 - $cy)) / $den;
                    $b = (($cy - $ay) * ($x + 0.5 - $cx) + ($ax - $cx) * ($y + 0.5 - $cy)) / $den;
                    $c = 1 - $a - $b;
                    if (min($a, $b, $c) < -1e-7) { continue; }
                    $visited[$index] = "\1";
                    if (!$texture) { $out->px[$index] = $solid; continue; }
                    $uv0 = $mesh['uv'][$i]; $uv1 = $mesh['uv'][$i + 1]; $uv2 = $mesh['uv'][$i + 2];
                    $t = $texture->sample($a * $uv0[0] + $b * $uv1[0] + $c * $uv2[0], $a * $uv0[1] + $b * $uv1[1] + $c * $uv2[1]);
                    if ($t[3] <= 0) { continue; }
                    $dst = $out->px[$index];
                    $da = (($dst >> 24) & 255) / 255;
                    $alpha = $t[3] + $da * (1 - $t[3]);
                    $value = (int)round($alpha * 255) << 24;
                    foreach ([16, 8, 0] as $channel => $shift) {
                        $v = ($t[$channel] * $color[$channel] * $t[3] + (($dst >> $shift) & 255) / 255 * $da * (1 - $t[3])) / $alpha;
                        $value |= max(0, min(255, (int)round($v * 255))) << $shift;
                    }
                    $out->px[$index] = $value;
                }
            }
        }
    }

    // Default engine thumbnail camera. Calling Lua may supply a different ThumbnailCamera.
    private static function thumbnailCamera2016(array $bounds, int $W, int $H, bool $crop = false, ?array $physicalBounds = null): array {
        $modelBounds = $physicalBounds ?? $bounds;
        $lo = $modelBounds['lo']; $hi = $modelBounds['hi'];
        $center = []; $size = [];
        for ($k = 0; $k < 3; $k++) {
            $center[] = ($lo[$k] + $hi[$k]) / 2;
            $size[] = $hi[$k] - $lo[$k];
        }
        $pitch = 35 * M_PI / 180;
        $roll = 45 * M_PI / 180;
        $direction = [-sin($roll) * sin($pitch), cos($roll) * sin($pitch), -cos($pitch)];
        $distance = max(1, max($size));
        $projection = self::perspective(70 * M_PI / 180, $W / $H, 0.1, 1000);
        $lo = $bounds['lo']; $hi = $bounds['hi'];
        $corners = [];
        foreach ([-1, 1] as $x) { foreach ([-1, 1] as $y) { foreach ([-1, 1] as $z) {
            $corners[] = [$x < 0 ? $lo[0] : $hi[0], $y < 0 ? $lo[1] : $hi[1], $z < 0 ? $lo[2] : $hi[2]];
        } } }
        // RCCService.Test/LuaScripts.cs AvatarThumbScript omits Click's optional crop flag.
        if (!$crop) {
            $eye = [$center[0] + $direction[0] * $distance, $center[1] + $direction[1] * $distance, $center[2] + $direction[2] * $distance];
            return ['vp' => self::mul4($projection, self::lookAt($eye, $center, [0, 1, 0])), 'eye' => $eye, 'center' => $center, 'distance' => $distance, 'bounds' => $bounds];
        }
        // Camera::tryZoomExtents starts one largestSide away. modifyThumbnailCamera
        // dollies backward by 1+depth^2 if any scene AABB corner clips, for at most 10 tries.
        for ($depth = 1; $depth <= 11; $depth++) {
            $eye = [$center[0] + $direction[0] * $distance, $center[1] + $direction[1] * $distance, $center[2] + $direction[2] * $distance];
            $vp = self::mul4($projection, self::lookAt($eye, $center, [0, 1, 0]));
            $screenLo = [INF, INF]; $screenHi = [-INF, -INF]; $clips = false;
            foreach ($corners as $p) {
                $w = $vp[3] * $p[0] + $vp[7] * $p[1] + $vp[11] * $p[2] + $vp[15];
                if ($w <= 0.001) { $clips = true; break; }
                foreach ([0, 1, 2] as $axis) {
                    $q = ($vp[$axis] * $p[0] + $vp[4 + $axis] * $p[1] + $vp[8 + $axis] * $p[2] + $vp[12 + $axis]) / $w;
                    if (abs($q) > 1) { $clips = true; }
                    if ($axis < 2) { $screenLo[$axis] = min($screenLo[$axis], $q); $screenHi[$axis] = max($screenHi[$axis], $q); }
                }
            }
            if (!$clips || $depth > 10) { break; }
            $distance += 1 + $depth * $depth;
        }
        if (!$clips) {
            $zoom = min(2 / ($screenHi[0] - $screenLo[0]), 2 / ($screenHi[1] - $screenLo[1]));
            $tx = -$zoom * ($screenLo[0] + $screenHi[0]) / 2;
            $ty = -$zoom * ($screenLo[1] + $screenHi[1]) / 2;
            $vp = self::mul4([$zoom, 0, 0, 0, 0, $zoom, 0, 0, 0, 0, 1, 0, $tx, $ty, 0, 1], $vp);
        }
        return ['vp' => $vp, 'eye' => $eye, 'center' => $center, 'distance' => $distance, 'bounds' => $bounds];
    }

    private static function sceneBounds2016(array $base, array $cfs, array $items, bool $physical = false, string $only = ''): array {
        $lo = [INF, INF, INF]; $hi = [-INF, -INF, -INF];
        $add = function (array $geometry, array $frame) use (&$lo, &$hi) {
            // The engine transforms each drawable's LOCAL mesh AABB, then unions it.
            $box = self::meshBounds($geometry);
            foreach ([-1, 1] as $x) { foreach ([-1, 1] as $y) { foreach ([-1, 1] as $z) {
                $p = self::cfApply($frame, [$x < 0 ? $box['lo'][0] : $box['hi'][0], $y < 0 ? $box['lo'][1] : $box['hi'][1], $z < 0 ? $box['lo'][2] : $box['hi'][2]]);
                for ($k = 0; $k < 3; $k++) { $lo[$k] = min($lo[$k], $p[$k]); $hi[$k] = max($hi[$k], $p[$k]); }
            } } }
        };
        foreach (['head' => 'Head', 'torso' => 'Torso', 'leftArm' => 'Left Arm', 'rightArm' => 'Right Arm', 'leftLeg' => 'Left Leg', 'rightLeg' => 'Right Leg'] as $key => $name) {
            if ($only === 'items' || ($only === 'head' && $key !== 'head')) {
                continue;
            }
            if ($physical) {
                $size = $key === 'head' ? [2, 1, 1] : ($key === 'torso' ? [2, 2, 1] : [1, 2, 1]);
                $geometry = ['pos' => [[$size[0] / -2, $size[1] / -2, $size[2] / -2], [$size[0] / 2, $size[1] / 2, $size[2] / 2]]];
            } else { $geometry = $base[$key]; }
            $add($geometry, $cfs[$name]);
        }
        foreach ($items as $item) {
            $s = $item['size'];
            $geometry = $physical ? ['pos' => [[$s[0] / -2, $s[1] / -2, $s[2] / -2], [$s[0] / 2, $s[1] / 2, $s[2] / 2]]] : $item['geom'];
            $add($geometry, $item['cf']);
        }
        return ['lo' => $lo, 'hi' => $hi];
    }
}
