<?php

declare(strict_types=1);

require_once __DIR__ . '/../../core/IcnfActiveOccurrencesProvider.php';
require_once __DIR__ . '/KmlPerimeterRepository.php';

final class IcnfBurnedAreaRepository
{

    private ?KmlPerimeterRepository $perimeters = null;

    public function __construct(
        private readonly string $projectRoot,
        private readonly string $timezone = 'Europe/Lisbon'
    ) {
    }

    public function featureCollection(array $query = []): array
    {
        $months = $this->months($query);
        $now = $this->now($query);
        $allItemsResult = $this->loadIcnfItems($query);
        $allItems = $allItemsResult['items'];
        $windowFrom = $now->sub(new DateInterval('P' . $months . 'M'));

        $features = [];
        foreach ($allItems as $item) {
            if (!is_array($item) || !$this->isBurnedAreaCandidate($item)) {
                continue;
            }

            $date = $this->eventDate($item);
            if ($date === null || $date < $windowFrom || $date > $now) {
                continue;
            }

            $feature = $this->itemToFeature($item, $date, $now);
            if ($feature !== null) {
                $features[] = $feature;
            }
        }

        usort($features, static function (array $a, array $b): int {
            $da = (string)($a['properties']['start_local'] ?? '');
            $db = (string)($b['properties']['start_local'] ?? '');
            return strcmp($db, $da);
        });

        return [
            'type' => 'FeatureCollection',
            'features' => array_values($features),
            'meta' => [
                'source_mode' => $allItemsResult['source_mode'],
                'source' => $allItemsResult['source'],
                'window_months' => $months,
                'window_from' => $windowFrom->format(DateTimeInterface::ATOM),
                'window_to' => $now->format(DateTimeInterface::ATOM),
                'total_icnf_records_available' => count($allItems),
                'returned_features' => count($features),
                'records_are_icnf_derived' => true,
                'source_provider' => 'icnf_active_occurrences',
                'central_data_provider' => true,
                'geometry_policy' => 'cached ICNF/GNR KML/KMZ perimeter when available; otherwise approximate circular polygon from LAT/LON and AREATOTAL',
            ],
        ];
    }

    public function totalAvailable(array $query = []): int
    {
        return count($this->loadIcnfItems($query)['items']);
    }

    public function scenario(array $query = [], ?array $override = null): array
    {
        $override = $override ?? [];
        $defaults = $this->defaultScenario();

        return [
            'rain_1h_mm' => $this->floatFrom($override['rain_1h_mm'] ?? $query['rain_1h_mm'] ?? $defaults['rain_1h_mm'] ?? 28.0, 0.0, 250.0),
            'rain_24h_mm' => $this->floatFrom($override['rain_24h_mm'] ?? $query['rain_24h_mm'] ?? $defaults['rain_24h_mm'] ?? 72.0, 0.0, 500.0),
            'soil_saturation' => $this->floatFrom($override['soil_saturation'] ?? $query['soil_saturation'] ?? $defaults['soil_saturation'] ?? 0.68, 0.0, 1.0),
        ];
    }

    public function defaultScenario(): array
    {
        $path = __DIR__ . '/data/default_scenario.json';
        if (!is_file($path)) {
            return [
                'rain_1h_mm' => 28.0,
                'rain_24h_mm' => 72.0,
                'soil_saturation' => 0.68,
                'description' => 'Default restored post-fire scenario.',
            ];
        }

        $data = json_decode((string)file_get_contents($path), true);
        if (!is_array($data)) {
            return [
                'rain_1h_mm' => 28.0,
                'rain_24h_mm' => 72.0,
                'soil_saturation' => 0.68,
                'description' => 'Default restored post-fire scenario.',
            ];
        }
        return $data;
    }


    public function perimeterStatus(array $query = []): array
    {
        $items = $this->loadIcnfItems($query)['items'];
        return $this->perimeters()->cacheStatus($items);
    }

