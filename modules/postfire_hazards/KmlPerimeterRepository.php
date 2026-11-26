<?php

declare(strict_types=1);

/**
 * Downloads and parses ICNF/GNR KML/KMZ perimeter files referenced by the
 * AREASFICHEIROS_GNR field. The post-fire module must never depend on the
 * network during normal map rendering, so geometryForItem() uses only local
 * cache. Downloads are explicit through the prefetch_perimeters API action.
 */
final class KmlPerimeterRepository
{
    private string $cacheDir;
    private string $rawDir;
    private string $metaDir;

    public function __construct(private readonly string $projectRoot)
    {
        $this->cacheDir = $this->projectRoot . '/storage/cache/postfire_hazards/perimeters';
        $this->rawDir = $this->cacheDir . '/raw';
        $this->metaDir = $this->cacheDir . '/meta';
        $this->ensureDirectory($this->rawDir);
        $this->ensureDirectory($this->metaDir);
    }

    public function geometryForItem(array $item): ?array
    {
        $url = $this->normalizeUrl((string)($item['kml_url'] ?? ''));
        if ($url === '') {
            return null;
        }

        $hash = $this->urlHash($url);
        $metaFile = $this->metaFile($hash);
        if (!is_file($metaFile)) {
            return null;
        }

        $meta = json_decode((string)file_get_contents($metaFile), true);
        if (!is_array($meta) || !isset($meta['geometry']) || !is_array($meta['geometry'])) {
            return null;
        }

        return $this->validGeoJsonGeometry($meta['geometry']) ? $meta['geometry'] : null;
    }

    public function hasCachedGeometry(string $url): bool
    {
        $url = $this->normalizeUrl($url);
        if ($url === '') {
            return false;
        }
        return $this->geometryForUrl($url) !== null;
    }

    public function geometryForUrl(string $url): ?array
    {
        $url = $this->normalizeUrl($url);
        if ($url === '') {
            return null;
        }

        $metaFile = $this->metaFile($this->urlHash($url));
        if (!is_file($metaFile)) {
            return null;
        }

        $meta = json_decode((string)file_get_contents($metaFile), true);
        if (!is_array($meta) || !isset($meta['geometry']) || !is_array($meta['geometry'])) {
            return null;
        }
        return $this->validGeoJsonGeometry($meta['geometry']) ? $meta['geometry'] : null;
    }

    public function cacheStatus(array $items): array
    {
        $urls = $this->extractUniqueUrls($items);
        $cached = 0;
        foreach ($urls as $url) {
            if ($this->geometryForUrl($url) !== null) {
                $cached++;
            }
        }

        return [
            'available_urls' => count($urls),
            'cached_geometries' => $cached,
            'missing_geometries' => max(0, count($urls) - $cached),
            'cache_dir' => $this->relativePath($this->cacheDir),
            'network_required_for_missing' => true,
        ];
    }

    /**
     * Download and parse a limited number of perimeter files. This is explicit
     * because it may contact ICNF/GNR URLs and should not block map rendering.
     */
    public function prefetch(array $items, int $limit = 10, bool $force = false): array
    {
        $urls = $this->extractUniqueUrls($items);
        $limit = max(1, min(50, $limit));
        $results = [];
        $attempted = 0;
        $cached = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($urls as $url) {
            if ($attempted >= $limit) {
                $skipped++;
                continue;
            }

            if (!$force && $this->geometryForUrl($url) !== null) {
                $cached++;
                $results[] = [
                    'url' => $url,
                    'success' => true,
                    'mode' => 'already-cached',
                ];
                continue;
            }

            $attempted++;
            $result = $this->downloadAndParse($url, $force);
            if (($result['success'] ?? false) === true) {
                $cached++;
            } else {
                $failed++;
            }
            $results[] = $result;
        }

        return [
            'success' => true,
            'available_urls' => count($urls),
            'attempted_downloads' => $attempted,
            'cached_or_parsed' => $cached,
            'failed' => $failed,
            'skipped_due_to_limit' => $skipped,
            'limit' => $limit,
            'force' => $force,
            'results' => $results,
            'cache_dir' => $this->relativePath($this->cacheDir),
        ];
    }

