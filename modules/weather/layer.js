window.FireRiskModules = window.FireRiskModules || {};

window.FireRiskModules.weather = {
    weatherMarker: null,

    async loadLayer(map, module, core) {
        const weather = await this.fetchWeather(module);
        this.renderWeatherMarker(map, weather, core);
    },

    async onOpen(module, core) {
        const weather = await this.fetchWeather(module);
        this.renderWeatherSummary(weather, core);
    },

    async fetchWeather(module) {
        const response = await fetch(module.api, { cache: 'no-store' });

        if (!response.ok) {
            throw new Error(`Weather API returned ${response.status}`);
        }

        const result = await response.json();

        if (!result.success) {
            throw new Error('Weather API response was not successful.');
        }

        return result.data || {};
    },

    renderWeatherMarker(map, weather, core) {
        if (this.weatherMarker) {
            this.weatherMarker.remove();
        }

        const element = document.createElement('div');
        element.className = 'weather-marker';
        element.innerHTML = '↗';
        element.style.transform = `rotate(${Number(weather.wind_direction_deg || 0)}deg)`;
        element.title = `Wind ${weather.wind_direction_label || ''} ${weather.wind_speed_kmh || '?'} km/h`;

        this.weatherMarker = new maplibregl.Marker({ element })
            .setLngLat(window.FIRERISK_CONFIG.center)
            .setPopup(new maplibregl.Popup({ offset: 20 }).setHTML(`
                <strong>Weather placeholder</strong><br>
                Wind: ${core.escapeHtml(weather.wind_direction_label)} ${Number(weather.wind_speed_kmh || 0)} km/h<br>
                Temp: ${Number(weather.temperature_c || 0)} °C<br>
                RH: ${Number(weather.relative_humidity_percent || 0)}%
            `))
            .addTo(map);
    },

    renderWeatherSummary(weather, core) {
        const container = document.getElementById('weather-summary');

        if (!container) {
            return;
        }

        container.innerHTML = `
            <div class="weather-card">
                <strong>${core.escapeHtml(weather.station || 'Weather station')}</strong>
                <span class="meta">
                    Observed: ${core.escapeHtml(weather.observed_at || 'n/a')}<br>
                    Temperature: ${Number(weather.temperature_c || 0)} °C<br>
                    Relative humidity: ${Number(weather.relative_humidity_percent || 0)}%<br>
                    Wind: ${core.escapeHtml(weather.wind_direction_label || 'n/a')} · ${Number(weather.wind_speed_kmh || 0)} km/h
                </span>
            </div>
            <div class="notice">${core.escapeHtml(weather.risk_note || 'No operational weather note available.')}</div>
        `;
    }
};
