<?php

require_once __DIR__ . '/../../core/IcnfActiveOccurrencesProvider.php';

class PropagationEngine
{

    private string $basePath;
    private string $timezone;

    public function __construct(string $basePath, string $timezone = 'Europe/Lisbon')
    {
        $this->basePath = rtrim($basePath, DIRECTORY_SEPARATOR);
        $this->timezone = $timezone !== '' ? $timezone : 'Europe/Lisbon';
    }

    public function forecast(array $query = []): array
    {
        $targetDate = $this->resolveTargetDate($query);
        $sourceMode = strtolower(trim((string) ($query['source'] ?? 'best')));
        $limit = $this->resolveLimit($query);
        $horizons = $this->resolveHorizons($query);

        $sourceResult = $this->loadOccurrences($sourceMode);
        $items = $this->extractItems($sourceResult);
        $filtered = $this->filterByDate($items, $targetDate);
        $usable = [];

        foreach ($filtered as $item) {
            if ($this->validCoordinate($item['latitude'] ?? null) && $this->validCoordinate($item['longitude'] ?? null)) {
                $usable[] = $item;
            }
        }

        usort($usable, function (array $a, array $b): int {
            $score = $this->rankingScore($b) <=> $this->rankingScore($a);
            if ($score !== 0) {
                return $score;
            }
            return strcmp((string) ($b['started_at'] ?? ''), (string) ($a['started_at'] ?? ''));
        });

        $limited = array_slice($usable, 0, $limit);
        $projections = [];

        foreach ($limited as $item) {
            $projections[] = $this->projectOccurrence($item, $horizons);
        }

        return [
            'success' => ($sourceResult['success'] ?? false) === true,
            'module' => 'propagation',
            'version' => '0.7.2',
            'source_provider' => 'icnf_active_occurrences',
            'central_data_provider' => true,
            'date_filter_enabled' => true,
            'filter_date' => $targetDate,
            'timezone' => $this->timezone,
            'source_mode' => $sourceResult['mode'] ?? $sourceMode,
            'source_warning' => $sourceResult['warning'] ?? null,
            'source_error' => $sourceResult['error'] ?? null,
            'total_count' => count($items),
            'date_count' => count($filtered),
            'usable_count' => count($usable),
            'count' => count($projections),
            'limit' => $limit,
            'horizons_minutes' => $horizons,
            'projections' => $projections,
            'overview' => $this->buildOverview($projections, $filtered, $usable),
            'method' => $this->methodDescription(),
        ];
    }

    public function geoJson(array $query = []): array
    {
        $forecast = $this->forecast($query);
        $features = [];

        foreach ($forecast['projections'] as $projection) {
            foreach (($projection['horizons'] ?? []) as $horizon) {
                if (!isset($horizon['polygon']) || !is_array($horizon['polygon'])) {
                    continue;
                }

                $features[] = [
                    'type' => 'Feature',
                    'geometry' => [
                        'type' => 'Polygon',
                        'coordinates' => [$horizon['polygon']],
                    ],
                    'properties' => [
                        'id' => $projection['id'],
                        'codigo' => $projection['codigo'],
                        'name' => $projection['name'],
                        'district' => $projection['district'],
                        'municipality' => $projection['municipality'],
                        'horizon_minutes' => $horizon['minutes'],
                        'front_distance_m' => $horizon['front_distance_m'],
                        'lateral_distance_m' => $horizon['lateral_distance_m'],
                        'model' => $horizon['model'],
                        'risk_level' => $projection['risk_level'],
                        'confidence' => $projection['confidence']['level'] ?? 'low',
                        'spread_rate_m_per_h' => $projection['spread']['rate_m_per_h'],
                    ],
                ];
            }
        }

        $forecast['geojson'] = [
            'type' => 'FeatureCollection',
            'features' => $features,
        ];

        return $forecast;
    }

    public function methodDescription(): array
    {
        return [
            'name' => 'FireRisk v0.6 deterministic propagation preview',
            'purpose' => 'Generate a tactical spread preview around current-day ICNF occurrences using fields already parsed by FireRisk.',
            'not_a_scientific_prediction' => true,
            'not_a_substitute_for_fire_behaviour_officer' => true,
            'main_inputs' => [
                'ICNF occurrence coordinates and date fields',
                'ISI, FWI, FFMC, BUI and DSR when available',
                'wind intensity and wind direction vector when available',
                'average slope and reported area growth when available',
            ],
            'important_limitations' => [
                'No live fuel model grid yet',
                'No calibrated Rothermel/FARSITE implementation yet',
                'No terrain raster or wind field interpolation yet',
                'When wind direction is missing, the module draws radial uncertainty zones instead of a directional head-fire ellipse',
            ],
            'outputs' => [
                '30/60/120 minute tactical polygons by default',
                'spread rate in metres per hour',
                'confidence level and explanation',
                'operational notes for each projected occurrence',
            ],
        ];
    }

