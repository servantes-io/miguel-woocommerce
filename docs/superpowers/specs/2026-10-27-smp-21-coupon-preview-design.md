# SMP-21: coupon preview from a throwaway WooCommerce cart — design

Replaces the `POST /miguel/v1/coupons/validate` route of SMP-21 (PR #42, deployed on test only).
Parent SERGEN-36; the backend side is SM-498, which has to move to this route (see
[What changes outside the plugin](#what-changes-outside-the-plugin)). The SERGEN-36 design this
revises is miguel `docs/superpowers/specs/2026-10-08-cfa-coupons-design.md`.

## Why

The route on the branch answers with the coupon's *rules* — `discount_type`, `amount`,
`limit_usage_to_x_items`, `eligible_product_codes` — and the backend re-implements WooCommerce's
arithmetic from them (`CouponPricing`, plus Melvil's own rules). The coupon screen of the e-shop,
with Smart Coupons installed, offers far more than that: gift products, store credit, max discount,
cheapest/costliest item, restrictions by location, payment and shipping method, user role, order
count, attributes, taxonomies, quantities. Mirroring each of them in Miguel is too much to support,
and every Smart Coupons release could add another.

Instead the plugin asks WooCommerce itself: it fills a throwaway cart with the customer's books,
prices it without the coupon and with it, and reports the difference. WooCommerce and whatever the
shop runs on top of it (Smart Coupons, Melvil's pricing plugin) stay the only place coupon logic
lives.

## Goals

- One route that says whether a code applies to a cart and, if it does, everything it changed:
  line prices and discounts, products it added or removed, free shipping, totals, and the shop's
  own notices — as much as WooCommerce and Smart Coupons expose, in a shape the backend can use
  without recomputing anything.
- **Report, don't judge.** The plugin reports what the cart did; the backend decides what to do
  with each change (a gift product, free shipping, store credit). The plugin never refuses a coupon
  for a change Miguel might not support.
- Faithful to the shop: the same answer the web cart would give for the same customer and books,
  as far as a REST request can reproduce the customer.
- Nothing persists: no order, no session row, no cookie, no coupon usage, no store-credit change,
  no e-mail, and the request leaves no trace in any real customer's cart.

## Non-goals

- Changing `POST /miguel/v1/orders` with `coupon_lines`: it stays as on the branch (coupon attached
  with discount 0, Miguel's line totals kept, usage counted by WooCommerce's status hook).
- Putting gift products on the order — if the backend wants them there, it sends them as order
  lines like any other.
- Several codes at once, the web storefront, auto-apply or URL coupons as a feature.

## Anti-goals

- A mirror of coupon rules in the plugin — no code path in the route reads a coupon's settings
  to compute a price. Reading a coupon's *description* fields for the response is fine.
- A route that changes anything a shopper can see: a real customer's cart, session or notices.

## Decisions (made with the user)

- A throwaway cart, a coupon applied to it, the changes detected — not an unsaved order. Melvil's
  pricing runs in cart hooks (`WcMelvilPrices::process_cart`), which an order never fires.
- Changes Miguel cannot simply turn into a price — an added product, free shipping, store credit —
  are reported in the response; the backend decides.
- As much information as WooCommerce and Smart Coupons give, but sensibly: amounts already
  computed, so the backend does not have to.
- It **replaces** `/coupons/validate` and its tests; SMP-21 is only on test.

## What the backend can send, and when

Read from miguel (`CartCouponService`, `MobileCartService`, `MobileOrderService`) and the app
(`miguel-reader-app`, `impl/EMMA-468`):

- **Cart page** (the code is entered there, before the shipping step; every cart read asks again,
  cached 5 min): the Miguel user's e-mail and the cart lines. No address, no delivery method.
- **Checkout** (asked live): also the chosen delivery method (`DeliveryMethod.WoocommerceMethodId`)
  and the billing and shipping addresses.
- **Payment method**: always the plugin's gateway `miguel` (`Miguel_Payment_Gateway::ID`) on every
  Miguel order, so the plugin sets it itself.

## The route

`POST /miguel/v1/coupons/preview` — same auth (`Miguel_Rest_Auth_Trait`) and registration as the
other API classes; product codes through `Miguel_Product_Code_Resolver`. The path name is my call.

### Request

```json
{
  "code": "MELVIL20",
  "email": "jan@example.com",
  "line_items": [{ "product_code": "ABC123", "quantity": 1 }],
  "shipping_method": "flat_rate",
  "billing":  { "country": "CZ", "state": "", "city": "Praha", "postcode": "11000" },
  "shipping": { "country": "CZ", "state": "", "city": "Praha", "postcode": "11000" }
}
```

- `code`, `line_items` required; `email`, `shipping_method`, `billing`, `shipping` optional.
- `line_items[].price` is gone: the cart prices the books as the shop does (see Prices).
- `shipping_method` takes the value the backend holds in `WoocommerceMethodId` (what
  `GET /delivery-methods` reports and what `POST /orders` already sends as `shipping_lines[].method_id`).
- 400 for a missing code or malformed lines, the resolver's 409 for an unknown or ambiguous code,
  as today.

### Response

200 whether or not the coupon applies — a refusal is a normal answer, not an error. Money is in the
shop currency, as decimals, **including tax**, with the tax part beside it.

```json
{
  "code": "MELVIL20",
  "applied": true,
  "error_code": null,
  "message": null,
  "currency": "CZK",

  "coupon": {
    "code": "melvil20",
    "discount_type": "percent",
    "amount": 20,
    "description": "Podzimní akce",
    "free_shipping": false,
    "expires_at": "2026-11-30T23:59:59+01:00"
  },

  "lines": [{
    "product_code": "ABC123",
    "quantity": 1,
    "before": { "unit_price": 271, "total": 271, "tax": 24.64 },
    "after":  { "unit_price": 255, "total": 255, "tax": 23.18 },
    "discount": 16,
    "discounted": true
  }],

  "added_lines": [{
    "product_code": "XYZ9", "product_id": 812, "sku": "XYZ9", "name": "Dárek",
    "quantity": 1, "unit_price": 0, "total": 0, "tax": 0,
    "source": "smart_coupons_action"
  }],
  "removed_lines": [],
  "gift_choices": null,

  "totals": {
    "before": { "items": 271, "discount": 0,  "shipping": null, "fees": 0, "total": 271, "tax": 24.64 },
    "after":  { "items": 255, "discount": 16, "shipping": null, "fees": 0, "total": 255, "tax": 23.18 }
  },

  "other_coupons": [],
  "unchecked": ["location"],
  "notices": ["Kupón byl úspěšně uplatněn."]
}
```

- **Refused:** `applied: false`, `error_code` and `message` set (message as plain text), the rest
  of the body describing the cart without the coupon (`after` equals `before`). `error_code` is
  WooCommerce's `E_WC_COUPON_*` number, captured from `woocommerce_coupon_error` as on the branch;
  Smart Coupons throws with code 0, so its refusals come as `0` plus its message.
- **`lines`:** one per line sent, in request order. `before` is the shop's price without the
  coupon, `after` with it; `discount` is `before.total − after.total`. `discounted` says the coupon
  touched the line. Prices come out of the cart after `calculate_totals()`: `line_total` +
  `line_tax` per line, divided by quantity for `unit_price`.
- **`added_lines`:** cart lines present after the coupon that were not sent — a Smart Coupons gift
  is a separate line keyed by cart item data `wc_sc_product_source` = the coupon code, even when the
  same book is also a sent line. `product_code` is the Miguel product code when the product has one,
  else null (Miguel then has the WooCommerce ID, SKU and name). `source` names the mechanism when
  it can be told (`smart_coupons_action`), else `unknown`.
- **`removed_lines`:** sent lines the coupon made the cart drop (product codes and quantities). Rare,
  reported for completeness.
- **A line the shop will not put in the cart** at all — not purchasable, no price, out of stock,
  sold individually (quantity capped), or refused by another plugin's
  `woocommerce_add_to_cart_validation` — is still 200 (report, don't judge; my call): its `lines`
  entry carries `in_cart_quantity` (0, or the capped quantity; equal to `quantity` normally) and
  `before`/`after` priced for what the cart actually holds, and the shop's notice lands in
  `notices`. `totals` describe the cart as it really was.
- **`gift_choices`:** a Smart Coupons coupon with `wc_sc_no_of_selectable_product` = yes adds
  nothing in a REST request (the customer picks through an AJAX dialog). The products offered and
  how many may be picked are read from the coupon's `wc_sc_add_product_details` and reported here;
  otherwise null.
- **`totals`:** `items` after line discounts, `discount` the cart's discount total (store credit
  in REST lands here, not on lines — Smart Coupons skips spreading it per line in a REST request),
  `shipping` only when `shipping_method` and an address were sent, else null; `fees` and `total`
  as the cart computes them.
- **`coupon.free_shipping`:** the coupon's own flag. Shipping is not recalculated for it unless a
  method was sent; the backend decides what free shipping means for its delivery methods.
- **`other_coupons`:** codes the shop applied on its own (Smart Coupons auto-apply) in either cart.
  Reported, not hidden; they are part of both `before` and `after`.
- **`unchecked`:** restrictions the cart could not judge for lack of input, so a yes is conditional:
  `location` when no address was sent, `shipping_method` when no method was sent. Payment method is
  always judged (`miguel`).
- **`notices`:** the plain-text notices the shop raised during the two calculations (applied,
  gift added, …), deduplicated.

## Constraints from reading the code

Smart Coupons 9.83.0 (`woocommerce-smart-coupons.zip` in the repo root, gitignored — licensed, not
redistributable) was read in full for cart behaviour. What binds the design:

- **The throwaway cart must be `WC()->cart` for the call.** Smart Coupons reads the global cart,
  not the cart a hook is handed (store credit, before-tax mode, coupon actions, min/max spend,
  taxonomy/attribute checks, combo, auto-apply, max discount). Likewise `WC()->session` and
  `WC()->customer`. The route swaps in its own cart, an in-memory session (never saved, no cookie)
  and an unsaved customer, and restores the originals in a `finally`.
- **A session must exist.** Several Smart Coupons paths call `WC()->session` unguarded and fatal
  with an `Error` that `WC_Discounts` does not catch (`reset_session()` on every
  `woocommerce_add_to_cart`, the shipping-method check, the new-user check). Notices go through
  `wc_add_notice` into that session; the route reads them out for `notices` and discards them.
- **The customer has to be the real one** for role, order-count and new-user rules: the shop user
  with the request's e-mail is made the current user for the call (`wp_set_current_user`) and
  restored after; with no such user it is a guest with that billing e-mail. A guest fails "only
  these roles" and order-count coupons — that is the shop's real answer for a guest.
- **Location with no address.** Smart Coupons passes a location rule when there is no customer
  object but fails it when the customer has an empty address. Without `billing`/`shipping`, the
  route must not turn that into a refusal: it reports `unchecked: ["location"]` and judges the
  coupon as if the rule passed. How to achieve that (no address on the customer and a filter
  bypassing only that check, or a probe with a customer-less discounts object) is left to the
  builder; the test pins the behaviour.
- **Payment method rules fail when the method is missing**, so the session's `chosen_payment_method`
  is set to `miguel` before applying.
- **Shipping-method rules read `chosen_shipping_methods` from the session** and pass when it is
  empty; with `shipping_method` sent, the route sets it (and the customer's shipping address) so
  the rule and shipping costs are judged.
- **Smart Coupons keeps state across calculations in one PHP request** (`discounted_products`,
  `discounted_cart_extra`, `smart_coupon_credit_used` on the cart, the cheapest/costliest item list
  in session). The *before* and *after* carts are therefore separate cart and session objects, and
  the *after* run must not inherit store-credit or item-list state from the *before* run. Order the
  runs and reset what is needed so the second calculation is not understated; the store-credit test
  pins it.
- **Melvil's plugin** (`woocommerce-melvil`, attached to EMMA-424) registers its cart hooks with no
  admin/REST/AJAX guard (`WcMelvilHooks::init_cart`). `WcMelvilCart::before_calculate_totals`
  (priority 20) prices the lines through `WcMelvilPrices::process_cart` on the cart it is handed;
  its coupon handling puts the discount into the line prices and forces WooCommerce's coupon
  discount to 0. So the route must read changes from the line totals of the two carts, never from
  WooCommerce's coupon discount amounts. Bundle-completion prices are added on add-to-cart only for
  a logged-in customer (`WcMelvilCart::add_cart_item_data` checks `is_user_logged_in()`), which the
  current-user swap gives; the `complete-bundle` (pe/pa) price comes only from a web link
  (`?melvil-apply-discount=`), so a throwaway cart never has it — the backend sees that in `before`.
- **Auto-apply** (`wc_sc_allow_auto_apply`) runs on add-to-cart and totals and will add coupons to
  both carts once a session exists — hence `other_coupons`.
- **Nothing to undo in the database**: Smart Coupons changes usage, balances and e-mails only on
  order status changes. Core WooCommerce counts usage on orders too. The route still asserts it in
  tests (usage count and store-credit amount unchanged).

## Prices — my call

The cart prices books as the shop does (product price, then whatever pricing plugins do in
`woocommerce_before_calculate_totals`), not with Miguel's prices: overriding them would bypass the
very plugins this route exists to consult. `before` shows what the shop thinks the price is, so the
backend can see when it differs from its own (e.g. Melvil's bundle-completion prices depend on what
the customer owns on the web) and decide — use `after`, or apply `discount` to its own price.

## Also my calls

- 200 for a refusal (with `applied: false`) rather than 422: the body carries the cart either way.
- Amounts include tax with the tax beside them; Melvil's prices are shown tax-inclusive.
- `coupon` describes the WooCommerce coupon (type, amount, description, free shipping, expiry) for
  labels only; the backend must not price from it.
- The route reports at most what the two carts show; it does not call Smart Coupons internals
  except to read `wc_sc_add_product_details` for `gift_choices` and the `wc_sc_product_source`
  marker.
- The branch's `/coupons/validate` class and tests are removed, not kept beside the new route.

## Left to the builder

- Class layout (one API class plus a small "isolated cart" helper that swaps and restores
  cart/session/customer/current user is the natural split), names, how notices are captured.
- Exact technique for each isolation point above, as long as the tests pin the behaviour.
- `openapi.yaml` schema names; CHANGELOG, `readme.txt`, translations, version bump as for other
  routes.

## Tests

PHPUnit in the Docker suite (`scripts/phpunit_docker.sh`), written first:

- Core coupons, each through the throwaway cart: `percent`, `fixed_product`, `fixed_cart` give the
  per-line `before`/`after`/`discount` WooCommerce computes; `limit_usage_to_x_items`; product and
  category include/exclude and `exclude_sale_items` show as `discounted: false` lines; free
  shipping sets `coupon.free_shipping`.
- Refusals keep their `error_code`: not found, expired, usage limit, e-mail restricted, min spend,
  max spend, and the body still describes the cart.
- Customer: per-user limit for a registered customer and for a guest by e-mail.
- Isolation: no order, no session row, no cookie header, coupon `usage_count` unchanged, a real
  `WC()->cart`/session/customer/current user present before the call is unchanged after it.
- `unchecked` holds `location` with no address and not with one; `shipping` totals only with a
  method.
- Malformed request 400, unknown product 409, as before.

Smart Coupons cases (gift product in `added_lines`, selectable gift in `gift_choices`, store credit
in `totals.after.discount`, payment-method rule passing with `miguel`, location rule `unchecked`,
role/order-count for guest vs. user, auto-apply in `other_coupons`, no fatal without a real
session) and a Melvil case (line price changed, WooCommerce coupon discount 0): see
[Testing Smart Coupons and Melvil](#testing-smart-coupons-and-melvil). The licensed zips are gitignored and absent from CI, and `scripts/phpunit_docker.sh` with
the Docker test files must stay byte-identical to origin/main for the triage loop, so this branch
cannot make the standard suite install them.

## Testing Smart Coupons and Melvil

Decided by the user: tests against the real plugins exist only locally during development, are
never stored in git or anywhere else, and are removed afterwards — or the plugins' behaviour is
mimicked by mocks for the committed tests. The plan uses both (my call):

- **Committed mocks** (`tests/helpers/`, loaded by the tests that need them, not by
  `tests/bootstrap.php`): small stand-ins that hook the same WooCommerce hooks the way the real
  plugins do, enough to pin the route's isolation — a gift line added on `woocommerce_applied_coupon`
  with `wc_sc_product_source`; a hook that calls `WC()->session` and `wc_add_notice` on
  `woocommerce_add_to_cart`; a `woocommerce_coupon_is_valid` check that throws code 0 when
  `chosen_payment_method` is missing and one that fails on an empty customer address; store credit
  taken off the total on `woocommerce_after_calculate_totals` with state kept on the cart between
  runs; an auto-apply that adds a coupon to `WC()->cart`; a Melvil-like
  `woocommerce_before_calculate_totals` that discounts line prices while the coupon's WooCommerce
  discount is 0. They mimic hooks and observable effects, not coupon rules.
- **Local-only tests against the real zips** during development: the plugins unpacked outside the
  repo, a test file outside git (or gitignored and deleted), run through the Docker suite, covering
  the Smart Coupons cases listed above and the Melvil case. Deleted when the work is done; their
  findings feed back into the mocks.

## What changes outside the plugin

Not part of this change, noted so it is planned: SM-498's client (`IWoocommerceClient`,
`WoocommerceCouponModels`) moves to this route; `CouponPricing` and the discount-type mapping in
`CartCouponService` are replaced by reading `lines[].after`/`discount`; the backend decides what to
do with `added_lines`, `gift_choices`, `coupon.free_shipping`, store credit and `unchecked`; the
checkout call sends `shipping_method` and addresses. The SERGEN-36 design's plugin section needs the
same revision.
