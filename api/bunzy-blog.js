// SOLO PARA LOS LINKS DE VISTA PREVIA EN VERCEL.
// En la tienda real el blog lo sirve Laravel (BlogController + webhook de Bunzy → tabla blog_posts) y esto no se usa.
// Vercel no ejecuta Laravel, así que para poder revisar el diseño con los artículos reales,
// esta función lee el blog que Bunzy ya publica en blog.tutienditavenezolana.com y responde
// con el mismo formato que /api/blog y /api/blog/{slug} de Laravel.
const BLOG = 'https://blog.tutienditavenezolana.com';
const CACHE = 'public, s-maxage=900, stale-while-revalidate=86400';

const json = (status, body) => new Response(JSON.stringify(body), {
  status,
  headers: { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': status === 200 ? CACHE : 'no-store' },
});
const decode = (s) => String(s || '').replace(/<!\[CDATA\[|\]\]>/g, '').replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&#39;/g, "'").trim();
const tag = (xml, name) => decode((xml.match(new RegExp(`<${name}[^>]*>([\\s\\S]*?)</${name}>`)) || [])[1]);
// Fecha en hora de Colombia (YYYY-MM-DD), como la guarda Laravel.
const bogotaDate = (d) => { const t = new Date(d); return Number.isNaN(t.getTime()) ? null : new Date(t.getTime() - 5 * 3600e3).toISOString().slice(0, 10); };
const slugOf = (link) => String(link).replace(/^https?:\/\/[^/]+\//, '').replace(/\/$/, '');

async function fetchText(url) {
  const r = await fetch(url, { headers: { Accept: 'text/html,application/xml' } });
  if (!r.ok) { const e = new Error(`Bunzy respondió ${r.status}`); e.status = r.status; throw e; }
  return r.text();
}
const ogImage = (html) => decode((html.match(/property="og:image" content="([^"]+)"/) || [])[1]) || null;

async function list() {
  const xml = await fetchText(`${BLOG}/feed.xml`);
  const items = (xml.match(/<item>[\s\S]*?<\/item>/g) || []).map((it) => {
    const slug = slugOf(tag(it, 'link'));
    return { id: slug, title: tag(it, 'title'), slug, excerpt: tag(it, 'description'), published_at: bogotaDate(tag(it, 'pubDate')), image_url: null };
  }).filter((a) => /^[a-z0-9-]{1,200}$/.test(a.slug));
  // Imagen de cada artículo (og:image), en paralelo y en grupos para no saturar.
  for (let i = 0; i < items.length; i += 8) {
    await Promise.all(items.slice(i, i + 8).map(async (a) => { try { a.image_url = ogImage(await fetchText(`${BLOG}/${a.slug}`)); } catch { /* sin imagen */ } }));
  }
  return items;
}

async function show(slug) {
  const html = await fetchText(`${BLOG}/${slug}`);
  const start = html.indexOf('<article'), end = html.lastIndexOf('</article>');
  if (start < 0 || end < 0) { const e = new Error('Artículo no encontrado'); e.status = 404; throw e; }
  let body = html.slice(html.indexOf('>', start) + 1, end);
  const title = decode((body.match(/<h1[^>]*>([\s\S]*?)<\/h1>/) || [])[1]);
  const date = (body.match(/dateTime="([^"]+)"/) || [])[1];
  body = body
    .replace(/<p class="c-back">[\s\S]*?<\/p>/, '')             // "← Todos los artículos" (el diseño ya tiene "Volver")
    .replace(/<header>[\s\S]*?<\/header>/, '')                  // título y fecha: los pinta la página
    .replace(/<img[^>]*class="c-hero"[^>]*\/?>/, '')            // imagen principal: la pinta la página
    .replace(/<aside class="bz-takeaways">([\s\S]*?)<\/aside>/, '<blockquote>$1</blockquote>') // "Puntos clave" destacados
    .replace(/<!--[\s\S]*?-->/g, '')
    .replace(/href="https?:\/\/blog\.tutienditavenezolana\.com\/([a-z0-9-]+)\/?"/g, 'href="/blogs/$1"') // enlaces entre artículos
    .replace(/href="\/([a-z0-9-]+)\/?"/g, 'href="/blogs/$1"');
  return { id: slug, title, slug, content: body.trim(), image_url: ogImage(html), published_at: bogotaDate(date) };
}

const handler = async (request) => {
  if (request.method !== 'GET') return json(405, { success: false, message: 'Método no permitido.' });
  const slug = (new URL(request.url).searchParams.get('slug') || '').trim();
  try {
    if (!slug) return json(200, await list());
    if (!/^[a-z0-9-]{1,200}$/.test(slug)) return json(404, { success: false, message: 'Artículo no encontrado.' });
    return json(200, await show(slug));
  } catch (e) {
    if (e.status === 404) return json(404, { success: false, message: 'Artículo no encontrado.' });
    return json(502, { success: false, message: 'No fue posible cargar el blog.' });
  }
};

export default { fetch: handler };
