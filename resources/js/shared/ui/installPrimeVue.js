import PrimeVue from 'primevue/config';
import ConfirmationService from 'primevue/confirmationservice';
import ToastService from 'primevue/toastservice';
import Tooltip from 'primevue/tooltip';
import MaprPreset from './maprPreset';

export function installPrimeVue(app) {
    app.use(PrimeVue, {
        ripple: false,
        theme: {
            preset: MaprPreset,
            options: {
                // MapR is light only. 'none' also stops PrimeVue from adding a
                // prefers-color-scheme rule that would follow the phone's dark mode.
                darkModeSelector: 'none',
                cssLayer: { name: 'primevue', order: 'theme, base, primevue, components, utilities' },
            },
        },
    });
    app.use(ToastService);
    app.use(ConfirmationService);
    app.directive('tooltip', Tooltip);

    return app;
}