    private function loadOccurrences(string $sourceMode): array
    {
        $sourceMode = strtolower(trim($sourceMode));
        $source = match ($sourceMode) {
            'test' => 'test',
            'remote' => 'remote',
            'refresh' => 'refresh',
            default => 'best',
        };

        $provider = new IcnfActiveOccurrencesProvider($this->basePath, $this->timezone);
        $result = $provider->all(['source' => $source]);
        $result['mode'] = $result['source_mode'] ?? $source;
        $result['source_provider'] = 'icnf_active_occurrences';
        $result['central_data_provider'] = true;

        return $result;
    }

    private function extractItems(array $sourceResult): array
    {
        $payload = $sourceResult['data'] ?? [];

        if (isset($payload['items']) && is_array($payload['items'])) {
            return $payload['items'];
        }

        if (is_array($payload)) {
            $isList = array_keys($payload) === range(0, count($payload) - 1);
            if ($isList) {
                return $payload;
            }
        }

        return [];
    }

    private function projectOccurrence(array $item, array $horizons): array
    {
        $lat = (float) $item['latitude'];
        $lon = (float) $item['longitude'];
        $spread = $this->estimateSpread($item);
        $direction = $this->resolveDirection($item);
        $confidence = $this->confidence($item, $direction);
        $riskLevel = $this->riskLevel($item, $spread['rate_m_per_h']);
        $horizonOutput = [];

        foreach ($horizons as $minutes) {
            $hours = $minutes / 60;
            $front = max(50.0, $spread['rate_m_per_h'] * $hours);
            $lateral = max(30.0, $front * $spread['lateral_ratio']);
            $back = max(20.0, $front * $spread['backing_ratio']);

            if ($direction['available']) {
                $polygon = $this->directionalEllipse($lat, $lon, $direction['degrees'], $front, $lateral, $back);
                $model = 'directional_ellipse';
            } else {
                $radius = max(50.0, $front * 0.65);
                $polygon = $this->circlePolygon($lat, $lon, $radius);
                $model = 'radial_uncertainty';
            }

            $horizonOutput[] = [
                'minutes' => $minutes,
                'model' => $model,
                'front_distance_m' => round($front, 1),
                'lateral_distance_m' => round($lateral, 1),
                'backing_distance_m' => round($back, 1),
                'polygon' => $polygon,
            ];
        }

        return [
            'id' => (string) ($item['id'] ?? $item['codigo'] ?? sha1(json_encode($item))),
            'codigo' => (string) ($item['codigo'] ?? $item['ncco'] ?? ''),
            'name' => (string) ($item['name'] ?? $item['local'] ?? 'ICNF occurrence'),
            'district' => (string) ($item['district'] ?? ''),
            'municipality' => (string) ($item['municipality'] ?? ''),
            'freguesia' => (string) ($item['freguesia'] ?? ''),
            'local' => (string) ($item['local'] ?? ''),
            'started_at' => $item['started_at'] ?? $item['dh_inicio'] ?? null,
            'coordinates' => [
                'latitude' => $lat,
                'longitude' => $lon,
            ],
            'risk_level' => $riskLevel,
            'spread' => $spread,
            'direction' => $direction,
            'confidence' => $confidence,
            'metrics' => $this->metrics($item),
            'horizons' => $horizonOutput,
            'operational_notes' => $this->operationalNotes($item, $spread, $direction, $confidence, $riskLevel),
        ];
    }

