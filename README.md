# Tu Tiendita Venezolana

Catálogo de comercio electrónico migrado de un único HTML a React + Vite. El proyecto mantiene filtros, búsqueda, carrito persistente, checkout demostrativo, políticas e imágenes de productos.

## Ejecutar localmente

```bash
npm install
npm run dev
```

Para crear la versión de producción:

```bash
npm run build
```

El resultado queda en `dist/` y puede publicarse en GitHub Pages, Netlify o Vercel.

El flujo `.github/workflows/deploy-pages.yml` publica automáticamente cada cambio enviado a la rama `main`. En GitHub activa **Settings → Pages → Source → GitHub Actions** una sola vez.

## Estructura

- `src/components/`: componentes React por dominio.
- `src/data/`: catálogo, categorías, marca y políticas.
- `src/styles/`: estilos globales y adaptaciones React.
- `src/utils/`: funciones compartidas.
- `public/assets/`: logo, banner e imágenes de productos.
- `scripts/`: herramienta reproducible de extracción desde el HTML original.
- `legacy/`: copia del prototipo monolítico original, conservada como respaldo.

## Pagos y correo

El pago actual es una simulación heredada del prototipo. Copia `.env.example` como `.env` y configura las credenciales, pero usa un backend para generar firmas de integridad de Wompi, validar webhooks, descontar inventario y enviar recibos. Nunca publiques llaves privadas en variables `VITE_*`.

## Subir a GitHub

```bash
git init
git add .
git commit -m "Migrar tienda a React"
git branch -M main
git remote add origin URL_DE_TU_REPOSITORIO
git push -u origin main
```
