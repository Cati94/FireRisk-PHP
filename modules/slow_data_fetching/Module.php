<?php

require_once __DIR__ . '/../../core/AbstractModule.php';
require_once __DIR__ . '/Fetcher.php';

class SlowDataFetchingModule extends AbstractModule
{
    public function getAssets(): array
    {
        return [
            'js' => [
                '/module-asset.php?module=slow_data_fetching&file=page.js',
            ],
            'css' => [
                '/module-asset.php?module=slow_data_fetching&file=style.css',
            ],
        ];
    }

    public function getApiEndpoint(): ?string
    {
        return '/api/module.php?module=slow_data_fetching';
    }

    public function getPageEndpoint(): ?string
    {
        return '/api/module.php?module=slow_data_fetching&page=1';
    }

    public function handleApi(array $query): void
    {
        $action = strtolower(trim((string) ($query['action'] ?? 'status')));
        $source = strtolower(trim((string) ($query['source'] ?? '')));
        $force = isset($query['force']) && (string) $query['force'] === '1';
        $fetcher = $this->fetcher();

        try {
            switch ($action) {
                case 'status':
                    $this->jsonResponse([
                        'success' => true,
                        'module' => $this->getId(),
                        'sources' => $fetcher->getStatus(),
                    ]);
                    return;

                case 'sources':
                    $this->jsonResponse([
                        'success' => true,
                        'module' => $this->getId(),
                        'sources' => $fetcher->getSources(false),
                    ]);
                    return;

                case 'data':
                    $this->jsonResponse($fetcher->getCachedData($source));
                    return;

                case 'fetch':
                    $this->jsonResponse($fetcher->fetchSource($source, $force));
                    return;

                case 'fetch_all':
                    $this->jsonResponse($fetcher->fetchAll($force));
                    return;

                case 'clear':
                    $this->jsonResponse($fetcher->clearCache($source !== '' ? $source : null));
                    return;

                default:
                    $this->jsonResponse([
                        'success' => false,
                        'error' => 'Unknown action: ' . $action,
                        'valid_actions' => ['status', 'sources', 'data', 'fetch', 'fetch_all', 'clear'],
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
<section class="module-page slow-data-page">
    <h2>Slow Data Fetching</h2>
    <p>This module protects the main interface from slow or unreliable data feeds. Sources are fetched with timeouts, cached, and served from stale cache when a live source fails.</p>

    <div class="slow-data-toolbar">
        <button id="slow-fetch-refresh-status" class="small-button" type="button">Refresh status</button>
        <button id="slow-fetch-all" class="small-button primary" type="button">Fetch all enabled</button>
        <button id="slow-fetch-clear" class="small-button danger" type="button">Clear all cache</button>
    </div>

    <div id="slow-fetch-message" class="slow-fetch-message">Loading source status...</div>
    <div id="slow-fetch-sources" class="slow-fetch-sources"></div>
    <details class="slow-fetch-details">
        <summary>Last API response</summary>
        <pre id="slow-fetch-output">No response yet.</pre>
    </details>
</section>
HTML;
    }

    private function fetcher(): SlowDataFetcher
    {
        $basePath = dirname(__DIR__, 2);
        return new SlowDataFetcher(
            $basePath,
            __DIR__,
            $basePath . '/storage/cache/slow_data_fetching'
        );
    }
}
