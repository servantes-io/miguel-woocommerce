# SMP-14 — No Shipping on Orders That Hold Only Digital Formats — Design

**Date:** 2026-09-13
**Status:** Approved for planning
**Issue:** [SMP-14](https://neatech.myjetbrains.com/issue/SMP-14) — *Woocommerce: odstranit dopravu z objednávek obsahující jen digi formáty*
**Repo:** `servantes-io/miguel-woocommerce` only. The backend needs no change (see Decisions).

## Goal

An order Miguel creates in a WooCommerce shop that contains only digital formats (e-books, audiobooks) carries no shipping address and no shipping line.

## Background

- `POST /miguel/v1/orders` (`includes/class-miguel-order-create-api.php`) **requires** `shipping` (non-empty object) and `shipping_lines` (non-empty array) on every order, in `validate_required_order_fields()`, before line items are looked at. Missing either is a 409 (`order.shipping_required`, `order.shipping_lines_required`).
- Because of that, the backend sends both even for digital-only orders: `shipping` is a copy of the billing address when the buyer gave no delivery address, and `shipping_lines` falls to the last tier of `WoocommerceManager.BuildShippingLines` — a hardcoded `{ method_id: "free_shipping", method_title: "Free Shipping", total: "0.00" }`.
- For printed items the backend sends real lines: one per delivery method, with the postage the customer was charged.
- WooCommerce shows the shipping address of an order only when it has a shipping line whose method is not a local-pickup one (`WC_Order::needs_shipping_address()`). An order with no shipping line hides the address in the admin, in emails and in My Account by itself.
- The plugin already tells digital from printed products with `WC_Product::is_downloadable()` (`Miguel_Product_Code_Source`): e-books and audiobooks are downloadable, printed books are not.

## Decisions

| Question | Decision |
|---|---|
| Where the fix lives | The plugin drops what a digital-only order does not need, so it works with the backend as it is today. |
| Backend | Unchanged. Having the backend stop sending them would also need a plugin-version gate, because every released plugin rejects an order without them; the plugin-side drop makes that unnecessary. |
| Shipping line with a cost on a digital-only order | Kept as sent (with the address), and logged. Dropping it would change the order total away from what the customer paid. |

## Rule: does an item need delivery?

A line item **needs delivery** when its product needs shipping and is not downloadable:

```php
$product->needs_shipping() && ! $product->is_downloadable()
```

- The product is `wc_get_product( variation_id )` when the line item has a positive `variation_id`, otherwise `wc_get_product( product_id )` — `product_id` is always set by the time this runs, because `prepare_line_item_for_wc_order()` has resolved any `product_code`.
- `needs_shipping()` is WooCommerce's own test (false for virtual products). `! is_downloadable()` also catches an e-book whose "virtual" box nobody ticked: it is downloadable, so it does not need delivery.
- A product that cannot be loaded counts as **needing delivery**. That keeps the old, stricter validation; WooCommerce then rejects the unknown product itself.

The **order needs delivery** when at least one line item does.

## Design

All changes live in `includes/class-miguel-order-create-api.php`.

1. **`validate_required_order_fields()`** no longer checks `shipping` / `shipping_lines`. It keeps the `email_template`, `payment_method` and `billing` checks.
2. **`order_needs_delivery( array $line_items ): bool`** — new private method implementing the rule above over the prepared line items.
3. **`prepare_shipping_for_wc_order( array $payload ): array|WP_Error`** — new private method, called from `prepare_payload_for_wc_order()` right after the line-item loop:
   - **Order needs delivery** → the two checks that used to be in `validate_required_order_fields()`, with the same codes, messages, status (409) and `data.field`. Payload returned unchanged.
   - **Digital-only, and every shipping line totals zero** (`total` absent, `''`, or numerically `0`; no lines at all counts too) → `unset( $payload['shipping'], $payload['shipping_lines'] )`, debug log "Dropped shipping from a digital-only order" with the number of lines dropped.
   - **Digital-only, but a shipping line has a non-zero total** → payload returned unchanged (address and lines kept), debug log "Kept shipping on a digital-only order because a shipping line has a cost" with the totals.
4. **Order of errors** — because shipping is now validated after line items, a payload that is wrong in both ways reports the line-item error (`line_items.*`, `line_item.*`, `product_code.*`) instead of the shipping one. Both are 409.
5. **Idempotency** — unchanged: the hash is computed from the payload as sent, before any of this runs, so a retry of the same request still replays.
6. **Logging** — the two digital-only outcomes each write their own debug entry (step 3). The existing "Prepared WooCommerce order payload" entry is unchanged; its `shipping_lines_count` reads 0 after a drop.

WooCommerce then stores no shipping address and no shipping item, so `needs_shipping_address()` is `false` and the address disappears from the admin screen, the emails and My Account without further code.

## Edge cases

| Order | Payload shipping | Result |
|---|---|---|
| E-book only | free_shipping 0.00 + address copy (today's backend) | Both dropped. No shipping on the order. |
| E-book only | none | Accepted (409 today). No shipping. |
| E-book only | `flat_rate` 49.00 | Kept as sent, logged. Total still matches payment. |
| E-book + printed book | real line(s) + address | Unchanged. |
| E-book + printed book | missing `shipping_lines` | 409 `order.shipping_lines_required`, as today. |
| Printed book only | missing `shipping` | 409 `order.shipping_required`, as today. |
| Downloadable but not virtual product | 0.00 line | Treated as digital: dropped. |
| Virtual, not downloadable product (e.g. a voucher) | 0.00 line | Treated as digital: dropped. |
| Line item with unknown `product_id` | none | Needs delivery → 409 `order.shipping_required` (the `shipping` check runs before the `shipping_lines` one; unchanged behaviour). |

## Error handling

No new error codes. `order.shipping_required` and `order.shipping_lines_required` keep their codes, messages and status; only the orders they apply to change.

## Testing

Tests run via `make test-docker`. In `tests/unit/test-order-create-api.php`:

- Digital-only (downloadable product) with today's backend payload (free_shipping 0.00 + address) → 201; order has no shipping items, empty shipping address, `needs_shipping_address()` false.
- Digital-only without `shipping` and `shipping_lines` → 201, no shipping.
- Digital-only with a non-zero shipping line → 201; line and address kept; order total includes the shipping.
- Mixed digital + printed with lines → unchanged (line kept with its total, address kept).
- Printed-only without `shipping_lines` → 409 `order.shipping_lines_required`; without `shipping` → 409 `order.shipping_required`.
- Virtual non-downloadable product → treated as digital.
- Existing tests that post a simple (non-downloadable, non-virtual) product without shipping keep expecting 409; tests whose setup relied on shipping being checked before line items are adjusted to the new order of errors.
- An idempotent replay of a digital-only order returns the same order.

`tests/helpers/class-miguel-helper-product.php` already creates downloadable products (`create_downloadable_product()`); a virtual product is a simple product with `set_virtual( true )`.

## Docs

- `docs/openapi.yaml`: `shipping` and `shipping_lines` leave the `required` list of `CreateOrderRequest`; their descriptions state when they are required and that zero-cost lines on a digital-only order are dropped. `minItems: 1` on `shipping_lines` is removed.
- `CHANGELOG.md`: entry under the unreleased `1.10.0`.

## Out of scope

- Backend changes (it may stop sending the placeholder line later, behind a plugin-version gate; not needed for this issue).
- Dropping the billing address or making it optional — invoices need it.
- Orders created in the shop itself (the WooCommerce checkout already handles virtual products).
