<?php
require dirname(__DIR__) . '/src/Vanilla.php';
use Vanilla\Avatar;
use Vanilla\Img;
use Vanilla\Inst;

set_error_handler(function ($level, $message, $file, $line) { throw new ErrorException($message, 0, $level, $file, $line); });
$count = 0;
function verifyGeometry($ok, string $message): void {
    global $count;
    if (!$ok) { throw new RuntimeException($message); }
    $count++;
}
function geometryCall(Avatar $avatar, string $name, array $args = []) {
    $m = new ReflectionMethod(Avatar::class, $name); $m->setAccessible(true);
    return $m->invokeArgs($avatar, $args);
}
$a = new Avatar(['renderer' => '2016']);
$look = geometryCall($a, 'emptyLook');
$base = geometryCall($a, 'base');
$native = geometryCall($a, 'characterBase2016', [$base, &$look]);
verifyGeometry(hash_file('sha256', dirname(__DIR__) . '/data/2016/face.png') === '7667dddefcd28e4688dd384e28efb03209745febcf99ee91619111536694d1fe', 'Default face matches original 2016 client bytes');
verifyGeometry($native['face']->px !== $base['face']->px, '2016 uses its own default face');
$faceXML = '<roblox version="4"><Item class="Decal"><Properties><Content name="Texture"><url>rbxassetid://2</url></Content></Properties></Item></roblox>';
$customFace = file_get_contents(dirname(__DIR__) . '/data/face.png');
$faceAvatar = new Avatar(['renderer' => '2016', 'fetcher' => function ($id) use ($faceXML, $customFace) { return $id === '1' ? $faceXML : ($id === '2' ? $customFace : null); }]);
verifyGeometry($faceAvatar->render(['ids' => ['1'], 'size' => '48x48'])['png'] !== $a->render(['size' => '48x48'])['png'], 'Equipped face overrides the 2016 fallback');
$directory = dirname(__DIR__) . '/data/2016/character';
$manifest = json_decode(file_get_contents($directory . '/source-sha256.json'), true);
foreach ($manifest as $name => $hash) { verifyGeometry(hash_file('sha256', "$directory/$name") === $hash, 'Bundled original mesh integrity: ' . $name); }
verifyGeometry(count($native['head']['pos']) === 2538, 'Original head triangle count');
verifyGeometry(count($native['torso']['pos']) === 132, 'Original torso triangle count');
verifyGeometry($native['torso']['pos'] === $base['torso']['pos'], 'Default torso geometry already matches original source');
verifyGeometry($native['head']['uv'] !== $base['head']['uv'], 'Binary head UVs follow 2016 loader');
$binary = "version 2.00\n" . pack('vCCVV', 12, 36, 12, 3, 1);
foreach ([[0, 0, 0], [1, 0, 0], [0, 1, 0]] as $v) {
    $binary .= pack('g*', $v[0], $v[1], $v[2], 0, 0, 1, 0.25, 0.75) . pack('V', 0);
}
$binary .= pack('V3', 0, 1, 2);
$v2 = geometryCall($a, 'parseMesh2016', [$binary]);
verifyGeometry($v2['uv'][0] === [0.25, 0.75], 'V2 UVs stay as stored');
$text = "version 1.01\n1\n" . str_repeat('[0,0,0][0,0,1][0.25,0.75,0]', 3);
$v1 = geometryCall($a, 'parseMesh2016', [$text]);
verifyGeometry($v1['uv'][0] === [0.25, 0.25], 'V1 UVs retain the source V flip');
$frames = geometryCall($a, 'partCFs', [false]);
$bounds = geometryCall($a, 'sceneBounds2016', [$native, $frames, [], true]);
verifyGeometry($bounds == ['lo' => [-2, 0, -0.5], 'hi' => [2, 5, 0.5]], 'Camera uses physical R6 part bounds');
$camera = geometryCall($a, 'thumbnailCamera2016', [$bounds, 512, 512]);
verifyGeometry($camera['center'] == [0, 2.5, 0] && $camera['distance'] == 5, 'Uncropped thumbnail focus and distance');
$dir = [-sin(M_PI / 4) * sin(35 * M_PI / 180), cos(M_PI / 4) * sin(35 * M_PI / 180), -cos(35 * M_PI / 180)];
for ($k = 0; $k < 3; $k++) { verifyGeometry(abs($camera['eye'][$k] - $camera['center'][$k] - $dir[$k] * 5) < 1e-12, 'Source camera direction'); }
$renderBounds = geometryCall($a, 'sceneBounds2016', [$native, $frames, []]);
verifyGeometry($renderBounds['hi'][1] > $bounds['hi'][1], 'Rendered mesh bounds are distinct from physical camera bounds');
$cropped = geometryCall($a, 'thumbnailCamera2016', [$renderBounds, 512, 512, true, $bounds]);
verifyGeometry($camera['vp'] !== $cropped['vp'], 'Optional source crop is distinct from default AvatarThumbScript');
$look['colors'] = array_fill_keys(array_keys($look['colors']), [0.5, 0.5, 0.5]);
$face = new Img(16, 16);
[$textures] = geometryCall($a, 'characterTextures2016', [$look, $face]);
foreach (['torso', 'leftArm', 'rightArm', 'leftLeg', 'rightLeg'] as $key) {
    $mesh = $native[$key]; $uv = [0, 0]; $n = 0;
    foreach ($mesh['nrm'] as $i => $normal) {
        if ($normal[2] < -0.999) { $uv[0] += $mesh['uv'][$i][0]; $uv[1] += $mesh['uv'][$i][1]; $n++; }
    }
    $c = $textures[$key]->sample($uv[0] / $n, $uv[1] / $n);
    verifyGeometry(abs($c[0] - 128 / 255) < 0.01 && abs($c[1] - $c[0]) < 0.001 && abs($c[2] - $c[0]) < 0.001, "$key native atlas gets the plain body color");
}
$template = new Img(585, 559);
foreach (Avatar::TEMPLATE as $part => $faces) {
    foreach ($faces as $faceName => $r) {
        $rgb = ['F' => [230, 20, 40], 'B' => [20, 40, 230], 'U' => [180, 20, 180], 'D' => [20, 180, 180], 'L' => [180, 160, 20], 'R' => [20, 180, 60]][$faceName];
        $template->fillRect($r[0] - 1, $r[1] - 1, $r[2] + 2, $r[3] + 2, $rgb);
    }
}
$look['shirt'] = $look['pants'] = $template;
[$textures] = geometryCall($a, 'characterTextures2016', [$look, $face]);
foreach (['torso', 'leftArm', 'rightArm', 'leftLeg', 'rightLeg'] as $key) {
    $mesh = $native[$key]; $uv = [0, 0]; $n = 0;
    foreach ($mesh['nrm'] as $i => $normal) {
        if ($normal[2] < -0.999) { $uv[0] += $mesh['uv'][$i][0]; $uv[1] += $mesh['uv'][$i][1]; $n++; }
    }
    $c = $textures[$key]->sample($uv[0] / $n, $uv[1] / $n);
    verifyGeometry($c[0] > 0.8 && $c[1] < 0.1 && $c[2] < 0.2, "$key front samples the shirt/pants front through native UVs");
}
$xml = '<roblox version="4"><Item class="CharacterMesh"><Properties><token name="BodyPart">1</token><int name="MeshId">2</int></Properties></Item></roblox>';
$replacement = file_get_contents($directory . '/leftarm.mesh');
$b = new Avatar(['renderer' => '2016', 'fetcher' => function ($id) use ($xml, $replacement) { return $id === '1' ? $xml : ($id === '2' ? $replacement : null); }]);
$r = $b->render(['ids' => ['1'], 'size' => '48x48']);
verifyGeometry(!$r['notes'], 'R6 CharacterMesh loads without missing mesh diagnostics');
verifyGeometry($r['png'] !== $a->render(['size' => '48x48'])['png'], 'R6 CharacterMesh changes rendered geometry');
$shape = new Inst('CylinderMesh', 'head'); $shape->props = ['Bevel' => 0.4, 'Roundness' => 0, 'Bulge' => 0];
$look['headMesh'] = $shape;
$head = geometryCall($a, 'headGeometry2016', [&$look]);
verifyGeometry($head === geometryCall($a, 'characterMesh2016', ['headD']), 'Legacy head selects the original baked headD variant');
$look['headMesh'] = new Inst('SpecialMesh', 'head');
$look['headMesh']->props = ['MeshType' => 0, 'Scale' => ['x' => 1.25, 'y' => 1.25, 'z' => 1.25]];
verifyGeometry(geometryCall($a, 'headGeometry2016', [&$look]) === $native['head'], 'Default SpecialMesh scale reproduces the original head');
echo "Passed $count geometry checks.\n";
