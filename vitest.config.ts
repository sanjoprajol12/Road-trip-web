import { existsSync } from 'node:fs';
import { defineConfig } from 'vitest/config';

// Local runs read .env.test; CI provides the same variables directly.
if (existsSync('.env.test')) process.loadEnvFile('.env.test');

export default defineConfig({
  test: {
    include: ['src/**/*.test.ts', 'tests/**/*.test.ts'],
    environment: 'node',
  },
});
