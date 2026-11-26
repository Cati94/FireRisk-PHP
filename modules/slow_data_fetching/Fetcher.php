<?php

class SlowDataFetcher
{
    private string $basePath;
    private string $modulePath;
    private string $cachePath;
    private string $sourcesFile;

    public function __construct(string $basePath, string $modulePath, string $cachePath)
    {
        $this->basePath = rtrim($basePath, DIRECTORY_SEPARATOR);
        $this->modulePath = rtrim($modulePath, DIRECTORY_SEPARATOR);
        $this->cachePath = rtrim($cachePath, DIRECTORY_SEPARATOR);
        $this->sourcesFile = $this->modulePath . DIRECTORY_SEPARATOR . 'sources.json';

        if (!is_dir($this->cachePath)) {
            mkdir($this->cachePath, 0775, true);
        }
    }

    public function getSources(bool $enabledOnly = false): array
    {
        if (!file_exists($this->sourcesFile)) {
            return [];
        }

        $json = file_get_contents($this->sourcesFile);
        $sources = json_decode((string) $json, true);

        if (!is_array($sources)) {
            return [];
        }

        $clean = [];

        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }

            $source = $this->normalizeSource($source);

            if ($source['id'] === '') {
                continue;
            }

            if ($enabledOnly && !$source['enabled']) {
                continue;
            }

