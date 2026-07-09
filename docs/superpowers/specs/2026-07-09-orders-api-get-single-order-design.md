# Design: Get a single order by ID (`GET /orders/{id}`)

**Date:** 2026-07-09
**Status:** Approved
**Component:** `Miguel_Orders_Api`

## Summary

Add a new public REST endpoint `GET /wp-json/miguel/v1/orders/{id}` to the existing
`Miguel_Orders_Api` class. It returns a **richer detail view** of a single WooCommerce
order: the same base fields as a list item plus order totals, structured billing/shipping
addresses, payment & shipping metadata, and a full line-item breakdown.

The endpoint reuses the existing bearer-token authentication and the existing
`format_order()` method so the shared fields can never drift between the list and the
single-order views.

## Motivation

`Miguel_Orders_Api` currently exposes only `GET /orders?updated_since=…` (a list of orders
modified since a date). There is no way to fetch the current state of one specific order by
its ID. This endpoint fills that gap and provides more per-order detail than the list view,
which only carries Miguel-relevant products.

## Scope

**In scope**

- New route `GET /orders/(?P<id>\d+)` on `Miguel_Orders_Api`.
- A `format_order_detail()` formatter (plus small private helpers) that extends the base
  order shape with the richer field groups.
- Unit tests in `tests/unit/test-orders-api.php`.
- OpenAPI documentation in `docs/openapi.yaml`.

**Out of scope**

- Any change to the existing list endpoint's response shape.
- Fixing the pre-existing OpenAPI/`user.address` mismatch (the list documents `user.address`
  as a structured object while the code returns a flattened string). Not touched here.
- Pagination, filtering, or new query parameters on either endpoint.

## Architecture

