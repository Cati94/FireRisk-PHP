window.FireRiskModules = window.FireRiskModules || {};

window.FireRiskModules.slow_data_fetching = {
    module: null,
    core: null,

    async onOpen(module, core) {
        this.module = module;
        this.core = core;
        this.bindEvents();
        await this.loadStatus();
    },

    bindEvents() {
        const refresh = document.getElementById('slow-fetch-refresh-status');
        const fetchAll = document.getElementById('slow-fetch-all');
        const clear = document.getElementById('slow-fetch-clear');

        if (refresh) {
            refresh.addEventListener('click', () => this.loadStatus());
        }

        if (fetchAll) {
            fetchAll.addEventListener('click', () => this.fetchAll());
        }

        if (clear) {
            clear.addEventListener('click', () => this.clearCache(''));
        }
    },

    async loadStatus() {
        this.setMessage('Reading cache/source status...');

        try {
            const result = await this.api({ action: 'status' });
            this.renderSources(result.sources || []);
            this.showOutput(result);
            this.setMessage(`${(result.sources || []).length} source(s) configured.`);
        } catch (error) {
            this.setMessage(`Failed to read status: ${error.message}`, true);
        }
    },

    async fetchSource(sourceId, force = true) {
        this.setMessage(`Fetching ${sourceId}...`);

        try {
            const result = await this.api({ action: 'fetch', source: sourceId, force: force ? '1' : '0' });
            this.showOutput(result);

            if (result.success) {
                this.setMessage(result.message || `Source ${sourceId} ready.`);
            } else {
                this.setMessage(result.error || `Source ${sourceId} failed.`, true);
            }

            await this.loadStatus();
        } catch (error) {
            this.setMessage(`Fetch failed: ${error.message}`, true);
        }
    },

    async showData(sourceId) {
        this.setMessage(`Reading cache for ${sourceId}...`);

        try {
            const result = await this.api({ action: 'data', source: sourceId });
            this.showOutput(result);

            if (result.success) {
                this.setMessage(`Cached data loaded for ${sourceId}.`);
            } else {
                this.setMessage(result.error || `No cached data for ${sourceId}.`, true);
            }
        } catch (error) {
            this.setMessage(`Failed to read cached data: ${error.message}`, true);
        }
    },

    async fetchAll() {
        this.setMessage('Fetching all enabled sources...');

        try {
            const result = await this.api({ action: 'fetch_all', force: '1' });
            this.showOutput(result);
            this.setMessage(`Fetch all completed for ${result.count || 0} source(s).`);
            await this.loadStatus();
        } catch (error) {
            this.setMessage(`Fetch all failed: ${error.message}`, true);
        }
    },

    async clearCache(sourceId) {
        const label = sourceId ? sourceId : 'all sources';
        this.setMessage(`Clearing cache for ${label}...`);

        try {
            const params = { action: 'clear' };
            if (sourceId) {
                params.source = sourceId;
            }

            const result = await this.api(params);
            this.showOutput(result);
            this.setMessage(`Cache cleared: ${(result.deleted || []).join(', ') || 'nothing to delete'}.`);
            await this.loadStatus();
        } catch (error) {
            this.setMessage(`Clear cache failed: ${error.message}`, true);
        }
    },

    renderSources(sources) {
        const container = document.getElementById('slow-fetch-sources');

        if (!container) {
            return;
        }

        if (!sources.length) {
            container.innerHTML = '<div class="notice">No slow data sources configured.</div>';
            return;
        }

        container.innerHTML = sources.map((source) => {
            const cache = source.cache || {};
            const state = source.enabled ? (cache.state || 'missing') : 'disabled';
            const fetchedAt = cache.fetched_at ? this.escapeHtml(cache.fetched_at) : 'Never';
            const expiresAt = cache.expires_at ? this.escapeHtml(cache.expires_at) : 'No expiry';
            const elapsed = cache.elapsed_ms !== null && cache.elapsed_ms !== undefined ? `${Number(cache.elapsed_ms)} ms` : 'n/a';
            const age = cache.age_seconds !== null && cache.age_seconds !== undefined ? `${Number(cache.age_seconds)}s old` : 'no cache';

            return `
                <article class="slow-source-card ${this.escapeHtml(state)}">
                    <div class="slow-source-header">
                        <div>
                            <h3>${this.escapeHtml(source.name)}</h3>
                            <p>${this.escapeHtml(source.notes || '')}</p>
                        </div>
                        <span class="slow-source-state">${this.escapeHtml(state)}</span>
                    </div>
                    <dl class="slow-source-meta">
                        <div><dt>ID</dt><dd>${this.escapeHtml(source.id)}</dd></div>
                        <div><dt>Type</dt><dd>${this.escapeHtml(source.type)}</dd></div>
                        <div><dt>Timeout</dt><dd>${Number(source.timeout_seconds)}s</dd></div>
                        <div><dt>TTL</dt><dd>${Number(source.ttl_seconds)}s</dd></div>
                        <div><dt>Fetched</dt><dd>${fetchedAt}</dd></div>
                        <div><dt>Expires</dt><dd>${expiresAt}</dd></div>
                        <div><dt>Age</dt><dd>${age}</dd></div>
                        <div><dt>Last fetch</dt><dd>${elapsed}</dd></div>
                    </dl>
                    <div class="slow-source-url">${this.escapeHtml(source.url)}</div>
                    <div class="slow-source-actions">
                        <button class="small-button" type="button" data-slow-action="fetch" data-source="${this.escapeHtml(source.id)}" ${source.enabled ? '' : 'disabled'}>Fetch now</button>
                        <button class="small-button" type="button" data-slow-action="data" data-source="${this.escapeHtml(source.id)}">Show cache</button>
                        <button class="small-button danger" type="button" data-slow-action="clear" data-source="${this.escapeHtml(source.id)}">Clear</button>
                    </div>
                </article>
            `;
        }).join('');

        container.querySelectorAll('[data-slow-action]').forEach((button) => {
            const sourceId = button.getAttribute('data-source');
            const action = button.getAttribute('data-slow-action');

            button.addEventListener('click', () => {
                if (action === 'fetch') {
                    this.fetchSource(sourceId, true);
                } else if (action === 'data') {
                    this.showData(sourceId);
                } else if (action === 'clear') {
                    this.clearCache(sourceId);
                }
            });
        });
    },

    async api(params) {
        const url = new URL(this.module.api, window.location.origin);

        Object.entries(params).forEach(([key, value]) => {
            url.searchParams.set(key, value);
        });

        const response = await fetch(url.toString(), { cache: 'no-store' });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        return response.json();
    },

    showOutput(value) {
        const output = document.getElementById('slow-fetch-output');

        if (output) {
            output.textContent = JSON.stringify(value, null, 2);
        }
    },

    setMessage(message, isError = false) {
        const box = document.getElementById('slow-fetch-message');

        if (box) {
            box.textContent = message;
            box.classList.toggle('error', isError);
        }

        if (this.core) {
            this.core.setStatus(message);
        }
    },

    escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }
};
