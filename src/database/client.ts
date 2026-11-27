import { drizzle } from 'drizzle-orm/node-postgres';
import pg from 'pg';

export function createDatabase(connectionString: string) {
  const pool = new pg.Pool({
    connectionString,
    max: 10,
    // Protects the API from runaway queries and forgotten transactions (SECURITY_ARCHITECTURE §7).
    statement_timeout: 5_000,
    idle_in_transaction_session_timeout: 10_000,
  });
  return { pool, db: drizzle({ client: pool }) };
}

export type Database = ReturnType<typeof createDatabase>;
