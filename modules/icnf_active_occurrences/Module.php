<?php

require_once __DIR__ . '/../../core/AbstractModule.php';
require_once __DIR__ . '/../../core/IcnfActiveOccurrencesProvider.php';

class IcnfActiveOccurrencesModule extends AbstractModule
{
    public function getAssets(): array
    {
        return [
            'js' => ['/module-asset.php?module=icnf_active_occurrences&file=layer.js'],
            'css' => ['/module-asset.php?module=icnf_active_occurrences&file=style.css'],
        ];
    }

    public function getApiEndpoint(): ?string
    {
        return '/api/module.php?module=icnf_active_occurrences';
    }

    public function getPageEndpoint(): ?string
    {
        return '/api/module.php?module=icnf_active_occurrences&page=1';
    }

    public function getLayerConfig(): ?array
    {
        return [
            'id' => $this->getId(),
            'type' => 'marker-layer',
            'api' => $this->getApiEndpoint(),
            'enabled' => true,
            'source' => IcnfActiveOccurrencesProvider::REMOTE_SOURCE,
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
                    $result = $provider->data($query);
                    $result['module'] = $this->getId();
                    $this->jsonResponse($result);
                    return;

                case 'all':
                    $result = $provider->all($query);
                    $result['module'] = $this->getId();
                    $this->jsonResponse($result);
                    return;

                case 'refresh':
                    $result = $provider->refresh($query);
                    $result['module'] = $this->getId();
                    $this->jsonResponse($result);
                    return;

                case 'test':
                case 'sample':
                    $result = $provider->test($query, true);
                    $result['module'] = $this->getId();
                    $this->jsonResponse($result);
                    return;

                case 'demo':
                    $result = $provider->demo($query, true);
                    $result['module'] = $this->getId();
                    $this->jsonResponse($result);
                    return;

                case 'geojson':
                    $result = $provider->data($query);
                    $this->jsonResponse([
                        'success' => $result['success'],
                        'module' => $this->getId(),
                        'provider' => $result['provider'],
                        'central_data_provider' => true,
                        'source_mode' => $result['source_mode'] ?? null,
                        'date_filter_enabled' => $result['date_filter_enabled'] ?? true,
                        'filter_date' => $result['filter_date'] ?? null,
                        'timezone' => $result['timezone'] ?? $this->timezoneName(),
                        'total_count' => $result['total_count'] ?? null,
                        'count' => $result['count'] ?? null,
                        'geojson' => $provider->geoJson($result['data'] ?? []),
                    ]);
                    return;

                case 'status':
                    $status = $provider->status($query);
                    $status['module'] = $this->getId();
                    $this->jsonResponse($status);
                    return;

                case 'clear':
                    $this->jsonResponse($provider->clearRemoteCache());
                    return;

                default:
                    $this->jsonResponse([
                        'success' => false,
                        'error' => 'Unknown action: ' . $action,
                        'valid_actions' => ['data', 'all', 'refresh', 'test', 'sample', 'demo', 'geojson', 'status', 'clear'],
                    ], 400);
                    return;
            }
        } catch (Throwable $error) {
            $this->jsonResponse([
                'success' => false,
                'module' => $this->getId(),
                'provider' => 'icnf_active_occurrences',
                'central_data_provider' => true,
                'error' => $error->getMessage(),
            ], 500);
        }
    }

    public function renderPage(array $query): string
    {
        $today = htmlspecialchars($this->provider()->resolveTargetDate($query), ENT_QUOTES, 'UTF-8');
        return <<<HTML
<section class="module-page icnf-page">
    <h2>ICNF Ocorrências Activas</h2>
    <p>This is the central FireRisk data provider. Every operational module should consume this normalized ICNF dataset instead of reading its own independent base data.</p>
    <p class="icnf-date-rule"><strong>Active date filter:</strong> {$today}</p>

    <div class="icnf-toolbar">
        <button id="icnf-load-cache" class="small-button" type="button">Load today's cached/best data</button>
        <button id="icnf-refresh-remote" class="small-button primary" type="button">Refresh today's ICNF XML</button>
        <button id="icnf-load-test" class="small-button" type="button">Load today's records from standard test XML</button>
        <button id="icnf-load-demo" class="small-button" type="button">Load today's records from demo XML</button>
        <button id="icnf-clear-cache" class="small-button danger" type="button">Clear ICNF cache</button>
    </div>

    <div id="icnf-message" class="icnf-message">Loading ICNF data...</div>
    <div id="icnf-summary" class="icnf-summary"></div>
    <div id="icnf-list" class="module-list">Loading occurrences...</div>

    <details class="icnf-details">
        <summary>Source status / last response</summary>
        <pre id="icnf-output">No response yet.</pre>
    </details>
</section>
HTML;
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
