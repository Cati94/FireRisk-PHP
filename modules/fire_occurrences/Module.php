<?php

require_once __DIR__ . '/../../core/AbstractModule.php';
require_once __DIR__ . '/../../core/IcnfActiveOccurrencesProvider.php';

class FireOccurrencesModule extends AbstractModule
{
    public function getAssets(): array
    {
        return [
            'js' => ['/module-asset.php?module=fire_occurrences&file=layer.js'],
            'css' => ['/module-asset.php?module=fire_occurrences&file=style.css'],
        ];
    }

    public function getApiEndpoint(): ?string
    {
        return '/api/module.php?module=fire_occurrences';
    }

    public function getPageEndpoint(): ?string
    {
        return '/api/module.php?module=fire_occurrences&page=1';
    }

    public function getLayerConfig(): ?array
    {
        return [
            'id' => $this->getId(),
            'type' => 'marker-layer',
            'api' => $this->getApiEndpoint(),
            'enabled' => true,
            'source_module' => 'icnf_active_occurrences',
            'central_data_provider' => true,
            'date_filter' => 'today',
        ];
    }

    public function handleApi(array $query): void
    {
        $action = strtolower(trim((string) ($query['action'] ?? 'data')));
        $provider = $this->provider();

        try {
            switch ($action) {
                case 'data':
                    $this->jsonResponse($this->formatFireResult($provider->data($query), 'icnf-central-provider'));
                    return;

                case 'refresh':
                    $this->jsonResponse($this->formatFireResult($provider->refresh($query), 'icnf-refresh'));
                    return;

                case 'test':
                    $this->jsonResponse($this->formatFireResult($provider->test($query, true), 'standard-test-xml'));
                    return;

                case 'demo':
                    $this->jsonResponse($this->formatFireResult($provider->demo($query, true), 'reduced-demo-xml'));
                    return;

                case 'geojson':
                    $result = $this->formatFireResult($provider->data($query), 'icnf-central-provider');
                    $this->jsonResponse([
                        'success' => $result['success'],
                        'module' => $this->getId(),
                        'source_module' => 'icnf_active_occurrences',
                        'central_data_provider' => true,
                        'source_mode' => $result['source_mode'] ?? null,
                        'date_filter_enabled' => $result['date_filter_enabled'] ?? true,
                        'filter_date' => $result['filter_date'] ?? null,
                        'timezone' => $result['timezone'] ?? $this->timezoneName(),
                        'total_count' => $result['total_count'] ?? null,
                        'count' => $result['count'] ?? null,
                        'geojson' => $this->toGeoJson($result['data'] ?? []),
                    ]);
                    return;

                case 'status':
                    $status = $provider->status($query);
                    $status['module'] = $this->getId();
                    $status['source_module'] = 'icnf_active_occurrences';
                    $this->jsonResponse($status);
                    return;

                default:
                    $this->jsonResponse([
                        'success' => false,
                        'error' => 'Unknown action: ' . $action,
                        'valid_actions' => ['data', 'refresh', 'test', 'demo', 'geojson', 'status'],
                    ], 400);
                    return;
            }
        } catch (Throwable $error) {
            $this->jsonResponse([
                'success' => false,
                'module' => $this->getId(),
                'source_module' => 'icnf_active_occurrences',
                'central_data_provider' => true,
                'error' => $error->getMessage(),
            ], 500);
        }
    }

    public function renderPage(array $query): string
    {
        $today = htmlspecialchars($this->provider()->resolveTargetDate($query), ENT_QUOTES, 'UTF-8');
        return <<<HTML
<section class="module-page fire-occurrences-page">
    <h2>Fire Occurrences</h2>
    <p>This operational occurrence view is only a presentation of the central <strong>ICNF Ocorrências Activas</strong> dataset. It does not own independent base data.</p>
    <p class="fire-date-rule"><strong>Active date filter:</strong> {$today}</p>

    <div class="fire-toolbar">
        <button id="fire-load-cache" class="small-button primary" type="button">Load today's ICNF-derived fires</button>
        <button id="fire-refresh-icnf" class="small-button" type="button">Refresh ICNF source</button>
        <button id="fire-load-test" class="small-button" type="button">Load standard ICNF test XML</button>
    </div>

    <div id="fire-message" class="fire-message">Loading ICNF-derived fire occurrences...</div>
    <div id="fire-summary" class="fire-summary"></div>
    <div id="fire-occurrences-list" class="module-list">Loading fire occurrences...</div>
</section>
HTML;
    }

