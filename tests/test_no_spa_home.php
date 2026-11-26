<?php

$root = dirname(__DIR__);
$index = file_get_contents($root . '/public/index.php');
$modulePage = file_get_contents($root . '/public/module.php');
$modulesXml = file_get_contents($root . '/modules.xml');

if (file_exists($root . '/public/app.js')) {
    fwrite(STDERR, "FAIL: public/app.js should not exist in the no-SPA architecture.\n");
    exit(1);
}

$forbiddenHomeFragments = [
    '/app.js',
    'maplibre-gl.js',
    'id="map"',
    'id="module-panel"',
    'id="module-menu"',
    'fetch(\'/api/modules.php\'',
    'fetch("/api/modules.php"',
];

foreach ($forbiddenHomeFragments as $fragment) {
    if (strpos($index, $fragment) !== false) {
        fwrite(STDERR, "FAIL: public/index.php still contains SPA fragment: {$fragment}\n");
        exit(1);
    }
}

$requiredHomeFragments = [
    'ModuleManager.php',
    'getEnabledModules()',
    '/module.php?module=',
    'No SPA rule',
];

foreach ($requiredHomeFragments as $fragment) {
    if (strpos($index, $fragment) === false) {
        fwrite(STDERR, "FAIL: public/index.php is missing multi-page fragment: {$fragment}\n");
        exit(1);
    }
}

if (strpos($modulePage, 'maplibre-gl.js') === false || strpos($modulePage, '/module-page.js') === false) {
    fwrite(STDERR, "FAIL: public/module.php must own module page assets and map bootstrapping.\n");
    exit(1);
}

foreach (['fire_occurrences', 'icnf_active_occurrences', 'decision_support', 'propagation', 'weather', 'slow_data_fetching'] as $id) {
    $pattern = '/<module id="' . preg_quote($id, '/') . '">.*?<load_mode>page<\/load_mode>/s';
    if (!preg_match($pattern, $modulesXml)) {
        fwrite(STDERR, "FAIL: {$id} must declare <load_mode>page</load_mode>.\n");
        exit(1);
    }
}

if (!preg_match('/<module id="postfire_hazards">.*?<load_mode>heavy_page<\/load_mode>/s', $modulesXml)) {
    fwrite(STDERR, "FAIL: postfire_hazards must remain heavy_page.\n");
    exit(1);
}

echo "OK: Home page is server-rendered and no longer loads SPA module logic.\n";
