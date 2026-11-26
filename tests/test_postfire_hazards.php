<?php

declare(strict_types=1);

require_once __DIR__ . '/../modules/postfire_hazards/IcnfBurnedAreaRepository.php';
require_once __DIR__ . '/../modules/postfire_hazards/PostFireRiskEngine.php';

$root = dirname(__DIR__);
$repo = new IcnfBurnedAreaRepository($root, 'Europe/Lisbon');
$query = [
    'source' => 'test',
    'months' => 12,
    'reference_date' => '2026-07-08T12:00:00+01:00',
];
$collection = $repo->featureCollection($query);
$count = count($collection['features'] ?? []);
if ($count <= 0) {
    fwrite(STDERR, "Post-fire module returned no ICNF-derived burned areas.\n");
    exit(1);
}
$analysis = PostFireRiskEngine::evaluateFeatureCollection(
    $collection,
    $repo->scenario(['rain_1h_mm' => 20, 'rain_24h_mm' => 55, 'soil_saturation' => 0.7]),
    12,
    new DateTimeImmutable('2026-07-08T12:00:00+01:00'),
    $repo->totalAvailable(['source' => 'test'])
);
if (($analysis['success'] ?? false) !== true || ($analysis['count'] ?? 0) <= 0) {
    fwrite(STDERR, "Post-fire risk analysis failed.\n");
    exit(1);
}
$geojson = $analysis['burned_areas_geojson'] ?? [];
if (($geojson['type'] ?? '') !== 'FeatureCollection' || count($geojson['features'] ?? []) <= 0) {
    fwrite(STDERR, "Post-fire GeoJSON output invalid.\n");
    exit(1);
}
echo 'OK: Post-fire hazards derived ' . $analysis['count'] . ' burned area(s) from locally stored ICNF data. Overall score: ' . ($analysis['overall']['combined_score'] ?? 0) . ".\n";
