<?php

require_once __DIR__ . '/../modules/decision_support/DecisionSupportEngine.php';

$config = require __DIR__ . '/../config.php';
$timezone = (string) ($config['timezone'] ?? 'Europe/Lisbon');
date_default_timezone_set($timezone);

$engine = new DecisionSupportEngine(dirname(__DIR__), $timezone);
$result = $engine->analyze([
    'source' => 'test',
    'date' => '2026-02-24',
]);

if (!is_array($result) || ($result['success'] ?? false) !== true) {
    fwrite(STDERR, "FAIL: Decision support analysis did not return success.\n");
    exit(1);
}

if (($result['filter_date'] ?? '') !== '2026-02-24') {
    fwrite(STDERR, "FAIL: Decision support date filter did not normalize to 2026-02-24.\n");
    exit(1);
}

if ((int) ($result['count'] ?? 0) <= 0) {
    fwrite(STDERR, "FAIL: Expected standard ICNF fixture to return current-date test records.\n");
    exit(1);
}

$priorities = $result['priorities'] ?? [];
if (!is_array($priorities) || count($priorities) === 0) {
    fwrite(STDERR, "FAIL: Decision support did not produce ranked priorities.\n");
    exit(1);
}

$lastScore = 101;
foreach ($priorities as $priority) {
    $score = (int) ($priority['risk_score'] ?? -1);
    if ($score < 0 || $score > 100) {
        fwrite(STDERR, "FAIL: Risk score is outside 0-100 range.\n");
        exit(1);
    }

    if ($score > $lastScore) {
        fwrite(STDERR, "FAIL: Priorities are not sorted by descending risk score.\n");
        exit(1);
    }

    if (!isset($priority['risk_level'], $priority['reasons'], $priority['suggested_actions'])) {
        fwrite(STDERR, "FAIL: Priority item is missing expected fields.\n");
        exit(1);
    }

    $lastScore = $score;
}

fwrite(STDOUT, "OK: Decision support ranked {$result['count']} ICNF records for 2026-02-24. Top score: {$priorities[0]['risk_score']}.\n");
exit(0);
