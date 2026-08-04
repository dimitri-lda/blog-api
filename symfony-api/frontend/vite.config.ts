import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
  plugins: [react(), tailwindcss()],
  build: { outDir: '../public/app', emptyOutDir: true, sourcemap: false },
  server: { host: '0.0.0.0', port: 5174, strictPort: true, allowedHosts: ['localhost','host.docker.internal'], proxy: { '/api': { target: 'http://caddy', changeOrigin: true } } },
  test: { environment: 'jsdom', globals: true, setupFiles: './src/test/setup.ts', exclude: ['e2e/**','node_modules/**'] },
});
