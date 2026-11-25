import mysql from 'mysql2/promise';

/** Runs statements against the isolated `test` database that Playwright requests use. */
export async function query(sql: string, values: unknown[] = []): Promise<void> {
  const connection = await mysql.createConnection({ host: 'db', user: 'db', password: 'db', database: 'test' });
  try {
    await connection.query(sql, values);
  } finally {
    await connection.end();
  }
}