    private function estimateSpread(array $item): array
    {
        $indices = is_array($item['fire_weather_indices'] ?? null) ? $item['fire_weather_indices'] : [];
        $weather = is_array($item['weather'] ?? null) ? $item['weather'] : [];
        $terrain = is_array($item['terrain'] ?? null) ? $item['terrain'] : [];
        $area = is_array($item['area'] ?? null) ? $item['area'] : [];

        $isi = $this->floatOrNull($indices['isi'] ?? null) ?? 0.0;
        $fwi = $this->floatOrNull($indices['fwi'] ?? null) ?? 0.0;
        $ffmc = $this->floatOrNull($indices['ffmc'] ?? null) ?? 0.0;
        $wind = $this->floatOrNull($weather['wind_intensity'] ?? null) ?? 0.0;
        $rh = $this->floatOrNull($weather['relative_humidity'] ?? null);
        $precip = $this->floatOrNull($weather['precipitation'] ?? null) ?? 0.0;
        $slope = $this->floatOrNull($terrain['declive_medio'] ?? null) ?? 0.0;
        $haPerHour = $this->floatOrNull($area['ha_per_hour'] ?? null) ?? 0.0;

        $rate = 70.0;
        $rate += min(900.0, max(0.0, $isi) * 65.0);
        $rate += min(420.0, max(0.0, $fwi) * 28.0);
        $rate += min(420.0, max(0.0, $wind) * 16.0);
        $rate += min(320.0, max(0.0, $slope) * 8.0);
        $rate += min(500.0, max(0.0, $haPerHour) * 80.0);

        if ($ffmc >= 88.0) {
            $rate += 140.0;
        } elseif ($ffmc >= 82.0) {
            $rate += 70.0;
        }

        if ($rh !== null && $rh < 30.0) {
            $rate += 180.0;
        } elseif ($rh !== null && $rh < 45.0) {
            $rate += 100.0;
        } elseif ($rh !== null && $rh > 80.0) {
            $rate -= 80.0;
        }

        if ($precip > 0.05) {
            $rate -= 180.0;
        }

        $rate = max(60.0, min(3000.0, $rate));

        $lateralRatio = 0.33;
        if ($wind >= 20.0 || $isi >= 8.0) {
            $lateralRatio = 0.25;
        } elseif ($wind < 5.0 && $isi < 2.0) {
            $lateralRatio = 0.45;
        }

        return [
            'rate_m_per_h' => round($rate, 1),
            'lateral_ratio' => $lateralRatio,
            'backing_ratio' => 0.18,
            'inputs_used' => [
                'isi' => $this->floatOrNull($indices['isi'] ?? null),
                'fwi' => $this->floatOrNull($indices['fwi'] ?? null),
                'ffmc' => $this->floatOrNull($indices['ffmc'] ?? null),
                'wind_intensity' => $this->floatOrNull($weather['wind_intensity'] ?? null),
                'relative_humidity' => $rh,
                'precipitation' => $this->floatOrNull($weather['precipitation'] ?? null),
                'slope' => $this->floatOrNull($terrain['declive_medio'] ?? null),
                'ha_per_hour' => $this->floatOrNull($area['ha_per_hour'] ?? null),
            ],
        ];
    }

    private function resolveDirection(array $item): array
    {
        $weather = is_array($item['weather'] ?? null) ? $item['weather'] : [];
        $raw = $this->floatOrNull($weather['wind_direction_vector'] ?? null);

        if ($raw !== null) {
            $degrees = fmod(($raw + 360.0), 360.0);
            return [
                'available' => true,
                'degrees' => round($degrees, 1),
                'source' => 'VENTODIRECAO_VETOR',
                'note' => 'Directional ellipse follows the available wind direction vector from the source feed.',
            ];
        }

        return [
            'available' => false,
            'degrees' => null,
            'source' => null,
            'note' => 'Wind direction is missing in the source record; radial uncertainty zones are used instead of a directional head-fire estimate.',
        ];
    }

    private function confidence(array $item, array $direction): array
    {
        $inputs = 0;
        $missing = [];
        $indices = is_array($item['fire_weather_indices'] ?? null) ? $item['fire_weather_indices'] : [];
        $weather = is_array($item['weather'] ?? null) ? $item['weather'] : [];
        $terrain = is_array($item['terrain'] ?? null) ? $item['terrain'] : [];

        foreach ([
            'isi' => $indices['isi'] ?? null,
            'fwi' => $indices['fwi'] ?? null,
            'wind_intensity' => $weather['wind_intensity'] ?? null,
            'relative_humidity' => $weather['relative_humidity'] ?? null,
            'slope' => $terrain['declive_medio'] ?? null,
        ] as $key => $value) {
            if ($this->floatOrNull($value) !== null) {
                $inputs++;
            } else {
                $missing[] = $key;
            }
        }

        if ($direction['available']) {
            $inputs += 2;
        } else {
            $missing[] = 'wind_direction';
        }

        $level = 'low';
        if ($inputs >= 7) {
            $level = 'high';
        } elseif ($inputs >= 4) {
            $level = 'medium';
        }

        return [
            'level' => $level,
            'score' => $inputs,
            'missing' => $missing,
        ];
    }

