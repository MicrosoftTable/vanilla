<?php
namespace Vanilla;

foreach (['BrickColors', 'Net', 'Inst', 'Rbxm', 'Img', 'PngStream', 'Texture', 'CharApp'] as $file) {
    require_once __DIR__ . '/' . $file . '.php';
}

foreach (['RenderProfiles', 'Character2016', 'Particles2016', 'Geometry', 'Assets', 'Meshes', 'LookBuilder', 'Particles', 'ItemRender', 'Scene', 'FastRaster', 'ReferenceRaster'] as $part) {
    require_once __DIR__ . '/parts/' . $part . '.php';
}

// vanilla is a software renderer in plain php with no gpu and no webgl and no rcc
final class Avatar {
    use RenderProfiles, Character2016, Particles2016, Geometry, Assets, Meshes, LookBuilder, Particles, ItemRender, Scene, FastRaster, ReferenceRaster;

    const TPL_W = 585, TPL_H = 559;
    // where each face of each body part sits on the 585x559 clothing template: x, y, width, height
    const TEMPLATE = [
        'torso' => [
            'U' => [231, 8, 128, 64],
            'D' => [231, 204, 128, 64],
            'F' => [231, 74, 128, 128],
            'B' => [427, 74, 128, 128],
            'R' => [165, 74, 64, 128],
            'L' => [361, 74, 64, 128],
        ],
        'right' => [
            'U' => [217, 289, 64, 64],
            'D' => [217, 485, 64, 64],
            'F' => [217, 355, 64, 128],
            'B' => [85, 355, 64, 128],
            'R' => [151, 355, 64, 128],
            'L' => [19, 355, 64, 128],
        ],
        'left' => [
            'U' => [308, 289, 64, 64],
            'D' => [308, 485, 64, 64],
            'F' => [308, 355, 64, 128],
            'B' => [440, 355, 64, 128],
            'R' => [506, 355, 64, 128],
            'L' => [374, 355, 64, 128],
        ],
    ];
    // camera and lighting values fitted to real 2012 thumbnails
    const CAM_DIR = [-0.40142, 0.39402, -0.82681];
    const CAM_FOV = 70.86, HEAD_SCALE = 1.0, CAM_ZOOM = 1.0;
    const FACE_BOX = [0.0343, -0.0233, 1.0806, 1.0544];
    const LAMP_DIR = [0.47490, 0.82250, -0.31280];
    const AMBIENT = 128 / 255, KEY = 0.75 * 0.9, FILL = 0.75 * 0.9 * 0.4 * 0.9, KS = 1.0, SPEC_EXP = 45;
    const BEVEL = 0.065;
    const SIZES = [
        '150x200' => [150, 200],
        '180x220' => [180, 220],
        '100x100' => [100, 100],
        '352x352' => [352, 352],
        '420x420' => [420, 420],
        '512x512' => [512, 512],
        '150x150' => [150, 150],
        '48x48' => [48, 48]
    ];
    // headshot camera fitted to a real 2012 150x150 headshot
    const HEAD_DIR = [0.0, 0.0, -1.0], HEAD_FOV = 42.84, HEAD_DIST = 3.405, HEAD_AIM = 0.0, HEAD_MAXOUT = 1.6;
    const VIEWS = ['body', 'head'];
    const MAX_ASSET_BYTES = 8 * 1024 * 1024, MAX_IMAGE_SIDE = 2048, MAX_MESH_VERTS = 150000, MAX_IDS = 12, MAX_ACCESSORIES = 8;
    const DEFAULT_COLORS = ['#f5cd30', '#0d69ac', '#f5cd30', '#f5cd30', '#a4bd47', '#a4bd47'];

    private $assetBase;
    private $allowedHosts = [];
    private $allowPrivate = false;
    private $subpixelBits;
    private $dataDir;
    private $cacheDir;
    private $fetcher;
    private $cache = [];
    private $content = [];
    private static $base = null;
    private $exact = false;

    function __construct(array $opts = []) {
        $this->assetBase = $opts['assetBase'] ?? 'https://example.com/asset/?id=';
        $this->allowedHosts = (array)($opts['allowedHosts'] ?? []);
        $this->allowPrivate = !empty($opts['allowPrivateHosts']);
        $this->dataDir = $opts['dataDir'] ?? dirname(__DIR__) . '/data';
        $this->cacheDir = $opts['cacheDir'] ?? null;
        $this->fetcher = $opts['fetcher'] ?? null;
        $this->subpixelBits = max(0, min(12, (int)($opts['subpixelBits'] ?? 8)));
        $this->exact = !empty($opts['exact']);
        $this->defaultRenderer = self::validateRenderer($opts['renderer'] ?? '2012');
        $this->selectRenderer($this->defaultRenderer);
    }

