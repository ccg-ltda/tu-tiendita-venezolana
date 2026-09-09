# Tu Tiendita Venezolana

Aplicación de comercio electrónico con frontend React/Vite y backend Laravel/MySQL.

## Desarrollo local

Requisitos: Node.js 22.13 o posterior, npm, PHP 8.2 o posterior, Composer y MySQL.

Inicia el frontend:

```bash
npm install
npm run dev
```

Inicia Laravel desde `backend/`:

```bash
php artisan serve
```

Vite redirige `/api` y `/storage` al backend Laravel local en `http://127.0.0.1:8000`.
Configura `backend/.env` a partir de `backend/.env.example`, ejecuta las migraciones necesarias y crea el enlace de almacenamiento para las imágenes gestionadas por Laravel.

## API principal

- `GET /api/products`: catálogo público.
- `POST /api/orders`: registra un pedido y descuenta inventario.
- `POST /api/auth/login`: inicia la sesión administrativa.
- `GET /api/auth/me`: comprueba la sesión.
- `POST /api/auth/logout`: cierra la sesión.
- `GET /api/admin/products`: catálogo completo, requiere sesión.
- `GET /api/admin/orders`: lista pedidos; `GET /api/admin/orders/{id}` muestra detalle.

## Estructura

- `src/`: interfaz React.
- `src/services/api.js`: cliente de la API Laravel.
- `backend/`: API Laravel, migraciones y seeders MySQL.
- `backend/database/data/products.json`: snapshot histórico para el seeder inicial de Laravel.
- `public/assets/`: marca e imágenes legacy activas del catálogo.
- `legacy/tu-tiendita-venezolana-FINAL.html`: referencia histórica y visual.
- `vercel.json`: compilación estática temporal del frontend y fallback SPA; no hospeda la API Laravel.

## Seguridad y pagos

Laravel almacena las contraseñas como hashes y usa sesiones para el administrador. Configura credenciales administrativas mediante variables de entorno de Laravel y usa HTTPS en producción.

Los pedidos se registran en MySQL. La integración de Wompi requiere su propio flujo de servidor y webhook de confirmación.
