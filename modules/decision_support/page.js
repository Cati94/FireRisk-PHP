window.FireRiskModules = window.FireRiskModules || {};

window.FireRiskModules.decision_support = {
    async onOpen(module, core) {
        this.module = module;
        this.core = core;
        this.bindButtons(module, core);
        await this.loadAnalysis(module, core, 'analysis');
    },

    bindButtons(module, core) {
        const refresh = document.getElementById('decision-refresh');
        const test = document.getElementById('decision-load-test');
        const method = document.getElementById('decision-show-method');

        if (refresh) {
            refresh.onclick = () => this.loadAnalysis(module, core, 'analysis');
        }

        if (test) {
            test.onclick = () => this.loadAnalysis(module, core, 'analysis&source=test&date=2026-02-24');
        }

        if (method) {
            method.onclick = () => this.loadMethod(module, core);
        }
    },

    async loadAnalysis(module, core, action) {
        const message = document.getElementById('decision-message');
        if (message) {
            message.className = 'decision-message loading';
            message.textContent = 'Loading operational triage analysis...';
        }

        try {
            const separator = module.api.includes('?') ? '&' : '?';
            const response = await fetch(`${module.api}${separator}action=${action}`, { cache: 'no-store' });

            if (!response.ok) {
                throw new Error(`Decision support API returned ${response.status}`);
            }

            const result = await response.json();

            if (!result.success) {
                throw new Error(result.error || 'Decision support API response was not successful.');
            }

            this.renderAnalysis(result, core);
            this.writeOutput(result);
        } catch (error) {
            this.renderError(error);
        }
    },

    async loadMethod(module, core) {
        try {
            const separator = module.api.includes('?') ? '&' : '?';
            const response = await fetch(`${module.api}${separator}action=method`, { cache: 'no-store' });
            const result = await response.json();
            this.writeOutput(result);

            const message = document.getElementById('decision-message');
            if (message) {
                message.className = 'decision-message';
                message.textContent = 'Scoring method loaded in the details panel.';
            }
        } catch (error) {
            this.renderError(error);
        }
    },

    renderAnalysis(result, core) {
        const message = document.getElementById('decision-message');
        if (message) {
            const warning = result.source_warning ? ` ${result.source_warning}` : '';
            message.className = 'decision-message';
            message.textContent = `Analysed ${Number(result.count || 0)} occurrence(s) for ${core.escapeHtml(result.filter_date || '')}.${warning}`;
        }

        this.renderOverview(result.overview || {}, core);
        this.renderRecommendations(result.recommendations || [], core);
        this.renderPriorities(result.priorities || [], core);
    },

    renderOverview(overview, core) {
        const container = document.getElementById('decision-overview');
        if (!container) {
            return;
        }

        const levels = overview.risk_levels || {};
        const cards = [
            ['Records today', overview.records_for_date ?? 0],
            ['Critical', levels.critical ?? 0],
            ['High', levels.high ?? 0],
            ['Medium', levels.medium ?? 0],
            ['Area ha', this.formatNumber(overview.total_reported_area_ha)],
            ['Max FWI', this.formatNumber(overview.max_fwi)],
            ['Max wind', this.formatNumber(overview.max_wind_intensity)],
            ['Max slope', this.formatNumber(overview.max_slope)]
        ];

        container.innerHTML = cards.map(([label, value]) => `
            <article class="decision-stat">
                <span>${core.escapeHtml(label)}</span>
                <strong>${core.escapeHtml(String(value ?? '—'))}</strong>
            </article>
        `).join('');
    },

    renderRecommendations(recommendations, core) {
        const container = document.getElementById('decision-recommendations');
        if (!container) {
            return;
        }

        if (recommendations.length === 0) {
            container.innerHTML = '';
            return;
        }

        container.innerHTML = `
            <h3>Operational prompts</h3>
            ${recommendations.map((item) => `
                <article class="decision-card ${core.escapeHtml(item.level || 'medium')}">
                    <strong>${core.escapeHtml(item.title || 'Recommendation')}</strong>
                    <p>${core.escapeHtml(item.text || '')}</p>
                </article>
            `).join('')}
        `;
    },

    renderPriorities(priorities, core) {
        const container = document.getElementById('decision-priorities');
        if (!container) {
            return;
        }

        if (priorities.length === 0) {
            container.innerHTML = '<div class="notice">No current-day occurrence requires ranking. The today-only filter is active.</div>';
            return;
        }

        container.innerHTML = `
            <h3>Ranked occurrences</h3>
            ${priorities.map((item, index) => this.renderPriority(item, index, core)).join('')}
        `;
    },

    renderPriority(item, index, core) {
        const metrics = item.metrics || {};
        const context = item.context || {};
        const reasons = Array.isArray(item.reasons) ? item.reasons : [];
        const actions = Array.isArray(item.suggested_actions) ? item.suggested_actions : [];

        return `
            <article class="decision-priority ${core.escapeHtml(item.risk_level || 'low')}">
                <header>
                    <div>
                        <strong>#${index + 1} ${core.escapeHtml(item.name || item.codigo || 'Occurrence')}</strong>
                        <small>${core.escapeHtml([item.municipality, item.district].filter(Boolean).join(', '))}</small>
                    </div>
                    <span class="risk-badge ${core.escapeHtml(item.risk_level || 'low')}">${Number(item.risk_score || 0)} / ${core.escapeHtml(item.risk_level || 'low')}</span>
                </header>

                <div class="decision-metrics">
                    <span>Area: ${core.escapeHtml(this.formatNumber(metrics.area_total_ha))} ha</span>
                    <span>FWI: ${core.escapeHtml(this.formatNumber(metrics.fwi))}</span>
                    <span>ISI: ${core.escapeHtml(this.formatNumber(metrics.isi))}</span>
                    <span>Wind: ${core.escapeHtml(this.formatNumber(metrics.wind_intensity))}</span>
                    <span>RH: ${core.escapeHtml(this.formatNumber(metrics.relative_humidity))}</span>
                    <span>Slope: ${core.escapeHtml(this.formatNumber(metrics.slope))}</span>
                </div>

                <details>
                    <summary>Why / suggested action</summary>
                    <div class="decision-detail-columns">
                        <div>
                            <h4>Reasons</h4>
                            <ul>${reasons.map((reason) => `<li>${core.escapeHtml(reason)}</li>`).join('')}</ul>
                        </div>
                        <div>
                            <h4>Actions</h4>
                            <ul>${actions.map((action) => `<li>${core.escapeHtml(action)}</li>`).join('')}</ul>
                        </div>
                    </div>
                    <p class="decision-context">
                        ${context.aps ? `<span>APS: ${core.escapeHtml(context.aps)}</span>` : ''}
                        ${context.forest_perimeter ? `<span>Perimeter: ${core.escapeHtml(context.forest_perimeter)}</span>` : ''}
                        ${context.cause_family ? `<span>Cause family: ${core.escapeHtml(context.cause_family)}</span>` : ''}
                    </p>
                </details>
            </article>
        `;
    },

    renderError(error) {
        const message = document.getElementById('decision-message');
        if (message) {
            message.className = 'decision-message error';
            message.textContent = `Decision support failed: ${error.message}`;
        }
        this.writeOutput({ success: false, error: error.message });
    },

    writeOutput(value) {
        const output = document.getElementById('decision-output');
        if (output) {
            output.textContent = JSON.stringify(value, null, 2);
        }
    },

    formatNumber(value) {
        if (value === null || value === undefined || value === '') {
            return '—';
        }
        const number = Number(value);
        if (Number.isNaN(number)) {
            return String(value);
        }
        return Math.round(number * 100) / 100;
    }
};
