# Experimental 2016 avatar rendering

Select with `renderer=2016`; `2012` is the default. This is a software thumbnail
profile inside Vanilla, not the original engine or a full scene renderer.

## Reference revision

Reference repository: https://github.com/Artifaqt/ROBLOX2016

Revision inspected: `e0cfac59fea3a5b986843e65b0fda286e439f9fc`.
Vanilla base revision: `14d8138` from https://github.com/MicrosoftTable/vanilla.

| Behavior | Source | Implementation |
| --- | --- | --- |
| Default ambient, brightness, time | `App/v8datamodel/Lighting.cpp`, constructor | Ambient 0.5, Brightness 1, 14:00 |
| Default sun direction and daylight color | `Rendering/g3d/g3dcpp/LightingParameters.cpp`, `setTime`; corresponding header | Day-zero sun at latitude 41.7333 degrees, daylight diffuse 0.75 |
| Light coordinate frame | `content/fonts/character3.rbxm`, root/body yaw 180 degrees | Rotate world sun into the normalized avatar frame along with the body/camera; front normals receive main light |
| Key and fill scaling | `Rendering/GfxRender/RenderView.cpp`, `presetLighting` | Key ×0.9, opposite fill ×0.4 |
| Diffuse and highlight equation | `shaders/source/default.hlsl` | Ambient + interpolated positive/negative normal-dot-light terms; HQ per-fragment Blinn highlights from normalized interpolated normal/view vectors |
| Material intensity and exponent | `Rendering/GfxRender/MaterialGenerator.cpp`, `getSpecular` | Plastic 102/255 and exponent 9; material table for accessory handles |
| R6 proportions, rest positions and gear pose | `content/fonts/character3.rbxm`; `RCCService.Test/LuaScripts.cs`, `AvatarThumbScript` | Original part centers and sizes; tool shoulder angle 1.57 radians |
| Thumbnail camera | `App/v8datamodel/Camera.cpp`, `setImageServerViewNoLerp` / `tryZoomExtents`; `RCCService.Test/LuaScripts.cs` | Source ZXY rotation (45,35,0 degrees), 70-degree vertical FOV, physical model bounds, distance equal to largest physical side, default crop disabled |
| Optional engine crop | `Rendering/GfxRender/RenderView.cpp`, `modifyThumbnailCamera` | Private camera helper also implements rendering-bounds crop/dolly, separately from the default uncropped avatar script |
| Body and head meshes | `content/fonts/{head,torso,leftarm,rightarm,leftleg,rightleg}.mesh`; `GeometryGenerator.cpp` | Original meshes and normals; original head scaling and native face UVs; source legacy head variants A through P |
| Binary versus text UV conventions | `Rendering/GfxBase/FileMeshData.cpp`, `readMeshFromV1` / `readMeshFromV2` | V1 flips V; V2 keeps V as stored; applies to body, head, package and accessory meshes |
| Clothing and body surface composition | `MaterialGenerator.cpp`; `TextureCompositor.cpp`; `GfxBase/PartIdentifier.cpp` | Original 1024x512 atlas and compositing meshes, including its 0.75 body UV scale; body colors, package base, pants, shirt, t-shirt, package overlay; no old stud or arm socket decals |
| R6 appearance assets | `CharacterMesh.cpp`; `GeometryGenerator.cpp`, `getHeadMeshId` | CharacterMesh body replacements and base/overlay textures; legacy and file mesh heads |
| Attachment welds | `App/v8datamodel/Accoutrement.cpp` | First handle attachment, exact name match against supported R6 anchors, inverse handle frame; legacy head/AttachmentPoint fallback |
| Fire, smoke, sparkles layers and parameters | `Rendering/GfxRender/ParticleEmitter.cpp`, `applySettings` | Two fire layers, two sparkle layers, one smoke layer; source sizes, rates, lifetime ranges, spread, forces, offsets, spin and damping |
| Effect simulation and half sizes | `Rendering/GfxRender/Emitter.cpp` | World-space emitter transforms; acceleration before linear damping, source spherical spread; sizes are billboard half extents |
| Effect colors and blending | `shaders/source/particle.hlsl` | Modulate, Crazy, CrazySparkles and Add equations, lifetime alpha/color strips |

The attachment path corresponds to `AccessoriesAndAttachments` enabled. The
source defaults that flag to false; historical rollout configurations may differ.
The particle spread follows `SphericalSparklesEmission=false` and damping follows
`EnableParticleDrag=false`, as declared in this revision. No runtime flag service
is emulated.

## Character assets

`data/2016/character/` contains the original binary meshes for the six default
parts, legacy head variants A through P, the five body-color compositing layers,
shirt/pants/t-shirt compositing layers, and package base/overlay layers.
`source-sha256.json` records their hashes against the inspected source checkout.
The six default mesh files are byte-identical to Vanilla's pre-existing files;
the corrections are in loading, UV conventions, compositing and camera behavior.
There was no reason to invent replacement default geometry.

