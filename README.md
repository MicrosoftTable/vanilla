<p align="center">
  <img src="assets/icon.png" alt="vanilla" width="128" height="128">
</p>

<h1 align="center">vanilla</h1>

<p align="center">
  2012 style roblox avatar renders in plain php, with an experimental 2016 mode
</p>

<p align="center">
  <img src="https://img.shields.io/badge/php-7.4%2B-777bb4?style=flat-square&logo=php&logoColor=white" alt="php 7.4+">
</p>


# Adding more poses

ts draws the character in a fixed pose. A pose is a set of joint rotations applied to the body parts before drawing. This guide shows how to add a new one.

## Where poses live

A pose name is checked in three places, and each one has to accept it:

| File | What it does |
|------|--------------|
| `src/Vanilla.php` | The `$allowedPoses` list in `render()`. Unknown names are rejected here. |
| `src/parts/Scene.php` | `applyPose()`, which turns the pose name into joint rotations. |
| `src/parts/Scene.php` | `poseRotationX()` and `poseRotationZ()`, the rotation helpers. |

If you're using Vanilla inside a Laravel app, the app may keep its own list too (for example in `AvatarRenderer` or a controller). Add the new name there as well.

## How a pose works

Each limb rotates around a **pivot**, which is its joint in the torso's space. For the R6 body:

- Shoulders: `[-1, 0.5, 0]` (left) and `[1, 0.5, 0]` (right)
- Hips: `[-0.5, -1, 0]` (left) and `[0.5, -1, 0]` (right)

`applyPose()` rotates a part around its pivot with this sequence:

1. Find the pivot in the torso's space: `self::cfApply($torso, $pivot)`
2. Build a rotation around that point: move to the pivot, rotate, move back
3. Apply it to the part: `$cfs[$part] = self::cfMul($around, $cfs[$part]);`

The angles are in radians. `M_PI / 2` is 90 degrees.

## Step 1: Add the name to the allow list

In `src/Vanilla.php`, inside `render()`, find `$allowedPoses` and add your name:

```php
$allowedPoses = [
    'standing',
    'overlord',
    'walking',
    'sitting',
    'tpose',
    'zombie',
    'cheer', // new
];
```

## Step 2: Add the rotations

In `src/parts/Scene.php`, inside `applyPose()`, add a branch for your pose. Put it with the other pose branches, before the code that handles the default pose.

Here's a `cheer` pose where both arms raise up and out:

```php
if ($pose === 'cheer') {
    $rot = [
        'Left Arm'  => self::poseRotationZ(-2.8),
        'Right Arm' => self::poseRotationZ(2.8),
    ];

    $shoulder = [
        'Left Arm'  => [-1, 0.5, 0],
        'Right Arm' => [1, 0.5, 0],
    ];

    foreach ($rot as $part => $R) {
        $p = self::cfApply($torso, $shoulder[$part]);

        $around = self::cfMul(
            self::cfMul(self::CF($p), self::CF([0, 0, 0], $R)),
            self::CF([-$p[0], -$p[1], -$p[2]])
        );

        $cfs[$part] = self::cfMul($around, $cfs[$part]);
    }

    return $cfs;
}
```
`poseRotationZ` rotates around the Z axis (arms swing out to the sides). `poseRotationX` rotates around the X axis (arms swing forward or back). Choose the helper that matches the direction you want.

## Step 3: Test it

Render the pose through Vanilla directly:

```php
$avatar = new \Vanilla\Avatar([...]); // your usual config
$result = $avatar->render([
    'ids' => [],
    'colors' => [24, 23, 24, 24, 119, 119],
    'size' => '150x200',
    'renderer' => '2012',
    'pose' => 'cheer',
]);
file_put_contents('cheer.png', $result['png']);
```

Open `cheer.png` and check:

- Both arms rise and point the same way.
- The arms start at the shoulders and don't cut into the torso.
- The legs and torso didn't move.

## Adjusting a pose

- **Arm is in the wrong place:** change the angle. Angles go up with bigger values, but the sign depends on the axis, so flip the sign if it goes the wrong way.
- **Arm rotates from the wrong point:** check the pivot. It should sit where the limb joins the torso.
- **Pose name is rejected:** the name is missing from one of the lists in the table above. Check `$allowedPoses` first.

## Adding a pose with legs

Legs use the hip pivots, `[±0.5, -1, 0]`. Walking and sitting work the same way: rotate the leg part around its hip pivot. Use `poseRotationX` for forward and backward motion.

## Checklist

- [ ] Name added to `$allowedPoses` in `src/Vanilla.php`
- [ ] Branch added to `applyPose()` in `src/parts/Scene.php`
- [ ] Name added to any app-side allow list
- [ ] Rendered and checked against the list above