All new code lives in `Miguel_Orders_Api` (Approach A — chosen over extracting a shared
formatter class or moving logic into `Miguel_Order_Utils`). This honors the task ("add to
`Miguel_Orders_Api`"), keeps risk to the working list endpoint at zero, and reuses
`format_order()` directly.

### Route registration

`register_routes()` gains a second `register_rest_route()` call:

```php
register_rest_route(
    'miguel/v1',
    '/orders/(?P<id>\d+)',
    array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => array( $this, 'get_order' ),
        'permission_callback' => array( $this, 'validate_api_access' ),
    )
);
```

This does not conflict with the existing routes:

- `GET  /orders` (list, this class)
- `POST /orders` (create, `Miguel_Order_Create_Api`)
- `PATCH /orders/{id}/status` (`Miguel_Order_Status_Update_Api`)
- `GET  /orders/{id}` (new)

`/orders/42` matches the new route; `/orders/42/status` still matches the status route.

### Handler: `get_order( $request )`

1. `$order_id = absint( $request->get_param( 'id' ) )`
2. `$order = wc_get_order( $order_id )`
3. If `! $order || 'shop_order' !== $order->get_type()` →
   `WP_Error( 'order.not_found', 'Order was not found.', array( 'status' => 404 ) )`.
   The type check excludes refunds (`WC_Order_Refund` is not type `shop_order`) and matches
   the list endpoint's `type => shop_order` filter. Error code and message are reused
   verbatim from `Miguel_Order_Status_Update_Api`.
4. Otherwise return `new WP_REST_Response( $this->format_order_detail( $order ), 200 )`.

There is no 400 branch — the endpoint takes no query parameters, and the route regex `\d+`
guarantees a numeric ID (a non-numeric path yields WordPress's own `rest_no_route` 404).

## Response shape

The response is a **bare order object** (no `{ order: … }` envelope, no `count`).

`format_order_detail()` is built as:

```php
private function format_order_detail( $order ) {
    return array_merge(
        $this->format_order( $order ), // base 8 fields — single source of truth
        array(
            // Order totals (strings, store currency, via wc_format_decimal)
            'total'          => wc_format_decimal( $order->get_total() ),
            'subtotal'       => wc_format_decimal( $order->get_subtotal() ),
            'total_tax'      => wc_format_decimal( $order->get_total_tax() ),
            'shipping_total' => wc_format_decimal( $order->get_shipping_total() ),
            'discount_total' => wc_format_decimal( $order->get_discount_total() ),

            // Structured addresses
            'billing'  => $this->format_billing_address( $order ),
            'shipping' => $this->format_shipping_address( $order ),

            // Payment & shipping meta
            'payment_method'       => $order->get_payment_method(),
            'payment_method_title' => $order->get_payment_method_title(),
            'transaction_id'       => $order->get_transaction_id(),
            'shipping_lines'       => $this->format_shipping_lines( $order ),
            'customer_note'        => $order->get_customer_note(),

            // All line items (every product line, not just Miguel)
            'line_items' => $this->format_line_items( $order ),
        )
    );
}
```

### Example response

```jsonc
{
  // base 8, from the existing format_order()
  "id": "42",
  "status": "processing",
  "currency_code": "CZK",
  "paid": true,
  "purchase_date": "2026-07-01T10:00:00+00:00",
  "update_date":   "2026-07-02T09:30:00+00:00",
  "user":     { "id": null, "email": "jan@example.com", "full_name": "Jan Novák",
                "address": "Václavské náměstí 1 Praha 11000 Czech Republic", "lang": "cs_CZ" },
  "products": [ { "code": "9788024271101", "price": { "sold_without_vat": 199.0 } } ],

  // order totals
  "total": "228.00", "subtotal": "199.00", "total_tax": "0.00",
  "shipping_total": "29.00", "discount_total": "0.00",

  // structured addresses
  "billing":  { "first_name": "Jan", "last_name": "Novák", "company": "",
                "address_1": "Václavské náměstí 1", "address_2": "", "city": "Praha",
                "state": "", "postcode": "11000", "country": "CZ",
                "email": "jan@example.com", "phone": "+420123456789" },
  "shipping": { "first_name": "Jan", "last_name": "Novák", "company": "",
                "address_1": "Václavské náměstí 1", "address_2": "", "city": "Praha",
                "state": "", "postcode": "11000", "country": "CZ", "phone": "" },

  // payment & shipping meta
  "payment_method": "bacs", "payment_method_title": "Direct Bank Transfer",
  "transaction_id": "",
  "shipping_lines": [ { "method_id": "flat_rate", "method_title": "Flat Rate", "total": "29.00" } ],
  "customer_note": "",

  // all line items
  "line_items": [
    { "product_id": 15, "name": "My eBook", "sku": "EBOOK-1", "quantity": 1,
      "total": "199.00", "tax": "0.00", "code": "9788024271101" },
    { "product_id": 20, "name": "Paper book", "sku": "PB-1", "quantity": 1,
      "total": "0.00", "tax": "0.00", "code": null }
  ]
}
```

### Helper methods

**`format_line_items( $order )`** — iterate `$order->get_items()`, keep only
`WC_Order_Item_Product` items, and emit for each:

| Field | Source |
|---|---|
| `product_id` | `$item->get_product_id()` (int) |
| `name` | `$item->get_name()` |
| `sku` | `$product ? $product->get_sku() : ''` (product may be null) |
| `quantity` | `$item->get_quantity()` (int) |
| `total` | `wc_format_decimal( $item->get_total() )` — line total, excl. tax |
| `tax` | `wc_format_decimal( $item->get_total_tax() )` — line tax |
| `code` | first Miguel code on the line, or `null` |

**`format_billing_address( $order )`** — `first_name, last_name, company, address_1,
address_2, city, state, postcode, country, email, phone` from `get_billing_*()`.
`country` is the **ISO code** (e.g. `CZ`).

**`format_shipping_address( $order )`** — same fields from `get_shipping_*()` **without
`email`** (WooCommerce shipping has no email). `phone` via `get_shipping_phone()`, guarded
with `method_exists()` (WooCommerce 5.6+); emit `""` when unavailable. `country` is the ISO
code.

**`format_shipping_lines( $order )`** — iterate `$order->get_shipping_methods()`, emit
`{ method_id: $item->get_method_id(), method_title: $item->get_name(), total:
wc_format_decimal( $item->get_total() ) }`. Matches the existing OpenAPI `ShippingLine`.

### Shared shortcode-scanning helper

The download-scanning logic currently inlined in `collect_products_from_order()` (find
downloadable line items, detect a Miguel/Wosa/audio shortcode, extract the code) is factored
into one private helper, e.g. `get_miguel_codes_for_item( $item ): array` returning the list
of Miguel codes on a line item. Both consumers use it:

- `collect_products_from_order()` — one `products[]` entry per code (unchanged output).
- `format_line_items()` — `code` = first element of the returned array, or `null` when empty.

This guarantees `products[]` and `line_items[].code` cannot disagree about whether a line is
a Miguel product.

### Field decisions

- **Country format.** `billing.country` / `shipping.country` are ISO codes, matching the
  create-order address shape. The flattened `user.address` string keeps the full country
  name it already uses. Two consumers, intentionally different.
- **`line_items[].code`** is the *first* Miguel code on the line (or `null`). Full code +
  price detail for every code still lives in `products[]`.
- **Money as strings.** All order totals and line money are normalized to strings via
  `wc_format_decimal()`. (The pre-existing `products[].price.sold_without_vat` stays a float,
  as today — not changed.)

## Error handling

| Case | Result |
|---|---|
| ID has no order (`wc_get_order()` → false) | 404 `order.not_found` |
| ID resolves to a refund / non-`shop_order` type | 404 `order.not_found` |
| Non-numeric ID | Route regex `\d+` doesn't match → WordPress `rest_no_route` 404 |
| Missing bearer token | 401 (auth trait) |
| Invalid bearer token | 403 (auth trait) |
| API not configured | 500 (auth trait) |

## Testing

New tests in `tests/unit/test-orders-api.php`, run via `make test-docker`. They call the
`get_order()` callback directly with a `WP_REST_Request`, matching the existing test style.

- `test_get_order_returns_404_when_not_found` — unknown high ID → `WP_Error` `order.not_found`,
  status 404.
- `test_get_order_returns_404_for_refund` — create order, `wc_create_refund()`, request the
  refund's ID → 404.
- `test_get_order_returns_base_fields` — created order → 200, base 8 keys present and equal to
  the same order's entry from `get_orders()`.
- `test_get_order_includes_detail_fields` — asserts every detail key is present:
  `line_items`, the five totals (`total`, `subtotal`, `total_tax`, `shipping_total`,
  `discount_total`), `billing`, `shipping`, `payment_method`, `payment_method_title`,
  `transaction_id`, `shipping_lines`, `customer_note`.
- `test_get_order_line_item_has_miguel_code` — order with a Miguel downloadable line →
  `line_items[].code` matches the code in `products[]`; a non-Miguel line → `code: null`.
- `test_get_order_billing_address_fields` — set billing data, assert structured `billing`
  fields reflect it (including ISO `country`).

## OpenAPI documentation (`docs/openapi.yaml`)

- New path `GET /orders/{id}`:
  - Path param `id`: integer, `minimum: 1`.
  - Responses: `200` → `OrderDetail`, `404` → `Error`, `401` → `Error`, `403` → `Error`.
- New schemas:
  - `OrderDetail`: `allOf: [ $ref Order ]` plus the extra properties (`total`, `subtotal`,
    `total_tax`, `shipping_total`, `discount_total`, `billing`, `shipping`, `payment_method`,
    `payment_method_title`, `transaction_id`, `shipping_lines`, `customer_note`, `line_items`).
  - `OrderLineItem`: `product_id` (int), `name`, `sku`, `quantity` (int), `total` (string),
    `tax` (string), `code` (string, nullable).
  - Reuse `ShippingLine` for `shipping_lines` and `BillingAddress` for `billing`.
  - Add a shipping-address schema (like `ShippingAddress`, plus `phone`) for `shipping`.

## Files touched

| File | Change |
|---|---|
| `includes/class-miguel-orders-api.php` | New route, `get_order()`, `format_order_detail()`, address/line-item/shipping helpers, shared shortcode-scan helper |
| `tests/unit/test-orders-api.php` | New tests listed above |
| `docs/openapi.yaml` | New `GET /orders/{id}` path + `OrderDetail`, `OrderLineItem`, shipping-address schemas |
