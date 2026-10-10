# Kirin Sports API

WordPress plugin API layer for Kirin Sports USA. The API reads WordPress and WooCommerce data and does not change registration, product access, schedule, checkout, or payment systems.

## API namespace

`/wp-json/kirin/v1`

## Phase 1 endpoints

- `GET /app` — existing plugin and feature information.
- `GET /categories` — categories containing published, visible, purchasable products. Counts reflect those products; parent categories are included for products assigned to child categories.
- `GET /products` — published, visible, purchasable WooCommerce products.
- `GET /products?category=snow-tickets` — products filtered by a product category slug. An unknown category returns HTTP 400.
- `GET /products/{id}` — one published, visible, purchasable product. Unavailable products return HTTP 404.
- `GET /programs` — published products in the WooCommerce `programs` category, whether or not a schedule is enabled.

Product responses include product identifiers and descriptions, current/regular/sale prices, formatted price and currency, image, category summaries, stock status, and the Kirin registration-required flag. Program responses retain their existing fields and optional schedule structure.

## Phases 2–5 endpoints

- `GET /me` — the authenticated WordPress user's basic account information.
- `GET /players` — player registration occurrences from the customer's processing/completed orders.
- `GET /registrations` — the customer's registration history.
- `GET /schedule` — the customer's training schedule.
- `GET /orders` — paginated customer order history, including stored totals, fees, and refunds.

These routes require an authenticated WordPress user. Orders are scoped to the current customer's ID; mobile token authentication is not implemented.

## Phase 6 News API (local implementation; awaiting live testing)

- `GET /news` — all published, publicly viewable, non-password-protected standard WordPress posts, including posts assigned to the default category.
- `GET /news?page=1&per_page=20&category=ID` — paginated news, optionally filtered by any valid WordPress post-category ID. Maximum `per_page` is 100; invalid parameters return HTTP 400.
- `GET /news/{id}` — article summary plus conservative sanitized HTML. Inaccessible articles return HTTP 404.
- `GET /news/categories` — all existing WordPress post categories, including empty categories, sorted by name ascending. The configured WordPress default category is excluded from navigation. Newly created categories appear automatically; no category names, IDs, or slugs are hardcoded.

News routes are public and read-only. Dates use UTC `YYYY-MM-DDTHH:mm:ssZ`, or `null` when unavailable. Articles sort by publication date and ID descending without sticky-post promotion. Featured images use medium size for lists and large for details, or `null` when unavailable.

Titles and excerpts are decoded plain text. Missing excerpts use safely extracted article text trimmed to 55 words. Article HTML excludes scripts, forms, iframes, unsafe attributes, and unsafe URL protocols. Shortcode callbacks and dynamic blocks are not executed. Passive Avada layout/text wrappers retain their prose; unsupported content is omitted. The public article URL is the fallback for full theme-dependent rendering.

The WooCommerce `/categories` endpoint is separate from News navigation and remains unchanged. The teams module remains an inert scaffold.

## Local checks

From the plugin directory, check PHP syntax with:

```sh
find . -name '*.php' -not -path './.git/*' -print0 | xargs -0 -n1 php -l
```

The plugin requires WordPress and WooCommerce for runtime REST checks. No external dependencies are used.
