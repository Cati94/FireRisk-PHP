<?php

require_once __DIR__ . '/ModuleInterface.php';

abstract class AbstractModule implements ModuleInterface
{
    protected string $id;
    protected string $name;
    protected string $type;
    protected int $relevance;
    protected string $description;
    protected string $loadMode = 'eager';

    public function __construct(array $definition = [])
    {
        $this->id = (string) ($definition['id'] ?? $this->id ?? '');
        $this->name = (string) ($definition['name'] ?? $this->name ?? 'Unnamed Module');
        $this->type = (string) ($definition['type'] ?? $this->type ?? 'page');
        $this->relevance = (int) ($definition['relevance'] ?? $this->relevance ?? 0);
        $this->description = (string) ($definition['description'] ?? $this->description ?? '');
        $this->loadMode = (string) ($definition['load_mode'] ?? $this->loadMode ?? 'eager');
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getRelevance(): int
    {
        return $this->relevance;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getLoadMode(): string
    {
        return $this->loadMode ?: 'eager';
    }

    public function getNavigationEndpoint(): ?string
    {
        return '/module.php?module=' . rawurlencode($this->getId());
    }

    public function getAssets(): array
    {
        return [
            'js' => [],
            'css' => [],
        ];
    }

    public function getApiEndpoint(): ?string
    {
        return null;
    }

    public function getPageEndpoint(): ?string
    {
        return null;
    }

    public function getLayerConfig(): ?array
    {
        return null;
    }

    public function handleApi(array $query): void
    {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error' => 'This module does not expose an API endpoint.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    public function renderPage(array $query): string
    {
        return '<section class="module-page"><h2>' . htmlspecialchars($this->getName(), ENT_QUOTES, 'UTF-8') . '</h2><p>No GUI page is available for this module.</p></section>';
    }

    protected function jsonResponse(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    protected function readJsonFile(string $file): array
    {
        if (!file_exists($file)) {
            return [];
        }

        $json = file_get_contents($file);
        $data = json_decode($json, true);

        return is_array($data) ? $data : [];
    }
}
