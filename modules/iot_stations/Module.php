<?php

require_once __DIR__ . '/../../core/AbstractModule.php';

class IotStationsModule extends AbstractModule
{
    public function getAssets(): array
    {
        return [
            'js' => ['/module-asset.php?module=iot_stations&file=layer.js'],
            'css' => [],
        ];
    }

    public function getApiEndpoint(): ?string
    {
        return '/api/module.php?module=iot_stations';
    }

    public function getPageEndpoint(): ?string
    {
        return '/api/module.php?module=iot_stations&page=1';
    }

    public function getLayerConfig(): ?array
    {
        return [
            'id' => $this->getId(),
            'type' => 'sensor-layer',
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
            'message' => 'IoT stations placeholder. Add solar weather station telemetry later.',
        ]);
    }

    public function renderPage(array $query): string
    {
        return '<section class="module-page"><h2>IoT Stations</h2><p>Placeholder for remote field sensors and local weather stations.</p></section>';
    }
}
