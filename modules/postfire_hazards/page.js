window.FireRiskModules = window.FireRiskModules || {};

window.FireRiskModules.postfire_hazards = {
    areaLayerIds: [],
    zoneSourceId: 'firerisk-postfire-hazard-zones',
    zoneLayerId: 'firerisk-postfire-hazard-zones-circle',
    latestResult: null,
    latestModule: null,
    defaultScenario: { rain_1h_mm: 28, rain_24h_mm: 72, soil_saturation: 0.68, months: 12 },
    levelColors: {
        residual: '#6b7280',
        low: '#22c55e',
        medium: '#f59e0b',
        high: '#ef4444',
        critical: '#dc2626'
    },

    async loadLayer(map, module, core) {
        this.latestModule = module;
        const result = await this.fetch(module, 'risk');
        this.latestResult = result;
        this.renderMap(map, result, core);
    },

    async onOpen(module, core) {
        this.latestModule = module;
        await this.loadDefaults(module);
        this.setScenarioValues(this.defaultScenario);
        this.bindUi(module, core);

        const shouldAutoRun = new URLSearchParams(window.location.search).get('autorun') === '1';

        if (this.latestResult) {
            this.renderMap(core.map, this.latestResult, core);
            this.renderPanel(this.latestResult, core);
            return;
        }

        if (shouldAutoRun) {
            await this.runScenario(module, core, false, false);
            return;
        }

        this.renderReadyState(core);
    },

    renderReadyState(core) {
        const message = document.getElementById('postfire-message');
        if (message) {
            message.className = 'postfire-message';
            message.textContent = 'Heavy workspace ready. Press “Update risk” to analyse locally stored ICNF burned areas. This keeps the main FireRisk map fast.';
        }
        this.renderMap(core.map, { burned_areas_geojson: this.emptyFeatureCollection(), zones: [] }, core);
        this.writeOutput({
            status: 'ready',
            load_mode: 'heavy_page',
            note: 'Analysis is intentionally manual on page open to avoid blocking the interface with thousands of ICNF-derived burned areas.'
        });
    },

    async loadDefaults(module) {
        try {
            const result = await this.fetch(module, 'defaults');
            const scenario = result.scenario || {};
            this.defaultScenario = {
                rain_1h_mm: Number(scenario.rain_1h_mm ?? 28),
                rain_24h_mm: Number(scenario.rain_24h_mm ?? 72),
                soil_saturation: Number(scenario.soil_saturation ?? 0.68),
                months: 12
            };
        } catch (error) {
            console.warn('Post-fire defaults unavailable, using built-in defaults.', error);
        }
    },

    bindUi(module, core) {
        const form = document.getElementById('postfire-scenario-form');
        const reset = document.getElementById('postfire-reset');
        const test = document.getElementById('postfire-load-test');
        const prefetch = document.getElementById('postfire-prefetch-perimeters');
        const perimeterStatus = document.getElementById('postfire-perimeter-status');
        const method = document.getElementById('postfire-show-method');
        const manifest = document.getElementById('postfire-show-manifest');
        const cacheStatus = document.getElementById('postfire-cache-status');
        const forceRecalc = document.getElementById('postfire-force-recalc');

        this.bindPair('postfire-rain-1h', 'postfire-rain-1h-range');
        this.bindPair('postfire-rain-24h', 'postfire-rain-24h-range');
        this.bindPair('postfire-soil', 'postfire-soil-range');
        this.bindPair('postfire-months', 'postfire-months-range');

        if (form) {
            form.onsubmit = (event) => {
                event.preventDefault();
                this.runScenario(module, core, false, false);
            };
        }
        if (reset) {
            reset.onclick = () => {
                this.setScenarioValues(this.defaultScenario);
                this.runScenario(module, core, false, false);
            };
        }
        if (test) {
            test.onclick = () => this.runScenario(module, core, true, false);
        }
        if (prefetch) {
            prefetch.onclick = () => this.prefetchPerimeters(module, core);
        }
        if (perimeterStatus) {
            perimeterStatus.onclick = () => this.showPerimeterStatus(module);
        }
        if (method) {
            method.onclick = () => this.showObject(module, 'method', 'Method loaded.');
        }
        if (manifest) {
            manifest.onclick = () => this.showObject(module, 'manifest', 'Manifest loaded.');
        }
        if (cacheStatus) {
            cacheStatus.onclick = () => this.showObject(module, 'cache_status', 'Analysis cache status loaded.');
        }
        if (forceRecalc) {
            forceRecalc.onclick = () => this.runScenario(module, core, false, true);
        }

        document.querySelectorAll('.postfire-preset').forEach((button) => {
            button.onclick = () => {
                this.setScenarioValues({
                    rain_1h_mm: Number(button.dataset.rain1h),
                    rain_24h_mm: Number(button.dataset.rain24h),
                    soil_saturation: Number(button.dataset.soil),
                    months: Number(document.getElementById('postfire-months')?.value || 12)
                });
                this.runScenario(module, core, false, false);
            };
        });
    },

    bindPair(numberId, rangeId) {
        const number = document.getElementById(numberId);
        const range = document.getElementById(rangeId);
        if (!number || !range || number.dataset.bound === '1') return;
        number.dataset.bound = '1';
        range.dataset.bound = '1';
        number.addEventListener('input', () => range.value = number.value);
        range.addEventListener('input', () => number.value = range.value);
    },

    setScenarioValues(scenario) {
        this.setValue('postfire-rain-1h', scenario.rain_1h_mm ?? 28);
        this.setValue('postfire-rain-1h-range', scenario.rain_1h_mm ?? 28);
        this.setValue('postfire-rain-24h', scenario.rain_24h_mm ?? 72);
        this.setValue('postfire-rain-24h-range', scenario.rain_24h_mm ?? 72);
        this.setValue('postfire-soil', scenario.soil_saturation ?? 0.68);
        this.setValue('postfire-soil-range', scenario.soil_saturation ?? 0.68);
        this.setValue('postfire-months', scenario.months ?? 12);
        this.setValue('postfire-months-range', scenario.months ?? 12);
    },

    setValue(id, value) {
        const el = document.getElementById(id);
        if (el) el.value = value;
    },

    scenarioFromInputs() {
        return {
            rain_1h_mm: Number(document.getElementById('postfire-rain-1h')?.value || 28),
            rain_24h_mm: Number(document.getElementById('postfire-rain-24h')?.value || 72),
            soil_saturation: Number(document.getElementById('postfire-soil')?.value || 0.68),
            months: Number(document.getElementById('postfire-months')?.value || 12)
        };
    },

    async runScenario(module, core, useTestSource, forceRecalculation = false) {
        const message = document.getElementById('postfire-message');
        if (message) {
            message.className = 'postfire-message loading';
            message.textContent = forceRecalculation ? 'Recalculating post-fire hazard scenario and rebuilding cache...' : 'Loading cached post-fire analysis, or calculating if needed...';
        }

        try {
            const scenario = this.scenarioFromInputs();
            const sourcePart = useTestSource ? '&source=test' : '';
            const forcePart = forceRecalculation ? '&force=1' : '';
            const action = `scenario&months=${encodeURIComponent(scenario.months)}${sourcePart}${forcePart}`;
            const result = await this.fetch(module, action, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(scenario)
            });
            this.latestResult = result;
            this.renderMap(core.map, result, core);
            this.renderPanel(result, core);
        } catch (error) {
            this.renderError(error);
        }
    },

    async prefetchPerimeters(module, core) {
        const message = document.getElementById('postfire-message');
        if (message) {
            message.className = 'postfire-message loading';
            message.textContent = 'Prefetching ICNF/GNR KML/KMZ perimeters into local cache...';
        }

        try {
            const result = await this.fetch(module, 'prefetch_perimeters&limit=10');
            this.writeOutput(result);
            if (message) {
                message.className = 'postfire-message';
                message.textContent = `Perimeter prefetch complete: ${result.cached_or_parsed || 0} cached/parsed, ${result.failed || 0} failed.`;
            }
            await this.runScenario(module, core, false, true);
        } catch (error) {
            this.renderError(error);
        }
    },

    async showPerimeterStatus(module) {
        try {
            const result = await this.fetch(module, 'perimeters');
            this.writeOutput(result);
            const message = document.getElementById('postfire-message');
            const stats = result.perimeters || {};
            if (message) {
                message.className = 'postfire-message';
                message.textContent = `Perimeter cache: ${stats.cached_geometries || 0}/${stats.available_urls || 0} ICNF/GNR KML/KMZ geometries cached.`;
            }
        } catch (error) {
            this.renderError(error);
        }
    },

    async showObject(module, action, messageText) {
        try {
            const result = await this.fetch(module, action);
            this.writeOutput(result);
            const message = document.getElementById('postfire-message');
            if (message) {
                message.className = 'postfire-message';
                message.textContent = messageText;
            }
        } catch (error) {
            this.renderError(error);
        }
    },

    async fetch(module, action = 'risk', options = {}) {
        const sep = module.api.includes('?') ? '&' : '?';
        const response = await fetch(`${module.api}${sep}action=${action}`, Object.assign({ cache: 'no-store' }, options));
        if (!response.ok) {
            throw new Error(`Post-fire API returned ${response.status}`);
        }
        const result = await response.json();
        if (!result.success) {
            throw new Error(result.error || 'Post-fire API response was not successful.');
        }
        return result;
    },

    renderMap(map, result, core) {
        if (!map || typeof map.getSource !== 'function') return;

        const apply = () => {
            const geojson = result.burned_areas_geojson || result.geojson || this.emptyFeatureCollection();
            const zones = result.zones || [];
            this.clearAreaLayers(map);
            this.renderIndependentAreaLayers(map, geojson, core);
            this.renderZoneLayer(map, zones, core);
        };

        if (map.isStyleLoaded()) apply(); else map.once('style.load', apply);
    },

    clearAreaLayers(map) {
        this.areaLayerIds.forEach((entry) => {
            [entry.fillLayerId, entry.lineLayerId].forEach((id) => {
                if (map.getLayer(id)) map.removeLayer(id);
            });
            if (map.getSource(entry.sourceId)) map.removeSource(entry.sourceId);
        });
        this.areaLayerIds = [];
    },

    renderIndependentAreaLayers(map, geojson, core) {
        const features = geojson.features || [];
        features.forEach((feature, index) => {
            const props = feature.properties || {};
            const safeId = this.safeId(feature.id || props.codigo || index);
            const sourceId = `postfire-area-source-${safeId}-${index}`;
            const fillLayerId = `postfire-area-fill-${safeId}-${index}`;
            const lineLayerId = `postfire-area-line-${safeId}-${index}`;
            const color = this.colorFor(props.combined_level || props.risk_summary?.combined_level);

            map.addSource(sourceId, { type: 'geojson', data: { type: 'FeatureCollection', features: [feature] } });
            map.addLayer({
                id: fillLayerId,
                type: 'fill',
                source: sourceId,
                paint: { 'fill-color': color, 'fill-opacity': 0.22 }
            });
            map.addLayer({
                id: lineLayerId,
                type: 'line',
                source: sourceId,
                paint: { 'line-color': color, 'line-width': 2.5, 'line-opacity': 0.9 }
            });
            map.on('click', fillLayerId, (event) => this.areaPopup(event, core));
            this.areaLayerIds.push({ sourceId, fillLayerId, lineLayerId, name: props.name || feature.id || `Area ${index + 1}` });
        });

        this.fitToFeatures(map, features);
        this.renderLayerToggles(map);
    },

    renderZoneLayer(map, zones, core) {
        const zoneFeatures = zones
            .filter((zone) => Number.isFinite(Number(zone.lat)) && Number.isFinite(Number(zone.lon)))
            .map((zone) => ({
                type: 'Feature',
                geometry: { type: 'Point', coordinates: [Number(zone.lon), Number(zone.lat)] },
                properties: Object.assign({}, zone)
            }));

        const data = { type: 'FeatureCollection', features: zoneFeatures };
        if (map.getLayer(this.zoneLayerId)) map.removeLayer(this.zoneLayerId);
        if (map.getSource(this.zoneSourceId)) map.removeSource(this.zoneSourceId);
        map.addSource(this.zoneSourceId, { type: 'geojson', data });
        map.addLayer({
            id: this.zoneLayerId,
            type: 'circle',
            source: this.zoneSourceId,
            paint: {
                'circle-radius': ['+', 6, ['/', ['coalesce', ['get', 'combined_score'], 0], 12]],
                'circle-color': [
                    'match',
                    ['get', 'combined_level'],
                    'critical', '#dc2626',
                    'high', '#ef4444',
                    'medium', '#f59e0b',
                    'low', '#22c55e',
                    'residual', '#6b7280',
                    '#6b7280'
                ],
                'circle-stroke-color': '#ffffff',
                'circle-stroke-width': 1.5,
                'circle-opacity': 0.9
            }
        });
        map.on('click', this.zoneLayerId, (event) => this.zonePopup(event, core));
    },

    fitToFeatures(map, features) {
        const coords = [];
        const walk = (value) => {
            if (!Array.isArray(value)) return;
            if (typeof value[0] === 'number' && typeof value[1] === 'number') {
                coords.push(value);
                return;
            }
            value.forEach(walk);
        };
        features.forEach((feature) => walk(feature.geometry?.coordinates));
        if (!coords.length) return;
        const bounds = coords.reduce((b, c) => b.extend(c), new maplibregl.LngLatBounds(coords[0], coords[0]));
        map.fitBounds(bounds, { padding: 60, maxZoom: 12, duration: 600 });
    },

    renderLayerToggles(map) {
        const box = document.getElementById('postfire-layer-toggles');
        if (!box) return;
        if (!this.areaLayerIds.length) {
            box.innerHTML = 'No burned-area layers loaded.';
            return;
        }
        box.innerHTML = this.areaLayerIds.map((entry, idx) => `
            <label class="postfire-layer-toggle">
                <input type="checkbox" checked data-layer-index="${idx}">
                <span>${this.escape(entry.name)}</span>
            </label>
        `).join('');
        box.querySelectorAll('input[type="checkbox"]').forEach((checkbox) => {
            checkbox.onchange = () => {
                const entry = this.areaLayerIds[Number(checkbox.dataset.layerIndex)];
                const visibility = checkbox.checked ? 'visible' : 'none';
                if (entry && map.getLayer(entry.fillLayerId)) map.setLayoutProperty(entry.fillLayerId, 'visibility', visibility);
                if (entry && map.getLayer(entry.lineLayerId)) map.setLayoutProperty(entry.lineLayerId, 'visibility', visibility);
            };
        });
    },

    areaPopup(event, core) {
        const feature = event.features && event.features[0];
        if (!feature) return;
        const props = feature.properties || {};
        new maplibregl.Popup()
            .setLngLat(event.lngLat)
            .setHTML(`
                <strong>${core.escapeHtml(props.name || 'Burned area')}</strong><br>
                Código: ${core.escapeHtml(props.codigo || '')}<br>
                Município: ${core.escapeHtml(props.municipality || '')}<br>
                Área: ${core.escapeHtml(props.burned_area_ha || '')} ha<br>
                Geometria: ${core.escapeHtml(props.geometry_source || '')}<br>
                Risco: ${core.escapeHtml(props.combined_score || '')} · ${core.escapeHtml(props.combined_level || '')}
            `)
            .addTo(core.map);
    },

    zonePopup(event, core) {
        const feature = event.features && event.features[0];
        if (!feature) return;
        const p = feature.properties || {};
        new maplibregl.Popup()
            .setLngLat(event.lngLat)
            .setHTML(`
                <strong>${core.escapeHtml(p.zone_id || 'Risk zone')} — ${core.escapeHtml(p.name || '')}</strong><br>
                Área: ${core.escapeHtml(p.area_name || '')}<br>
                Cheias: ${core.escapeHtml(p.flood_score || '')} · ${core.escapeHtml(p.flood_level_label || p.flood_level || '')}<br>
                Erosão: ${core.escapeHtml(p.erosion_score || '')} · ${core.escapeHtml(p.erosion_level_label || p.erosion_level || '')}<br>
                Combinado: ${core.escapeHtml(p.combined_score || '')} · ${core.escapeHtml(p.combined_level_label || p.combined_level || '')}
            `)
            .addTo(core.map);
    },

    renderPanel(result, core) {
        const overall = result.overall || {};
        const inputs = result.inputs || {};
        const areas = result.areas || [];
        const zones = result.zones || [];

        this.setMetric('combined', overall.combined_score, overall.combined_level_label || overall.combined_level);
        this.setMetric('flood', overall.flood_score, overall.flood_level_label || overall.flood_level);
        this.setMetric('erosion', overall.erosion_score, overall.erosion_level_label || overall.erosion_level);

        this.setText('postfire-system-date', this.fmtDate(inputs.window_to));
        this.setText('postfire-window-from', this.fmtDate(inputs.window_from));
        this.setText('postfire-area-count', inputs.burned_areas_in_window ?? areas.length);
        this.setText('postfire-excluded-count', inputs.burned_areas_excluded_by_window ?? '—');
        this.setText('postfire-zone-count', zones.length);

        const message = document.getElementById('postfire-message');
        if (message) {
            const cachedPerimeters = areas.filter((area) => area.perimeter_cached).length;
            message.className = 'postfire-message';
            const cacheLabel = result.cache && result.cache.hit ? ` Served from analysis cache (${Math.round((result.cache.age_seconds || 0) / 60)} min old).` : ' Cache rebuilt.';
            message.textContent = `Analysed ${areas.length} burned area(s) from ${result.data_source || 'local ICNF data'}. Independent map layers: ${areas.length}. Cached perimeters used: ${cachedPerimeters}.${cacheLabel}`;
        }

        this.renderAreaCards(areas, core);
        this.renderZoneTable(zones, core);
        this.writeOutput({ inputs, overall, count: result.count, warning: result.warning, data_source: result.data_source, cache: result.cache || null });
    },

    setMetric(key, score, level) {
        this.setText(`postfire-${key}-score`, Math.round(Number(score || 0)));
        this.setText(`postfire-${key}-level`, level || '—');
        const card = document.getElementById(`postfire-${key}-card`);
        if (card) card.dataset.level = this.normalizedLevel(level);
    },

    renderAreaCards(areas, core) {
        const box = document.getElementById('postfire-area-cards');
        if (!box) return;
        if (!areas.length) {
            box.innerHTML = '<div class="notice">No recent burned areas found in the ICNF local cache for this window.</div>';
            return;
        }
        box.innerHTML = areas.slice(0, 30).map((area) => `
            <article class="postfire-area-card" data-level="${this.escape(area.combined_level || 'residual')}">
                <h3>${this.escape(area.name || area.area_id)}</h3>
                <p>${this.escape(area.municipality || '')} · ${this.escape(area.district || '')} · ${this.escape(this.fmtDate(area.start_local))}</p>
                <div class="postfire-card-meta">
                    <span>${this.escape(String(area.burned_area_ha ?? 0))} ha</span>
                    <span>${this.escape(String(area.zone_count ?? 0))} zones</span>
                    <span>${this.escape(area.perimeter_cached ? 'KML/KMZ perimeter' : 'centroid fallback')}</span>
                </div>
                <strong>${this.escape(String(area.combined_score ?? 0))} · ${this.escape(area.combined_level_label || area.combined_level || '—')}</strong>
                <button type="button" class="small-button" onclick="FireRiskCore.flyTo(${Number(area.lon || 0)}, ${Number(area.lat || 0)}, 12)">Go to area</button>
            </article>
        `).join('');
    },

    renderZoneTable(zones, core) {
        const tbody = document.getElementById('postfire-zones');
        if (!tbody) return;
        if (!zones.length) {
            tbody.innerHTML = '<tr><td colspan="6">No prioritized zones.</td></tr>';
            return;
        }
        tbody.innerHTML = zones.slice(0, 60).map((z) => `
            <tr>
                <td><strong>${this.escape(z.zone_id || '')}</strong><br><span>${this.escape(z.name || '')}<br>${this.escape(z.exposure || '')}</span></td>
                <td><strong>${this.escape(z.area_name || '')}</strong><br><span>${this.escape(this.fmtDate(z.area_start_local))} · ${this.escape(String(z.burned_area_ha ?? 0))} ha</span></td>
                <td><b class="postfire-chip" data-level="${this.escape(z.flood_level || '')}">${this.escape(String(z.flood_score ?? 0))} · ${this.escape(z.flood_level_label || z.flood_level || '')}</b></td>
                <td><b class="postfire-chip" data-level="${this.escape(z.erosion_level || '')}">${this.escape(String(z.erosion_score ?? 0))} · ${this.escape(z.erosion_level_label || z.erosion_level || '')}</b></td>
                <td><b class="postfire-chip" data-level="${this.escape(z.combined_level || '')}">${this.escape(String(z.combined_score ?? 0))} · ${this.escape(z.combined_level_label || z.combined_level || '')}</b></td>
                <td>${(z.recommendations || []).map((r) => this.escape(r)).join('<br>')}</td>
            </tr>
        `).join('');
    },

    writeOutput(result) {
        const output = document.getElementById('postfire-output');
        if (output) output.textContent = JSON.stringify(result, null, 2);
    },

    renderError(error) {
        const message = document.getElementById('postfire-message');
        if (message) {
            message.className = 'postfire-message error';
            message.textContent = error.message;
        }
    },

    setText(id, value) {
        const el = document.getElementById(id);
        if (el) el.textContent = value;
    },

    colorFor(level) {
        return this.levelColors[this.normalizedLevel(level)] || '#6b7280';
    },

    normalizedLevel(level) {
        const value = String(level || '').toLowerCase();
        if (value.includes('cr')) return 'critical';
        if (value.includes('elev') || value.includes('high')) return 'high';
        if (value.includes('mod') || value.includes('medium')) return 'medium';
        if (value.includes('baixo') || value.includes('low')) return 'low';
        return value || 'residual';
    },

    safeId(value) {
        return String(value || 'x').toLowerCase().replace(/[^a-z0-9_-]+/g, '-').slice(0, 40) || 'area';
    },

    fmtDate(value) {
        if (!value) return '—';
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return String(value);
        return date.toLocaleDateString('pt-PT');
    },

    escape(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    },

    emptyFeatureCollection() {
        return { type: 'FeatureCollection', features: [] };
    }
};
