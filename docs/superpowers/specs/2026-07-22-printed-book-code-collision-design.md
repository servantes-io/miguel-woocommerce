# Printed-Book / E-book Product-Code Collision — Design

**Date:** 2026-07-22
**Status:** Approved for planning

## Goal

Let a WooCommerce shop sell **printed books** through the Miguel/Servantes platform alongside their **e-book** editions, when both editions share the same identifier (`<slug>`), **without changing any existing eshop data** (SKUs, product records, order history).

## Background

The plugin connects a WooCommerce shop to the Miguel platform. A WooCommerce product is mapped to a Miguel "book" by a **product code**:

- **E-books/audiobooks** get their code from a `[miguel|wosa|audio ...]` shortcode placed in a *Downloadable file* URL. The shortcode `id` is the code (`<slug>`).
- **Fallback:** when a product has no such shortcode, the resolver falls back to the product **SKU** as the code.

The shop now adds **printed books**. A printed book:

- is **not** downloadable, so the shortcode mechanism does not apply, and
- already has its **SKU set to `<slug>`** — the exact string the e-book edition uses as its Miguel code.

Because the resolver falls back to SKU for shortcode-less products, each printed book claims the same code as its e-book twin. The code `<slug>` then maps to **two** products, so it is flagged non-unique. This breaks e-book resolution: `POST /orders` line items referencing `<slug>` fail with `product_code.ambiguous`, and `/product-code-map` reports the code as duplicate.

## Constraints

- **No eshop data changes.** Printed-book SKUs stay `<slug>`. No bulk migration.
- Both the plugin and the Miguel platform side can change (coordinated deploys). Only eshop data is frozen.
- Discriminator confirmed for this shop: **printed books are non-downloadable products; e-books/audiobooks are always downloadable.** `WC_Product::is_downloadable()` reliably separates them.
- This is bespoke to a single eshop — the suffix is an explicit configured value, **not** a hardcoded default.

## Approach

**Prefix/suffix-namespaced print codes (A) + optional per-product override (C), using a suffix.**

The plugin owns a naming convention: a **printed** (non-downloadable) product with SKU `<slug>` is exposed to Miguel as code `<slug><SUFFIX>` (e.g. `harry-potter:print`). The e-book keeps its bare `<slug>` from the shortcode. Because the two codes live in separate namespaces, the ambiguity is gone **by construction** — no `format`/`type` field is needed on order line items.

The suffix is applied **only at extraction/export**. The resolver builds its `code → product_id` map from those same extracted codes, so an inbound order code `harry-potter:print` matches its map key directly. Resolution stays a **pure map lookup**, symmetric on read and write; no stripping logic anywhere.

### Rejected alternatives

- **B — explicit `format`/`type` tuple on line items.** Requires a larger cross-side contract change, restructures the flat `code → id` map into a 2-D key, and — to key a flat map — ends up composing `code+type` into a string anyway, i.e. Approach A with extra steps.
- **C alone — per-product `_miguel_code` meta on every print product.** Manual per-product work across a large catalog; operationally heavy and error-prone. Kept only as an optional override, not the primary mechanism.

## Core component: `Miguel_Product_Code_Source`

A single class answering one question: *"What Miguel code(s) does this product expose, and what is each one?"* It is the **single source of truth** for product→code extraction, replacing the logic currently duplicated across four consumers.

**Return shape** — a list of entries:

```
{
  code:    string,              // exact Miguel-addressable string (suffixed for print)
  book_id: string,              // bare slug
  format:  string,              // 'epub' | 'mobi' | 'pdf' | 'audio' | 'print' | ''
  type:    'digital' | 'print'
}
```

**Rule (priority order):**

1. **Explicit override (C):** product has `_miguel_code` meta → use it **verbatim** as `code` (no suffix, no derivation). `book_id = code`. Escape hatch for oddballs. (`type` follows `is_downloadable()`; `format` may be `''`.)
2. **Digital via shortcode:** for each `[miguel|wosa|audio ...]` download → `code = book_id = shortcode id`, `format` from the shortcode, `type = digital`. *(Unchanged from today.)*
3. **Digital by SKU (legacy):** downloadable product, no shortcode, has SKU → `code = book_id = SKU`, `format = ''`, `type = digital`. *(Unchanged — preserves existing SKU-mapped e-books.)*
4. **Print (new):** **not** downloadable, has SKU, **and a non-empty suffix is configured** → `code = SKU + SUFFIX`, `book_id = SKU`, `format = 'print'`, `type = print`.
5. Otherwise → exposes **nothing** (not addressable).

**Suffix source:** read from a single accessor wrapping the `miguel_print_code_suffix` option, exposed through a `miguel_print_code_suffix` filter for tests/overrides. **Empty suffix ⇒ rule 4 never fires.**

**Why the collision dies even with the suffix unset:** the bare-SKU fallback (rule 3) is gated on `is_downloadable()`. A printed book is not downloadable, so it never reaches rule 3 and never claims a bare `<slug>`. With the suffix empty it simply exposes nothing; with the suffix set it exposes `<slug><SUFFIX>`. The e-book's `<slug>` is never contested.

