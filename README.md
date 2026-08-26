# Tu Tiendita Venezolana

Aplicación de comercio electrónico con frontend React, backend Node.js + Express y base de datos libSQL. Usa SQLite localmente y Turso como almacenamiento persistente en Vercel.

## Funcionalidades

- Catálogo, búsqueda, categorías y carrito persistente.
- Inventario validado por el servidor.
- Registro de pedidos y descuento transaccional de existencias.
- Inicio de sesión administrativo con contraseña cifrada mediante scrypt.
- Sesiones almacenadas en la base de datos y enviadas en cookies HttpOnly.
- Creación y edición de productos, precios, inventario y visibilidad.
- API protegida para administración.

## Requisitos

- Node.js 22.13 o posterior.
- npm.

## Ejecutar en desarrollo

Instala las dependencias:

```bash
npm install
```

Copia `.env.example` como `.env` y cambia, como mínimo, la contraseña administrativa:

```env
ADMIN_EMAIL=admin@tutiendita.com
ADMIN_PASSWORD=una-contraseña-larga-y-unica
PORT=3001
DATABASE_PATH=data/store.sqlite
TURSO_DATABASE_URL=
TURSO_AUTH_TOKEN=
```

Inicia frontend y backend con un solo comando:

```bash
npm run dev
```

La tienda se abre normalmente en `http://127.0.0.1:5173` y Vite redirige las solicitudes `/api` al backend de `http://127.0.0.1:3001`.

También pueden ejecutarse por separado:

```bash
npm run dev:server
npm run dev:client
```

## Desplegar en Vercel con Turso

1. Crea una base de datos en Turso desde su panel o CLI.
2. Obtén la URL y un token:

```bash
turso db show --url tu-tiendita
turso db tokens create tu-tiendita
```

3. Importa este repositorio desde el panel de Vercel.
4. En **Settings → Environment Variables**, configura:

```env
TURSO_DATABASE_URL=libsql://tu-base-tu-organizacion.turso.io
TURSO_AUTH_TOKEN=tu-token-privado
ADMIN_EMAIL=admin@tutiendita.com
ADMIN_PASSWORD=una-contraseña-larga-y-unica
```

5. Despliega. `vercel.json` compila React, publica `dist/` y dirige las rutas `/api/*` a la función Express.

El esquema, el administrador inicial y los 198 productos se crean automáticamente durante la primera solicitud. No configures `DATABASE_PATH` en Vercel; esa variable es únicamente para desarrollo local.

## Producción en un servidor Node tradicional

Genera el frontend:

```bash
npm run build
```

Inicia Node con `NODE_ENV=production`. En PowerShell:

```powershell
$env:NODE_ENV='production'
npm start
```

Sin `TURSO_DATABASE_URL`, Express utilizará `data/store.sqlite` y servirá tanto la API como los archivos generados en `dist/`.

## API principal

- `GET /api/health`: estado del servidor.
- `GET /api/products`: catálogo público.
- `POST /api/orders`: registra un pedido y descuenta inventario.
- `POST /api/auth/login`: inicia la sesión administrativa.
- `GET /api/auth/session`: comprueba la sesión.
- `POST /api/auth/logout`: cierra la sesión.
- `GET /api/admin/products`: catálogo completo, requiere sesión.
- `POST /api/admin/products`: crea un producto.
- `PUT /api/admin/products/:id`: actualiza producto, precio o inventario.
- `POST /api/admin/products/reset`: restaura el catálogo inicial.
- `GET /api/admin/orders`: lista pedidos registrados.

## Estructura

- `server/`: API, autenticación y acceso a libSQL/Turso.
- `api/index.js`: entrada serverless utilizada por Vercel.
- `src/components/`: interfaz React.
- `src/services/api.js`: cliente de la API.
- `src/data/products.json`: catálogo usado para la carga inicial.
- `data/store.sqlite`: base local de desarrollo; no se incluye en Git.
- `vercel.json`: compilación y rutas para Vercel.
- `public/assets/`: marca e imágenes de productos.

## Seguridad y pagos

La contraseña nunca se envía al frontend salvo durante el formulario de acceso y se almacena en forma derivada, no como texto. Cambia las credenciales predeterminadas antes de publicar y usa HTTPS en producción.

Los pedidos ya se registran de forma real en la base de datos, pero el cobro electrónico todavía requiere integrar Wompi en el servidor, validar su webhook y cambiar el estado del pedido después de confirmar el pago.
