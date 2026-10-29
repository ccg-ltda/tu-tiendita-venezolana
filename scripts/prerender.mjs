// Post-build step: visits each real route of the built SPA with headless
// Chrome and dumps the rendered HTML to dist/<route>/index.html, so crawlers
// that don't execute JS (or wait for it) still see real product/category
// content. Routes come from the live catalog API.
import { spawn } from 'node:child_process';
import { mkdir, writeFile, readFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import path from 'node:path';
import puppeteer from 'puppeteer';

const API_URL = process.env.PRERENDER_API_URL || 'https://api.tutienditavenezolana.com';
const DIST_DIR = path.resolve('dist');
const PORT = process.env.PRERENDER_PORT ? Number(process.env.PRERENDER_PORT) : 4173;
const BASE_URL = `http://127.0.0.1:${PORT}`;

function waitForServer(url, timeoutMs = 20000) {
  const start = Date.now();
  return new Promise((resolve, reject) => {
    const tick = async () => {
      try {
        const res = await fetch(url);
        if (res.ok || res.status === 404) return resolve();
      } catch {
        // server not up yet
      }
      if (Date.now() - start > timeoutMs) return reject(new Error(`Timed out waiting for ${url}`));
      setTimeout(tick, 300);
    };
    tick();
  });
}

async function fetchRoutes() {
  const res = await fetch(`${API_URL}/api/products`);
  if (!res.ok) throw new Error(`No se pudo leer el catálogo (${API_URL}/api/products): HTTP ${res.status}`);
  const { products } = await res.json();
  const active = products.filter((p) => p.active !== false);

  const categories = new Map();
  active.forEach((p) => { if (p.catSlug && !categories.has(p.catSlug)) categories.set(p.catSlug, p.cat); });

  const routes = [{ path: '/', label: 'inicio' }];
  for (const slug of categories.keys()) routes.push({ path: `/categoria/${slug}`, label: `categoría ${slug}` });
  for (const p of active) if (p.slug) routes.push({ path: `/producto/${p.slug}`, label: `producto ${p.slug}` });

  return routes;
}

async function outputPathFor(routePath) {
  if (routePath === '/') return path.join(DIST_DIR, 'index.html');
  return path.join(DIST_DIR, routePath.replace(/^\//, ''), 'index.html');
}

async function prerenderRoute(page, routePath) {
  await page.goto(`${BASE_URL}${routePath}`, { waitUntil: 'networkidle0', timeout: 30000 });
  // El catálogo llega por fetch; dale un respiro extra al render de React.
  await page.waitForSelector('h1', { timeout: 10000 }).catch(() => {});
  const html = await page.content();
  const outPath = await outputPathFor(routePath);
  await mkdir(path.dirname(outPath), { recursive: true });
  await writeFile(outPath, html, 'utf8');
}

async function main() {
  if (!existsSync(DIST_DIR)) throw new Error('dist/ no existe. Corré "vite build" antes del prerender.');

  console.log(`[prerender] Leyendo catálogo de ${API_URL} ...`);
  const routes = await fetchRoutes();
  console.log(`[prerender] ${routes.length} rutas a renderizar.`);

  const server = spawn('npx', ['serve', '-s', 'dist', '-l', String(PORT)], {
    stdio: 'ignore',
    shell: true,
  });

  try {
    await waitForServer(BASE_URL);
    // El servidor estático local corre en un origin distinto al dominio real
    // (CORS lo bloquearía). Es un Chrome efímero solo para scraping en build
    // time, nunca expuesto a usuarios, así que desactivar CORS acá es seguro.
    const browser = await puppeteer.launch({ headless: true, args: ['--disable-web-security'] });
    try {
      const page = await browser.newPage();
      for (const route of routes) {
        try {
          await prerenderRoute(page, route.path);
          console.log(`[prerender] OK  ${route.path}`);
        } catch (error) {
          console.error(`[prerender] FAIL ${route.path}: ${error.message}`);
        }
      }
    } finally {
      await browser.close();
    }
  } finally {
    server.kill();
  }

  console.log('[prerender] Listo.');
}

main().catch((error) => {
  console.error('[prerender] Error fatal:', error);
  process.exitCode = 1;
});
