import mysql from 'mysql2/promise';

/** Runs a statement against the isolated `test` database that Playwright requests use; returns the rows of a SELECT. */
export async function query<T = Record<string, unknown>>(sql: string, values: unknown[] = []): Promise<T[]> {
  const connection = await mysql.createConnection({ host: 'db', user: 'db', password: 'db', database: 'test' });
  try {
    const [rows] = await connection.query(sql, values);
    return Array.isArray(rows) ? (rows as T[]) : [];
  } finally {
    await connection.end();
  }
}
