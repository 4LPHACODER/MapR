import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    server: {
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        hmr: { host: process.env.VITE_DEV_SERVER_HOST || 'localhost' },
    },
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/login.js',
                'resources/js/cdrrmo.js',
                'resources/js/rdana.js',
                'resources/js/bdrrm.js',
                'resources/js/resident.js',
            ],
            refresh: true,
        }),
        vue(),
        tailwindcss(),
    ],
});
