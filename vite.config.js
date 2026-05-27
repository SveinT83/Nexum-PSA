import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

const devServerHost = process.env.VITE_DEV_SERVER_HOST || '0.0.0.0';
const devServerPort = Number(process.env.VITE_DEV_SERVER_PORT || 5173);
const devServerPublicHost = process.env.VITE_DEV_SERVER_PUBLIC_HOST || 'localhost';
const devServerHmrProtocol = process.env.VITE_DEV_SERVER_HMR_PROTOCOL || 'ws';

export default defineConfig({
    server: {
        host: devServerHost,
        port: devServerPort,
        allowedHosts: [devServerPublicHost],
        hmr: {
            host: devServerPublicHost,
            protocol: devServerHmrProtocol,
            port: devServerPort,
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
