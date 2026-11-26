<?php

$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../core/ModuleManager.php';

$manager = new ModuleManager($config['modules_file'], dirname(__DIR__));

$moduleId = isset($_GET['module']) ? strtolower(trim((string) $_GET['module'])) : '';
$file = isset($_GET['file']) ? basename((string) $_GET['file']) : '';

if (!preg_match('/^[a-z0-9_\-]+$/', $moduleId) || !preg_match('/^[a-zA-Z0-9_.\-]+$/', $file)) {
    http_response_code(400);
    echo 'Invalid asset request.';
    exit;
}

$module = $manager->getModuleById($moduleId);

if (!$module) {
    http_response_code(404);
    echo 'Module not found or disabled.';
    exit;
}

$path = dirname(__DIR__) . '/modules/' . $moduleId . '/' . $file;
$realPath = realpath($path);
$moduleDir = realpath(dirname(__DIR__) . '/modules/' . $moduleId);

if (!$realPath || !$moduleDir || !str_starts_with($realPath, $moduleDir) || !is_file($realPath)) {
    http_response_code(404);
    echo 'Asset not found.';
    exit;
}

$extension = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
$contentTypes = [
    'js' => 'application/javascript; charset=utf-8',
    'css' => 'text/css; charset=utf-8',
    'json' => 'application/json; charset=utf-8',
];

header('Content-Type: ' . ($contentTypes[$extension] ?? 'text/plain; charset=utf-8'));
header('Cache-Control: no-store');
readfile($realPath);
