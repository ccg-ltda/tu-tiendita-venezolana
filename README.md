# Tu Tiendita Venezolana

Tu Tiendita Venezolana is a React storefront backed by Laravel. Its runtime data flow is:

React -> Laravel -> local catalog snapshot -> Apps Script -> Google Sheets

Wompi handles payment checkout and sends payment events to Laravel; Laravel persists checkout state through Apps Script. MySQL, XAMPP, SQL migrations, and database seeders are not required for normal operation.

## Catalog readiness

Google Sheets remains the source of truth. Laravel serves product listings from a private,
validated snapshot at `storage/app/private/catalog/products.json` and its local file cache;
product HTTP requests never wait for Apps Script.

Before a new instance receives catalog traffic, create a valid snapshot:

```bash
cd backend
php artisan products:refresh-catalog
```

Then run Laravel's scheduler in production (`php artisan schedule:run` from cron every minute,
or `php artisan schedule:work`). It refreshes the catalog every five minutes. If a refresh
fails, Laravel keeps serving the last valid snapshot. A missing or invalid initial snapshot
returns a generic 503 with `Retry-After`; it is a catalog readiness failure, not a reason for a
client request to call Apps Script.

## Administrative orders readiness

Administrator order lists are served from an encrypted private Laravel cache. Before a new
instance receives administrative traffic, prewarm the first page:

```bash
cd backend
php artisan orders:refresh-admin-cache --page=1 --per-page=25
```

The scheduler refreshes that page every minute. Entries are fresh for 60 seconds and may be
served as a last-valid stale fallback for no more than five minutes if Apps Script is
temporarily unavailable. Order detail is intentionally not derived from the list because it
contains additional customer PII; prewarm a detail only when needed with
`php artisan orders:refresh-admin-detail {orderId}`. No order data is exposed as a storage URL.

## Local development

Requirements: Node.js, npm, PHP 8.2 or newer, Composer, and configured Apps Script, Wompi, and administrator environment variables.

Start the frontend:

```bash
npm install
npm run dev
```

Start Laravel from `backend/`:

```bash
php artisan serve
```

Vite proxies `/api` and `/storage` to Laravel at `http://127.0.0.1:8000` during local development.

## API

- `GET /api/products`: public catalog from the local snapshot/cache.
- `POST /api/payments/wompi/prepare`: prepares a checkout through Apps Script.
- `GET /api/payments/wompi/status`: reads checkout status through Apps Script.
- `POST /api/webhooks/wompi`: receives verified Wompi events.
- `POST /api/auth/login`, `GET /api/auth/me`, `POST /api/auth/logout`: administrator session endpoints.
- `GET /api/admin/products`: complete catalog for an authenticated administrator, from the same local snapshot/cache.
- `GET /api/admin/orders` and `GET /api/admin/orders/{id}`: authenticated order reads from the encrypted private cache; Apps Script is used only by refresh commands.

Administrative product writes are intentionally disabled while their Sheets workflow is not implemented.
