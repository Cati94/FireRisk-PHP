<?php

require_once __DIR__ . '/../../core/AbstractModule.php';

class NasaFirmsModule extends AbstractModule
{
    public function getAssets(): array
    {
        return [
            'js' => ['/module-asset.php?module=nasa_firms&file=layer.js'],
            'css' => [],
        ];
    }

    public function getApiEndpoint(): ?string
    {
        return '/api/module.php?module=nasa_firms';
    }

    public function getLayerConfig(): ?array
    {
        return [
            'id' => $this->getId(),
            'type' => 'hotspot-layer',
            'api' => $this->getApiEndpoint(),
            'enabled' => true,
        ];
    }

    public function handleApi(array $query): void
    {
        $this->jsonResponse([
            'success' => true,
            'module' => $this->getId(),
            'data' => [],
            'message' => 'NASA FIRMS connector placeholder. Add API key and data source later.',
        ]);
    }
}