The default body camera follows the avatar script shipped in the RCC test suite:
`Click(fileExtension, x, y, true)` leaves the optional crop argument false.
It does not use Vanilla's previous 94% silhouette fit. Physical part bounds set
the initial camera; rendering bounds are only relevant if the private crop
helper is explicitly enabled. This distinction matters for oversized mesh hats
and tools whose visual extents differ from their handle size.

## Default face

`data/2016/face.png` is the original `content/textures/face.png` from the reference
revision (SHA-256 `7667dddefcd28e4688dd384e28efb03209745febcf99ee91619111536694d1fe`).
`character3.rbxm` uses that texture for its default face decal. It replaces the
2012 fallback only in 2016 mode; explicitly equipped face decals still override it.

## Effect assets

`data/2016/*.png` are lossless PNG conversions of these original files under
`content/textures/particles/` in the reference revision:

- `common_alpha.dds`
- `fire_main.dds`, `fire_color.dds`, `fire_alpha.dds`
- `fire_sparks_main.dds`, `fire_sparks_color.dds`
- `smoke_main.dds`, `smoke_color.dds`
- `sparkles_main.dds`, `sparkles_color.dds`

These original client assets retain their original ownership, like the existing
meshes and textures in Vanilla's `data/`. No C++ source or compiled client is
bundled. PNG conversions let shared PHP hosting load the textures through GD.

## Remaining accuracy gaps

- No paired 2016 client screenshots have been used for pixel comparison. The
  original thumbnail service's calling Lua script can override lighting, pose,
  camera and time. The included RCC test avatar script is now followed for body framing; this alone does not establish every production website thumbnail configuration.
- The supplied reference images have been inspected visually, but their exact
  charapps/assets and original render jobs are not available for paired captures.
  Pixel-perfect parity with those avatars remains unverified.
- The `head` view retains the existing fitted headshot camera. The new native
  body camera applies to `view=body`; it is not a recovered production headshot script.
- Custom file-mesh heads with their own texture load that texture; separate face
  decal compositing over an arbitrary custom head texture is not reproduced.
  R6 package textures are composited by the original layer meshes, but per-vertex
  mesh color, transparent body parts and nonstandard physical part dimensions
  from arbitrary custom rigs are not implemented.
- Lighting implements the composited material HQ shader's diffuse/specular path,
  without GPU float/half quantization, normal maps, material surface patterns, outlines, SSAO,
  reflection, neon glow, local lights, light grids, fog or global/self shadows.
  Material selection currently changes highlights only.
- Standard R6 attachment anchors are supported, not arbitrary body models,
  R15 rigs, animation, layered clothing, or modern SurfaceAppearance parity.
- Fire/Smoke/Sparkles snapshots simulate six seconds at 30 Hz from Vanilla's
  deterministic seed. They do not reproduce the client's random stream, exact
  particle positions, render frame timing, throttling or draw-order tie behavior.
- Custom `ParticleEmitter` instances, sequences, ForceField and Explosion effects
  are not implemented. Direct custom emitters on handles produce a diagnostic note.
- Effect texture filtering, sampling, raster coverage and transparent PNG
  compositing can differ from GPU output. Pure additive light cannot be represented
  faithfully by ordinary PNG alpha over every possible page background.

## Verification

`php tests/renderers.php`, `php tests/geometry2016.php`, and `php tests/lighting2016.php` exercise both profiles, both views, smooth/hard edges,
PNG transparency format, deterministic effects, enabled/disabled effects, both
rasterizers, renderer validation, profile reset between calls, material values and
attachment weld equations. Geometry checks also cover original asset integrity,
V1/V2 UV conventions, uncropped camera focus/distance, physical versus rendering
bounds, clothing front-face mapping, package mesh loading and legacy head selection.
The tests use local RBXM/texture fixtures with no remote asset downloads. They do
not substitute for original-client screenshot comparisons.

## Torso and leg lighting correction

The supplied crowned classic-avatar reference exposes two specific mistakes in
the previous profile: its sun direction was in the original world's coordinates
after the body was rotated into Vanilla's coordinates, and its highlights were
computed at vertices instead of following `DefaultHQFS`. The corrected profile
rotates the light through the same 180-degree root normalization and evaluates
HQ highlights at fragments in both rasterizers. Diffuse lighting still follows
the source vertex path; this is not a global brightness/gamma adjustment.

The reference's dominant flat leg-front color is RGB (117,135,51). The previous
profile produced (96,110,41); the corrected plain avatar produces (117,135,51).
The arm and torso fronts also move into the reference's measured color range.
These flat-surface checks isolate the lighting issue; accessories, exact camera
and a full-image match remain unverified without the corresponding avatar data.
