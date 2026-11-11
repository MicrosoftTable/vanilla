<?php
namespace Vanilla;

trait SceneRender {
    private static $sceneParts = [
        'Part' => 1, 'WedgePart' => 1, 'CornerWedgePart' => 1, 'TrussPart' => 1, 'Seat' => 1, 'VehicleSeat' => 1,
        'SpawnLocation' => 1, 'SkateboardPlatform' => 1, 'FlagStand' => 1, 'MeshPart' => 1, 'UnionOperation' => 1,
    ];

    function renderScene(array $req): array {
        $kind = $req['kind'] ?? 'model';
        $bytes = (string)($req['bytes'] ?? '');
        $W = (int)($req['width'] ?? 420);
        $H = (int)($req['height'] ?? 420);
        if ($bytes === '' || $W < 16 || $H < 16 || $W > 1920 || $H > 1080) {
            throw new \InvalidArgumentException('scene needs bytes and a size');
        }
        $deadline = microtime(true) + (float)($req['seconds'] ?? 75);
        $notes = [];
        $cam = null;
        $thumb = null;
        $overlays = [];
        if ($kind === 'mesh') {
            $mesh = self::cleanMesh(self::parseMesh2016($bytes));
            $items = [['mesh' => $mesh, 'cf' => self::CF(), 'scale' => [1, 1, 1], 'off' => [0, 0, 0], 'color' => [0.64, 0.64, 0.66], 'alpha' => 1.0, 'tex' => null, 'smooth' => true]];
        } else {
            $tree = Rbxm::parse(self::sceneStrip($bytes), 67108864);
            $roots = $tree['roots'] ?? [];
            if ($kind === 'place') {
                $world = array_values(array_filter($roots, function ($r) { return $r->cls === 'Workspace'; }));
                $roots = $world ?: $roots;
            }
            $items = [];
            $parts = [];
            $stack = array_values($roots);
            $saved = null;
            foreach ($roots as $r) {
                $ref = $r->props['CurrentCamera']['ref'] ?? null;
                if (is_string($ref) && isset($tree['byRef'][$ref]) && $tree['byRef'][$ref]->cls === 'Camera') {
                    $saved = $tree['byRef'][$ref];
                }
            }
            while ($stack) {
                $x = array_pop($stack);
                if ($x->cls === 'Camera' && ($kind !== 'place' || !self::sceneInWearable($x))) {
                    if (strcasecmp((string)$x->name, 'ThumbnailCamera') === 0) {
                        $thumb = $thumb ?: $x;
                    } elseif (!$cam) {
                        $cam = $x;
                    }
                }
                if (isset(self::$sceneParts[$x->cls])) {
                    $parts[] = $x;
                }
                if ($kind === 'place' && ($x->cls === 'Hint' || $x->cls === 'Message')) {
                    $text = trim((string)($x->props['Text'] ?? ''));
                    if ($text !== '' && !isset($overlays[$x->cls])) {
                        $overlays[$x->cls] = $text;
                    }
                }
                foreach ($x->children as $c) {
                    $stack[] = $c;
                }
            }
            if (!$parts) {
                throw new \InvalidArgumentException('nothing to draw in this ' . $kind);
            }
            $cam = $saved ?? $cam;
            $items = $this->sceneItems($parts, $deadline, $notes);
        }
        if (!$items) {
            throw new \InvalidArgumentException('nothing visible in this ' . $kind);
        }
        $pose = self::sceneThumbCamera($thumb);
        $fitted = $pose === null;
        [$eye, $target, $fov] = $pose ?? $this->sceneCamera($items, $kind, $cam, $W / $H);
        if (!empty($parts)) $items = array_merge($items, $this->sceneSparkles($parts, $eye, $target));
        $items = $this->sceneTextures($items, $eye, $target, $fov, $W / $H, $deadline, $notes);
        $tris = $this->sceneTriangles($items, $eye, $target, $fov, $W, $H, $deadline, $notes);
        if (!$fitted && !$tris[0] && !$tris[1]) {
            [$eye, $target, $fov] = $this->sceneCamera($items, $kind, $cam, $W / $H);
            $tris = $this->sceneTriangles($items, $eye, $target, $fov, $W, $H, $deadline, $notes);
        }
        if (!$tris[0] && !$tris[1]) {
            throw new \InvalidArgumentException('nothing in view in this ' . $kind);
        }
        return ['png' => self::sceneRaster($tris, $W, $H, $kind === 'place', (int)($req['outWidth'] ?? $W), (int)($req['outHeight'] ?? $H), $deadline, $overlays), 'notes' => $notes];
    }

    private static function sceneInWearable(Inst $x): bool {
        for ($p = $x->parent, $i = 0; $p && $i < 200; $p = $p->parent, $i++) {
            if (in_array($p->cls, ['Tool', 'HopperBin', 'Hat', 'Accessory', 'Accoutrement'], true)) {
                return true;
            }
        }
        return false;
    }

    private static function sceneThumbCamera($thumb): ?array {
        $frame = $thumb ? ($thumb->props['CFrame'] ?? $thumb->props['CoordinateFrame'] ?? null) : null;
        if (!is_array($frame)) {
            return null;
        }
        $cf = self::cfFrom($frame);
        $look = self::rmul($cf['R'], [0, 0, -1]);
        if (self::dot($look, $look) < 0.5) {
            return null;
        }
        $fov = (float)($thumb->props['FieldOfView'] ?? 70);
        $fov = $fov > 1 && $fov < 150 ? $fov : 70.0;
        $eye = $cf['p'];
        return [$eye, [$eye[0] + $look[0], $eye[1] + $look[1], $eye[2] + $look[2]], $fov];
    }

    private static function sceneDecal(array $size, int $face): array {
        $x = $size[0] / 2;
        $y = $size[1] / 2;
        $z = $size[2] / 2;
        $g = 0.01;
        $quads = [
            0 => [[$x + $g, $y, $z], [$x + $g, $y, -$z], [$x + $g, -$y, -$z], [$x + $g, -$y, $z], [1, 0, 0]],
            1 => [[$x, $y + $g, $z], [-$x, $y + $g, $z], [-$x, $y + $g, -$z], [$x, $y + $g, -$z], [0, 1, 0]],
            2 => [[-$x, $y, $z + $g], [$x, $y, $z + $g], [$x, -$y, $z + $g], [-$x, -$y, $z + $g], [0, 0, 1]],
            3 => [[-$x - $g, $y, -$z], [-$x - $g, $y, $z], [-$x - $g, -$y, $z], [-$x - $g, -$y, -$z], [-1, 0, 0]],
            4 => [[-$x, -$y - $g, $z], [$x, -$y - $g, $z], [$x, -$y - $g, -$z], [-$x, -$y - $g, -$z], [0, -1, 0]],
            5 => [[$x, $y, -$z - $g], [-$x, $y, -$z - $g], [-$x, -$y, -$z - $g], [$x, -$y, -$z - $g], [0, 0, -1]],
        ];
        [$tl, $tr, $br, $bl, $n] = $quads[$face] ?? $quads[5];
        return [
            'pos' => [$tl, $bl, $br, $tl, $br, $tr],
            'nrm' => [$n, $n, $n, $n, $n, $n],
            'uv' => [[0, 0], [0, 1], [1, 1], [0, 0], [1, 1], [1, 0]],
        ];
    }

    private static function sceneFaceId($face): int {
        if (is_string($face) && !is_numeric($face)) {
            $names = ['right' => 0, 'top' => 1, 'back' => 2, 'left' => 3, 'bottom' => 4, 'front' => 5];
            return $names[strtolower($face)] ?? 5;
        }
        return (int)$face;
    }