## The four consumers

All four stop hand-rolling extraction and call `Miguel_Product_Code_Source`.

| # | File | Uses | Change |
|---|------|------|--------|
| 1 | `includes/class-miguel-product-code-resolver.php` | `code` | `get_product_codes_from_product()` delegates to the source; map now contains `<slug>` (e-book) and `<slug><SUFFIX>` (print) as distinct keys. Uniqueness/ambiguity logic unchanged. Powers Orders-create resolution and `/product-code-map`. |
| 2 | `includes/class-miguel-products-api.php` | `book_id`, `format`, `code` | `get_miguel_items_from_product()` emits print entries too, and each `miguel_items` entry gains a **`code`** field so the platform learns the exact string to echo back. Additive; for digital `code == book_id`. |
| 3 | `includes/api/v2/mappers/class-miguel-order-mapper.php` | `code` | Replaces the `is_downloadable()` gate that currently drops physical items, so a print order placed **in WooCommerce** syncs to Miguel with `<slug><SUFFIX>`. Existing bundle logic preserved; leaf extraction delegated to the source. |
| 4 | `includes/class-miguel-orders-api.php` | `code` | Replaces the `is_downloadable()` gate in `collect_products_from_order()`, so `GET /orders` reports print line items too. |

### End-to-end round-trip

```
Discovery:  GET /products → platform sees { code:"harry-potter:print", format:"print" } → pairs its print edition
Inbound:    POST /orders line_item { product_code:"harry-potter:print" } → resolver → print product ✓ (e-book untouched)
Outbound:   print order placed in WooCommerce → mapper emits "harry-potter:print" → Miguel ✓
```

## Settings field

Added to `Miguel_Settings::get_settings()` (`includes/admin/class-miguel-settings.php`):

```php
array(
  'id'      => 'miguel_print_code_suffix',
  'css'     => 'min-width: 350px;',
  'type'    => 'text',
  'title'   => __( 'Printed-book code suffix', 'miguel' ),
  'desc'    => __( 'Appended to a printed book\'s SKU to form its Miguel product code — e.g. ":print" makes SKU "harry-potter" resolve as "harry-potter:print". Leave empty to disable printed-book pairing. Must match the suffix configured on the Miguel platform.', 'miguel' ),
  'default' => '',
),
```

The `miguel_print_code_suffix` filter wraps the option read so code and tests can force a value.

## Edge cases

| Case | Result |
|------|--------|
| E-book + print share slug (normal case) | `slug` → e-book, `slug<SUFFIX>` → print — both unique ✓ |
| Two physical products, same SKU | `slug<SUFFIX>` maps to both → existing `product_code.ambiguous` ✓ |
| Physical product, no SKU, no override | Exposes nothing (not addressable) — intentional |
| Suffix unset/empty | No print codes anywhere; e-books fully unaffected |
| Physical non-book product (merch) | Gets `sku<SUFFIX>` — harmless; platform only pairs what it knows |
| Real slug literally ending in the suffix | Prevented by choosing a suffix containing a character slugs never use (this eshop picks the value) |
| `_miguel_code` override present | Used verbatim; bypasses SKU + suffix derivation |

## Error handling

No new error codes. Print codes flow through the existing resolver and reuse `product_code.not_found` and `product_code.ambiguous` (both HTTP 409) exactly as e-books do. `/product-code-map` keeps reporting `duplicate_count` / `duplicate_codes`, which now surface only genuine SKU-on-two-physical-products clashes rather than the systemic e-book/print collision.

## Testing

TDD throughout. Tests run via `make test-docker` (project convention — do not call `vendor/bin/phpunit` directly).

- **New `tests/unit/test-product-code-source.php`** — the rule table: shortcode digital; digital-by-SKU; print → suffixed; `_miguel_code` override verbatim; no-SKU → nothing; **suffix empty → physical exposes nothing**; e-book + print same slug → two distinct codes.
- **Resolver** (`tests/unit/test-product-code-resolver.php`): `slug` → e-book and `slug<SUFFIX>` → print both resolve unique; two physical same SKU → `ambiguous`.
- **Products API**: physical product yields a `format:"print"` item carrying `code`; digital item gains `code == book_id`.
- **Order Mapper** (`tests/unit/test-order-mapper.php`) + **Orders API GET** (`tests/unit/test-orders-api.php`): a physical line item now produces `slug<SUFFIX>` (previously dropped).
- **Regression**: full suite green — existing e-book / audio / bundle behavior unchanged.

## Rollout (safe, decoupled)

1. Ship plugin with the suffix **empty** → no-op for e-books; collision already gone (physical no longer bare-SKU mapped). Deployable independently.
2. Configure the matching suffix on the Miguel platform side.
3. Set the suffix in WooCommerce → Settings → Miguel → print pairing goes live.

## Out of scope (YAGNI)

- No stock/inventory changes.
- No new order statuses.
- No bulk data migration.
- No changes to existing e-book shortcodes.
