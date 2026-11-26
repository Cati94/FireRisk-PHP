window.FireRiskModules = window.FireRiskModules || {};

(function () {
    const module = window.FIRERISK_STANDALONE_MODULE;
    const status = document.getElementById('status-bar');

    function setStatus(message) {
        if (status) {
            status.textContent = message;
        }
    }

    if (!module || !module.id) {
        setStatus('Module metadata missing.');
        return;
    }

    const mapElement = document.getElementById('map');
    let map = null;
    let mapResizeObserver = null;

    function showMapNotice(message, tone = 'warning') {
        if (!mapElement) {
            return;
        }

        let notice = document.getElementById('map-runtime-notice');
        if (!notice) {
            notice = document.createElement('div');
            notice.id = 'map-runtime-notice';
            notice.setAttribute('role', 'status');
            mapElement.appendChild(notice);
        }

        notice.className = 'map-runtime-notice map-runtime-notice-' + tone;
        notice.textContent = message;
    }

    if (mapElement) {
        if (!window.maplibregl) {
            showMapNotice('Map engine unavailable. Reload the page or check the local MapLibre files.', 'error');
            setStatus('Map engine unavailable.');
        } else {
            try {
                map = new maplibregl.Map({
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
                                id: 'firerisk-map-background',
                                type: 'background',
                                paint: { 'background-color': '#dbe4e8' }
                            },
                            { id: 'osm', type: 'raster', source: 'osm' }
                        ]
                    },
                    center: window.FIRERISK_CONFIG.center,
                    zoom: window.FIRERISK_CONFIG.zoom
                });

                map.addControl(new maplibregl.NavigationControl(), 'top-right');
                mapElement.dataset.mapEngine = 'maplibre-' + (window.FIRERISK_CONFIG.mapLibreVersion || 'local');

                map.on('error', (event) => {
                    const sourceId = String(event && (event.sourceId || (event.source && event.source.id)) || '');
                    const message = String(event && event.error && event.error.message || '');
                    if (sourceId === 'osm' || /failed to fetch|networkerror|tile.+(?:load|fetch)/i.test(message)) {
                        showMapNotice('Basemap tiles unavailable. FireRisk overlays remain active.');
                        setStatus('Basemap tiles unavailable; operational overlays remain active.');
                        return;
                    }

                    if (/webgl|context/i.test(message)) {
                        showMapNotice('Map rendering is unavailable in this browser.', 'error');
                        setStatus('Map rendering unavailable.');
                    }
                    console.error('MapLibre error', event && (event.error || event));
                });

                map.on('style.load', () => map.resize());

                if (typeof ResizeObserver === 'function') {
                    mapResizeObserver = new ResizeObserver(() => map && map.resize());
                    mapResizeObserver.observe(mapElement);
                    map.on('remove', () => mapResizeObserver && mapResizeObserver.disconnect());
                } else {
                    window.addEventListener('resize', () => map && map.resize());
                }
            } catch (error) {
                map = null;
                showMapNotice('Map initialization failed: ' + error.message, 'error');
                setStatus('Map initialization failed.');
                console.error(error);
            }
        }
    }

    const core = {
        map,
        activeModuleId: module.id,
        flyTo(lng, lat, zoom = 11) {
            if (map) {
                map.flyTo({ center: [Number(lng), Number(lat)], zoom });
            }
        },
        setStatus,
        escapeHtml(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }
    };

    window.FireRiskCore = core;

    async function bootModule() {
        const implementation = window.FireRiskModules[module.id];
        if (!implementation) {
            setStatus(`${module.name} has no JavaScript implementation.`);
            return;
        }

        try {
            // Multi-page rule: each module page owns its own map and assets.
            // Layer modules load their own layer here, not through the home page.
            if (module.layer && map && typeof implementation.loadLayer === 'function') {
                await implementation.loadLayer(map, module, core);
            }

            if (typeof implementation.onOpen === 'function') {
                await implementation.onOpen(module, core);
            }

            setStatus(`${module.name} ready`);
        } catch (error) {
            console.error(error);
            setStatus(`${module.name} failed: ${error.message}`);
        }
    }

    if (map) {
        if (map.isStyleLoaded()) {
            bootModule();
        } else {
            map.once('style.load', bootModule);
        }
    } else {
        bootModule();
    }
}());
