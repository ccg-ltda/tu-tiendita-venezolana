import { DatabaseSync } from 'node:sqlite';
import { mkdirSync, readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { randomBytes, scryptSync, timingSafeEqual } from 'node:crypto';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const databasePath = resolve(root, process.env.DATABASE_PATH || 'data/store.sqlite');
mkdirSync(dirname(databasePath), { recursive: true });

export const db = new DatabaseSync(databasePath);
db.exec('PRAGMA foreign_keys = ON; PRAGMA journal_mode = WAL;');
db.exec(`
  CREATE TABLE IF NOT EXISTS admin_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
  );
  CREATE TABLE IF NOT EXISTS sessions (
    token_hash TEXT PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES admin_users(id) ON DELETE CASCADE,
    expires_at TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
  );
  CREATE TABLE IF NOT EXISTS products (
    id INTEGER PRIMARY KEY,
    img TEXT,
    category TEXT NOT NULL,
    subcategory TEXT NOT NULL,
    name TEXT NOT NULL,
    presentation TEXT NOT NULL DEFAULT 'Unidad',
    price INTEGER NOT NULL DEFAULT 0 CHECK(price >= 0),
    image TEXT,
    inventory INTEGER NOT NULL DEFAULT 0 CHECK(inventory >= 0),
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
  );
  CREATE TABLE IF NOT EXISTS orders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    reference TEXT NOT NULL UNIQUE,
    status TEXT NOT NULL DEFAULT 'PENDING',
    customer_name TEXT NOT NULL,
    customer_email TEXT NOT NULL,
    customer_phone TEXT NOT NULL,
    customer_document TEXT NOT NULL,
    address TEXT NOT NULL,
    extra TEXT,
    city TEXT NOT NULL,
    region TEXT NOT NULL,
    postal TEXT,
    total INTEGER NOT NULL CHECK(total >= 0),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
  );
  CREATE TABLE IF NOT EXISTS order_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
    product_id INTEGER NOT NULL,
    product_name TEXT NOT NULL,
    unit_price INTEGER NOT NULL,
    quantity INTEGER NOT NULL CHECK(quantity > 0)
  );
`);

export function hashPassword(password) {
  const salt = randomBytes(16);
  const hash = scryptSync(password, salt, 64);
  return `${salt.toString('hex')}:${hash.toString('hex')}`;
}

export function verifyPassword(password, stored) {
  const [saltHex, hashHex] = String(stored).split(':');
  if (!saltHex || !hashHex) return false;
  const expected = Buffer.from(hashHex, 'hex');
  const actual = scryptSync(password, Buffer.from(saltHex, 'hex'), expected.length);
  return actual.length === expected.length && timingSafeEqual(actual, expected);
}

export function seedCatalog({ force = false } = {}) {
  const catalog = JSON.parse(readFileSync(resolve(root, 'src/data/products.json'), 'utf8'));
  const count = db.prepare('SELECT COUNT(*) AS count FROM products').get().count;
  if (count && !force) return;
  const insert = db.prepare(`
    INSERT INTO products (id, img, category, subcategory, name, presentation, price, image, inventory, active)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
  `);
  db.exec('BEGIN IMMEDIATE');
  try {
    if (force) db.exec('DELETE FROM products');
    for (const product of catalog) {
      insert.run(product.id, product.img || null, product.cat, product.sub, product.name, product.pres || 'Unidad', Math.max(0, Number(product.price) || 0), product.image || null, 20);
    }
    db.exec('COMMIT');
  } catch (error) {
    db.exec('ROLLBACK');
    throw error;
  }
}

export function toProduct(row) {
  return {
    id: row.id,
    img: row.img,
    cat: row.category,
    sub: row.subcategory,
    name: row.name,
    pres: row.presentation,
    price: row.price,
    image: row.image,
    inventory: row.inventory,
    active: Boolean(row.active),
  };
}

seedCatalog();

const adminEmail = (process.env.ADMIN_EMAIL || 'admin@tutiendita.com').trim().toLowerCase();
const adminPassword = process.env.ADMIN_PASSWORD || 'Admin123!';
const existingAdmin = db.prepare('SELECT id FROM admin_users WHERE email = ?').get(adminEmail);
if (!existingAdmin) {
  if (process.env.ADMIN_EMAIL || process.env.ADMIN_PASSWORD) {
    db.exec('DELETE FROM sessions; DELETE FROM admin_users;');
  }
  db.prepare('INSERT INTO admin_users (email, password_hash) VALUES (?, ?)').run(adminEmail, hashPassword(adminPassword));
  console.info(`Administrador inicial creado: ${adminEmail}`);
} else if (process.env.ADMIN_PASSWORD) {
  db.prepare('UPDATE admin_users SET password_hash = ? WHERE id = ?').run(hashPassword(adminPassword), existingAdmin.id);
}
