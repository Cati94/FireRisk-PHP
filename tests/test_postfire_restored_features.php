<?php

declare(strict_types=1);

require_once __DIR__ . '/../modules/postfire_hazards/IcnfBurnedAreaRepository.php';
require_once __DIR__ . '/../modules/postfire_hazards/PostFireRiskEngine.php';

$root = dirname(__DIR__);
$repo = new IcnfBurnedAreaRepository($root, 'Europe/Lisbon');

$defaults = $repo->defaultScenario();
foreach (['rain_1h_mm', 'rain_24h_mm', 'soil_saturation'] as $key) {
    if (!array_key_exists($key, $defaults)) {
        fwrite(STDERR, "FAIL: default scenario missing {$key}.\n");
        exit(1);
    }
}

$query = [
    'source' => 'test',
    'months' => 12,
    'reference_date' => '2026-07-08T12:00:00+01:00',
];
$collection = $repo->featureCollection($query);
$features = $collection['features'] ?? [];
if (count($features) <= 0) {
    fwrite(STDERR, "FAIL: restored postfire repository returned no features.\n");
    exit(1);
}

$firstProps = $features[0]['properties'] ?? [];
foreach (['risk_zones', 'geometry_source', 'burned_area_ha', 'start_local'] as $key) {
    if (!array_key_exists($key, $firstProps)) {
        fwrite(STDERR, "FAIL: restored burned-area feature missing {$key}.\n");
        exit(1);
    }
}

$analysis = PostFireRiskEngine::evaluateFeatureCollection(
    $collection,
    $repo->scenario($query, $defaults),
    12,
    new DateTimeImmutable('2026-07-08T12:00:00+01:00'),
    $repo->totalAvailable(['source' => 'test'])
);

foreach (['overall', 'areas', 'zones', 'burned_areas_geojson', 'module_info'] as $key) {
    if (!array_key_exists($key, $analysis)) {
        fwrite(STDERR, "FAIL: restored analysis missing {$key}.\n");
        exit(1);
    }
}

$zone = $analysis['zones'][0] ?? [];
foreach (['flood_score', 'erosion_score', 'landslide_score', 'combined_score', 'lat', 'lon', 'recommendations'] as $key) {
    if (!array_key_exists($key, $zone)) {
        fwrite(STDERR, "FAIL: restored zone missing {$key}.\n");
        exit(1);
    }
}

$manifestPath = $root . '/modules/postfire_hazards/module.json';
if (!is_file($manifestPath)) {
    fwrite(STDERR, "FAIL: restored module manifest missing.\n");
    exit(1);
}
$manifest = json_decode((string)file_get_contents($manifestPath), true);
if (($manifest['constraints']['map_layer_policy'] ?? '') === '') {
    fwrite(STDERR, "FAIL: manifest map_layer_policy missing.\n");
    exit(1);
}

echo 'OK: Restored original post-fire functionality using real ICNF-derived local data. Areas: ' . count($analysis['areas']) . ', zones: ' . count($analysis['zones']) . ".\n";
