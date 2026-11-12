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

  routes.push({ path: '/blogs', label: 'blogs' });
  try {
    const blogRes = await fetch(`${API_URL}/api/blog`, { signal: AbortSignal.timeout(10000) });
    if (!blogRes.ok) throw new Error(`HTTP ${blogRes.status}`);
    const articles = await blogRes.json();
    if (!Array.isArray(articles)) throw new Error('La respuesta no es una lista de artículos');

    const blogPaths = new Set();
    for (const article of articles) {
      const slug = article?.slug;
      if (typeof slug !== 'string' || !slug.trim() || slug === '.' || slug === '..' || /[/\\]/.test(slug)) continue;
      const blogPath = `/blogs/${encodeURIComponent(slug)}`;
      if (blogPaths.has(blogPath)) continue;
      blogPaths.add(blogPath);
      routes.push({ path: blogPath, label: `blog ${slug}` });
    }
  } catch (error) {
    console.warn(`[prerender] No se pudieron leer los blogs: ${error.message}`);
  }

  return routes;
}

async function outputPathFor(routePath) {
  if (routePath === '/') return path.join(DIST_DIR, 'index.html');
  if (routePath.startsWith('/blogs/')) return path.join(DIST_DIR, 'blogs', decodeURIComponent(routePath.slice('/blogs/'.length)), 'index.html');
  return path.join(DIST_DIR, routePath.replace(/^\//, ''), 'index.html');
}

async function prerenderRoute(page, routePath) {
  await page.goto(`${BASE_URL}${routePath}`, { waitUntil: 'networkidle0', timeout: 30000 });
  // El catálogo llega por fetch; dale un respiro extra al render de React.
  await page.waitForSelector('h1', { timeout: 10000 }).catch(() => {});
  // El snippet vuelve a insertar GTM al cargar el HTML generado.
  // La hoja de fuentes de Google se carga sin bloquear (media=print → all al cargar). Al guardar la foto de la
  // página ya cargada quedaría media=all (bloqueante): se devuelve a como está en index.html.
  await page.evaluate(() => {
    document.querySelectorAll('link[rel="stylesheet"][href*="fonts.googleapis.com"]').forEach((link) => {
      link.setAttribute('media', 'print');
      link.setAttribute('onload', "this.media='all'");
    });
  });
  await page.evaluate(() => {
    document.querySelectorAll('script[src^="https://www.googletagmanager.com/gtm.js?"]').forEach((script) => script.remove());
  });
  // Vite inserta precargas de módulos con la dirección completa del servidor local
  // (http://127.0.0.1:4173/assets/...). Publicadas así, fallan en la web real: se dejan relativas.
  const html = (await page.content()).replaceAll(BASE_URL, '');
  const outPath = await outputPathFor(routePath);
  await mkdir(path.dirname(outPath), { recursive: true });
  await writeFile(outPath, html, 'utf8');
}

async function main() {
  if (!existsSync(DIST_DIR)) throw new Error('dist/ no existe. Corré "vite build" antes del prerender.');

  console.log(`[prerender] Leyendo catálogo de ${API_URL} ...`);
  // En las vistas previas de Vercel solo se preparan inicio, categorías y blog (cada página tarda ~5 s allá).
  // Las páginas de producto igual funcionan: cargan de la forma normal. El build real (hosting) prepara todas.
  const routes = (await fetchRoutes()).filter((r) => !process.env.VERCEL || !r.path.startsWith('/producto/'));
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
    // En Vercel no viene Chrome: se usa @sparticuz/chromium (Chrome para servidores). En local y en el
    // hosting de los desarrolladores sigue el Chrome normal de puppeteer, igual que antes.
    const browser = process.env.VERCEL
      ? await (async () => { const chromium = (await import('@sparticuz/chromium')).default; return puppeteer.launch({ headless: true, executablePath: await chromium.executablePath(), args: [...chromium.args, '--disable-web-security'] }); })()
      : await puppeteer.launch({ headless: true, args: ['--disable-web-security'] });
    try {
      const page = await browser.newPage();
      await page.setRequestInterception(true);
      page.on('request', (request) => {
        if (request.url().startsWith('https://www.googletagmanager.com/gtm.js?')) request.abort();
        else request.continue();
      });
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
  // En Vercel (vistas previas) no hay librerías para abrir Chrome: se omite el
  // prerender y la vista previa funciona como SPA. El build de producción (dist
  // que se sube al hosting) se sigue generando en local con prerender completo.
  if (process.env.VERCEL) {
    console.warn('[prerender] Omitido en Vercel (vista previa):', error && error.message ? String(error.message).split(String.fromCharCode(10))[0] : error);
    return;
  }
  console.error('[prerender] Error fatal:', error);
  process.exitCode = 1;
});
