import { defineConfig, devices } from '@playwright/test';
import { mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';

const database = process.env.SMART_MARKET_BROWSER_DB ??= join(mkdtempSync(join(tmpdir(), 'smart-market-browser-')), 'testing.sqlite');
const browserPort = Number.parseInt(process.env.SMART_MARKET_BROWSER_PORT ?? '8138', 10);
if (!Number.isInteger(browserPort) || browserPort < 1 || browserPort > 65535) {
    throw new Error('SMART_MARKET_BROWSER_PORT must be a valid TCP port.');
}

export const browserEnvironment = {
    APP_ENV: 'testing', APP_DEBUG: 'false', APP_URL: `http://127.0.0.1:${browserPort}`,
    APP_KEY: `base64:${Buffer.alloc(32, 7).toString('base64')}`,
    DB_CONNECTION: 'sqlite', DB_DATABASE: database, DB_URL: '',
    SESSION_DRIVER: 'database', SESSION_SECURE_COOKIE: 'false', SESSION_ENCRYPT: 'true',
    APP_CONFIG_CACHE: join(dirname(database), 'config.php'),
    APP_ROUTES_CACHE: join(dirname(database), 'routes.php'),
    APP_EVENTS_CACHE: join(dirname(database), 'events.php'),
    CATALOG_STORAGE_PATH: join(dirname(database), 'catalog'),
    CACHE_STORE: 'array', MAIL_MAILER: 'array', QUEUE_CONNECTION: 'sync',
};
export default defineConfig({
    testDir: './tests/Browser', testMatch: '**/*.spec.ts', fullyParallel: false, workers: 1,
    globalSetup: './tests/Browser/setup.ts', globalTeardown: './tests/Browser/teardown.ts',
    use: { baseURL: browserEnvironment.APP_URL, trace: 'retain-on-failure', screenshot: 'only-on-failure' },
    projects: [
        { name: 'desktop-chromium', use: { ...devices['Desktop Chrome'] } },
        { name: 'mobile-chromium', use: { ...devices['Pixel 7'] } },
    ],
    webServer: { command: `php artisan serve --host=127.0.0.1 --port=${browserPort} --no-reload`, url: `${browserEnvironment.APP_URL}/up`, reuseExistingServer: false, env: browserEnvironment },
});
