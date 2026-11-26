<?php

require_once __DIR__ . '/../../core/AbstractModule.php';

class RecoveryModule extends AbstractModule
{
    public function getAssets(): array
    {
        return [
            'js' => [],
            'css' => [],
        ];
    }

    public function getPageEndpoint(): ?string
    {
        return '/api/module.php?module=recovery&page=1';
    }

    public function renderPage(array $query): string
    {
        return '<section class="module-page"><h2>Post-Fire Recovery</h2><p>Placeholder for recovery, prevention, reinspection and mitigation workflows.</p></section>';
    }
}
