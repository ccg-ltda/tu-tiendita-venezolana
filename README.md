# Tu Tiendita Venezolana

Aplicación de comercio electrónico con frontend en React y backend en Laravel. Su flujo principal de datos es:

```text
React → Laravel → snapshot/caché local → Apps Script / Google Sheets
```

Google Sheets es la fuente principal de datos del proyecto. El funcionamiento normal no requiere MySQL.

## Arquitectura y funcionamiento

### Productos

El catálogo público se entrega desde un snapshot y caché privados de Laravel, por lo que las consultas del cliente no dependen directamente de Apps Script. Google Sheets sigue siendo la fuente principal de productos y Laravel cuenta con sincronización hacia esa fuente.

El administrador puede crear y editar productos, modificar su inventario y activarlos o desactivarlos. Si una operación no puede sincronizarse de inmediato, el sistema conserva localmente la operación pendiente para procesarla posteriormente.

### Pedidos

Los pedidos se persisten mediante Apps Script y Google Sheets. El panel administrativo consume un listado desde la caché privada de Laravel, que se actualiza automáticamente mediante el scheduler. Ante fallos transitorios del servicio externo, puede mantenerse temporalmente el último estado válido.

El detalle de un pedido usa la caché cuando está disponible; si es necesario, se consulta únicamente el pedido seleccionado. El administrador puede actualizar el estado operativo de los pedidos según las transiciones permitidas por el sistema.

### Pagos con Wompi

Wompi se utiliza para el checkout y el procesamiento de pagos. Laravel prepara el proceso de pago, recibe los eventos de Wompi en su webhook, procesa el estado resultante y coordina su persistencia. También existe consulta del estado de pago y manejo automático de reservas vencidas.

### Autenticación administrativa

La autenticación administrativa no depende de MySQL. Se configura con variables de entorno y Laravel administra la sesión.

## Requisitos

- Node.js 22.13 o superior y npm.
- PHP 8.2 o superior.
- Composer.
- Acceso configurado de forma privada a Google Sheets, Apps Script y Wompi.

## Preparación inicial

1. Clone el repositorio.
2. Configure los archivos `.env` necesarios sin incorporar secretos al repositorio.
3. Instale las dependencias del frontend y del backend.
4. Genere el snapshot inicial del catálogo y la primera página de la caché administrativa de pedidos:

```bash
cd backend
php artisan products:refresh-catalog
php artisan orders:refresh-admin-cache --page=1 --per-page=25
```

## Variables de entorno

Configure el archivo `backend/.env` a partir de `backend/.env.example`. No incluya valores reales, credenciales ni archivos de secretos en el control de versiones.

Las variables propias de la integración que utiliza el código son:

- `APP_KEY`
- `APP_URL`
- `FRONTEND_URL`
- `APPS_SCRIPT_URL`
- `APPS_SCRIPT_API_KEY`
- `GOOGLE_SHEETS_SPREADSHEET_ID`
- `WOMPI_ENVIRONMENT`
- `WOMPI_PUBLIC_KEY`
- `WOMPI_PRIVATE_KEY`
- `WOMPI_INTEGRITY_SECRET`
- `WOMPI_EVENTS_SECRET`
- `ADMIN_EMAIL`
- `ADMIN_PASSWORD_HASH`

La integración directa con Google Sheets también requiere las credenciales privadas de la cuenta de servicio que usa el backend. Deben aprovisionarse fuera del repositorio.

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

Durante el desarrollo, `schedule:work` debe permanecer ejecutándose para que Laravel ejecute las tareas programadas, incluidas las actualizaciones del catálogo y del listado administrativo de pedidos.

### Webhooks de Wompi en local

Cuando sea necesario recibir webhooks en el entorno local:

```bash
ngrok http 8000
```

ngrok expone temporalmente el backend local para recibir webhooks. La URL pública puede cambiar al reiniciar el túnel.

## Google Sheets y Apps Script

Google Sheets funciona como almacenamiento principal de la información del proyecto. Apps Script actúa como capa de integración para determinadas operaciones. El código fuente de Apps Script está versionado en `apps-script/`.

Mantenga las credenciales, secretos y la API key de Apps Script fuera del repositorio; la API key real debe configurarse de manera privada.

## API

Las rutas actuales del backend son las siguientes. Los endpoints administrativos requieren una sesión administrativa activa.

| Método | Ruta | Propósito |
| --- | --- | --- |
| `GET` | `/api/products` | Obtiene el catálogo público desde el snapshot/caché local. |
| `POST` | `/api/payments/wompi/prepare` | Prepara un checkout de Wompi. |
| `GET` | `/api/payments/wompi/status` | Consulta el estado de un pago preparado. |
| `POST` | `/api/webhooks/wompi` | Recibe eventos de pago enviados por Wompi. |
| `GET` | `/api/auth/csrf-token` | Obtiene el token CSRF para la sesión actual. |
| `POST` | `/api/auth/login` | Inicia la sesión administrativa. |
| `GET` | `/api/auth/me` | Devuelve la identidad de la sesión administrativa. |
| `POST` | `/api/auth/logout` | Cierra la sesión administrativa. |
| `GET` | `/api/admin/products` | Consulta el catálogo para administración. |
| `POST` | `/api/admin/products` | Crea un producto. |
| `PATCH` | `/api/admin/products/{productId}` | Edita un producto, incluido su inventario. |
| `PATCH` | `/api/admin/products/{productId}/status` | Activa o desactiva un producto. |
| `GET` | `/api/admin/orders` | Consulta el listado administrativo de pedidos. |
| `GET` | `/api/admin/orders/{id}` | Consulta el detalle de un pedido. |
| `PATCH` | `/api/admin/orders/{id}/status` | Actualiza el estado operativo de un pedido. |

## Producción

En desarrollo puede utilizarse `php artisan schedule:work`. En producción, las tareas programadas deben mantenerse ejecutándose mediante el mecanismo de procesos o tareas programadas que proporcione el servidor o hosting.

Antes de habilitar tráfico, ejecute los comandos de preparación inicial para disponer de un catálogo y un listado administrativo de pedidos actualizados.

## Estructura del proyecto

- `src/`: aplicación React, vistas y servicios del frontend.
- `backend/`: aplicación Laravel, API, comandos y tareas programadas.
- `apps-script/`: código fuente de la integración con Google Apps Script.
- `public/`: recursos estáticos del frontend.