    private function riskLevel(array $item, float $rate): string
    {
        $metrics = $this->metrics($item);
        $score = 0;

        if ($rate >= 1600) {
            $score += 4;
        } elseif ($rate >= 900) {
            $score += 3;
        } elseif ($rate >= 450) {
            $score += 2;
        } elseif ($rate >= 200) {
            $score += 1;
        }

        if (($metrics['fwi'] ?? 0) >= 8) {
            $score += 2;
        } elseif (($metrics['fwi'] ?? 0) >= 4) {
            $score += 1;
        }

        if (($metrics['area_total_ha'] ?? 0) >= 10) {
            $score += 3;
        } elseif (($metrics['area_total_ha'] ?? 0) >= 2) {
            $score += 2;
        } elseif (($metrics['area_total_ha'] ?? 0) >= 0.25) {
            $score += 1;
        }

        if (($metrics['slope'] ?? 0) >= 25) {
            $score += 1;
        }

        if ($score >= 7) {
            return 'critical';
        }
        if ($score >= 5) {
            return 'high';
        }
        if ($score >= 3) {
            return 'medium';
        }
        return 'low';
    }

    private function metrics(array $item): array
    {
        $indices = is_array($item['fire_weather_indices'] ?? null) ? $item['fire_weather_indices'] : [];
        $weather = is_array($item['weather'] ?? null) ? $item['weather'] : [];
        $terrain = is_array($item['terrain'] ?? null) ? $item['terrain'] : [];
        $area = is_array($item['area'] ?? null) ? $item['area'] : [];

        return [
            'area_total_ha' => $this->floatOrNull($area['total'] ?? $item['area_total'] ?? null),
            'ha_per_hour' => $this->floatOrNull($area['ha_per_hour'] ?? null),
            'fwi' => $this->floatOrNull($indices['fwi'] ?? null),
            'isi' => $this->floatOrNull($indices['isi'] ?? null),
            'ffmc' => $this->floatOrNull($indices['ffmc'] ?? null),
            'bui' => $this->floatOrNull($indices['bui'] ?? null),
            'dsr' => $this->floatOrNull($indices['dsr'] ?? null),
            'wind_intensity' => $this->floatOrNull($weather['wind_intensity'] ?? null),
            'relative_humidity' => $this->floatOrNull($weather['relative_humidity'] ?? null),
            'precipitation' => $this->floatOrNull($weather['precipitation'] ?? null),
            'slope' => $this->floatOrNull($terrain['declive_medio'] ?? null),
        ];
    }

    private function operationalNotes(array $item, array $spread, array $direction, array $confidence, string $riskLevel): array
    {
        $notes = [];

        if (!$direction['available']) {
            $notes[] = 'Confirm wind direction on scene before using the spread shape tactically.';
        }

        if (($spread['rate_m_per_h'] ?? 0) >= 900) {
            $notes[] = 'Potential fast spread: prioritize safe anchor points and avoid unsupported direct attack on the head.';
        }

        if ($riskLevel === 'critical' || $riskLevel === 'high') {
            $notes[] = 'Consider early reinforcement, sectorization and protection of exposed values.';
        }

        $metrics = $this->metrics($item);
        if (($metrics['slope'] ?? 0) >= 25) {
            $notes[] = 'Slope is significant; check uphill run potential and escape routes.';
        }

        if (($confidence['level'] ?? 'low') === 'low') {
            $notes[] = 'Low confidence preview: use only as an orientation aid until local observations improve the inputs.';
        }

        return $notes;
    }

    private function directionalEllipse(float $lat, float $lon, float $bearing, float $front, float $lateral, float $back): array
    {
        $points = [];
        $steps = 40;
        $centerShift = ($front - $back) / 2.0;
        $semiMajor = ($front + $back) / 2.0;
        $semiMinor = $lateral;

        for ($i = 0; $i <= $steps; $i++) {
            $theta = (2.0 * M_PI * $i) / $steps;
            $x = cos($theta) * $semiMajor + $centerShift;
            $y = sin($theta) * $semiMinor;
            $distance = sqrt(($x * $x) + ($y * $y));
            $angleOffset = rad2deg(atan2($y, $x));
            $pointBearing = $bearing + $angleOffset;
            $points[] = $this->destination($lat, $lon, $pointBearing, $distance);
        }

        return $points;
    }

    private function circlePolygon(float $lat, float $lon, float $radius): array
    {
        $points = [];
        $steps = 40;
        for ($i = 0; $i <= $steps; $i++) {
            $bearing = (360.0 * $i) / $steps;
            $points[] = $this->destination($lat, $lon, $bearing, $radius);
        }
        return $points;
    }