    public function prefetchPerimeters(array $query = []): array
    {
        $items = $this->loadIcnfItems($query)['items'];
        $limit = max(1, min(50, (int)($query['limit'] ?? 10)));
        $force = filter_var($query['force'] ?? false, FILTER_VALIDATE_BOOL);
        return $this->perimeters()->prefetch($items, $limit, $force);
    }

    private function loadIcnfItems(array $query = []): array
    {
        $source = strtolower(trim((string) ($query['source'] ?? 'auto')));
        $providerSource = match ($source) {
            'test' => 'test',
            'refresh' => 'refresh',
            'remote' => 'remote',
            default => 'best',
        };

        $provider = new IcnfActiveOccurrencesProvider($this->projectRoot, $this->timezone);
        $sourceResult = $provider->all(['source' => $providerSource]);

        if (($sourceResult['success'] ?? false) !== true) {
            return [
                'source_mode' => $sourceResult['source_mode'] ?? $providerSource,
                'source' => $sourceResult['source'] ?? null,
                'source_provider' => 'icnf_active_occurrences',
                'central_data_provider' => true,
                'items' => [],
                'error' => $sourceResult['error'] ?? 'Unable to load central ICNF source.',
            ];
        }

        return [
            'source_mode' => $sourceResult['source_mode'] ?? $providerSource,
            'source' => $sourceResult['source'] ?? null,
            'source_provider' => 'icnf_active_occurrences',
            'central_data_provider' => true,
            'items' => array_values(array_filter($sourceResult['data'] ?? [], 'is_array')),
        ];
    }

    private function isBurnedAreaCandidate(array $item): bool
    {
        $area = $this->areaHa($item);
        if ($area <= 0.0) {
            return false;
        }

        $class = $item['classification'] ?? [];
        if (is_array($class) && (($class['falso_alarme'] ?? false) === true)) {
            return false;
        }

        return is_numeric($item['latitude'] ?? null) && is_numeric($item['longitude'] ?? null);
    }

    private function itemToFeature(array $item, DateTimeImmutable $date, DateTimeImmutable $now): ?array
    {
        $lat = $item['latitude'] ?? null;
        $lon = $item['longitude'] ?? null;
        if (!is_numeric($lat) || !is_numeric($lon)) {
            return null;
        }

        $areaHa = $this->areaHa($item);
        $radiusM = $this->radiusFromAreaHa($areaHa);
        $severity = $this->assumedBurnSeverity($item);
        $riskZones = $this->riskZones($item, $radiusM);
        $kmlGeometry = $this->perimeters()->geometryForItem($item);
        $geometry = $kmlGeometry ?? [
            'type' => 'Polygon',
            'coordinates' => [$this->circlePolygon((float)$lon, (float)$lat, $radiusM)],
        ];
        $geometrySource = $kmlGeometry !== null ? 'cached-icnf-kml-kmz-perimeter' : 'centroid-area-fallback';
        $start = $this->dateToIso($item['ended_at'] ?? '') ?: $this->dateToIso($item['started_at'] ?? '') ?: $date->format(DateTimeInterface::ATOM);
        $id = (string)($item['id'] ?? $item['codigo'] ?? sha1(json_encode($item)));
        $name = trim((string)($item['name'] ?? $item['local'] ?? $item['municipality'] ?? $id));

        return [
            'type' => 'Feature',
            'id' => $id,
            'geometry' => $geometry,
            'properties' => [
                'external_id' => $id,
                'codigo' => $item['codigo'] ?? $id,
                'name' => $name,
                'source' => 'ICNF',
                'district' => $item['district'] ?? '',
                'municipality' => $item['municipality'] ?? '',
                'freguesia' => $item['freguesia'] ?? '',
                'local' => $item['local'] ?? '',
                'start_local' => $start,
                'started_at' => $item['started_at'] ?? null,
                'ended_at' => $item['ended_at'] ?? null,
                'burned_area_ha' => $areaHa,
                'area_total_ha' => $areaHa,
                'area_povoamento_ha' => $item['area']['povoamento'] ?? null,
                'area_mato_ha' => $item['area']['mato'] ?? null,
                'area_agricola_ha' => $item['area']['agricola'] ?? null,
                'assumed_burn_severity' => $severity,
                'lat' => (float)$lat,
                'lon' => (float)$lon,
                'slope_deg' => $item['terrain']['declive_medio'] ?? null,
                'altitude_m' => $item['terrain']['altitude_media'] ?? null,
                'aps' => $item['aps'] ?? '',
                'forest_perimeter' => $item['forest_perimeter'] ?? '',
                'kml_url' => $item['kml_url'] ?? '',
                'geometry_source' => $geometrySource,
                'perimeter_cached' => $kmlGeometry !== null,
                'risk_zones' => $riskZones,
                'icnf_fields_used' => ['LAT', 'LON', 'AREATOTAL', 'DHFIM/DHINICIO', 'DECLIVEMEDIO', 'TIPO', 'APS', 'PERIMETRO'],
            ],
        ];
    }

