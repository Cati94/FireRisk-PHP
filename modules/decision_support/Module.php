<?php

require_once __DIR__ . '/../../core/AbstractModule.php';
require_once __DIR__ . '/DecisionSupportEngine.php';

class DecisionSupportModule extends AbstractModule
{
    public function getAssets(): array
    {
        return [
            'js' => [
                '/module-asset.php?module=decision_support&file=page.js',
            ],
            'css' => [
                '/module-asset.php?module=decision_support&file=style.css',
            ],
        ];
    }

    public function getApiEndpoint(): ?string
    {
        return '/api/module.php?module=decision_support';
    }

    public function getPageEndpoint(): ?string
    {
        return '/api/module.php?module=decision_support&page=1';
    }

    public function handleApi(array $query): void
    {
        $action = strtolower(trim((string) ($query['action'] ?? 'analysis')));
        $engine = new DecisionSupportEngine(dirname(__DIR__, 2), $this->timezoneName());

        try {
            switch ($action) {
                case 'analysis':
                case 'today':
                    $this->jsonResponse($engine->analyze($query));
                    return;

                case 'method':
                    $this->jsonResponse([
                        'success' => true,
                        'module' => $this->getId(),
                        'method' => $engine->methodDescription(),
                    ]);
                    return;

                default:
                    $this->jsonResponse([
                        'success' => false,
                        'error' => 'Unknown action: ' . $action,
                        'valid_actions' => ['analysis', 'today', 'method'],
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
<section class="module-page decision-support-page">
    <h2>Decision Support</h2>
    <p>v0.5 ranks the current-day ICNF occurrences by operational relevance using deterministic triage rules. It does not predict propagation yet; it helps decide what deserves attention first.</p>
    <p class="decision-date-rule"><strong>Active date filter:</strong> {$today}</p>

    <div class="decision-toolbar">
        <button id="decision-refresh" class="small-button primary" type="button">Refresh analysis</button>
        <button id="decision-load-test" class="small-button" type="button">Analyse standard test XML</button>
        <button id="decision-show-method" class="small-button" type="button">Show scoring method</button>
    </div>

    <div id="decision-message" class="decision-message">Loading decision support analysis...</div>
    <div id="decision-overview" class="decision-overview"></div>
    <div id="decision-recommendations" class="decision-recommendations"></div>
    <div id="decision-priorities" class="module-list decision-priorities">Loading ranked occurrences...</div>

    <details class="decision-details">
        <summary>Last response / method</summary>
        <pre id="decision-output">No response yet.</pre>
    </details>
</section>
HTML;
    }

    private function resolveTargetDate(array $query = []): string
    {
        $requested = isset($query['date']) ? $this->normalizeDateString((string) $query['date']) : null;
        if ($requested !== null) {
            return $requested;
        }

        try {
            $zone = new DateTimeZone($this->timezoneName());
        } catch (Throwable $error) {
            $zone = new DateTimeZone('Europe/Lisbon');
        }

        return (new DateTimeImmutable('now', $zone))->format('Y-m-d');
    }

    private function normalizeDateString(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $match)) {
            return $match[1] . '-' . $match[2] . '-' . $match[3];
        }
        if (preg_match('/^(\d{2})-(\d{2})-(\d{4})/', $value, $match)) {
            return $match[3] . '-' . $match[2] . '-' . $match[1];
        }
        try {
            $zone = new DateTimeZone($this->timezoneName());
            return (new DateTimeImmutable($value, $zone))->setTimezone($zone)->format('Y-m-d');
        } catch (Throwable $error) {
            return null;
        }
    }

    private function timezoneName(): string
    {
        $configFile = dirname(__DIR__, 2) . '/config.php';
        $config = file_exists($configFile) ? require $configFile : [];
        $timezone = is_array($config) ? (string) ($config['timezone'] ?? 'Europe/Lisbon') : 'Europe/Lisbon';
        return $timezone !== '' ? $timezone : 'Europe/Lisbon';
    }
}
