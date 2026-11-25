import { defineConfig, devices } from '@playwright/test';

/**
 * Run inside DDEV via the ochorocho/ddev-playwright add-on:
 *   ddev playwright test
 *
 * Every request carries `X-Test-Run: 1`; Build/config/system/additional.php
 * routes those requests to the isolated `test` database. globalSetup reseeds
 * `test` before the run.
 */
export default defineConfig({
  testDir: './Tests/playwright/specs',
  globalSetup: './Tests/playwright/scripts/global-setup.mjs',
  fullyParallel: false,
  workers: 1,
  timeout: 60_000,
  retries: 1,
  // `ddev playwright` forwards no host environment, so reporters are unconditional.
  reporter: [
    ['list'],
    ['html', { open: 'never' }],
    ['junit', { outputFile: 'test-results/junit.xml' }],
  ],
  use: {
    baseURL: process.env.PLAYWRIGHT_BASE_URL ?? 'http://web',
    extraHTTPHeaders: {
      'X-Test-Run': '1',
    },
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
});
