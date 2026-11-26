<?php

declare(strict_types=1);

require_once __DIR__ . '/../modules/slow_data_fetching/Fetcher.php';

/**
 * Central ICNF data provider.
 *
 * All operational FireRisk modules must use this class as their base data source.
 * It hides slow-fetch/cache/test-fallback details and exposes the same normalized
 * ICNF records to Fire Occurrences, Decision Support, Propagation, Weather and
 * Post-Fire modules.
 */
final class IcnfActiveOccurrencesProvider
{
    public const REMOTE_SOURCE = 'icnf_active_occurrences_remote';
    public const TEST_SOURCE = 'icnf_active_occurrences_test';
    public const DEMO_SOURCE = 'icnf_active_occurrences_demo';

    private string $basePath;
    private string $timezone;
    private ?SlowDataFetcher $fetcher = null;

    public function __construct(string $basePath, string $timezone = 'Europe/Lisbon')
    {
        $this->basePath = rtrim($basePath, DIRECTORY_SEPARATOR);
        $this->timezone = $timezone !== '' ? $timezone : 'Europe/Lisbon';
    }

    public function data(array $query = []): array
    {
        return $this->formatResult($this->loadSourceResult($query), $query, true);
    }

    public function all(array $query = []): array
    {
        return $this->formatResult($this->loadSourceResult($query), $query, false);
    }

    public function refresh(array $query = []): array
    {
        $remote = $this->fetcher()->fetchSource(self::REMOTE_SOURCE, true);
        $mode = ($remote['success'] ?? false) === true ? ($remote['mode'] ?? 'remote-refresh') : 'standard-test-fallback-after-remote-failure';

        if (($remote['success'] ?? false) === true) {
            $remote['mode'] = $mode;
            return $this->formatResult($remote, $query, true);
        }

        $test = $this->fetcher()->fetchSource(self::TEST_SOURCE, false);
        $test['mode'] = $mode;
        $test['warning'] = 'Remote ICNF refresh failed. Standard local ICNF XML test fixture returned so dependent modules keep working.';
        $test['remote_error'] = $remote['error'] ?? 'Unknown remote ICNF error.';
        return $this->formatResult($test, $query, true);
    }

    public function test(array $query = [], bool $applyDateFilter = true): array
    {
        $result = $this->fetcher()->fetchSource(self::TEST_SOURCE, false);
        $result['mode'] = 'standard-test-xml';
        return $this->formatResult($result, $query, $applyDateFilter);
    }

    public function demo(array $query = [], bool $applyDateFilter = true): array
    {
        $result = $this->fetcher()->fetchSource(self::DEMO_SOURCE, false);
        $result['mode'] = 'reduced-demo-xml';
        return $this->formatResult($result, $query, $applyDateFilter);
    }

