<?php
namespace Vanilla;

// camera lighting and the draw calls for one look
trait Scene {
    /** The Camera named inside a hat model, expressed relative to the Handle, or null. */
    private static function thumbCamOf(Inst $acc, Inst $h): ?array {
        if (!isset($h->props['CFrame'])) {
            return null;
        }
        foreach ($acc->children as $c) {
            if ($c->cls !== 'Camera' || !isset($c->props['CFrame'])) {
                continue;
            }
            $inv = self::cfInv(self::cfFrom($h->props['CFrame']));
            $cam = self::cfMul($inv, self::cfFrom($c->props['CFrame']));
            $fp  = self::cfApply($inv, self::cfFrom($c->props['Focus'] ?? $c->props['CFrame'])['p']);
            if (self::dot(self::sub($cam['p'], $fp), self::sub($cam['p'], $fp)) < 1e-6) {
                return null;
            }
            $fov = (float)($c->props['FieldOfView'] ?? 70);
            return ['eye' => $cam['p'], 'up' => [$cam['R'][1], $cam['R'][4], $cam['R'][7]], 'focus' => $fp, 'fov' => min(90, max(15, $fov ?: 70))];
        }
        return null;
    }

    /** Swings a camera direction (a unit vector from the subject to the camera) by the caller's yaw and pitch. No override: unchanged. */
    private function turnCamera(array $d): array {
        if ($this->camYaw == 0.0 && $this->camPitch == 0.0) {
            return $d;
        }
        $az = atan2($d[0], $d[2]) + deg2rad($this->camYaw);
        $el = max(-deg2rad(85), min(deg2rad(85), atan2($d[1], sqrt($d[0] * $d[0] + $d[2] * $d[2])) + deg2rad($this->camPitch)));
        return [cos($el) * sin($az), sin($el), cos($el) * cos($az)];
    }

    /** Is this hat or gear see-through: a Handle with Transparency, or a texture whose alpha is meant to show (blended, not cut out). */
    private static function blendsItem(array $it): bool {
        if (($it['alpha'] ?? 1.0) < 0.999) {
            return true;
        }
        return !empty($it['tex']) && $it['tex']->hasAlpha && $it['tex']->cut;
    }

    /** Where a package's right hand is, in the arm's own space: a RightGripAttachment under its CharacterMesh, or null. */
    private static function packageGripOf(array $look): ?array {
        $cm = $look['bodyMeshes']['rightArm'] ?? null;
        if (!$cm) {
            return null;
        }
        foreach ($cm->children as $c) {
            if ($c->cls === 'Attachment' && strtolower((string)$c->name) === 'rightgripattachment') {
                $p = self::cfFrom($c->props['CFrame'] ?? ($c->props['CoordinateFrame'] ?? null))['p'];
                if (abs($p[0]) < 4 && abs($p[1]) < 4 && abs($p[2]) < 4) {
                    return $p;
                }
            }
        }
        return null;
    }