ok bye. thanks claude for writing this tutorial...... gulp

---

renders 2012 style roblox avatars in php (a recreation, see below, not a port of rccservice). you give it a charapp (or asset ids and body colors) and it gives you back a png. runs on regular shared hosting, no gpu or webgl or anything.

## how it works

you send a charapp link, it downloads the items from your asset host, builds the character and draws it in php, then sends back a png. renders get cached so the same avatar is instant after the first time (the first one takes about 0.5 to 2 seconds).

## a recreation, not a port

vanilla is a **recreation** of what rccservice does, not a port of it. none of roblox's engine or render code is in here, it's a separate renderer written from scratch in php and fitted by eye and by comparing against real thumbnails. that means renders are bound to be a little different from the real thing (lighting, camera, mesh edge cases, particles) and it's not meant to be 100% accurate.

## vs rccservice

rccservice is roblox's real render server. you send it a soap request with a lua script and it runs the actual engine on a windows machine with a gpu.

vanilla doesn't use any of that. there's no soap, no lua and no windows box, you just make a normal request and get an image back.

## setup

needs php 7.4+ with gd, curl and zlib. Allow 256 MB PHP memory for large 2016 renders; `avatar.php` already requests this limit. Direct PHP callers should configure the same limit.

1. put the folder on your server
2. set `assetBase` in `config.php` to your asset host (the id gets added to the end)
3. make sure php can write to `cache/`

now request a render:

```
avatar.php?charapp=https://yoursite.com/charapp&size=150x200
```

the charapp url is the link your site gives out for an avatar. vanilla fetches it and reads the list inside, which looks like this:

```
https://yoursite.com/bodycolors?a;https://yoursite.com/asset/?id=1234;https://yoursite.com/asset/?id=5678;
```

the first url is the bodycolors url, it gets fetched for the six body colors. every `asset/?id=` url after it is an item the avatar is wearing. everything is split by `;`. you can also pass that list straight into `charapp` instead of a link to it.

the urls have to be on your `assetBase` host. if you need other hosts, add them to `allowedHosts` in `config.php`.

the user agent vanilla sends on those requests is `userAgent` in `config.php` (`Vanilla/1.0` by default). in php, pass `'userAgent'` to the `Avatar` constructor.

if you don't have a bodycolors url you can pass `colors` yourself (`colors=f5cd30,0d69ac,...`, head, torso, left arm, right arm, left leg, right leg). if you pass both, `colors` wins.

you can also skip the charapp and just use ids and colors:

```
avatar.php?ids=1234,5678&colors=f5cd30,0d69ac,f5cd30,f5cd30,a4bd47,a4bd47&size=150x200
```

| param | what it does |
|---|---|
| `charapp` | the charapp link (or the list itself). use this or `ids`, not both |
| `item` | one asset id, rendered on its own, see below. use it instead of `charapp` and `ids` |
| `ids` | up to 12 asset ids (shirts, pants, t-shirts, faces, hats, gear) |
| `colors` | head, torso, left arm, right arm, left leg, right leg as `rrggbb` |
| `size` | `150x200` (default), `180x220`, `100x100`, `352x352`, `420x420`, `512x512`, `150x150`, `48x48`. `x` and `y` work too |
| `view` | `body` (default) or `head` for a headshot |
| `renderer` | `2012` (default) or experimental `2016`; separate lighting, material highlights, and legacy particle effects |
| `format` | `png` (default), `webp` or `jpg`. png and webp keep transparency, jpg gets a solid background |
| `bg` | background colour for jpg as hex, `ffffff` by default |
| `zoom` | camera zoom, `1` (default) is the normal framing, `1.5` is closer, `0.6` is farther. 0.25 to 4 |
| `yaw` | swing the camera around the avatar, in degrees from the default angle (-180 to 180). `0` is the default, positive and negative go opposite ways |
| `pitch` | tilt the camera up or down from the default angle, in degrees (-80 to 80) |
| `camera` | `modern` (default) or `classic`. classic is the original 2012 item thumbnail: the wide lens and the steep catalog angles, with their stretching. it only backs the camera off for a hat that gets very close to the lens, so brims and visors don't blow up |
| `smooth` | `1` (default) or `0` for hard edges |
| `sid` | 16 to 32 hex chars. a new render with the same sid deletes the previous one |

### camera

`zoom`, `yaw` and `pitch` change the default camera for one render, so you can frame things your own way. leave them out and you get the usual picture. they work on body renders, headshots (`view=head`) and single items, in the 2012 renderer:

```
avatar.php?ids=1234&yaw=35&pitch=10&zoom=1.2
avatar.php?ids=1234&view=head&yaw=-25
```

