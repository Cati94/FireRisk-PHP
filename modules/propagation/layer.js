window.FireRiskModules = window.FireRiskModules || {};

window.FireRiskModules.propagation = {
    sourceId: 'firerisk-propagation-preview',
    fillLayerId: 'firerisk-propagation-preview-fill',
    lineLayerId: 'firerisk-propagation-preview-line',
    latestResult: null,

    async loadLayer(map, module, core) {
        const result = await this.fetchProjection(module, 'geojson');
        this.latestResult = result;
        this.renderLayer(map, result.geojson || this.emptyFeatureCollection(), core);
    },

    async onOpen(module, core) {
        this.bindButtons(module, core);
        const result = this.latestResult || await this.fetchProjection(module, 'geojson');
        this.latestResult = result;
        this.renderLayer(core.map, result.geojson || this.emptyFeatureCollection(), core);
        this.renderPanel(result, core);
    },

    bindButtons(module, core) {
        const refresh = document.getElementById('propagation-refresh');
        const test = document.getElementById('propagation-load-test');
        const method = document.getElementById('propagation-show-method');

        if (refresh) {
            refresh.onclick = async () => this.run(module, core, 'geojson');
        }

        if (test) {
            test.onclick = async () => this.run(module, core, 'geojson&source=test&date=2026-02-24');
        }

        if (method) {
            method.onclick = async () => this.showMethod(module, core);
        }
    },

    async run(module, core, action) {
        const message = document.getElementById('propagation-message');
        if (message) {
            message.className = 'propagation-message loading';
            message.textContent = 'Loading propagation preview...';
        }

        try {
            const result = await this.fetchProjection(module, action);
            this.latestResult = result;
            this.renderLayer(core.map, result.geojson || this.emptyFeatureCollection(), core);
            this.renderPanel(result, core);
        } catch (error) {
            this.renderError(error);
        }
    },

    async showMethod(module, core) {
        try {
            const result = await this.fetchProjection(module, 'method');
            this.writeOutput(result);
            const message = document.getElementById('propagation-message');
            if (message) {
                message.className = 'propagation-message';
                message.textContent = 'Propagation method loaded in the details panel.';
            }
        } catch (error) {
            this.renderError(error);
        }
    },

    async fetchProjection(module, action = 'geojson') {
        const separator = module.api.includes('?') ? '&' : '?';
        const response = await fetch(`${module.api}${separator}action=${action}`, { cache: 'no-store' });

        if (!response.ok) {
            throw new Error(`Propagation API returned ${response.status}`);
        }

        const result = await response.json();
        if (!result.success) {
            throw new Error(result.error || 'Propagation API response was not successful.');
        }

        return result;
    },

    renderLayer(map, geojson, core) {
        if (!map || typeof map.getSource !== 'function') {
            return;
        }

        const apply = () => {
            if (map.getSource(this.sourceId)) {
                map.getSource(this.sourceId).setData(geojson);
                return;
            }

            map.addSource(this.sourceId, {
                type: 'geojson',
                data: geojson
            });

            map.addLayer({
                id: this.fillLayerId,
                type: 'fill',
                source: this.sourceId,
                paint: {
                    'fill-color': [
                        'match',
                        ['get', 'risk_level'],
                        'critical', '#dc2626',
                        'high', '#ef4444',
                        'medium', '#f59e0b',
                        'low', '#22c55e',
                        '#6b7280'
                    ],
                    'fill-opacity': [
                        'match',
                        ['get', 'horizon_minutes'],
                        30, 0.28,
                        60, 0.18,
                        120, 0.10,
                        0.12
                    ]
                }
            });

            map.addLayer({
                id: this.lineLayerId,
                type: 'line',
                source: this.sourceId,
                paint: {
                    'line-color': [
                        'match',
                        ['get', 'risk_level'],
                        'critical', '#dc2626',
                        'high', '#ef4444',
                        'medium', '#f59e0b',
                        'low', '#22c55e',
                        '#6b7280'
                    ],
                    'line-width': [
                        'match',
                        ['get', 'horizon_minutes'],
                        30, 2.5,
                        60, 1.8,
                        120, 1.1,
                        1
                    ],
                    'line-opacity': 0.85
                }
            });

            map.on('click', this.fillLayerId, (event) => {
                const feature = event.features && event.features[0];
                if (!feature) {
                    return;
                }
                const props = feature.properties || {};
                const coordinates = event.lngLat;
                new maplibregl.Popup()
                    .setLngLat(coordinates)
                    .setHTML(`
                        <strong>${core.escapeHtml(props.name || 'Propagation preview')}</strong><br>
                        Código: ${core.escapeHtml(props.codigo || '')}<br>
                        Horizon: ${core.escapeHtml(props.horizon_minutes || '')} min<br>
                        Rate: ${core.escapeHtml(props.spread_rate_m_per_h || '')} m/h<br>
                        Risk: ${core.escapeHtml(props.risk_level || '')}<br>
                        Confidence: ${core.escapeHtml(props.confidence || '')}<br>
                        Model: ${core.escapeHtml(props.model || '')}
                    `)
                    .addTo(map);
            });

            map.on('mouseenter', this.fillLayerId, () => {
                map.getCanvas().style.cursor = 'pointer';
            });

            map.on('mouseleave', this.fillLayerId, () => {
                map.getCanvas().style.cursor = '';
            });
        };

        if (map.isStyleLoaded()) {
            apply();
        } else {
            map.once('style.load', apply);
        }
    },

    renderPanel(result, core) {
        const message = document.getElementById('propagation-message');
        const overview = document.getElementById('propagation-overview');
        const list = document.getElementById('propagation-list');
        const projections = result.projections || [];

        if (message) {
            const warning = result.source_warning ? ` ${result.source_warning}` : '';
            message.className = 'propagation-message';
            message.textContent = `Projected ${Number(result.count || 0)} occurrence(s) for ${result.filter_date || 'today'} using ${result.source_mode || 'source'}.${warning}`;
        }

        if (overview) {
            const levels = result.overview && result.overview.risk_levels ? result.overview.risk_levels : {};
            const cards = [
                ['Date records', result.date_count ?? 0],
                ['Projected', result.count ?? 0],
                ['Critical', levels.critical ?? 0],
                ['High', levels.high ?? 0],
                ['Medium', levels.medium ?? 0],
                ['Radial zones', result.overview?.radial_uncertainty_count ?? 0],
                ['Directional', result.overview?.directional_projection_count ?? 0],
                ['Max m/h', this.formatNumber(result.overview?.max_spread_rate_m_per_h)]
            ];

            overview.innerHTML = cards.map(([label, value]) => `
                <article class="propagation-stat">
                    <span>${core.escapeHtml(label)}</span>
                    <strong>${core.escapeHtml(String(value ?? '—'))}</strong>
                </article>
            `).join('');
        }

        if (list) {
            if (projections.length === 0) {
                list.innerHTML = '<div class="notice">No current-day ICNF records available for propagation. The today-only filter is active.</div>';
            } else {
                list.innerHTML = projections.map((projection) => this.renderProjectionCard(projection, core)).join('');
                list.querySelectorAll('.propagation-card').forEach((card) => {
                    card.addEventListener('click', () => {
                        core.flyTo(card.dataset.lng, card.dataset.lat, 12);
                    });
                });
            }
        }

        this.writeOutput(result);
    },

    renderProjectionCard(projection, core) {
        const metrics = projection.metrics || {};
        const confidence = projection.confidence || {};
        const direction = projection.direction || {};
        const notes = Array.isArray(projection.operational_notes) ? projection.operational_notes : [];
        const horizons = Array.isArray(projection.horizons) ? projection.horizons : [];
        const coords = projection.coordinates || {};

        return `
            <button class="propagation-card ${core.escapeHtml(projection.risk_level || 'low')}" type="button" data-lng="${Number(coords.longitude)}" data-lat="${Number(coords.latitude)}">
                <strong>${core.escapeHtml(projection.name || projection.codigo || 'Projection')}</strong>
                <span class="meta">
                    Código: ${core.escapeHtml(projection.codigo || '')}<br>
                    ${core.escapeHtml([projection.municipality, projection.district].filter(Boolean).join(', '))}<br>
                    Risk: ${core.escapeHtml(projection.risk_level || 'low')} · Confidence: ${core.escapeHtml(confidence.level || 'low')}<br>
                    Rate: ${core.escapeHtml(this.formatNumber(projection.spread?.rate_m_per_h))} m/h · Direction: ${direction.available ? `${core.escapeHtml(direction.degrees)}º` : 'radial uncertainty'}<br>
                    Area: ${core.escapeHtml(this.formatNumber(metrics.area_total_ha))} ha · FWI: ${core.escapeHtml(this.formatNumber(metrics.fwi))} · ISI: ${core.escapeHtml(this.formatNumber(metrics.isi))}<br>
                    Horizons: ${horizons.map((horizon) => `${core.escapeHtml(horizon.minutes)}m/${core.escapeHtml(this.formatNumber(horizon.front_distance_m))}m`).join(' · ')}
                </span>
                ${notes.length ? `<span class="propagation-notes">${notes.map((note) => core.escapeHtml(note)).join(' ')}</span>` : ''}
            </button>
        `;
    },

    renderError(error) {
        const message = document.getElementById('propagation-message');
        if (message) {
            message.className = 'propagation-message error';
            message.textContent = `Propagation preview failed: ${error.message}`;
        }
        this.writeOutput({ success: false, error: error.message });
    },

    writeOutput(value) {
        const output = document.getElementById('propagation-output');
        if (output) {
            output.textContent = JSON.stringify(value, null, 2);
        }
    },

    emptyFeatureCollection() {
        return { type: 'FeatureCollection', features: [] };
    },

    formatNumber(value) {
        if (value === null || value === undefined || value === '') {
            return '—';
        }
        const number = Number(value);
        if (Number.isNaN(number)) {
            return '—';
        }
        if (Math.abs(number) >= 100) {
            return number.toFixed(0);
        }
        if (Math.abs(number) >= 10) {
            return number.toFixed(1);
        }
        return number.toFixed(2);
    }
};
