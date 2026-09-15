# SMP-16 — Products Refunded in WooCommerce Leave Miguel — Design

**Date:** 2026-09-15
**Status:** Approved for planning
**Issue:** [SMP-16](https://neatech.myjetbrains.com/issue/SMP-16) — *Woocommerce: nepodporuje správně částečně vrácené objednávky*
**Repo:** `servantes-io/miguel-woocommerce` only. The backend needs no change (see Background).

## Goal

When a shop refunds the customer for some products of an order, Miguel receives the order as it now stands: only the products — and quantities — the customer is still entitled to. Refunding everything Miguel delivers removes the order from Miguel; deleting a refund gives the products back.

## Background

- **Push.** `Miguel_Orders` re-syncs an order on `woocommerce_order_status_changed` and `woocommerce_update_order` (asynchronously, deduplicated by a hash of the mapped payload in `_miguel_last_sync_hash`). `Miguel_Order_Mapper::map()` builds the items from `$item->get_quantity()` — the quantity **as ordered**. A partial refund in WooCommerce creates a separate `WC_Order_Refund` and never changes the order's line items, so every sync keeps sending every product.
- **Refund creation already triggers a sync.** `wc_create_refund()` sets the parent order's modified date and saves it, which fires `woocommerce_update_order`. Once the mapper sends net quantities, the hash changes and the sync goes out. A full refund also moves the order to the `refunded` status, which is a "removal" status by default and already deletes the order in Miguel.
- **Refund deletion does not.** The admin "delete refund" action (`WC_AJAX::delete_refund`) deletes the refund and fires `woocommerce_refund_deleted( $refund_id, $order_id )`; it neither saves the parent order nor changes its modified date.
- **Pull.** `GET /miguel/v1/orders` (and `/orders/{id}`) reports each order's Miguel `products` (code + price, no quantity) from every line item, and a `deleted` flag from the order status. Miguel polls it to repair pushes that never arrived.
- **Backend behaviour (servantes-io/miguel, `OrderManager.CreateOrUpdateOrderAsync`).** Re-sending an existing order diffs its items: items whose code is no longer sent are deleted and their tasks expired (download links stop working); items still sent get their quantity and price updated. So Miguel already honours "fewer products" — the plugin just never sends fewer.
- WooCommerce's refund form has, per line, a **quantity** field and an **amount** field (without tax, tax separately). Shops refunding digital goods often type only the amount.

## Decisions

| Question | Decision |
|---|---|
| Which refunds take a product away | A refunded **quantity**, or a refunded **amount covering whole units** — whichever removes more. Partial compensation (less than one unit's price) keeps access. |
| Everything Miguel delivers refunded, status unchanged | The order is deleted in Miguel, as for a removal status. |
| Deleting a refund | Products come back on the next sync — except a *full* refund: deleting it leaves the order in WooCommerce's `refunded` status, which is itself a removal status, so the order stays removed from Miguel until the status changes. |
| Price of remaining units | Unchanged (the per-unit price as sold). |
| Backend | Unchanged. |

None of this touches WooCommerce's own My Account download links: WooCommerce removes a line's download permissions as soon as it carries any line-level refund, whole or partial, and never restores them when the refund is deleted. "Keeps access" and "products come back" above are both about the order Miguel holds, not about those WooCommerce-native links. The rule also applies to refunds made before this version, the next time such an order syncs — nothing is backfilled proactively.

## The rule

New class **`Miguel_Order_Refunds`** (`includes/class-miguel-order-refunds.php`), static methods, the single place the rule lives:

```php
Miguel_Order_Refunds::get_entitled_quantity( WC_Order $order, WC_Order_Item_Product $item ): int
```

```
ordered         = $item->get_quantity()
refunded_qty    = abs( $order->get_qty_refunded_for_item( $item->get_id() ) )
refunded_total  = abs( $order->get_total_refunded_for_item( $item->get_id() ) )   // without tax
line_total      = (float) $item->get_total()                                       // without tax, after discounts
raw_unit_price  = ordered > 0 ? line_total / ordered : 0
unit_price      = round( raw_unit_price, wc_get_price_decimals() )
unit_price      = ( unit_price == 0 && raw_unit_price > 0 ) ? raw_unit_price : unit_price
money_units     = unit_price > 0 ? floor( ( refunded_total + tolerance ) / unit_price ) : 0
tolerance       = 0.5 × 10^(−wc_get_price_decimals())                               // half a cent for 2 decimals
entitled        = max( 0, ordered − max( refunded_qty, money_units ) )
```

- The unit price is rounded to the store's price precision before it is compared against refunded
  money, so several refunds that each cover one rounded unit price (33.33 against a 3 × 33.333…
  line, refunded three times) add up to the whole line instead of falling short by
  floating-point error against the unrounded price.
- If rounding would make a line that is not actually free look free (`unit_price` rounds to 0
  while `raw_unit_price` is > 0 — a sub-cent unit price), the unrounded price is used instead, so
  the line can still be taken by money.
- A free line (`line_total` 0) can lose units only through the quantity field.
- Tax is ignored on both sides: `refunded_total` and `line_total` are both without tax.

Examples (2-decimal store):

| Line | Refund | Entitled |
|---|---|---|
| qty 1, 199.00 | qty 1 | 0 |
| qty 1, 199.00 | amount 199.00, qty empty | 0 |
| qty 1, 199.00 | amount 40.00 (compensation) | 1 |
| qty 2, 398.00 | qty 1 | 1 |
| qty 2, 398.00 | amount 199.00 | 1 |
| qty 2, 398.00 | amount 250.00 | 1 |
| qty 1, 0.00 (free) | qty 1 | 0 |
| qty 1, 0.00 (free) | none | 1 |
| qty 3, 100.00 | amount 33.33 | 2 (unit price rounds to 33.33: 33.33 is one whole unit) |
| qty 3, 100.00 | amount 99.99, one refund | 0 (three rounded unit prices) |
| qty 3, 100.00 | 33.33 refunded three separate times | 0 (each refund covers one rounded unit price; they add up, unlike against the unrounded 33.333…) |
| qty 2, 328.9256 (0-decimal store) | amount 164 | 1 (unit price rounds to 164 at 0 decimals) |

A second static method, used by the sync and the pull:

```php
Miguel_Order_Refunds::has_refunded_all_miguel_items( WC_Order $order, callable $has_miguel_codes ): bool
```

True when the order has at least one product line whose product yields Miguel codes (per `$has_miguel_codes( WC_Product ): bool`) and every such line has an entitled quantity of 0. The callable keeps the class independent of how codes are extracted: the push passes `Miguel_Order_Mapper::has_miguel_codes()` (bundles included), the pull passes its own code check.

## Changes

1. **`Miguel_Order_Mapper::map()`** sends each line with `quantity = get_entitled_quantity()` and skips lines with 0 (for a bundle, all its codes). Per-unit `sold_price` is unchanged. When no line is left it returns `null`, as it does today for an order without Miguel products.
2. **`Miguel_Orders::sync_order()`**: an order for which `has_refunded_all_miguel_items()` is true (checked with the mapper's bundle-aware `has_miguel_codes()`) takes the existing delete branch, like a removal status — same `delete_order()` call, same `'delete'` hash, same logging. For such an order the mapper would return `null` anyway, since no Miguel line has units left. An order that simply has no Miguel products still does nothing.
3. **`Miguel_Orders`** registers `woocommerce_refund_deleted` → `handle_refund_deleted( $refund_id, $order_id )`: loads the parent order, `set_date_modified( time() )` and `save()`. The save fires `woocommerce_update_order`, which queues the usual sync (products return), and the new modified date makes `GET /orders?updated_since` report the order again. A missing order is ignored.

   `woocommerce_refund_deleted` fires only from the admin "delete refund" action (`WC_AJAX::delete_refund`). Deleting a refund through the REST API (`DELETE /wc/v2/orders/{order_id}/refunds/{id}`) instead fires `woocommerce_rest_delete_shop_order_refund_object` with the deleted `WC_Order_Refund` object (refunds do not support trashing, so the delete is always forced, which resets the refund object's own ID to 0 but leaves its parent order ID intact). `Miguel_Orders` also registers this action, to a public `handle_refund_deleted_via_rest( $refund )` that calls `handle_refund_deleted( $refund->get_id(), $refund->get_parent_id() )` — so a refund deleted through either path resyncs the parent the same way.
4. **`Miguel_Orders_Api`**:
   - `collect_products_from_order()` skips lines whose entitled quantity is 0.
   - `format_order()` reports `deleted: true` also when `has_refunded_all_miguel_items()` is true, judged with the same bundle-aware `Miguel_Order_Mapper::has_miguel_codes()` the push uses (not the pull's own code source, which does not see bundles), so a bundle with codes left is not reported deleted here while the push still sends it. This is why `Miguel_Orders_Api` holds a `Miguel_Order_Mapper` instance too, constructed the same way it already constructs its product code source.
   - `format_line_items()` (order detail) is unchanged — it is a raw view of WooCommerce's lines.
5. **Docs:** `docs/openapi.yaml` — `products` omits fully refunded lines; the `Order` schema gains its missing `deleted` property, documented with both meanings (removal status, everything refunded). `README.md` if it describes these fields. `CHANGELOG.md` entry under the unreleased `1.10.0`.

## Edge cases

| Case | Result |
|---|---|
| E-book + printed book, e-book refunded (qty) | Push sends only the printed book; Miguel deletes the e-book item and expires its links. |
| Single e-book refunded by amount, status stays "completed" (shipping not refunded) | Push deletes the order in Miguel; pull reports `deleted: true`. |
| Full refund | Status becomes `refunded` → existing removal path (unchanged). |
| Partial compensation 40.00 on a 199.00 e-book | Nothing changes in Miguel. |
| Refund deleted | Order saved → re-sync sends the product again → Miguel re-adds the item. |
| Refund of a non-Miguel item only | The mapper's items are unchanged, but the payload also carries the order's modified date (`eshopUpdatedAt`), which the refund still moves — so the hash changes and an identical push re-sends the same items (harmless). |
| Order created through `POST /miguel/v1/orders` later refunded | Same as any order: the create API only suppresses the initial sync-back. |

## Error handling

No new error codes. A failed delete or sync logs exactly as today and is retried by the next order update, as today.

## Testing

Tests run in Docker (`docker compose -p miguel-woocommerce -f docker-compose.test.yml run --rm phpunit`). Refunds are created with `wc_create_refund()` with `line_items` (`qty`, `refund_total`), as the admin form does.

- **New `tests/unit/test-order-refunds.php`** — every row of the examples table; `has_refunded_all_miguel_items()` true/false (no Miguel lines → false; one of two Miguel lines refunded → false; all refunded → true).
- **`tests/unit/test-order-mapper.php`** — a qty refund lowers the quantity; a fully refunded line is omitted (for a bundle line, all its codes); all lines refunded → `null`.
- **`tests/unit/test-orders.php`** — partial refund → `create_order` with fewer items; everything refunded with status unchanged → `delete_order`; `woocommerce_refund_deleted` saves the parent, its modified date moves, and a subsequent sync sends the product again; `woocommerce_rest_delete_shop_order_refund_object` is registered to `handle_refund_deleted_via_rest()`, which resyncs the parent the same way.
- **`tests/unit/test-orders-api.php`** — `products` omits a refunded line; `deleted` true when every Miguel line is refunded, false after one of two; `deleted` follows the mapper's bundle-aware code check (a bundle with codes left is not flagged even though a loose Miguel line was refunded; a bundle that is the only Miguel product and gets fully refunded is flagged).

## Out of scope

- Lowering the price Miguel records after partial compensation.
- The "Miguel-only vs mixed" classification used by the order-finished status change (`Miguel_Order_Finished_Api::is_miguel_only()`) ignoring refunded lines.
- Bundle expansion in `GET /orders` (the push expands bundles; the pull does not — unchanged).
- Reporting quantities in `GET /orders`.
