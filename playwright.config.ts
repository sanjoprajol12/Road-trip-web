import { defineConfig, devices } from '@playwright/test'

// Production shape: Laravel serves the built SPA and the API from one origin (:8010).
// Needs the local Postgres + mail catcher: `pnpm db:start`. Emails link to APP_URL, set to this origin.
// @playwright/test is pinned to 1.63.0 to match the browser builds cached on the dev machine.
export default defineConfig({
  testDir: 'e2e',
  globalSetup: './e2e/global-setup.ts',
  workers: 1,
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? 'github' : 'list',
  use: { baseURL: 'http://127.0.0.1:8010', trace: 'retain-on-failure' },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    { name: 'firefox', use: { ...devices['Desktop Firefox'] } },
    { name: 'webkit', use: { ...devices['Desktop Safari'] } },
    { name: 'mobile', use: { ...devices['Pixel 7'] } },
  ],
  webServer: {
    command: 'pnpm build-only && APP_URL=http://127.0.0.1:8010 php artisan serve --host=127.0.0.1 --port=8010',
    url: 'http://127.0.0.1:8010/api/v1/health',
    reuseExistingServer: !process.env.CI,
    timeout: 180_000,
  },
})