    private static function sceneStrip(string $bytes): string {
        if (substr($bytes, 0, 8) === '<roblox!') {
            return $bytes;
        }
        $out = '';
        $n = strlen($bytes);
        $i = 0;
        while ($i < $n) {
            $a = strpos($bytes, '<ProtectedString', $i);
            $b = strpos($bytes, '<BinaryString', $i);
            $s = $a === false ? $b : ($b === false ? $a : min($a, $b));
            if ($s === false) {
                $out .= substr($bytes, $i);
                break;
            }
            $close = $bytes[$s + 1] === 'P' ? '</ProtectedString>' : '</BinaryString>';
            $e = strpos($bytes, $close, $s);
            if ($e === false) {
                $out .= substr($bytes, $i);
                break;
            }
            $out .= substr($bytes, $i, $s - $i);
            $i = $e + strlen($close);
        }
        $unused = '(?:Back|Bottom|Front|Left|Right|Top)(?:ParamA|ParamB|SurfaceInput)|Anchored|Archivable|CanCollide|CollisionGroupId|Locked|Elasticity|Friction|formFactorRaw|FormFactor|ControllerFlagShown|DraggingV1|PrimaryPart|LinkedSource|Disabled|ClassName';
        $trimmed = preg_replace('/<token name="(?:Back|Bottom|Front|Left|Right|Top)Surface">0<\/token>\s*/', '', $out);
        $out = is_string($trimmed) ? $trimmed : $out;
        $trimmed = preg_replace('/<([A-Za-z0-9]+) name="(?:' . $unused . ')">[^<]*<\\/\\1>\\s*/', '', $out);
        $out = is_string($trimmed) ? $trimmed : $out;
        $trimmed = preg_replace('/<Vector3 name="(?:Velocity|RotVelocity)">.*?<\\/Vector3>\\s*/s', '', $out);
        return is_string($trimmed) ? $trimmed : $out;
    }

    private function sceneItems(array $parts, float $deadline, array &$notes): array {
        $items = [];
        $meshes = [];
        $missing = 0;
        $wanted = [];
        foreach ($parts as $h) {
            foreach ($h->children as $c) {
                $ref = $c->props['MeshId']['url'] ?? null;
                $one = $ref ? self::assetId($ref) : null;
                if ($one !== null) {
                    $wanted[$one] = true;
                }
            }
        }
        try {
            $this->prefetch(array_slice(array_map('strval', array_keys($wanted)), 0, 200));
        } catch (\Throwable $e) {
        }
        foreach ($parts as $h) {
            $P = $h->props;
            $tr = $P['Transparency'] ?? 0;
            $alpha = is_numeric($tr) ? max(0.0, min(1.0, 1.0 - (float)$tr)) : 1.0;
            if (!isset($P['CFrame'])) {
                continue;
            }
            $size = self::partSize($h);
            $cf = self::cfFrom($P['CFrame']);
            if ($alpha < 0.05) {
                foreach (self::sceneDecals($h, $cf, $size) as $decal) {
                    $items[] = $decal;
                }
                continue;
            }
            $rf = $P['Reflectance'] ?? 0;
            $item = ['cf' => $cf, 'scale' => [1, 1, 1], 'off' => [0, 0, 0], 'color' => self::partColor($h), 'alpha' => $alpha, 'tex' => null, 'smooth' => false, 'size' => $size, 'refl' => is_numeric($rf) ? max(0.0, min(1.0, (float)$rf)) : 0.0];
            $sm = null;
            foreach ($h->children as $c) {
                if (in_array($c->cls, ['SpecialMesh', 'FileMesh', 'BlockMesh', 'CylinderMesh'], true)) {
                    $sm = $c;
                    break;
                }
            }
            $mesh = null;
            $hx = $size[0] / 2;
            $hy = $size[1] / 2;
            $hz = $size[2] / 2;
            $meshUrl = null;
            $texUrl = null;
            $type = null;
            if ($h->cls === 'MeshPart') {
                $meshUrl = ($P['MeshId'] ?? $P['MeshID'] ?? [])['url'] ?? null;
                $texUrl = ($P['TextureID'] ?? $P['TextureId'] ?? [])['url'] ?? null;
            } elseif ($sm) {
                $item['scale'] = self::v3($sm->props['Scale'] ?? null, [1, 1, 1]);
                $item['off'] = self::v3($sm->props['Offset'] ?? null, [0, 0, 0]);
                $type = $sm->cls === 'BlockMesh' ? 6 : ($sm->cls === 'CylinderMesh' ? 4 : ($sm->props['MeshType'] ?? 5));
                if (is_string($type) && !is_numeric($type)) {
                    $names = ['head' => 0, 'torso' => 1, 'wedge' => 2, 'sphere' => 3, 'cylinder' => 4, 'filemesh' => 5, 'brick' => 6];
                    $type = $names[strtolower($type)] ?? 6;
                }
                $type = (int)$type;
                if ($type === 5) {
                    $meshUrl = $sm->props['MeshId']['url'] ?? null;
                    $texUrl = $sm->props['TextureId']['url'] ?? null;
                }
            }
            if ($meshUrl) {
                if (!array_key_exists($meshUrl, $meshes)) {
                    $meshes[$meshUrl] = null;
                    if (microtime(true) < $deadline - 50 && count($meshes) <= 80) {
                        try {
                            $b = $this->bytes($meshUrl);
                            $meshes[$meshUrl] = $b === null ? null : self::cleanMesh(self::parseMesh2016($b));
                        } catch (\Throwable $e) {
                            $meshes[$meshUrl] = null;
                        }
                    }
                }
                $mesh = $meshes[$meshUrl];
                if ($mesh) {
                    $item['smooth'] = true;
                    if ($h->cls === 'MeshPart') {
                        $bx = self::meshBounds($mesh);
                        $lo = $bx[0] ?? $bx['min'] ?? [-1, -1, -1];
                        $hi = $bx[1] ?? $bx['max'] ?? [1, 1, 1];
                        $item['scale'] = [
                            $size[0] / max(0.001, $hi[0] - $lo[0]),
                            $size[1] / max(0.001, $hi[1] - $lo[1]),
                            $size[2] / max(0.001, $hi[2] - $lo[2]),
                        ];
                    }
                    $item['texUrl'] = $texUrl ?: null;
                } else {
                    $missing++;
                }
            }
            $decalSize = $size;
            if (!$mesh && $type === 0) {
                $kk = min($size[0] / 2, $size[1], $size[2]) / 1.25;
                $item['scale'] = [$item['scale'][0] * $kk, $item['scale'][1] * $kk, $item['scale'][2] * $kk];
                $mesh = $this->base()['headRaw'];
                $item['smooth'] = true;
                $hb = self::meshBounds($mesh);
                $decalSize = [
                    max($size[0], ($hb['hi'][0] - $hb['lo'][0]) * $item['scale'][0] * 1.02),
                    max($size[1], ($hb['hi'][1] - $hb['lo'][1]) * $item['scale'][1] * 1.02),
                    max($size[2], ($hb['hi'][2] - $hb['lo'][2]) * $item['scale'][2] * 1.02),
                ];
            }
            if (!$mesh) {
                $sx = $item['scale'][0];
                $sy = $item['scale'][1];
                $sz = $item['scale'][2];
                $k = $sm ? [$hx * $sx, $hy * $sy, $hz * $sz] : [$hx, $hy, $hz];
                $item['scale'] = [1, 1, 1];
                $shape = $P['shape'] ?? $P['Shape'] ?? 1;
                if ($h->cls === 'WedgePart' || $type === 2) {
                    $mesh = self::sceneWedge($k[0], $k[1], $k[2]);
                } elseif ($h->cls === 'CornerWedgePart') {
                    $mesh = self::sceneCorner($k[0], $k[1], $k[2]);
                } elseif ($type === 3 || ($type === null && (int)$shape === 0)) {
                    $mesh = self::sphereMesh($k[0], $k[1], $k[2]);
                    $item['smooth'] = true;
                } elseif ($type === 4 || ($type === null && (int)$shape === 2)) {
                    $mesh = self::sceneCylinder($k[0], $k[1], $k[2], $type === 4);
                    $item['smooth'] = true;
                } else {
                    $mesh = self::plainBox($k[0], $k[1], $k[2]);
                }
            }
            $item['mesh'] = $mesh;
            if (empty($item['tex']) && empty($item['texUrl']) && !$meshUrl) {
                $isBox = !$sm && $h->cls !== 'WedgePart' && $h->cls !== 'CornerWedgePart' && (int)($P['shape'] ?? $P['Shape'] ?? 1) === 1;
                $item['tiles'] = self::sceneTiles($P, $isBox);
            }
            $material = (int)(is_array($P['Material'] ?? null) ? 256 : ($P['Material'] ?? 256));
            if ($material === 288) {
                $item['unlit'] = true;
            } elseif ($material === 1568 && $item['alpha'] > 0.6) {
                $item['alpha'] = 0.6;
            }
            $items[] = $item;
            foreach (self::sceneDecals($h, $cf, $decalSize) as $decal) {
                $items[] = $decal;
            }
        }
        if ($missing) {
            $notes[] = $missing . ' meshes could not be loaded and were drawn as plain parts';
        }
        return $items;
    }

