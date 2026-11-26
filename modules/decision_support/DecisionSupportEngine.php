<?php

require_once __DIR__ . '/../../core/IcnfActiveOccurrencesProvider.php';

class DecisionSupportEngine
{

    private string $basePath;
    private string $timezone;

    public function __construct(string $basePath, string $timezone = 'Europe/Lisbon')
    {
        $this->basePath = rtrim($basePath, DIRECTORY_SEPARATOR);
        $this->timezone = $timezone !== '' ? $timezone : 'Europe/Lisbon';
    }

    public function analyze(array $query = []): array
    {
        $targetDate = $this->resolveTargetDate($query);
        $sourceMode = strtolower(trim((string) ($query['source'] ?? 'best')));
        $sourceResult = $this->loadOccurrences($sourceMode);
        $items = $this->extractItems($sourceResult);
        $filtered = $this->filterByDate($items, $targetDate);
        $analysed = [];

        foreach ($filtered as $item) {
            $analysed[] = $this->analyseOccurrence($item);
        }

        usort($analysed, static function (array $a, array $b): int {
            $score = ($b['risk_score'] ?? 0) <=> ($a['risk_score'] ?? 0);
            if ($score !== 0) {
                return $score;
            }

            return strcmp((string) ($b['started_at'] ?? ''), (string) ($a['started_at'] ?? ''));
        });

        $overview = $this->buildOverview($analysed, $filtered, $items, $targetDate);

        return [
            'success' => ($sourceResult['success'] ?? false) === true,
            'module' => 'decision_support',
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
            'count' => count($filtered),
            'overview' => $overview,
            'priorities' => array_slice($analysed, 0, 12),
            'recommendations' => $this->buildGeneralRecommendations($overview, $analysed),
            'method' => $this->methodDescription(),
        ];
    }

