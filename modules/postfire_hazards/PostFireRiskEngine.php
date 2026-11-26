<?php

declare(strict_types=1);

final class PostFireRiskEngine
{
    public static function num(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric(str_replace(',', '.', $value))) {
            return (float) str_replace(',', '.', $value);
        }
        return 0.0;
    }

    private static function clamp(float $value, float $low = 0.0, float $high = 1.0): float
    {
        return max($low, min($high, $value));
    }

    private static function level(float $score): string
    {
        if ($score >= 81.0) {
            return 'critical';
        }
        if ($score >= 61.0) {
            return 'high';
        }
        if ($score >= 41.0) {
            return 'medium';
        }
        if ($score >= 21.0) {
            return 'low';
        }
        return 'residual';
    }

    private static function label(string $level): string
    {
        return match ($level) {
            'critical' => 'Crítico',
            'high' => 'Elevado',
            'medium' => 'Moderado',
            'low' => 'Baixo',
            default => 'Residual',
        };
    }

    private static function daysSince(string $dateValue, DateTimeImmutable $now): int
    {
        try {
            $start = new DateTimeImmutable($dateValue);
        } catch (Throwable) {
            return 365;
        }

        $diffSeconds = $now->getTimestamp() - $start->getTimestamp();
        return max(0, (int) floor($diffSeconds / 86400));
    }

    private static function postFireFactor(float $burnedAreaHa, float $burnSeverity, int $daysAfterFire): float
    {
        $areaComponent = self::clamp(sqrt(max($burnedAreaHa, 0.0) / 120.0));
        $severityComponent = self::clamp($burnSeverity);
        $recencyComponent = self::clamp(exp(-$daysAfterFire / 365.0));

        return self::clamp(
            0.34 * $areaComponent +
            0.42 * $severityComponent +
            0.24 * $recencyComponent
        );
    }

    private static function floodScore(float $rain1hMm, float $rain24hMm, float $soilSaturation, float $distanceToDrainageM, float $postFire): float
    {
        $rain1h = self::clamp($rain1hMm / 35.0);
        $rain24h = self::clamp($rain24hMm / 95.0);
        $saturation = self::clamp($soilSaturation);
        $drainage = self::clamp(1.0 - $distanceToDrainageM / 450.0);

        $score =
            0.31 * $rain1h +
            0.22 * $rain24h +
            0.17 * $saturation +
            0.20 * $postFire +
            0.10 * $drainage;

        return round($score * 100.0, 1);
    }

    private static function erosionScore(float $slopeDeg, float $rain24hMm, float $soilSaturation, float $soilFactor, float $postFire): float
    {
        $slope = self::clamp(($slopeDeg - 5.0) / 35.0);
        $rain24h = self::clamp($rain24hMm / 105.0);
        $saturation = self::clamp($soilSaturation);
        $soil = self::clamp($soilFactor);

        $score =
            0.32 * $slope +
            0.20 * $rain24h +
            0.16 * $saturation +
            0.20 * $postFire +
            0.12 * $soil;

        return round($score * 100.0, 1);
    }

    private static function recommendations(float $flood, float $erosion, array $area): array
    {
        $recs = [];
        $maxScore = max($flood, $erosion);
        $name = (string) ($area['name'] ?? 'área ardida');

        if ($flood >= 61.0) {
            $recs[] = 'Verificar linhas de água, passagens hidráulicas, valetas, pontes pequenas e pontos baixos a jusante de ' . $name . '.';
        }
        if ($erosion >= 61.0) {
            $recs[] = 'Sinalizar encostas/taludes queimados e limitar exposição durante chuva forte ou prolongada.';
        }
        if ($maxScore >= 81.0) {
            $recs[] = 'Preparar reconhecimento preventivo, eventual corte de vias e aviso local antes de precipitação significativa.';
        } elseif ($maxScore >= 41.0) {
            $recs[] = 'Manter vigilância reforçada no primeiro episódio de chuva relevante após o incêndio.';
        } else {
            $recs[] = 'Manter monitorização normal de precipitação, escorrência superficial e assoreamento.';
        }

        return array_values(array_unique($recs));
    }

    public static function evaluateFeatureCollection(array $featureCollection, array $scenario, int $months, DateTimeImmutable $now, int $totalAvailableFeatures): array
    {
        $rain1h = max(0.0, min(250.0, self::num($scenario['rain_1h_mm'] ?? 0.0)));
        $rain24h = max(0.0, min(500.0, self::num($scenario['rain_24h_mm'] ?? 0.0)));
        $soilSaturation = self::clamp(self::num($scenario['soil_saturation'] ?? 0.5));

        $zones = [];
        $areaSummaries = [];
        $evaluatedFeatures = [];

        foreach (($featureCollection['features'] ?? []) as $feature) {
            if (!is_array($feature)) {
                continue;
            }

            $props = $feature['properties'] ?? [];
            $areaId = (string)($feature['id'] ?? $props['external_id'] ?? $props['codigo'] ?? 'ICNF-UNKNOWN');
            $areaName = (string)($props['name'] ?? $areaId);
            $startLocal = (string)($props['start_local'] ?? $props['ended_at'] ?? $props['started_at'] ?? '');
            $days = self::daysSince($startLocal, $now);
            $burnedAreaHa = self::num($props['burned_area_ha'] ?? $props['area_total_ha'] ?? 0);
            $severity = self::num($props['assumed_burn_severity'] ?? 0.55);
            $postFire = self::postFireFactor($burnedAreaHa, $severity, $days);

            $areaZones = [];
            foreach (($props['risk_zones'] ?? []) as $zone) {
                if (!is_array($zone)) {
                    continue;
                }

                $flood = self::floodScore(
                    $rain1h,
                    $rain24h,
                    $soilSaturation,
                    self::num($zone['distance_to_drainage_m'] ?? $zone['distance_to_stream_m'] ?? 260),
                    $postFire
                );
                $erosion = self::erosionScore(
                    self::num($zone['slope_deg'] ?? 10),
                    $rain24h,
                    $soilSaturation,
                    self::num($zone['soil_factor'] ?? 0.5),
                    $postFire
                );
                $combined = round(max($flood, $erosion) * 0.72 + (($flood + $erosion) / 2.0) * 0.28, 1);
                $combinedLevel = self::level($combined);

                $evaluated = array_merge($zone, [
                    'area_id' => $areaId,
                    'area_name' => $areaName,
                    'municipality' => $props['municipality'] ?? '',
                    'district' => $props['district'] ?? '',
                    'area_start_local' => $startLocal,
                    'burned_area_ha' => $burnedAreaHa,
                    'days_since_fire' => $days,
                    'post_fire_factor' => round($postFire, 3),
                    'flood_score' => $flood,
                    'flood_level' => self::level($flood),
                    'flood_level_label' => self::label(self::level($flood)),
                    'erosion_score' => $erosion,
                    'erosion_level' => self::level($erosion),
                    'erosion_level_label' => self::label(self::level($erosion)),
                    'landslide_score' => $erosion,
                    'landslide_level' => self::level($erosion),
                    'landslide_level_label' => self::label(self::level($erosion)),
                    'combined_score' => $combined,
                    'combined_level' => $combinedLevel,
                    'combined_level_label' => self::label($combinedLevel),
                    'recommendations' => self::recommendations($flood, $erosion, ['name' => $areaName]),
                ]);

                $zones[] = $evaluated;
                $areaZones[] = $evaluated;
            }

            $areaCombined = empty($areaZones) ? 0.0 : round(max(array_column($areaZones, 'combined_score')), 1);
            $areaFlood = empty($areaZones) ? 0.0 : round(max(array_column($areaZones, 'flood_score')), 1);
            $areaErosion = empty($areaZones) ? 0.0 : round(max(array_column($areaZones, 'erosion_score')), 1);
            $areaLevel = self::level($areaCombined);

            $areaSummaries[$areaId] = [
                'area_id' => $areaId,
                'name' => $areaName,
                'codigo' => $props['codigo'] ?? $areaId,
                'municipality' => $props['municipality'] ?? '',
                'district' => $props['district'] ?? '',
                'lat' => $props['lat'] ?? null,
                'lon' => $props['lon'] ?? null,
                'start_local' => $startLocal,
                'burned_area_ha' => $burnedAreaHa,
                'assumed_burn_severity' => $severity,
                'geometry_source' => $props['geometry_source'] ?? 'unknown',
                'perimeter_cached' => (bool)($props['perimeter_cached'] ?? false),
                'kml_url' => $props['kml_url'] ?? '',
                'days_since_fire' => $days,
                'zone_count' => count($areaZones),
                'flood_score' => $areaFlood,
                'flood_level' => self::level($areaFlood),
                'flood_level_label' => self::label(self::level($areaFlood)),
                'erosion_score' => $areaErosion,
                'erosion_level' => self::level($areaErosion),
                'erosion_level_label' => self::label(self::level($areaErosion)),
                'landslide_score' => $areaErosion,
                'landslide_level' => self::level($areaErosion),
                'landslide_level_label' => self::label(self::level($areaErosion)),
                'combined_score' => $areaCombined,
                'combined_level' => $areaLevel,
                'combined_level_label' => self::label($areaLevel),
                'recommendations' => empty($areaZones) ? [] : self::recommendations($areaFlood, $areaErosion, ['name' => $areaName]),
            ];

            $feature['properties']['risk_summary'] = $areaSummaries[$areaId];
            $feature['properties']['combined_score'] = $areaCombined;
            $feature['properties']['combined_level'] = $areaLevel;
            unset($feature['properties']['risk_zones']);
            $evaluatedFeatures[] = $feature;
        }

        usort($zones, static fn (array $a, array $b): int => ($b['combined_score'] <=> $a['combined_score']));
        usort($areaSummaries, static fn (array $a, array $b): int => ($b['combined_score'] <=> $a['combined_score']));

        $overallFlood = empty($zones) ? 0.0 : round(max(array_column($zones, 'flood_score')), 1);
        $overallErosion = empty($zones) ? 0.0 : round(max(array_column($zones, 'erosion_score')), 1);
        $overallCombined = empty($zones) ? 0.0 : round(max(array_column($zones, 'combined_score')), 1);
        $overallLevel = self::level($overallCombined);

        $windowFrom = $now->sub(new DateInterval('P' . $months . 'M'));
        return [
            'success' => true,
            'module' => 'postfire_hazards',
            'module_info' => [
                'id' => 'postfire_hazards',
                'name' => 'FireRisk Lite — Post-Fire Hazards',
                'version' => '0.6.3-firerisk-module',
                'operational_use' => false,
            ],
            'name' => 'Post-Fire Hazards',
            'generated_at' => $now->format(DateTimeInterface::ATOM),
            'data_source' => $featureCollection['meta']['source_mode'] ?? 'icnf-derived',
            'inputs' => [
                'rain_1h_mm' => $rain1h,
                'rain_24h_mm' => $rain24h,
                'soil_saturation' => $soilSaturation,
                'window_months' => $months,
                'window_from' => $windowFrom->format(DateTimeInterface::ATOM),
                'window_to' => $now->format(DateTimeInterface::ATOM),
                'burned_areas_total_available' => $totalAvailableFeatures,
                'burned_areas_in_window' => count($evaluatedFeatures),
                'burned_areas_excluded_by_window' => max(0, $totalAvailableFeatures - count($evaluatedFeatures)),
            ],
            'overall' => [
                'flood_score' => $overallFlood,
                'flood_level' => self::level($overallFlood),
                'flood_level_label' => self::label(self::level($overallFlood)),
                'erosion_score' => $overallErosion,
                'erosion_level' => self::level($overallErosion),
                'erosion_level_label' => self::label(self::level($overallErosion)),
                'landslide_score' => $overallErosion,
                'landslide_level' => self::level($overallErosion),
                'landslide_level_label' => self::label(self::level($overallErosion)),
                'combined_score' => $overallCombined,
                'combined_level' => $overallLevel,
                'combined_level_label' => self::label($overallLevel),
            ],
            'burned_areas_geojson' => [
                'type' => 'FeatureCollection',
                'features' => $evaluatedFeatures,
                'meta' => $featureCollection['meta'] ?? [],
            ],
            'areas' => array_values($areaSummaries),
            'zones' => $zones,
            'count' => count($areaSummaries),
        ];
    }
}
