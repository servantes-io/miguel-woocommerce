# Miguel API v2 Migration — Design

**Date:** 2026-06-15
**Status:** Approved for planning
**Branch:** `migrate-miguel-api-v2`

## Goal

Migrate all outbound communication from the WooCommerce plugin to the Miguel API
from v1 to v2, using a dedicated typed v2 client built from plain PHP value
objects (DTOs) and mappers. Hard cutover — no v1 code remains.

## Background

The plugin makes outbound calls to the Miguel API and also exposes inbound REST
routes that Miguel calls into WooCommerce. **Only the outbound calls are in
scope.** The inbound REST routes (`*-api.php` classes) are already v2-era and are
explicitly out of scope.

### Current outbound calls (all in `includes/class-miguel-api.php`)

| Method | v1 endpoint | Caller |
|---|---|---|
| `generate($book, $format, $args)` | `POST v1/generate_{format}/{book}` | `Miguel_Download::serve_file()` |
| `submit_order($data)` | `POST v1/orders` | `Miguel_Orders::sync_order()` |
| `delete_order($code)` | `DELETE v1/orders/{code}` | `Miguel_Orders::sync_order()` |
| `connect_woocommerce(...)` | `POST v2/eshop/woocommerce/connect` | `admin/class-miguel-settings.php` |

`connect_woocommerce()` already targets v2 and its body already matches the v2
`ConnectRequest` schema exactly — but it will be moved into the new typed client
so that **all** outbound communication lives in one place.

### v2 endpoint mapping (confirmed against swagger)

Swagger source: `https://miguel-test.servantes.cz/v2/swagger/v2/swagger.json`
Base server: `https://miguel.servantes.cz` (env-specific URLs unchanged).
Auth: `Authorization: Bearer <token>` (unchanged from v1).

| v1 | v2 |
|---|---|
| `POST v1/generate_{format}/{book}` | `POST v2/product-variants/{code}/watermarked-file` |
| `POST v1/orders` | `POST v2/orders` |
| `DELETE v1/orders/{code}` | `DELETE v2/orders/{code}` |
| `POST v2/eshop/woocommerce/connect` | unchanged |

## Decisions

1. **Architecture:** Approach C — typed v2 client with DTO value objects + mappers.
2. **Model scope:** only the 4 endpoints the plugin actually calls (plus their
   nested objects). No full SDK.
3. **DTO style:** plain value objects — typed constructor params, docblock-typed
   private properties, `to_array()` producing exact v2 JSON. **PHP 7.2-safe**
   (no typed class properties, no constructor promotion).
4. **Download UX:** on-demand only. The `woocommerce_download_product` hook keeps
   minting a fresh watermarked link per click. Order sync sends
   `sendEmail = "disable"` so Miguel does not also email links.
5. **Cutover:** hard cutover. No v1 code, no toggle, no fallback.
6. **Pricing:** `soldPrice` continues to be sent **excluding VAT** (per-unit,
   `get_item_total($item, false, false)`), preserving current behavior. See
   "Known divergence" below.
7. **Addresses:** include `billingAddress` and `shippingAddress` as
   `OrderAddressModel` objects in the order payload (additive).
8. **`Miguel_Request`:** folded into the watermark mapper and removed.
9. **`connect`:** moved into the new client; `settings.php` re-wired.

## Architecture

### New file layout

```
includes/api/v2/
  class-miguel-v2-client.php                      Miguel_V2_Client
  dto/
    class-miguel-v2-watermark-user.php            Miguel_V2_Watermark_User
    class-miguel-v2-order-address.php             Miguel_V2_Order_Address
    class-miguel-v2-order-create-item.php         Miguel_V2_Order_Create_Item
    class-miguel-v2-order-create.php              Miguel_V2_Order_Create
    class-miguel-v2-watermarked-file-request.php  Miguel_V2_Watermarked_File_Request
    class-miguel-v2-connect-request.php           Miguel_V2_Connect_Request
  mappers/
    class-miguel-order-mapper.php                 Miguel_Order_Mapper
    class-miguel-watermark-mapper.php             Miguel_Watermark_Mapper
```

All files are registered via `include_once` in `Miguel::includes()`
(`includes/class-miguel.php`), following the existing manual-loading pattern.

### DTOs (value objects)

Each DTO: typed constructor parameters (nullable where the field is nullable),
docblock-typed private properties, and a `to_array()` that emits the exact v2
JSON. Optional/nullable fields are omitted from the array when null, except where
the v2 schema lists the field as required-but-nullable (those are emitted as
`null`).

**`Miguel_V2_Watermark_User`** (`WatermarkUser`)
- Required: `email` (string), `language` (string)
- Optional/nullable: `id` (string|null — e-shop user id), `name` (string|null),
  `address` (string|null)
