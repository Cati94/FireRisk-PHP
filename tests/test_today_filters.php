<?php

require_once __DIR__ . '/../core/FireRepository.php';
require_once __DIR__ . '/../modules/icnf_active_occurrences/Module.php';

$config = require __DIR__ . '/../config.php';
$timezone = (string) ($config['timezone'] ?? 'Europe/Lisbon');
date_default_timezone_set($timezone);

$repo = new FireRepository(__DIR__ . '/../data/fires.json');
$today = $repo->today($timezone);
$fires = $repo->getForDate($today, $timezone);

foreach ($fires as $fire) {
    $date = $repo->normalizeDate((string) ($fire['started_at'] ?? ''), $timezone);
    if ($date !== $today) {
        fwrite(STDERR, "FAIL: Local fire occurrence outside today's date filter.\n");
        exit(1);
    }
}

$module = new IcnfActiveOccurrencesModule([
    'id' => 'icnf_active_occurrences',
    'name' => 'ICNF Ocorrências Activas',
    'type' => 'hybrid',
    'relevance' => 98,
    'description' => 'ICNF active occurrences.',
]);

ob_start();
$module->handleApi([
    'action' => 'test',
    'date' => '2026-02-24',
]);
$json = ob_get_clean();
$result = json_decode((string) $json, true);

if (!is_array($result) || ($result['success'] ?? false) !== true) {
    fwrite(STDERR, "FAIL: ICNF date-filtered test endpoint did not return success.\n");
    exit(1);
}

if (($result['filter_date'] ?? '') !== '2026-02-24') {
    fwrite(STDERR, "FAIL: ICNF date filter did not normalize to 2026-02-24.\n");
    exit(1);
}

if ((int) ($result['count'] ?? 0) <= 0) {
    fwrite(STDERR, "FAIL: Expected standard ICNF fixture to contain records for 2026-02-24.\n");
    exit(1);
}

foreach (($result['data'] ?? []) as $item) {
    $date = $item['started_at'] ?? $item['data_alerta'] ?? '';
    if (!str_starts_with((string) $date, '2026-02-24') && !str_starts_with((string) ($item['data_alerta'] ?? ''), '24-02-2026')) {
        fwrite(STDERR, "FAIL: ICNF item outside requested date filter.\n");
        exit(1);
    }
}

fwrite(STDOUT, "OK: Local and ICNF APIs apply today's/date filters correctly. ICNF fixture returned {$result['count']} records for 2026-02-24.\n");
exit(0);
