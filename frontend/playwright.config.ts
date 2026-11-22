import { defineConfig, devices } from '@playwright/test'

// Public website E2E against the local stack. Needs the local Postgres + Mailpit (`pnpm db:start` in ../) and the
// isolated database myridenepal_e2e (`pnpm db:setup`, then migrate it: see docs/architecture/DEVELOPMENT_SETUP.md).
// Starts its own Laravel API from ../ on E2E_API_PORT (default 8011, so a developer's `composer dev` on :8010 is never
// reused: it would use the development database) and this app's preview on :4180.
// @playwright/test is pinned to 1.63.0 to match the browser builds cached on the dev machine.
const API_PORT = process.env.E2E_API_PORT ?? '8011'
const API = `http://127.0.0.1:${API_PORT}`
const PUBLIC = 'http://127.0.0.1:4180'

export default defineConfig({
  testDir: 'e2e',
  globalSetup: './e2e/global-setup.ts',
  workers: 1,
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? 'github' : 'list',
  use: { baseURL: PUBLIC, trace: 'retain-on-failure' },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    { name: 'firefox', use: { ...devices['Desktop Firefox'] } },
    { name: 'webkit', use: { ...devices['Desktop Safari'] } },
    { name: 'mobile', use: { ...devices['Pixel 7'] } },
  ],
  webServer: [
    {
      command: `php artisan serve --host=127.0.0.1 --port=${API_PORT}`,
      cwd: '..',
      env: {
        DB_DATABASE: 'myridenepal_e2e',
        APP_URL: PUBLIC,
        ADMIN_URL: 'http://127.0.0.1:4181',
        API_PER_MINUTE: '5000', // every test browser shares 127.0.0.1
        CONTACT_PER_HOUR: '1000',
        LOCAL_DISK_ROOT: 'app/e2e', // uploads from browser tests never mix with development files
        SANCTUM_STATEFUL_DOMAINS: '127.0.0.1:4180,localhost:4180,127.0.0.1:4181,localhost:4181',
      },
      url: `${API}/api/v1/health`,
      reuseExistingServer: !process.env.CI,
      timeout: 60_000,
    },
    {
      command: 'pnpm build-only && pnpm preview --host 127.0.0.1',
      env: { API_TARGET: API },
      url: PUBLIC,
      reuseExistingServer: !process.env.CI,
      timeout: 180_000,
    },
  ],
})
