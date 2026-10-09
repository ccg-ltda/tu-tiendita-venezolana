# Tu Tiendita Venezolana

Aplicación de comercio electrónico para Tu Tiendita Venezolana.

## Arquitectura actual

- Frontend: React + Vite.
- Backend: Laravel.
- Persistencia definitiva: MySQL/MariaDB.

El catálogo público, la administración, el checkout, las reservas, los pedidos y la auditoría usan persistencia SQL. Wompi procesa los pagos y Laravel recibe sus eventos mediante webhook. Las notificaciones de pedidos se entregan mediante SMTP a través de un outbox SQL.

## Módulos principales

- Productos.
- Categorías y subcategorías.
- Promociones.
- Cupones.
- Pedidos y reservas de checkout.
- Administradores y sesiones.
- Auditoría e historial administrativo.
- Pagos con Wompi.
- Notificaciones SMTP.

`Promociones` es una agrupación virtual del storefront; no es una categoría administrable.

## Desarrollo local

Instale las dependencias del frontend desde la raíz y las del backend desde `backend/`. Configure `backend/.env` a partir de `backend/.env.example` sin versionar secretos.

En terminales separadas:

```text
npm run dev
php artisan serve
php artisan schedule:work
```

Los dos últimos comandos se ejecutan dentro de `backend/`.

## Scheduler de producción

Ejecute el scheduler cada minuto:

```text
php artisan schedule:run
```

Tareas programadas actuales:

```text
wompi:release-expired-reservations
wompi:sync-pending-payment-events
orders:send-pending-notifications
```

## Pruebas y build

Desde `backend/`:

```text
php artisan test
vendor\bin\phpunit -c phpunit.mysql.xml
```

Desde la raíz:

```text
npm run build
```

## Integraciones

- Wompi: configure las claves y secretos únicamente en `backend/.env`.
- Correo: configure SMTP y los destinatarios administrativos únicamente en `backend/.env`.
- Base de datos: use MySQL o MariaDB conforme a las variables `DB_*` de `backend/.env.example`.

## Estructura

- `src/`: aplicación React.
- `public/`: recursos estáticos del frontend.
- `backend/`: API Laravel, dominio de negocio, migraciones, comandos y scheduler.
- `.github/`: automatización de despliegue del frontend.
