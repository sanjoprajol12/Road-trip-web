import { pino } from 'pino';

// Secrets and personal data never reach the logs (TD-14).
export function createLogger(level: string) {
  return pino({
    level,
    redact: {
      paths: [
        'req.headers.authorization',
        'req.headers.cookie',
        'req.headers["x-csrf-token"]',
        'res.headers["set-cookie"]',
        '*.password',
        '*.token',
        '*.secret',
        '*.email',
      ],
      censor: '[redacted]',
    },
  });
}
