import type { ErrorRequestHandler, RequestHandler } from 'express';
import { AppError, NotFoundError } from '../common/errors.js';

export const notFound: RequestHandler = (_req, _res, next) => next(new NotFoundError());

export const errorHandler: ErrorRequestHandler = (err, req, res, _next) => {
  const requestId = String(req.id);

  if (err instanceof AppError) {
    res.status(err.status).json({
      error: { code: err.code, message: err.message, ...(err.fields && { fields: err.fields }), requestId },
    });
    return;
  }

  // Body parser errors (malformed JSON, too large) carry a 4xx status and are safe to describe.
  const status = typeof err?.status === 'number' && err.status >= 400 && err.status < 500 ? err.status : 500;
  if (status < 500) {
    const code = status === 413 ? 'payload_too_large' : 'bad_request';
    res.status(status).json({ error: { code, message: 'The request could not be read.', requestId } });
    return;
  }

  // Unknown failure: details go to the log only, never to the client.
  req.log.error({ err }, 'unhandled error');
  res.status(500).json({ error: { code: 'internal_error', message: 'Something went wrong.', requestId } });
};
