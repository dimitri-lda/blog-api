import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';

export default defineConfig({
    plugins: [
        laravel({
            input: 'resources/js/app.jsx',
            refresh: true,
        }),
        react(),
    ],
    server: {
        host: '0.0.0.0',
        allowedHosts: ['blog-api.local', 'admin.blog-api.local'],
        cors: {
            origin: ['http://blog-api.local', 'http://admin.blog-api.local'],
        },
        hmr: {
            host: 'blog-api.local',
        },
    },
});
