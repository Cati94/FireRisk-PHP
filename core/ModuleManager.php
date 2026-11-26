<?php

require_once __DIR__ . '/ModuleInterface.php';

class ModuleManager
{
    private string $xmlFile;
    private string $basePath;
    private array $errors = [];

    public function __construct(string $xmlFile, ?string $basePath = null)
    {
        $this->xmlFile = $xmlFile;
        $this->basePath = $basePath ?: dirname(__DIR__);
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getEnabledModules(): array
    {
        $modules = [];

        foreach ($this->readDefinitions() as $definition) {
            if (!$definition['enabled']) {
                continue;
            }

            $module = $this->loadModule($definition);

            if ($module instanceof ModuleInterface) {
                $modules[] = $module;
            }
        }

        usort($modules, function (ModuleInterface $a, ModuleInterface $b): int {
            return $b->getRelevance() <=> $a->getRelevance();
        });

        return $modules;
    }

    public function getModuleById(string $id): ?ModuleInterface
    {
        foreach ($this->getEnabledModules() as $module) {
            if ($module->getId() === $id) {
                return $module;
            }
        }

        return null;
    }

    public function getEnabledModuleIds(): array
    {
        return array_map(function (ModuleInterface $module): string {
            return $module->getId();
        }, $this->getEnabledModules());
    }

    private function readDefinitions(): array
    {
        if (!file_exists($this->xmlFile)) {
            $this->errors[] = 'Modules XML file not found: ' . $this->xmlFile;
            return [];
        }

        $contents = file_get_contents($this->xmlFile);

        if ($contents === false || trim($contents) === '') {
            $this->errors[] = 'Modules XML file is empty or unreadable.';
            return [];
        }

        $definitions = [];

        if (!preg_match_all('/<module\s+id="([a-zA-Z0-9_\-]+)"\s*>(.*?)<\/module>/s', $contents, $matches, PREG_SET_ORDER)) {
            $this->errors[] = 'No module declarations found in modules XML.';
            return [];
        }

        foreach ($matches as $match) {
            $id = $this->safeId($match[1]);
            $body = $match[2];

            if ($id === '') {
                $this->errors[] = 'Skipped module without a valid id.';
                continue;
            }

            $definitions[] = [
                'id' => $id,
                'name' => $this->readTag($body, 'name'),
                'enabled' => strtolower($this->readTag($body, 'enabled')) === 'true',
                'type' => $this->readTag($body, 'type'),
                'relevance' => (int) $this->readTag($body, 'relevance'),
                'entry' => $this->readTag($body, 'entry'),
                'class' => $this->readTag($body, 'class'),
                'description' => $this->readTag($body, 'description'),
                'load_mode' => $this->readTag($body, 'load_mode') ?: 'eager',
            ];
        }

        return $definitions;
    }

    private function readTag(string $body, string $tag): string
    {
        if (!preg_match('/<' . preg_quote($tag, '/') . '>(.*?)<\/' . preg_quote($tag, '/') . '>/s', $body, $match)) {
            return '';
        }

        return html_entity_decode(trim($match[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function loadModule(array $definition): ?ModuleInterface
    {
        $entry = $this->normalizePath($definition['entry']);

        if ($entry === null || !file_exists($entry)) {
            $this->errors[] = 'Module entry not found for ' . $definition['id'];
            error_log('FireRisk module entry not found: ' . ($entry ?? $definition['entry']));
            return null;
        }

        require_once $entry;

        $className = $definition['class'];

        if ($className === '' || !class_exists($className)) {
            $this->errors[] = 'Module class not found for ' . $definition['id'];
            error_log('FireRisk module class not found: ' . $className);
            return null;
        }

        $module = new $className($definition);

        if (!$module instanceof ModuleInterface) {
            $this->errors[] = 'Module does not implement ModuleInterface: ' . $definition['id'];
            error_log('Invalid FireRisk module: ' . $definition['id']);
            return null;
        }

        return $module;
    }

    private function normalizePath(string $relativePath): ?string
    {
        $relativePath = str_replace(['../', '..\\'], '', $relativePath);
        $path = $this->basePath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
        return $path;
    }

    private function safeId(string $id): string
    {
        $id = strtolower(trim($id));
        return preg_match('/^[a-z0-9_\-]+$/', $id) ? $id : '';
    }
}