    private function base(): array {
        return self::$base ?: $this->buildBase();
    }

    private function buildBase(): array {
        $D = $this->dataDir;
        $hm = self::parseMesh(file_get_contents("$D/head.mesh"));
        $k = self::HEAD_SCALE;
        foreach ($hm['pos'] as &$p) {
            $p = [$p[0] * $k, $p[1] * $k, $p[2] * $k];
        }
        unset($p);
        $T = self::TEMPLATE;
        $part = function ($f, $hx, $hy, $hz, $region) use ($D) {
            return self::templateMesh(self::parseMesh(file_get_contents("$D/$f")), $hx, $hy, $hz, $region);
        };
        return self::$base = [
            'head' => $hm, 'headRaw' => self::parseMesh(file_get_contents("$D/head.mesh")), 'headBox' => self::meshBounds($hm),
            'torso' => $part('torso.mesh', 1, 1, 0.5, $T['torso']),
            'rightArm' => $part('rightarm.mesh', 0.5, 1, 0.5, $T['right']),
            'leftArm' => $part('leftarm.mesh', 0.5, 1, 0.5, $T['left']),
            'rightLeg' => $part('rightleg.mesh', 0.5, 1, 0.5, $T['right']),
            'leftLeg' => $part('leftleg.mesh', 0.5, 1, 0.5, $T['left']),
            'stud' => new Texture(Img::decode(file_get_contents("$D/stud.png"))),
            'inlet' => Img::decode(file_get_contents("$D/inlet.png")),
            'fx' => [new Texture(Img::decode(file_get_contents("$D/fx_sparkle.png"))), new Texture(Img::decode(file_get_contents("$D/fx_fire.png"))), new Texture(Img::decode(file_get_contents("$D/fx_smoke.png")))],
            'face' => Img::decode(file_get_contents("$D/face.png")),
        ];
    }

    function render(array $req): array {
        $this->selectRenderer($req['renderer'] ?? $this->defaultRenderer);
        if (isset($req['item'])) {
            if (!empty($req['ids']) || isset($req['charapp'])) {
                throw new \InvalidArgumentException('use item on its own');
            }
            return $this->renderItem($req);
        }
        if (isset($req['charapp'])) {
            if (!empty($req['ids'])) {
                throw new \InvalidArgumentException('use charapp or ids and not both');
            }
            $c = CharApp::resolve(
                (string)$req['charapp'],
                ['assetBase' => $this->assetBase, 'allowedHosts' => $this->allowedHosts, 'allowPrivateHosts' => $this->allowPrivate, 'cacheDir' => $this->cacheDir]
            );
            $req['ids'] = $c['ids'];
            $req['colors'] = $req['colors'] ?? $c['colors'];
        }
        $raw = is_array($req['ids'] ?? null) ? $req['ids'] : [];
        if (count($raw) > self::MAX_IDS * 4) {
            throw new \InvalidArgumentException('Too many IDs.');
        }
        $ids = [];
        foreach ($raw as $r) {
            $id = self::parseId($r);
            if ($id === null) {
                throw new \InvalidArgumentException('"' . substr((string)$r, 0, 20) . '" is not an asset ID. Only numeric asset IDs are accepted.');
            }
            if (!in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        if (count($ids) > self::MAX_IDS) {
            throw new \InvalidArgumentException('At most ' . self::MAX_IDS . ' asset IDs at a time.');
        }
        $cols = is_array($req['colors'] ?? null) ? array_values($req['colors']) : self::DEFAULT_COLORS;
        if (count($cols) !== 6) {
            throw new \InvalidArgumentException('Body colours need six colours, each written #rrggbb.');
        }
        $look = self::emptyLook();
        foreach (['head', 'torso', 'leftArm', 'rightArm', 'leftLeg', 'rightLeg'] as $j => $k) {
            $look['colors'][$k] = self::parseColor($cols[$j]);
        }
        $size = self::SIZES[$req['size'] ?? '150x200'] ?? null;
        if (!$size) {
            throw new \InvalidArgumentException('Unknown size.');
        }
        $this->loadWaves($ids);
        foreach ($ids as $id) {
            $this->addAsset($look, $id);
        }
        $look['accessories'] = array_slice($look['accessories'], 0, self::MAX_ACCESSORIES);
        $view = $req['view'] ?? 'body';
        if (!in_array($view, self::VIEWS, true)) {
            throw new \InvalidArgumentException('View is body or head.');
        }
        $SS = ($req['smooth'] ?? true) === false ? 1 : 4;
        $png = $this->draw($look, $size[0], $size[1], $SS, $view);
        return ['png' => $png, 'notes' => $look['notes']];
    }
}