    private static function sceneMemoryLimit(): int {
        $raw = trim((string)ini_get('memory_limit'));
        if ($raw === '' || $raw === '-1') {
            return 1500 * 1048576;
        }
        $n = (int)$raw;
        $unit = strtolower(substr($raw, -1));
        $bytes = $unit === 'g' ? $n * 1073741824 : ($unit === 'm' ? $n * 1048576 : ($unit === 'k' ? $n * 1024 : $n));
        return (int)($bytes * 0.7);
    }

    private function sceneTextures(array $items, array $eye, array $target, float $fov, float $aspect, float $deadline, array &$notes): array {
        $dir = self::norm(self::sub($target, $eye));
        $upRef = abs($dir[1]) > 0.99 ? [0, 0, 1] : [0, 1, 0];
        $right = self::norm(self::cross($dir, $upRef));
        $up = self::cross($right, $dir);
        $tanY = tan(deg2rad($fov) / 2);
        $tanX = $tanY * $aspect;
        $near = [];
        foreach ($items as $it) {
            $url = $it['texUrl'] ?? null;
            if (!$url) {
                continue;
            }
            $v = self::sub($it['cf']['p'], $eye);
            $z = self::dot($v, $dir);
            $s = $it['size'] ?? [1, 1, 1];
            $r = max($s) * 0.87;
            if ($z < -$r || abs(self::dot($v, $right)) > ($z + $r) * $tanX + $r || abs(self::dot($v, $up)) > ($z + $r) * $tanY + $r) {
                continue;
            }
            $d = max(0.0, $z);
            if (!isset($near[$url]) || $d < $near[$url]) {
                $near[$url] = $d;
            }
        }
        asort($near);
        $urls = array_keys($near);
        $ids = [];
        foreach (array_slice($urls, 0, 200) as $url) {
            $one = self::assetId($url);
            if ($one !== null) {
                $ids[] = $one;
            }
        }
        $memoryLimit = self::sceneMemoryLimit();
        try {
            $this->prefetch($ids);
        } catch (\Throwable $e) {
        }
        $textures = [];
        foreach ($urls as $url) {
            $textures[$url] = null;
            if (microtime(true) < $deadline - 30 && memory_get_usage() < $memoryLimit) {
                try {
                    $textures[$url] = $this->image($url);
                } catch (\Throwable $e) {
                }
            }
        }
        $out = [];
        $missing = 0;
        foreach ($items as $it) {
            $url = $it['texUrl'] ?? null;
            $it['tex'] = $url ? ($textures[$url] ?? null) : ($it['tex'] ?? null);
            if (!empty($it['decal']) && !$it['tex']) {
                if ($url && array_key_exists($url, $textures)) {
                    $missing++;
                }
                continue;
            }
            $out[] = $it;
        }
        if ($missing) {
            $notes[] = $missing . ' decals in view could not be loaded';
        }
        return $out;
    }

    private static $sceneTileCache = [];

    private static function sceneTiles(array $P, bool $isBox): ?array {
        $material = (int)(is_array($P['Material'] ?? null) ? 256 : ($P['Material'] ?? 256));
        $names = ['RightSurface', 'TopSurface', 'BackSurface', 'LeftSurface', 'BottomSurface', 'FrontSurface'];
        $out = [];
        $any = false;
        foreach ($names as $i => $name) {
            $surface = $isBox ? (int)(is_array($P[$name] ?? null) ? 0 : ($P[$name] ?? 0)) : 0;
            $out[$i] = self::sceneTile($material, $surface);
            $any = $any || $out[$i] !== null;
        }
        return $any ? $out : null;
    }

    private static function sceneTile(int $material, int $surface): ?array {
        $surface = $surface === 3 || $surface === 4 ? $surface : 0;
        $key = $material . '|' . $surface;
        if (array_key_exists($key, self::$sceneTileCache)) {
            return self::$sceneTileCache[$key];
        }
        $px = self::sceneMaterial($material);
        if ($surface) {
            $file = __DIR__ . '/../../data/' . ($surface === 3 ? 'stud' : 'inlet') . '.png';
            $g = is_file($file) ? @imagecreatefrompng($file) : false;
            if ($g) {
                $px = $px ?? array_fill(0, 16384, 100);
                $gw = imagesx($g);
                $gh = imagesy($g);
                for ($y = 0; $y < 128; $y++) {
                    for ($x = 0; $x < 128; $x++) {
                        $s = imagecolorat($g, (int)(($x % 32) / 32 * $gw), (int)(($y % 32) / 32 * $gh));
                        $a = (127 - (($s >> 24) & 127)) / 127;
                        $k = (($s >> 16) & 255) > 127 ? 1 + 0.55 * $a : 1 - 0.55 * $a;
                        $px[$y * 128 + $x] = (int)round($px[$y * 128 + $x] * $k);
                    }
                }
                imagedestroy($g);
            }
        }
        return self::$sceneTileCache[$key] = $px === null ? null : self::sceneMips($px);
    }

    private static function sceneMips(array $px): array {
        $levels = [$px];
        for ($size = 128; $size > 2; $size >>= 1) {
            $half = $size >> 1;
            $next = [];
            for ($y = 0; $y < $half; $y++) {
                for ($x = 0; $x < $half; $x++) {
                    $x2 = $x * 2;
                    $y2 = $y * 2;
                    $next[] = (int)(($px[$y2 * $size + $x2] + $px[$y2 * $size + $x2 + 1] + $px[($y2 + 1) * $size + $x2] + $px[($y2 + 1) * $size + $x2 + 1]) / 4);
                }
            }
            $levels[] = $next;
            $px = $next;
        }
        return $levels;
    }

    private static function sceneNoise(int $cells, int $seed): array {
        $grid = [];
        $v = $seed * 2654435761 % 4294967296;
        for ($i = 0; $i < $cells * $cells; $i++) {
            $v = ($v * 1664525 + 1013904223) % 4294967296;
            $grid[] = ($v >> 8 & 65535) / 65535;
        }
        $out = [];
        for ($y = 0; $y < 128; $y++) {
            $fy = $y / 128 * $cells;
            $iy = (int)$fy;
            $ty = $fy - $iy;
            $ty = $ty * $ty * (3 - 2 * $ty);
            for ($x = 0; $x < 128; $x++) {
                $fx = $x / 128 * $cells;
                $ix = (int)$fx;
                $tx = $fx - $ix;
                $tx = $tx * $tx * (3 - 2 * $tx);
                $a = $grid[$iy * $cells + $ix];
                $b = $grid[$iy * $cells + ($ix + 1) % $cells];
                $c = $grid[(($iy + 1) % $cells) * $cells + $ix];
                $d = $grid[(($iy + 1) % $cells) * $cells + ($ix + 1) % $cells];
                $out[] = ($a + ($b - $a) * $tx) * (1 - $ty) + ($c + ($d - $c) * $tx) * $ty;
            }
        }
        return $out;
    }

