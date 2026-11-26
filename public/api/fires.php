<?php

// Backwards-compatible endpoint from v0.1.
// It now delegates to the Fire Occurrences module, which uses ICNF Ocorrências Activas data.

require_once __DIR__ . '/../../modules/fire_occurrences/Module.php';

$config = require __DIR__ . '/../../config.php';
date_default_timezone_set((string) ($config['timezone'] ?? 'Europe/Lisbon'));

$module = new FireOccurrencesModule([
    'id' => 'fire_occurrences',
    'name' => 'Fire Occurrences',
    'type' => 'hybrid',
    'load_mode' => 'page',
    'relevance' => 100,
    'description' => 'Displays active fire occurrences from ICNF Ocorrências Activas.',
]);

$module->handleApi($_GET);