    public function methodDescription(): array
    {
        return [
            'name' => 'FireRisk v0.5 deterministic operational triage',
            'purpose' => 'Rank current-day occurrences by operational relevance using only fields already parsed from ICNF/local data.',
            'not_a_prediction' => true,
            'main_factors' => [
                'current status / missing end time',
                'burned area and ha/hour indicator',
                'FWI / ISI / FFMC / BUI / DSR fire-weather indices',
                'wind intensity, relative humidity and precipitation',
                'average slope and terrain exposure proxy',
                'protected area / forest perimeter context',
                'initial intervention delay and duration',
                'classification: incêndio, fogacho, agrícola, reacendimento',
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

    private function analyseOccurrence(array $item): array
    {
        $score = 0;
        $reasons = [];
        $actions = [];

        $status = strtolower((string) ($item['status'] ?? ''));
        $endedAt = trim((string) ($item['ended_at'] ?? $item['dh_fim'] ?? ''));
        if ($status === 'active' || $endedAt === '') {
            $score += 22;
            $reasons[] = 'Occurrence appears active or has no end time.';
            $actions[] = 'Confirm current status, active front location and immediate containment objective.';
        }

        $classification = is_array($item['classification'] ?? null) ? $item['classification'] : [];
        if ((int) ($classification['incendio'] ?? 0) === 1) {
            $score += 12;
            $reasons[] = 'Classified as incêndio rather than only fogacho/agricultural event.';
        } elseif ((int) ($classification['fogacho'] ?? 0) === 1) {
            $score += 3;
            $reasons[] = 'Classified as fogacho; keep monitoring for escalation.';
        }

        if ((int) ($classification['reacendimento'] ?? 0) === 1) {
            $score += 14;
            $reasons[] = 'Reacendimento flag present.';
            $actions[] = 'Check previous perimeter, hot spots and mop-up quality before committing to new direct attack.';
        }

        $area = is_array($item['area'] ?? null) ? $item['area'] : [];
        $areaTotal = $this->floatOrNull($area['total'] ?? $item['area_total'] ?? null);
        if ($areaTotal !== null) {
            if ($areaTotal >= 50) {
                $score += 30;
                $reasons[] = 'Very large burned area already reported.';
                $actions[] = 'Prioritize sectorization, command rhythm and protection of exposed values.';
            } elseif ($areaTotal >= 10) {
                $score += 24;
                $reasons[] = 'Large area reported.';
                $actions[] = 'Assess need for reinforcement before the incident exceeds initial attack capacity.';
            } elseif ($areaTotal >= 2) {
                $score += 16;
                $reasons[] = 'Area is already above small-occurrence scale.';
            } elseif ($areaTotal >= 0.25) {
                $score += 8;
                $reasons[] = 'Area is small but operationally relevant.';
            }
        }

        $haPerHour = $this->floatOrNull($area['ha_per_hour'] ?? null);
        if ($haPerHour !== null) {
            if ($haPerHour >= 5) {
                $score += 18;
                $reasons[] = 'High reported area growth rate.';
                $actions[] = 'Avoid unsupported direct attack on the head; anchor from safe flanks where possible.';
            } elseif ($haPerHour >= 1) {
                $score += 10;
                $reasons[] = 'Area growth rate is operationally significant.';
            }
        }

        $indices = is_array($item['fire_weather_indices'] ?? null) ? $item['fire_weather_indices'] : [];
        $fwi = $this->floatOrNull($indices['fwi'] ?? null);
        $isi = $this->floatOrNull($indices['isi'] ?? null);
        $ffmc = $this->floatOrNull($indices['ffmc'] ?? null);
        $bui = $this->floatOrNull($indices['bui'] ?? null);
        $dsr = $this->floatOrNull($indices['dsr'] ?? null);

        if ($fwi !== null) {
            if ($fwi >= 12) {
                $score += 24;
                $reasons[] = 'FWI is high for tactical concern.';
            } elseif ($fwi >= 6) {
                $score += 17;
                $reasons[] = 'FWI is moderate-high.';
            } elseif ($fwi >= 3) {
                $score += 10;
                $reasons[] = 'FWI is moderate.';
            }
        }

        if ($isi !== null) {
            if ($isi >= 8) {
                $score += 18;
                $reasons[] = 'ISI indicates potential for fast initial spread.';
                $actions[] = 'Use wind/head-fire direction as the first spread cue and reinforce anchor points.';
            } elseif ($isi >= 4) {
                $score += 12;
                $reasons[] = 'ISI is elevated.';
            }
        }

        if ($ffmc !== null && $ffmc >= 88) {
            $score += 8;
            $reasons[] = 'Fine fuels appear receptive by FFMC.';
        }

        if ($bui !== null && $bui >= 8) {
            $score += 6;
            $reasons[] = 'BUI suggests available fuel build-up.';
        }

        if ($dsr !== null && $dsr >= 1) {
            $score += 8;
            $reasons[] = 'DSR is elevated.';
        }

        $weather = is_array($item['weather'] ?? null) ? $item['weather'] : [];
        $wind = $this->floatOrNull($weather['wind_intensity'] ?? null);
        $humidity = $this->floatOrNull($weather['relative_humidity'] ?? null);
        $precipitation = $this->floatOrNull($weather['precipitation'] ?? null);

        if ($wind !== null) {
            if ($wind >= 25) {
                $score += 18;
                $reasons[] = 'Strong wind intensity.';
                $actions[] = 'Expect rapid change on the head and flanks; validate escape routes and LCES.';
            } elseif ($wind >= 15) {
                $score += 11;
                $reasons[] = 'Wind intensity is relevant to spread direction.';
            } elseif ($wind >= 8) {
                $score += 5;
                $reasons[] = 'Wind may still influence flame angle and spotting tendency.';
            }
        }

        if ($humidity !== null) {
            if ($humidity <= 35) {
                $score += 14;
                $reasons[] = 'Low relative humidity.';
            } elseif ($humidity <= 55) {
                $score += 8;
                $reasons[] = 'Relative humidity is not protective.';
            }
        }

        if ($precipitation !== null && $precipitation > 0.5) {
            $score -= 5;
            $reasons[] = 'Recent precipitation reduces immediate concern slightly.';
        }

        $terrain = is_array($item['terrain'] ?? null) ? $item['terrain'] : [];
        $slope = $this->floatOrNull($terrain['declive_medio'] ?? null);
        if ($slope !== null) {
            if ($slope >= 35) {
                $score += 18;
                $reasons[] = 'Steep average slope.';
                $actions[] = 'Avoid uphill direct attack without a secure anchor and lookout coverage.';
            } elseif ($slope >= 20) {
                $score += 10;
                $reasons[] = 'Slope may accelerate uphill spread.';
            }
        }

        $aps = trim((string) ($item['aps'] ?? ''));
        $perimeter = trim((string) ($item['forest_perimeter'] ?? ''));
        if ($aps !== '') {
            $score += 8;
            $reasons[] = 'Occurrence intersects or references protected area context.';
            $actions[] = 'Check protected-area constraints, access limits and sensitive values.';
        }
        if ($perimeter !== '') {
            $score += 6;
            $reasons[] = 'Occurrence references a forest perimeter.';
        }

        $delay = $this->initialInterventionDelayMinutes($item);
        if ($delay !== null) {
            if ($delay >= 60) {
                $score += 12;
                $reasons[] = 'Long delay between alert and first intervention.';
            } elseif ($delay >= 25) {
                $score += 6;
                $reasons[] = 'Initial intervention delay is noticeable.';
            }
        }

        $duration = $this->floatOrNull($item['duration_minutes'] ?? null);
        if ($duration !== null) {
            if ($duration >= 240) {
                $score += 12;
                $reasons[] = 'Long duration suggests containment difficulty or extended operations.';
            } elseif ($duration >= 90) {
                $score += 6;
                $reasons[] = 'Duration is above quick-resolution range.';
            }
        }

        $score = max(0, min(100, (int) round($score)));
        $level = $this->riskLevel($score);

        if (count($actions) === 0) {
            $actions[] = 'Maintain monitoring, verify access/water points and keep the information loop updated.';
        }

        return [
            'id' => $item['id'] ?? $item['codigo'] ?? sha1(json_encode($item)),
            'codigo' => $item['codigo'] ?? null,
            'name' => $item['name'] ?? trim(((string) ($item['tipo'] ?? '')) . ' - ' . ((string) ($item['local'] ?? ''))),
            'tipo' => $item['tipo'] ?? null,
            'district' => $item['district'] ?? null,
            'municipality' => $item['municipality'] ?? null,
            'freguesia' => $item['freguesia'] ?? null,
            'local' => $item['local'] ?? null,
            'latitude' => $item['latitude'] ?? null,
            'longitude' => $item['longitude'] ?? null,
            'started_at' => $item['started_at'] ?? null,
            'ended_at' => $item['ended_at'] ?? null,
            'status' => $item['status'] ?? null,
            'risk_score' => $score,
            'risk_level' => $level,
            'reasons' => array_values(array_unique($reasons)),
            'suggested_actions' => array_values(array_unique($actions)),
            'metrics' => [
                'area_total_ha' => $areaTotal,
                'ha_per_hour' => $haPerHour,
                'fwi' => $fwi,
                'isi' => $isi,
                'ffmc' => $ffmc,
                'bui' => $bui,
                'dsr' => $dsr,
                'wind_intensity' => $wind,
                'relative_humidity' => $humidity,
                'precipitation' => $precipitation,
                'slope' => $slope,
                'initial_intervention_delay_minutes' => $delay,
                'duration_minutes' => $duration,
            ],
            'context' => [
                'aps' => $aps,
                'forest_perimeter' => $perimeter,
                'cause_type' => is_array($item['cause'] ?? null) ? ($item['cause']['type'] ?? null) : null,
                'cause_family' => is_array($item['cause'] ?? null) ? ($item['cause']['family'] ?? null) : null,
                'kml_url' => $item['kml_url'] ?? null,
            ],
        ];
    }

    private function buildOverview(array $analysed, array $filtered, array $allItems, string $targetDate): array
    {
        $byLevel = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
        $active = 0;
        $incendios = 0;
        $fogachos = 0;
        $totalArea = 0.0;
        $maxScore = 0;
        $maxFwi = null;
        $maxWind = null;
        $maxSlope = null;

        foreach ($analysed as $item) {
            $level = (string) ($item['risk_level'] ?? 'low');
            $byLevel[$level] = ($byLevel[$level] ?? 0) + 1;
            $maxScore = max($maxScore, (int) ($item['risk_score'] ?? 0));
            $metrics = is_array($item['metrics'] ?? null) ? $item['metrics'] : [];
            $totalArea += (float) ($metrics['area_total_ha'] ?? 0);
            $maxFwi = $this->maxNullable($maxFwi, $metrics['fwi'] ?? null);
            $maxWind = $this->maxNullable($maxWind, $metrics['wind_intensity'] ?? null);
            $maxSlope = $this->maxNullable($maxSlope, $metrics['slope'] ?? null);
            if (($item['status'] ?? '') === 'active' || empty($item['ended_at'])) {
                $active++;
            }
        }

        foreach ($filtered as $item) {
            $classification = is_array($item['classification'] ?? null) ? $item['classification'] : [];
            $incendios += (int) (($classification['incendio'] ?? 0) === 1);
            $fogachos += (int) (($classification['fogacho'] ?? 0) === 1);
        }

        return [
            'date' => $targetDate,
            'records_available_before_date_filter' => count($allItems),
            'records_for_date' => count($filtered),
            'active_or_open_records' => $active,
            'incendios' => $incendios,
            'fogachos' => $fogachos,
            'risk_levels' => $byLevel,
            'max_risk_score' => $maxScore,
            'total_reported_area_ha' => round($totalArea, 4),
            'max_fwi' => $maxFwi,
            'max_wind_intensity' => $maxWind,
            'max_slope' => $maxSlope,
        ];
    }

    private function buildGeneralRecommendations(array $overview, array $analysed): array
    {
        $recommendations = [];
        $criticalHigh = (int) (($overview['risk_levels']['critical'] ?? 0) + ($overview['risk_levels']['high'] ?? 0));

        if ((int) ($overview['records_for_date'] ?? 0) === 0) {
            return [[
                'level' => 'low',
                'title' => 'No current-day ICNF records available',
                'text' => 'The operational map is correctly filtering to today. Verify the ICNF feed/cache if real fires are expected today.',
            ]];
        }

        if ($criticalHigh > 0) {
            $recommendations[] = [
                'level' => 'high',
                'title' => 'Review high-priority occurrences first',
                'text' => 'Open the ranked list and confirm command priorities, exposed values, access, water supply and safe anchor points for the top occurrences.',
            ];
        }

        if ((float) ($overview['max_wind_intensity'] ?? 0) >= 15) {
            $recommendations[] = [
                'level' => 'high',
                'title' => 'Wind is operationally relevant',
                'text' => 'Use wind direction and topography to identify likely head-fire movement. Avoid unsupported direct attack on wind-aligned fronts.',
            ];
        }

        if ((float) ($overview['max_slope'] ?? 0) >= 20) {
            $recommendations[] = [
                'level' => 'medium',
                'title' => 'Slope can amplify spread',
                'text' => 'Prioritize lookout coverage and avoid committing crews above the fire without secure egress and anchor points.',
            ];
        }

        if ((float) ($overview['total_reported_area_ha'] ?? 0) >= 10) {
            $recommendations[] = [
                'level' => 'medium',
                'title' => 'Area already exceeds small-incident range',
                'text' => 'Consider early sectorization, reinforcement needs and coordination cadence before operations become reactive.',
            ];
        }

        $recommendations[] = [
            'level' => 'medium',
            'title' => 'Keep the information loop active',
            'text' => 'Update status, first intervention, observed behaviour and tactical decisions. This data will feed later propagation and AI modules.',
        ];

        return $recommendations;
    }

    private function filterByDate(array $items, string $targetDate): array
    {
        return array_values(array_filter($items, function (array $item) use ($targetDate): bool {
            return $this->extractOccurrenceDate($item) === $targetDate;
        }));
    }

    private function extractOccurrenceDate(array $item): ?string
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

    private function resolveTargetDate(array $query = []): string
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

    private function normalizeDateString(string $value): ?string
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

    private function initialInterventionDelayMinutes(array $item): ?int
    {
        $raw = is_array($item['raw'] ?? null) ? $item['raw'] : [];
        $dataAlerta = (string) ($item['data_alerta'] ?? $raw['dataalerta'] ?? '');
        $horaAlerta = (string) ($item['hora_alerta'] ?? $raw['horaalerta'] ?? '');
        $dataIntervencao = (string) ($raw['data1intervencao'] ?? '');
        $horaIntervencao = (string) ($raw['hora1intervencao'] ?? '');

        if ($dataIntervencao === '' || $horaIntervencao === '' || $dataAlerta === '' || $horaAlerta === '') {
            return null;
        }

        try {
            $zone = new DateTimeZone($this->timezone);
            $alert = new DateTimeImmutable($dataAlerta . ' ' . $horaAlerta, $zone);
            $intervention = new DateTimeImmutable($dataIntervencao . ' ' . $horaIntervencao, $zone);
            return max(0, (int) round(($intervention->getTimestamp() - $alert->getTimestamp()) / 60));
        } catch (Throwable $error) {
            return null;
        }
    }

    private function riskLevel(int $score): string
    {
        if ($score >= 80) {
            return 'critical';
        }
        if ($score >= 55) {
            return 'high';
        }
        if ($score >= 30) {
            return 'medium';
        }
        return 'low';
    }

    private function floatOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        $normalized = str_replace(',', '.', (string) $value);
        return is_numeric($normalized) ? (float) $normalized : null;
    }

    private function maxNullable(?float $current, mixed $candidate): ?float
    {
        $value = $this->floatOrNull($candidate);
        if ($value === null) {
            return $current;
        }
        return $current === null ? $value : max($current, $value);
    }
}
