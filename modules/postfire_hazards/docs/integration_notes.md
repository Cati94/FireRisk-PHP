# Post-Fire Hazards — FireRisk v0.6.3 Integration Notes

This module restores the important behaviour from the original standalone PHP ZIP while keeping the current FireRisk module architecture.

## Restored from the original module

- scenario simulation with rain 1h, rain 24h and soil saturation;
- default scenario JSON;
- weak / strong / extreme rain presets;
- top metrics for combined, flood/runoff and erosion/landslide risk;
- burned-area cards;
- prioritized zone table;
- one independent map layer per burned area;
- 12-month rolling burned-area window;
- module manifest.

## FireRisk-specific changes

The original mock GeoJSON repository is replaced by an ICNF adapter:

```text
slow_data_fetching local ICNF cache/test XML
    -> normalized ICNF records
    -> postfire_hazards burned area FeatureCollection
    -> optional cached KML/KMZ geometry
    -> fallback centroid/area polygon
```

The module remains non-operational/demonstrative until hydrology, DEM/slope, drainage, soil/geology and observed/forecast precipitation are validated.
