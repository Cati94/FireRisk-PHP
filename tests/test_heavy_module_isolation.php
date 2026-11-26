<?php

declare(strict_types=1);

require_once __DIR__ . '/../core/ModuleManager.php';

$config = require __DIR__ . '/../config.php';
date_default_timezone_set((string)($config['timezone'] ?? 'Europe/Lisbon'));
$manager = new ModuleManager($config['modules_file'], dirname(__DIR__));
$module = $manager->getModuleById('postfire_hazards');

if (!$module) {
    fwrite(STDERR, "Post-fire module not found.\n");
    exit(1);
}

if ($module->getLoadMode() !== 'heavy_page') {
    fwrite(STDERR, "Expected post-fire load_mode heavy_page, got {$module->getLoadMode()}.\n");
    exit(1);
}

if ($module->getLayerConfig() !== null) {
    fwrite(STDERR, "Post-fire module must not auto-register a main-map layer.\n");
    exit(1);
}

if ($module->getNavigationEndpoint() !== '/module.php?module=postfire_hazards') {
    fwrite(STDERR, "Unexpected post-fire navigation endpoint.\n");
    exit(1);
}

$page = file_get_contents(__DIR__ . '/../public/module.php');
if ($page === false || strpos($page, 'FIRERISK_STANDALONE_MODULE') === false) {
    fwrite(STDERR, "Standalone module page entrypoint is missing required bootstrap metadata.\n");
    exit(1);
}

echo "OK: Heavy post-fire module is isolated from the main SPA and opens through a standalone workspace.\n";
