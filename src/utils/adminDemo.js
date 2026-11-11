// Modo demostración del panel: SOLO en los links de prueba de Vercel (y en este computador).
// Entra sin clave y NO toca datos reales: las peticiones del panel se responden aquí mismo.
// En tutienditavenezolana.com nunca se activa.
const KEY = 'tt_admin_demo';

export const isPreviewHost = () => typeof window !== 'undefined' && /(\.vercel\.app|localhost|127\.0\.0\.1)$/.test(window.location.hostname);

export const isAdminDemo = () => {
  if (!isPreviewHost()) return false;
  try { return window.sessionStorage.getItem(KEY) === '1'; } catch { return false; }
};

export const startAdminDemo = () => { try { window.sessionStorage.setItem(KEY, '1'); } catch { /* sin almacenamiento */ } };
export const stopAdminDemo = () => { try { window.sessionStorage.removeItem(KEY); } catch { /* sin almacenamiento */ } };

export const DEMO_USER = { id: 0, name: 'Demostración', username: 'demostracion', role: 'ADMIN', demo: true };

/** Respuesta local para una petición del panel en modo demostración, o undefined si debe ir al servidor. */
export function demoResponse(path) {
  if (!isAdminDemo()) return undefined;
  const p = String(path).split('?')[0];
  if (p === '/api/auth/me') return { user: DEMO_USER };
  if (p === '/api/auth/csrf-token') return { csrf_token: 'demo' };
  if (p === '/api/auth/logout') { stopAdminDemo(); return null; }
  if (p.startsWith('/api/admin')) {
    const error = new Error('Esta sección no muestra datos en la demostración (no se usan datos reales).');
    error.status = 404;
    throw error;
  }
  return undefined; // catálogo y blog públicos sí van al servidor
}