zoom is applied after the automatic fit, so a zoom above 1 can crop the avatar on purpose. in php, pass `'zoom'`, `'yaw'` and `'pitch'` to `render()`. the 2016 renderer's body camera and the close-up of a lone body part ignore yaw and pitch.

### rendering a single item

`item` renders one asset by itself, same lighting and camera as the avatar renders:

```
avatar.php?item=1234&size=420x420
```

| item | what you get |
|---|---|
| hat, accessory, gear | the item alone, framed to fit (if the hat has its own thumbnail camera, that angle is used) |
| head, face | the head alone |
| shirt, pants, t-shirt | a gray avatar wearing it |
| package (old id list or the newer r6/r15 bundle) | a full avatar with the package's parts, r6 only. parts with their own textures are drawn with them, parts without one go on the grey and blue checkered template, and every other part is white (or the package's own body colors if it has a BodyColors entry) |
| package with a single body part (one arm, leg or torso) | that part on its own, close up and straight on |

anything else (bare images, models without one of the above) is refused.

note on packages: when a package is a single body part, that part is rendered separately (on its own, close up and straight on), and its position is not accurate to how roblox did it. that's on purpose, vanilla takes a different approach here.

### asset versions

any asset id can be pinned to a version, either as `123@4` or as an asset url with `&version=4` (`live` and `latest` work too). vanilla asks your asset host for `assetBase + id + &version=4`. pinned versions are cached for a day, `live` and `latest` only for a minute.

a package list can pin its parts the same way, like a charapp:

```
https://site.com/asset/?id=100301953&version=1;https://site.com/asset/?id=100301459&version=1;...
```

### package heads

a package head is drawn with its own mesh and texture. transparent parts of the texture show the head color underneath. if the head has a `NeckRigAttachment` it sits on the neck by that point instead of the middle of the head. a face decal on the head (or the default face, unless the head is tagged `NoFace`) is drawn onto the front of the mesh.

### rbxasset:// paths

some gear points at `rbxasset://` files. vanilla swaps the `rbxasset://` part for your asset url, so with `assetBase` set to `https://site.com/asset/?id=` the path `rbxasset://fonts/sword.mesh` is requested as `https://site.com/asset/?id=fonts/sword.mesh`, and `rbxasset://123` is the same as asset id 123. only `.mesh`, `.png` and `.jpg` paths are read. the body part meshes and the default face are bundled.

or from php:

```php
require 'src/Vanilla.php';
$r = (new Vanilla\Avatar(['assetBase' => 'https://your.host/asset/?id=', 'cacheDir' => __DIR__ . '/cache']))
    ->render(['charapp' => 'https://your.host/charapp', 'size' => '150x200']);
file_put_contents('thumb.png', $r['png']);
```

## 2016 rendering option

Append `&renderer=2016` to an existing render URL:

```
avatar.php?ids=1234,5678&size=420x420&renderer=2016
```

Use `renderer=2012` for the existing appearance. You can also set `renderer` in
`config.php` (or `config.local.php`) to change the endpoint default. Both modes
share the same assets and request parameters; their PNG caches and ETags are separate.
In PHP, pass `'renderer' => '2016'` to `render()` or to the `Avatar` constructor.
A per-request choice only affects that request.

The experimental mode uses source-derived 2016 default lighting, per-material
specular values, and layered Fire/Smoke/Sparkles with original 2016 textures.
Lighting rotates the sun into the avatar coordinate frame and evaluates HQ highlights per pixel.
It also uses the source avatar script's uncropped body camera, native binary mesh
UVs and clothing atlas, no body studs, and the original head face UVs. R6 package
body meshes and legacy/file-mesh heads can replace default parts.
It is **not verified as a 1:1 2016 RCCService replacement**. See [source notes and remaining gaps](docs/2016-renderer.md).

Run the offline checks with `php tests/renderers.php`, `php tests/geometry2016.php`, and `php tests/lighting2016.php`.

## switching from rccservice to vanilla

you already have the charapp you give rcc. instead of sending it to rcc, put it in the `charapp` param of `avatar.php` and use the png you get back. save it or put the url in an `<img>`.

## what's where

```
avatar.php            the endpoint, reads the request and sends back the png
config.php            your settings
src/Vanilla.php       the renderer, render() is the entry point
src/CharApp.php       reads charapp links and lists
src/Net.php           all outside requests, public addresses only
src/parts/            the renderer split by job (assets, meshes, look building, particles, scene, rasterisers)
src/Rbxm.php          reads roblox models (xml and binary)
src/Img.php           image decoding and drawing
data/                 the body meshes and textures
```

## license

[GNU General Public License v3.0](LICENSE). you can use, change and share vanilla freely, as long as anything you distribute that's based on it is also released under the GPLv3 with its source available. the models and textures in `data/` come from the original client and aren't covered by it.