- `to_array()` → `{ id, name, address, email, language }`

**`Miguel_V2_Order_Address`** (`OrderAddressModel`, all nullable)
- `fullName, company, address1, address2, city, state, zip, country, phone`
- `to_array()` → object with the above keys

**`Miguel_V2_Order_Create_Item`** (`OrderCreateItem`)
- Required: `code` (string), `soldPrice` (float, per-unit excl VAT),
  `quantity` (int)
- Optional/nullable: `deliveryMethodId` (int|null)
- `to_array()` → `{ code, soldPrice, quantity, deliveryMethodId? }`

**`Miguel_V2_Order_Create`** (`OrderCreate`)
- `code` (string), `user` (Miguel_V2_Watermark_User), `purchasedAt` (string|null,
  ISO-8601), `currencyCode` (string), `items` (Miguel_V2_Order_Create_Item[]),
  `sendEmail` (string, `"disable"`), `eshopId` (string|null),
  `eshopCreatedAt` (string|null, ISO-8601), `eshopUpdatedAt` (string|null,
  ISO-8601), `source` (string|null), `socialDrmContent` (string|null),
  `billingAddress` (Miguel_V2_Order_Address|null),
  `shippingAddress` (Miguel_V2_Order_Address|null)
- `to_array()` → full `OrderCreate` JSON (nested DTOs serialized via their own
  `to_array()`)

**`Miguel_V2_Watermarked_File_Request`** (`GetWatermarkedFileFromVariantRequest`)
- Required: `target` (string, `FileFormat`), `userInfo`
  (Miguel_V2_Watermark_User), `purchaseDate` (string, ISO-8601)
- `orderInfo` (object): `{ code, currencyCode, soldPrice }` (all required when
  present; `soldPrice` per-unit excl VAT)
- `to_array()` → `{ target, userInfo, purchaseDate, orderInfo }`
- The old v1 `result: "download_link"` field is dropped.

**`Miguel_V2_Connect_Request`** (`ConnectRequest`)
- Required: `wcVersion, moduleVersion, baseUrl, baseUri` (all strings)
- `to_array()` → `{ wcVersion, moduleVersion, baseUrl, baseUri }`

### `Miguel_V2_Client`

Constructed with `($url, $token)`. Owns the HTTP transport (`wp_remote_post`,
`wp_remote_request`), the shared headers (`Content-Type`, `Authorization`,
`Accept-Language`), the user-agent string, and v2 `IProblem` error parsing.

Methods:
- `get_watermarked_file($variant_code, Miguel_V2_Watermarked_File_Request $req)`
  → `POST v2/product-variants/{urlencode($variant_code)}/watermarked-file`.
  Success: 200. Returns the decoded `{ downloadUrl, downloadExpiresAt, task }`
  body (as array/object) or `WP_Error`.
- `create_order(Miguel_V2_Order_Create $order)` → `POST v2/orders`. Success:
  200 or 201. Logs and returns `WP_Error` for others (including 409 conflict).
- `delete_order($code)` → `DELETE v2/orders/{urlencode($code)}`. Success: 204 or
  404 (idempotent). Logs and returns `WP_Error` for others.
- `connect(Miguel_V2_Connect_Request $req)` → `POST v2/eshop/woocommerce/connect`.
  Success: 200.

**Error handling:** on a non-success HTTP code the client parses the v2
`IProblem` body (`{ status, code, title, detail, errors, traceId }`) and produces
a `WP_Error` whose message combines `title` and `detail`. Transport-level
`WP_Error` from `wp_remote_*` is passed through.

### Mappers

**`Miguel_Order_Mapper`** — `WC_Order → Miguel_V2_Order_Create | null`
- Absorbs the current `Miguel_Orders::prepare_order_data()` field-building.
- Builds `user` from order billing data + e-shop user id (id is null for guests,
  matching the existing default-user-id fix).
- Builds `items[]` from Miguel product codes; **`quantity` is threaded from
  `$item->get_quantity()`**; `soldPrice` is the per-unit price excl VAT
  (`get_item_total($item, false, false)`), unchanged from today.
- Builds `billingAddress` and `shippingAddress` from the WC order.
- Sets `sendEmail = "disable"`, `source = null`, `socialDrmContent = null`,
  `eshopId = code = strval(order id)`, `eshopCreatedAt = date_created`,
  `eshopUpdatedAt = date_modified`, `purchasedAt = paid-or-created date`.
- Returns `null` when the order has no Miguel items (caller skips the request).
- The Miguel-code extraction and bundle price-proportioning logic
  (`get_miguel_products_from_item`, `get_miguel_products_from_bundle`,
  `extract_*_miguel_codes`) stays available to the mapper (moved or shared from
  `Miguel_Orders`); behavior is preserved exactly, now also producing `quantity`.

