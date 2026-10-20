<?php
// get avatar.php with charapp or ids and colors then size view smooth sid and nothing else is accepted
declare(strict_types=1);
require __DIR__ . '/src/Vanilla.php';
$config = require __DIR__ . '/config.php';

const RENDER_VERSION = 'vanilla-1.0';

function fail(int $code, string $msg): void {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $msg, "\n";
    exit;
}

header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'");
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'HEAD') {
    fail(405, 'Only GET.');
}

$q = $_GET;
foreach ($q as $k => $v) {
    if (!is_string($v)) {
        fail(400, 'Bad parameter: ' . substr((string)$k, 0, 20));
    }
}
// every input is a plain string checked against a strict pattern
$idsRaw = trim($q['ids'] ?? '');
$ids = $idsRaw === '' ? [] : preg_split('/\s*,\s*/', $idsRaw);
foreach ($ids as $id) {
    if (!preg_match('/^[1-9]\d{0,17}$/D', $id)) {
        fail(400, 'Asset IDs are numbers only.');
    }
}
if (count($ids) > Vanilla\Avatar::MAX_IDS) {
    fail(400, 'At most ' . Vanilla\Avatar::MAX_IDS . ' asset IDs.');
}
$ids = array_values(array_unique($ids));
$colors = null;
if (isset($q['colors']) && $q['colors'] !== '') {
    $colors = preg_split('/\s*,\s*/', strtolower(trim($q['colors'])));
    if (count($colors) !== 6) {
        fail(400, 'Six colours: head, torso, left arm, right arm, left leg, right leg.');
    }
    foreach ($colors as &$c) {
        $c = ltrim($c, '#');
        if (!preg_match('/^[0-9a-f]{6}$/D', $c)) {
            fail(400, 'Colours are written rrggbb.');
        }
        $c = '#' . $c;
    }
    unset($c);
}
if (isset($q['charapp']) && $q['charapp'] !== '') {
    if ($idsRaw !== '') {
        fail(400, 'Use charapp or ids, not both.');
    }
    $given = $colors;
    $cd = $config['cacheDir'];
    if (!is_dir($cd)) {
        @mkdir($cd, 0750, true);
    }
    try {
        $ca = Vanilla\CharApp::resolve(
            $q['charapp'],
            ['assetBase' => $config['assetBase'], 'allowedHosts' => $config['allowedHosts'] ?? [], 'cacheDir' => $cd]
        );
    } catch (InvalidArgumentException $e) {
        fail(400, $e->getMessage());
    }
    $ids = $ca['ids'];
    $colors = $given ?? $ca['colors'];
    if (count($ids) > Vanilla\Avatar::MAX_IDS) {
        fail(400, 'At most ' . Vanilla\Avatar::MAX_IDS . ' assets in a charapp.');
    }
}
if (isset($q['format']) && strtolower($q['format']) !== 'png') {
    fail(400, 'format is png.');
}
$size = $q['size'] ?? (isset($q['x'], $q['y']) ? $q['x'] . 'x' . $q['y'] : '150x200');
if (!isset(Vanilla\Avatar::SIZES[$size])) {
    fail(400, 'Size is one of: ' . implode(', ', array_keys(Vanilla\Avatar::SIZES)) . '.');
}

$smooth = $q['smooth'] ?? '1';
if ($smooth !== '1' && $smooth !== '0') {
    fail(400, 'smooth is 1 or 0.');
}
$smooth = $smooth === '1';
$view = $q['view'] ?? 'body';
if ($view !== 'body' && $view !== 'head') {
    fail(400, 'view is body or head.');
}
$sid = $q['sid'] ?? '';
if ($sid !== '' && !preg_match('/^[0-9a-f]{16,32}$/D', $sid)) {
    fail(400, 'sid is 16-32 hex characters.');
}

$cacheDir = $config['cacheDir'];
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0750, true);
}
// a finished render for exactly this request is reused
$key = hash(
    'sha256',
    RENDER_VERSION . '|' . implode(',', $ids) . '|' . implode(
        ',',
        $colors ?? Vanilla\Avatar::DEFAULT_COLORS
    ) . '|' . $size . '|' . ($smooth ? 's' : 'h') . '|' . $view
);
$file = "$cacheDir/render-$key.png";
$etag = '"' . substr($key, 0, 32) . '"';
$maxAge = (int)$config['cacheSeconds'];

