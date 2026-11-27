import { pino } from 'pino';
import { createApp } from '../src/app.js';
import { createDatabase } from '../src/database/client.js';

const silent = pino({ level: 'silent' });

export function testDatabaseUrl(): string {
  const url = process.env.DATABASE_URL;
  if (!url) throw new Error('DATABASE_URL is not set: copy .env.test.example to .env.test');
  if (!/\/myridenepal_test(\?|$)/.test(url))
    throw new Error('Tests only run against the myridenepal_test database');
  return url;
}

export function buildTestApp(databaseUrl = testDatabaseUrl()) {
  const database = createDatabase(databaseUrl);
  return { app: createApp({ database, logger: silent }), database };
}
