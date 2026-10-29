<?php
require dirname(__DIR__) . '/src/Vanilla.php';
use Vanilla\Avatar;
$checks = 0;
function lightingCheck($condition, string $message): void {
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    $checks++;
}
function lightingCall(Avatar $avatar, string $name, array $args = []) {
    $method = new ReflectionMethod(Avatar::class, $name); $method->setAccessible(true);
    return $method->invokeArgs($avatar, $args);
}
$a = new Avatar(['renderer' => '2016']);
$property = new ReflectionProperty(Avatar::class, 'lighting'); $property->setAccessible(true);
$lighting = $property->getValue($a); $L = $lighting['direction'];
lightingCheck($L[0] > 0 && $L[1] > 0 && $L[2] < 0, 'Light is in normalized avatar coordinates');
$angle = 14 / 24 * 2 * M_PI;
$tilt = (-23.5 * cos(M_PI * (14 / 24 - 182.6282) / 182.6282) - 41.7333) * M_PI / 180;
$worldLight = Avatar::norm([sin($angle) * cos($tilt), -cos($angle) * cos($tilt), -sin($tilt)]);
$rootRotation = [-1, 0, 0, 0, 1, 0, 0, 0, -1];
foreach ([[0, 0, -1], [1, 0, 0], [0, 1, 0]] as $normal) {
    $worldNormal = Avatar::rmul($rootRotation, $normal);
    lightingCheck(abs(Avatar::dot($normal, $L) - Avatar::dot($worldNormal, $worldLight)) < 1e-12, 'Diffuse term is invariant under root normalization');
}
$frontDiffuse = $lighting['ambient'] + $lighting['key'] * -$L[2];
lightingCheck($frontDiffuse > 0.70 && $frontDiffuse < 0.72, 'Front receives main light rather than weaker fill');
$T = ['lightingOffset' => 2, 'specPower' => 9];
$P = [[0, 0, 1], [0, 0, 0], [0, 0, 0], [0, 0, 0], [0, 0, -1], [0.3, 0, -$L[0]], [0, 0.3, -$L[1]], [0, 0, $L[2]]];
$center = lightingCall($a, 'specular2016', [$T, $P, 0, 0, 1, 1]);
$left = lightingCall($a, 'specular2016', [$T, $P, -1, 0, 1, 1]);
$right = lightingCall($a, 'specular2016', [$T, $P, 1, 0, 1, 1]);
lightingCheck(abs($center - 1) < 1e-12, 'HQ highlight reaches mirror peak at interior fragment');
lightingCheck($center > ($left + $right) / 2 + 0.1, 'Interior highlight is nonlinear, not interpolated vertex brightness');
lightingCheck(lightingCall($a, 'specular2016', [$T, $P, 0, 0, 1, 0]) === 0.0, 'Unlit specular gate is respected');
$r = $a->render(['size' => '150x150', 'smooth' => false]);
$im = imagecreatefromstring($r['png']); $matches = 0;
for ($y = 75; $y < 150; $y++) { for ($x = 0; $x < 150; $x++) {
    if ((imagecolorat($im, $x, $y) & 0xFFFFFF) === 0x758733) { $matches++; }
} }
imagedestroy($im);
lightingCheck($matches > 100, 'Leg-front plateau matches measured reference color');
foreach (['2012', '2016'] as $mode) {
    $fast = (new Avatar)->render(['renderer' => $mode, 'size' => '100x100', 'smooth' => false])['png'];
    $exact = (new Avatar(['exact' => true]))->render(['renderer' => $mode, 'size' => '100x100', 'smooth' => false])['png'];
    if ($mode === '2012') {
        lightingCheck($fast === $exact, '2012 hard-edge output agrees across rasterizers');
    } else {
        $fastImage = imagecreatefromstring($fast); $exactImage = imagecreatefromstring($exact); $samePlateau = 0;
        for ($y = 50; $y < 100; $y++) { for ($x = 0; $x < 100; $x++) {
            if ((imagecolorat($fastImage, $x, $y) & 0xFFFFFF) === 0x758733 && (imagecolorat($exactImage, $x, $y) & 0xFFFFFF) === 0x758733) { $samePlateau++; }
        } }
        imagedestroy($fastImage); imagedestroy($exactImage);
        lightingCheck($samePlateau > 100, '2016 interior lighting agrees across rasterizers');
    }
}
echo "Passed $checks lighting checks.\n";
