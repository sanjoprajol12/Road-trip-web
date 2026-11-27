import { randomUUID } from 'node:crypto';
import express from 'express';
import helmet from 'helmet';
import { pinoHttp } from 'pino-http';
import type { Logger } from 'pino';
import type { Database } from './database/client.js';
import { errorHandler, notFound } from './middleware/errors.js';
import { healthRoutes } from './modules/health/health.routes.js';

export function createApp({ database, logger }: { database: Database; logger: Logger }) {
  const app = express();
  app.disable('x-powered-by');
  // Requests arrive through the frontends' reverse proxy (TD-10).
  app.set('trust proxy', 'loopback');

  app.use(
    pinoHttp({
      logger,
      genReqId: (req, res) => {
        const id = randomUUID();
        res.setHeader('X-Request-Id', id);
        return id;
      },
    }),
  );
  // The API serves JSON only, so the strictest CSP applies (TD-20).
  app.use(
    helmet({
      contentSecurityPolicy: { directives: { defaultSrc: ["'none'"], frameAncestors: ["'none'"] } },
    }),
  );
  app.use(express.json({ limit: '100kb' }));

  // /health for the host's probes; /api/v1/health so the frontends can reach it through their /api proxy.
  const health = healthRoutes(database);
  app.use('/health', health);
  app.use('/api/v1/health', health);

  app.use(notFound);
  app.use(errorHandler);
  return app;
}