    private static function sceneCells(int $count, int $seed): array {
        $pts = [];
        $v = $seed * 40503 % 4294967296;
        for ($i = 0; $i < $count; $i++) {
            $v = ($v * 1664525 + 1013904223) % 4294967296;
            $x = ($v >> 8 & 65535) / 65535 * 128;
            $v = ($v * 1664525 + 1013904223) % 4294967296;
            $y = ($v >> 8 & 65535) / 65535 * 128;
            $pts[] = [$x, $y];
        }
        $out = [];
        for ($y = 0; $y < 128; $y++) {
            for ($x = 0; $x < 128; $x++) {
                $d1 = 1e9;
                $d2 = 1e9;
                foreach ($pts as $p) {
                    $dx = abs($x - $p[0]);
                    $dy = abs($y - $p[1]);
                    $dx = min($dx, 128 - $dx);
                    $dy = min($dy, 128 - $dy);
                    $d = $dx * $dx + $dy * $dy;
                    if ($d < $d1) {
                        $d2 = $d1;
                        $d1 = $d;
                    } elseif ($d < $d2) {
                        $d2 = $d;
                    }
                }
                $out[] = sqrt($d2) - sqrt($d1);
            }
        }
        return $out;
    }

    private static function sceneMaterial(int $material): ?array {
        $known = [512, 528, 784, 800, 816, 832, 848, 864, 880, 1040, 1056, 1072, 1088, 1280, 1296, 1312, 1536];
        if (!in_array($material, $known, true)) {
            return null;
        }
        $fine = self::sceneNoise(64, $material + 1);
        $mid = self::sceneNoise(16, $material + 2);
        $big = self::sceneNoise(4, $material + 3);
        $cells = null;
        if ($material === 880) {
            $cells = self::sceneCells(14, 880);
        } elseif ($material === 864) {
            $cells = self::sceneCells(40, 864);
        }
        $px = [];
        for ($y = 0; $y < 128; $y++) {
            for ($x = 0; $x < 128; $x++) {
                $i = $y * 128 + $x;
                $f = $fine[$i] - 0.5;
                $m = $mid[$i] - 0.5;
                $b = $big[$i] - 0.5;
                switch ($material) {
                    case 512:
                        $v = 100 + 13 * sin($x / 128 * 2 * M_PI * 5 + 7 * $b + 3 * $m) + 8 * $f;
                        break;
                    case 528:
                        $row = (int)($y / 32);
                        $edge = $y % 32 < 2 ? -32 : 0;
                        $v = 100 + 10 * sin($x / 128 * 2 * M_PI * 3 + $row * 2.1 + 5 * $m) + 7 * $f + $edge + ($row % 2 ? 4 : -4);
                        break;
                    case 784:
                        $vein = abs(sin(($x + $y) / 128 * 2 * M_PI * 2 + 7 * $b + 4 * $m));
                        $v = 108 - ($vein < 0.08 ? 30 : ($vein < 0.2 ? 10 : 0)) + 4 * $f;
                        break;
                    case 800:
                        $v = 100 + 34 * $m + 22 * $b + 14 * $f;
                        break;
                    case 816:
                        $v = 100 + 18 * $f + 8 * $m;
                        break;
                    case 832:
                        $v = 100 + 42 * $f + 6 * $m;
                        break;
                    case 848:
                        $row = (int)($y / 16);
                        $bx = ($x + ($row % 2 ? 16 : 0)) % 32;
                        $mortar = $y % 16 < 2 || $bx < 2;
                        $v = $mortar ? 128 : 94 + 12 * $m + 8 * $f;
                        break;
                    case 864:
                    case 880:
                        $e = $cells[$i];
                        $v = ($e < 1.5 ? 62 : ($e < 3.5 ? 84 : 104)) + 8 * $f + 8 * $m;
                        break;
                    case 1040:
                        $v = 92 + 56 * $m + 28 * $b + 18 * $f;
                        break;
                    case 1056:
                        $tx = $x % 32;
                        $ty = $y % 32;
                        $lift = abs($tx - $ty) < 2 && $tx > 7 && $tx < 25;
                        $tx2 = ($x + 16) % 32;
                        $ty2 = ($y + 16) % 32;
                        $lift2 = abs(($tx2 + $ty2) - 32) < 2 && $tx2 > 7 && $tx2 < 25;
                        $v = $lift || $lift2 ? 128 : 96 + 3 * $f;
                        break;
                    case 1072:
                        $v = 104 + 46 * $m + 24 * $f;
                        break;
                    case 1088:
                        $v = 102 + 14 * ($fine[$y * 128] - 0.5) + 6 * $f;
                        break;
                    case 1280:
                        $v = 98 + 32 * $f + 16 * $m + 10 * $b;
                        break;
                    case 1296:
                        $v = 101 + 22 * $f + 6 * $b;
                        break;
                    case 1312:
                        $v = 100 + ((($x >> 2) + ($y >> 2)) % 2 ? 7 : -7) + 6 * $f;
                        break;
                    default:
                        $v = 108 + 12 * $m + 10 * $b;
                }
                $px[] = (int)round($v);
            }
        }
        return $px;
    }

    private static function sceneOverlay($im, array $overlays, int $W, int $H): void {
        imagealphablending($im, true);
        $k = 1.0;
        if (isset($overlays['Message'])) {
            imagefilledrectangle($im, 0, 0, $W - 1, $H - 1, imagecolorallocatealpha($im, 0, 0, 0, 64));
            $font = self::sceneFont('ArialBold-18pt');
            if ($font) {
                $t = self::sceneText($font, self::sceneClean($overlays['Message']), $font['h'] * $k, (int)($W * 0.8));
                imagecopy($im, $t, (int)(($W - imagesx($t)) / 2), (int)(($H - imagesy($t)) / 2), 0, 0, imagesx($t), imagesy($t));
                imagedestroy($t);
            }
        }
        if (isset($overlays['Hint'])) {
            $font = self::sceneFont('Arial-18pt');
            $t = $font ? self::sceneText($font, self::sceneClean($overlays['Hint']), 15 * $k, (int)($W - 16 * $k)) : null;
            $band = (int)max(round(16 * $k), $t ? imagesy($t) : 0);
            imagefilledrectangle($im, 0, 0, $W - 1, $band - 1, imagecolorallocatealpha($im, 0, 0, 0, 0));
            if ($t) {
                imagecopy($im, $t, (int)(($W - imagesx($t)) / 2), (int)(($band - imagesy($t)) / 2), 0, 0, imagesx($t), imagesy($t));
                imagedestroy($t);
            }
        }
    }

    private static function sceneClean(string $text): string {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        return trim((string)preg_replace('/[^\P{C}\n]+/u', '', $text));
    }

    private static function sceneFont(string $name): ?array {
        static $fonts = [];
        if (array_key_exists($name, $fonts)) {
            return $fonts[$name];
        }
        $base = __DIR__ . '/../../data/fonts/' . $name;
        $def = @file_get_contents($base . '.fontdef');
        $img = is_file($base . '.png') ? @imagecreatefrompng($base . '.png') : false;
        if ($def === false || !$img) {
            return $fonts[$name] = null;
        }
        $iw = imagesx($img);
        $ih = imagesy($img);
        $glyphs = [];
        $h = 0;
        preg_match_all('/^\s*glyph\s+(\S+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)/m', $def, $m, PREG_SET_ORDER);
        foreach ($m as $g) {
            $key = strlen($g[1]) > 1 && $g[1][0] === 'u' ? mb_chr((int)substr($g[1], 1), 'UTF-8') : $g[1];
            $r = [(int)round($g[2] * $iw), (int)round($g[3] * $ih), (int)round(($g[4] - $g[2]) * $iw), (int)round(($g[5] - $g[3]) * $ih)];
            $glyphs[$key] = $r;
            $h = max($h, $r[3]);
        }
        return $fonts[$name] = $glyphs && $h ? ['img' => $img, 'glyphs' => $glyphs, 'h' => $h] : null;
    }