    public function geoJson(array $items): array
    {
        $features = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $lat = $item['latitude'] ?? null;
            $lon = $item['longitude'] ?? null;

            if (!is_numeric($lat) || !is_numeric($lon)) {
                continue;
            }

            $features[] = [
                'type' => 'Feature',
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [(float) $lon, (float) $lat],
                ],
                'properties' => $item,
            ];
        }

        return [
            'type' => 'FeatureCollection',
            'features' => $features,
        ];
    }

    public function status(array $query = []): array
    {
        $targetDate = $this->resolveTargetDate($query);
        return [
            'success' => true,
            'provider' => 'icnf_active_occurrences',
            'central_data_provider' => true,
            'date_filter_enabled' => true,
            'filter_date' => $targetDate,
            'timezone' => $this->timezone,
            'remote_source' => self::REMOTE_SOURCE,
            'standard_test_source' => self::TEST_SOURCE,
            'demo_source' => self::DEMO_SOURCE,
            'sources' => array_values(array_filter($this->fetcher()->getStatus(), static function (array $source): bool {
                return in_array($source['id'], [self::REMOTE_SOURCE, self::TEST_SOURCE, self::DEMO_SOURCE], true);
            })),
        ];
    }

    public function clearRemoteCache(): array
    {
        return $this->fetcher()->clearCache(self::REMOTE_SOURCE);
    }

    public function resolveTargetDate(array $query = []): string
    {
        $requested = isset($query['date']) ? $this->normalizeDateString((string) $query['date']) : null;
        if ($requested !== null) {
            return $requested;
        }

        try {
            $zone = new DateTimeZone($this->timezone);
        } catch (Throwable $error) {
            $zone = new DateTimeZone('Europe/Lisbon');
        }

        return (new DateTimeImmutable('now', $zone))->format('Y-m-d');
    }

    public function filterByDate(array $items, string $targetDate): array
    {
        return array_values(array_filter($items, function ($item) use ($targetDate): bool {
            return is_array($item) && $this->extractOccurrenceDate($item) === $targetDate;
        }));
    }

    public function extractOccurrenceDate(array $item): ?string
    {
        foreach (['data_alerta', 'started_at', 'dh_inicio', 'date'] as $field) {
            if (!isset($item[$field])) {
                continue;
            }

            $date = $this->normalizeDateString((string) $item[$field]);
            if ($date !== null) {
                return $date;
            }
        }

        return null;
    }

    public function normalizeDateString(string $value): ?string
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

        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})/', $value, $match)) {
            return $match[3] . '-' . $match[2] . '-' . $match[1];
        }

        try {
            $zone = new DateTimeZone($this->timezone);
            return (new DateTimeImmutable($value, $zone))->setTimezone($zone)->format('Y-m-d');
        } catch (Throwable $error) {
            return null;
        }
    }

    private function loadSourceResult(array $query): array
    {
        $source = strtolower(trim((string) ($query['source'] ?? 'best')));

        if ($source === 'test') {
            $result = $this->fetcher()->fetchSource(self::TEST_SOURCE, false);
            $result['mode'] = 'standard-test-xml';
            return $result;
        }

        if ($source === 'demo') {
            $result = $this->fetcher()->fetchSource(self::DEMO_SOURCE, false);
            $result['mode'] = 'reduced-demo-xml';
            return $result;
        }

        if ($source === 'refresh') {
            $result = $this->fetcher()->fetchSource(self::REMOTE_SOURCE, true);
            $result['mode'] = 'remote-refresh';
            return $result;
        }

        if ($source === 'remote') {
            $cache = $this->fetcher()->getCachedData(self::REMOTE_SOURCE);
            if (($cache['success'] ?? false) === true) {
                $cache['mode'] = 'remote-cache';
            }
            return $cache;
        }

        $cache = $this->fetcher()->getCachedData(self::REMOTE_SOURCE);
        if (($cache['success'] ?? false) === true) {
            $cache['mode'] = 'remote-cache';
            return $cache;
        }

        $test = $this->fetcher()->fetchSource(self::TEST_SOURCE, false);
        $test['mode'] = 'standard-test-fallback';
        $test['warning'] = 'No remote ICNF cache exists yet. Standard ICNF XML test fixture was used as the central base dataset.';
        return $test;
    }

    private function formatResult(array $sourceResult, array $query, bool $applyDateFilter): array
    {
        $items = $this->extractItems($sourceResult);
        $targetDate = $this->resolveTargetDate($query);
        $returnedItems = $applyDateFilter ? $this->filterByDate($items, $targetDate) : array_values($items);
        $payload = is_array($sourceResult['data'] ?? null) ? $sourceResult['data'] : [];
        $payload['items'] = $returnedItems;
        $payload['count'] = count($returnedItems);
        $payload['total_count_before_date_filter'] = count($items);
        $payload['date_filter'] = $applyDateFilter ? $targetDate : null;

        return [
            'success' => ($sourceResult['success'] ?? false) === true,
            'provider' => 'icnf_active_occurrences',
            'central_data_provider' => true,
            'source_module' => 'icnf_active_occurrences',
            'source' => $sourceResult['source'] ?? null,
            'source_mode' => $sourceResult['mode'] ?? null,
            'date_filter_enabled' => $applyDateFilter,
            'filter_date' => $applyDateFilter ? $targetDate : null,
            'timezone' => $this->timezone,
            'cache' => $sourceResult['cache'] ?? null,
            'total_count' => count($items),
            'count' => count($returnedItems),
            'data' => $returnedItems,
            'raw' => $payload,
            'message' => $sourceResult['message'] ?? null,
            'warning' => $sourceResult['warning'] ?? null,
            'error' => $sourceResult['error'] ?? null,
            'remote_error' => $sourceResult['remote_error'] ?? null,
        ];
    }

    private function extractItems(array $sourceResult): array
    {
        $payload = $sourceResult['data'] ?? [];

        if (is_array($payload) && isset($payload['items']) && is_array($payload['items'])) {
            return array_values(array_filter($payload['items'], 'is_array'));
        }

        if (is_array($payload) && array_keys($payload) === range(0, count($payload) - 1)) {
            return array_values(array_filter($payload, 'is_array'));
        }

        return [];
    }

    private function fetcher(): SlowDataFetcher
    {
        if ($this->fetcher instanceof SlowDataFetcher) {
            return $this->fetcher;
        }

        $this->fetcher = new SlowDataFetcher(
            $this->basePath,
            $this->basePath . '/modules/slow_data_fetching',
            $this->basePath . '/storage/cache/slow_data_fetching'
        );

        return $this->fetcher;
    }
}
