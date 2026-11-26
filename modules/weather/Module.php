<?php

require_once __DIR__ . '/../../core/AbstractModule.php';
require_once __DIR__ . '/../../core/IcnfActiveOccurrencesProvider.php';

class WeatherModule extends AbstractModule
{
    public function getAssets(): array
    {
        return [
            'js' => ['/module-asset.php?module=weather&file=layer.js'],
            'css' => ['/module-asset.php?module=weather&file=style.css'],
        ];
    }

    public function getApiEndpoint(): ?string
    {
        return '/api/module.php?module=weather';
    }

    public function getPageEndpoint(): ?string
    {
        return '/api/module.php?module=weather&page=1';
    }

    public function getLayerConfig(): ?array
    {
        return [
            'id' => $this->getId(),
            'type' => 'weather-overlay',
            'api' => $this->getApiEndpoint(),
            'enabled' => true,
            'source_module' => 'icnf_active_occurrences',
            'central_data_provider' => true,
        ];
    }

    public function handleApi(array $query): void
    {
        $provider = $this->provider();
        $icnf = $provider->data($query);
        $weather = $this->weatherFromIcnf($icnf['data'] ?? []);

        if ($weather === null) {
            $weather = $this->readJsonFile(__DIR__ . '/../../data/weather.json');
            $weather['source_mode'] = 'legacy-placeholder-fallback';
            $weather['risk_note'] = ($weather['risk_note'] ?? 'No operational weather note available.') . ' No current-day ICNF weather fields were available.';
        }

        $this->jsonResponse([
            'success' => true,
            'module' => $this->getId(),
            'provider' => 'icnf_active_occurrences',
            'source_module' => 'icnf_active_occurrences',
            'central_data_provider' => true,
            'date_filter_enabled' => $icnf['date_filter_enabled'] ?? true,
            'filter_date' => $icnf['filter_date'] ?? null,
            'timezone' => $icnf['timezone'] ?? $this->timezoneName(),
            'icnf_count' => $icnf['count'] ?? 0,
            'data' => $weather,
        ]);
    }

    public function renderPage(array $query): string
    {
        return <<<HTML
<section class="module-page weather-page">
    <h2>Weather</h2>
    <p>Operational weather is now derived from the central ICNF occurrence dataset when ICNF weather fields are available. External weather providers can still be added later.</p>
    <div id="weather-summary" class="module-list">Loading ICNF-derived weather...</div>
</section>
HTML;
    }

    private function weatherFromIcnf(array $items): ?array
    {
        $temps = [];
        $rhs = [];
        $winds = [];
        $precip = [];
        $directions = [];

        foreach ($items as $item) {
            if (!is_array($item) || !is_array($item['weather'] ?? null)) {
                continue;
            }
            $w = $item['weather'];
            foreach ([['temperature_c', &$temps], ['relative_humidity', &$rhs], ['wind_intensity', &$winds], ['precipitation', &$precip], ['wind_direction_vector', &$directions]] as [$key, &$bucket]) {
                if (isset($w[$key]) && is_numeric($w[$key])) {
                    $bucket[] = (float) $w[$key];
                }
            }
            unset($bucket);
        }

        if (count($temps) + count($rhs) + count($winds) === 0) {
            return null;
        }

        $avgDirection = count($directions) > 0 ? $this->average($directions) : 0.0;
        return [
            'station' => 'ICNF occurrence weather aggregate',
            'observed_at' => (new DateTimeImmutable('now', new DateTimeZone($this->timezoneName())))->format(DateTimeInterface::ATOM),
            'temperature_c' => $this->roundOrNull($this->averageNullable($temps)),
            'relative_humidity_percent' => $this->roundOrNull($this->averageNullable($rhs)),
            'wind_speed_kmh' => $this->roundOrNull($this->averageNullable($winds)),
            'wind_direction_deg' => round($avgDirection, 1),
            'wind_direction_label' => count($directions) > 0 ? $this->directionLabel($avgDirection) : 'n/a',
            'precipitation_mm' => $this->roundOrNull($this->averageNullable($precip)),
            'source_mode' => 'icnf-active-occurrences-weather-fields',
            'record_count' => count($items),
            'risk_note' => 'Derived from weather fields embedded in the current ICNF occurrence records. Use dedicated weather providers later for real station/forecast precision.',
        ];
    }

    private function averageNullable(array $values): ?float
    {
        return count($values) > 0 ? $this->average($values) : null;
    }

    private function average(array $values): float
    {
        return array_sum($values) / max(1, count($values));
    }

    private function roundOrNull(?float $value): ?float
    {
        return $value === null ? null : round($value, 1);
    }

    private function directionLabel(float $degrees): string
    {
        $labels = ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'];
        $index = (int) round(fmod(($degrees + 360.0), 360.0) / 45.0) % 8;
        return $labels[$index];
    }

    private function provider(): IcnfActiveOccurrencesProvider
    {
        return new IcnfActiveOccurrencesProvider(dirname(__DIR__, 2), $this->timezoneName());
    }

    private function timezoneName(): string
    {
        $configFile = dirname(__DIR__, 2) . '/config.php';
        $config = file_exists($configFile) ? require $configFile : [];
        $timezone = is_array($config) ? (string) ($config['timezone'] ?? 'Europe/Lisbon') : 'Europe/Lisbon';
        return $timezone !== '' ? $timezone : 'Europe/Lisbon';
    }
}