    private static function sceneGlyph(array $font, string $ch): ?array {
        return $font['glyphs'][$ch] ?? $font['glyphs']['?'] ?? null;
    }

    private static function sceneMeasure(array $font, string $text): int {
        $w = 0;
        foreach (mb_str_split($text, 1, 'UTF-8') as $ch) {
            $g = self::sceneGlyph($font, $ch);
            $w += $g ? $g[2] : 0;
        }
        return $w;
    }

    private static function sceneWrap(array $font, string $text, int $max): array {
        $out = [];
        foreach (explode("\n", $text) as $para) {
            $line = '';
            foreach (preg_split('/(\s+)/u', $para, -1, PREG_SPLIT_DELIM_CAPTURE) as $word) {
                $next = $line . $word;
                if ($line !== '' && self::sceneMeasure($font, rtrim($next)) > $max) {
                    $out[] = rtrim($line);
                    $line = ltrim($word);
                } else {
                    $line = $next;
                }
            }
            $out[] = rtrim($line);
        }
        return $out;
    }

    private static function sceneText(array $font, string $text, float $px, int $maxWidth) {
        $k = $px / $font['h'];
        $lines = self::sceneWrap($font, $text, max(1, (int)floor($maxWidth / $k)));
        $w = 1;
        foreach ($lines as $l) {
            $w = max($w, self::sceneMeasure($font, $l));
        }
        $h = count($lines) * $font['h'];
        $c = imagecreatetruecolor($w, $h);
        imagealphablending($c, false);
        imagesavealpha($c, true);
        imagefill($c, 0, 0, imagecolorallocatealpha($c, 255, 255, 255, 127));
        foreach ($lines as $i => $line) {
            $x = (int)round(($w - self::sceneMeasure($font, $line)) / 2);
            foreach (mb_str_split($line, 1, 'UTF-8') as $ch) {
                $g = self::sceneGlyph($font, $ch);
                if (!$g) {
                    continue;
                }
                if (trim($ch) !== '') {
                    for ($gy = 0; $gy < $g[3]; $gy++) {
                        for ($gx = 0; $gx < $g[2]; $gx++) {
                            $a = (imagecolorat($font['img'], $g[0] + $gx, $g[1] + $gy) >> 24) & 127;
                            if ($a < 127) {
                                imagesetpixel($c, $x + $gx, $i * $font['h'] + $gy, ($a << 24) | 0xffffff);
                            }
                        }
                    }
                }
                $x += $g[2];
            }
        }
        $ow = max(1, (int)round($w * $k));
        $oh = max(1, (int)round($h * $k));
        $o = imagecreatetruecolor($ow, $oh);
        imagealphablending($o, false);
        imagesavealpha($o, true);
        imagefill($o, 0, 0, imagecolorallocatealpha($o, 255, 255, 255, 127));
        imagecopyresampled($o, $c, 0, 0, 0, 0, $ow, $oh, $w, $h);
        imagedestroy($c);
        imagealphablending($o, true);
        return $o;
    }

    private static function sceneDecals(Inst $h, array $cf, array $size): array {
        $out = [];
        foreach ($h->children as $c) {
            if ($c->cls !== 'Decal') {
                continue;
            }
            $url = $c->props['Texture']['url'] ?? null;
            if (!$url) {
                continue;
            }
            $dt = $c->props['Transparency'] ?? 0;
            $da = is_numeric($dt) ? max(0.0, min(1.0, 1.0 - (float)$dt)) : 1.0;
            if ($da < 0.05) {
                continue;
            }
            $out[] = ['cf' => $cf, 'scale' => [1, 1, 1], 'off' => [0, 0, 0], 'color' => [1, 1, 1], 'alpha' => $da, 'tex' => null, 'texUrl' => $url, 'smooth' => false, 'size' => $size,
                'mesh' => self::sceneDecal($size, self::sceneFaceId($c->props['Face'] ?? 5)), 'decal' => true];
        }
        return $out;
    }

    private static function sceneFaces(array $faces): array {
        $out = ['pos' => [], 'nrm' => [], 'uv' => []];
        foreach ($faces as $f) {
            $n = self::norm(self::cross(self::sub($f[1], $f[0]), self::sub($f[2], $f[0])));
            $mid = [0.0, 0.0, 0.0];
            foreach ($f as $q) {
                $mid = [$mid[0] + $q[0], $mid[1] + $q[1], $mid[2] + $q[2]];
            }
            if (self::dot($n, $mid) < 0) {
                $f = array_reverse($f);
                $n = [-$n[0], -$n[1], -$n[2]];
            }
            for ($i = 1; $i + 1 < count($f); $i++) {
                foreach ([$f[0], $f[$i], $f[$i + 1]] as $p) {
                    $out['pos'][] = $p;
                    $out['nrm'][] = $n;
                    $out['uv'][] = [0, 0];
                }
            }
        }
        return $out;
    }

    private static function sceneWedge(float $x, float $y, float $z): array {
        $a = [-$x, -$y, -$z]; $b = [$x, -$y, -$z]; $c = [$x, -$y, $z]; $d = [-$x, -$y, $z];
        $e = [-$x, $y, $z]; $f = [$x, $y, $z];
        return self::sceneFaces([[$a, $b, $c, $d], [$d, $c, $f, $e], [$a, $e, $f, $b], [$a, $d, $e], [$b, $f, $c]]);
    }

    private static function sceneCorner(float $x, float $y, float $z): array {
        $a = [-$x, -$y, -$z]; $b = [$x, -$y, -$z]; $c = [$x, -$y, $z]; $d = [-$x, -$y, $z];
        $t = [$x, $y, -$z];
        return self::sceneFaces([[$a, $b, $c, $d], [$b, $t, $c], [$a, $t, $b], [$c, $t, $d], [$d, $t, $a]]);
    }

    private static function sceneCylinder(float $x, float $y, float $z, bool $alongY): array {
        $faces = [];
        $S = 16;
        $at = function (float $u, float $t) use ($x, $y, $z, $alongY) {
            return $alongY
                ? [cos($t) * $x, $u * $y, sin($t) * $z]
                : [$u * $x, cos($t) * $y, sin($t) * $z];
        };
        $top = [];
        $bot = [];
        for ($i = 0; $i < $S; $i++) {
            $t0 = $i / $S * 2 * M_PI;
            $t1 = ($i + 1) / $S * 2 * M_PI;
            $q = [$at(-1, $t0), $at(1, $t0), $at(1, $t1), $at(-1, $t1)];
            $faces[] = $alongY ? array_reverse($q) : $q;
            $top[] = $at(1, $t0);
            $bot[] = $at(-1, $t0);
        }
        $faces[] = $alongY ? $top : array_reverse($top);
        $faces[] = $alongY ? array_reverse($bot) : $bot;
        return self::sceneFaces($faces);
    }

