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

---

renders 2012 style roblox avatars in php. you give it a charapp (or asset ids and body colors) and it gives you back a png. runs on regular shared hosting, no gpu or webgl or anything.

## how it works

you send a charapp link, it downloads the items from your asset host, builds the character and draws it in php, then sends back a png. renders get cached so the same avatar is instant after the first time (the first one takes about 0.5 to 2 seconds).

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
| `smooth` | `1` (default) or `0` for hard edges |
| `sid` | 16 to 32 hex chars. a new render with the same sid deletes the previous one |

### rendering a single item

`item` renders one asset by itself, same lighting and camera as the avatar renders:

```
avatar.php?item=1234&size=420x420
```

| item | what you get |
|---|---|
| hat, accessory, gear | the item alone, framed to fit |
| head, face | the head alone |
| shirt, pants, t-shirt | a gray avatar wearing it |
| package (old id list or the newer r6/r15 bundle) | a full avatar in the package, r6 only |

anything else (bare images, models without one of the above) is refused.

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

[Hippocratic License 3.0](LICENSE). you can use and change vanilla freely, but not to harm people (human rights, discrimination, forced labor and so on), and anyone who breaks that loses the license. the models and textures in `data/` come from the original client and aren't covered by it.
