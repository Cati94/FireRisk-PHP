<?php

declare(strict_types=1);

/**
 * Small file-backed cache for expensive post-fire analysis responses.
 *
 * Heavy modules should not recompute thousands of ICNF-derived burned areas
 * every time the operator opens the workspace. This cache is deliberately
 * module-local and independent from the slow_data_fetching source cache.
 */
final class PostFireAnalysisCache
{
    private string $cacheDir;
    private int $ttlSeconds;

    public function __construct(private readonly string $projectRoot, int $ttlSeconds = 86400)
    {
        $this->cacheDir = $this->projectRoot . '/storage/cache/postfire_hazards/analysis';
        $this->ttlSeconds = max(60, $ttlSeconds);
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0775, true);
        }
    }

    public function key(array $query, array $scenario, string $timezone): string
    {
        $payload = [
            'source' => strtolower(trim((string)($query['source'] ?? 'auto'))),
            'months' => max(1, min(60, (int)($query['months'] ?? $scenario['months'] ?? 12))),
            'reference_date' => trim((string)($query['reference_date'] ?? '')),
            'timezone' => $timezone,
            'scenario' => [
                'rain_1h_mm' => round((float)($scenario['rain_1h_mm'] ?? 0), 3),
                'rain_24h_mm' => round((float)($scenario['rain_24h_mm'] ?? 0), 3),
                'soil_saturation' => round((float)($scenario['soil_saturation'] ?? 0), 3),
            ],
            'schema' => 'postfire-analysis-v2',
        ];

        return sha1(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function get(string $key): ?array
    {
        $path = $this->path($key);
        if (!is_file($path)) {
            return null;
        }

        $age = time() - filemtime($path);
        if ($age > $this->ttlSeconds) {
            return null;
        }

        $raw = (string)file_get_contents($path);
        if (str_ends_with($path, '.json.gz') && function_exists('gzdecode')) {
            $decoded = gzdecode($raw);
            if ($decoded !== false) {
                $raw = $decoded;
            }
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }

        $data['cache'] = array_merge($data['cache'] ?? [], [
            'enabled' => true,
            'hit' => true,
            'key' => $key,
            'age_seconds' => $age,
            'ttl_seconds' => $this->ttlSeconds,
            'path' => $this->relativePath($path),
        ]);

        return $data;
    }

    public function put(string $key, array $analysis): void
    {
        $path = $this->path($key);
        $analysis['cache'] = array_merge($analysis['cache'] ?? [], [
            'enabled' => true,
            'hit' => false,
            'key' => $key,
            'generated_at' => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
            'ttl_seconds' => $this->ttlSeconds,
            'path' => $this->relativePath($path),
        ]);

        $json = json_encode($analysis, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }

        $tmp = $path . '.tmp';
        if (function_exists('gzencode')) {
            $encoded = gzencode($json, 5);
            file_put_contents($tmp, $encoded !== false ? $encoded : $json);
        } else {
            file_put_contents($tmp, $json);
        }
        rename($tmp, $path);
    }

    public function status(): array
    {
        $files = glob($this->cacheDir . '/*.json.gz') ?: [];
        $items = [];
        $totalBytes = 0;
        foreach ($files as $file) {
            $size = filesize($file) ?: 0;
            $totalBytes += $size;
            $items[] = [
                'file' => basename($file),
                'size_bytes' => $size,
                'age_seconds' => time() - filemtime($file),
                'updated_at' => date(DateTimeInterface::ATOM, filemtime($file)),
            ];
        }

        usort($items, static fn(array $a, array $b): int => strcmp((string)$b['updated_at'], (string)$a['updated_at']));

        return [
            'cache_dir' => $this->relativePath($this->cacheDir),
            'ttl_seconds' => $this->ttlSeconds,
            'entries' => count($files),
            'total_bytes' => $totalBytes,
            'items' => array_slice($items, 0, 30),
        ];
    }

    public function clear(): int
    {
        $files = glob($this->cacheDir . '/*.json.gz') ?: [];
        $deleted = 0;
        foreach ($files as $file) {
            if (is_file($file) && unlink($file)) {
                $deleted++;
            }
        }
        return $deleted;
    }

    private function path(string $key): string
    {
        return $this->cacheDir . '/' . preg_replace('/[^a-f0-9]/', '', $key) . '.json.gz';
    }

    private function relativePath(string $path): string
    {
        $root = rtrim(str_replace('\\', '/', $this->projectRoot), '/');
        $normalized = str_replace('\\', '/', $path);
        return str_starts_with($normalized, $root) ? ltrim(substr($normalized, strlen($root)), '/') : $normalized;
    }
}
