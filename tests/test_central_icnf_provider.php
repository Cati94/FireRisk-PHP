<?php

require_once __DIR__ . '/../core/IcnfActiveOccurrencesProvider.php';
require_once __DIR__ . '/../modules/fire_occurrences/Module.php';
require_once __DIR__ . '/../modules/weather/Module.php';
require_once __DIR__ . '/../modules/decision_support/DecisionSupportEngine.php';
require_once __DIR__ . '/../modules/propagation/PropagationEngine.php';
require_once __DIR__ . '/../modules/postfire_hazards/IcnfBurnedAreaRepository.php';

$config = require __DIR__ . '/../config.php';
$timezone = (string) ($config['timezone'] ?? 'Europe/Lisbon');
date_default_timezone_set($timezone);
$root = dirname(__DIR__);

function fail_provider(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function module_json(ModuleInterface $module, array $query): array
{
    ob_start();
    $module->handleApi($query);
    $json = ob_get_clean();
    $data = json_decode((string) $json, true);
    if (!is_array($data)) {
        fail_provider('Module did not emit valid JSON: ' . substr((string) $json, 0, 200));
    }
    return $data;
}

$provider = new IcnfActiveOccurrencesProvider($root, $timezone);
$base = $provider->test(['date' => '2026-02-24'], true);
if (($base['central_data_provider'] ?? false) !== true || ($base['provider'] ?? '') !== 'icnf_active_occurrences') {
    fail_provider('Central provider metadata is missing.');
}
if ((int) ($base['count'] ?? 0) !== 18) {
    fail_provider('Expected 18 provider records for 2026-02-24, got ' . (string) ($base['count'] ?? 'n/a') . '.');
}

$fire = module_json(new FireOccurrencesModule(['id' => 'fire_occurrences', 'name' => 'Fire Occurrences']), [
    'action' => 'test',
    'date' => '2026-02-24',
]);
if (($fire['source_module'] ?? '') !== 'icnf_active_occurrences' || ($fire['central_data_provider'] ?? false) !== true) {
    fail_provider('Fire Occurrences does not declare ICNF as central provider.');
}
if ((int) ($fire['count'] ?? 0) !== 18) {
    fail_provider('Fire Occurrences did not return the 18 ICNF records for 2026-02-24.');
}

$weather = module_json(new WeatherModule(['id' => 'weather', 'name' => 'Weather']), [
    'source' => 'test',
    'date' => '2026-02-24',
]);
if (($weather['source_module'] ?? '') !== 'icnf_active_occurrences' || ($weather['central_data_provider'] ?? false) !== true) {
    fail_provider('Weather does not use ICNF as central provider.');
}

$decision = (new DecisionSupportEngine($root, $timezone))->analyze([
    'source' => 'test',
    'date' => '2026-02-24',
]);
if (($decision['source_provider'] ?? '') !== 'icnf_active_occurrences' || ($decision['central_data_provider'] ?? false) !== true) {
    fail_provider('Decision Support does not use ICNF as central provider.');
}

$propagation = (new PropagationEngine($root, $timezone))->forecast([
    'source' => 'test',
    'date' => '2026-02-24',
]);
if (($propagation['source_provider'] ?? '') !== 'icnf_active_occurrences' || ($propagation['central_data_provider'] ?? false) !== true) {
    fail_provider('Propagation does not use ICNF as central provider.');
}

$postfire = (new IcnfBurnedAreaRepository($root, $timezone))->featureCollection([
    'source' => 'test',
    'reference_date' => '2026-02-24',
    'months' => 12,
]);
$meta = $postfire['meta'] ?? [];
if (($meta['source_provider'] ?? '') !== 'icnf_active_occurrences' || ($meta['central_data_provider'] ?? false) !== true) {
    fail_provider('Post-Fire Hazards does not use ICNF as central provider.');
}

fwrite(STDOUT, "OK: ICNF Ocorrências Activas is the central base data provider for Fire Occurrences, Weather, Decision Support, Propagation and Post-Fire Hazards.\n");
exit(0);
