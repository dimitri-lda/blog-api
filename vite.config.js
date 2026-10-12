import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    const https = env.VITE_HTTPS === 'true';
    const host = env.VITE_HOST ?? 'blog-api.local';
    const port = Number(env.VITE_PORT ?? 5173);

    return {
        plugins: [
            laravel({
                input: 'resources/js/app.jsx',
                refresh: true,
            }),
            react(),
        ],
        server: {
            host: '0.0.0.0',
            port,
            strictPort: true,
            https: https ? {
                key: env.VITE_HTTPS_KEY ?? './docker/certs/blog-api.local-key.pem',
                cert: env.VITE_HTTPS_CERT ?? './docker/certs/blog-api.local.pem',
            } : undefined,
            allowedHosts: ['blog-api.local', 'admin.blog-api.local'],
            cors: {
                origin: [`${https ? 'https' : 'http'}://blog-api.local`, `${https ? 'https' : 'http'}://admin.blog-api.local`],
            },
            hmr: {
                host,
                port,
                protocol: https ? 'wss' : 'ws',
            },
        },
    };
});
