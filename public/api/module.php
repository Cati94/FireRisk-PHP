<?php

require_once __DIR__ . '/../../core/ModuleManager.php';

$config = require __DIR__ . '/../../config.php';
date_default_timezone_set((string) ($config['timezone'] ?? 'Europe/Lisbon'));
$manager = new ModuleManager($config['modules_file'], dirname(__DIR__, 2));

$moduleId = isset($_GET['module']) ? strtolower(trim((string) $_GET['module'])) : '';

if (!preg_match('/^[a-z0-9_\-]+$/', $moduleId)) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error' => 'Invalid or missing module id.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

$module = $manager->getModuleById($moduleId);

if (!$module) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error' => 'Module not found or disabled.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

if (isset($_GET['page'])) {
    header('Content-Type: text/html; charset=utf-8');
    echo $module->renderPage($_GET);
    exit;
}

$module->handleApi($_GET);
