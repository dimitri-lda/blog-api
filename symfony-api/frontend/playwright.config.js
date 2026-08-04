var _a;
import { defineConfig } from '@playwright/test';
export default defineConfig({ testDir: './e2e', use: { baseURL: (_a = process.env.E2E_BASE_URL) !== null && _a !== void 0 ? _a : 'http://localhost:8080', trace: 'retain-on-failure' }, webServer: process.env.CI ? undefined : { command: 'npm run dev -- --host 127.0.0.1', url: 'http://127.0.0.1:5174', reuseExistingServer: true } });