    private function sceneCamera(array $items, string $kind, $cam, float $aspect): array {
        $centres = [];
        $lo = [INF, INF, INF];
        $hi = [-INF, -INF, -INF];
        foreach ($items as $it) {
            $p = $it['cf']['p'];
            $ext = $it['size'] ?? null;
            if (!$ext) {
                $bx = self::meshBounds($it['mesh']);
                $l = $bx[0] ?? $bx['min'] ?? [-1, -1, -1];
                $u = $bx[1] ?? $bx['max'] ?? [1, 1, 1];
                $ext = [$u[0] - $l[0], $u[1] - $l[1], $u[2] - $l[2]];
                $p = [($u[0] + $l[0]) / 2, ($u[1] + $l[1]) / 2, ($u[2] + $l[2]) / 2];
            }
            $big = max($ext);
            if ($kind === 'place' && $big > 400) {
                continue;
            }
            $centres[] = $p;
            for ($k = 0; $k < 3; $k++) {
                $lo[$k] = min($lo[$k], $p[$k] - $ext[$k] / 2);
                $hi[$k] = max($hi[$k], $p[$k] + $ext[$k] / 2);
            }
        }
        if (!$centres) {
            foreach ($items as $it) {
                $centres[] = $it['cf']['p'];
            }
            $lo = [-64, 0, -64];
            $hi = [64, 32, 64];
        }
        if ($kind === 'place' && count($centres) > 10) {
            for ($k = 0; $k < 3; $k++) {
                $v = array_column($centres, $k);
                sort($v);
                $n = count($v);
                $lo[$k] = max($lo[$k], $v[(int)($n * 0.03)] - 8);
                $hi[$k] = min($hi[$k], $v[(int)($n * 0.97)] + 8);
            }
        }
        $pose = $kind === 'place' ? self::sceneThumbCamera($cam) : null;
        if ($pose && abs($pose[0][0]) + abs($pose[0][1]) + abs($pose[0][2]) > 0.01) {
            [$eye, $t, $fov] = $pose;
            $dir = self::norm(self::sub($t, $eye));
            $tanY = tan(deg2rad($fov) / 2);
            $tanX = $tanY * $aspect;
            $up = abs($dir[1]) > 0.99 ? [0, 0, 1] : [0, 1, 0];
            $right = self::norm(self::cross($dir, $up));
            $upv = self::cross($right, $dir);
            foreach ($items as $it) {
                $v = self::sub($it['cf']['p'], $eye);
                $z = self::dot($v, $dir);
                if ($z > 0.2 && abs(self::dot($v, $right)) < $z * $tanX && abs(self::dot($v, $upv)) < $z * $tanY) {
                    return $pose;
                }
            }
        }
        $c = [($lo[0] + $hi[0]) / 2, ($lo[1] + $hi[1]) / 2, ($lo[2] + $hi[2]) / 2];
        $r = max(1.0, sqrt(pow($hi[0] - $lo[0], 2) + pow($hi[1] - $lo[1], 2) + pow($hi[2] - $lo[2], 2)) / 2);
        $fov = $kind === 'place' ? 50.0 : 30.0;
        $dir = $kind === 'place' ? self::norm([-0.62, 0.5, -0.6]) : self::norm([-0.5, 0.35, -0.8]);
        $fit = $r / sin(deg2rad($fov) / 2 * min(1.0, $aspect));
        $dist = $kind === 'place' ? $fit * 0.8 : $fit * 1.05;
        return [[$c[0] + $dir[0] * $dist, $c[1] + $dir[1] * $dist, $c[2] + $dir[2] * $dist], $c, $fov];
    }

    private static function sceneReflect(array $col, float $refl, array $v, array $n): array {
        $vl = self::norm($v);
        $nn = self::norm($n);
        $d = $vl[0] * $nn[0] + $vl[1] * $nn[1] + $vl[2] * $nn[2];
        $ry = $vl[1] - 2 * $d * $nn[1];
        if ($ry >= 0) {
            $t = min(1.0, $ry * 1.4);
            $env = [0.80 + (0.42 - 0.80) * $t, 0.87 + (0.62 - 0.87) * $t, 0.96 + (0.92 - 0.96) * $t];
        } else {
            $t = min(1.0, -$ry * 2);
            $env = [0.55 + (0.33 - 0.55) * $t, 0.55 + (0.32 - 0.55) * $t, 0.53 + (0.30 - 0.53) * $t];
        }
        $k = $refl * 0.85;
        return [$col[0] * (1 - $k) + $env[0] * $k, $col[1] * (1 - $k) + $env[1] * $k, $col[2] * (1 - $k) + $env[2] * $k];
    }

    private function sceneSparkles(array $parts, array $eye, array $target): array {
        $fx = $this->base()['fx'][0] ?? null;
        if (!$fx || empty($fx->levels[0])) return [];
        [$tw, $th, $tpx] = $fx->levels[0];
        $dir = self::norm(self::sub($target, $eye));
        $upRef = abs($dir[1]) > 0.99 ? [0, 0, 1] : [0, 1, 0];
        $right = self::norm(self::cross($dir, $upRef));
        $up = self::cross($right, $dir);
        $tinted = [];
        $out = [];
        $count = 0;
        foreach ($parts as $h) {
            if (!isset($h->props['CFrame'])) continue;
            foreach ($h->children as $c) {
                if ($c->cls !== 'Sparkles' || ($c->props['Enabled'] ?? true) === false || $count >= 60) continue;
                $count++;
                $sc = $c->props['SparkleColor'] ?? null;
                $rgb = is_array($sc) && isset($sc['r']) ? [$sc['r'], $sc['g'], $sc['b']] : [144 / 255, 25 / 255, 1.0];
                $key = implode(',', array_map(fn($v) => round($v, 3), $rgb));
                if (!isset($tinted[$key])) {
                    $px = [];
                    foreach ($tpx as $p) {
                        $a = (int)((($p >> 24) & 255) * sqrt(max(($p >> 16) & 255, ($p >> 8) & 255, $p & 255) / 255));
                        $px[] = ($a << 24) | ((int)min(255, (($p >> 16) & 255) * $rgb[0] * 1.3) << 16) | ((int)min(255, (($p >> 8) & 255) * $rgb[1] * 1.3) << 8) | (int)min(255, ($p & 255) * $rgb[2] * 1.3);
                    }
                    $tinted[$key] = new Img($tw, $th, $px);
                }
                $cf = self::cfFrom($h->props['CFrame']);
                $size = self::partSize($h);
                $ctr = $cf['p'];
                mt_srand(crc32(sprintf('%.2f,%.2f,%.2f', $ctr[0], $ctr[1], $ctr[2])));
                $reach = [$size[0] / 2 + 0.8, $size[1] / 2 + 1.6, $size[2] / 2 + 0.8];
                $pos = [];
                $nrm = [];
                $uv = [];
                for ($i = 0; $i < 12; $i++) {
                    $q = [$ctr[0] + (mt_rand() / mt_getrandmax() * 2 - 1) * $reach[0], $ctr[1] + (mt_rand() / mt_getrandmax() * 1.6 - 0.6) * $reach[1], $ctr[2] + (mt_rand() / mt_getrandmax() * 2 - 1) * $reach[2]];
                    $hs = 0.45 + mt_rand() / mt_getrandmax() * 0.45;
                    $corner = fn($sx, $sy) => [$q[0] + $right[0] * $sx * $hs + $up[0] * $sy * $hs, $q[1] + $right[1] * $sx * $hs + $up[1] * $sy * $hs, $q[2] + $right[2] * $sx * $hs + $up[2] * $sy * $hs];
                    $tl = $corner(-1, 1);
                    $tr = $corner(1, 1);
                    $bl = $corner(-1, -1);
                    $br = $corner(1, -1);
                    array_push($pos, $tl, $bl, $br, $tl, $br, $tr);
                    $n = [-$dir[0], -$dir[1], -$dir[2]];
                    array_push($nrm, $n, $n, $n, $n, $n, $n);
                    array_push($uv, [0, 0], [0, 1], [1, 1], [0, 0], [1, 1], [1, 0]);
                }
                $out[] = ['cf' => self::CF(), 'scale' => [1, 1, 1], 'off' => [0, 0, 0], 'color' => [1, 1, 1], 'alpha' => 1.0, 'tex' => $tinted[$key], 'smooth' => false,
                    'size' => [0.5, 0.5, 0.5], 'decal' => true, 'mesh' => ['pos' => $pos, 'nrm' => $nrm, 'uv' => $uv]];
            }
        }
        return $out;
    }

