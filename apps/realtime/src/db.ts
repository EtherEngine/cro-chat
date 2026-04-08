import mysql from 'mysql2/promise';

let pool: mysql.Pool;

export function initDb(config: {
  host: string;
  port: number;
  database: string;
  user: string;
  password: string;
}): void {
  pool = mysql.createPool({
    ...config,
    waitForConnections: true,
    connectionLimit: 5,
    charset: 'utf8mb4',
  });
}

export function db(): mysql.Pool {
  return pool;
}