    private function riskZones(array $item, float $radiusM): array
    {
        $slope = $this->floatOr($item['terrain']['declive_medio'] ?? null, 10.0);
        $area = $this->areaHa($item);
        $mato = $this->floatOr($item['area']['mato'] ?? null, 0.0);
        $povoamento = $this->floatOr($item['area']['povoamento'] ?? null, 0.0);
        $agricola = $this->floatOr($item['area']['agricola'] ?? null, 0.0);
        $soilBase = min(1.0, max(0.25, 0.38 + ($slope / 80.0) + min($mato + $povoamento, $area) / max(1.0, $area) * 0.24 - ($agricola / max(1.0, $area) * 0.08)));
        $nearProtected = trim((string)($item['aps'] ?? '')) !== '' || trim((string)($item['forest_perimeter'] ?? '')) !== '';
        $distance = $nearProtected ? 120.0 : max(90.0, 360.0 - min(260.0, $area * 8.0) - min(80.0, $slope));

        $lat = $this->floatOr($item['latitude'] ?? null, 0.0);
        $lon = $this->floatOr($item['longitude'] ?? null, 0.0);
        $downstreamOffsetM = min(1800.0, max(90.0, $radiusM * 0.75));
        $slopeOffsetM = min(1400.0, max(70.0, $radiusM * 0.45));
        $latDownstream = $lat - ($downstreamOffsetM / 111320.0);
        $latSlope = $lat + ($slopeOffsetM / 111320.0);

        return [
            [
                'zone_id' => 'downstream',
                'name' => 'Downstream runoff / drainage check',
                'exposure' => 'Potential runoff concentration below burned area; check culverts, roads, bridges and drainage lines.',
                'lat' => round($latDownstream, 7),
                'lon' => round($lon, 7),
                'slope_deg' => round($slope, 2),
                'distance_to_stream_m' => round($distance, 1),
                'distance_to_drainage_m' => round($distance, 1),
                'soil_factor' => round($soilBase, 3),
                'radius_m' => round($radiusM, 1),
            ],
            [
                'zone_id' => 'slope',
                'name' => 'Slope erosion / instability check',
                'exposure' => 'Potential erosion or shallow instability on burned slopes and tracks after heavy rain.',
                'lat' => round($latSlope, 7),
                'lon' => round($lon, 7),
                'slope_deg' => round(max($slope, $slope * 1.18), 2),
                'distance_to_stream_m' => round($distance + 110.0, 1),
                'distance_to_drainage_m' => round($distance + 110.0, 1),
                'soil_factor' => round(min(1.0, $soilBase + 0.12), 3),
                'radius_m' => round($radiusM * 0.75, 1),
            ],
        ];
    }


    private function perimeters(): KmlPerimeterRepository
    {
        if ($this->perimeters === null) {
            $this->perimeters = new KmlPerimeterRepository($this->projectRoot);
        }
        return $this->perimeters;
    }

    private function months(array $query): int
    {
        $months = (int)($query['months'] ?? 12);
        return max(1, min(60, $months));
    }

