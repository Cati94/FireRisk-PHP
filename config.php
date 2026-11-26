<?php

return [
    'app_name' => 'FireRisk PHP',
    'version' => '0.7.2',
    'map_center' => [
        'lat' => 39.5,
        'lng' => -8.0,
    ],
    'map_zoom' => 6,
    'maplibre_assets' => [
        'version' => '5.24.0',
        'css' => '/vendor/maplibre-gl/5.24.0/maplibre-gl.css',
        'js' => '/vendor/maplibre-gl/5.24.0/maplibre-gl.js',
    ],
    'modules_file' => __DIR__ . '/modules.xml',
    'timezone' => 'Europe/Lisbon',
];
