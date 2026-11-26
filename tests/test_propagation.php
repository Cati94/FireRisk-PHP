<?php

require_once __DIR__ . '/../modules/propagation/PropagationEngine.php';

$config = require __DIR__ . '/../config.php';
$timezone = (string) ($config['timezone'] ?? 'Europe/Lisbon');
date_default_timezone_set($timezone);

$engine = new PropagationEngine(dirname(__DIR__), $timezone);
$result = $engine->geoJson([
    'source' => 'test',
    'date' => '2026-02-24',
]);

if (!is_array($result) || ($result['success'] ?? false) !== true) {
    fwrite(STDERR, "FAIL: Propagation forecast did not return success.\n");
    exit(1);
}

if (($result['filter_date'] ?? '') !== '2026-02-24') {
    fwrite(STDERR, "FAIL: Propagation date filter did not normalize to 2026-02-24.\n");
    exit(1);
}

$projections = $result['projections'] ?? [];
if (!is_array($projections) || count($projections) <= 0) {
    fwrite(STDERR, "FAIL: Expected propagation projections for the standard fixture date.\n");
    exit(1);
}

foreach ($projections as $projection) {
    if (!isset($projection['spread']['rate_m_per_h'], $projection['risk_level'], $projection['horizons'])) {
        fwrite(STDERR, "FAIL: Propagation projection is missing expected fields.\n");
        exit(1);
    }

    if ((float) $projection['spread']['rate_m_per_h'] <= 0) {
        fwrite(STDERR, "FAIL: Propagation spread rate must be positive.\n");
        exit(1);
    }

    if (!is_array($projection['horizons']) || count($projection['horizons']) !== 3) {
        fwrite(STDERR, "FAIL: Expected exactly three default propagation horizons.\n");
        exit(1);
    }

    foreach ($projection['horizons'] as $horizon) {
        if (!isset($horizon['polygon']) || !is_array($horizon['polygon']) || count($horizon['polygon']) < 4) {
            fwrite(STDERR, "FAIL: Propagation horizon does not contain a valid polygon.\n");
            exit(1);
        }
    }
}

$features = $result['geojson']['features'] ?? [];
if (!is_array($features) || count($features) !== count($projections) * 3) {
    fwrite(STDERR, "FAIL: GeoJSON feature count does not match projection horizons.\n");
    exit(1);
}

fwrite(STDOUT, "OK: Propagation generated " . count($projections) . " projections and " . count($features) . " GeoJSON polygons for 2026-02-24.\n");
exit(0);
