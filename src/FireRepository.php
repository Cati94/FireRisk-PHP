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
        $data = json_decode($json, true);

        if (!is_array($data)) {
            return [];
        }

        return array_values(array_filter($data, function ($fire) {
            return isset($fire['latitude'], $fire['longitude']);
        }));
    }
}
