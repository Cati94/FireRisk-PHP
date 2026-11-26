<?php

require_once __DIR__ . '/../modules/slow_data_fetching/Fetcher.php';

$basePath = dirname(__DIR__);
$fetcher = new SlowDataFetcher(
    $basePath,
    $basePath . '/modules/slow_data_fetching',
    $basePath . '/storage/cache/slow_data_fetching'
);

$result = $fetcher->fetchSource('icnf_active_occurrences_test', true);
$data = $result['data'] ?? [];
$count = (int) ($data['count'] ?? 0);
$root = (string) ($data['root'] ?? '');
$recordTag = (string) ($data['record_tag'] ?? '');
$items = $data['items'] ?? [];

if ($count <= 0 || !is_array($items) || count($items) <= 0) {
    fwrite(STDERR, "FAIL: Standard ICNF fixture parsed zero records.\n");
    exit(1);
}

if ($root !== 'INCENDIOSRURAIS') {
    fwrite(STDERR, "FAIL: Expected root INCENDIOSRURAIS, got {$root}.\n");
    exit(1);
}

if ($recordTag !== 'CODIGO') {
    fwrite(STDERR, "FAIL: Expected record tag CODIGO, got {$recordTag}.\n");
    exit(1);
}

$first = $items[0];
foreach (['id', 'codigo', 'latitude', 'longitude', 'district', 'municipality'] as $field) {
    if (!array_key_exists($field, $first)) {
        fwrite(STDERR, "FAIL: First parsed item is missing field {$field}.\n");
        exit(1);
    }
}

fwrite(STDOUT, "OK: Standard ICNF fixture parsed {$count} CODIGO records from {$root}.\n");
exit(0);
