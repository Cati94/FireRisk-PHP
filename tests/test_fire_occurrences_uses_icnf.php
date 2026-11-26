<?php

declare(strict_types=1);

require_once __DIR__ . '/../modules/fire_occurrences/Module.php';

$config = require __DIR__ . '/../config.php';
date_default_timezone_set((string) ($config['timezone'] ?? 'Europe/Lisbon'));

$module = new FireOccurrencesModule([
    'id' => 'fire_occurrences',
    'name' => 'Fire Occurrences',
    'type' => 'hybrid',
    'load_mode' => 'page',
    'relevance' => 100,
    'description' => 'Fire occurrences from ICNF.',
]);

ob_start();
$module->handleApi([
    'action' => 'test',
    'date' => '2026-02-24',
]);
$json = ob_get_clean();
$result = json_decode((string) $json, true);

if (!is_array($result) || ($result['success'] ?? false) !== true) {
    fwrite(STDERR, "FAIL: Fire Occurrences ICNF-backed test endpoint did not return success.\n");
    exit(1);
}

if (($result['source_module'] ?? '') !== 'icnf_active_occurrences') {
    fwrite(STDERR, "FAIL: Fire Occurrences is not using ICNF Ocorrências Activas as source module.\n");
    exit(1);
}

if (($result['filter_date'] ?? '') !== '2026-02-24') {
    fwrite(STDERR, "FAIL: Fire Occurrences date override did not normalize correctly.\n");
    exit(1);
}

if ((int) ($result['count'] ?? 0) <= 0) {
    fwrite(STDERR, "FAIL: Expected ICNF fixture records for Fire Occurrences on 2026-02-24.\n");
    exit(1);
}

$first = $result['data'][0] ?? null;
if (!is_array($first)) {
    fwrite(STDERR, "FAIL: First Fire Occurrence record is missing.\n");
    exit(1);
}

foreach (['id', 'codigo', 'name', 'latitude', 'longitude', 'severity', 'started_at', 'source', 'source_label', 'icnf'] as $field) {
    if (!array_key_exists($field, $first)) {
        fwrite(STDERR, "FAIL: Fire Occurrence ICNF-mapped record missing field {$field}.\n");
        exit(1);
    }
}

if (($first['source'] ?? '') !== 'icnf_active_occurrences') {
    fwrite(STDERR, "FAIL: Fire Occurrence record source is not ICNF.\n");
    exit(1);
}

foreach (($result['data'] ?? []) as $fire) {
    if (($fire['source'] ?? '') !== 'icnf_active_occurrences') {
        fwrite(STDERR, "FAIL: Non-ICNF fire record found in Fire Occurrences.\n");
        exit(1);
    }

    if (!str_starts_with((string) ($fire['started_at'] ?? ''), '2026-02-24')) {
        fwrite(STDERR, "FAIL: Fire Occurrence outside requested date filter.\n");
        exit(1);
    }
}

echo "OK: Fire Occurrences uses ICNF Ocorrências Activas data and returned {$result['count']} records for 2026-02-24.\n";
exit(0);
