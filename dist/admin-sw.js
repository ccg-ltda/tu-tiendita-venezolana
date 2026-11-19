// Service worker mínimo del panel: permite instalarlo como app. No guarda pedidos ni datos en caché.
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));
self.addEventListener('fetch', () => {});
