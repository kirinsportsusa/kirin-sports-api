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

The current endpoints are public read-only routes. Later phase modules remain scaffolds and do not register routes.

## Local checks

From the plugin directory, check PHP syntax with:

```sh
find . -name '*.php' -not -path './.git/*' -print0 | xargs -0 -n1 php -l
```

The plugin requires WordPress and WooCommerce for runtime REST checks. No external dependencies are used.
