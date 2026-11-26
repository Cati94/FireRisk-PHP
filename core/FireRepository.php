<?php

class FireRepository
{
    private string $dataFile;

    public function __construct(string $dataFile)
    {
        $this->dataFile = $dataFile;
    }

    public function getAll(): array
    {
        if (!file_exists($this->dataFile)) {
            return [];
        }

        $json = file_get_contents($this->dataFile);
        $data = json_decode((string) $json, true);

        if (!is_array($data)) {
            return [];
        }

        return array_values(array_filter($data, function ($fire): bool {
            return is_array($fire) && isset($fire['latitude'], $fire['longitude']);
        }));
    }

    public function getForDate(?string $date = null, string $timezone = 'Europe/Lisbon'): array
    {
        $targetDate = $this->normalizeDate($date, $timezone) ?? $this->today($timezone);

        return array_values(array_filter($this->getAll(), function (array $fire) use ($targetDate, $timezone): bool {
            $fireDate = $this->extractFireDate($fire, $timezone);
            return $fireDate === $targetDate;
        }));
    }

    public function today(string $timezone = 'Europe/Lisbon'): string
    {
        try {
            $zone = new DateTimeZone($timezone);
        } catch (Throwable $error) {
            $zone = new DateTimeZone('Europe/Lisbon');
        }

        return (new DateTimeImmutable('now', $zone))->format('Y-m-d');
    }

    public function normalizeDate(?string $date, string $timezone = 'Europe/Lisbon'): ?string
    {
        $value = trim((string) $date);
        if ($value === '') {
            return null;
        }

        $formats = ['!Y-m-d', '!d-m-Y', '!Y/m/d', '!d/m/Y'];
        try {
            $zone = new DateTimeZone($timezone);
        } catch (Throwable $error) {
            $zone = new DateTimeZone('Europe/Lisbon');
        }

        foreach ($formats as $format) {
            $dateTime = DateTimeImmutable::createFromFormat($format, $value, $zone);
            if ($dateTime instanceof DateTimeImmutable) {
                return $dateTime->format('Y-m-d');
            }
        }

        try {
            return (new DateTimeImmutable($value, $zone))->setTimezone($zone)->format('Y-m-d');
        } catch (Throwable $error) {
            return null;
        }
    }

    private function extractFireDate(array $fire, string $timezone): ?string
    {
        foreach (['started_at', 'dh_inicio', 'data_alerta', 'date'] as $field) {
            if (!isset($fire[$field])) {
                continue;
            }

            $date = $this->normalizeDate((string) $fire[$field], $timezone);
            if ($date !== null) {
                return $date;
            }
        }

        return null;
    }
}
