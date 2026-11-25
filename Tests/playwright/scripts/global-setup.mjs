// Reseeds the `test` database before the run.
import { readFile } from 'node:fs/promises';
import mysql from 'mysql2/promise';

const SEED_FILE = new URL('../fixtures/seed.sql', import.meta.url);

export default async function globalSetup() {
  const connection = await mysql.createConnection({
    host: 'db',
    user: 'db',
    password: 'db',
    database: 'test',
    multipleStatements: true,
  });
  try {
    await connection.query(await readFile(SEED_FILE, 'utf8'));
  } finally {
    await connection.end();
  }
}
