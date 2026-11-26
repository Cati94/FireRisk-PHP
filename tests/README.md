# FireRisk test commands

Run from the project root:

```powershell
php tests/test_icnf_standard_fixture.php
php tests/test_today_filters.php
php tests/test_decision_support.php
php tests/test_propagation.php
php tests/test_local_map_runtime.php
```

The decision-support and propagation tests use the standard ICNF XML fixture with `date=2026-02-24` so they do not depend on today's real ICNF feed.

## v0.6.1 Post-Fire Hazards

```powershell
php tests/test_postfire_hazards.php
```

Validates that the post-fire module derives burned areas from the locally stored ICNF XML/parser cache path and generates risk + GeoJSON output.
