<?php

declare(strict_types=1);

require_once __DIR__ . '/../../core/AbstractModule.php';
require_once __DIR__ . '/IcnfBurnedAreaRepository.php';
require_once __DIR__ . '/KmlPerimeterRepository.php';
require_once __DIR__ . '/PostFireRiskEngine.php';
require_once __DIR__ . '/PostFireAnalysisCache.php';

class PostfireHazardsModule extends AbstractModule
{
    public function getAssets(): array
    {
        return [
            'js' => [
                '/module-asset.php?module=postfire_hazards&file=page.js',
            ],
            'css' => [
                '/module-asset.php?module=postfire_hazards&file=style.css',
            ],
        ];
    }

    public function getApiEndpoint(): ?string
    {
        return '/api/module.php?module=postfire_hazards';
    }

    public function getPageEndpoint(): ?string
    {
        return '/api/module.php?module=postfire_hazards&page=1';
    }

    public function getLayerConfig(): ?array
    {
        // Heavy post-fire analysis intentionally does not auto-load on the main operational map.
        // Open it through /module.php?module=postfire_hazards when the operator needs the workspace.
        return null;
    }

    public function handleApi(array $query): void
    {
        $action = strtolower(trim((string)($query['action'] ?? 'risk')));
        $repo = $this->repository();

        try {
            switch ($action) {
                case 'health':
                    $this->jsonResponse([
                        'success' => true,
                        'module' => $this->getId(),
                        'name' => $this->getName(),
                        'version' => '0.6.5',
                        'compatible_with' => 'FireRisk PHP module registry',
                        'restored_from_original_zip' => true,
                        'data_source' => 'ICNF records from slow_data_fetching cache/local fixture',
                        'restored_features' => [
                            'scenario sliders and numeric inputs',
                            'scenario presets',
                            'default scenario JSON',
                            'top risk metrics',
                            'burned-area cards',
                            'prioritized risk-zone table',
                            'one independent map layer per burned area inside the dedicated heavy workspace',
                            '12-month rolling window',
                            'file-backed analysis cache for fast repeated loading',
                        ],
                    ]);
                    return;

                case 'defaults':
                case 'default_scenario':
                    $this->jsonResponse([
                        'success' => true,
                        'module' => $this->getId(),
                        'scenario' => $repo->defaultScenario(),
                    ]);
                    return;

                case 'manifest':
                    $this->jsonResponse([
                        'success' => true,
                        'module' => $this->getId(),
                        'manifest' => $this->manifest(),
                    ]);
                    return;

                case 'perimeters':
                case 'perimeter_status':
                    $this->jsonResponse([
                        'success' => true,
                        'module' => $this->getId(),
                        'perimeters' => $repo->perimeterStatus($query),
                    ]);
                    return;

                case 'prefetch_perimeters':
                    $this->jsonResponse(array_merge([
                        'success' => true,
                        'module' => $this->getId(),
                    ], $repo->prefetchPerimeters($query)));
                    return;

                case 'burned_areas':
                case 'burned-areas':
                case 'areas':
                    $collection = $repo->featureCollection($query);
                    $this->jsonResponse([
                        'success' => true,
                        'module' => $this->getId(),
                        'count' => count($collection['features'] ?? []),
                        'geojson' => $collection,
                        'meta' => $collection['meta'] ?? [],
                    ]);
                    return;

                case 'geojson':
                    $analysis = $this->analysis($repo, $query);
                    $this->jsonResponse([
                        'success' => true,
                        'module' => $this->getId(),
                        'count' => $analysis['count'] ?? 0,
                        'overall' => $analysis['overall'] ?? [],
                        'inputs' => $analysis['inputs'] ?? [],
                        'zones' => $analysis['zones'] ?? [],
                        'areas' => $analysis['areas'] ?? [],
                        'geojson' => $analysis['burned_areas_geojson'] ?? ['type' => 'FeatureCollection', 'features' => []],
                    ]);
                    return;

                case 'risk':
                case 'scenario':
                    $this->jsonResponse($this->analysis($repo, $query, $this->requestJson()));
                    return;


                case 'cache_status':
                case 'cache-status':
                    $this->jsonResponse([
                        'success' => true,
                        'module' => $this->getId(),
                        'cache' => $this->cache()->status(),
                    ]);
                    return;

                case 'clear_cache':
                case 'clear-cache':
                    $this->jsonResponse([
                        'success' => true,
                        'module' => $this->getId(),
                        'deleted' => $this->cache()->clear(),
                        'cache' => $this->cache()->status(),
                    ]);
                    return;

                case 'precompute':
                case 'rebuild_cache':
                case 'rebuild-cache':
                    $query['force'] = '1';
                    $this->jsonResponse($this->analysis($repo, $query, $this->requestJson()));
                    return;

                case 'method':
                    $this->jsonResponse([
                        'success' => true,
                        'module' => $this->getId(),
                        'method' => $this->methodDescription(),
                    ]);
                    return;

                default:
                    $this->jsonResponse([
                        'success' => false,
                        'error' => 'Unknown action: ' . $action,
                        'valid_actions' => ['health', 'defaults', 'manifest', 'risk', 'scenario', 'geojson', 'burned_areas', 'burned-areas', 'areas', 'perimeters', 'prefetch_perimeters', 'cache_status', 'clear_cache', 'precompute', 'method'],
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
        return <<<HTML
<section class="module-page postfire-page postfire-restored">
    <header class="postfire-hero-card">
        <div>
            <h2>Post-Fire Hazards</h2>
            <p>Restored full module UI using locally stored ICNF records. It evaluates all burned areas from the last 12 months, uses cached ICNF/GNR KML/KMZ perimeters when available, and keeps one independent map layer per burned area.</p>
        </div>
        <div class="postfire-badges">
            <span>ICNF local data</span>
            <span>12 months</span>
            <span>Independent layers</span>
        </div>
    </header>

    <div class="postfire-mini-grid">
        <article><span>System date</span><strong id="postfire-system-date">—</strong></article>
        <article><span>Window from</span><strong id="postfire-window-from">—</strong></article>
        <article><span>Areas in map</span><strong id="postfire-area-count">—</strong></article>
        <article><span>Excluded by date</span><strong id="postfire-excluded-count">—</strong></article>
    </div>

    <div class="postfire-metrics">
        <article id="postfire-combined-card"><span>Combined max</span><strong id="postfire-combined-score">—</strong><em id="postfire-combined-level">—</em></article>
        <article id="postfire-flood-card"><span>Flood / runoff</span><strong id="postfire-flood-score">—</strong><em id="postfire-flood-level">—</em></article>
        <article id="postfire-erosion-card"><span>Erosion / landslide</span><strong id="postfire-erosion-score">—</strong><em id="postfire-erosion-level">—</em></article>
    </div>

    <form id="postfire-scenario-form" class="postfire-scenario-form">
        <h3>Weather scenario</h3>
        <label>Rain in 1h</label>
        <div class="postfire-input-row"><input id="postfire-rain-1h-range" type="range" min="0" max="120" step="1"><input id="postfire-rain-1h" type="number" min="0" max="250" step="1"></div>
        <label>Rain in 24h</label>
        <div class="postfire-input-row"><input id="postfire-rain-24h-range" type="range" min="0" max="220" step="1"><input id="postfire-rain-24h" type="number" min="0" max="500" step="1"></div>
        <label>Soil saturation</label>
        <div class="postfire-input-row"><input id="postfire-soil-range" type="range" min="0" max="1" step="0.01"><input id="postfire-soil" type="number" min="0" max="1" step="0.01"></div>
        <label>Window months</label>
        <div class="postfire-input-row"><input id="postfire-months-range" type="range" min="1" max="60" step="1"><input id="postfire-months" type="number" min="1" max="60" step="1"></div>
        <div class="postfire-toolbar">
            <button id="postfire-refresh" class="small-button primary" type="submit">Update risk</button>
            <button id="postfire-reset" class="small-button" type="button">Reset</button>
            <button id="postfire-load-test" class="small-button" type="button">Use standard ICNF test XML</button>
        </div>
        <div class="postfire-preset-row">
            <button type="button" class="postfire-preset" data-rain1h="8" data-rain24h="22" data-soil="0.35">Weak rain</button>
            <button type="button" class="postfire-preset" data-rain1h="28" data-rain24h="72" data-soil="0.68">Strong rain</button>
            <button type="button" class="postfire-preset" data-rain1h="55" data-rain24h="132" data-soil="0.90">Extreme event</button>
        </div>
    </form>

    <div class="postfire-toolbar secondary-row">
        <button id="postfire-prefetch-perimeters" class="small-button" type="button">Prefetch KML/KMZ perimeters</button>
        <button id="postfire-perimeter-status" class="small-button" type="button">Perimeter cache status</button>
        <button id="postfire-show-method" class="small-button" type="button">Show method</button>
        <button id="postfire-show-manifest" class="small-button" type="button">Show manifest</button>
    </div>

    <div id="postfire-message" class="postfire-message">Loading post-fire risk from locally stored ICNF data...</div>

    <details class="postfire-layer-box" open>
        <summary>Independent burned-area map layers</summary>
        <div id="postfire-layer-toggles" class="postfire-layer-toggles">No layers loaded yet.</div>
    </details>

    <div id="postfire-area-cards" class="postfire-area-cards"></div>

    <div class="postfire-table-card">
        <div class="postfire-table-head"><h3>Prioritized zones</h3><span><b id="postfire-zone-count">—</b> zones</span></div>
        <div class="postfire-table-wrap">
            <table class="postfire-zones-table">
                <thead><tr><th>Zone</th><th>Burned area</th><th>Flood</th><th>Erosion</th><th>Combined</th><th>Recommendation</th></tr></thead>
                <tbody id="postfire-zones"></tbody>
            </table>
        </div>
    </div>

    <details class="postfire-details">
        <summary>Inputs / last response / method</summary>
        <pre id="postfire-output">No response yet.</pre>
    </details>
</section>
HTML;
    }

    private function analysis(IcnfBurnedAreaRepository $repo, array $query, ?array $body = null): array
    {
        $body = $body ?? [];
        $months = max(1, min(60, (int)($query['months'] ?? $body['months'] ?? 12)));
        $scenario = $repo->scenario($query, $body);
        $cache = $this->cache();
        $cacheKey = $cache->key(array_merge($query, ['months' => $months]), $scenario, $this->timezoneName());
        $force = $this->boolValue($query['force'] ?? $query['refresh'] ?? $query['no_cache'] ?? $body['force'] ?? false);

        if (!$force) {
            $cached = $cache->get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        $collection = $repo->featureCollection(array_merge($query, ['months' => $months]));
        $now = $this->referenceNow($query);
        $total = $collection['meta']['total_icnf_records_available'] ?? $repo->totalAvailable($query);

        $analysis = PostFireRiskEngine::evaluateFeatureCollection($collection, $scenario, $months, $now, (int)$total);
        $analysis['warning'] = 'Operational preview only. Burned-area polygons use cached ICNF/GNR KML/KMZ perimeters when available, otherwise they fall back to ICNF LAT/LON and AREATOTAL approximations.';
        $analysis['restored_original_features'] = true;
        $analysis['cache'] = [
            'enabled' => true,
            'hit' => false,
            'key' => $cacheKey,
            'force_recalculation' => $force,
        ];
        $cache->put($cacheKey, $analysis);
        return $analysis;
    }

    private function cache(): PostFireAnalysisCache
    {
        return new PostFireAnalysisCache(dirname(__DIR__, 2), 86400);
    }

    private function boolValue(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL);
    }

    private function repository(): IcnfBurnedAreaRepository
    {
        return new IcnfBurnedAreaRepository(dirname(__DIR__, 2), $this->timezoneName());
    }

    private function referenceNow(array $query): DateTimeImmutable
    {
        $zone = new DateTimeZone($this->timezoneName());
        $reference = trim((string)($query['reference_date'] ?? ''));
        if ($reference !== '') {
            try {
                return new DateTimeImmutable($reference, $zone);
            } catch (Throwable) {
            }
        }
        return new DateTimeImmutable('now', $zone);
    }

    private function timezoneName(): string
    {
        $configFile = dirname(__DIR__, 2) . '/config.php';
        $config = file_exists($configFile) ? require $configFile : [];
        $timezone = is_array($config) ? (string)($config['timezone'] ?? 'Europe/Lisbon') : 'Europe/Lisbon';
        return $timezone !== '' ? $timezone : 'Europe/Lisbon';
    }

    private function requestJson(): array
    {
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private function manifest(): array
    {
        $path = __DIR__ . '/module.json';
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string)file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    private function methodDescription(): array
    {
        return [
            'purpose' => 'Estimate post-fire flood/runoff and erosion susceptibility using ICNF-derived burned areas already stored locally.',
            'restored_from_original_zip' => [
                'weather scenario simulation',
                'scenario presets',
                'default scenario JSON',
                'top metrics',
                'burned area cards',
                'prioritized zone table',
                'one independent map layer per burned area inside the dedicated heavy workspace',
                '12-month rolling time window',
                'post-fire analysis cache for fast repeated scenario loading',
            ],
            'real_data_inputs' => [
                'ICNF LAT/LON for centroid',
                'ICNF AREATOTAL/AREAPOV/AREAMATO/AREAAGRIC for burned area and fuel context',
                'ICNF DHFIM/DHINICIO for post-fire recency',
                'ICNF DECLIVEMEDIO/ALTITUDEMEDIA for terrain context',
                'ICNF APS/PERIMETRO/KML URL as extra operational context',
                'Cached ICNF/GNR KML/KMZ perimeter geometry when available',
            ],
            'scenario_inputs' => ['rain_1h_mm', 'rain_24h_mm', 'soil_saturation'],
            'limits' => [
                'No hydrological calibration yet',
                'Risk zones are derived from ICNF centroid/area/terrain fields, not surveyed drainage modelling',
                'KML/KMZ perimeter downloads are explicit and cached; normal map rendering does not wait on network',
                'Full post-fire analysis is file-cached for 24 hours unless force recalculation is requested',
                'Fallback polygons are approximate circles derived from burned area size when perimeter cache is missing',
            ],
        ];
    }
}
