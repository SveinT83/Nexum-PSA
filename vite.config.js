import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    server: {
        host: '0.0.0.0',
        port: 5173,
        allowedHosts: ['nexum-psa.local'],
        hmr: {
            host: 'nexum-psa.local', // bruk domenet du åpner siden med
            protocol: 'ws',
            port: 5173,              // eller clientPort hvis du tunneler til en annen port lokalt
        },
    },

    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
});
