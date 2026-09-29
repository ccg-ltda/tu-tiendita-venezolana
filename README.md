# Tu Tiendita Venezolana

Aplicación de comercio electrónico con frontend en React y backend en Laravel. Google Sheets es la fuente principal de datos del negocio; MySQL no se usa como persistencia de negocio en el flujo actual.

## Arquitectura actual

El backend centraliza la integración con los servicios externos y mantiene cachés y snapshots privados para las lecturas administrativas y del catálogo.

```text
Catálogo y administración de productos: React → Laravel → Google Sheets API directa
Checkout: React → Laravel → Google Sheets API directa
Pagos: React → Laravel → Wompi
Eventos de pago: Wompi → webhook de Laravel → Google Sheets API directa
```

`CHECKOUT_WRITER_BACKEND=direct` selecciona el writer directo de Laravel para las operaciones críticas de checkout. Apps Script sigue disponible para funciones auxiliares, lecturas y respaldo, pero sus writers críticos están protegidos o deshabilitados por `apps-script/CutoverGuard.gs` durante el cutover.

## Productos

Google Sheets es la fuente del catálogo. Laravel expone el catálogo público desde su snapshot y caché privados, de modo que el cliente no consulta directamente servicios externos en cada solicitud.

El administrador puede crear, editar, activar o desactivar productos e incluir cambios de inventario. Estas escrituras se realizan desde Laravel directamente mediante la API de Google Sheets. Si una sincronización no puede completarse de inmediato, Laravel conserva la operación pendiente para reintentarla.

## Checkout y pedidos

El checkout sigue el flujo React → Laravel → Google Sheets API directa. Durante la preparación del checkout, Laravel reserva el pedido y descuenta el inventario correspondiente. El sistema también gestiona idempotencia, consistencia y liberación de reservas vencidas.

El listado de pedidos de administración se entrega desde una caché/snapshot privado de Laravel. Para el detalle se usa la caché disponible o se consulta únicamente el pedido solicitado. El estado operativo del pedido puede actualizarse desde el panel según las transiciones permitidas.

## Pagos con Wompi

React solicita a Laravel la preparación del pago y Laravel se integra con Wompi. Wompi envía los eventos de pago al webhook de Laravel; el webhook procesa el evento y lo registra en Google Sheets mediante la API directa. Los eventos que no se puedan persistir en el momento se sincronizan posteriormente con el scheduler.

## Autenticación admin

La autenticación administrativa se configura con variables de entorno (`ADMIN_EMAIL` y `ADMIN_PASSWORD_HASH`) y Laravel gestiona la sesión. No depende de MySQL.

## Requisitos

- Node.js 22.13 o superior y npm.
- PHP 8.2 o superior.
- Composer.
- Acceso privado a Google Sheets, Wompi y, cuando se usen sus funciones auxiliares o de respaldo, Apps Script.

## Preparación inicial

1. Clone el repositorio.
2. Configure `backend/.env` a partir de `backend/.env.example`, sin versionar secretos.
3. Instale las dependencias del frontend y del backend.
4. Instale el archivo de credenciales de Google indicado en la sección correspondiente.
5. Desde `backend`, genere el snapshot inicial del catálogo y una página de caché administrativa de pedidos:

```bash
php artisan products:refresh-catalog
php artisan orders:refresh-admin-cache --page=1 --per-page=25
```

## Desarrollo local

### Frontend

Desde la raíz del proyecto:

```bash
npm install
npm run dev
```

### Backend

En otra terminal:

```bash
cd backend
composer install
php artisan serve
```

### Scheduler

En una tercera terminal, dentro de `backend`:

```bash
php artisan schedule:work
```

El scheduler libera reservas vencidas, sincroniza eventos de pago pendientes, actualiza el catálogo y refresca la caché administrativa de pedidos. Los comandos disponibles son:

```bash
php artisan wompi:release-expired-reservations
php artisan wompi:sync-pending-payment-events
php artisan products:refresh-catalog
php artisan orders:refresh-admin-cache --page=1 --per-page=25
```

### Webhooks de Wompi en local

Para recibir webhooks en el entorno local:

```bash
ngrok http 8000
```

Configure en Wompi la URL pública temporal de ngrok apuntando al endpoint `POST /api/webhooks/wompi`. La URL cambia al reiniciar el túnel.

## Google Sheets y Apps Script

Google Sheets almacena los datos de negocio. Laravel usa la API directa de Google Sheets para las escrituras críticas de checkout, los eventos de pago y las operaciones administrativas de productos.

Las credenciales de la cuenta de servicio deben estar en:

```text
backend/storage/app/private/google/service-account.json
```

Ese archivo no debe versionarse ni incluirse en despliegues públicos. Compártalo con la hoja de cálculo configurada en `GOOGLE_SHEETS_SPREADSHEET_ID` y aprovisiónelo de forma privada en cada entorno.

El código de Apps Script se encuentra en `apps-script/`. Permanece para lecturas, funciones auxiliares y respaldo. `CutoverGuard.gs` protege los writers críticos de Apps Script durante el uso del writer directo de Laravel.

## API actual

Las rutas administrativas requieren una sesión de administrador activa.

| Método | Ruta | Propósito |
| --- | --- | --- |
| `GET` | `/api/products` | Obtiene el catálogo público desde el snapshot/caché de Laravel. |
| `POST` | `/api/payments/wompi/prepare` | Prepara el checkout y el pago de Wompi. |
| `GET` | `/api/payments/wompi/status` | Consulta el estado de un pago preparado. |
| `POST` | `/api/webhooks/wompi` | Recibe eventos de pago enviados por Wompi. |
| `GET` | `/api/auth/csrf-token` | Obtiene el token CSRF de la sesión actual. |
| `POST` | `/api/auth/login` | Inicia la sesión administrativa. |
| `GET` | `/api/auth/me` | Devuelve la identidad de la sesión administrativa. |
| `POST` | `/api/auth/logout` | Cierra la sesión administrativa. |
| `GET` | `/api/admin/products` | Obtiene productos para administración. |
| `POST` | `/api/admin/products` | Crea un producto. |
| `PATCH` | `/api/admin/products/{productId}` | Edita un producto, incluido su inventario. |
| `PATCH` | `/api/admin/products/{productId}/status` | Activa o desactiva un producto. |
| `GET` | `/api/admin/orders` | Obtiene el listado administrativo de pedidos desde la caché privada. |
| `GET` | `/api/admin/orders/{id}` | Obtiene el detalle de un pedido. |
| `PATCH` | `/api/admin/orders/{id}/status` | Actualiza el estado operativo de un pedido. |

## Despliegue

Configure las variables de entorno y las credenciales privadas de Google en el entorno de despliegue. Establezca `CHECKOUT_WRITER_BACKEND=direct`, publique `APP_URL` y `FRONTEND_URL` con sus URLs reales y registre en Wompi el webhook `POST /api/webhooks/wompi` bajo la URL pública de Laravel.

Mantenga el scheduler de Laravel en ejecución mediante el mecanismo de procesos o tareas programadas del hosting. Antes de atender tráfico, ejecute los comandos de preparación inicial para disponer de un catálogo y una caché administrativa actualizados.

## Estructura del proyecto

- `src/`: aplicación React y servicios del frontend.
- `public/`: recursos estáticos del frontend.
- `backend/`: aplicación Laravel, API, integración con Google Sheets y Wompi, comandos y tareas programadas.
- `backend/storage/app/private/`: almacenamiento privado de Laravel, incluidas las credenciales de Google no versionables.
- `apps-script/`: código fuente de Apps Script, incluidos los guards de cutover.
