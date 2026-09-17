# UPSELL — shopping assistant platform

Multi-tenant widget for stores (WooCommerce first, Shopify later). The plan, in Hebrew, is
`docs/WORK-PLAN.md`; read the relevant section before building a phase.

## Layout

- `apps/api` — Laravel 13, Filament 5, Octane (FrankenPHP), PHPUnit 12, Pint.
- `packages/*` — language-neutral JSON Schemas shared by API, widget and plugin (npm workspaces).
- `docs/ADR` — decisions. `docs/runbooks` — how-tos, including the Railway service settings.
- Railway services are configured through the Railway API/dashboard, not files: Railway deprecated
  `railway.json` config-as-code, and its replacement (`.railway/railway.ts`) is applied only via
  `railway config apply`. A service cannot be managed by both. See `docs/runbooks/deploy-railway.md`.

## The kernel and modules (apps/api)

- `app/Core` is the kernel. It must never reference a module.
- Every feature is a module in `app/Modules/{Name}` with a `module.json` declaring `requires`,
  `features` (flags) and `settings` (typed, bounded caps). Create one with
  `php artisan make:module Name --requires=Tenancy`.
- A module may import another module only if it lists it in `requires`, and only from its
  `Contracts`, `Models`, `Enums` or `Events` namespaces. `tests/Architecture` enforces this.
- Filament screens go in `app/Modules/{Name}/Filament/{Operator|Merchant}/{Resources|Pages|Widgets}`
  and are discovered automatically. No panel provider edits.
- Module folders: `Database/Migrations`, `Database/Factories` (PSR-4 case matters on Linux),
  `lang/{he,en}`, `resources/views`, `routes/api.php` (prefixed `/api/v1`), `routes/web.php`, `Tests`.
- Shop-owned models use `App\Core\Tenancy\BelongsToTenant`. Queries without a shop in
  `TenantContext` throw. Cross-shop code uses `TenantContext::runUnscoped()` on purpose.
- Read flags and caps through `App\Core\Facades\Features` / `Settings`, never hardcode a cap.
- Every UI string goes through module translations in both `he` and `en`. `php artisan i18n:check`
  fails on a missing key and on a declared flag or setting without a label.
- Business logic lives in single-purpose `Actions`. Controllers, commands and Filament call them.

## WooCommerce plugin (plugins/woocommerce)

- Plain WordPress PHP, no Composer runtime deps, minimum PHP 8.1 (CI lints on 8.1: no readonly
  classes, no typed class constants). Namespace `Rega\`, text domain `rega`, REST `rega/v1`.
- Read-only by design: no write routes, nothing about customers, orders or users. ADR 0005.
- Translations: `languages/rega-he_IL.l10n.php` (WP 6.5+ PHP format). `php bin/i18n.php check`.
- Integration tests boot real WordPress + WooCommerce in Playground:
  `node tests/playground/run.mjs` (about 3-5 minutes; `REGA_KEEP=1` leaves the site running,
  login admin / password). Zip: `php bin/build.php` -> `dist/rega-<version>.zip`.
- From Git Bash, Playground VFS paths need `MSYS_NO_PATHCONV=1`; `run.mjs` avoids the issue.

## Commands (run from apps/api)

```sh
composer check                 # pint --test, i18n:check, phpunit
php artisan module:list
php artisan admin:operator you@example.com
```

On this Windows machine PHP comes from Herd. From Git Bash use
`/c/Users/user/.config/herd/bin/php84/php.exe` directly; `php` resolves only in PowerShell.

## Gotchas

- `AuthenticateShopKey` implements `AuthenticatesRequests` so Laravel runs it before
  `ThrottleRequests`; otherwise the per-shop rate limit silently becomes per-IP.
- Postgres refuses `FOR UPDATE` with aggregates: lock the parent row, then count.
- Tests run on SQLite locally and on Postgres + pgvector in CI. Tests that create their own
  tables must drop them in tearDown.
- AI rules from the plan: OpenAI's top model writes visitor-facing text; Claude models do
  analysis (Haiku for bulk, in Batch). No LLM call on page load, ever.