    public function downloadAndParse(string $url, bool $force = false): array
    {
        $url = $this->normalizeUrl($url);
        if ($url === '') {
            return ['success' => false, 'error' => 'Empty KML/KMZ URL.'];
        }

        $hash = $this->urlHash($url);
        $extension = $this->extensionFromUrl($url);
        $rawFile = $this->rawDir . '/' . $hash . '.' . $extension;
        $metaFile = $this->metaFile($hash);

        if (!$force && is_file($metaFile)) {
            $meta = json_decode((string)file_get_contents($metaFile), true);
            if (is_array($meta) && isset($meta['geometry'])) {
                return [
                    'success' => true,
                    'url' => $url,
                    'mode' => 'already-cached',
                    'geometry_type' => $meta['geometry']['type'] ?? null,
                    'raw_file' => $this->relativePath($rawFile),
                    'meta_file' => $this->relativePath($metaFile),
                ];
            }
        }

        $download = $this->downloadToFile($url, $rawFile);
        if (($download['success'] ?? false) !== true) {
            return array_merge(['url' => $url], $download);
        }

        $parse = $this->parseFile($rawFile, $extension);
        if (($parse['success'] ?? false) !== true) {
            return array_merge(['url' => $url, 'raw_file' => $this->relativePath($rawFile)], $parse);
        }

        $meta = [
            'url' => $url,
            'downloaded_at' => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
            'raw_file' => $this->relativePath($rawFile),
            'geometry' => $parse['geometry'],
            'geometry_source' => $parse['geometry_source'] ?? 'kml-coordinates',
            'rings' => $parse['rings'] ?? null,
            'bytes' => filesize($rawFile) ?: null,
        ];
        file_put_contents($metaFile, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return [
            'success' => true,
            'url' => $url,
            'mode' => 'downloaded-and-parsed',
            'geometry_type' => $parse['geometry']['type'] ?? null,
            'rings' => $parse['rings'] ?? null,
            'raw_file' => $this->relativePath($rawFile),
            'meta_file' => $this->relativePath($metaFile),
        ];
    }

    public function parseFile(string $file, ?string $extension = null): array
    {
        if (!is_file($file)) {
            return ['success' => false, 'error' => 'KML/KMZ file not found: ' . $file];
        }

        $extension = strtolower($extension ?: pathinfo($file, PATHINFO_EXTENSION));
        if ($extension === 'kmz') {
            $kml = $this->extractKmlFromKmz($file);
            if ($kml === null) {
                return ['success' => false, 'error' => 'Unable to extract KML from KMZ. ZipArchive may be disabled or KMZ is invalid.'];
            }
        } else {
            $kml = (string)file_get_contents($file);
        }

        return $this->parseKmlString($kml);
    }

    public function parseKmlString(string $kml): array
    {
        $kml = trim($kml);
        if ($kml === '') {
            return ['success' => false, 'error' => 'KML is empty.'];
        }

        $rings = [];
        if (preg_match_all('/<coordinates[^>]*>(.*?)<\/coordinates>/is', $kml, $matches)) {
            foreach ($matches[1] as $block) {
                $ring = $this->parseCoordinateBlock((string)$block);
                if (count($ring) >= 4) {
                    $rings[] = $ring;
                }
            }
        }

        $rings = $this->deduplicateRings($rings);
        if (empty($rings)) {
            return ['success' => false, 'error' => 'No valid coordinate rings found in KML.'];
        }

        usort($rings, fn(array $a, array $b): int => $this->ringArea($b) <=> $this->ringArea($a));
        $polygons = [];
        foreach ($rings as $ring) {
            if ($this->ringArea($ring) <= 0.0) {
                continue;
            }
            $polygons[] = [$ring];
        }

        if (empty($polygons)) {
            return ['success' => false, 'error' => 'Coordinate rings were present but not polygonal.'];
        }

        $geometry = count($polygons) === 1
            ? ['type' => 'Polygon', 'coordinates' => $polygons[0]]
            : ['type' => 'MultiPolygon', 'coordinates' => $polygons];

        return [
            'success' => true,
            'geometry' => $geometry,
            'geometry_source' => 'kml-coordinates',
            'rings' => count($polygons),
        ];
    }

    private function parseCoordinateBlock(string $block): array
    {
        $block = html_entity_decode(strip_tags($block), ENT_QUOTES | ENT_XML1, 'UTF-8');
        $tokens = preg_split('/\s+/', trim($block)) ?: [];
        $ring = [];

        foreach ($tokens as $token) {
            $parts = explode(',', trim($token));
            if (count($parts) < 2) {
                continue;
            }
            if (!is_numeric($parts[0]) || !is_numeric($parts[1])) {
                continue;
            }
            $lon = round((float)$parts[0], 7);
            $lat = round((float)$parts[1], 7);
            if ($lon < -180 || $lon > 180 || $lat < -90 || $lat > 90) {
                continue;
            }
            $point = [$lon, $lat];
            if (empty($ring) || $ring[count($ring) - 1] !== $point) {
                $ring[] = $point;
            }
        }

        if (count($ring) >= 3 && $ring[0] !== $ring[count($ring) - 1]) {
            $ring[] = $ring[0];
        }

        return $ring;
    }

    private function deduplicateRings(array $rings): array
    {
        $seen = [];
        $out = [];
        foreach ($rings as $ring) {
            $key = sha1(json_encode($ring));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $ring;
        }
        return $out;
    }

    private function ringArea(array $ring): float
    {
        $area = 0.0;
        $count = count($ring);
        if ($count < 4) {
            return 0.0;
        }
        for ($i = 0; $i < $count - 1; $i++) {
            $x1 = (float)$ring[$i][0];
            $y1 = (float)$ring[$i][1];
            $x2 = (float)$ring[$i + 1][0];
            $y2 = (float)$ring[$i + 1][1];
            $area += ($x1 * $y2) - ($x2 * $y1);
        }
        return abs($area / 2.0);
    }

    private function extractKmlFromKmz(string $file): ?string
    {
        if (!class_exists('ZipArchive')) {
            return null;
        }

        $zip = new ZipArchive();
        if ($zip->open($file) !== true) {
            return null;
        }

        $bestIndex = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string)$zip->getNameIndex($i);
            if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'kml') {
                $bestIndex = $i;
                if (strtolower(basename($name)) === 'doc.kml') {
                    break;
                }
            }
        }

        if ($bestIndex === null) {
            $zip->close();
            return null;
        }

        $content = $zip->getFromIndex($bestIndex);
        $zip->close();
        return is_string($content) ? $content : null;
    }

    private function downloadToFile(string $url, string $targetFile): array
    {
        $this->ensureDirectory(dirname($targetFile));
        $tmpFile = $targetFile . '.part';
        $encodedUrl = $this->encodeUrlPath($url);
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: FireRisk/0.6.2\r\nAccept: application/vnd.google-earth.kml+xml, application/vnd.google-earth.kmz, application/xml, text/xml, */*\r\nAccept-Encoding: identity\r\n",
                'timeout' => 90,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $in = @fopen($encodedUrl, 'rb', false, $context);
        if (!is_resource($in)) {
            return ['success' => false, 'error' => 'Unable to open KML/KMZ URL.'];
        }
        $out = @fopen($tmpFile, 'wb');
        if (!is_resource($out)) {
            fclose($in);
            return ['success' => false, 'error' => 'Unable to write temporary perimeter file.'];
        }

        $bytes = @stream_copy_to_stream($in, $out);
        fclose($out);
        fclose($in);

        if (!is_int($bytes) || $bytes <= 0) {
            @unlink($tmpFile);
            return ['success' => false, 'error' => 'Downloaded perimeter file was empty.'];
        }

        @rename($tmpFile, $targetFile);
        return ['success' => true, 'bytes' => $bytes];
    }

    private function extractUniqueUrls(array $items): array
    {
        $urls = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $url = $this->normalizeUrl((string)($item['kml_url'] ?? ''));
            if ($url !== '') {
                $urls[$url] = true;
            }
        }
        return array_keys($urls);
    }

    private function normalizeUrl(string $url): string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_XML1, 'UTF-8'));
        if ($url === '') {
            return '';
        }
        if (!preg_match('/^https?:\/\//i', $url)) {
            return '';
        }
        if (!preg_match('/\.(kml|kmz)(?:$|[?# ])/i', $url)) {
            return '';
        }
        return $url;
    }

    private function encodeUrlPath(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return str_replace(' ', '%20', $url);
        }

        $path = $parts['path'] ?? '';
        $segments = array_map(static fn(string $segment): string => rawurlencode(rawurldecode($segment)), explode('/', $path));
        $rebuilt = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $rebuilt .= ':' . $parts['port'];
        }
        $rebuilt .= implode('/', $segments);
        if (isset($parts['query'])) {
            $rebuilt .= '?' . $parts['query'];
        }
        if (isset($parts['fragment'])) {
            $rebuilt .= '#' . $parts['fragment'];
        }
        return $rebuilt;
    }

    private function extensionFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: $url;
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($ext, ['kml', 'kmz'], true) ? $ext : 'kml';
    }

    private function validGeoJsonGeometry(array $geometry): bool
    {
        $type = $geometry['type'] ?? null;
        return in_array($type, ['Polygon', 'MultiPolygon'], true) && isset($geometry['coordinates']) && is_array($geometry['coordinates']);
    }

    private function urlHash(string $url): string
    {
        return sha1($this->normalizeUrl($url));
    }

    private function metaFile(string $hash): string
    {
        return $this->metaDir . '/' . $hash . '.json';
    }

    private function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }

    private function relativePath(string $path): string
    {
        $root = rtrim($this->projectRoot, '/') . '/';
        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }
}