    private function sceneTriangles(array $items, array $eye, array $target, float $fov, int $W, int $H, float $deadline, array &$notes): array {
        $dir = self::norm(self::sub($target, $eye));
        $upRef = abs($dir[1]) > 0.99 ? [0, 0, 1] : [0, 1, 0];
        $right = self::norm(self::cross($dir, $upRef));
        $up = self::cross($right, $dir);
        $f = ($H / 2) / tan(deg2rad($fov) / 2);
        $cx = $W / 2;
        $cy = $H / 2;
        $near = 0.2;
        $sun = self::norm([0.45, 0.8, -0.4]);
        $fill = self::norm([-0.5, 0.2, 0.6]);
        $solid = [];
        $glass = [];
        $count = 0;
        usort($items, function ($p, $q) {
            $pd = empty($p['decal']) ? 0 : 1;
            $qd = empty($q['decal']) ? 0 : 1;
            if ($pd !== $qd) {
                return $qd <=> $pd;
            }
            $ps = $p['size'] ?? [0, 0, 0];
            $qs = $q['size'] ?? [0, 0, 0];
            return max($qs) <=> max($ps);
        });
        $limit = 700000;
        foreach ($items as $it) {
            if ($count > $limit || microtime(true) > $deadline - 20) {
                $notes[] = 'scene was cut short to stay within limits';
                break;
            }
            $R = $it['cf'];
            $m = $it['mesh'];
            $sx = $it['scale'][0];
            $sy = $it['scale'][1];
            $sz = $it['scale'][2];
            $ox = $it['off'][0];
            $oy = $it['off'][1];
            $oz = $it['off'][2];
            $col = $it['color'];
            $tex = $it['tex'];
            $smooth = $it['smooth'];
            $alpha = $it['alpha'];
            $decal = !empty($it['decal']);
            $tiles = $it['tiles'] ?? null;
            $unlit = !empty($it['unlit']);
            $partSize = $it['size'] ?? [0, 0, 0];
            $refl = $decal ? 0.0 : (float)($it['refl'] ?? 0);
            $halfs = [$partSize[0] / 2, $partSize[1] / 2, $partSize[2] / 2];
            $posL = $m['pos'];
            $nrmL = $m['nrm'];
            $uvL = $m['uv'];
            $n = count($posL);
            $nonUniform = abs($sx - $sy) > 1e-3 || abs($sy - $sz) > 1e-3;
            for ($i = 0; $i + 2 < $n; $i += 3) {
                $vs = [];
                for ($j = 0; $j < 3; $j++) {
                    $q = $posL[$i + $j];
                    $w = self::cfApply($R, [$q[0] * $sx + $ox, $q[1] * $sy + $oy, $q[2] * $sz + $oz]);
                    $v = [$w[0] - $eye[0], $w[1] - $eye[1], $w[2] - $eye[2]];
                    $zc = $v[0] * $dir[0] + $v[1] * $dir[1] + $v[2] * $dir[2];
                    $xc = $v[0] * $right[0] + $v[1] * $right[1] + $v[2] * $right[2];
                    $yc = $v[0] * $up[0] + $v[1] * $up[1] + $v[2] * $up[2];
                    $nl = $nrmL[$i + $j] ?? [0, 1, 0];
                    if ($nonUniform) {
                        $nl = self::norm([$nl[0] / max(1e-4, $sx), $nl[1] / max(1e-4, $sy), $nl[2] / max(1e-4, $sz)]);
                    }
                    $nw = self::rmul($R['R'], $nl);
                    if ($j === 0) {
                        $n0 = $nw;
                        $v0 = $v;
                    }
                    $d1 = max(0.0, $nw[0] * $sun[0] + $nw[1] * $sun[1] + $nw[2] * $sun[2]);
                    $d2 = max(0.0, $nw[0] * $fill[0] + $nw[1] * $fill[1] + $nw[2] * $fill[2]);
                    $uv = $uvL[$i + $j] ?? [0, 0];
                    if ($tiles) {
                        if ($j === 0) {
                            $nf = $nrmL[$i] ?? [0, 1, 0];
                            $ax = abs($nf[0]);
                            $ay = abs($nf[1]);
                            $az = abs($nf[2]);
                            $axis = $ax >= $ay && $ax >= $az ? 0 : ($ay >= $az ? 1 : 2);
                            $faceTile = $tiles[$nf[$axis] >= 0 ? $axis : $axis + 3];
                        }
                        $lx = $q[0] + $halfs[0];
                        $ly = $q[1] + $halfs[1];
                        $lz = $q[2] + $halfs[2];
                        $uv = $axis === 0 ? [$lz / 4, $ly / 4] : ($axis === 1 ? [$lx / 4, $lz / 4] : [$lx / 4, $ly / 4]);
                    }
                    $vs[] = [$xc, $yc, $zc, $unlit ? 1.15 : 0.5 + 0.55 * $d1 + 0.12 * $d2, $uv[0], $uv[1]];
                }
                $poly = $vs;
                if ($vs[0][2] < $near || $vs[1][2] < $near || $vs[2][2] < $near) {
                    $poly = [];
                    for ($j = 0; $j < 3; $j++) {
                        $a = $vs[$j];
                        $b = $vs[($j + 1) % 3];
                        $ai = $a[2] >= $near;
                        $bi = $b[2] >= $near;
                        if ($ai) {
                            $poly[] = $a;
                        }
                        if ($ai !== $bi) {
                            $t = ($near - $a[2]) / ($b[2] - $a[2]);
                            $c = [];
                            for ($k = 0; $k < 6; $k++) {
                                $c[$k] = $a[$k] + ($b[$k] - $a[$k]) * $t;
                            }
                            $poly[] = $c;
                        }
                    }
                    if (count($poly) < 3) {
                        continue;
                    }
                }
                $pc = [];
                foreach ($poly as $q) {
                    $iz = 1.0 / $q[2];
                    $pc[] = [$cx + $q[0] * $f * $iz, $cy - $q[1] * $f * $iz, $iz, $q[3], $q[4], $q[5]];
                }
                for ($t = 1; $t + 1 < count($pc); $t++) {
                    $a = $pc[0];
                    $b = $pc[$t];
                    $c = $pc[$t + 1];
                    $area = ($b[0] - $a[0]) * ($c[1] - $a[1]) - ($c[0] - $a[0]) * ($b[1] - $a[1]);
                    if ($area > -0.01) {
                        continue;
                    }
                    if (max($a[0], $b[0], $c[0]) < 0 || min($a[0], $b[0], $c[0]) >= $W
                        || max($a[1], $b[1], $c[1]) < 0 || min($a[1], $b[1], $c[1]) >= $H) {
                        continue;
                    }
                    $tcol = $refl > 0.01 ? self::sceneReflect($col, $refl, $v0, $n0) : $col;
                    $tri = [$a, $b, $c, $area, $tcol, $tex, $smooth, $alpha, $decal, $tiles ? $faceTile : null, $f];
                    if ($alpha < 0.99 || $decal) {
                        $glass[] = $tri;
                    } else {
                        $solid[] = $tri;
                    }
                    $count++;
                }
            }
        }
        usort($glass, function ($p, $q) {
            return ($p[0][2] + $p[1][2] + $p[2][2]) <=> ($q[0][2] + $q[1][2] + $q[2][2]);
        });
        return [$solid, $glass];
    }

