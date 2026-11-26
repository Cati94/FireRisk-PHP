<?php

interface ModuleInterface
{
    public function getId(): string;

    public function getName(): string;

    public function getType(): string;

    public function getRelevance(): int;

    public function getDescription(): string;

    public function getLoadMode(): string;

    public function getNavigationEndpoint(): ?string;

    public function getAssets(): array;

    public function getApiEndpoint(): ?string;

    public function getPageEndpoint(): ?string;

    public function getLayerConfig(): ?array;

    public function handleApi(array $query): void;

    public function renderPage(array $query): string;
}
