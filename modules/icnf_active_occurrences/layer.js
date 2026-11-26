window.FireRiskModules = window.FireRiskModules || {};

window.FireRiskModules.icnf_active_occurrences = {
    markers: new Map(),
    latestData: [],
    latestResult: null,

    async loadLayer(map, module, core) {
        const result = await this.fetchData(module, 'data');
        this.latestResult = result;
        this.latestData = result.data || [];
        this.renderMarkers(map, this.latestData, core);
    },

    async onOpen(module, core) {
        await this.bindPage(module, core);
        const result = this.latestResult || await this.fetchData(module, 'data');
        this.latestResult = result;
        this.latestData = result.data || [];
        this.renderPanel(result, core);
    },

    async bindPage(module, core) {
        const bind = (id, action) => {
            const button = document.getElementById(id);
            if (!button) {
                return;
            }
            button.addEventListener('click', async () => {
                await this.runAction(module, core, action);
            });
        };

        bind('icnf-load-cache', 'data');
        bind('icnf-refresh-remote', 'refresh');
        bind('icnf-load-test', 'test');
        bind('icnf-load-demo', 'demo');
        bind('icnf-clear-cache', 'clear');
    },

    async runAction(module, core, action) {
        const message = document.getElementById('icnf-message');
        if (message) {
            message.textContent = action === 'refresh' ? 'Refreshing remote ICNF XML...' : 'Loading ICNF data...';
        }

        try {
            const result = await this.fetchData(module, action);
            this.latestResult = result;

            if (action !== 'clear') {
                this.latestData = result.data || [];
                this.renderMarkers(core.map, this.latestData, core);
            }

            this.renderPanel(result, core);
        } catch (error) {
            console.error(error);
            if (message) {
                message.textContent = error.message;
            }
        }
    },

    async fetchData(module, action = 'data') {
        const separator = module.api.includes('?') ? '&' : '?';
        const response = await fetch(`${module.api}${separator}action=${encodeURIComponent(action)}`, { cache: 'no-store' });

        if (!response.ok) {
            throw new Error(`ICNF module API returned ${response.status}`);
        }

        const result = await response.json();

        if (!result.success && action !== 'clear') {
            throw new Error(result.error || 'ICNF module API response was not successful.');
        }

        return result;
    },

    renderMarkers(map, occurrences, core) {
        this.markers.forEach((marker) => marker.remove());
        this.markers.clear();

        occurrences.forEach((occurrence) => {
            if (!Number.isFinite(Number(occurrence.latitude)) || !Number.isFinite(Number(occurrence.longitude))) {
                return;
            }

            const markerElement = document.createElement('div');
            markerElement.className = 'icnf-marker';
            markerElement.title = occurrence.name || occurrence.local || 'ICNF occurrence';

            const popup = new maplibregl.Popup({ offset: 25 }).setHTML(`
                <strong>${core.escapeHtml(occurrence.name || 'ICNF occurrence')}</strong><br>
                Código: ${core.escapeHtml(occurrence.codigo || occurrence.id || '')}<br>
                Estado: ${core.escapeHtml(occurrence.status || '')}<br>
                Início: ${core.escapeHtml(occurrence.dh_inicio || occurrence.started_at || '')}<br>
                Área: ${core.escapeHtml(this.formatArea(occurrence))}<br>
                FWI: ${core.escapeHtml(this.formatFwi(occurrence))}<br>
                Distrito: ${core.escapeHtml(occurrence.district || '')}<br>
                Concelho: ${core.escapeHtml(occurrence.municipality || '')}
            `);

            const marker = new maplibregl.Marker({ element: markerElement })
                .setLngLat([Number(occurrence.longitude), Number(occurrence.latitude)])
                .setPopup(popup)
                .addTo(map);

            markerElement.addEventListener('click', () => {
                core.flyTo(occurrence.longitude, occurrence.latitude, 12);
            });

            this.markers.set(String(occurrence.id || occurrence.codigo), marker);
        });
    },

    renderPanel(result, core) {
        const message = document.getElementById('icnf-message');
        const summary = document.getElementById('icnf-summary');
        const list = document.getElementById('icnf-list');
        const output = document.getElementById('icnf-output');
        const occurrences = result.data || [];

        if (message) {
            const sourceName = result.source && result.source.name ? result.source.name : 'unknown source';
            const mode = result.source_mode || 'unknown mode';
            const fallback = result.demo_fallback ? ' · local fallback/test data' : '';
            const warning = result.warning ? ` · ${result.warning}` : '';
            message.textContent = `${occurrences.length || 0} occurrence(s) for ${result.filter_date || 'today'} loaded from ${sourceName} (${mode}${fallback}).${warning}`;
        }

        if (summary) {
            const cache = result.cache || {};
            summary.innerHTML = `
                <div><strong>Source mode:</strong> ${core.escapeHtml(result.source_mode || 'n/a')}</div>
                <div><strong>Date filter:</strong> ${core.escapeHtml(result.filter_date || 'n/a')} (${core.escapeHtml(result.timezone || 'n/a')})</div>
                <div><strong>Total before filter:</strong> ${core.escapeHtml(String(result.total_count ?? 'n/a'))}</div>
                <div><strong>Cache:</strong> ${core.escapeHtml(cache.state || 'n/a')}</div>
                <div><strong>Fetched at:</strong> ${core.escapeHtml(cache.fetched_at || 'n/a')}</div>
                <div><strong>Expires at:</strong> ${core.escapeHtml(cache.expires_at || 'n/a')}</div>
            `;
        }

        if (list) {
            if (occurrences.length === 0) {
                list.innerHTML = '<div class="notice">No ICNF active occurrences found.</div>';
            } else {
                list.innerHTML = occurrences.map((occurrence) => `
                    <button class="icnf-card" type="button" data-lng="${Number(occurrence.longitude)}" data-lat="${Number(occurrence.latitude)}">
                        <strong>${core.escapeHtml(occurrence.name || occurrence.local || 'ICNF occurrence')}</strong>
                        <span class="meta">
                            Código: ${core.escapeHtml(occurrence.codigo || occurrence.id || '')}<br>
                            Tipo: ${core.escapeHtml(occurrence.tipo || '')}<br>
                            Estado: ${core.escapeHtml(occurrence.status || '')}<br>
                            Início: ${core.escapeHtml(occurrence.dh_inicio || occurrence.started_at || '')}<br>
                            Área: ${core.escapeHtml(this.formatArea(occurrence))} · FWI: ${core.escapeHtml(this.formatFwi(occurrence))}<br>
                            Distrito: ${core.escapeHtml(occurrence.district || '')} · Concelho: ${core.escapeHtml(occurrence.municipality || '')}<br>
                            ${occurrence.kml_url ? 'KML/KMZ: available' : ''}
                        </span>
                    </button>
                `).join('');

                list.querySelectorAll('.icnf-card').forEach((card) => {
                    card.addEventListener('click', () => {
                        core.flyTo(card.dataset.lng, card.dataset.lat, 12);
                    });
                });
            }
        }

        if (output) {
            output.textContent = JSON.stringify(result, null, 2);
        }
    },

    formatArea(occurrence) {
        if (!occurrence.area || !Number.isFinite(Number(occurrence.area.total))) {
            return 'n/a';
        }
        return `${Number(occurrence.area.total).toFixed(3)} ha`;
    },

    formatFwi(occurrence) {
        const fwi = occurrence.fire_weather_indices && occurrence.fire_weather_indices.fwi;
        if (!Number.isFinite(Number(fwi))) {
            return 'n/a';
        }
        return Number(fwi).toFixed(2);
    }
};
