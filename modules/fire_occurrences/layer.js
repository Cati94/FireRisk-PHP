window.FireRiskModules = window.FireRiskModules || {};

window.FireRiskModules.fire_occurrences = {
    markers: new Map(),
    latestData: [],
    latestResult: null,

    async loadLayer(map, module, core) {
        const result = await this.fetchResult(module, 'data');
        this.latestResult = result;
        this.latestData = result.data || [];
        this.renderMarkers(map, this.latestData, core);
    },

    async onOpen(module, core) {
        this.bindPage(module, core);
        const result = this.latestResult || await this.fetchResult(module, 'data');
        this.latestResult = result;
        this.latestData = result.data || [];
        this.renderPanel(result, core);
    },

    bindPage(module, core) {
        const bind = (id, action) => {
            const button = document.getElementById(id);
            if (!button) {
                return;
            }

            button.addEventListener('click', async () => {
                await this.runAction(module, core, action);
            });
        };

        bind('fire-load-cache', 'data');
        bind('fire-refresh-icnf', 'refresh');
        bind('fire-load-test', 'test');
    },

    async runAction(module, core, action) {
        const message = document.getElementById('fire-message');
        if (message) {
            message.textContent = action === 'refresh'
                ? 'Refreshing ICNF source for Fire Occurrences...'
                : 'Loading ICNF-derived fire occurrences...';
        }

        try {
            const result = await this.fetchResult(module, action);
            this.latestResult = result;
            this.latestData = result.data || [];
            this.renderMarkers(core.map, this.latestData, core);
            this.renderPanel(result, core);
        } catch (error) {
            console.error(error);
            if (message) {
                message.textContent = error.message;
            }
        }
    },

    async fetchResult(module, action = 'data') {
        const separator = module.api.includes('?') ? '&' : '?';
        const response = await fetch(`${module.api}${separator}action=${encodeURIComponent(action)}`, { cache: 'no-store' });

        if (!response.ok) {
            throw new Error(`Fire occurrence API returned ${response.status}`);
        }

        const result = await response.json();

        if (!result.success) {
            throw new Error(result.error || 'Fire occurrence API response was not successful.');
        }

        return result;
    },

    renderMarkers(map, fires, core) {
        this.markers.forEach((marker) => marker.remove());
        this.markers.clear();

        fires.forEach((fire) => {
            if (!Number.isFinite(Number(fire.latitude)) || !Number.isFinite(Number(fire.longitude))) {
                return;
            }

            const markerElement = document.createElement('div');
            markerElement.className = `fire-marker ${core.escapeHtml(fire.severity)}`;
            markerElement.title = fire.name;

            const popup = new maplibregl.Popup({ offset: 25 }).setHTML(`
                <strong>${core.escapeHtml(fire.name)}</strong><br>
                Código: ${core.escapeHtml(fire.codigo || '')}<br>
                Estado: ${core.escapeHtml(fire.status)}<br>
                Severidade: ${core.escapeHtml(fire.severity)}<br>
                Início: ${core.escapeHtml(fire.started_at)}<br>
                Área: ${core.escapeHtml(this.formatArea(fire))}<br>
                FWI: ${core.escapeHtml(this.formatNumber(fire.fwi))}<br>
                Fonte: ${core.escapeHtml(fire.source_label || fire.source || '')}
            `);

            const marker = new maplibregl.Marker({ element: markerElement })
                .setLngLat([Number(fire.longitude), Number(fire.latitude)])
                .setPopup(popup)
                .addTo(map);

            markerElement.addEventListener('click', () => {
                core.flyTo(fire.longitude, fire.latitude, 12);
            });

            this.markers.set(String(fire.id), marker);
        });
    },

    renderPanel(result, core) {
        const message = document.getElementById('fire-message');
        const summary = document.getElementById('fire-summary');
        const fires = result.data || [];

        if (message) {
            const sourceName = result.source && result.source.name ? result.source.name : 'ICNF Ocorrências Activas';
            const warning = result.warning ? ` · ${result.warning}` : '';
            message.textContent = `${fires.length} fire occurrence(s) for ${result.filter_date || 'today'} loaded from ${sourceName} via ${result.source_mode || 'unknown mode'}.${warning}`;
        }

        if (summary) {
            const cache = result.cache || {};
            summary.innerHTML = `
                <div><strong>Source module:</strong> ${core.escapeHtml(result.source_module || 'icnf_active_occurrences')}</div>
                <div><strong>Source mode:</strong> ${core.escapeHtml(result.source_mode || 'n/a')}</div>
                <div><strong>Date filter:</strong> ${core.escapeHtml(result.filter_date || 'n/a')} (${core.escapeHtml(result.timezone || 'n/a')})</div>
                <div><strong>Total ICNF before filter:</strong> ${core.escapeHtml(String(result.total_count ?? 'n/a'))}</div>
                <div><strong>Cache:</strong> ${core.escapeHtml(cache.state || 'n/a')}</div>
                <div><strong>Fetched at:</strong> ${core.escapeHtml(cache.fetched_at || 'n/a')}</div>
            `;
        }

        this.renderPanelList(fires, core);
    },

    renderPanelList(fires, core) {
        const list = document.getElementById('fire-occurrences-list');

        if (!list) {
            return;
        }

        if (fires.length === 0) {
            list.innerHTML = '<div class="notice">No ICNF-derived fire occurrences found for the selected date.</div>';
            return;
        }

        list.innerHTML = fires.map((fire) => `
            <button class="fire-card ${core.escapeHtml(fire.severity)}" type="button" data-lng="${Number(fire.longitude)}" data-lat="${Number(fire.latitude)}">
                <strong>${core.escapeHtml(fire.name)}</strong>
                <span class="meta">
                    Código: ${core.escapeHtml(fire.codigo || '')}<br>
                    Estado: ${core.escapeHtml(fire.status)} · Severidade: ${core.escapeHtml(fire.severity)}<br>
                    Tipo: ${core.escapeHtml(fire.type || '')}<br>
                    Início: ${core.escapeHtml(fire.started_at)}<br>
                    Área: ${core.escapeHtml(this.formatArea(fire))} · FWI: ${core.escapeHtml(this.formatNumber(fire.fwi))}<br>
                    Distrito: ${core.escapeHtml(fire.district || '')} · Concelho: ${core.escapeHtml(fire.municipality || '')}<br>
                    Fonte: ${core.escapeHtml(fire.source_label || fire.source || '')}
                </span>
            </button>
        `).join('');

        list.querySelectorAll('.fire-card').forEach((card) => {
            card.addEventListener('click', () => {
                core.flyTo(card.dataset.lng, card.dataset.lat, 12);
            });
        });
    },

    formatArea(fire) {
        if (!Number.isFinite(Number(fire.area_total_ha))) {
            return 'n/a';
        }
        return `${Number(fire.area_total_ha).toFixed(3)} ha`;
    },

    formatNumber(value) {
        if (!Number.isFinite(Number(value))) {
            return 'n/a';
        }
        return Number(value).toFixed(2);
    }
};
