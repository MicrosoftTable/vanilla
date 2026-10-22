<?php
namespace Vanilla;

// fetching assets by id and decoding them (only ever from the configured host)
trait Assets {
    // an asset is a number, or a number and a version written 123@4 (a version is a number, live or latest)
    static function parseId($s): ?string {
        $s = trim((string)$s);
        return preg_match('/^[1-9]\d{0,17}(@([1-9]\d{0,5}|live|latest))?$/D', $s) ? $s : null;
    }

    // "123@4" -> ["123", "4"]; "123" -> ["123", null]
    static function splitKey(string $key): array {
        $p = explode('@', $key, 2);
        return [$p[0], $p[1] ?? null];
    }

    // the address of an asset on the asset host, with its version when it has one
    private function assetUrl(string $key): string {
        [$n, $v] = self::splitKey($key);
        return $this->assetBase . $n . ($v === null ? '' : '&version=' . $v);
    }

    // a version that can change ("live", "latest") is only kept a minute; everything else a day
    private static function keepFor(string $key): int {
        return preg_match('/@(live|latest)$/D', $key) ? 60 : 86400;
    }

    static function assetId($ref): ?string {
        if (!is_string($ref) || $ref === '') {
            return null;
        }
        if (
            preg_match('/[?&]id=(\d{1,18})(?!\d)/i', $ref, $m) || preg_match('/^rbxassetid:\/\/(\d{1,18})$/iD', $ref, $m) || preg_match(
                '/^(\d{1,18})$/D',
                trim($ref),
                $m
            )
        ) {
            if (!preg_match('/^[1-9]/', $m[1])) {
                return null;
            }
            // ...&version=N pins a version
            if (preg_match('/[?&]version=([1-9]\d{0,5}|live|latest)(?![\w])/i', $ref, $v)) {
                return $m[1] . '@' . strtolower($v[1]);
            }
            // 123@4 as written by a caller
            if (preg_match('/^(\d{1,18})@([1-9]\d{0,5}|live|latest)$/iD', trim($ref), $w) && preg_match('/^[1-9]/', $w[1])) {
                return $w[1] . '@' . strtolower($w[2]);
            }
            return $m[1];
        }
        if (preg_match('/^([1-9]\d{0,17})@([1-9]\d{0,5}|live|latest)$/iD', trim($ref), $w)) {
            return $w[1] . '@' . strtolower($w[2]);
        }
        return null;
    }

    function fetch($id): ?string {
        $id = self::parseId($id);
        if ($id === null) {
            return null;
        }
        if (array_key_exists($id, $this->cache)) {
            return $this->cache[$id];
        }
        $b = null;
        $file = $this->cacheDir ? $this->cacheDir . '/asset-' . $id : null;
        if ($file && is_file($file) && filemtime($file) > time() - self::keepFor($id)) {
            $b = file_get_contents($file);
        }
        if ($b === null || $b === false) {
            $b = null;
            for ($n = 0; $n < 3 && $b === null; $n++) {
                if ($n) {
                    usleep(400000 * $n);
                }
                $b = $this->fetcher ? ($this->fetcher)($id) : $this->httpGet($this->assetUrl($id));
            }
            if ($b !== null && $file) {
                @file_put_contents($file, $b, LOCK_EX);
            }
        }
        if ($b !== null && strlen($b) > self::MAX_ASSET_BYTES) {
            $b = null;
        }
        return $this->cache[$id] = $b;
    }

    // assets are only ever fetched by number from the one configured host and only from public addresses
    private function netOpts(): array {
        $port = parse_url($this->assetBase, PHP_URL_PORT);
        return [
            'maxBytes' => self::MAX_ASSET_BYTES,
            'redirects' => 4,
            'allowPrivate' => $this->allowPrivate,
            'ports' => $port ? [(int)$port] : [],
        ];
    }

    private function httpGet(string $url): ?string {
        return Net::get($url, $this->netOpts());
    }

