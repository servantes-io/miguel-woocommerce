# SMP-13 — A Real "Miguel" Payment Method for Orders From the App — Design

**Date:** 2026-09-13
**Status:** Approved for planning
**Issue:** [SMP-13](https://neatech.myjetbrains.com/issue/SMP-13) — *Woocommerce: správná platba pro objednávky z aplikace*
**Repo:** `servantes-io/miguel-woocommerce` only. The backend needs no change (see Background).

## Goal

Orders Miguel creates in a WooCommerce shop show a proper payment method ("Miguel") instead of WooCommerce's "Other" (Czech: "Jiná").

## Background

- The backend creates every such order with `payment_method: "miguel"` and no `payment_method_title` (`WoocommerceManager._CreateOrderOnWoocommerceInternal`, hardcoded). The customer pays through Stripe Checkout run by Miguel (Stripe Connect, paid to the publisher's account); the shop order is created unpaid and later marked paid through `PATCH /miguel/v1/orders/{id}/status` → `payment_complete()`.
- The plugin registers **no** payment gateway. WooCommerce's order edit screen builds its payment dropdown from the registered gateways whose `enabled` is `yes`, and when the order's method is not among them it selects an extra option labelled **"Other"** (`class-wc-meta-box-order-data.php`, the `$found_method` branch). That is the "Jiná" in the issue.
- The order header reads "Payment via %s" with the gateway's title when the gateway is registered, and the raw id (`miguel`) otherwise. Emails, the order list and exports print the stored `payment_method_title`, which is empty today.
- The plugin creates the order through `WC_REST_Orders_Controller::create_item()`, which does not check `payment_method` against the registered gateways, so creation already works without a gateway; only the display is wrong.

## Decisions

| Question | Decision |
|---|---|
| Approach | Register a real gateway with id `miguel` (what the backend already sends). |
| At checkout | Never offered — neither classic nor block checkout. |
| Title | Default "Miguel", editable by the merchant under WooCommerce → Payments. |
| Empty `payment_method_title` in the payload | The plugin fills it with the gateway's title. |
| Existing orders | Header and dropdown fix themselves once the gateway exists; their stored title stays empty. No back-fill. |
| Backend | Unchanged. |

## Design

### New class: `Miguel_Payment_Gateway` (`includes/class-miguel-payment-gateway.php`)

`extends WC_Payment_Gateway`.

| Property / method | Value |
|---|---|
| `const ID` / `$this->id` | `'miguel'` |
| `$this->method_title` | `__( 'Miguel', 'miguel' )` — the gateway's name in WooCommerce → Payments |
| `$this->method_description` | `__( 'Orders placed and paid in a Miguel app. Miguel creates these orders itself; this method is never offered at checkout.', 'miguel' )` |
| `$this->has_fields` | `false` |
| `$this->supports` | `array( 'products' )` — no `refunds`: the money is in Stripe, so a WooCommerce refund stays a manual one |
| `init_form_fields()` | `enabled` (checkbox, default `yes`, label "Show Miguel as the payment method of orders from the Miguel app"), `title` (text, default `__( 'Miguel', 'miguel' )`), `description` (textarea, default `''`) |
| `is_available()` | always `false` — the gateway can never be chosen at checkout, whatever its `enabled` setting |
| `process_payment()` | returns `array( 'result' => 'failure' )` and takes no money. Unreachable because the gateway is never available; overridden so that a checkout which ignores `is_available()` fails closed |

`enabled` defaults to `yes` because that is what puts the gateway in the order edit dropdown. A merchant who disables it gets today's display back ("Jiná"); order creation is unaffected either way.

### Registration

The plugin file loads before WooCommerce (`miguel` sorts before `woocommerce`), so `WC_Payment_Gateway` does not exist when `Miguel::includes()` runs. The gateway file is therefore **not** in `includes()`; instead:

- `Miguel::init_hooks()` adds a `woocommerce_payment_gateways` filter (via the hook manager, like the other hooks) whose callback `include_once`s `class-miguel-payment-gateway.php` and appends `'Miguel_Payment_Gateway'` to the list. The filter only fires once WooCommerce is loaded.
- The filter is registered **outside** the `! defined( 'MIGUEL_TESTS' )` block, so the test suite sees the gateway exactly as a shop does. (The services inside that block are skipped in tests because they call out to Miguel; the gateway does not.)
- In the existing `before_woocommerce_init` callback, next to the HPOS declaration, the plugin also declares `cart_checkout_blocks` compatibility (`FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', MIGUEL_PLUGIN_FILE, true )`). The gateway never renders at checkout, so it is compatible by construction; without the declaration WooCommerce may flag the plugin as incompatible with the block checkout.

### Filling `payment_method_title`

In `Miguel_Order_Create_Api::prepare_payload_for_wc_order()`: when `payment_method` equals `Miguel_Payment_Gateway::ID` and `payment_method_title` is absent or blank, set it to the gateway's current title — read from `WC()->payment_gateways()->payment_gateways()['miguel']->get_title()`, falling back to `__( 'Miguel', 'miguel' )` when the gateway is not registered (e.g. a merchant filtered it out). A non-blank title in the payload is kept verbatim, so the backend can still choose per-app wording later.

This runs after the idempotency hash is computed (the hash covers the payload as sent), so it does not affect replays.

### Block checkout risk

WooCommerce's block checkout editor lists enabled gateways that have no block integration as "incompatible". The `cart_checkout_blocks` declaration covers the plugin-level notice; the implementation must check the **Checkout block editor** in the Docker site (`docker-compose.yml`) with the gateway enabled. If the gateway is still listed as incompatible, add a minimal block integration — a `Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType` subclass registered on `woocommerce_blocks_payment_method_type_registration` whose `is_active()` returns `false` and which enqueues no script. If it is not listed, add nothing.

## Edge cases

| Case | Result |
|---|---|
| Payload `payment_method: miguel`, no title | Title filled with the gateway title ("Miguel"). Dropdown and header show "Miguel". |
| Payload `payment_method: miguel`, title "Miguel app" | Title kept as "Miguel app". |
| Payload with another method (e.g. `bacs`) | Untouched. |
| Merchant renamed the gateway to "Zaplaceno v aplikaci" | New orders get that title; the header of every `miguel` order shows it. |
| Merchant disabled the gateway | Orders still created; the dropdown shows "Other" again; new orders still get the title filled. |
| Customer at checkout | Gateway never listed (`is_available()` is `false`). |
| Refund in WooCommerce | Manual refund only; no "Refund via Miguel" button. |
| Existing orders with `miguel` | Header and dropdown show "Miguel"; stored title stays empty (emails/exports of old orders unchanged). |

## Error handling

No new error codes. Filling the title cannot fail the request.

## Testing

Tests run via `make test-docker`.

- **New `tests/unit/test-payment-gateway.php`:**
  - the gateway is registered: `WC()->payment_gateways()->payment_gateways()` has key `miguel`, class `Miguel_Payment_Gateway`;
  - `is_available()` is `false`, and `miguel` is absent from `WC()->payment_gateways()->get_available_payment_gateways()`;
  - `enabled` defaults to `yes`, `get_title()` defaults to "Miguel", and a saved `woocommerce_miguel_settings['title']` is returned instead;
  - `supports( 'refunds' )` is `false`;
  - `process_payment()` returns `result => failure`.
- **`tests/unit/test-order-create-api.php`:**
  - `payment_method: miguel` without a title → order's `payment_method_title` is "Miguel";
  - with a title → kept verbatim;
  - another method → title untouched;
  - with a renamed gateway (settings option updated, gateways reloaded) → the new title.
- **Manual (recorded in the PR):** in the Docker shop, an order created with `payment_method: miguel` shows "Platba přes Miguel" / "Payment via Miguel" in the header and "Miguel" selected in the dropdown; the classic and block checkout do not offer it; the Checkout block editor does not flag it.

## Docs

- `CHANGELOG.md`: entry under the unreleased `1.10.0`.
- `languages/`: the new strings in both catalogues (`miguel-cs_CZ.po` and `miguel-en_US.po`; the `.mo` files are built by `make build`), with the Czech `method_description` and checkbox label translated; the title default stays "Miguel" in both.
- `docs/openapi.yaml`: `payment_method_title` in `CreateOrderRequest` documents the fill-in for `miguel`.

## Out of scope

- Back-filling `payment_method_title` on existing orders.
- Storing the Stripe payment id as the order's `transaction_id` (the order is created before payment; a separate change on the mark-paid path).
- Per-app titles (possible later from the backend without a plugin change, because a sent title wins).