    private static function sceneRaster(array $tris, int $W, int $H, bool $sky, int $outW, int $outH, float $deadline, array $overlays = []): string {
        $N = $W * $H;
        $Z = array_fill(0, $N, 0.0);
        if ($sky) {
            $C = [];
            for ($y = 0; $y < $H; $y++) {
                $t = $y / max(1, $H - 1);
                $r = (int)(96 + 110 * $t);
                $g = (int)(158 + 62 * $t);
                $b = (int)(232 + 16 * $t);
                $rgb = (0 << 24) | ($r << 16) | ($g << 8) | $b;
                for ($x = 0; $x < $W; $x++) {
                    $C[] = $rgb;
                }
            }
        } else {
            $C = array_fill(0, $N, 127 << 24);
        }
        foreach ([0, 1] as $pass) {
            $i = 0;
            foreach ($tris[$pass] as $T) {
                if ((++$i & 1023) === 0 && microtime(true) > $deadline) {
                    break 2;
                }
                [$a, $b, $c, $area, $col, $tex, $smooth, $alpha, $decal, $tile, $focal] = $T;
                $x0 = max(0, (int)floor(min($a[0], $b[0], $c[0])));
                $x1 = min($W - 1, (int)ceil(max($a[0], $b[0], $c[0])));
                $y0 = max(0, (int)floor(min($a[1], $b[1], $c[1])));
                $y1 = min($H - 1, (int)ceil(max($a[1], $b[1], $c[1])));
                if ($x1 < $x0 || $y1 < $y0) {
                    continue;
                }
                $ia = -1.0 / $area;
                $e0x = ($c[1] - $b[1]) * $ia; $e0y = ($b[0] - $c[0]) * $ia;
                $e1x = ($a[1] - $c[1]) * $ia; $e1y = ($c[0] - $a[0]) * $ia;
                $e2x = ($b[1] - $a[1]) * $ia; $e2y = ($a[0] - $b[0]) * $ia;
                $px = $x0 + 0.5;
                $py = $y0 + 0.5;
                $r0 = (($px - $b[0]) * ($c[1] - $b[1]) - ($py - $b[1]) * ($c[0] - $b[0])) * $ia;
                $r1 = (($px - $c[0]) * ($a[1] - $c[1]) - ($py - $c[1]) * ($a[0] - $c[0])) * $ia;
                $r2 = (($px - $a[0]) * ($b[1] - $a[1]) - ($py - $a[1]) * ($b[0] - $a[0])) * $ia;
                $za = $a[2]; $zb = $b[2]; $zc = $c[2];
                $la = $a[3]; $lb = $b[3]; $lc = $c[3];
                if (!$smooth) {
                    $la = $lb = $lc = ($la + $lb + $lc) / 3;
                }
                $cr = $col[0] * 255;
                $cg = $col[1] * 255;
                $cb = $col[2] * 255;
                $tw = $tex ? $tex->w : 0;
                $th = $tex ? $tex->h : 0;
                $tpx = $tex ? $tex->px : null;
                $ua = $a[4] * $za; $ub = $b[4] * $zb; $uc = $c[4] * $zc;
                $va = $a[5] * $za; $vb = $b[5] * $zb; $vc = $c[5] * $zc;
                for ($y = $y0; $y <= $y1; $y++) {
                    $w0 = $r0; $w1 = $r1; $w2 = $r2;
                    $row = $y * $W;
                    for ($x = $x0; $x <= $x1; $x++) {
                        if ($w0 >= 0 && $w1 >= 0 && $w2 >= 0) {
                            $iz = $w0 * $za + $w1 * $zb + $w2 * $zc;
                            $k = $row + $x;
                            if ($iz > $Z[$k]) {
                                $l = $w0 * $la + $w1 * $lb + $w2 * $lc;
                                $r = $cr; $g = $cg; $bl = $cb;
                                $over = $alpha;
                                if ($tile !== null) {
                                    $zz = 1.0 / $iz;
                                    $u = ($w0 * $ua + $w1 * $ub + $w2 * $uc) * $zz;
                                    $v = ($w0 * $va + $w1 * $vb + $w2 * $vc) * $zz;
                                    $pps = $iz * $focal;
                                    if ($pps >= 16) {
                                        $lv = 0;
                                        $ts = 128;
                                    } elseif ($pps >= 8) {
                                        $lv = 1;
                                        $ts = 64;
                                    } elseif ($pps >= 4) {
                                        $lv = 2;
                                        $ts = 32;
                                    } elseif ($pps >= 2) {
                                        $lv = 3;
                                        $ts = 16;
                                    } elseif ($pps >= 1) {
                                        $lv = 4;
                                        $ts = 8;
                                    } else {
                                        $lv = 5;
                                        $ts = 4;
                                    }
                                    $l *= $tile[$lv][((int)(($v - floor($v)) * $ts) & ($ts - 1)) * $ts + ((int)(($u - floor($u)) * $ts) & ($ts - 1))] / 100;
                                }
                                if ($tpx) {
                                    $zz = 1.0 / $iz;
                                    $u = ($w0 * $ua + $w1 * $ub + $w2 * $uc) * $zz;
                                    $v = ($w0 * $va + $w1 * $vb + $w2 * $vc) * $zz;
                                    $tx = ((int)floor(($u - floor($u)) * $tw)) % $tw;
                                    $ty = ((int)floor(($v - floor($v)) * $th)) % $th;
                                    $s = $tpx[$ty * $tw + $tx];
                                    $ta = ($s >> 24) & 255;
                                    if ($ta < 8) {
                                        $w0 += $e0x; $w1 += $e1x; $w2 += $e2x;
                                        continue;
                                    }
                                    $f = $ta / 255;
                                    if ($decal) {
                                        $r = ($s >> 16) & 255;
                                        $g = ($s >> 8) & 255;
                                        $bl = $s & 255;
                                        $over = $alpha * $f;
                                        $l = 1.0;
                                    } else {
                                        $r = (($s >> 16) & 255) * $f + $r * (1 - $f);
                                        $g = (($s >> 8) & 255) * $f + $g * (1 - $f);
                                        $bl = ($s & 255) * $f + $bl * (1 - $f);
                                    }
                                }
                                $r = min(255.0, $r * $l);
                                $g = min(255.0, $g * $l);
                                $bl = min(255.0, $bl * $l);
                                if ($over < 0.99) {
                                    $d = $C[$k];
                                    $da = ($d >> 24) & 127;
                                    if ($da === 127) {
                                        $C[$k] = ((int)(127 - 127 * $over) << 24) | ((int)$r << 16) | ((int)$g << 8) | (int)$bl;
                                    } else {
                                        $r = $r * $over + (($d >> 16) & 255) * (1 - $over);
                                        $g = $g * $over + (($d >> 8) & 255) * (1 - $over);
                                        $bl = $bl * $over + ($d & 255) * (1 - $over);
                                        $C[$k] = ($da << 24) | ((int)$r << 16) | ((int)$g << 8) | (int)$bl;
                                    }
                                } else {
                                    $Z[$k] = $iz;
                                    $C[$k] = ((int)$r << 16) | ((int)$g << 8) | (int)$bl;
                                }
                            }
                        }
                        $w0 += $e0x; $w1 += $e1x; $w2 += $e2x;
                    }
                    $r0 += $e0y; $r1 += $e1y; $r2 += $e2y;
                }
            }
        }
        $im = imagecreatetruecolor($W, $H);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        $k = 0;
        for ($y = 0; $y < $H; $y++) {
            for ($x = 0; $x < $W; $x++) {
                imagesetpixel($im, $x, $y, $C[$k++]);
            }
        }
        unset($C, $Z);
        if ($outW !== $W || $outH !== $H) {
            $o = imagecreatetruecolor($outW, $outH);
            imagealphablending($o, false);
            imagesavealpha($o, true);
            imagefill($o, 0, 0, imagecolorallocatealpha($o, 0, 0, 0, 127));
            imagecopyresampled($o, $im, 0, 0, 0, 0, $outW, $outH, $W, $H);
            imagedestroy($im);
            $im = $o;
        }
        if ($overlays) {
            self::sceneOverlay($im, $overlays, imagesx($im), imagesy($im));
            imagealphablending($im, false);
        }
        ob_start();
        imagepng($im);
        imagedestroy($im);
        return (string)ob_get_clean();
    }
}