    private function now(array $query): DateTimeImmutable
    {
        $zone = new DateTimeZone($this->timezone);
        $reference = trim((string)($query['reference_date'] ?? ''));
        if ($reference !== '') {
            try {
                return new DateTimeImmutable($reference, $zone);
            } catch (Throwable) {
            }
        }
        return new DateTimeImmutable('now', $zone);
    }

    private function eventDate(array $item): ?DateTimeImmutable
    {
        foreach (['ended_at', 'started_at', 'dh_fim', 'dh_inicio', 'data_alerta'] as $field) {
            if (!isset($item[$field]) || trim((string)$item[$field]) === '') {
                continue;
            }
            $iso = $this->dateToIso((string)$item[$field]);
            if ($iso !== null) {
                try {
                    return new DateTimeImmutable($iso, new DateTimeZone($this->timezone));
                } catch (Throwable) {
                }
            }
        }
        return null;
    }

    private function dateToIso(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value)) {
            try {
                return (new DateTimeImmutable($value))->format(DateTimeInterface::ATOM);
            } catch (Throwable) {
                return null;
            }
        }
        if (preg_match('/^(\d{2})-(\d{2})-(\d{4})(?:\s+(\d{2}:\d{2}(?::\d{2})?))?/', $value, $m)) {
            $time = $m[4] ?? '00:00:00';
            if (substr_count($time, ':') === 1) {
                $time .= ':00';
            }
            try {
                return (new DateTimeImmutable($m[3] . '-' . $m[2] . '-' . $m[1] . ' ' . $time, new DateTimeZone($this->timezone)))->format(DateTimeInterface::ATOM);
            } catch (Throwable) {
                return null;
            }
        }
        try {
            return (new DateTimeImmutable($value, new DateTimeZone($this->timezone)))->format(DateTimeInterface::ATOM);
        } catch (Throwable) {
            return null;
        }
    }

    private function areaHa(array $item): float
    {
        $area = $item['area']['total'] ?? $item['areatotal'] ?? 0.0;
        return max(0.0, $this->floatOr($area, 0.0));
    }

    private function radiusFromAreaHa(float $areaHa): float
    {
        $areaM2 = max(100.0, $areaHa * 10000.0);
        $radius = sqrt($areaM2 / pi()) * 1.25;
        return max(35.0, min(2000.0, $radius));
    }

    private function assumedBurnSeverity(array $item): float
    {
        $area = $this->areaHa($item);
        $slope = $this->floatOr($item['terrain']['declive_medio'] ?? null, 10.0);
        $fwi = $this->floatOr($item['fire_weather_indices']['fwi'] ?? null, 0.0);
        $incendio = ($item['classification']['incendio'] ?? false) === true ? 0.14 : 0.0;
        $severity = 0.38 + min(0.25, sqrt($area) / 18.0) + min(0.18, $slope / 180.0) + min(0.08, $fwi / 100.0) + $incendio;
        return round(max(0.2, min(0.95, $severity)), 3);
    }

    private function circlePolygon(float $lon, float $lat, float $radiusM, int $segments = 48): array
    {
        $coordinates = [];
        $earthRadius = 6378137.0;
        $latRad = deg2rad($lat);

        for ($i = 0; $i <= $segments; $i++) {
            $bearing = 2.0 * pi() * ($i / $segments);
            $dLat = ($radiusM * cos($bearing)) / $earthRadius;
            $dLon = ($radiusM * sin($bearing)) / ($earthRadius * max(0.000001, cos($latRad)));
            $coordinates[] = [
                round($lon + rad2deg($dLon), 7),
                round($lat + rad2deg($dLat), 7),
            ];
        }
        return $coordinates;
    }

    private function floatOr(mixed $value, float $default): float
    {
        if (is_int($value) || is_float($value)) {
            return (float)$value;
        }
        if (is_string($value) && is_numeric(str_replace(',', '.', $value))) {
            return (float)str_replace(',', '.', $value);
        }
        return $default;
    }

    private function floatFrom(mixed $value, float $min, float $max): float
    {
        return max($min, min($max, $this->floatOr($value, $min)));
    }
}
