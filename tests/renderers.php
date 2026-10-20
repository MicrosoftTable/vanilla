<?php
// Run: php tests/renderers.php. All inputs are local; no asset server is needed.
require dirname(__DIR__) . '/src/Vanilla.php';
use Vanilla\Avatar;
use Vanilla\Inst;

set_error_handler(function ($level, $message, $file, $line) {
    throw new ErrorException($message, 0, $level, $file, $line);
});
$checks = 0;
function check($condition, string $message): void {
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    $checks++;
}
function invoke(Avatar $avatar, string $name, ...$args) {
    $method = new ReflectionMethod(Avatar::class, $name);
    $method->setAccessible(true);
    return $method->invokeArgs($avatar, $args);
}
function pngCheck(string $png, int $w, int $h): void {
    $info = getimagesizefromstring($png);
    check($info && $info[0] === $w && $info[1] === $h && $info['mime'] === 'image/png', 'Valid PNG dimensions');
    $im = imagecreatefromstring($png);
    check(ord($png[25]) === 6, 'RGBA output preserves transparency');
    imagedestroy($im);
}
function hatFixture(string $effect, bool $enabled = true): string {
    return '<roblox version="4"><Item class="Hat"><Properties><string name="Name">TestHat</string></Properties>' .
        '<Item class="Part"><Properties><string name="Name">Handle</string><Vector3 name="size"><X>1</X><Y>1</Y><Z>1</Z></Vector3></Properties>' .
        '<Item class="' . $effect . '"><Properties><bool name="Enabled">' . ($enabled ? 'true' : 'false') . '</bool></Properties></Item></Item></Item></roblox>';
}

$a = new Avatar;
$default = $a->render(['size' => '48x48'])['png'];
$old = $a->render(['renderer' => '2012', 'size' => '48x48'])['png'];
$new = $a->render(['renderer' => '2016', 'size' => '48x48'])['png'];
check($old === $default, 'Default is 2012');
check($old !== $new, '2016 changes shading');
check($old === $a->render(['size' => '48x48'])['png'], 'Request mode does not leak to the next render');
check($new === (new Avatar(['renderer' => '2016']))->render(['size' => '48x48'])['png'], 'Constructor default');
foreach (['2012', '2016'] as $mode) {
    foreach (['head', 'body'] as $view) {
        foreach ([true, false] as $smooth) {
            $r = $a->render(['renderer' => $mode, 'view' => $view, 'smooth' => $smooth, 'size' => '48x48']);
            pngCheck($r['png'], 48, 48);
            check(!$r['notes'], 'No notes for a plain avatar');
        }
    }
}
foreach (['2015', '', 2016, [], null] as $bad) {
    try { Avatar::validateRenderer($bad); throw new RuntimeException('Bad renderer accepted'); }
    catch (InvalidArgumentException $e) { $checks++; }
}
try { new Avatar(['renderer' => '2015']); throw new RuntimeException('Bad constructor mode accepted'); }
catch (InvalidArgumentException $e) { $checks++; }

foreach (['Fire', 'Smoke', 'Sparkles'] as $effect) {
    $xml = hatFixture($effect);
    $fx = new Avatar(['fetcher' => function ($id) use ($xml) { return $xml; }]);
    foreach (['2012', '2016'] as $mode) {
        foreach ([true, false] as $smooth) {
            $req = ['renderer' => $mode, 'ids' => ['1'], 'size' => '100x100', 'smooth' => $smooth];
            $r = $fx->render($req);
            pngCheck($r['png'], 100, 100);
            check($r['png'] === $fx->render($req)['png'], "$effect snapshot deterministic");
            $offXML = hatFixture($effect, false);
            $off = new Avatar(['fetcher' => function ($id) use ($offXML) { return $offXML; }]);
            check($r['png'] !== $off->render($req)['png'], "$effect enabled changes the render");
        }
    }
    $inst = new Inst($effect, 'effect');
    $recipes = invoke($a, 'fxRecipes2016', $inst);
    check(count($recipes) === ($effect === 'Smoke' ? 1 : 2), "$effect emitter layers");
    $inst->props['Enabled'] = false;
    check(invoke($a, 'fxRecipes2016', $inst) === [], 'Disabled effect emits nothing');
}

invoke($a, 'selectRenderer', '2016');
check(invoke($a, 'materialSpecular', 256) === [102 / 255, 9], 'Source plastic specular');
check(invoke($a, 'materialSpecular', 'Metal') === [204 / 255, 120], 'Source metal specular');
$cfs = invoke($a, 'partCFs', false);
$hat = new Inst('Hat', 'hat');
$handle = new Inst('Part', 'handle');
$att = new Inst('Attachment', 'att');
$att->name = 'HatAttachment';
$att->props['CFrame'] = ['x' => 0.2, 'y' => 0.3, 'z' => -0.1, 'm' => Avatar::rotZ(0.5)];
$handle->children[] = $att;
$frame = invoke($a, 'accessoryFrame', $hat, $handle, $cfs);
$weld = Avatar::cfMul($frame, Avatar::cfFrom($att->props['CFrame']));
$target = Avatar::cfMul($cfs['Head'], Avatar::CF([0, 0.6, 0]));
foreach (['p', 'R'] as $key) {
    foreach ($target[$key] as $i => $v) { check(abs($weld[$key][$i] - $v) < 1e-9, 'Attachment weld equality'); }
}
$att->name = 'hatattachment';
check(invoke($a, 'accessoryFrame', $hat, $handle, $cfs) == Avatar::CF([0, 5, 0]), 'Case-sensitive attachment fallback');
$first = new Inst('Attachment', 'unknown');
$first->name = 'UnknownAttachment';
$att->name = 'HatAttachment';
array_unshift($handle->children, $first);
check(invoke($a, 'accessoryFrame', $hat, $handle, $cfs) == Avatar::CF([0, 5, 0]), 'Only first handle attachment is used');

// Exact raster and fast raster both exercise the shared profile and FX shader.
$xml = hatFixture('Fire');
$exact = new Avatar(['exact' => true, 'fetcher' => function ($id) use ($xml) { return $xml; }]);
foreach (['2012', '2016'] as $mode) {
    pngCheck($exact->render(['renderer' => $mode, 'ids' => ['1'], 'size' => '48x48'])['png'], 48, 48);
}
echo "Passed $checks checks.\n";
