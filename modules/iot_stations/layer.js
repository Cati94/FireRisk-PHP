window.FireRiskModules = window.FireRiskModules || {};
window.FireRiskModules.iot_stations = {
    async loadLayer(map, module, core) {
        console.info('IoT stations placeholder layer loaded.', module, map, core);
    },
    async onOpen(module, core) {
        core.setStatus('IoT stations placeholder ready');
    }
};
