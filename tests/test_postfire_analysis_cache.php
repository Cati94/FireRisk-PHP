<?php

declare(strict_types=1);

require_once __DIR__ . '/../core/ModuleManager.php';

$root = dirname(__DIR__);
$manager = new ModuleManager($root . '/modules.xml', $root);
$module = $manager->getModuleById('postfire_hazards');

if (!$module) {
    fwrite(STDERR, "Post-fire module not found.\n");
    exit(1);
}

$cacheDir = $root . '/storage/cache/postfire_hazards/analysis';
if (is_dir($cacheDir)) {
    foreach (glob($cacheDir . '/*.json.gz') ?: [] as $file) {
        @unlink($file);
    }
}

$query = [
    'module' => 'postfire_hazards',
    'action' => 'scenario',
    'source' => 'test',
    'reference_date' => '2026-02-24',
    'months' => '12',
    'rain_1h_mm' => '28',
    'rain_24h_mm' => '72',
    'soil_saturation' => '0.68',
    'force' => '1',
];

ob_start();
$module->handleApi($query);
$firstRaw = ob_get_clean();
$first = json_decode($firstRaw, true);

if (!is_array($first) || ($first['success'] ?? false) !== true) {
    fwrite(STDERR, "Forced post-fire analysis failed.\n" . $firstRaw . "\n");
    exit(1);
}

if (($first['cache']['hit'] ?? true) !== false) {
    fwrite(STDERR, "Expected first forced run to rebuild cache.\n");
    exit(1);
}

$query['force'] = '0';
ob_start();
$module->handleApi($query);
$secondRaw = ob_get_clean();
$second = json_decode($secondRaw, true);

if (!is_array($second) || ($second['success'] ?? false) !== true) {
    fwrite(STDERR, "Cached post-fire analysis failed.\n" . $secondRaw . "\n");
    exit(1);
}

if (($second['cache']['hit'] ?? false) !== true) {
    fwrite(STDERR, "Expected second run to be served from analysis cache.\n");
    exit(1);
}

if (($second['count'] ?? 0) < 1) {
    fwrite(STDERR, "Cached analysis returned no burned areas.\n");
    exit(1);
}

ob_start();
$module->handleApi(['action' => 'cache_status']);
$statusRaw = ob_get_clean();
$status = json_decode($statusRaw, true);
if (($status['success'] ?? false) !== true || ($status['cache']['entries'] ?? 0) < 1) {
    fwrite(STDERR, "Cache status did not report any entries.\n" . $statusRaw . "\n");
    exit(1);
}

echo 'OK: Post-fire analysis cache rebuilt once and served the next request from cache. Areas: ' . $second['count'] . PHP_EOL;
