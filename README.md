# Opryx ERP — by Sheikh IT

Multi-tenant SaaS ERP for D2C brands that make and sell their own products:
orders with stock reservation, courier handover, returns + QC, raw materials,
BOM recipes, production runs and a full stock ledger.

Live (first tenant): https://erp.sheikhit.com.bd · Future SaaS domain: opryx.app

## Stack

- Laravel 12 (PHP 8.2+) · Inertia.js 2 · React 18 + TypeScript · Tailwind CSS 3
- MySQL / MariaDB (production) — also tested on PostgreSQL
- One code base, one database; every company (tenant) is isolated by `tenant_id`,
  composite foreign keys and a fail-closed global scope. The company is chosen by
  the request **domain** (`tenant_domains` table), never by user input.

## Modules (Phase 1)

| Screen | Route | Roles |
|---|---|---|
| Dashboard | `/dashboard` | all |
| Orders (list / new / detail, confirm, cancel, handover, delivered) | `/orders` | sales, warehouse |
| Returns & QC (barcode scan, pass / damaged) | `/returns` | qc, warehouse |
| Products & stock — colour × size grid, + Add colour / + Add size | `/products` | sales, warehouse, manufacturer |
| Stock In (scan) — scan style barcode, type qty per colour/size | `/stock-in` | warehouse |
| Barcode labels (EAN-13, 50×30 / 38×25 mm) | `/labels` | sales, warehouse, manufacturer |
| Stock ledger | `/stock-ledger` | sales, warehouse, manufacturer |
| Raw materials | `/raw-materials` | manufacturer, warehouse |
| BOM / recipes | `/boms` | manufacturer |
| Production | `/manufacturing` | manufacturer |
| Team & roles | `/team` | owner |

`owner` can do everything; `manager` everything except Team.
A JSON API for integrations lives under `/api` (see `routes/opryx.php`).

## Barcodes

Every variant (style + colour + size) gets its own EAN-13 (`2` + tenant + sequence + check digit);
the style gets one too. Custom manufacturer barcodes can replace generated ones.
Courier handover and return QC require every unit to be scanned (`scans` are verified
server-side against the order lines).

## Stock rules (the core)

| Event | Available | Reserved | Transit | Damaged |
|---|---:|---:|---:|---:|
| Production of q | +q | | | |
| Order saved | −q | +q | | |
| Order cancelled | +q | −q | | |
| Handed to courier | | −q | +q | |
| Delivered | | | −q | |
| Return QC pass | +q | | −q | |
| Return QC damaged | | | −q | +q |

All writers go through `App\Services\InventoryService`, lock the tenant row,
are idempotent (UUID keys) and append to `inventory_movements`.

## Install on shared / cloud hosting (no SSH needed)

1. Upload the release so the project root is the web folder (a root `.htaccess`
   forwards everything into `public/`), or point the domain at `public/`.
2. Create an empty MySQL database.
3. Open `https://your-domain/install.php` → enter DB details, company and owner login.
   The installer writes `.env`, runs migrations, creates the company and locks itself.

CLI alternatives: `php artisan migrate --force`,
`php artisan opryx:tenant "Company" erp.example.com owner@example.com`,
`php artisan opryx:domain {tenant_id} client.opryx.app`.

## Development

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan migrate
php artisan opryx:tenant "Demo" localhost you@example.com --password=secret123
npm run dev & php artisan serve       # open http://localhost:8000
vendor/bin/phpunit                     # needs MySQL/PostgreSQL (not SQLite)
```

Built assets in `public/build` are committed on purpose: the hosting server has no Node.js build step.
Run `npm run build` before every commit that touches `resources/js`.
