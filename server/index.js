import express from 'express';
import { createHash, randomBytes } from 'node:crypto';
import { existsSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { db, seedCatalog, toProduct, verifyPassword } from './db.js';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const app = express();
const port = Number(process.env.PORT) || 3001;
const production = process.env.NODE_ENV === 'production';
const sessionCookie = 'ttv_session';
const sessionDays = 7;
const loginAttempts = new Map();

app.disable('x-powered-by');
app.use(express.json({ limit: '250kb' }));

const parseCookies = (header = '') => Object.fromEntries(header.split(';').map((part) => part.trim().split('=')).filter(([key]) => key).map(([key, value]) => [key, decodeURIComponent(value || '')]));
const tokenHash = (token) => createHash('sha256').update(token).digest('hex');
const productRows = (onlyActive = false) => db.prepare(`SELECT * FROM products ${onlyActive ? 'WHERE active = 1' : ''} ORDER BY id`).all().map(toProduct);

function currentAdmin(req) {
  const token = parseCookies(req.headers.cookie)[sessionCookie];
  if (!token) return null;
  return db.prepare(`
    SELECT admin_users.id, admin_users.email
    FROM sessions JOIN admin_users ON admin_users.id = sessions.user_id
    WHERE sessions.token_hash = ? AND sessions.expires_at > CURRENT_TIMESTAMP
  `).get(tokenHash(token)) || null;
}

function requireAdmin(req, res, next) {
  const admin = currentAdmin(req);
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

app.get('/api/health', (req, res) => res.json({ ok: true, database: 'sqlite' }));
app.get('/api/products', (req, res) => res.json({ products: productRows(true) }));

app.post('/api/auth/login', (req, res) => {
  const key = req.ip;
  const attempt = loginAttempts.get(key) || { count: 0, since: Date.now() };
  if (Date.now() - attempt.since > 15 * 60 * 1000) { attempt.count = 0; attempt.since = Date.now(); }
  if (attempt.count >= 8) return res.status(429).json({ error: 'Demasiados intentos. Espera 15 minutos.' });
  const email = String(req.body.email || '').trim().toLowerCase();
  const user = db.prepare('SELECT * FROM admin_users WHERE email = ?').get(email);
  if (!user || !verifyPassword(String(req.body.password || ''), user.password_hash)) {
    attempt.count += 1;
    loginAttempts.set(key, attempt);
    return res.status(401).json({ error: 'Correo o contraseña incorrectos.' });
  }
  loginAttempts.delete(key);
  db.prepare('DELETE FROM sessions WHERE expires_at <= CURRENT_TIMESTAMP').run();
  const token = randomBytes(32).toString('hex');
  const expires = new Date(Date.now() + sessionDays * 86400000);
  db.prepare('INSERT INTO sessions (token_hash, user_id, expires_at) VALUES (?, ?, ?)').run(tokenHash(token), user.id, expires.toISOString());
  res.cookie(sessionCookie, token, { httpOnly: true, sameSite: 'lax', secure: production, path: '/', maxAge: sessionDays * 86400000 });
  res.json({ admin: { id: user.id, email: user.email } });
});

app.get('/api/auth/session', (req, res) => {
  const admin = currentAdmin(req);
  if (!admin) return res.status(401).json({ admin: null });
  res.json({ admin });
});

app.post('/api/auth/logout', (req, res) => {
  const token = parseCookies(req.headers.cookie)[sessionCookie];
  if (token) db.prepare('DELETE FROM sessions WHERE token_hash = ?').run(tokenHash(token));
  res.clearCookie(sessionCookie, { httpOnly: true, sameSite: 'lax', secure: production, path: '/' });
  res.status(204).end();
});

app.get('/api/admin/products', requireAdmin, (req, res) => res.json({ products: productRows(false) }));

app.post('/api/admin/products', requireAdmin, (req, res) => {
  try {
    const product = validateProduct(req.body);
    const result = db.prepare(`
      INSERT INTO products (img, category, subcategory, name, presentation, price, image, inventory, active)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    `).run(product.img, product.cat, product.sub, product.name, product.pres, product.price, product.image, product.inventory, product.active);
    const row = db.prepare('SELECT * FROM products WHERE id = ?').get(Number(result.lastInsertRowid));
    res.status(201).json({ product: toProduct(row) });
  } catch (error) {
    res.status(400).json({ error: error.message });
  }
});

app.put('/api/admin/products/:id', requireAdmin, (req, res) => {
  try {
    const id = Number(req.params.id);
    const product = validateProduct(req.body);
    const result = db.prepare(`
      UPDATE products SET img = ?, category = ?, subcategory = ?, name = ?, presentation = ?,
        price = ?, image = ?, inventory = ?, active = ?, updated_at = CURRENT_TIMESTAMP
      WHERE id = ?
    `).run(product.img, product.cat, product.sub, product.name, product.pres, product.price, product.image, product.inventory, product.active, id);
    if (!result.changes) return res.status(404).json({ error: 'Producto no encontrado.' });
    res.json({ product: toProduct(db.prepare('SELECT * FROM products WHERE id = ?').get(id)) });
  } catch (error) {
    res.status(400).json({ error: error.message });
  }
});

app.post('/api/admin/products/reset', requireAdmin, (req, res) => {
  seedCatalog({ force: true });
  res.json({ products: productRows(false) });
});

app.get('/api/admin/orders', requireAdmin, (req, res) => {
  const orders = db.prepare('SELECT * FROM orders ORDER BY id DESC LIMIT 200').all();
  res.json({ orders });
});

app.post('/api/orders', (req, res) => {
  const customer = req.body.customer || {};
  const requestedItems = Array.isArray(req.body.items) ? req.body.items : [];
  if (!customer.name || !customer.email || !customer.phone || !customer.document || !customer.address || !customer.city || !customer.region) {
    return res.status(400).json({ error: 'Completa todos los datos obligatorios.' });
  }
  if (!requestedItems.length) return res.status(400).json({ error: 'El pedido no tiene productos.' });
  db.exec('BEGIN IMMEDIATE');
  try {
    const items = requestedItems.map((item) => {
      const quantity = Math.max(1, Math.floor(Number(item.qty) || 0));
      const product = db.prepare('SELECT * FROM products WHERE id = ? AND active = 1').get(Number(item.id));
      if (!product) throw new Error('Uno de los productos ya no está disponible.');
      if (product.inventory < quantity) throw new Error(`Solo quedan ${product.inventory} unidades de ${product.name}.`);
      return { product, quantity };
    });
    const total = items.reduce((sum, item) => sum + item.product.price * item.quantity, 0);
    const reference = `TTV-${Date.now().toString(36).toUpperCase()}-${randomBytes(2).toString('hex').toUpperCase()}`;
    const order = db.prepare(`
      INSERT INTO orders (reference, status, customer_name, customer_email, customer_phone, customer_document, address, extra, city, region, postal, total)
      VALUES (?, 'PENDING', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    `).run(reference, String(customer.name), String(customer.email), String(customer.phone), String(customer.document), String(customer.address), String(customer.extra || ''), String(customer.city), String(customer.region), String(customer.postal || ''), total);
    const orderId = Number(order.lastInsertRowid);
    const addItem = db.prepare('INSERT INTO order_items (order_id, product_id, product_name, unit_price, quantity) VALUES (?, ?, ?, ?, ?)');
    const reduceStock = db.prepare('UPDATE products SET inventory = inventory - ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
    for (const item of items) {
      addItem.run(orderId, item.product.id, item.product.name, item.product.price, item.quantity);
      reduceStock.run(item.quantity, item.product.id);
    }
    db.exec('COMMIT');
    res.status(201).json({ order: { id: orderId, reference, status: 'PENDING', total } });
  } catch (error) {
    db.exec('ROLLBACK');
    res.status(409).json({ error: error.message });
  }
});

const dist = resolve(root, 'dist');
if (production && existsSync(dist)) {
  app.use(express.static(dist));
  app.use((req, res, next) => req.method === 'GET' && !req.path.startsWith('/api/') ? res.sendFile(resolve(dist, 'index.html')) : next());
}

app.use((req, res) => res.status(404).json({ error: 'Ruta no encontrada.' }));
app.use((error, req, res, next) => {
  console.error(error);
  res.status(500).json({ error: 'Error interno del servidor.' });
});

app.listen(port, () => console.info(`Backend disponible en http://127.0.0.1:${port}`));