**`Miguel_Watermark_Mapper`** — `WC_Order + WC_Order_Item + Miguel_File →
Miguel_V2_Watermarked_File_Request`
- Replaces `Miguel_Request::to_array()`.
- `target` = `$file->get_format()`; `userInfo` from order; `purchaseDate` from
  the order's paid date; `orderInfo` = `{ code: order id, currencyCode,
  soldPrice: per-unit excl VAT }`.
- Validity guard (purchase date present) preserved from `Miguel_Request::is_valid()`.

### Consumers rewired

- **`Miguel_API`** → reduced to configuration/factory concerns: keep the static
  env/url/token/enabled helpers and their backward-compat wrappers (other code
  and tests depend on them). Instance transport methods (`generate`,
  `submit_order`, `delete_order`, `connect_woocommerce`, `post`, `delete`,
  `build_url`, `user_agent`) are removed. Add a factory that builds a
  `Miguel_V2_Client` from the current API configuration.
- **`Miguel_Download::serve_file()`** → builds the request via
  `Miguel_Watermark_Mapper`, calls `$client->get_watermarked_file()`, reads
  `downloadUrl`, redirects on success, surfaces `IProblem` errors via the
  existing error handler.
- **`Miguel_Orders::sync_order()`** → uses `Miguel_Order_Mapper` +
  `$client->create_order()` / `$client->delete_order()`. The existing
  change-hash dedupe (`has_order_data_changed`, `store_order_hash`) is preserved.
- **`Miguel_Request`** → removed; folded into `Miguel_Watermark_Mapper`.
  `Miguel_File` is retained unchanged.
- **`admin/class-miguel-settings.php`** → connect call re-wired to
  `$client->connect(new Miguel_V2_Connect_Request(...))`.

## Data flow

**Download (on-demand):** customer clicks download → `woocommerce_download_product`
→ `Miguel_Download::download()` → `serve()` → `serve_file()` →
`Miguel_Watermark_Mapper` builds DTO → `Miguel_V2_Client::get_watermarked_file()`
→ Miguel returns `{ downloadUrl }` → redirect.

**Order sync:** order status change → `Miguel_Orders::sync_order()` → if
deletable state → `Miguel_V2_Client::delete_order()`; else `Miguel_Order_Mapper`
→ `Miguel_V2_Client::create_order()`. Dedupe hash stored on success.

**Connect:** admin saves settings → `settings.php` →
`Miguel_V2_Client::connect()`.

## Error handling

- v2 `IProblem` (`{ status, code, title, detail, errors, traceId }`) is parsed
  into `WP_Error` messages by the client.
- Per-endpoint accepted status codes: watermarked-file 200; create order 200/201
  (409 logged as error); delete order 204/404 (idempotent); connect 200.
- `Miguel::log()` is used for failures, matching current logging behavior.

## Testing

Run via `make test-docker` (Docker, PHP 8.3 test image). Mock outbound HTTP with
`Miguel_Helper_HTTP` (the `pre_http_request` filter), asserting request URL,
method, body, and headers.

- **DTO unit tests:** each DTO's `to_array()` produces the exact expected v2 JSON,
  including null-omission rules and nested-DTO serialization.
- **Mapper tests:** `Miguel_Order_Mapper` from WC order fixtures → expected
  `OrderCreate` array (covering single product, bundle proportioning, quantity,
  billing/shipping addresses, guest = null user id, no-Miguel-items → null).
  `Miguel_Watermark_Mapper` → expected request array.
- **Client tests:** each method hits the correct v2 URL with correct
  body/headers; success codes accepted; `IProblem` → `WP_Error`.
- **Integration tests:** `Miguel_Download` redirects on `downloadUrl`;
  `Miguel_Orders::sync_order()` calls create/delete correctly and preserves
  dedupe.
- `tests/unit/test-request.php` is replaced by a watermark-mapper test.
- `tests/unit/test-api.php` updated for the reduced `Miguel_API` (config helpers).

## Known divergence (non-blocking)

The v2 schema documents `OrderCreateItem.soldPrice` and the watermarked-file
`orderInfo.soldPrice` as "Sold price after applying discounts, taxes, etc."
Per decision, the plugin continues to send the price **excluding VAT** to
preserve current behavior. If royalty/pricing figures look off after rollout,
confirm the intended tax semantics with the Miguel API team.

## Out of scope

- Inbound REST routes the plugin exposes (`*-api.php`).
- New v2 capabilities not currently used (delivery-method sync, price sync,
  product/variant management, reviewers, admin endpoints).
- Any v1 fallback or version toggle.
```
