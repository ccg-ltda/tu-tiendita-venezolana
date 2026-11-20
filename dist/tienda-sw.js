// Service worker mínimo de la tienda: permite instalarla como app. No guarda nada en caché (precios y stock siempre al día).
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));
self.addEventListener('fetch', () => {});
