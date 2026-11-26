<?php

declare(strict_types=1);

require_once __DIR__ . '/../modules/postfire_hazards/KmlPerimeterRepository.php';

$root = dirname(__DIR__);
$repo = new KmlPerimeterRepository($root);

$kml = <<<'KML'
<?xml version="1.0" encoding="UTF-8"?>
<kml xmlns="http://www.opengis.net/kml/2.2">
  <Document>
    <Placemark>
      <name>Test perimeter</name>
      <Polygon>
        <outerBoundaryIs>
          <LinearRing>
            <coordinates>
              -8.001,41.001,0 -8.002,41.002,0 -8.003,41.001,0 -8.002,41.000,0 -8.001,41.001,0
            </coordinates>
          </LinearRing>
        </outerBoundaryIs>
      </Polygon>
    </Placemark>
  </Document>
</kml>
KML;

$result = $repo->parseKmlString($kml);
if (($result['success'] ?? false) !== true) {
    fwrite(STDERR, "FAIL: Expected KML parser success.\n" . json_encode($result, JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$geometry = $result['geometry'] ?? [];
if (($geometry['type'] ?? '') !== 'Polygon') {
    fwrite(STDERR, "FAIL: Expected Polygon geometry.\n");
    exit(1);
}

$ring = $geometry['coordinates'][0] ?? [];
if (count($ring) !== 5 || $ring[0] !== $ring[count($ring) - 1]) {
    fwrite(STDERR, "FAIL: Expected closed five-point KML ring.\n");
    exit(1);
}

fwrite(STDOUT, "OK: KML perimeter parser created a valid cached-perimeter polygon.\n");
