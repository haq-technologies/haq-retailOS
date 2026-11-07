import { defineConfig, devices } from '@playwright/test';

/**
 * Browser tests of the counter (spec section 14): Chromium, as the counters run Chrome in kiosk mode, at
 * the large layout of a 1366 by 768 touchscreen. One worker, because the tests share one shop.
 */
export default defineConfig({
    testDir: '.',
    outputDir: './results',
    fullyParallel: false,
    workers: 1,
    retries: 0,
    timeout: 60_000,
    expect: { timeout: 10_000 },
    reporter: [['list'], ['html', { outputFolder: './report', open: 'never' }]],
    globalSetup: './global-setup.ts',
    use: {
        baseURL: process.env.BASE_URL ?? 'http://localhost:8000',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        hasTouch: true,
        // The shop's devices run on Nigerian time, as the server's reports do.
        timezoneId: 'Africa/Lagos',
        locale: 'en-GB',
    },
    projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'], viewport: { width: 1366, height: 768 }, hasTouch: true } }],
});