    private function destination(float $lat, float $lon, float $bearing, float $distanceMeters): array
    {
        $earthRadius = 6371008.8;
        $delta = $distanceMeters / $earthRadius;
        $theta = deg2rad($bearing);
        $phi1 = deg2rad($lat);
        $lambda1 = deg2rad($lon);

        $sinPhi2 = sin($phi1) * cos($delta) + cos($phi1) * sin($delta) * cos($theta);
        $phi2 = asin(max(-1.0, min(1.0, $sinPhi2)));
        $lambda2 = $lambda1 + atan2(
            sin($theta) * sin($delta) * cos($phi1),
            cos($delta) - sin($phi1) * sin($phi2)
        );

        $lon2 = fmod((rad2deg($lambda2) + 540.0), 360.0) - 180.0;
        return [round($lon2, 7), round(rad2deg($phi2), 7)];
    }

    private function buildOverview(array $projections, array $filtered, array $usable): array
    {
        $levels = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
        $maxRate = null;
        $lowConfidence = 0;
        $directional = 0;

        foreach ($projections as $projection) {
            $level = $projection['risk_level'] ?? 'low';
            if (isset($levels[$level])) {
                $levels[$level]++;
            }

            $rate = $projection['spread']['rate_m_per_h'] ?? null;
            if ($rate !== null) {
                $maxRate = $maxRate === null ? $rate : max($maxRate, $rate);
            }

            if (($projection['confidence']['level'] ?? 'low') === 'low') {
                $lowConfidence++;
            }

            if (($projection['direction']['available'] ?? false) === true) {
                $directional++;
            }
        }

        return [
            'records_for_date' => count($filtered),
            'usable_coordinates' => count($usable),
            'projected_records' => count($projections),
            'risk_levels' => $levels,
            'max_spread_rate_m_per_h' => $maxRate,
            'directional_projection_count' => $directional,
            'radial_uncertainty_count' => max(0, count($projections) - $directional),
            'low_confidence_count' => $lowConfidence,
        ];
    }

    private function rankingScore(array $item): float
    {
        $metrics = $this->metrics($item);
        return (($metrics['area_total_ha'] ?? 0) * 4)
            + (($metrics['fwi'] ?? 0) * 8)
            + (($metrics['isi'] ?? 0) * 10)
            + (($metrics['wind_intensity'] ?? 0) * 2)
            + (($metrics['slope'] ?? 0) * 1.5);
    }

    private function filterByDate(array $items, string $targetDate): array
    {
        return array_values(array_filter($items, function (array $item) use ($targetDate): bool {
            return $this->extractDate($item) === $targetDate;
        }));
    }

    private function extractDate(array $item): ?string
    {
        foreach (['data_alerta', 'started_at', 'dh_inicio', 'ended_at'] as $key) {
            if (!empty($item[$key])) {
                $date = $this->parseDate((string) $item[$key]);
                if ($date !== null) {
                    return $date;
                }
            }
        }
        return null;
    }

    private function parseDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^(\d{2})-(\d{2})-(\d{4})/', $value, $match)) {
            return sprintf('%04d-%02d-%02d', (int) $match[3], (int) $match[2], (int) $match[1]);
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $match)) {
            return sprintf('%04d-%02d-%02d', (int) $match[1], (int) $match[2], (int) $match[3]);
        }

        try {
            $zone = new DateTimeZone($this->timezone);
            return (new DateTimeImmutable($value, $zone))->setTimezone($zone)->format('Y-m-d');
        } catch (Throwable $error) {
            return null;
        }
    }

    private function resolveTargetDate(array $query): string
    {
        $date = trim((string) ($query['date'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $date;
        }

        return (new DateTimeImmutable('now', new DateTimeZone($this->timezone)))->format('Y-m-d');
    }

    private function resolveLimit(array $query): int
    {
        $limit = (int) ($query['limit'] ?? 50);
        return max(1, min(200, $limit));
    }

    private function resolveHorizons(array $query): array
    {
        $raw = trim((string) ($query['horizons'] ?? $query['horizon'] ?? ''));
        if ($raw === '') {
            return [30, 60, 120];
        }

        $values = [];
        foreach (preg_split('/[,;\s]+/', $raw) ?: [] as $piece) {
            $minutes = (int) $piece;
            if ($minutes >= 10 && $minutes <= 360) {
                $values[] = $minutes;
            }
        }

        $values = array_values(array_unique($values));
        sort($values);
        return count($values) > 0 ? $values : [30, 60, 120];
    }

    private function validCoordinate($value): bool
    {
        return is_numeric($value) && abs((float) $value) <= 180;
    }

    private function floatOrNull($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        $normalized = str_replace(',', '.', (string) $value);
        return is_numeric($normalized) ? (float) $normalized : null;
    }
}
