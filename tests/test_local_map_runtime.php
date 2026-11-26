<?php

declare(strict_types=1);

$root = dirname(__DIR__);

function localMapAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function localMapRead(string $path): string
{
    $content = file_get_contents($path);
    localMapAssert(is_string($content), 'Unable to read ' . $path . '.');
    return $content;
}

function localMapRenderModule(string $root, string $moduleId): string
{
    $_GET = ['module' => $moduleId];
    ob_start();
    include $root . '/public/module.php';
    return (string) ob_get_clean();
}

$vendorRoot = $root . '/public/vendor/maplibre-gl/5.24.0';
$expectedAssets = [
    'maplibre-gl.js' => [
        'minimum_size' => 1000000,
        'sha256' => '45a9b07a9189ce56054c620a947ccf41e291e58c95e9b61533b740aaa65ee5cb',
    ],
    'maplibre-gl.css' => [
        'minimum_size' => 50000,
        'sha256' => 'ab1e70d59ec40465bae7e7030da2f3ccf28133fd502e62bd598eefbadfd7a732',
    ],
];

foreach ($expectedAssets as $filename => $metadata) {
    $path = $vendorRoot . '/' . $filename;
    localMapAssert(is_file($path), 'Missing vendored MapLibre file: ' . $filename);
    localMapAssert((filesize($path) ?: 0) >= $metadata['minimum_size'], 'Vendored MapLibre file is truncated: ' . $filename);
    localMapAssert(hash_file('sha256', $path) === $metadata['sha256'], 'Vendored MapLibre checksum mismatch: ' . $filename);
}

foreach (['LICENSE.txt', 'README.md'] as $filename) {
    localMapAssert(is_file($vendorRoot . '/' . $filename), 'Missing MapLibre provenance file: ' . $filename);
}

$configuration = require $root . '/config.php';
$assets = $configuration['maplibre_assets'] ?? null;
localMapAssert(is_array($assets), 'MapLibre asset configuration is missing.');
localMapAssert(($assets['version'] ?? null) === '5.24.0', 'MapLibre version must be pinned to 5.24.0.');
localMapAssert(($assets['js'] ?? null) === '/vendor/maplibre-gl/5.24.0/maplibre-gl.js', 'MapLibre JS must use the local asset.');
localMapAssert(($assets['css'] ?? null) === '/vendor/maplibre-gl/5.24.0/maplibre-gl.css', 'MapLibre CSS must use the local asset.');

$moduleTemplate = localMapRead($root . '/public/module.php');
localMapAssert(!str_contains($moduleTemplate, 'unpkg.com/maplibre-gl'), 'Module pages must not depend on the MapLibre CDN.');

$propagationPage = localMapRenderModule($root, 'propagation');
foreach ([
    '/vendor/maplibre-gl/5.24.0/maplibre-gl.css',
    '/vendor/maplibre-gl/5.24.0/maplibre-gl.js',
    'mapLibreVersion: "5.24.0"',
    'id="map"',
] as $fragment) {
    localMapAssert(str_contains($propagationPage, $fragment), 'Propagation page is missing local map fragment: ' . $fragment);
}
localMapAssert(!str_contains($propagationPage, 'unpkg.com'), 'Rendered propagation page still references UNPKG.');

$decisionPage = localMapRenderModule($root, 'decision_support');
localMapAssert(!str_contains($decisionPage, '/vendor/maplibre-gl/'), 'A non-map module must not load MapLibre.');
localMapAssert(!str_contains(localMapRead($root . '/public/index.php'), '/vendor/maplibre-gl/'), 'The homepage must not load MapLibre.');

$runtime = localMapRead($root . '/public/module-page.js');
foreach ([
    "id: 'firerisk-map-background'",
    "map.once('style.load', bootModule)",
    'Map engine unavailable.',
    'Basemap tiles unavailable. FireRisk overlays remain active.',
    'ResizeObserver',
] as $fragment) {
    localMapAssert(str_contains($runtime, $fragment), 'Map runtime is missing resilience fragment: ' . $fragment);
}

localMapAssert(str_contains(localMapRead($root . '/modules/propagation/layer.js'), "map.once('style.load', apply)"), 'Propagation overlays must wait for style.load.');
localMapAssert(str_contains(localMapRead($root . '/modules/postfire_hazards/page.js'), "map.once('style.load', apply)"), 'Post-fire overlays must wait for style.load.');

$css = localMapRead($root . '/public/module-page.css');
foreach (['.map-runtime-notice', 'background: #dbe4e8', 'min-height: 0'] as $fragment) {
    localMapAssert(str_contains($css, $fragment), 'Map CSS is missing fallback styling: ' . $fragment);
}

fwrite(STDOUT, "OK: MapLibre 5.24.0 is local, pinned, map-only and resilient to basemap failure.\n");
