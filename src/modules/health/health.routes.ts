import { sql } from 'drizzle-orm';
import { Router } from 'express';
import type { Database } from '../../database/client.js';

// Route class: system (no data, no session).
export function healthRoutes({ db }: Database) {
  const router = Router();

  router.get('/live', (_req, res) => {
    res.json({ data: { status: 'ok' } });
  });

  router.get('/ready', async (req, res) => {
    try {
      await db.execute(sql`select 1`);
      res.json({ data: { status: 'ok', database: 'ok' } });
    } catch (err) {
      req.log.warn({ err }, 'readiness check failed');
      res.status(503).json({ data: { status: 'unavailable', database: 'unreachable' } });
    }
  });

  return router;
}