    static function referencedIds(string $bytes): array {
        try {
            $tree = Rbxm::parse($bytes);
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        $keys = [
            'MeshId',
            'MeshID',
            'TextureId',
            'TextureID',
            'Texture',
            'ShirtTemplate',
            'PantsTemplate',
            'Graphic',
            'Image',
            'ColorMap', 'BaseTextureId', 'OverlayTextureId'
        ];
        $walk = function ($x, $d) use (&$walk, &$out, $keys) {
            if ($d > 12 || count($out) > 24) {
                return;
            }
            foreach ($keys as $k) {
                $v = $x->props[$k] ?? null;
                if ($x->cls === 'CharacterMesh' && (is_int($v) || is_string($v) || (is_float($v) && is_finite($v) && $v === floor($v)))) {
                    $id = self::parseId($v);
                    if ($id) { $out[$id] = $id; }
                }
                if (is_array($v) && isset($v['url'])) {
                    $id = self::assetId($v['url']);
                    if ($id) {
                        $out[$id] = $id;
                    }
                }
            }
            foreach ($x->children as $c) {
                $walk($c, $d + 1);
            }
        };
        foreach ($tree['roots'] as $r) {
            $walk($r, 0);
        }
        return array_values($out);
    }

    // fetches the assets and then the meshes and images they point at, a few rounds deep
    function loadWaves(array $ids): void {
        $wave = $ids;
        for ($round = 0; $round < 3 && $wave; $round++) {
            $this->prefetch($wave);
            $next = [];
            foreach ($wave as $id) {
                $b = $this->cache[self::parseId($id) ?? ''] ?? null;
                if ($b === null || Img::isImageBytes($b)) {
                    continue;
                }
                foreach (self::referencedIds($b) as $r) {
                    if (!array_key_exists($r, $this->cache)) {
                        $next[$r] = $r;
                    }
                }
            }
            $wave = array_slice(array_values($next), 0, 24);
        }
    }

    function prefetch(array $ids): void {
        if ($this->fetcher || !function_exists('curl_multi_init')) {
            return;
        }
        $need = [];
        foreach ($ids as $id) {
            $id = self::parseId($id);
            if ($id === null || array_key_exists($id, $this->cache)) {
                continue;
            }
            $file = $this->cacheDir ? $this->cacheDir . '/asset-' . $id : null;
            if ($file && is_file($file) && filemtime($file) > time() - self::keepFor($id)) {
                continue;
            }
            $need[$id] = $file;
        }
        if (!$need) {
            return;
        }
        $opts = $this->netOpts();
        $mh = curl_multi_init();
        $hs = [];
        $bufs = [];
        foreach ($need as $id => $file) {
            $t = Net::target($this->assetUrl((string)$id), $opts);
            if ($t === null) {
                continue;
            }
            $bufs[$id] = '';
            $h = curl_init();
            curl_setopt_array($h, Net::options($t, $bufs[$id], $opts));
            curl_multi_add_handle($mh, $h);
            $hs[$id] = $h;
        }
        $running = 0;
        do {
            curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 0.2);
            }
        } while ($running > 0);
        foreach ($hs as $id => $h) {
            $code = (int)curl_getinfo($h, CURLINFO_RESPONSE_CODE);
            $loc = (string)curl_getinfo($h, CURLINFO_REDIRECT_URL);
            $body = null;
            if ($code >= 200 && $code < 300 && curl_errno($h) === 0 && strlen($bufs[$id]) <= self::MAX_ASSET_BYTES) {
                $body = $bufs[$id];
            } elseif ($code >= 300 && $code < 400 && $loc !== '') {
                // redirects are followed one checked hop at a time
                $body = Net::get($loc, ['redirects' => 3] + $opts);
            }
            if ($body !== null) {
                if ($need[$id]) {
                    @file_put_contents($need[$id], $body, LOCK_EX);
                }
                $this->cache[$id] = $body;
            }
            curl_multi_remove_handle($mh, $h);
            curl_close($h);
        }
        curl_multi_close($mh);
    }

    function bytes($ref): ?string {
        if (self::isContent($ref)) {
            return $this->contentBytes($ref);
        }
        $id = self::assetId($ref);
        return $id ? $this->fetch($id) : null;
    }

    static function isContent($ref): bool {
        return is_string($ref) && stripos($ref, 'rbxasset://') === 0;
    }

    // rbxasset:// is rewritten to the asset url: a number is an asset id and a path is added after the asset base
    private function contentBytes(string $ref): ?string {
        if (array_key_exists($ref, $this->content)) {
            return $this->content[$ref];
        }
        $b = null;
        $rel = substr($ref, 11);
        if (preg_match('/^[1-9]\d{0,17}$/D', $rel)) {
            $b = $this->fetch($rel);
        } elseif (strlen($rel) <= 200 && preg_match('#^[A-Za-z0-9_ .\-]+(/[A-Za-z0-9_ .\-]+){0,7}$#D', $rel) && strpos($rel, '..') === false
            && in_array(strtolower(pathinfo($rel, PATHINFO_EXTENSION)), ['mesh', 'png', 'jpg', 'jpeg'], true)) {
            $own = $this->bundledContent(strtolower($rel));
            if ($own !== null) {
                $b = (string)file_get_contents($own);
            } else {
                $b = $this->contentFetch($rel);
            }
        }
        return $this->content[$ref] = $b;
    }

    private function contentFetch(string $rel): ?string {
        $file = $this->cacheDir ? $this->cacheDir . '/content-' . hash('sha256', $this->assetBase . $rel) : null;
        if ($file && is_file($file) && filemtime($file) > time() - 300) {
            return (string)file_get_contents($file);
        }
        $url = $this->assetBase . implode('/', array_map('rawurlencode', explode('/', $rel)));
        $b = $this->fetcher ? ($this->fetcher)($rel) : $this->httpGet($url);
        if ($b !== null && strlen($b) > self::MAX_ASSET_BYTES) {
            $b = null;
        }
        if ($b !== null && $file) {
            @file_put_contents($file, $b, LOCK_EX);
        }
        return $b;
    }

    private function bundledContent(string $rel): ?string {
        $map = ['fonts/head.mesh' => 'head.mesh', 'fonts/torso.mesh' => 'torso.mesh', 'fonts/leftarm.mesh' => 'leftarm.mesh', 'fonts/rightarm.mesh' => 'rightarm.mesh',
            'fonts/leftleg.mesh' => 'leftleg.mesh', 'fonts/rightleg.mesh' => 'rightleg.mesh', 'textures/face.png' => 'face.png'];
        return isset($map[$rel]) ? $this->dataDir . '/' . $map[$rel] : null;
    }

    private function decoded(string $id, string $bytes, bool $hat): ?Img {
        $f = $this->cacheDir ? $this->cacheDir . '/img-' . $id . ($hat ? 'h' : '') . '.z' : null;
        if ($f && is_file($f) && filemtime($f) > time() - self::keepFor($id)) {
            $im = Img::fromCache((string)@file_get_contents($f));
            if ($im) {
                return $im;
            }
        }
        $im = $hat ? Img::decodeHat($bytes) : Img::decode($bytes, self::MAX_IMAGE_SIDE);
        if ($im && $f) {
            $tmp = $f . '.' . getmypid() . '.tmp';
            if (@file_put_contents($tmp, Img::toCache($im)) !== false) {
                @rename($tmp, $f);
            }
        }
        return $im;
    }

    function image($ref, int $depth = 0, bool $big = false): ?Img {
        if (self::isContent($ref)) {
            $b = $this->contentBytes($ref);
            return $b !== null && Img::isImageBytes($b) ? ($big ? Img::decodeHat($b) : Img::decode($b, self::MAX_IMAGE_SIDE)) : null;
        }
        $id = self::assetId($ref);
        if (!$id) {
            return null;
        }
        $b = $this->fetch($id);
        if ($b === null) {
            return null;
        }
        if (Img::isImageBytes($b)) {
            return $this->decoded($id, $b, $big);
        }
        if ($depth > 2) {
            return null;
        }
        try {
            $tree = Rbxm::parse($b);
        } catch (\Throwable $e) {
            return null;
        }
        $found = null;
        $walk = function ($x) use (&$walk, &$found, $id) {
            foreach (['Texture', 'ShirtTemplate', 'PantsTemplate', 'Graphic', 'TextureId', 'Image'] as $k) {
                $v = $x->props[$k] ?? null;
                if (!$found && is_array($v) && isset($v['url']) && self::assetId($v['url']) && self::assetId($v['url']) !== $id) {
                    $found = $v['url'];
                }
            }
            foreach ($x->children as $c) {
                $walk($c);
            }
        };
        foreach ($tree['roots'] as $r) {
            $walk($r);
        }
        return $found ? $this->image($found, $depth + 1, $big) : null;
    }
}
