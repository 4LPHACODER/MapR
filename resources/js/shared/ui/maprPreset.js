import { definePreset } from '@primeuix/themes';
import Aura from '@primeuix/themes/aura';

const focusRing = { width: '3px', style: 'solid', color: '{primary.600}', offset: '3px', shadow: 'none' };
const overlayShadow = '0 8px 24px rgba(15, 23, 42, 0.12)';

// PrimeVue 4 / Aura 2 token paths. Keep the existing MapR palette and light-only UI.
export default definePreset(Aura, {
    primitive: {
        borderRadius: { none: '0', xs: '4px', sm: '8px', md: '12px', lg: '16px', xl: '20px' },
    },
    semantic: {
        primary: {
            50: '{blue.50}', 100: '{blue.100}', 200: '{blue.200}', 300: '{blue.300}',
            400: '{blue.400}', 500: '{blue.500}', 600: '{blue.600}', 700: '{blue.700}',
            800: '{blue.800}', 900: '{blue.900}', 950: '{blue.950}',
        },
        focusRing,
        formField: {
            paddingX: '0.875rem', paddingY: '0.625rem', borderRadius: '{border.radius.md}',
            transitionDuration: '160ms', focusRing,
        },
        overlay: {
            modal: { borderRadius: '{border.radius.lg}', shadow: overlayShadow },
            popover: { borderRadius: '{border.radius.md}', shadow: overlayShadow },
            select: { borderRadius: '{border.radius.md}', shadow: overlayShadow },
        },
        colorScheme: {
            light: {
                surface: {
                    0: '#ffffff', 50: '{slate.50}', 100: '{slate.100}', 200: '{slate.200}',
                    300: '{slate.300}', 400: '{slate.400}', 500: '{slate.500}', 600: '{slate.600}',
                    700: '{slate.700}', 800: '{slate.800}', 900: '{slate.900}', 950: '{slate.950}',
                },
                primary: { color: '{primary.600}', contrastColor: '#ffffff', hoverColor: '{primary.700}', activeColor: '{primary.800}' },
                highlight: { background: '{primary.50}', focusBackground: '{primary.100}', color: '{primary.900}', focusColor: '{primary.900}' },
                text: { color: '{surface.900}', mutedColor: '{surface.500}' },
                content: { borderColor: '{surface.200}' },
                mask: { background: 'rgba(15, 23, 42, 0.35)' },
                formField: {
                    background: '{surface.0}', disabledBackground: '{surface.100}', borderColor: '{surface.200}',
                    hoverBorderColor: '{surface.300}', focusBorderColor: '{primary.600}', invalidBorderColor: '#b91c1c',
                    color: '{surface.900}', placeholderColor: '{surface.500}', shadow: '0 1px 2px rgba(15, 23, 42, 0.04)',
                },
            },
        },
    },
    components: {
        button: {
            root: {
                borderRadius: '999px', gap: '0.5rem', paddingX: '1.35rem', paddingY: '0.75rem',
                iconOnlyWidth: '3rem', label: { fontWeight: '600' },
            },
            colorScheme: {
                light: {
                    root: {
                        // Keep white destructive labels above 4.5:1 contrast.
                        danger: {
                            background: '{red.700}', hoverBackground: '{red.800}', activeBackground: '{red.900}',
                            borderColor: '{red.700}', hoverBorderColor: '{red.800}', activeBorderColor: '{red.900}',
                        },
                    },
                    outlined: {
                        secondary: { borderColor: '{surface.200}', color: '#123b70', hoverBackground: '{primary.50}', activeBackground: '{primary.100}' },
                    },
                    text: {
                        secondary: { color: '#123b70', hoverBackground: '{primary.50}', activeBackground: '{primary.100}' },
                    },
                },
            },
        },
        dialog: { root: { borderRadius: '{border.radius.lg}' } },
        datepicker: { date: { width: '2.75rem', height: '2.75rem' } },
        tag: {
            root: { borderRadius: '999px', roundedBorderRadius: '999px', fontSize: '0.8125rem', fontWeight: '600' },
            colorScheme: {
                light: {
                    success: { background: '#ecfdf5', color: '#047857' },
                    danger: { background: '#fef2f2', color: '#b91c1c' },
                    warn: { background: '#fffbeb', color: '#b45309' },
                    info: { background: '{primary.50}', color: '{primary.900}' },
                    primary: { background: '{primary.50}', color: '#123b70' },
                    contrast: { background: '#fff7ed', color: '#c2410c' },
                },
            },
        },
        toast: { closeButton: { width: '2.75rem', height: '2.75rem' } },
    },
    css: `
.p-button { min-height: 2.75rem; }
.p-inputtext, .p-select { min-height: 2.75rem; font-size: 1rem; }
`,
});
