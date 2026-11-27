import { createApp } from './app.js';
import { loadEnv } from './config/env.js';
import { createDatabase } from './database/client.js';
import { createLogger } from './logger.js';

const env = loadEnv();
const logger = createLogger(env.LOG_LEVEL);
const database = createDatabase(env.DATABASE_URL);
const app = createApp({ database, logger });

const server = app.listen(env.PORT, () => logger.info({ port: env.PORT }, 'API listening'));

// Finish in-flight requests, then close the pool, so deploys never cut a request mid-transaction.
function shutdown(signal: string) {
  logger.info({ signal }, 'shutting down');
  server.close(() => {
    database.pool.end().finally(() => process.exit(0));
  });
  setTimeout(() => process.exit(1), 10_000).unref();
}
process.on('SIGTERM', () => shutdown('SIGTERM'));
process.on('SIGINT', () => shutdown('SIGINT'));
