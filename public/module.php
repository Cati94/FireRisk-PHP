<?php

require_once __DIR__ . '/../core/ModuleManager.php';

$config = require __DIR__ . '/../config.php';
date_default_timezone_set((string) ($config['timezone'] ?? 'Europe/Lisbon'));
$manager = new ModuleManager($config['modules_file'], dirname(__DIR__));

$moduleId = isset($_GET['module']) ? strtolower(trim((string) $_GET['module'])) : '';

if (!preg_match('/^[a-z0-9_\-]+$/', $moduleId)) {
    http_response_code(400);
    echo 'Invalid or missing module id.';
    exit;
}

$module = $manager->getModuleById($moduleId);

if (!$module) {
    http_response_code(404);
    echo 'Module not found or disabled.';
    exit;
}

$appName = htmlspecialchars((string) $config['app_name'], ENT_QUOTES, 'UTF-8');
$version = htmlspecialchars((string) $config['version'], ENT_QUOTES, 'UTF-8');
$moduleName = htmlspecialchars($module->getName(), ENT_QUOTES, 'UTF-8');
$moduleDescription = htmlspecialchars($module->getDescription(), ENT_QUOTES, 'UTF-8');
$hasMap = $module->getLayerConfig() !== null || in_array($module->getType(), ['hybrid', 'layer', 'heavy_page'], true);
$centerLng = (float) $config['map_center']['lng'];
$centerLat = (float) $config['map_center']['lat'];
$zoom = (float) $config['map_zoom'];
$mapLibreAssets = is_array($config['maplibre_assets'] ?? null)
    ? $config['maplibre_assets']
    : [];
$mapLibreCss = (string) ($mapLibreAssets['css'] ?? '/vendor/maplibre-gl/5.24.0/maplibre-gl.css');
$mapLibreJs = (string) ($mapLibreAssets['js'] ?? '/vendor/maplibre-gl/5.24.0/maplibre-gl.js');
$mapLibreVersion = (string) ($mapLibreAssets['version'] ?? '5.24.0');
$assets = $module->getAssets();
$moduleMeta = [
    'id' => $module->getId(),
    'name' => $module->getName(),
    'type' => $module->getType(),
    'load_mode' => $module->getLoadMode(),
    'relevance' => $module->getRelevance(),
    'description' => $module->getDescription(),
    'api' => $module->getApiEndpoint(),
    'page' => $module->getPageEndpoint(),
    'layer' => $module->getLayerConfig(),
    'has_map' => $hasMap,
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $moduleName ?> · <?= $appName ?> v<?= $version ?></title>
    <?php if ($hasMap): ?>
        <link href="<?= htmlspecialchars($mapLibreCss, ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet">
    <?php endif; ?>
    <link href="/style.css" rel="stylesheet">
    <link href="/module-page.css" rel="stylesheet">
    <?php foreach (($assets['css'] ?? []) as $href): ?>
        <link href="<?= htmlspecialchars((string) $href, ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet">
    <?php endforeach; ?>
</head>
<body class="module-workspace-body <?= $hasMap ? '' : 'module-workspace-no-map' ?>">
    <header class="module-workspace-header">
        <div>
            <a href="/" class="module-back-link">← FireRisk home</a>
            <h1><?= $moduleName ?></h1>
            <p><?= $moduleDescription ?></p>
        </div>
        <span class="version">v<?= $version ?></span>
    </header>

    <main class="module-workspace-layout">
        <section id="module-panel" class="module-workspace-panel">
            <?= $module->renderPage($_GET) ?>
        </section>
        <?php if ($hasMap): ?>
            <section id="map" class="module-workspace-map" aria-label="Module map"></section>
        <?php endif; ?>
    </main>

    <div id="status-bar">Opening <?= $moduleName ?>...</div>

    <script>
        window.FIRERISK_CONFIG = {
            center: [<?= $centerLng ?>, <?= $centerLat ?>],
            zoom: <?= $zoom ?>,
            mapLibreVersion: <?= json_encode($mapLibreVersion, JSON_UNESCAPED_SLASHES) ?>,
            version: '<?= $version ?>'
        };
        window.FIRERISK_STANDALONE_MODULE = <?= json_encode($moduleMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    </script>
    <?php if ($hasMap): ?>
        <script src="<?= htmlspecialchars($mapLibreJs, ENT_QUOTES, 'UTF-8') ?>"></script>
    <?php endif; ?>
    <?php foreach (($assets['js'] ?? []) as $src): ?>
        <script src="<?= htmlspecialchars((string) $src, ENT_QUOTES, 'UTF-8') ?>"></script>
    <?php endforeach; ?>
    <script src="/module-page.js"></script>
</body>
</html>
