window.FireRiskModules = window.FireRiskModules || {};

const FireRiskCore = {
    modules: [],
    loadedAssets: new Set(),
    activeModuleId: null,

    map: new maplibregl.Map({
        container: 'map',
        style: {
            version: 8,
            sources: {
                osm: {
                    type: 'raster',
                    tiles: ['https://tile.openstreetmap.org/{z}/{x}/{y}.png'],
                    tileSize: 256,
                    attribution: '© OpenStreetMap contributors'
                }
            },
            layers: [
                {
                    id: 'osm',
                    type: 'raster',
                    source: 'osm'
                }
            ]
        },
        center: window.FIRERISK_CONFIG.center,
        zoom: window.FIRERISK_CONFIG.zoom
    }),

    async init() {
        this.map.addControl(new maplibregl.NavigationControl(), 'top-right');
        document.getElementById('reload-modules').addEventListener('click', () => this.loadModules());
        await this.loadModules();
    },

    async loadModules() {
        this.setStatus('Loading enabled modules...');

        try {
            const response = await fetch('/api/modules.php', { cache: 'no-store' });

            if (!response.ok) {
                throw new Error(`Module registry returned ${response.status}`);
            }

            const result = await response.json();

            if (!result.success) {
                throw new Error('Module registry response was not successful.');
            }

            this.modules = result.modules || [];
            this.renderModuleMenu(this.modules);
            await this.loadModuleAssets(this.modules);
            this.loadMapLayers(this.modules);
            this.openFirstPageModule(this.modules);
            this.setStatus(`${this.modules.length} module(s) active`);

            if (Array.isArray(result.errors) && result.errors.length > 0) {
                console.warn('Module warnings:', result.errors);
            }
        } catch (error) {
            console.error(error);
            document.getElementById('module-menu').innerHTML = '<div class="error">Failed to load modules.</div>';
            this.setStatus('Module loading failed');
        }
    },

    renderModuleMenu(modules) {
        const menu = document.getElementById('module-menu');
        menu.innerHTML = '';

        if (modules.length === 0) {
            menu.innerHTML = '<div class="notice">No enabled modules found.</div>';
            return;
        }

        modules.forEach((module) => {
            const button = document.createElement('button');
            button.className = `module-button ${module.load_mode === 'heavy_page' ? 'heavy-module' : ''}`;
            button.type = 'button';
            button.dataset.moduleId = module.id;
            const modeLabel = module.load_mode === 'heavy_page' ? 'full page' : module.type;
            button.innerHTML = `
                <span class="module-button-title">
                    <span>${this.escapeHtml(module.name)}</span>
                    <span class="module-type">${this.escapeHtml(modeLabel)}</span>
                </span>
                <span class="module-button-meta">
                    Relevance ${Number(module.relevance)} · ${this.escapeHtml(module.description)}
                </span>
            `;

            button.addEventListener('click', () => this.openModule(module));
            menu.appendChild(button);
        });
    },

    async loadModuleAssets(modules) {
        for (const module of modules) {
            if (module.load_mode === 'heavy_page') {
                continue;
            }
            const assets = module.assets || {};
            const cssFiles = assets.css || [];
            const jsFiles = assets.js || [];

            cssFiles.forEach((href) => this.loadCss(href));

            for (const src of jsFiles) {
                await this.loadScript(src);
            }
        }
    },

    loadCss(href) {
        if (this.loadedAssets.has(href)) {
            return;
        }

        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = href;
        document.head.appendChild(link);
        this.loadedAssets.add(href);
    },

    loadScript(src) {
        if (this.loadedAssets.has(src)) {
            return Promise.resolve();
        }

        return new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = src;
            script.onload = () => {
                this.loadedAssets.add(src);
                resolve();
            };
            script.onerror = () => reject(new Error(`Failed to load script: ${src}`));
            document.body.appendChild(script);
        });
    },

    loadMapLayers(modules) {
        modules.forEach((module) => {
            if (module.load_mode === 'heavy_page') {
                return;
            }
            if (!module.layer) {
                return;
            }

            const implementation = window.FireRiskModules[module.id];

            if (!implementation || typeof implementation.loadLayer !== 'function') {
                console.warn(`Layer module has no JS loadLayer implementation: ${module.id}`);
                return;
            }

            try {
                implementation.loadLayer(this.map, module, this);
            } catch (error) {
                console.error(`Module layer failed: ${module.id}`, error);
            }
        });
    },

    openFirstPageModule(modules) {
        const first = modules.find((module) => module.page && module.load_mode !== 'heavy_page');

        if (first) {
            this.openModule(first);
        }
    },

    async openModule(module) {
        if (module.load_mode === 'heavy_page') {
            const url = module.navigation || `/module.php?module=${encodeURIComponent(module.id)}`;
            window.location.href = url;
            return;
        }

        if (!module.page) {
            this.setStatus(`${module.name} has no page GUI`);
            return;
        }

        this.activeModuleId = module.id;
        this.markActiveModule(module.id);
        this.setStatus(`Opening ${module.name}...`);

        try {
            const response = await fetch(module.page, { cache: 'no-store' });

            if (!response.ok) {
                throw new Error(`Page returned ${response.status}`);
            }

            document.getElementById('module-panel').innerHTML = await response.text();

            const implementation = window.FireRiskModules[module.id];
            if (implementation && typeof implementation.onOpen === 'function') {
                await implementation.onOpen(module, this);
            }

            this.setStatus(`${module.name} ready`);
        } catch (error) {
            console.error(error);
            document.getElementById('module-panel').innerHTML = '<div class="error">Failed to open module GUI.</div>';
            this.setStatus(`Failed to open ${module.name}`);
        }
    },

    markActiveModule(moduleId) {
        document.querySelectorAll('.module-button').forEach((button) => {
            button.classList.toggle('active', button.dataset.moduleId === moduleId);
        });
    },

    flyTo(lng, lat, zoom = 11) {
        this.map.flyTo({ center: [Number(lng), Number(lat)], zoom });
    },

    setStatus(message) {
        document.getElementById('status-bar').textContent = message;
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

FireRiskCore.init();