            $clean[] = $source;
        }

        usort($clean, function (array $a, array $b): int {
            return $b['relevance'] <=> $a['relevance'];
        });

        return $clean;
    }

    public function getStatus(): array
    {
        $items = [];

        foreach ($this->getSources(false) as $source) {
            $cache = $this->readCache($source['id']);
            $items[] = [
                'id' => $source['id'],
                'name' => $source['name'],
                'enabled' => $source['enabled'],
                'type' => $source['type'],
                'parser' => $source['parser'],
                'url' => $source['url'],
                'ttl_seconds' => $source['ttl_seconds'],
                'timeout_seconds' => $source['timeout_seconds'],
                'streaming' => $source['streaming'],
                'resume' => $source['resume'],
                'accept_encoding' => $source['accept_encoding'],
                'chunk_size_bytes' => $source['chunk_size_bytes'],
                'relevance' => $source['relevance'],
                'notes' => $source['notes'],
                'cache' => $this->cacheSummary($source, $cache),
            ];
        }

        return $items;
    }

    public function getCachedData(string $sourceId): array
    {
        $source = $this->findSource($sourceId);

        if (!$source) {
            return [
                'success' => false,
                'error' => 'Unknown source id.',
            ];
        }

        $cache = $this->readCache($source['id']);

        if (!$cache) {
            return [
                'success' => false,
                'source' => $this->publicSource($source),
                'cache' => $this->cacheSummary($source, null),
                'error' => 'No cache exists for this source yet. Use fetch to populate it.',
            ];
        }

        return [
            'success' => true,
            'source' => $this->publicSource($source),
            'cache' => $this->cacheSummary($source, $cache),
            'data' => $cache['data'] ?? null,
        ];
    }

    public function clearCache(?string $sourceId = null): array
    {
        $deleted = [];

        if ($sourceId !== null && $sourceId !== '') {
            $sourceId = $this->safeId($sourceId);
            $file = $this->cacheFile($sourceId);

            if (file_exists($file)) {
                unlink($file);
                $deleted[] = $sourceId;
            }

            return [
                'success' => true,
                'deleted' => $deleted,
            ];
        }

        foreach (glob($this->cachePath . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            if (is_file($file)) {
                $deleted[] = basename($file, '.json');
                unlink($file);
            }
        }

        return [
            'success' => true,
            'deleted' => $deleted,
        ];
    }

    public function fetchAll(bool $force = false): array
    {
        $results = [];

        foreach ($this->getSources(true) as $source) {
            $results[] = $this->fetchSource($source['id'], $force);
        }

        return [
            'success' => true,
            'count' => count($results),
            'results' => $results,
        ];
    }

    public function fetchSource(string $sourceId, bool $force = false): array
    {
        $source = $this->findSource($sourceId);

        if (!$source) {
            return [
                'success' => false,
                'error' => 'Unknown source id.',
            ];
        }

        if (!$source['enabled']) {
            return [
                'success' => false,
                'source' => $this->publicSource($source),
                'error' => 'Source is disabled in sources.json.',
            ];
        }

        $cache = $this->readCache($source['id']);

        if (!$force && $cache && !$this->isExpired($source, $cache)) {
            return [
                'success' => true,
                'source' => $this->publicSource($source),
                'mode' => 'cache',
                'message' => 'Fresh cache returned. Use force=1 to fetch again.',
                'cache' => $this->cacheSummary($source, $cache),
                'data' => $cache['data'] ?? null,
            ];
        }

        $lockHandle = $this->acquireLock($source['id']);

        if (!$lockHandle) {
            return [
                'success' => (bool) $cache,
                'source' => $this->publicSource($source),
                'mode' => $cache ? 'stale-cache' : 'locked',
                'warning' => 'Another fetch is already running for this source.',
                'cache' => $this->cacheSummary($source, $cache),
                'data' => $cache['data'] ?? null,
            ];
        }

        $this->extendRuntimeForSource($source);
        $started = microtime(true);

        try {
            $fetched = $this->performFetch($source);
            $elapsedMs = (int) round((microtime(true) - $started) * 1000);
            $now = time();

            $payload = [
                'source_id' => $source['id'],
                'source_name' => $source['name'],
                'source_type' => $source['type'],
                'parser' => $source['parser'],
                'url' => $source['url'],
                'fetched_at' => date(DATE_ATOM, $now),
                'expires_at' => date(DATE_ATOM, $now + $source['ttl_seconds']),
                'ttl_seconds' => $source['ttl_seconds'],
                'timeout_seconds' => $source['timeout_seconds'],
                'elapsed_ms' => $elapsedMs,
                'data' => $fetched['data'],
            ];

            $this->writeCache($source['id'], $payload);

            return [
                'success' => true,
                'source' => $this->publicSource($source),
                'mode' => 'fetched',
                'message' => 'Source fetched and cache updated.',
                'cache' => $this->cacheSummary($source, $payload),
                'elapsed_ms' => $elapsedMs,
                'data' => $fetched['data'],
            ];
        } catch (Throwable $error) {
            return [
                'success' => (bool) $cache,
                'source' => $this->publicSource($source),
                'mode' => $cache ? 'stale-cache' : 'failed',
                'error' => $error->getMessage(),
                'warning' => $cache ? 'Fetch failed. Stale cache was returned instead.' : null,
                'cache' => $this->cacheSummary($source, $cache),
                'data' => $cache['data'] ?? null,
            ];
        } finally {
            $this->releaseLock($lockHandle);
        }
    }

    private function performFetch(array $source): array
    {
        if ($source['type'] === 'local_json') {
            return $this->fetchLocalJson($source);
        }

        if ($source['type'] === 'http_json') {
            return $this->fetchHttpJson($source);
        }

        if ($source['type'] === 'local_xml') {
            return $this->fetchLocalXml($source);
        }

        if ($source['type'] === 'http_xml') {
            return $this->fetchHttpXml($source);
        }

        throw new RuntimeException('Unsupported source type: ' . $source['type']);
    }

    private function fetchLocalJson(array $source): array
    {
        $path = $this->normalizeLocalPath($source['url']);

        if ($path === null || !file_exists($path)) {
            throw new RuntimeException('Local source file not found.');
        }

        $contents = file_get_contents($path);
        return [
            'data' => $this->decodeJson((string) $contents, 'Local JSON source is not valid JSON.'),
        ];
    }

    private function fetchLocalXml(array $source): array
    {
        $path = $this->normalizeLocalPath($source['url']);

        if ($path === null || !file_exists($path)) {
            throw new RuntimeException('Local XML source file not found.');
        }

        if ($source['parser'] === 'icnf_active_occurrences' || $source['streaming']) {
            return [
                'data' => $this->decodeXmlFile($path, $source),
            ];
        }

        return [
            'data' => $this->decodeXml((string) file_get_contents($path), $source),
        ];
    }

    private function fetchHttpJson(array $source): array
    {
        $contents = $this->fetchHttpContents($source, 'application/json');

        $trimmed = ltrim($contents);
        if ($trimmed !== '' && $trimmed[0] === '<') {
            throw new RuntimeException('Source returned markup instead of JSON. Keeping stale cache if available.');
        }

        return [
            'data' => $this->decodeJson((string) $contents, 'HTTP source did not return valid JSON.'),
        ];
    }

    private function fetchHttpXml(array $source): array
    {
        if ($source['parser'] === 'icnf_active_occurrences' || $source['streaming']) {
            return $this->fetchHttpXmlBuffered($source);
        }

        $contents = $this->fetchHttpContents($source, 'application/xml, text/xml, */*');
        return [
            'data' => $this->decodeXml((string) $contents, $source),
        ];
    }

    private function fetchHttpXmlBuffered(array $source): array
    {
        $file = $this->downloadHttpToFile($source, 'application/xml, text/xml, */*');

        return [
            'data' => $this->decodeXmlFile($file, $source),
        ];
    }

    private function downloadHttpToFile(array $source, string $accept): string
    {
        if (!filter_var($source['url'], FILTER_VALIDATE_URL)) {
            throw new RuntimeException('Invalid HTTP URL.');
        }

        if (!ini_get('allow_url_fopen')) {
            throw new RuntimeException('PHP allow_url_fopen is disabled. Enable it or replace downloadHttpToFile with cURL.');
        }

        $rawDir = $this->cachePath . DIRECTORY_SEPARATOR . 'raw';
        if (!is_dir($rawDir)) {
            mkdir($rawDir, 0775, true);
        }

        $safeId = $this->safeId($source['id']);
        $finalFile = $rawDir . DIRECTORY_SEPARATOR . $safeId . '.xml';
        $partFile = $finalFile . '.part';
        $offset = ($source['resume'] && file_exists($partFile)) ? (int) filesize($partFile) : 0;

        $timeoutSeconds = max(1, (int) $source['timeout_seconds']);
        @ini_set('default_socket_timeout', (string) $timeoutSeconds);

        $acceptEncoding = $source['accept_encoding'] !== '' ? $source['accept_encoding'] : 'identity';
        $headers = [
            'Accept: ' . $accept,
            'Accept-Encoding: ' . $acceptEncoding,
            'User-Agent: FireRisk/0.4.5 SlowDataFetcher',
            'Connection: close',
        ];

        if ($offset > 0) {
            $headers[] = 'Range: bytes=' . $offset . '-';
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeoutSeconds,
                'ignore_errors' => true,
                'header' => implode("\r\n", $headers) . "\r\n",
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $input = @fopen($source['url'], 'rb', false, $context);
        if (!$input) {
            throw new RuntimeException('HTTP XML stream could not be opened. Keeping stale cache if available.');
        }

        $meta = stream_get_meta_data($input);
        $wrapperData = $meta['wrapper_data'] ?? [];
        $headersText = is_array($wrapperData) ? implode("\n", $wrapperData) : (string) $wrapperData;

        if (preg_match('/HTTP\/\S+\s+(\d+)/i', $headersText, $match)) {
            $statusCode = (int) $match[1];
            if ($statusCode >= 400) {
                fclose($input);
                throw new RuntimeException('HTTP source returned status ' . $statusCode . '.');
            }

            if ($offset > 0 && $statusCode !== 206) {
                $offset = 0;
            }
        }

        $output = @fopen($partFile, $offset > 0 ? 'ab' : 'wb');
        if (!$output) {
            fclose($input);
            throw new RuntimeException('Could not open local buffer file for streamed XML download.');
        }

        $bytesWritten = $offset;
        $chunkSize = max(8192, min(1048576, (int) $source['chunk_size_bytes']));

        while (!feof($input)) {
            $chunk = fread($input, $chunkSize);
            if ($chunk === false) {
                fclose($output);
                fclose($input);
                throw new RuntimeException('Network read failed during XML stream. Partial file kept for resume.');
            }

            if ($chunk === '') {
                continue;
            }

            $written = fwrite($output, $chunk);
            if ($written === false) {
                fclose($output);
                fclose($input);
                throw new RuntimeException('Could not write streamed XML chunk to local buffer.');
            }

            $bytesWritten += $written;
        }

        fclose($output);
        fclose($input);

        if ($bytesWritten <= 0 || !file_exists($partFile) || filesize($partFile) <= 0) {
            throw new RuntimeException('HTTP source returned an empty XML response.');
        }

        $probe = (string) file_get_contents($partFile, false, null, 0, 512);
        $lowerProbe = strtolower(ltrim($probe));
        if (str_starts_with($lowerProbe, '<!doctype html') || str_starts_with($lowerProbe, '<html')) {
            @unlink($partFile);
            throw new RuntimeException('Source returned HTML instead of XML. Keeping stale cache if available.');
        }

        if (!@rename($partFile, $finalFile)) {
            if (!@copy($partFile, $finalFile)) {
                throw new RuntimeException('Could not finalize streamed XML buffer file.');
            }
            @unlink($partFile);
        }

        return $finalFile;
    }

    private function fetchHttpContents(array $source, string $accept): string
    {
        if (!filter_var($source['url'], FILTER_VALIDATE_URL)) {
            throw new RuntimeException('Invalid HTTP URL.');
        }

        if (!ini_get('allow_url_fopen')) {
            throw new RuntimeException('PHP allow_url_fopen is disabled. Enable it or replace this method with cURL.');
        }

        $timeoutSeconds = max(1, (int) $source['timeout_seconds']);
        @ini_set('default_socket_timeout', (string) $timeoutSeconds);

        $context = stream_context_create([
            'http' => [
                'timeout' => $timeoutSeconds,
                'ignore_errors' => true,
                'header' => "Accept: {$accept}\r\nUser-Agent: FireRisk/0.4.5 SlowDataFetcher\r\n",
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $contents = @file_get_contents($source['url'], false, $context);

        if ($contents === false) {
            throw new RuntimeException('HTTP request failed or timed out.');
        }

        $trimmed = ltrim($contents);
        if ($trimmed === '') {
            throw new RuntimeException('Source returned an empty response.');
        }

        $lowerStart = strtolower(substr($trimmed, 0, 120));
        if (str_contains($lowerStart, '<!doctype html') || str_contains($lowerStart, '<html')) {
            throw new RuntimeException('Source returned HTML instead of structured data. Keeping stale cache if available.');
        }

        return (string) $contents;
    }

    private function decodeJson(string $contents, string $errorMessage): array
    {
        $data = json_decode($contents, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            throw new RuntimeException($errorMessage . ' ' . json_last_error_msg());
        }

        return $data;
    }

    private function decodeXml(string $contents, array $source): array
    {
        $contents = trim($contents);

        if ($contents === '') {
            throw new RuntimeException('XML source returned an empty response.');
        }

        $records = $this->extractXmlRecordsFromString($contents);

        if ($source['parser'] === 'icnf_active_occurrences') {
            return $this->parseIcnfActiveOccurrencesRecords($contents, $records);
        }

        return [
            'format' => 'xml',
            'parser' => $source['parser'],
            'root' => $this->detectXmlRootName($contents),
            'items' => $records,
        ];
    }

    private function decodeXmlFile(string $path, array $source): array
    {
        if (!file_exists($path) || filesize($path) === 0) {
            throw new RuntimeException('XML file is missing or empty.');
        }

        if ($source['parser'] === 'icnf_active_occurrences') {
            return $this->parseIcnfActiveOccurrencesFile($path);
        }

        return $this->decodeXml((string) file_get_contents($path), $source);
    }

    private function parseIcnfActiveOccurrencesFile(string $path): array
    {
        if (!class_exists('XMLReader')) {
            return $this->parseIcnfActiveOccurrencesRecords((string) file_get_contents($path), []);
        }

        $reader = new XMLReader();
        $opened = @$reader->open($path, null, LIBXML_PARSEHUGE | LIBXML_COMPACT | LIBXML_NONET);

        if (!$opened) {
            throw new RuntimeException('Could not open XML file with XMLReader.');
        }

        $items = [];
        $seenIds = [];
        $rootName = 'unknown';
        $totalCodigoRecords = 0;

        libxml_use_internal_errors(true);

        while (@$reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }

            if ($rootName === 'unknown') {
                $rootName = $reader->name;
            }

            if ($reader->name !== 'CODIGO') {
                continue;
            }

            $totalCodigoRecords++;
            $outerXml = $reader->readOuterXML();

            if ($outerXml === '') {
                continue;
            }

            $node = @simplexml_load_string($outerXml, 'SimpleXMLElement', LIBXML_PARSEHUGE | LIBXML_NOCDATA | LIBXML_NONET);
            if (!$node instanceof SimpleXMLElement) {
                continue;
            }

            $record = $this->simpleXmlCodigoToRecordArray($node);
            unset($node);

            $item = $this->parseIcnfOccurrenceRecord($record, $seenIds);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        $reader->close();
        libxml_clear_errors();

        return [
            'format' => 'xml',
            'parser' => 'icnf_active_occurrences',
            'root' => $rootName,
            'record_tag' => 'CODIGO',
            'streaming_parser' => true,
            'total_codigo_records' => $totalCodigoRecords,
            'count' => count($items),
            'items' => $items,
        ];
    }

    private function simpleXmlCodigoToRecordArray(SimpleXMLElement $node): array
    {
        $record = [];

        foreach ($node->attributes() as $name => $value) {
            $record[$this->normalizeKey((string) $name)] = html_entity_decode(trim((string) $value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        foreach ($node->children() as $name => $child) {
            $key = $this->normalizeKey((string) $name);
            $value = trim((string) $child);
            $record[$key] = html_entity_decode($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return $record;
    }

    private function parseIcnfOccurrenceRecord(array $record, array &$seenIds): ?array
    {
        $lat = $this->firstRecordValue($record, ['lat', 'latitude']);
        $lon = $this->firstRecordValue($record, ['lon', 'lng', 'longitude']);

        if (!$this->isNumericCoordinate($lat) || !$this->isNumericCoordinate($lon)) {
            return null;
        }

        $codigo = $this->firstRecordValue($record, ['id', 'codigo', 'codigo_id', 'código', 'cod', 'ncco']);
        $ncco = $this->firstRecordValue($record, ['ncco']);
        if ($codigo === '' && $ncco !== '') {
            $codigo = $ncco;
        }

        $kmlUrl = $this->firstRecordValue($record, ['areasficheiros_gnr']);
        $baseId = $codigo !== '' ? $codigo : sha1(json_encode($record));
        $seenIds[$baseId] = ($seenIds[$baseId] ?? 0) + 1;
        $uniqueId = $seenIds[$baseId] > 1 ? $baseId . '_' . $seenIds[$baseId] : $baseId;

        $tipo = $this->firstRecordValue($record, ['tipo', 'natureza']);
        $distrito = $this->firstRecordValue($record, ['distrito']);
        $concelho = $this->firstRecordValue($record, ['concelho', 'municipio', 'município']);
        $freguesia = $this->firstRecordValue($record, ['freguesia']);
        $local = $this->firstRecordValue($record, ['local', 'localidade']);

        $dhInicio = $this->firstRecordValue($record, ['dhinicio', 'dh_inicio', 'inicio', 'datahora', 'data_hora']);
        if ($dhInicio === '') {
            $dhInicio = trim($this->firstRecordValue($record, ['dataalerta']) . ' ' . $this->firstRecordValue($record, ['horaalerta']));
        }

        $dhFim = $this->firstRecordValue($record, ['dhfim', 'dh_fim']);
        $status = $this->firstRecordValue($record, ['estado', 'status', 'fase']);
        if ($status === '') {
            $status = $dhFim !== '' ? 'closed' : 'active';
        }

        $areaTotal = $this->nullableFloat($this->firstRecordValue($record, ['areatotal']));
        $fwi = $this->nullableFloat($this->firstRecordValue($record, ['fwi']));
        $isi = $this->nullableFloat($this->firstRecordValue($record, ['isi']));
        $duration = $this->nullableInt($this->firstRecordValue($record, ['duracao', 'duração', 'dur']));

        return [
            'id' => $uniqueId,
            'codigo' => $codigo,
            'ncco' => $ncco,
            'duplicate_index' => $seenIds[$baseId],
            'tipo' => $tipo,
            'year' => $this->nullableInt($this->firstRecordValue($record, ['ano'])),
            'district' => $distrito,
            'municipality' => $concelho,
            'freguesia' => $freguesia,
            'local' => $local,
            'name' => trim(($tipo !== '' ? $tipo . ' - ' : '') . ($local !== '' ? $local : $concelho)),
            'status' => $status,
            'source_alert' => $this->firstRecordValue($record, ['fontealerta']),
            'ine' => $this->firstRecordValue($record, ['ine']),
            'x' => $this->nullableFloat($this->firstRecordValue($record, ['x'])),
            'y' => $this->nullableFloat($this->firstRecordValue($record, ['y'])),
            'latitude' => (float) str_replace(',', '.', (string) $lat),
            'longitude' => (float) str_replace(',', '.', (string) $lon),
            'data_alerta' => $this->firstRecordValue($record, ['dataalerta']),
            'hora_alerta' => $this->firstRecordValue($record, ['horaalerta']),
            'started_at' => $this->normalizeDateTime($dhInicio),
            'dh_inicio' => $dhInicio,
            'ended_at' => $this->normalizeDateTime($dhFim),
            'dh_fim' => $dhFim,
            'duration_minutes' => $duration,
            'area' => [
                'povoamento' => $this->nullableFloat($this->firstRecordValue($record, ['areapov'])),
                'mato' => $this->nullableFloat($this->firstRecordValue($record, ['areamato'])),
                'agricola' => $this->nullableFloat($this->firstRecordValue($record, ['areaagric'])),
                'total' => $areaTotal,
                'ha_per_hour' => $this->nullableFloat($this->firstRecordValue($record, ['hahora'])),
            ],
            'classification' => [
                'incendio' => $this->flagInt($this->firstRecordValue($record, ['incendio'])),
                'fogacho' => $this->flagInt($this->firstRecordValue($record, ['fogacho'])),
                'agricola' => $this->flagInt($this->firstRecordValue($record, ['agricola'])),
                'falso_alarme' => $this->flagInt($this->firstRecordValue($record, ['falsoalarme'])),
                'queimada' => $this->flagInt($this->firstRecordValue($record, ['queimada'])),
                'reacendimento' => $this->flagInt($this->firstRecordValue($record, ['reacendimentos'])),
            ],
            'cause' => [
                'code' => $this->firstRecordValue($record, ['causa']),
                'type' => $this->firstRecordValue($record, ['tipocausa']),
                'family' => $this->firstRecordValue($record, ['causafamilia']),
            ],
            'weather' => [
                'temperature_c' => $this->nullableFloat($this->firstRecordValue($record, ['temperatura'])),
                'relative_humidity' => $this->nullableFloat($this->firstRecordValue($record, ['humidad relativa', 'humidade_relativa', 'humidaderelativa'])),
                'wind_intensity' => $this->nullableFloat($this->firstRecordValue($record, ['ventointensidade'])),
                'wind_intensity_vector' => $this->nullableFloat($this->firstRecordValue($record, ['ventointensidade_vetor'])),
                'wind_direction_vector' => $this->nullableFloat($this->firstRecordValue($record, ['ventodirecao_vetor'])),
                'precipitation' => $this->nullableFloat($this->firstRecordValue($record, ['precepitacao', 'precipitacao'])),
            ],
            'fire_weather_indices' => [
                'ffmc' => $this->nullableFloat($this->firstRecordValue($record, ['ffmc'])),
                'dmc' => $this->nullableFloat($this->firstRecordValue($record, ['dmc'])),
                'dc' => $this->nullableFloat($this->firstRecordValue($record, ['dc'])),
                'isi' => $isi,
                'bui' => $this->nullableFloat($this->firstRecordValue($record, ['bui'])),
                'fwi' => $fwi,
                'dsr' => $this->nullableFloat($this->firstRecordValue($record, ['dsr'])),
            ],
            'terrain' => [
                'altitude_media' => $this->nullableFloat($this->firstRecordValue($record, ['altitudemedia'])),
                'declive_medio' => $this->nullableFloat($this->firstRecordValue($record, ['declivemedio'])),
                'horas_exposicao_media' => $this->nullableFloat($this->firstRecordValue($record, ['horasexposicaomedia'])),
                'densidade_rv' => $this->nullableFloat($this->firstRecordValue($record, ['dendidaderv', 'densidaderv'])),
                'cosn5_variedade' => $this->nullableFloat($this->firstRecordValue($record, ['cosn5variedade'])),
            ],
            'modfarsite' => $this->firstRecordValue($record, ['modfarsite']),
            'area_mancha_modfarsite' => $this->nullableFloat($this->firstRecordValue($record, ['areamanchamodfarsite'])),
            'aps' => $this->firstRecordValue($record, ['aps']),
            'forest_perimeter' => $this->firstRecordValue($record, ['perimetro', 'perímetro']),
            'kml_url' => $kmlUrl,
            'severity' => $this->classifyIcnfSeverity($areaTotal, $fwi, $isi),
            'raw' => $record,
        ];
    }

    private function parseIcnfActiveOccurrencesRecords(string $contents, array $records): array
    {
        $rawRecords = $this->extractIcnfCodigoRecords($contents);

        if (count($rawRecords) === 0) {
            $rawRecords = $records;
        }

        $items = [];
        $seenIds = [];

        foreach ($rawRecords as $record) {
            $lat = $this->firstRecordValue($record, ['lat', 'latitude']);
            $lon = $this->firstRecordValue($record, ['lon', 'lng', 'longitude']);

            if (!$this->isNumericCoordinate($lat) || !$this->isNumericCoordinate($lon)) {
                continue;
            }

            $codigo = $this->firstRecordValue($record, ['id', 'codigo', 'codigo_id', 'código', 'cod', 'ncco']);
            $ncco = $this->firstRecordValue($record, ['ncco']);
            if ($codigo === '' && $ncco !== '') {
                $codigo = $ncco;
            }

            $kmlUrl = $this->firstRecordValue($record, ['areasficheiros_gnr']);
            $baseId = $codigo !== '' ? $codigo : sha1(json_encode($record));
            $seenIds[$baseId] = ($seenIds[$baseId] ?? 0) + 1;
            $uniqueId = $seenIds[$baseId] > 1 ? $baseId . '_' . $seenIds[$baseId] : $baseId;

            $tipo = $this->firstRecordValue($record, ['tipo', 'natureza']);
            $distrito = $this->firstRecordValue($record, ['distrito']);
            $concelho = $this->firstRecordValue($record, ['concelho', 'municipio', 'município']);
            $freguesia = $this->firstRecordValue($record, ['freguesia']);
            $local = $this->firstRecordValue($record, ['local', 'localidade']);

            $dhInicio = $this->firstRecordValue($record, ['dhinicio', 'dh_inicio', 'inicio', 'datahora', 'data_hora']);
            if ($dhInicio === '') {
                $dhInicio = trim($this->firstRecordValue($record, ['dataalerta']) . ' ' . $this->firstRecordValue($record, ['horaalerta']));
            }

            $dhFim = $this->firstRecordValue($record, ['dhfim', 'dh_fim']);
            $status = $this->firstRecordValue($record, ['estado', 'status', 'fase']);
            if ($status === '') {
                $status = $dhFim !== '' ? 'closed' : 'active';
            }

            $areaTotal = $this->nullableFloat($this->firstRecordValue($record, ['areatotal']));
            $fwi = $this->nullableFloat($this->firstRecordValue($record, ['fwi']));
            $isi = $this->nullableFloat($this->firstRecordValue($record, ['isi']));
            $duration = $this->nullableInt($this->firstRecordValue($record, ['duracao', 'duração', 'dur']));

            $items[] = [
                'id' => $uniqueId,
                'codigo' => $codigo,
                'ncco' => $ncco,
                'duplicate_index' => $seenIds[$baseId],
                'tipo' => $tipo,
                'year' => $this->nullableInt($this->firstRecordValue($record, ['ano'])),
                'district' => $distrito,
                'municipality' => $concelho,
                'freguesia' => $freguesia,
                'local' => $local,
                'name' => trim(($tipo !== '' ? $tipo . ' - ' : '') . ($local !== '' ? $local : $concelho)),
                'status' => $status,
                'source_alert' => $this->firstRecordValue($record, ['fontealerta']),
                'ine' => $this->firstRecordValue($record, ['ine']),
                'x' => $this->nullableFloat($this->firstRecordValue($record, ['x'])),
                'y' => $this->nullableFloat($this->firstRecordValue($record, ['y'])),
                'latitude' => (float) str_replace(',', '.', (string) $lat),
                'longitude' => (float) str_replace(',', '.', (string) $lon),
                'data_alerta' => $this->firstRecordValue($record, ['dataalerta']),
                'hora_alerta' => $this->firstRecordValue($record, ['horaalerta']),
                'started_at' => $this->normalizeDateTime($dhInicio),
                'dh_inicio' => $dhInicio,
                'ended_at' => $this->normalizeDateTime($dhFim),
                'dh_fim' => $dhFim,
                'duration_minutes' => $duration,
                'area' => [
                    'povoamento' => $this->nullableFloat($this->firstRecordValue($record, ['areapov'])),
                    'mato' => $this->nullableFloat($this->firstRecordValue($record, ['areamato'])),
                    'agricola' => $this->nullableFloat($this->firstRecordValue($record, ['areaagric'])),
                    'total' => $areaTotal,
                    'ha_per_hour' => $this->nullableFloat($this->firstRecordValue($record, ['hahora'])),
                ],
                'classification' => [
                    'incendio' => $this->flagInt($this->firstRecordValue($record, ['incendio'])),
                    'fogacho' => $this->flagInt($this->firstRecordValue($record, ['fogacho'])),
                    'agricola' => $this->flagInt($this->firstRecordValue($record, ['agricola'])),
                    'falso_alarme' => $this->flagInt($this->firstRecordValue($record, ['falsoalarme'])),
                    'queimada' => $this->flagInt($this->firstRecordValue($record, ['queimada'])),
                    'reacendimento' => $this->flagInt($this->firstRecordValue($record, ['reacendimentos'])),
                ],
                'cause' => [
                    'code' => $this->firstRecordValue($record, ['causa']),
                    'type' => $this->firstRecordValue($record, ['tipocausa']),
                    'family' => $this->firstRecordValue($record, ['causafamilia']),
                ],
                'weather' => [
                    'temperature_c' => $this->nullableFloat($this->firstRecordValue($record, ['temperatura'])),
                    'relative_humidity' => $this->nullableFloat($this->firstRecordValue($record, ['humidad relativa', 'humidade_relativa', 'humidaderelativa'])),
                    'wind_intensity' => $this->nullableFloat($this->firstRecordValue($record, ['ventointensidade'])),
                    'wind_intensity_vector' => $this->nullableFloat($this->firstRecordValue($record, ['ventointensidade_vetor'])),
                    'wind_direction_vector' => $this->nullableFloat($this->firstRecordValue($record, ['ventodirecao_vetor'])),
                    'precipitation' => $this->nullableFloat($this->firstRecordValue($record, ['precepitacao', 'precipitacao'])),
                ],
                'fire_weather_indices' => [
                    'ffmc' => $this->nullableFloat($this->firstRecordValue($record, ['ffmc'])),
                    'dmc' => $this->nullableFloat($this->firstRecordValue($record, ['dmc'])),
                    'dc' => $this->nullableFloat($this->firstRecordValue($record, ['dc'])),
                    'isi' => $isi,
                    'bui' => $this->nullableFloat($this->firstRecordValue($record, ['bui'])),
                    'fwi' => $fwi,
                    'dsr' => $this->nullableFloat($this->firstRecordValue($record, ['dsr'])),
                ],
                'terrain' => [
                    'altitude_media' => $this->nullableFloat($this->firstRecordValue($record, ['altitudemedia'])),
                    'declive_medio' => $this->nullableFloat($this->firstRecordValue($record, ['declivemedio'])),
                    'horas_exposicao_media' => $this->nullableFloat($this->firstRecordValue($record, ['horasexposicaomedia'])),
                    'densidade_rv' => $this->nullableFloat($this->firstRecordValue($record, ['dendidaderv', 'densidaderv'])),
                    'cosn5_variedade' => $this->nullableFloat($this->firstRecordValue($record, ['cosn5variedade'])),
                ],
                'modfarsite' => $this->firstRecordValue($record, ['modfarsite']),
                'area_mancha_modfarsite' => $this->nullableFloat($this->firstRecordValue($record, ['areamanchamodfarsite'])),
                'aps' => $this->firstRecordValue($record, ['aps']),
                'forest_perimeter' => $this->firstRecordValue($record, ['perimetro', 'perímetro']),
                'kml_url' => $kmlUrl,
                'severity' => $this->classifyIcnfSeverity($areaTotal, $fwi, $isi),
                'raw' => $record,
            ];
        }

        return [
            'format' => 'xml',
            'parser' => 'icnf_active_occurrences',
            'root' => $this->detectXmlRootName($contents),
            'record_tag' => 'CODIGO',
            'count' => count($items),
            'items' => $items,
        ];
    }

    private function extractIcnfCodigoRecords(string $contents): array
    {
        $contents = $this->cleanXmlString($contents);
        $records = [];

        if (!preg_match_all('/<CODIGO\b([^>]*)>(.*?)<\/CODIGO>/is', $contents, $matches, PREG_SET_ORDER)) {
            return [];
        }

        foreach ($matches as $match) {
            $record = [];
            $attributes = $match[1] ?? '';
            $inner = $match[2] ?? '';

            if (preg_match_all('/([A-Za-z_][A-Za-z0-9_:\.\-]*)\s*=\s*(["\'])(.*?)\2/s', $attributes, $attrMatches, PREG_SET_ORDER)) {
                foreach ($attrMatches as $attr) {
                    $record[$this->normalizeKey($attr[1])] = html_entity_decode(trim($attr[3]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                }
            }

            if (preg_match_all('/<([A-Za-z_][A-Za-z0-9_:\.\-]*)\b[^>]*>(.*?)<\/\1>|<([A-Za-z_][A-Za-z0-9_:\.\-]*)\b[^>]*\/\s*>/s', $inner, $fieldMatches, PREG_SET_ORDER)) {
                foreach ($fieldMatches as $fieldMatch) {
                    $tag = $fieldMatch[1] !== '' ? $fieldMatch[1] : $fieldMatch[3];
                    $value = $fieldMatch[1] !== '' ? $fieldMatch[2] : '';
                    $record[$this->normalizeKey($tag)] = html_entity_decode(trim(strip_tags($value)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                }
            }

            if ($this->looksLikeDataRecord($record)) {
                $records[] = $record;
            }
        }

        return $records;
    }

    private function nullableFloat(string $value): ?float
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $normalized = str_replace(',', '.', $value);
        return is_numeric($normalized) ? (float) $normalized : null;
    }

    private function nullableInt(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $normalized = str_replace(',', '.', $value);
        return is_numeric($normalized) ? (int) round((float) $normalized) : null;
    }

    private function flagInt(string $value): bool
    {
        $value = trim($value);
        return $value === '1' || strtolower($value) === 'true' || strtolower($value) === 'yes';
    }

    private function classifyIcnfSeverity(?float $areaTotal, ?float $fwi, ?float $isi): string
    {
        if (($areaTotal !== null && $areaTotal >= 10.0) || ($fwi !== null && $fwi >= 30.0) || ($isi !== null && $isi >= 10.0)) {
            return 'high';
        }

        if (($areaTotal !== null && $areaTotal >= 1.0) || ($fwi !== null && $fwi >= 10.0) || ($isi !== null && $isi >= 5.0)) {
            return 'medium';
        }

        return 'low';
    }

    private function extractXmlRecordsFromString(string $contents): array
    {
        $contents = $this->cleanXmlString($contents);
        $candidateTags = [
            'CODIGO', 'Codigo', 'codigo',
            'Ocorrencia', 'ocorrencia', 'OCORRENCIA',
            'Incendio', 'incendio', 'INCENDIO',
            'row', 'Row', 'item', 'Item', 'Table', 'Table1',
        ];

        foreach ($candidateTags as $tag) {
            $blocks = $this->extractXmlBlocksByTag($contents, $tag);
            $records = [];

            foreach ($blocks as $block) {
                $record = $this->flattenXmlBlock($block);
                if ($this->looksLikeDataRecord($record)) {
                    $records[] = $record;
                }
            }

            if (count($records) > 0) {
                return $records;
            }
        }

        $allBlocks = [];
        if (preg_match_all('/<([A-Za-z_][A-Za-z0-9_:\\.\-]*)\b[^>]*>(.*?)<\/\1>/s', $contents, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $tag = $match[1];
                $inner = $match[2];
                if ($tag !== $this->detectXmlRootName($contents)) {
                    $allBlocks[] = $inner;
                }
            }
        }

        $records = [];
        foreach ($allBlocks as $block) {
            $record = $this->flattenXmlBlock($block);
            if ($this->looksLikeDataRecord($record)) {
                $records[] = $record;
            }
        }

        if (count($records) > 0) {
            return $records;
        }

        $single = $this->flattenXmlBlock($contents);
        return count($single) > 0 ? [$single] : [];
    }

    private function cleanXmlString(string $contents): string
    {
        $contents = preg_replace('/<\?xml[^>]*\?>/i', '', $contents) ?? $contents;
        $contents = preg_replace('/<!--.*?-->/s', '', $contents) ?? $contents;
        $contents = preg_replace_callback('/<!\[CDATA\[(.*?)\]\]>/s', static function (array $match): string {
            return htmlspecialchars($match[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }, $contents) ?? $contents;
        return trim($contents);
    }

    private function extractXmlBlocksByTag(string $contents, string $tag): array
    {
        $safeTag = preg_quote($tag, '/');
        if (!preg_match_all('/<' . $safeTag . '\b[^>]*>(.*?)<\/' . $safeTag . '>/is', $contents, $matches)) {
            return [];
        }
        return $matches[1] ?? [];
    }

    private function flattenXmlBlock(string $block, string $prefix = ''): array
    {
        $record = [];

        if (!preg_match_all('/<([A-Za-z_][A-Za-z0-9_:\\.\-]*)\b([^>]*)>(.*?)<\/\1>/s', $block, $matches, PREG_SET_ORDER)) {
            return $record;
        }

        foreach ($matches as $match) {
            $key = $this->normalizeKey($prefix . $match[1]);
            $attributes = $match[2] ?? '';
            $inner = $match[3] ?? '';

            if (preg_match_all('/([A-Za-z_][A-Za-z0-9_:\\.\-]*)\s*=\s*(["\'])(.*?)\2/s', $attributes, $attrMatches, PREG_SET_ORDER)) {
                foreach ($attrMatches as $attr) {
                    $record[$this->normalizeKey($key . '_' . $attr[1])] = html_entity_decode(trim($attr[3]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                }
            }

            if (preg_match('/<([A-Za-z_][A-Za-z0-9_:\\.\-]*)\b[^>]*>.*?<\/\1>/s', $inner)) {
                $nested = $this->flattenXmlBlock($inner, $key . '_');
                foreach ($nested as $nestedKey => $nestedValue) {
                    $record[$nestedKey] = $nestedValue;
                }
            } else {
                $record[$key] = html_entity_decode(trim(strip_tags($inner)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        }

        return $record;
    }

    private function detectXmlRootName(string $contents): string
    {
        $contents = $this->cleanXmlString($contents);
        if (preg_match('/<([A-Za-z_][A-Za-z0-9_:\\.\-]*)\b[^>]*>/', $contents, $match)) {
            return $match[1];
        }
        return 'unknown';
    }

    private function looksLikeDataRecord(array $record): bool
    {
        if (count($record) < 3) {
            return false;
        }

        $keys = array_keys($record);
        $hasLat = count(array_intersect($keys, ['lat', 'latitude', 'coordy', 'y'])) > 0;
        $hasLon = count(array_intersect($keys, ['lon', 'lng', 'longitude', 'coordx', 'x'])) > 0;
        $hasOccurrenceField = count(array_intersect($keys, ['codigo', 'cod', 'ncco', 'dhinicio', 'concelho', 'distrito', 'local'])) > 0;

        return ($hasLat && $hasLon) || ($hasOccurrenceField && count($record) >= 5);
    }


    private function normalizeKey(string $key): string
    {
        $key = strtolower(trim($key));
        $key = strtr($key, [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
            'é' => 'e', 'ê' => 'e', 'è' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n',
        ]);
        $key = preg_replace('/[^a-z0-9]+/', '_', $key) ?: '';
        return trim($key, '_');
    }

    private function firstRecordValue(array $record, array $keys): string
    {
        foreach ($keys as $key) {
            $normalized = $this->normalizeKey($key);
            if (array_key_exists($normalized, $record) && trim((string) $record[$normalized]) !== '') {
                return trim((string) $record[$normalized]);
            }
        }

        return '';
    }

    private function isNumericCoordinate(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        return is_numeric(str_replace(',', '.', $value));
    }

    private function normalizeDateTime(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $formats = [
            'd-m-Y H:i:s',
            'd/m/Y H:i:s',
            'd-m-Y H:i',
            'd/m/Y H:i',
            'Y-m-d H:i:s',
            DATE_ATOM,
        ];

        foreach ($formats as $format) {
            $date = DateTime::createFromFormat($format, $value);
            if ($date instanceof DateTime) {
                return $date->format(DATE_ATOM);
            }
        }

        $timestamp = strtotime($value);
        return $timestamp ? date(DATE_ATOM, $timestamp) : $value;
    }

    private function extendRuntimeForSource(array $source): void
    {
        $timeoutSeconds = max(1, (int) ($source['timeout_seconds'] ?? 5));
        $runtimeSeconds = max(30, $timeoutSeconds + 20);

        if (function_exists('set_time_limit')) {
            @set_time_limit($runtimeSeconds);
        }

        @ini_set('max_execution_time', (string) $runtimeSeconds);
    }

    private function normalizeSource(array $source): array
    {
        return [
            'id' => $this->safeId((string) ($source['id'] ?? '')),
            'name' => trim((string) ($source['name'] ?? 'Unnamed source')),
            'enabled' => (bool) ($source['enabled'] ?? false),
            'type' => strtolower(trim((string) ($source['type'] ?? 'local_json'))),
            'parser' => $this->safeId((string) ($source['parser'] ?? 'generic')) ?: 'generic',
            'url' => trim((string) ($source['url'] ?? '')),
            'ttl_seconds' => max(10, (int) ($source['ttl_seconds'] ?? 300)),
            'timeout_seconds' => max(1, min(300, (int) ($source['timeout_seconds'] ?? 5))),
            'streaming' => (bool) ($source['streaming'] ?? false),
            'resume' => (bool) ($source['resume'] ?? false),
            'accept_encoding' => trim((string) ($source['accept_encoding'] ?? 'identity')),
            'chunk_size_bytes' => max(8192, min(1048576, (int) ($source['chunk_size_bytes'] ?? 262144))),
            'relevance' => (int) ($source['relevance'] ?? 0),
            'notes' => trim((string) ($source['notes'] ?? '')),
        ];
    }

    private function publicSource(array $source): array
    {
        return [
            'id' => $source['id'],
            'name' => $source['name'],
            'enabled' => $source['enabled'],
            'type' => $source['type'],
            'parser' => $source['parser'],
            'url' => $source['url'],
            'ttl_seconds' => $source['ttl_seconds'],
            'timeout_seconds' => $source['timeout_seconds'],
            'streaming' => $source['streaming'],
            'resume' => $source['resume'],
            'accept_encoding' => $source['accept_encoding'],
            'chunk_size_bytes' => $source['chunk_size_bytes'],
            'relevance' => $source['relevance'],
            'notes' => $source['notes'],
        ];
    }

    private function findSource(string $sourceId): ?array
    {
        $sourceId = $this->safeId($sourceId);

        foreach ($this->getSources(false) as $source) {
            if ($source['id'] === $sourceId) {
                return $source;
            }
        }

        return null;
    }

    private function readCache(string $sourceId): ?array
    {
        $file = $this->cacheFile($sourceId);

        if (!file_exists($file)) {
            return null;
        }

        $json = file_get_contents($file);
        $data = json_decode((string) $json, true);

        return is_array($data) ? $data : null;
    }

    private function writeCache(string $sourceId, array $payload): void
    {
        $file = $this->cacheFile($sourceId);
        file_put_contents($file, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function cacheSummary(array $source, ?array $cache): array
    {
        if (!$cache) {
            return [
                'exists' => false,
                'state' => 'missing',
                'fresh' => false,
                'stale' => false,
                'age_seconds' => null,
                'fetched_at' => null,
                'expires_at' => null,
                'elapsed_ms' => null,
            ];
        }

        $fetchedAt = isset($cache['fetched_at']) ? strtotime((string) $cache['fetched_at']) : false;
        $age = $fetchedAt ? max(0, time() - $fetchedAt) : null;
        $expired = $this->isExpired($source, $cache);

        return [
            'exists' => true,
            'state' => $expired ? 'stale' : 'fresh',
            'fresh' => !$expired,
            'stale' => $expired,
            'age_seconds' => $age,
            'fetched_at' => $cache['fetched_at'] ?? null,
            'expires_at' => $cache['expires_at'] ?? null,
            'elapsed_ms' => $cache['elapsed_ms'] ?? null,
        ];
    }

    private function isExpired(array $source, array $cache): bool
    {
        $fetchedAt = isset($cache['fetched_at']) ? strtotime((string) $cache['fetched_at']) : false;

        if (!$fetchedAt) {
            return true;
        }

        return (time() - $fetchedAt) >= $source['ttl_seconds'];
    }

    private function cacheFile(string $sourceId): string
    {
        return $this->cachePath . DIRECTORY_SEPARATOR . $this->safeId($sourceId) . '.json';
    }

    private function lockFile(string $sourceId): string
    {
        return $this->cachePath . DIRECTORY_SEPARATOR . $this->safeId($sourceId) . '.lock';
    }

    private function acquireLock(string $sourceId)
    {
        $handle = fopen($this->lockFile($sourceId), 'c');

        if (!$handle) {
            return null;
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }

        return $handle;
    }

    private function releaseLock($handle): void
    {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function normalizeLocalPath(string $relativePath): ?string
    {
        $relativePath = ltrim(str_replace(['../', '..\\'], '', $relativePath), '/\\');
        $path = $this->basePath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
        $real = realpath($path);

        if ($real === false) {
            return null;
        }

        $base = realpath($this->basePath);

        if ($base === false || !str_starts_with($real, $base)) {
            return null;
        }

        return $real;
    }

    private function safeId(string $id): string
    {
        $id = strtolower(trim($id));
        return preg_match('/^[a-z0-9_\-]+$/', $id) ? $id : '';
    }
}
