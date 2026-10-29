export const formatPrice = (value) =>
  value === 0 ? 'Precio a consultar' : `$${Math.round(value).toLocaleString('es-CO')}`;

export const normalizeText = (value = '') =>
  String(value).toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');

export const unique = (values) => [...new Set(values)];

/**
 * Builds the real-category tab list ({name, slug}) from the active catalog.
 * "Promociones" has no slug/route of its own (it's a client-side filter),
 * so callers that need real <Link> tabs should treat a null slug as that case.
 */
export const buildCategories = (catalog, { includePromotions = true } = {}) => {
  const active = catalog.filter((product) => product.active !== false);
  const seen = new Map();
  active.forEach((product) => { if (!seen.has(product.cat)) seen.set(product.cat, product.catSlug || null); });
  const base = [...seen.entries()].map(([name, slug]) => ({ name, slug }));
  const hasPromotions = includePromotions && active.some((product) => product.promotion?.active === true);
  return hasPromotions ? [{ name: 'Promociones', slug: null }, ...base] : base;
};
