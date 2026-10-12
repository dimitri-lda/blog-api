import { fileURLToPath } from 'node:url';
import { defineConfig, loadEnv } from 'vite';
import react from '@vitejs/plugin-react';

const srcPath = fileURLToPath(new URL('./src', import.meta.url));

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    const apiOrigin = env.VITE_API_ORIGIN ?? 'http://localhost:8080';

    return {
        plugins: [react()],
        resolve: {
            alias: {
                '@': srcPath,
            },
        },
        server: {
            host: '0.0.0.0',
            port: 5173,
            allowedHosts: ['blog-api.local', 'admin.blog-api.local'],
            proxy: {
                '/api': {
                    target: apiOrigin,
                    changeOrigin: true,
                },
                '/healthz': {
                    target: apiOrigin,
                    changeOrigin: true,
                },
            },
        },
    };
});
