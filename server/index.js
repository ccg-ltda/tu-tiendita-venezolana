import express from 'express';
import { createHash, randomBytes } from 'node:crypto';
import { existsSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { db, databaseReady, queryAll, queryOne, seedCatalog, toProduct, verifyPassword } from './db.js';

const filename = fileURLToPath(import.meta.url);
const root = resolve(dirname(filename), '..');
const app = express();
const port = Number(process.env.PORT) || 3001;
const production = process.env.NODE_ENV === 'production' || Boolean(process.env.VERCEL);
const sessionCookie = 'ttv_session';
const sessionDays = 7;
const loginAttempts = new Map();

app.disable('x-powered-by');
app.use(express.json({ limit: '250kb' }));
app.use(async (req, res, next) => {
  await databaseReady;
  next();
});

const parseCookies = (header = '') => Object.fromEntries(header.split(';').map((part) => part.trim().split('=')).filter(([key]) => key).map(([key, value]) => [key, decodeURIComponent(value || '')]));
const tokenHash = (token) => createHash('sha256').update(token).digest('hex');
const productRows = async (onlyActive = false) => (await queryAll(`SELECT * FROM products ${onlyActive ? 'WHERE active = 1' : ''} ORDER BY id`)).map(toProduct);

async function currentAdmin(req) {
  const token = parseCookies(req.headers.cookie)[sessionCookie];
  if (!token) return null;
  return queryOne(`
    SELECT admin_users.id, admin_users.email
    FROM sessions JOIN admin_users ON admin_users.id = sessions.user_id
    WHERE sessions.token_hash = ? AND sessions.expires_at > CURRENT_TIMESTAMP
  `, [tokenHash(token)]);
}

async function requireAdmin(req, res, next) {
  const admin = await currentAdmin(req);
  if (!admin) return res.status(401).json({ error: 'Debes iniciar sesión como administrador.' });
  req.admin = admin;
  next();
}

function validateProduct(body) {
  const product = {
    img: body.img ? String(body.img).trim() : null,
    cat: String(body.cat || '').trim(),
    sub: String(body.sub || '').trim(),
    name: String(body.name || '').trim(),
    pres: String(body.pres || 'Unidad').trim(),
    price: Math.max(0, Math.round(Number(body.price) || 0)),
    image: body.image ? String(body.image).trim() : null,
    inventory: Math.max(0, Math.floor(Number(body.inventory) || 0)),
    active: body.active === false ? 0 : 1,
  };
  if (!product.name || !product.cat || !product.sub) throw new Error('Nombre, categoría y subcategoría son obligatorios.');
  return product;
}

app.get('/api/health', async (req, res) => {
  await queryOne('SELECT 1 AS ok');
  res.json({ ok: true, database: process.env.TURSO_DATABASE_URL ? 'turso' : 'sqlite-local' });
});

app.get('/api/products', async (req, res) => res.json({ products: await productRows(true) }));

app.post('/api/auth/login', async (req, res) => {
  const key = req.ip;
  const attempt = loginAttempts.get(key) || { count: 0, since: Date.now() };
  if (Date.now() - attempt.since > 15 * 60 * 1000) { attempt.count = 0; attempt.since = Date.now(); }
  if (attempt.count >= 8) return res.status(429).json({ error: 'Demasiados intentos. Espera 15 minutos.' });
  const email = String(req.body.email || '').trim().toLowerCase();
  const user = await queryOne('SELECT * FROM admin_users WHERE email = ?', [email]);
  if (!user || !verifyPassword(String(req.body.password || ''), user.password_hash)) {
    attempt.count += 1;
    loginAttempts.set(key, attempt);
    return res.status(401).json({ error: 'Correo o contraseña incorrectos.' });
  }
  loginAttempts.delete(key);
  await db.execute('DELETE FROM sessions WHERE expires_at <= CURRENT_TIMESTAMP');
  const token = randomBytes(32).toString('hex');
  const expires = new Date(Date.now() + sessionDays * 86400000);
  await db.execute({ sql: 'INSERT INTO sessions (token_hash, user_id, expires_at) VALUES (?, ?, ?)', args: [tokenHash(token), user.id, expires.toISOString()] });
  res.cookie(sessionCookie, token, { httpOnly: true, sameSite: 'lax', secure: production, path: '/', maxAge: sessionDays * 86400000 });
  res.json({ admin: { id: Number(user.id), email: user.email } });
});

app.get('/api/auth/session', async (req, res) => {
  const admin = await currentAdmin(req);
  if (!admin) return res.status(401).json({ admin: null });
  res.json({ admin: { id: Number(admin.id), email: admin.email } });
});

app.post('/api/auth/logout', async (req, res) => {
  const token = parseCookies(req.headers.cookie)[sessionCookie];
  if (token) await db.execute({ sql: 'DELETE FROM sessions WHERE token_hash = ?', args: [tokenHash(token)] });
  res.clearCookie(sessionCookie, { httpOnly: true, sameSite: 'lax', secure: production, path: '/' });
  res.status(204).end();
});

app.get('/api/admin/products', requireAdmin, async (req, res) => res.json({ products: await productRows(false) }));

app.post('/api/admin/products', requireAdmin, async (req, res) => {
  try {
    const product = validateProduct(req.body);
    const result = await db.execute({
      sql: `INSERT INTO products (img, category, subcategory, name, presentation, price, image, inventory, active)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)`,
      args: [product.img, product.cat, product.sub, product.name, product.pres, product.price, product.image, product.inventory, product.active],
    });
    const row = await queryOne('SELECT * FROM products WHERE id = ?', [Number(result.lastInsertRowid)]);
    res.status(201).json({ product: toProduct(row) });
  } catch (error) {
    res.status(400).json({ error: error.message });
  }
});

app.put('/api/admin/products/:id', requireAdmin, async (req, res) => {
  try {
    const id = Number(req.params.id);
    const product = validateProduct(req.body);
    const result = await db.execute({
      sql: `UPDATE products SET img = ?, category = ?, subcategory = ?, name = ?, presentation = ?,
        price = ?, image = ?, inventory = ?, active = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?`,
      args: [product.img, product.cat, product.sub, product.name, product.pres, product.price, product.image, product.inventory, product.active, id],
    });
    if (!result.rowsAffected) return res.status(404).json({ error: 'Producto no encontrado.' });
    res.json({ product: toProduct(await queryOne('SELECT * FROM products WHERE id = ?', [id])) });
  } catch (error) {
    res.status(400).json({ error: error.message });
  }
});

app.post('/api/admin/products/reset', requireAdmin, async (req, res) => {
  await seedCatalog({ force: true });
  res.json({ products: await productRows(false) });
});

app.get('/api/admin/orders', requireAdmin, async (req, res) => {
  const orders = await queryAll('SELECT * FROM orders ORDER BY id DESC LIMIT 200');
  res.json({ orders });
});

app.post('/api/orders', async (req, res) => {
  const customer = req.body.customer || {};
  const requestedItems = Array.isArray(req.body.items) ? req.body.items : [];
  if (!customer.name || !customer.email || !customer.phone || !customer.document || !customer.address || !customer.city || !customer.region) {
    return res.status(400).json({ error: 'Completa todos los datos obligatorios.' });
  }
  if (!requestedItems.length) return res.status(400).json({ error: 'El pedido no tiene productos.' });
  const transaction = await db.transaction('write');
  try {
    const items = [];
    for (const item of requestedItems) {
      const quantity = Math.max(1, Math.floor(Number(item.qty) || 0));
      const result = await transaction.execute({ sql: 'SELECT * FROM products WHERE id = ? AND active = 1', args: [Number(item.id)] });
      const product = result.rows[0];
      if (!product) throw new Error('Uno de los productos ya no está disponible.');
      if (Number(product.inventory) < quantity) throw new Error(`Solo quedan ${product.inventory} unidades de ${product.name}.`);
      items.push({ product, quantity });
    }
    const total = items.reduce((sum, item) => sum + Number(item.product.price) * item.quantity, 0);
    const reference = `TTV-${Date.now().toString(36).toUpperCase()}-${randomBytes(2).toString('hex').toUpperCase()}`;
    const order = await transaction.execute({
      sql: `INSERT INTO orders (reference, status, customer_name, customer_email, customer_phone, customer_document, address, extra, city, region, postal, total)
        VALUES (?, 'PENDING', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
      args: [reference, String(customer.name), String(customer.email), String(customer.phone), String(customer.document), String(customer.address), String(customer.extra || ''), String(customer.city), String(customer.region), String(customer.postal || ''), total],
    });
    const orderId = Number(order.lastInsertRowid);
    for (const item of items) {
      await transaction.execute({
        sql: 'INSERT INTO order_items (order_id, product_id, product_name, unit_price, quantity) VALUES (?, ?, ?, ?, ?)',
        args: [orderId, item.product.id, item.product.name, item.product.price, item.quantity],
      });
      await transaction.execute({ sql: 'UPDATE products SET inventory = inventory - ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?', args: [item.quantity, item.product.id] });
    }
    await transaction.commit();
    res.status(201).json({ order: { id: orderId, reference, status: 'PENDING', total } });
  } catch (error) {
    await transaction.rollback();
    res.status(409).json({ error: error.message });
  }
});

const dist = resolve(root, 'dist');
if (!process.env.VERCEL && production && existsSync(dist)) {
  app.use(express.static(dist));
  app.use((req, res, next) => req.method === 'GET' && !req.path.startsWith('/api/') ? res.sendFile(resolve(dist, 'index.html')) : next());
}

app.use((req, res) => res.status(404).json({ error: 'Ruta no encontrada.' }));
app.use((error, req, res, next) => {
  console.error(error);
  res.status(500).json({ error: 'Error interno del servidor.' });
});

if (process.argv[1] && resolve(process.argv[1]) === filename) {
  databaseReady.then(() => app.listen(port, () => console.info(`Backend disponible en http://127.0.0.1:${port}`))).catch((error) => {
    console.error('No se pudo iniciar la base de datos', error);
    process.exitCode = 1;
  });
}

export default app;