    /** The shoulder angle that points $grip (arm space) the same way a normal hand points when the arm is raised as $stdArm. */
    private static function raiseForGrip(array $stdArm, array $grip): float {
        $S = self::cfApply(self::cfMul(self::CF([0, 3, 0]), self::SHOULDER_R()), [0, 0, 0]);
        $dir = function (array $v) use ($S) {
            $d = [$v[0] - $S[0], $v[1] - $S[1], $v[2] - $S[2]];
            $l = sqrt($d[1] * $d[1] + $d[2] * $d[2]) ?: 1;
            return [$d[1] / $l, $d[2] / $l];   // the arm turns about x, so only y and z matter
        };
        $want = $dir(self::cfApply($stdArm, self::RIGHT_GRIP_C0()['p']));
        $best = M_PI / 2;
        $bestScore = INF;
        for ($i = -360; $i <= 360; $i++) {
            $a = $i * M_PI / 360;
            $h = $dir(self::cfApply(self::partCFs(true, $a)['Right Arm'], $grip));
            $score = ($h[0] - $want[0]) ** 2 + ($h[1] - $want[1]) ** 2;
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $a;
            }
        }
        return $best;
    }

    private function draw(array &$look, int $W, int $H, int $SS, string $view = 'body'): string {
        if ($view === 'head') {
            $look['tool'] = null;   // a headshot never shows gear: it is held in the hand, not worn on the head
        }
        $base = $this->base();
        $only = $look['only'];
        $partTex = [];
        $headTex = null;
        if ($this->renderer === '2016') {
            $base = $this->characterBase2016($base, $look);
        } else {
            [$base, $partTex, $headTex] = $this->characterBase2012($base, $look);
        }
        $cfs = self::partCFs((bool)$look['tool'], $this->renderer === '2016' ? 1.57 : M_PI / 2);
        // a package right arm can say where its hand is (a RightGripAttachment under its CharacterMesh). The arm is then raised
        // just far enough to put that hand straight out in front, and the gear goes in the hand, held the usual way up.
        $stdArm = $cfs['Right Arm'];
        $handGrip = $look['tool'] ? self::packageGripOf($look) : null;
        if ($handGrip) {
            $cfs = self::partCFs(true, self::raiseForGrip($stdArm, $handGrip));
        }
        $items = [];
        foreach ($look['accessories'] as $acc) {
            $h = self::handleOf($acc);
            if (!$h) {
                $look['notes'][] = 'a hat has no Handle part so it was skipped';
            }
            if ($h) {
                $it = $this->buildHandleDrawable($h, $this->accessoryFrame($acc, $h, $cfs), $look['notes']);
                if ($it) {
                    try { $it['thumbCam'] = self::thumbCamOf($acc, $h); } catch (\Throwable $e) { $it['thumbCam'] = null; }
                    $items[] = $it;
                }
            }
        }
        $nHats = count($items);
        if ($look['tool']) {
            $th = self::handleOf($look['tool']);
            if (!$th) {
                $look['notes'][] = 'the gear has no Handle part so it was skipped';
            }
            if ($th) {
                $it = $this->buildHandleDrawable(
                    $th,
                    self::cfMul(
                        $handGrip
                            ? self::CF(self::cfApply($cfs['Right Arm'], $handGrip), self::cfMul($stdArm, self::RIGHT_GRIP_C0())['R'])
                            : self::cfMul($cfs['Right Arm'], self::RIGHT_GRIP_C0()),
                        self::cfInv(self::gripCF($look['tool']))
                    ),
                    $look['notes']
                );
                if ($it) {
                    $items[] = $it;
                }
            }
        }
        if ($only === 'items' && !$items) {
            throw new \InvalidArgumentException('the item could not be drawn' . ($look['notes'] ? ' (' . $look['notes'][0] . ')' : ''));
        }
        $faceImg = $look['face'] ?: $base['face'];
        $Wss = $W * $SS;
        $Hss = $H * $SS;

        $ndcOf = function (array $vp, array $p): ?array {
            $w = $vp[3] * $p[0] + $vp[7] * $p[1] + $vp[11] * $p[2] + $vp[15];
            if ($w <= 1e-6) {
                return null;
            }
            return [
                ($vp[0] * $p[0] + $vp[4] * $p[1] + $vp[8] * $p[2] + $vp[12]) / $w,
                ($vp[1] * $p[0] + $vp[5] * $p[1] + $vp[9] * $p[2] + $vp[13]) / $w
            ];
        };
        $partMap = ['torso' => ['Torso', 0], 'rightArm' => ['Right Arm', 1], 'leftArm' => ['Left Arm', 2], 'rightLeg' => ['Right Leg', 3], 'leftLeg' => ['Left Leg', 4]];
        $onlyPart = (strncmp($only, 'part:', 5) === 0 && isset($partMap[substr($only, 5)])) ? substr($only, 5) : null;
        if ($onlyPart !== null) {
            // one body part on its own: zoomed in and looked at straight from the front
            [$cfName, $partIdx] = $partMap[$onlyPart];
            $pts = array_map(function ($q) use ($cfs, $cfName) { return self::cfApply($cfs[$cfName], $q); }, $base[$onlyPart]['pos']);
            $lo = [1e9, 1e9, 1e9]; $hi = [-1e9, -1e9, -1e9];
            foreach ($pts as $q) { for ($k = 0; $k < 3; $k++) { $lo[$k] = min($lo[$k], $q[$k]); $hi[$k] = max($hi[$k], $q[$k]); } }
            $center = [($lo[0] + $hi[0]) / 2, ($lo[1] + $hi[1]) / 2, ($lo[2] + $hi[2]) / 2];
            $radius = sqrt(($hi[0] - $lo[0]) ** 2 + ($hi[1] - $lo[1]) ** 2 + ($hi[2] - $lo[2]) ** 2) / 2;
            $fov = 18 * M_PI / 180;
            $dist = 1.3 * max($radius, 0.5) / tan($fov / 2);
            $eye = [$center[0], $center[1], $center[2] - $dist];
            $vp = self::mul4(self::perspective($fov, $W / $H, 0.1, 400), self::lookAt($eye, $center, [0, 1, 0]));
            $nx0 = 1e9; $nx1 = -1e9; $ny0 = 1e9; $ny1 = -1e9;
            foreach ($pts as $q) {
                $w = $vp[3] * $q[0] + $vp[7] * $q[1] + $vp[11] * $q[2] + $vp[15];
                if ($w <= 1e-6) { continue; }
                $x = ($vp[0] * $q[0] + $vp[4] * $q[1] + $vp[8] * $q[2] + $vp[12]) / $w;
                $y = ($vp[1] * $q[0] + $vp[5] * $q[1] + $vp[9] * $q[2] + $vp[13]) / $w;
                $nx0 = min($nx0, $x); $nx1 = max($nx1, $x); $ny0 = min($ny0, $y); $ny1 = max($ny1, $y);
            }
            if ($nx1 > $nx0 && $ny1 > $ny0) {
                $s = 0.86 / max(($nx1 - $nx0) / 2, ($ny1 - $ny0) / 2);
                $vp = self::mul4([$s, 0, 0, 0, 0, $s, 0, 0, 0, 0, 1, 0, -$s * ($nx0 + $nx1) / 2, -$s * ($ny0 + $ny1) / 2, 0, 1], $vp);
            }
        } elseif ($this->renderer === '2016' && $view === 'body') {
            $camera = self::thumbnailCamera2016(self::sceneBounds2016($base, $cfs, $items, false, $only), $W, $H, (bool)$only, self::sceneBounds2016($base, $cfs, $items, true, $only));
            $vp = $camera['vp']; $eye = $camera['eye']; $center = $camera['center'];
        } elseif ($view === 'head') {
            $hp = $cfs['Head']['p'];
            $center = [$hp[0], $hp[1] + self::HEAD_AIM, $hp[2]];
            $fov = self::HEAD_FOV * M_PI / 180;
            $dist = self::HEAD_DIST;
            $cd = $this->turnCamera(self::norm(self::HEAD_DIR));
            // A hat that reaches well out in front of the face (an astronaut helmet, a big cap brim) ends up very close to the lens,
            // and perspective then blows it up and bends it. Back the camera off until the nearest hat point is a sane distance away
            // and narrow the lens by the same ratio, so the head keeps its size but the hat is not warped.
            $near = INF;
            foreach (array_slice($items, 0, $nHats) as $it) {
                foreach ($it['geom']['pos'] as $p) {
                    $q = self::cfApply($it['cf'], $p);
                    $near = min($near, $dist - (($q[0] - $center[0]) * $cd[0] + ($q[1] - $center[1]) * $cd[1] + ($q[2] - $center[2]) * $cd[2]));
                }
            }
            if ($near < self::HEAD_NEAR) {
                $newDist = $dist + (self::HEAD_NEAR - $near);
                $fov = 2 * atan(tan($fov / 2) * $dist / $newDist);
                $dist = $newDist;
            }
            $eye = [$center[0] + $cd[0] * $dist, $center[1] + $cd[1] * $dist, $center[2] + $cd[2] * $dist];
            $vp = self::mul4(self::perspective($fov, $W / $H, 0.05, 200), self::lookAt($eye, $center, [0, 1, 0]));
            $m = 0;
            foreach (array_slice($items, 0, $nHats) as $it) {
                foreach ($it['geom']['pos'] as $p) {
                    $q = $ndcOf($vp, self::cfApply($it['cf'], $p));
                    if ($q) {
                        $m = max($m, abs($q[0]), abs($q[1]));
                    }
                }
            }
            if ($m > 0.96) {
                $s = max(1 / self::HEAD_MAXOUT, 0.96 / $m);
                $vp = self::mul4([$s, 0, 0, 0, 0, $s, 0, 0, 0, 0, 1, 0, 0, 0, 0, 1], $vp);
            }
        } else {
            $lo = [1e9, 1e9, 1e9];
            $hi = [-1e9, -1e9, -1e9];
            $addBox = function ($cf, $size) use (&$lo, &$hi) {
                for ($sx = -1; $sx <= 1; $sx += 2) {
                    for ($sy = -1; $sy <= 1; $sy += 2) {
                        for ($sz = -1; $sz <= 1; $sz += 2) {
                            $q = self::cfApply($cf, [$sx * $size[0] / 2, $sy * $size[1] / 2, $sz * $size[2] / 2]);
                            for ($k = 0; $k < 3; $k++) {
                                $lo[$k] = min($lo[$k], $q[$k]);
                                $hi[$k] = max($hi[$k], $q[$k]);
                            }
                        }
                    }
                }
            };
            if (!$only) {
                $addBox($cfs['Torso'], [2, 2, 1]);
            }
            if ($only !== 'items') {
                $addBox($cfs['Head'], [2, 1, 1]);
            }
            foreach ($only ? [] : ['Left Arm', 'Right Arm', 'Left Leg', 'Right Leg'] as $n) {
                $addBox($cfs[$n], [1, 2, 1]);
            }
            foreach ($items as $it) {
                $addBox($it['cf'], $it['size']);
            }
            $center = [($lo[0] + $hi[0]) / 2, ($lo[1] + $hi[1]) / 2, ($lo[2] + $hi[2]) / 2];
            $radius = sqrt(($hi[0] - $lo[0]) ** 2 + ($hi[1] - $lo[1]) ** 2 + ($hi[2] - $lo[2]) ** 2) / 2;
            // a hat or gear on its own is drawn through a narrow lens: the wide thumbnail lens stretches tall, close things (helmets)
            $fov = ($only === 'items' ? min(self::CAM_FOV, self::ITEM_THUMB_FOV) : self::CAM_FOV) * M_PI / 180;
            $dist = self::CAM_ZOOM * $radius / tan($fov / 2);
            $cd = $this->turnCamera(self::norm(self::CAM_DIR));
            $eye = [$center[0] + $cd[0] * $dist, $center[1] + $cd[1] * $dist, $center[2] + $cd[2] * $dist];
            $camUp = [0, 1, 0];
            if ($only === 'items' && count($items) === 1 && !empty($items[0]['thumbCam'])) { try {
                // the hat's own ThumbnailCamera (what Roblox used for its catalog picture): same angle, same focus point
                $tc = $items[0]['thumbCam'];
                $fp = self::cfApply($items[0]['cf'], $tc['focus']);
                $ep = self::cfApply($items[0]['cf'], $tc['eye']);
                $dir = self::norm(self::sub($ep, $fp));
                $up = self::rmul($items[0]['cf']['R'], $tc['up']);
                // a camera looking steeply down (or up) at the item shows a squashed, top-heavy shape: keep it within 35 degrees of level
                $maxY = sin(35 * M_PI / 180);
                if (abs($dir[1]) > $maxY) {
                    $flat = sqrt(max(1e-9, $dir[0] * $dir[0] + $dir[2] * $dir[2]));
                    $k = sqrt(1 - $maxY * $maxY) / $flat;
                    $dir = [$dir[0] * $k, $dir[1] < 0 ? -$maxY : $maxY, $dir[2] * $k];
                    $up = [0, 1, 0];
                }
                if (abs(self::dot($dir, self::norm($up))) < 0.98) {
                    // the catalog cameras use wide lenses (often 70 degrees) set close in, which stretches whatever is nearest the
                    // camera. Keep their angle but use a narrow lens from farther back; the fit below sizes the picture the same.
                    $fov = min($tc['fov'], self::ITEM_THUMB_FOV) * M_PI / 180;
                    $center = $fp;
                    // some hats ship a ThumbnailCamera whose Focus was left somewhere else entirely (the picture then shows nothing
                    // or only a corner). Keep the camera's angle and lens but aim it at the item.
                    $ic = [0, 0, 0];
                    $n = 0;
                    foreach ($items[0]['geom']['pos'] as $gp) { $q = self::cfApply($items[0]['cf'], $gp); for ($k = 0; $k < 3; $k++) { $ic[$k] += $q[$k]; } $n++; }
                    $ib = $n ? [$ic[0] / $n, $ic[1] / $n, $ic[2] / $n] : $items[0]['cf']['p'];
                    $off = self::sub($fp, $ib);
                    if (self::dot($off, $off) > (1.5 * max($radius, 0.5)) ** 2) {
                        $center = $ib;
                    }
                    $dist = max(1.0 * $radius, 0.5) / tan($fov / 2);
                    $eye = [$center[0] + $dir[0] * $dist, $center[1] + $dir[1] * $dist, $center[2] + $dir[2] * $dist];
                    $camUp = self::norm($up);
                }
            } catch (\Throwable $e) { $camUp = [0, 1, 0]; } }
            $vp = self::mul4(self::perspective($fov, $W / $H, 0.1, 200), self::lookAt($eye, $center, $camUp));

            $nx0 = 1e9;
            $nx1 = -1e9;
            $ny0 = 1e9;
            $ny1 = -1e9;
            $ndc = function ($p) use ($vp, &$nx0, &$nx1, &$ny0, &$ny1) {
                $x = $vp[0] * $p[0] + $vp[4] * $p[1] + $vp[8] * $p[2] + $vp[12];
                $y = $vp[1] * $p[0] + $vp[5] * $p[1] + $vp[9] * $p[2] + $vp[13];
                $w = $vp[3] * $p[0] + $vp[7] * $p[1] + $vp[11] * $p[2] + $vp[15];
                if ($w <= 1e-6) {
                    return;
                }
                $x /= $w;
                $y /= $w;
                if ($x < $nx0) {
                    $nx0 = $x;
                }
                if ($x > $nx1) {
                    $nx1 = $x;
                }
                if ($y < $ny0) {
                    $ny0 = $y;
                }
                if ($y > $ny1) {
                    $ny1 = $y;
                }
            };
            $boxPts = function ($cf, $size) use ($ndc) {
                for ($sx = -1; $sx <= 1; $sx += 2) {
                    for ($sy = -1; $sy <= 1; $sy += 2) {
                        for ($sz = -1; $sz <= 1; $sz += 2) {
                            $ndc(self::cfApply($cf, [$sx * $size[0] / 2, $sy * $size[1] / 2, $sz * $size[2] / 2]));
                        }
                    }
                }
            };
            if (!$only) {
                $boxPts($cfs['Torso'], [2, 2, 1]);
            }
            foreach ($only ? [] : ['Left Arm', 'Right Arm', 'Left Leg', 'Right Leg'] as $n) {
                $boxPts($cfs[$n], [1, 2, 1]);
            }
            foreach ($only === 'items' ? [] : $base['head']['pos'] as $p) {
                $ndc(self::cfApply($cfs['Head'], $p));
            }
            foreach ($items as $it) {
                foreach ($it['geom']['pos'] as $p) {
                    $ndc(self::cfApply($it['cf'], $p));
                }
            }
            if ($nx1 > $nx0) {
                $s = 0.94 / max(($nx1 - $nx0) / 2, ($ny1 - $ny0) / 2);
                if (!$only) {
                    $s = min(1, $s);
                }
                $tx = -$s * ($nx0 + $nx1) / 2;
                $ty = -$s * ($ny0 + $ny1) / 2;
                $vp = self::mul4([$s, 0, 0, 0, 0, $s, 0, 0, 0, 0, 1, 0, $tx, $ty, 0, 1], $vp);
            }
        }

        if ($this->camZoom != 1.0) {
            // the caller's zoom, applied last so the automatic fit above does not undo it (about the middle of the picture)
            $z = $this->camZoom;
            $vp = self::mul4([$z, 0, 0, 0, 0, $z, 0, 0, 0, 0, 1, 0, 0, 0, 0, 1], $vp);
        }

        if ($this->renderer === '2016') {
            [$bodyTextures2016, $headTexture2016] = $this->characterTextures2016($look, $faceImg, $base['head']);
            $upTex = $loTex = $bodyTextures2016['torso'];
        } else {
            [$upTex, $loTex] = self::paintSheets($look, $base['inlet']);
        }
        $studs = $this->renderer !== '2016' && !$look['shirt'];
        $hb = $base['headBox'];
        $fb = self::FACE_BOX;
        $fs = ($hb['hi'][0] - $hb['lo'][0]) / 2;
        $faceBox = [($fb[0] - $fb[2]) * $fs, ($fb[0] + $fb[2]) * $fs, ($fb[1] - $fb[3]) * $fs, ($fb[1] + $fb[3]) * $fs];
        $calls = [
            [$base['torso'], $cfs['Torso'], 1, null, $upTex, $studs, [1, 1, 0.5]],
            [$base['rightArm'], $cfs['Right Arm'], 1, null, $upTex, $studs, [0.5, 1, 0.5]],
            [$base['leftArm'], $cfs['Left Arm'], 1, null, $upTex, $studs, [0.5, 1, 0.5]],
            [$base['rightLeg'], $cfs['Right Leg'], 1, null, $loTex, false, null],
            [$base['leftLeg'], $cfs['Left Leg'], 1, null, $loTex, false, null],
            [$base['head'], $cfs['Head'], 2, $look['colors']['head'], new Texture($faceImg), false, null],
        ];
        if ($this->renderer === '2016') {
            foreach (['torso', 'rightArm', 'leftArm', 'rightLeg', 'leftLeg'] as $i => $part) { $calls[$i][4] = $bodyTextures2016[$part]; }
            $calls[5] = [$base['head'], $cfs['Head'], 3, null, $headTexture2016, false, null];
        }
        foreach ($partTex as $part => $t) {
            $i = ['torso' => 0, 'rightArm' => 1, 'leftArm' => 2, 'rightLeg' => 3, 'leftLeg' => 4][$part];
            if ($t) {
                $calls[$i] = [$base[$part], $calls[$i][1], 3, null, $t, false, null];
            } else {
                $calls[$i] = [$base[$part], $calls[$i][1], 0, $look['colors'][$part], null, false, null];
            }
        }
        if ($headTex) {
            $calls[5] = [$base['head'], $cfs['Head'], 3, null, $headTex, false, null];
        }
        if ($only === 'items') {
            $calls = [];
        } elseif ($only === 'head') {
            $calls = [$calls[5]];
        } elseif ($onlyPart !== null) {
            $calls = [$calls[$partIdx]];
        }
        foreach ($items as $it) {
            if ($it['tex']) {
                $calls[] = [$it['geom'], $it['cf'], 3, null, $it['tex'], false, null, $it['tint'], $it['material'] ?? 256, $it['alpha'] ?? 1.0, self::blendsItem($it)];
            } else {
                $calls[] = [
                    $it['geom'],
                    $it['cf'],
                    0,
                    [
                        $it['color'][0] * $it['tint'][0],
                        $it['color'][1] * $it['tint'][1],
                        $it['color'][2] * $it['tint'][2]
                    ],
                    null,
                    false,
                    null,
                    [1, 1, 1],
                    $it['material'] ?? 256,
                    $it['alpha'] ?? 1.0,
                    self::blendsItem($it)
                ];
            }
        }
        $solid = [];
        $glass = [];
        foreach ($calls as $c) {
            $tt = [];
            $this->setup($tt, $c, $vp, $eye, $Wss, $Hss, $faceBox);
            if (!empty($c[10])) {
                foreach ($tt as $q) {
                    $glass[] = $q;
                }
            } else {
                foreach ($tt as $q) {
                    $solid[] = $q;
                }
            }
        }
        // see-through triangles (a hat's soft texture alpha, or a Handle with Transparency) are blended over everything solid,
        // the farthest first, the way Roblox draws them
        foreach ($glass as &$q) {
            $q['blend'] = true;
            $q['depth'] = $q['P'][1][0] * (($q['bx0'] + $q['bx1']) / 2 + 0.5) + $q['P'][1][1] * (($q['by0'] + $q['by1']) / 2 + 0.5) + $q['P'][1][2];
        }
        unset($q);
        usort($glass, function ($a, $b) {
            return $b['depth'] <=> $a['depth'];
        });
        $tris = array_merge($solid, $glass);
        $cz = self::norm(self::sub($eye, $center));
        $cx = self::norm(self::cross([0, 1, 0], $cz));
        $cy = self::cross($cz, $cx);
        foreach (($this->renderer === '2016' ? $this->fxBuild2016($items, $eye) : self::fxBuild($items, $eye)) as $sys) {
            $this->setupFx($tris, $sys, $sys['texture'] ?? $base['fx'][$sys['mat']], $vp, $cx, $cy, $Wss, $Hss);
        }
        $fast = !$this->exact;
        if ($fast && $SS === 1) {
            foreach ($tris as $T) {
                if ($T['mode'] === 4 || !empty($T['blend'])) {
                    $fast = false;
                    break;
                }
            }
        }
        return $fast ? $this->rasteriseFast($tris, $W, $H, $SS, $base['stud'], $faceBox) : $this->rasterise(
            $tris,
            $W,
            $H,
            $SS,
            $base['stud'],
            $faceBox
        );
    }

    private function setup(array &$tris, array $call, array $vp, array $eye, int $Wss, int $Hss, array $faceBox): void {
        [$g, $cf, $mode, $color, $tex, $studs, $half] = $call;
        $tint = $call[7] ?? [1, 1, 1];
        $R = $cf['R'];
        $t = $cf['p'];
        $L = $this->lighting['direction'];
        [$specular, $specPower] = $this->materialSpecular($call[8] ?? 256);
        $n = count($g['pos']);
        $V = [];
        for ($i = 0; $i < $n; $i++) {
            $p = $g['pos'][$i];
            $nn = $g['nrm'][$i];
            $wx = $R[0] * $p[0] + $R[1] * $p[1] + $R[2] * $p[2] + $t[0];
            $wy = $R[3] * $p[0] + $R[4] * $p[1] + $R[5] * $p[2] + $t[1];
            $wz = $R[6] * $p[0] + $R[7] * $p[1] + $R[8] * $p[2] + $t[2];
            $N = self::norm(
                [
                    $R[0] * $nn[0] + $R[1] * $nn[1] + $R[2] * $nn[2],
                    $R[3] * $nn[0] + $R[4] * $nn[1] + $R[5] * $nn[2],
                    $R[6] * $nn[0] + $R[7] * $nn[1] + $R[8] * $nn[2]
                ]
            );
            $ndl = $N[0] * $L[0] + $N[1] * $L[1] + $N[2] * $L[2];
            $Vd = self::norm([$eye[0] - $wx, $eye[1] - $wy, $eye[2] - $wz]);
            $Hh = self::norm([$Vd[0] + $L[0], $Vd[1] + $L[1], $Vd[2] + $L[2]]);
            $nh = $N[0] * $Hh[0] + $N[1] * $Hh[1] + $N[2] * $Hh[2];
            $spec = ($nh > 0 ? $nh ** $specPower : 0.0) * ($ndl >= 0 ? 1 : 0);
            $cx = $vp[0] * $wx + $vp[4] * $wy + $vp[8] * $wz + $vp[12];
            $cy = $vp[1] * $wx + $vp[5] * $wy + $vp[9] * $wz + $vp[13];
            $cz = $vp[2] * $wx + $vp[6] * $wy + $vp[10] * $wz + $vp[14];
            $cw = $vp[3] * $wx + $vp[7] * $wy + $vp[11] * $wz + $vp[15];
            $vy = [max($ndl, 0.0), max(-$ndl, 0.0), $spec];
            if ($mode === 1 || $mode === 3) {
                $vy[] = $g['uv'][$i][0];
                $vy[] = $g['uv'][$i][1];
            }
            if ($mode === 1 && $studs) {
                $vy[] = $p[0];
                $vy[] = $p[1];
                $vy[] = $p[2];
                $vy[] = $nn[1];
            }
            if ($mode === 2) {
                $vy[] = $p[0];
                $vy[] = $p[1];
                $vy[] = $nn[2];
            }
            $lightingOffset = null;
            if ($this->renderer === '2016') {
                // DefaultHQVS carries normal/view direction to the pixel shader. The
                // third legacy varying becomes only the positive-light specular gate.
                $vy[2] = $ndl > 0 ? 1.0 : 0.0;
                $lightingOffset = count($vy) + 2; // P starts with inverse-W and depth.
                array_push($vy, $N[0], $N[1], $N[2], $eye[0] - $wx, $eye[1] - $wy, $eye[2] - $wz);
            }
            $V[] = [$cx, $cy, $cz, $cw, $vy];
        }
        $nv = count($V[0][4]);
        $snap = 1 << $this->subpixelBits;
        for ($i = 0; $i + 2 < $n; $i += 3) {
            $a = $V[$i];
            $b = $V[$i + 1];
            $c = $V[$i + 2];
            if ($a[3] <= 1e-6 || $b[3] <= 1e-6 || $c[3] <= 1e-6) {
                continue;
            }
            $S = [];
            foreach ([$a, $b, $c] as $v) {
                $iw = 1 / $v[3];
                $sx = ($v[0] * $iw + 1) * 0.5 * $Wss;
                $sy = (1 - ($v[1] * $iw + 1) * 0.5) * $Hss;
                $sx = round($sx * $snap) / $snap;
                $sy = round($sy * $snap) / $snap;
                $S[] = [$sx, $sy, ($v[2] * $iw + 1) * 0.5, $iw, $v[4]];
            }
            [$x0, $y0] = $S[0];
            [$x1, $y1] = $S[1];
            [$x2, $y2] = $S[2];
            $area = ($x1 - $x0) * ($y2 - $y0) - ($x2 - $x0) * ($y1 - $y0);
            if (!($area < 0)) {
                continue;
            }
            $d0 = ($x2 - $x1) * ($y0 - $y1) - ($y2 - $y1) * ($x0 - $x1);
            $d1 = ($x0 - $x2) * ($y1 - $y2) - ($y0 - $y2) * ($x1 - $x2);
            if ($d0 == 0 || $d1 == 0) {
                continue;
            }
            $a0 = -($y2 - $y1) / $d0;
            $b0 = ($x2 - $x1) / $d0;
            $c0 = (($y2 - $y1) * $x1 - ($x2 - $x1) * $y1) / $d0;
            $a1 = -($y0 - $y2) / $d1;
            $b1 = ($x0 - $x2) / $d1;
            $c1 = (($y0 - $y2) * $x2 - ($x0 - $x2) * $y2) / $d1;
            $plane = function ($f0, $f1, $f2) use ($a0, $b0, $c0, $a1, $b1, $c1) {
                $e0 = $f0 - $f2;
                $e1 = $f1 - $f2;
                return [$a0 * $e0 + $a1 * $e1, $b0 * $e0 + $b1 * $e1, $f2 + $c0 * $e0 + $c1 * $e1];
            };
            $P = [$plane($S[0][3], $S[1][3], $S[2][3]), $plane($S[0][2], $S[1][2], $S[2][2])];
            for ($k = 0; $k < $nv; $k++) {
                $P[] = $plane($S[0][4][$k] * $S[0][3], $S[1][4][$k] * $S[1][3], $S[2][4][$k] * $S[2][3]);
            }
            $tris[] = [
                'bx0' => max(0, (int)ceil(min($x0, $x1, $x2) - 0.5)), 'bx1' => min($Wss - 1, (int)floor(max($x0, $x1, $x2) - 0.5)),
                'by0' => max(0, (int)ceil(min($y0, $y1, $y2) - 0.5)), 'by1' => min($Hss - 1, (int)floor(max($y0, $y1, $y2) - 0.5)),
                'e' => [$a0, $b0, $c0, $a1, $b1, $c1], 'P' => $P, 'mode' => $mode, 'color' => $color, 'tex' => $tex, 'studs' => $studs, 'half' => $half, 'tint' => $tint, 'specular' => $specular, 'specPower' => $specPower, 'lightingOffset' => $lightingOffset, 'alpha' => (float)($call[9] ?? 1.0), 'sq' => $this->renderer === '2012',
            ];
        }
    }
}