    private function formatFireResult(array $icnfResult, string $mode): array
    {
        $items = array_values(array_filter($icnfResult['data'] ?? [], 'is_array'));
        $fires = array_map(fn (array $item): array => $this->mapIcnfOccurrenceToFire($item), $items);

        return [
            'success' => ($icnfResult['success'] ?? false) === true,
            'module' => $this->getId(),
            'provider' => 'icnf_active_occurrences',
            'source_module' => 'icnf_active_occurrences',
            'central_data_provider' => true,
            'source' => $icnfResult['source'] ?? null,
            'source_mode' => $icnfResult['source_mode'] ?? $mode,
            'date_filter_enabled' => $icnfResult['date_filter_enabled'] ?? true,
            'filter_date' => $icnfResult['filter_date'] ?? null,
            'timezone' => $icnfResult['timezone'] ?? $this->timezoneName(),
            'cache' => $icnfResult['cache'] ?? null,
            'total_count' => $icnfResult['total_count'] ?? count($items),
            'count' => count($fires),
            'data' => $fires,
            'raw_icnf_count' => count($items),
            'message' => $icnfResult['message'] ?? null,
            'warning' => $icnfResult['warning'] ?? null,
            'error' => $icnfResult['error'] ?? null,
        ];
    }

    private function mapIcnfOccurrenceToFire(array $item): array
    {
        $area = isset($item['area']) && is_array($item['area']) ? $item['area'] : [];
        $indices = isset($item['fire_weather_indices']) && is_array($item['fire_weather_indices']) ? $item['fire_weather_indices'] : [];
        $weather = isset($item['weather']) && is_array($item['weather']) ? $item['weather'] : [];

        $codigo = (string) ($item['codigo'] ?? $item['ncco'] ?? $item['id'] ?? '');
        $name = (string) ($item['name'] ?? '');
        if ($name === '') {
            $local = (string) ($item['local'] ?? '');
            $municipality = (string) ($item['municipality'] ?? '');
            $tipo = (string) ($item['tipo'] ?? '');
            $name = trim(($tipo !== '' ? $tipo . ' - ' : '') . ($local !== '' ? $local : $municipality));
        }

        return [
            'id' => 'icnf-' . (string) ($item['id'] ?? $codigo),
            'codigo' => $codigo,
            'name' => $name !== '' ? $name : 'ICNF fire occurrence',
            'latitude' => (float) ($item['latitude'] ?? 0),
            'longitude' => (float) ($item['longitude'] ?? 0),
            'status' => (string) ($item['status'] ?? 'active'),
            'severity' => $this->normalizeSeverity((string) ($item['severity'] ?? 'low')),
            'started_at' => (string) ($item['started_at'] ?? $item['dh_inicio'] ?? ''),
            'ended_at' => (string) ($item['ended_at'] ?? $item['dh_fim'] ?? ''),
            'type' => (string) ($item['tipo'] ?? ''),
            'district' => (string) ($item['district'] ?? ''),
            'municipality' => (string) ($item['municipality'] ?? ''),
            'freguesia' => (string) ($item['freguesia'] ?? ''),
            'local' => (string) ($item['local'] ?? ''),
            'area_total_ha' => $area['total'] ?? null,
            'fwi' => $indices['fwi'] ?? null,
            'isi' => $indices['isi'] ?? null,
            'wind_intensity' => $weather['wind_intensity'] ?? null,
            'kml_url' => (string) ($item['kml_url'] ?? ''),
            'source' => 'icnf_active_occurrences',
            'source_label' => 'ICNF Ocorrências Activas',
            'central_data_provider' => true,
            'icnf' => $item,
        ];
    }

    private function normalizeSeverity(string $severity): string
    {
        $severity = strtolower(trim($severity));
        return in_array($severity, ['low', 'medium', 'high'], true) ? $severity : 'low';
    }

    private function toGeoJson(array $fires): array
    {
        $features = [];
        foreach ($fires as $fire) {
            $lat = $fire['latitude'] ?? null;
            $lon = $fire['longitude'] ?? null;
            if (!is_numeric($lat) || !is_numeric($lon)) {
                continue;
            }
            $features[] = [
                'type' => 'Feature',
                'geometry' => ['type' => 'Point', 'coordinates' => [(float) $lon, (float) $lat]],
                'properties' => $fire,
            ];
        }
        return ['type' => 'FeatureCollection', 'features' => $features];
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