// a new render for a sid deletes that sid previous render from the server
function keepOnly(string $dir, string $sid, string $file, int $maxAge, int $maxBytes): void {
    if ($sid !== '') {
        $ptr = "$dir/sid-$sid";
        $prev = is_file($ptr) ? trim((string)@file_get_contents($ptr)) : '';
        if ($prev !== '' && $prev !== basename($file) && preg_match('/^render-[0-9a-f]{64}\.png$/D', $prev)) {
            @unlink("$dir/$prev");
        }
        @file_put_contents($ptr, basename($file), LOCK_EX);
    }
    if (mt_rand(1, 20) !== 1) {
        return;
    }
    $now = time();
    $keep = [];
    $total = 0;
    foreach (glob("$dir/*") ?: [] as $f) {
        if (!is_file($f)) {
            continue;
        }
        $b = basename($f);
        $age = $now - (int)filemtime($f);
        if (substr($b, -4) === '.tmp') {
            if ($age > 3600) {
                @unlink($f);
            }
            continue;
        }
        if (preg_match('/^(render-[0-9a-f]{64}\.png|sid-[0-9a-f]+)$/D', $b)) {
            if ($age > $maxAge) {
                @unlink($f);
            } elseif ($b[0] !== 's') {
                $keep[$f] = filemtime($f);
                $total += filesize($f);
            }
            continue;
        }
        if (preg_match('/^(asset|img)-/', $b)) {
            if ($age > 86400) {
                @unlink($f);
            } else {
                $keep[$f] = filemtime($f);
                $total += filesize($f);
            }
        }
    }
    if ($total > $maxBytes) {
        asort($keep);
        foreach ($keep as $f => $t) {
            if ($total <= $maxBytes) {
                break;
            }
            $total -= (int)@filesize($f);
            @unlink($f);
        }
    }
}

$maxBytes = (int)($config['cacheMaxMB'] ?? 200) * 1048576;

function send(string $png, string $etag, int $maxAge, array $notes = []): void {
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=' . $maxAge);
    header('ETag: ' . $etag);
    if ($notes) {
        header('X-Vanilla-Notes: ' . substr(preg_replace('/[^\x20-\x7e]/', '?', implode(' | ', $notes)), 0, 900));
    }
    header('Content-Length: ' . strlen($png));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
        echo $png;
    }
    exit;
}

if (trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag && is_file($file) && filemtime($file) > time() - $maxAge) {
    keepOnly($cacheDir, $sid, $file, $maxAge, $maxBytes);
    http_response_code(304);
    exit;
}
if (is_file($file) && filemtime($file) > time() - $maxAge) {
    keepOnly($cacheDir, $sid, $file, $maxAge, $maxBytes);
    send((string)file_get_contents($file), $etag, $maxAge);
}

// only a few renders run at a time
@set_time_limit(90);
@ini_set('memory_limit', '256M');
$slot = null;
for ($t = 0; $t < 300 && !$slot; $t++) {
    for ($i = 0; $i < max(1, (int)$config['maxConcurrent']); $i++) {
        $h = fopen("$cacheDir/slot-$i.lock", 'c');
        if ($h && flock($h, LOCK_EX | LOCK_NB)) {
            $slot = $h;
            break;
        }
        if ($h) {
            fclose($h);
        }
    }
    if (!$slot) {
        usleep(200000);
    }
}
if (!$slot) {
    fail(503, 'Busy, try again shortly.');
}
if (is_file($file) && filemtime($file) > time() - $maxAge) {
    keepOnly($cacheDir, $sid, $file, $maxAge, $maxBytes);
    send((string)file_get_contents($file), $etag, $maxAge);
}

try {
    $avatar = new Vanilla\Avatar(['assetBase' => $config['assetBase'], 'cacheDir' => $cacheDir]);
    $r = $avatar->render(['ids' => $ids, 'colors' => $colors, 'size' => $size, 'smooth' => $smooth, 'view' => $view]);
} catch (InvalidArgumentException $e) {
    fail(400, $e->getMessage());
} catch (Throwable $e) {
    error_log('avatar.php: ' . $e->getMessage());
    fail(500, 'The render failed.');
}
$tmp = $file . '.' . getmypid() . '.tmp';
if (@file_put_contents($tmp, $r['png']) !== false) {
    @rename($tmp, $file);
}
flock($slot, LOCK_UN);
fclose($slot);
keepOnly($cacheDir, $sid, $file, $maxAge, $maxBytes);
send($r['png'], $etag, $maxAge, $r['notes']);
