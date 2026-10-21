<?php
// get avatar.php with charapp or ids or item, colors, size, view, smooth, renderer, format, bg and sid
declare(strict_types=1);
umask(0077);
require __DIR__ . '/src/Vanilla.php';
$config = require __DIR__ . '/config.php';

const RENDER_VERSION = 'vanilla-1.5-items';

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
$item = null;
if (isset($q['item']) && $q['item'] !== '') {
    if (!preg_match('/^[1-9]\d{0,17}$/D', $q['item'])) {
        fail(400, 'item is a number.');
    }
    if (($q['ids'] ?? '') !== '' || ($q['charapp'] ?? '') !== '') {
        fail(400, 'Use item on its own.');
    }
    $item = $q['item'];
}
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
$caNotes = [];
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
        @mkdir($cd, 0700, true);
    }
    try {
        $ca = Vanilla\CharApp::resolve(
            $q['charapp'],
            ['assetBase' => $config['assetBase'], 'allowedHosts' => $config['allowedHosts'] ?? [], 'allowPrivateHosts' => !empty($config['allowPrivateHosts']), 'cacheDir' => $cd]
        );
    } catch (InvalidArgumentException $e) {
        fail(400, $e->getMessage());
    }
    $ids = $ca['ids'];
    $colors = $given ?? $ca['colors'];
    $caNotes = $ca['notes'];
    if (!$ids && $ca['colors'] === null) {
        fail(400, 'The charapp had nothing vanilla could use (' . $ca['entries'] . ' entries). ' . implode('; ', $ca['notes']));
    }
    if (count($ids) > Vanilla\Avatar::MAX_IDS) {
        fail(400, 'At most ' . Vanilla\Avatar::MAX_IDS . ' assets in a charapp.');
    }
}
$format = strtolower($q['format'] ?? $config['format'] ?? 'png');
$format = $format === 'jpeg' ? 'jpg' : $format;
if (!isset(Vanilla\Img::FORMATS[$format])) {
    fail(400, 'format is one of: ' . implode(', ', array_keys(Vanilla\Img::FORMATS)) . '.');
}
$bg = $q['bg'] ?? 'ffffff';
if (!preg_match('/^#?[0-9a-fA-F]{6}$/D', $bg)) {
    fail(400, 'bg is a hex colour like ffffff.');
}
$bg = (int)hexdec(ltrim($bg, '#'));
$size = $q['size'] ?? (isset($q['x'], $q['y']) ? $q['x'] . 'x' . $q['y'] : '150x200');
if (!isset(Vanilla\Avatar::SIZES[$size])) {
    fail(400, 'Size is one of: ' . implode(', ', array_keys(Vanilla\Avatar::SIZES)) . '.');
}

try {
    $renderer = Vanilla\Avatar::validateRenderer($q['renderer'] ?? $config['renderer'] ?? '2012');
} catch (InvalidArgumentException $e) {
    fail(400, $e->getMessage());
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
    @mkdir($cacheDir, 0700, true);
}
// a finished render for exactly this request is reused
$key = hash(
    'sha256',
    RENDER_VERSION . '|' . ($item !== null ? 'item' . $item : implode(',', $ids) . '|' . implode(
        ',',
        $colors ?? Vanilla\Avatar::DEFAULT_COLORS
    ) . '|' . $view) . '|' . $size . '|' . ($smooth ? 's' : 'h') . '|' . $renderer . '|' . $format . ($format === 'jpg' ? '|' . $bg : '')
);
$file = "$cacheDir/render-$key.$format";
$etag = '"' . substr($key, 0, 32) . '"';
$maxAge = (int)$config['cacheSeconds'];

// a new render for a sid deletes that sid previous render from the server
function keepOnly(string $dir, string $sid, string $file, int $maxAge, int $maxBytes): void {
    if ($sid !== '') {
        $ptr = "$dir/sid-$sid";
        $prev = is_file($ptr) ? trim((string)@file_get_contents($ptr)) : '';
        if ($prev !== '' && $prev !== basename($file) && preg_match('/^render-[0-9a-f]{64}\.(png|webp|jpg)$/D', $prev)) {
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
    $sids = [];
    // only our own file names are ever touched and links are skipped
    foreach (@scandir($dir) ?: [] as $b) {
        if (!preg_match('/^(render-[0-9a-f]{64}\.(png|webp|jpg)|sid-[0-9a-f]{16,32}|asset-\d{1,18}|img-\d{1,18}h?\.z|charapp-[0-9a-f]{64}|content-[0-9a-f]{64}|.+\.\d+\.tmp)$/D', $b)) {
            continue;
        }
        $f = "$dir/$b";
        if (is_link($f) || !is_file($f)) {
            continue;
        }
        $mt = (int)filemtime($f);
        $age = $now - $mt;
        if (substr($b, -4) === '.tmp') {
            if ($age > 3600) {
                @unlink($f);
            }
        } elseif ($b[0] === 'c') {
            if ($age > 300) {
                @unlink($f);
            }
        } elseif ($b[0] === 's') {
            if ($age > $maxAge) {
                @unlink($f);
            } else {
                $sids[$f] = $mt;
            }
        } elseif ($age > ($b[0] === 'r' ? $maxAge : 86400)) {
            @unlink($f);
        } else {
            $keep[$f] = $mt;
            $total += (int)filesize($f);
        }
    }
    if (count($sids) > 20000) {
        asort($sids);
        foreach (array_slice(array_keys($sids), 0, count($sids) - 20000) as $f) {
            @unlink($f);
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
    global $format;
    header('Content-Type: ' . Vanilla\Img::FORMATS[$format]);
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
    $avatar = new Vanilla\Avatar([
        'assetBase' => $config['assetBase'],
        'allowedHosts' => $config['allowedHosts'] ?? [],
        'allowPrivateHosts' => !empty($config['allowPrivateHosts']),
        'cacheDir' => $cacheDir,
    ]);
    $r = $avatar->render($item !== null
        ? ['item' => $item, 'size' => $size, 'smooth' => $smooth, 'renderer' => $renderer]
        : ['ids' => $ids, 'colors' => $colors, 'size' => $size, 'smooth' => $smooth, 'view' => $view, 'renderer' => $renderer]);
} catch (InvalidArgumentException $e) {
    fail(400, $e->getMessage());
} catch (Throwable $e) {
    error_log('avatar.php: ' . $e->getMessage());
    fail(500, 'The render failed.');
}
$out = Vanilla\Img::encode($r['png'], $format, $bg);
if ($out === null) {
    flock($slot, LOCK_UN);
    fclose($slot);
    fail(501, 'This server cannot make ' . $format . '.');
}
$tmp = $file . '.' . getmypid() . '.tmp';
if (@file_put_contents($tmp, $out) !== false) {
    @rename($tmp, $file);
}
flock($slot, LOCK_UN);
fclose($slot);
keepOnly($cacheDir, $sid, $file, $maxAge, $maxBytes);
send($out, $etag, $maxAge, array_merge($caNotes, $r['notes']));
