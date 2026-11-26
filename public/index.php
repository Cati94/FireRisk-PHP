<?php

require_once __DIR__ . '/../core/ModuleManager.php';

$config = require __DIR__ . '/../config.php';
date_default_timezone_set((string) ($config['timezone'] ?? 'Europe/Lisbon'));
$manager = new ModuleManager($config['modules_file'], dirname(__DIR__));
$modules = $manager->getEnabledModules();
$errors = $manager->getErrors();

$appName = htmlspecialchars((string) $config['app_name'], ENT_QUOTES, 'UTF-8');
$version = htmlspecialchars((string) $config['version'], ENT_QUOTES, 'UTF-8');
$today = htmlspecialchars((new DateTimeImmutable('now'))->format('Y-m-d'), ENT_QUOTES, 'UTF-8');
$timezone = htmlspecialchars((string) ($config['timezone'] ?? 'Europe/Lisbon'), ENT_QUOTES, 'UTF-8');

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function moduleKindLabel(ModuleInterface $module): string
{
    $type = $module->getType();
    if ($module->getLoadMode() === 'heavy_page') {
        return 'heavy page';
    }
    if ($module->getLayerConfig() !== null) {
        return 'map page';
    }
    return $type ?: 'page';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $appName ?> v<?= $version ?></title>
    <link href="/style.css" rel="stylesheet">
</head>
<body class="home-body no-spa-home">
    <main class="home-shell">
        <header class="home-hero">
            <div>
                <p class="eyebrow">FireRisk multi-page architecture</p>
                <h1>FireRisk</h1>
                <p class="home-lead">Each feature opens in its own PHP page. The home screen does not load module JavaScript, module CSS, dynamic panels, MapLibre, or automatic map layers.</p>
            </div>
            <div class="home-meta-card">
                <span class="version">v<?= $version ?></span>
                <strong><?= $today ?></strong>
                <small><?= $timezone ?></small>
            </div>
        </header>

        <?php if (!empty($errors)): ?>
            <section class="home-alert error">
                <strong>Module registry warnings</strong>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= h($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>

        <section class="home-grid">
            <?php foreach ($modules as $module): ?>
                <?php
                    $url = $module->getNavigationEndpoint() ?: ('/module.php?module=' . rawurlencode($module->getId()));
                    $kind = moduleKindLabel($module);
                    $hasMap = $module->getLayerConfig() !== null ? 'yes' : 'optional';
                ?>
                <article class="home-module-card <?= $module->getLoadMode() === 'heavy_page' ? 'heavy' : '' ?>">
                    <div class="home-module-card-header">
                        <h2><?= h($module->getName()) ?></h2>
                        <span class="module-type"><?= h($kind) ?></span>
                    </div>
                    <p><?= h($module->getDescription()) ?></p>
                    <dl class="home-module-facts">
                        <div><dt>Relevance</dt><dd><?= (int) $module->getRelevance() ?></dd></div>
                        <div><dt>Map</dt><dd><?= h($hasMap) ?></dd></div>
                        <div><dt>ID</dt><dd><?= h($module->getId()) ?></dd></div>
                    </dl>
                    <a class="home-open-button" href="<?= h($url) ?>">Open module</a>
                </article>
            <?php endforeach; ?>
        </section>

        <footer class="home-footer">
            <p><strong>No SPA rule:</strong> this page is only a server-rendered module launcher. Module code runs only after opening a module page.</p>
            <p>APIs remain available under <code>/api/module.php</code> for the module pages and tests.</p>
        </footer>
    </main>
</body>
</html>
