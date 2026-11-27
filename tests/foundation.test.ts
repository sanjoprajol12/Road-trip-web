import request from 'supertest';
import { afterAll, describe, expect, it } from 'vitest';
import { loadEnv } from '../src/config/env.js';
import { buildTestApp } from './helpers.js';

const { app, database } = buildTestApp();
afterAll(() => database.pool.end());

describe('health endpoints', () => {
  it('reports liveness without touching the database', async () => {
    const res = await request(app).get('/health/live').expect(200);
    expect(res.body).toEqual({ data: { status: 'ok' } });
  });

  it('reports readiness with a real database round trip', async () => {
    const res = await request(app).get('/health/ready').expect(200);
    expect(res.body).toEqual({ data: { status: 'ok', database: 'ok' } });
  });

  it('is also reachable under /api/v1 for the frontends', async () => {
    await request(app).get('/api/v1/health/ready').expect(200);
  });

  it('answers 503 when the database is unreachable', async () => {
    // Port 1 never has PostgreSQL listening; the URL keeps the test-database guard happy.
    const broken = buildTestApp('postgres://mrn_app:x@127.0.0.1:1/myridenepal_test');
    try {
      const res = await request(broken.app).get('/health/ready').expect(503);
      expect(res.body.data.database).toBe('unreachable');
    } finally {
      await broken.database.pool.end();
    }
  });
});

describe('error format and headers', () => {
  it('answers unknown paths with the JSON error shape and a request id', async () => {
    const res = await request(app).get('/nope').expect(404);
    expect(res.body.error).toMatchObject({ code: 'not_found' });
    expect(res.body.error.requestId).toBe(res.headers['x-request-id']);
  });

  it('rejects malformed JSON as 400 without leaking parser details', async () => {
    const res = await request(app)
      .post('/health/live')
      .set('Content-Type', 'application/json')
      .send('{"broken":')
      .expect(400);
    expect(res.body.error).toMatchObject({ code: 'bad_request', message: 'The request could not be read.' });
  });

  it('rejects bodies over 100 KB with 413', async () => {
    const res = await request(app)
      .post('/health/live')
      .set('Content-Type', 'application/json')
      .send(JSON.stringify({ x: 'a'.repeat(110_000) }))
      .expect(413);
    expect(res.body.error.code).toBe('payload_too_large');
  });

  it('sends security headers and hides the framework', async () => {
    const res = await request(app).get('/health/live');
    expect(res.headers['content-security-policy']).toContain("default-src 'none'");
    expect(res.headers['x-content-type-options']).toBe('nosniff');
    expect(res.headers['x-powered-by']).toBeUndefined();
  });
});

describe('environment validation', () => {
  const valid = {
    DATABASE_URL: 'postgres://u:p@localhost:5442/db',
    APP_URL: 'http://localhost:5273',
    ADMIN_URL: 'http://localhost:5274',
  };

  it('accepts a valid configuration and applies defaults', () => {
    expect(loadEnv(valid)).toMatchObject({ PORT: 4000, NODE_ENV: 'development' });
  });

  it('names every missing or invalid variable', () => {
    expect(() => loadEnv({ ...valid, DATABASE_URL: 'mysql://x', APP_URL: undefined })).toThrow(
      /DATABASE_URL[\s\S]*APP_URL/,
    );
  });
});
