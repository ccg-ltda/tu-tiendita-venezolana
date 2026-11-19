/** Envía un evento al dataLayer de GTM (GA4 y Meta lo leen desde allí). Nunca incluye datos personales. */
export function track(event, params = {}) {
  try {
    window.dataLayer = window.dataLayer || [];
    window.dataLayer.push({ event, ...params });
  } catch { /* sin medición: no pasa nada */ }
}
