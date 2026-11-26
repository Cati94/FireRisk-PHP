<?php

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../core/ModuleManager.php';

$config = require __DIR__ . '/../../config.php';
date_default_timezone_set((string) ($config['timezone'] ?? 'Europe/Lisbon'));
$manager = new ModuleManager($config['modules_file'], dirname(__DIR__, 2));

$modules = [];

foreach ($manager->getEnabledModules() as $module) {
    $modules[] = [
        'id' => $module->getId(),
        'name' => $module->getName(),
        'type' => $module->getType(),
        'relevance' => $module->getRelevance(),
        'description' => $module->getDescription(),
        'load_mode' => $module->getLoadMode(),
        'navigation' => $module->getNavigationEndpoint(),
        'assets' => $module->getLoadMode() === 'heavy_page' ? ['js' => [], 'css' => []] : $module->getAssets(),
        'api' => $module->getApiEndpoint(),
        'page' => $module->getPageEndpoint(),
        'layer' => $module->getLayerConfig(),
    ];
}

echo json_encode([
    'success' => true,
    'modules' => $modules,
    'errors' => $manager->getErrors(),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
