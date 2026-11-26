# FireRisk PHP v0.7.2

Short architecture improvement: **ICNF Ocorrências Activas is now the central base data provider for every operational module**.

The app remains multi-page, not SPA:

```text
/                         -> server-rendered module launcher
/module.php?module=<id>   -> isolated module workspace
/api/module.php           -> module APIs
/api/modules.php          -> registry API for tests/tools only
```

The home page does **not** load MapLibre, module JavaScript, module CSS, dynamic module panels, or automatic layers. It only lists enabled modules from `modules.xml` and links to their own pages.

## Core rule from v0.7.2 onward

```text
ICNF Ocorrências Activas = central normalized data provider
```

Every module that needs fire/occurrence/burned-area/weather context must consume the same normalized ICNF dataset instead of reading its own independent base data.

Added central provider:

```text
core/IcnfActiveOccurrencesProvider.php
```

This provider owns:

```text
- remote ICNF cache
- standard ICNF XML test fixture
- demo XML fallback
- date filtering
- source selection: best / test / demo / remote / refresh
- normalized records
- GeoJSON point conversion
- provider status metadata
```

## Modules now using the central ICNF provider

```text
Fire Occurrences     -> presents ICNF records as operational fire cards/points
ICNF Ocorrências     -> exposes the central normalized dataset directly
Weather              -> derives weather aggregate from ICNF weather fields when available
Decision Support     -> ranks ICNF records
Fire Propagation     -> previews spread from ICNF records
Post-Fire Hazards    -> derives 12-month burned-area features from ICNF records
```

`slow_data_fetching` remains the low-level fetch/cache engine. It is infrastructure, not an independent operational base-data module.

## Active modules

```text
100 Fire Occurrences          -> /module.php?module=fire_occurrences
98  ICNF Ocorrências Activas  -> /module.php?module=icnf_active_occurrences
97  Decision Support          -> /module.php?module=decision_support
96  Fire Propagation          -> /module.php?module=propagation
90  Weather                   -> /module.php?module=weather
88  Post-Fire Hazards         -> /module.php?module=postfire_hazards
85  Slow Data Fetching        -> /module.php?module=slow_data_fetching
```

## Run

```powershell
php -S localhost:8000 -t public
```

Open:

```text
http://localhost:8000
```

Map-capable module pages load the pinned MapLibre 5.24.0 runtime from
`public/vendor/maplibre-gl/5.24.0`; they do not require a JavaScript CDN.
OpenStreetMap supplies the background tiles, while FireRisk overlays and a
visible fallback remain available if those tiles cannot be reached.

Open modules directly:

```text
http://localhost:8000/module.php?module=icnf_active_occurrences
http://localhost:8000/module.php?module=fire_occurrences
http://localhost:8000/module.php?module=decision_support
http://localhost:8000/module.php?module=propagation
http://localhost:8000/module.php?module=weather
http://localhost:8000/module.php?module=postfire_hazards
```

## Useful API checks

Central ICNF records for current day:

```text
/api/module.php?module=icnf_active_occurrences&action=data
```

Central ICNF standard fixture with date override:

```text
/api/module.php?module=icnf_active_occurrences&action=test&date=2026-02-24
```

Fire Occurrences using central ICNF data:

```text
/api/module.php?module=fire_occurrences&action=test&date=2026-02-24
```

Decision Support using central ICNF data:

```text
/api/module.php?module=decision_support&action=analysis&source=test&date=2026-02-24
```

Propagation using central ICNF data:

```text
/api/module.php?module=propagation&action=geojson&source=test&date=2026-02-24
```

Post-Fire Hazards using central ICNF data:

```text
/api/module.php?module=postfire_hazards&action=risk&source=test&reference_date=2026-02-24&months=12
```

## Important no-SPA rule

Do not reintroduce SPA loading.

Avoid this in `public/index.php`:

```text
MapLibre startup
fetch('/api/modules.php')
load all module assets
load all module layers
inject module GUIs into one panel
```

Use this instead:

```text
server-rendered links
standalone module pages
module-specific JS/CSS only on that module page
module-specific map/layer only on that module page
```

## Tests

```powershell
php tests/test_central_icnf_provider.php
php tests/test_icnf_standard_fixture.php
php tests/test_today_filters.php
php tests/test_fire_occurrences_uses_icnf.php
php tests/test_decision_support.php
php tests/test_propagation.php
php tests/test_postfire_hazards.php
php tests/test_postfire_perimeters.php
php tests/test_postfire_restored_features.php
php tests/test_heavy_module_isolation.php
php tests/test_postfire_analysis_cache.php
php tests/test_no_spa_home.php
php tests/test_local_map_runtime.php
```

## Commit

```powershell
git add .
git commit -m "Centralize operational modules on ICNF active occurrences data"
```

## Important limitation

The propagation and post-fire modules are tactical/demonstrative previews, not validated scientific simulators. Before operational use they require validated fuels, wind, terrain, hydrology, soil/geology and field confirmation.
