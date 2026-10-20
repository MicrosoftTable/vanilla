<?php
namespace Vanilla;

require_once __DIR__ . '/Rbxm.php';
require_once __DIR__ . '/Net.php';

// reads a roblox charapp string like https://host/;https://host/asset/?id=1;https://host/asset/?id=2;
final class CharApp {
    const MAX_CHARS = 4096, MAX_FETCH_BYTES = 65536, MAX_ENTRIES = 64;
    const PARTS = [
        'HeadColor' => 0,
        'TorsoColor' => 1,
        'LeftArmColor' => 2,
        'RightArmColor' => 3,
        'LeftLegColor' => 4,
        'RightLegColor' => 5
    ];

    // opts: assetBase allowedHosts cacheDir and get (a function from url to body or null, used by tests)
    // returns ids (asset ids in order) and colors (six body colors or null when the charapp has no bodycolors)
    static function resolve(string $charapp, array $opts): array {
        $charapp = trim($charapp);
        if ($charapp === '' || strlen($charapp) > self::MAX_CHARS) {
            throw new \InvalidArgumentException('charapp is empty or too long');
        }
        $hosts = self::hosts($opts);
        $entries = self::split($charapp);
        // a single url that is not an asset or bodycolors is an endpoint that returns the list (like CharacterFetch.ashx)
        if (
            count($entries) === 1 && self::kind($entries[0]) === 'other' && preg_match('#^https?://#i', $entries[0]) && !preg_match(
                '#^https?://[^/]+/?$#i',
                $entries[0]
            )
        ) {
            self::checkHost($entries[0], $hosts);
            $body = self::get($entries[0], $opts);
            if ($body === null) {
                throw new \InvalidArgumentException('could not fetch the charapp url (' . (Net::$error ?: 'no reply') . ')');
            }
            $entries = self::split($body);
        }
        if (count($entries) > self::MAX_ENTRIES) {
            throw new \InvalidArgumentException('charapp has too many entries');
        }
        $ids = [];
        $colors = null;
        $fetchedColors = false;
        $notes = [];
        foreach ($entries as $e) {
            $k = self::kind($e);
            if ($k === 'other') {
                if (preg_match('#^https?://#i', $e)) {
                    self::checkHost($e, $hosts);
                }
                $notes[] = 'charapp entry ignored: ' . substr($e, 0, 80);
                continue;
            }
            self::checkHost($e, $hosts);
            if ($k === 'asset') {
                $id = Avatar::assetId($e);
                if ($id !== null && !in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
                continue;
            }
            // only the first bodycolors url is fetched
            if ($fetchedColors) {
                continue;
            }
            $fetchedColors = true;
            $xml = self::get($e, $opts);
            if ($xml === null) {
                $notes[] = 'bodycolors could not be fetched' . (Net::$error !== '' ? ' (' . Net::$error . ')' : '');
                continue;
            }
            $c = self::bodyColors($xml);
            if ($c !== null) {
                $colors = $c;
            } else {
                $notes[] = 'bodycolors had no BodyColors data';
            }
        }
        return ['ids' => array_slice($ids, 0, 1000), 'colors' => $colors, 'notes' => array_slice($notes, 0, 8), 'entries' => count($entries)];
    }

    private static function split(string $s): array {
        $out = [];
        foreach (preg_split('/[;\r\n]+/', $s) as $p) {
            $p = trim($p);
            if ($p !== '') {
                $out[] = $p;
            }
        }
        return $out;
    }

    private static function kind(string $e): string {
        if (preg_match('/bodycolors/i', $e)) {
            return 'bodycolors';
        }
        if (preg_match('/^\d{1,18}$/D', $e)) {
            return 'asset';
        }
        if (Avatar::assetId($e) !== null && preg_match('/asset/i', (string)parse_url($e, PHP_URL_PATH))) {
            return 'asset';
        }
        return 'other';
    }

    private static function hosts(array $opts): array {
        $h = [];
        foreach (($opts['allowedHosts'] ?? []) as $x) {
            $x = strtolower(trim((string)$x));
            if (preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $x)) {
                $h[] = $x;
            }
        }
        $b = parse_url((string)($opts['assetBase'] ?? ''), PHP_URL_HOST);
        if ($b) {
            $h[] = strtolower($b);
        }
        return array_values(array_unique($h));
    }

    private static function checkHost(string $url, array $hosts): void {
        if (preg_match('/^\d+$/D', $url)) {
            return;
        }
        $h = parse_url($url, PHP_URL_HOST);
        $sch = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if (!$h || ($sch !== 'http' && $sch !== 'https') || !in_array(strtolower($h), $hosts, true)) {
            throw new \InvalidArgumentException('charapp host is not allowed: ' . substr((string)$h, 0, 60) . ' (add it to allowedHosts)');
        }
    }

    private static function get(string $url, array $opts): ?string {
        if (isset($opts['get'])) {
            return ($opts['get'])($url);
        }
        $dir = $opts['cacheDir'] ?? null;
        $file = $dir ? $dir . '/charapp-' . hash('sha256', $url) : null;
        if ($file && is_file($file) && filemtime($file) > time() - 30) {
            return (string)file_get_contents($file);
        }
        $port = parse_url((string)($opts['assetBase'] ?? ''), PHP_URL_PORT);
        $buf = Net::get($url, [
            'maxBytes' => self::MAX_FETCH_BYTES,
            'hosts' => self::hosts($opts),
            'redirects' => 3,
            'allowPrivate' => !empty($opts['allowPrivateHosts']),
            'ports' => $port ? [(int)$port] : [],
            'timeout' => 15,
        ]);
        if ($buf === null) {
            return null;
        }
        if ($file) {
            @file_put_contents($file, $buf, LOCK_EX);
        }
        return $buf;
    }

    // the bodycolors xml from roblox: ints or brickcolors named HeadColor and so on and color3 versions named HeadColor3
    static function bodyColors(string $xml): ?array {
        try {
            $roots = Rbxm::parse($xml)["roots"];
        } catch (\Throwable $e) {
            return null;
        }
        $find = function ($list) use (&$find) {
            foreach ($list as $i) {
                if ($i->cls === 'BodyColors') {
                    return $i;
                }
                if ($r = $find($i->children)) {
                    return $r;
                }
            }
            return null;
        };
        $bc = $find($roots);
        if (!$bc) {
            return null;
        }
        $out = [null, null, null, null, null, null];
        foreach (self::PARTS as $name => $j) {
            $v = $bc->props[$name] ?? null;
            $v3 = $bc->props[$name . '3'] ?? null;
            if (is_array($v3) && isset($v3['r'])) {
                $out[$j] = sprintf(
                    '#%02x%02x%02x',
                    round($v3['r'] * 255),
                    round($v3['g'] * 255),
                    round($v3['b'] * 255)
                );
            } elseif (is_array($v) && isset($v['brick'])) {
                $out[$j] = (int)$v['brick'];
            } elseif (is_int($v) || is_float($v)) {
                $out[$j] = (int)$v;
            }
        }
        $d = Avatar::DEFAULT_COLORS;
        foreach ($out as $j => $c) {
            if ($c === null || (is_int($c) && !BrickColors::has($c))) {
                $out[$j] = $d[$j];
            }
        }
        return $out;
    }

}
