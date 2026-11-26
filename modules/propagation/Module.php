<?php

require_once __DIR__ . '/../../core/AbstractModule.php';
require_once __DIR__ . '/PropagationEngine.php';

class PropagationModule extends AbstractModule
{
    public function getAssets(): array
    {
        return [
            'js' => ['/module-asset.php?module=propagation&file=layer.js'],
            'css' => ['/module-asset.php?module=propagation&file=style.css'],
        ];
    }

    public function getApiEndpoint(): ?string
    {
        return '/api/module.php?module=propagation';
    }

    public function getPageEndpoint(): ?string
    {
        return '/api/module.php?module=propagation&page=1';
    }

    public function getLayerConfig(): ?array
    {
        return [
            'id' => $this->getId(),
            'type' => 'propagation-preview-layer',
            'api' => $this->getApiEndpoint(),
            'enabled' => true,
            'date_filter' => 'today',
            'horizons_minutes' => [30, 60, 120],
        ];
    }

    public function handleApi(array $query): void
    {
        $action = strtolower(trim((string) ($query['action'] ?? 'forecast')));
        $engine = $this->engine();

        try {
            switch ($action) {
                case 'forecast':
                case 'data':
                    $this->jsonResponse($engine->forecast($query));
                    return;

                case 'geojson':
                    $this->jsonResponse($engine->geoJson($query));
                    return;

                case 'method':
                    $this->jsonResponse([
                        'success' => true,
                        'module' => $this->getId(),
                        'version' => '0.6',
                        'method' => $engine->methodDescription(),
                    ]);
                    return;

                default:
                    $this->jsonResponse([
                        'success' => false,
                        'error' => 'Unknown action: ' . $action,
                        'valid_actions' => ['forecast', 'data', 'geojson', 'method'],
                    ], 400);
                    return;
            }
        } catch (Throwable $error) {
            $this->jsonResponse([
                'success' => false,
                'module' => $this->getId(),
                'error' => $error->getMessage(),
            ], 500);
        }
    }

    public function renderPage(array $query): string
    {
        $today = htmlspecialchars($this->resolveTargetDate($query), ENT_QUOTES, 'UTF-8');
        return <<<HTML
<section class="module-page propagation-page">
    <h2>Fire Propagation Preview</h2>
    <p>Deterministic tactical preview for current-day ICNF occurrences. This is an operational orientation layer, not a validated scientific simulator.</p>
    <p class="propagation-date-rule"><strong>Active date filter:</strong> {$today}</p>

    <div class="propagation-toolbar">
        <button id="propagation-refresh" class="small-button primary" type="button">Load today's propagation preview</button>
        <button id="propagation-load-test" class="small-button" type="button">Test with 2026-02-24 fixture</button>
        <button id="propagation-show-method" class="small-button" type="button">Show method</button>
    </div>

    <div id="propagation-message" class="propagation-message">Loading propagation preview...</div>
    <div id="propagation-overview" class="propagation-overview"></div>
    <div id="propagation-list" class="module-list">Loading projections...</div>

    <details class="propagation-details">
        <summary>Projection response / method</summary>
        <pre id="propagation-output">No response yet.</pre>
    </details>
</section>
HTML;
    }

    private function engine(): PropagationEngine
    {
        return new PropagationEngine(dirname(__DIR__, 2), $this->timezoneName());
    }

    private function timezoneName(): string
    {
        $configFile = dirname(__DIR__, 2) . '/config.php';
        $config = file_exists($configFile) ? require $configFile : [];
        $timezone = is_array($config) ? (string) ($config['timezone'] ?? 'Europe/Lisbon') : 'Europe/Lisbon';
        return $timezone !== '' ? $timezone : 'Europe/Lisbon';
    }

    private function resolveTargetDate(array $query): string
    {
        $date = trim((string) ($query['date'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $date;
        }

        return (new DateTimeImmutable('now', new DateTimeZone($this->timezoneName())))->format('Y-m-d');
    }
}
