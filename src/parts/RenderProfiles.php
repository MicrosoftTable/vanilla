<?php
namespace Vanilla;

// Source notes and the limits of the software profile are in docs/2016-renderer.md.
trait RenderProfiles {
    const RENDERERS = ['2012', '2016'];
    private $renderer = '2012';
    private $defaultRenderer = '2012';
    private $lighting = [];

    public static function validateRenderer($renderer): string {
        if (!is_string($renderer) || !in_array($renderer, self::RENDERERS, true)) {
            throw new \InvalidArgumentException('renderer is 2012 or 2016.');
        }
        return $renderer;
    }

    private function selectRenderer($renderer): void {
        $this->renderer = self::validateRenderer($renderer);
        if ($this->renderer === '2012') {
            $this->lighting = ['ambient' => self::AMBIENT, 'key' => self::KEY, 'fill' => self::FILL, 'direction' => self::LAMP_DIR];
            return;
        }
        // Lighting.cpp defaults: 14:00, latitude 41.7333, Ambient .5, Brightness 1.
        // G3D LightingParameters::setTime, at day zero, then RenderView::presetLighting.
        $angle = 14 / 24 * 2 * M_PI;
        $day = 14 / 24;
        $tilt = (-23.5 * cos(M_PI * ($day - 182.6282) / 182.6282) - 41.7333) * M_PI / 180;
        // character3.rbxm faces world +Z (root yaw 180 degrees). Vanilla normalizes
        // the character and camera to face -Z, so the world light must rotate too.
        $this->lighting = [
            'ambient' => 0.5, 'key' => 0.75 * 0.9, 'fill' => 0.75 * 0.9 * 0.4,
            'direction' => self::norm([-sin($angle) * cos($tilt), -cos($angle) * cos($tilt), sin($tilt)])
        ];
    }

    // default.hlsl DefaultHQFS: normalize the interpolated normal and view vector,
    // then evaluate the Blinn highlight per fragment, not at triangle vertices.
    private function specular2016(array $T, array $P, float $x, float $y, float $w, float $gate): float {
        if ($gate <= 0) { return 0.0; }
        $o = $T['lightingOffset'];
        $v = [];
        for ($i = 0; $i < 6; $i++) {
            $plane = $P[$o + $i];
            $v[] = ($plane[0] * $x + $plane[1] * $y + $plane[2]) * $w;
        }
        $normal = self::norm([$v[0], $v[1], $v[2]]);
        $view = self::norm([$v[3], $v[4], $v[5]]);
        $lamp = $this->lighting['direction'];
        $half = self::norm([$lamp[0] + $view[0], $lamp[1] + $view[1], $lamp[2] + $view[2]]);
        return $gate * pow(max(0.0, min(1.0, self::dot($normal, $half))), $T['specPower']);
    }

    private function materialSpecular($material): array {
        if ($this->renderer === '2012') {
            return [self::KS, self::SPEC_EXP];
        }
        // MaterialGenerator::getSpecular: byte intensity, exponent. No normal maps here.
        $table = [256 => [102, 9], 272 => [191, 81], 288 => [191, 81],
            512 => [64, 32], 528 => [71, 53], 784 => [179, 54], 800 => [36, 20],
            816 => [38, 22], 832 => [48, 24], 848 => [33, 44], 864 => [18, 22],
            880 => [54, 22], 1040 => [89, 103], 1056 => [230, 160],
            1072 => [238, 240], 1088 => [204, 120], 1280 => [43, 18],
            1296 => [18, 6], 1312 => [8, 16], 1536 => [255, 190]];
        $names = ['Plastic' => 256, 'SmoothPlastic' => 272, 'Neon' => 288, 'Wood' => 512,
            'WoodPlanks' => 528, 'Marble' => 784, 'Slate' => 800, 'Concrete' => 816,
            'Granite' => 832, 'Brick' => 848, 'Pebble' => 864, 'Cobblestone' => 880,
            'CorrodedMetal' => 1040, 'DiamondPlate' => 1056, 'Foil' => 1072, 'Metal' => 1088,
            'Grass' => 1280, 'Sand' => 1296, 'Fabric' => 1312, 'Ice' => 1536];
        $v = $table[$names[$material] ?? $material] ?? $table[256];
        return [$v[0] / 255, $v[1]];
    }

    private function accessoryFrame(Inst $acc, Inst $h, array $cfs): array {
        if ($this->renderer !== '2016') {
            return self::accessoryCF($acc, $h, $cfs);
        }
        // Accoutrement::buildWeld: only the first handle Attachment is considered.
        // Names match exactly; unknown names fall back to legacy AttachmentPoint.
        $names = ['HatAttachment' => ['Head', 'hat'], 'HairAttachment' => ['Head', 'hair'],
            'FaceFrontAttachment' => ['Head', 'facefront'], 'FaceCenterAttachment' => ['Head', 'facecenter'],
            'NeckAttachment' => ['Torso', 'neck'], 'BodyFrontAttachment' => ['Torso', 'bodyfront'],
            'BodyBackAttachment' => ['Torso', 'bodyback'], 'LeftCollarAttachment' => ['Torso', 'leftcollar'],
            'RightCollarAttachment' => ['Torso', 'rightcollar'], 'WaistFrontAttachment' => ['Torso', 'waistfront'],
            'WaistCenterAttachment' => ['Torso', 'waistcenter'], 'WaistBackAttachment' => ['Torso', 'waistback'],
            'LeftShoulderAttachment' => ['Left Arm', 'leftshoulder'], 'RightShoulderAttachment' => ['Right Arm', 'rightshoulder'],
            'LeftGripAttachment' => ['Left Arm', 'leftgrip'], 'RightGripAttachment' => ['Right Arm', 'rightgrip'],
            'LeftFootAttachment' => ['Left Leg', 'leftfoot'], 'RightFootAttachment' => ['Right Leg', 'rightfoot']];
        foreach ($h->children as $a) {
            if ($a->cls !== 'Attachment') {
                continue;
            }
            if (isset($names[$a->name])) {
                [$part, $key] = $names[$a->name];
                $body = self::cfMul($cfs[$part], self::CF(self::$BODY_ATT[$part][$key]));
                return self::cfMul($body, self::cfInv(self::cfFrom($a->props['CFrame'] ?? null)));
            }
            break;
        }
        return self::cfMul(self::cfMul($cfs['Head'], self::CF([0, 0.5, 0])), self::cfInv(self::cfFrom($acc->props['AttachmentPoint'] ?? null)));
    }
}
