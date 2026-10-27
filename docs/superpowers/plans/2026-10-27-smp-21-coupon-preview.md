# SMP-21 Coupon Preview Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace `POST /miguel/v1/coupons/validate` with `POST /miguel/v1/coupons/preview`, which prices the customer's books in a throwaway WooCommerce cart without and with the coupon and reports every difference.

**Architecture:** `Miguel_Isolated_Cart` swaps a fresh `WC_Cart`, an in-memory `WC_Session`, an unsaved `WC_Customer` and the current user into WooCommerce for one callback and restores them. `Miguel_Coupon_Cart_Probe` fills such a cart, optionally applies a coupon, calculates totals and returns a plain snapshot. `Miguel_Coupon_Preview_Api` validates the request, takes a *before* and an *after* snapshot and turns the two into the response. Smart Coupons specifics (location bypass, `unchecked`, gift choices) live in the API class behind `class_exists`/meta checks.

**Tech Stack:** PHP 7.4+ WordPress plugin, WooCommerce `WC_Cart`/`WC_Session`/`WC_Discounts`, PHPUnit 9 via `WC_Unit_Test_Case` in Docker.

**Spec:** `docs/superpowers/specs/2026-10-27-smp-21-coupon-preview-design.md` (read it first; this plan argues from it).

## Global Constraints

- Work in `/root/proj/.impl-worktrees/SMP-21` on `impl/SMP-21`. First `cp -a /root/proj/miguel-woocommerce/vendor .` (vendor/ is gitignored).
- Filtered test run: `docker compose -p miguel-woocommerce -f docker-compose.test.yml run --rm phpunit --filter <X>`. Whole suite: `scripts/phpunit_docker.sh` (no arguments). Never two runs at once under that project name.
- Do not change `scripts/phpunit_docker.sh`, `docker-compose.test.yml`, `Dockerfile.test`, `bin/docker-test-entrypoint.sh`, `bin/install-wp-tests.sh` (must stay byte-identical to origin/main).
- Nothing persists: no order, no `woocommerce_sessions` row, no cookie, no coupon `usage_count` change, no real cart/session/customer/current-user change.
- Money in responses: decimals in the shop currency, **including tax**, tax beside it, each rounded with `round( $x, wc_get_price_decimals() )`.
- Refusals are HTTP 200 with `applied: false`; 400 `coupon.code_required` / `coupon.line_items_invalid` and the resolver's 409 stay as today.
- Payment method is always `Miguel_Payment_Gateway::ID` (`miguel`) in the throwaway session.
- No code path reads a coupon's settings to compute a price (anti-goal). Reading meta to *describe* (`coupon`, `gift_choices`, `unchecked`) is fine.
- The Smart Coupons, Melvil, wc-bundle and melvil-customer zips are licensed: never committed, never copied anywhere but the gitignored `temp/` of this worktree; local-only tests are deleted at the end (Task 5).
- Version stays 1.12.0 (already bumped, unreleased). Code style: WordPress PHPCS as the surrounding files (tabs, `array()`, docblocks on every method, `esc_html__( …, 'miguel' )`).

## Review Focus

- Two `line_items` with the same `product_code` merge into one cart item → each line reports that item's unit price with its own quantity (test in Task 2).
- A plugin throws (`Exception` or `Error`) during `add_to_cart`/`calculate_totals` → globals still restored; the route answers 500 `coupon.preview_failed`, never leaks the throwaway cart (tests in Task 1 and Task 3).
- A real `WC()->cart`/session with items and notices exists when the route runs → identical afterwards (test in Task 1).
- Code sent as `" Melvil20 "` against coupon `melvil20` → applied, `coupon.code` is `melvil20` (test in Task 3).
- The coupon is accepted by `apply_coupon()` but removed again during `calculate_totals()` (Smart Coupons re-validates) → `applied: false`, `error_code` 0, the shop's message (test in Task 4, via mock).

---

### Task 1: Isolated cart environment

**Files:**
- Create: `includes/class-miguel-memory-session.php`
- Create: `includes/class-miguel-isolated-cart.php`
- Modify: `includes/class-miguel.php` (include both files next to the coupon API include, ~line 101)
- Test: `tests/unit/test-isolated-cart.php`

**Interfaces:**
- Produces: `class Miguel_Memory_Session extends WC_Session` — data only in `$this->_data`; `init()`, `save_data()`, `forget_session()`, `set_customer_session_cookie()`, `has_session()` (true), `get_session_cookie()` (false), `destroy_session()` no-ops; `get_customer_id()` returns `'miguel-preview'`.
- Produces: `Miguel_Isolated_Cart::run( array $customer, callable $work ): array` — `$customer` keys: `email` (string), `user_id` (int, 0 = guest), `billing` (array|null: country, state, city, postcode), `shipping` (same|null), `shipping_method` (string|null, `method_id:instance_id`). Calls `$work( WC_Cart $cart )`; returns `array( 'result' => mixed, 'notices' => string[] )` where notices are the plain-text (`wp_strip_all_tags`, entity-decoded) messages of every type from the session's `wc_notices`, deduplicated, in order. Rethrows whatever `$work` throws after restoring.

- [ ] **Step 1: Write the failing tests** in `Test_Miguel_Isolated_Cart extends Miguel_Test_Case`:
  - `test_work_sees_a_fresh_cart_session_and_customer` — inside `$work`: `WC()->cart === $cart`, `WC()->cart` not the original object, `WC()->session instanceof Miguel_Memory_Session`, `WC()->customer->get_billing_email() === 'buyer@example.com'`, `WC()->customer->get_id() === 0`, `WC()->session->get( 'chosen_payment_method' ) === 'miguel'`.
  - `test_shipping_method_and_addresses_reach_session_and_customer` — with `shipping_method => 'flat_rate:3'`, `shipping => ['country'=>'CZ','city'=>'Praha','postcode'=>'11000','state'=>'']`: `WC()->session->get( 'chosen_shipping_methods' ) === ['flat_rate:3']`, `WC()->customer->get_shipping_country() === 'CZ'`; without `shipping_method`, `chosen_shipping_methods` is null.
  - `test_current_user_is_the_customer_for_the_call` — `user_id` of a created user → `get_current_user_id()` equals it inside, equals the previous id after; `user_id` 0 → 0 inside.
  - `test_everything_is_restored_after_the_call` — put a product in the real `WC()->cart` and `wc_add_notice( 'real' )` first; after `run()`: same cart object, same contents, same session object, `wc_get_notices()` still holds `real` only, same customer object, same current user.
  - `test_everything_is_restored_when_work_throws` — `$work` throws `new Error( 'boom' )` → `expectException( Error::class )` and, in a `finally` in the test, the same assertions as above.
  - `test_notices_are_returned_not_kept` — `$work` calls `wc_add_notice( '<b>Kupón &amp; dárek</b>', 'success' )` twice and `wc_add_notice( 'Chyba', 'error' )` → `notices === ['Kupón & dárek', 'Chyba']`; real session has no such notice.
  - `test_nothing_is_written_to_the_sessions_table` — `$wpdb` count of `{$wpdb->prefix}woocommerce_sessions` unchanged after a `run()` that adds a product and calls `calculate_totals()`.

- [ ] **Step 2: Run, expect FAIL** — `--filter Test_Miguel_Isolated_Cart` → class not found.

- [ ] **Step 3: Implement both classes.** `run()` saves `WC()->cart`, `WC()->session`, `WC()->customer`, `get_current_user_id()`; sets the new ones (`new WC_Customer( 0 )` unsaved, billing email + addresses; `wp_set_current_user( $customer['user_id'] )`); sets session `chosen_payment_method`, and `chosen_shipping_methods` only when given; runs `$work` inside `try`; reads notices from the session; restores everything in `finally`. Create the `WC_Cart` *after* swapping session and customer (its constructor reads them).

- [ ] **Step 4: Run, expect PASS** — same filter.

- [ ] **Step 5: Commit** — `feat(coupons): isolated WooCommerce cart for one call (SMP-21)`.

---

### Task 2: Cart probe — price a cart with or without a coupon

**Files:**
- Create: `includes/class-miguel-coupon-cart-probe.php`
- Modify: `includes/class-miguel.php` (include)
- Test: `tests/unit/test-coupon-cart-probe.php`

**Interfaces:**
- Consumes: `Miguel_Isolated_Cart::run()` (Task 1).
- Produces: `Miguel_Coupon_Cart_Probe::price( array $lines, ?string $coupon_code, array $customer ): array` — `$lines` is a list of `array( 'product_id' => int, 'quantity' => int )` (resolved by the caller); `$customer` as in Task 1. Returns:
  ```php
  array(
    'lines'      => array( /* same index as $lines */ array( 'in_cart_quantity' => int, 'unit_price' => float, 'total' => float, 'tax' => float ) ),
    'extra'      => array( /* cart items not created from $lines */ array( 'key' => string, 'product_id' => int, 'variation_id' => int, 'quantity' => int, 'unit_price' => float, 'total' => float, 'tax' => float, 'source' => ?string /* wc_sc_product_source */ ) ),
    'coupons'    => string[], // WC()->cart->get_applied_coupons() after calculate_totals()
    'error_code' => ?int,     // from woocommerce_coupon_error during apply_coupon, else null
    'message'    => ?string,  // that filter's message, plain text
    'totals'     => array( 'items' => float, 'discount' => float, 'shipping' => ?float, 'fees' => float, 'total' => float, 'tax' => float ),
    'notices'    => string[], // from run()
  )
  ```
  `total`/`unit_price` include tax: `total = line_total + line_tax`, `unit_price = total / in_cart_quantity` (0 when not in cart). Lines whose `add_to_cart()` returned the same key share it: each reports that item's `unit_price` and `total = unit_price × its own quantity`, `in_cart_quantity` capped by what the item holds. `totals.items = cart_contents_total + cart_contents_tax`; `discount = discount_total + discount_tax`; `shipping = shipping_total + shipping_tax` only when `$customer['shipping_method']` is set, else null; `fees = fee_total + fee_tax`; `total = $cart->get_total( 'edit' )`; `tax = $cart->get_total_tax()`.

- [ ] **Step 1: Write the failing tests** (`Test_Miguel_Coupon_Cart_Probe`; reuse the `create_product`/`create_coupon` helpers of the current `test-coupon-validate-api.php`, product price 319, tax off unless stated):
  - `test_without_coupon_prices_lines_as_the_shop` — two products ×1, ×2 → `lines[0].total == 319`, `lines[1].total == 638`, `lines[1].unit_price == 319`, `coupons == []`, `error_code === null`, `totals.total == 957`, `totals.shipping === null`.
  - `test_percent_coupon` — 20 % → `lines[0].total == 255.2`, `totals.discount == 63.8`, `coupons == ['c20']`.
  - `test_fixed_product_coupon` — 50 → `lines[0].total == 269`.
  - `test_fixed_cart_coupon_and_item_limit` — fixed_cart 100 over 2 lines → sum of line totals == 538; percent 50 with `limit_usage_to_x_items` 1 on a ×2 line → `lines[0].total == 478.5`.
  - `test_excluded_product_keeps_its_price` — percent coupon with `excluded_product_ids` of line 1 → line 1 total 319, line 0 discounted.
  - `test_refusals_carry_woocommerce_codes` — data provider: not found → `WC_Coupon::E_WC_COUPON_NOT_EXIST`; expired → `E_WC_COUPON_EXPIRED`; usage limit → `E_WC_COUPON_USAGE_LIMIT_REACHED`; e-mail restricted → `E_WC_COUPON_NOT_YOURS_REMOVED` (accept the codes the current branch test accepts); min spend 1000 → `E_WC_COUPON_MIN_SPEND_LIMIT_NOT_MET`; max spend 100 → `E_WC_COUPON_MAX_SPEND_LIMIT_MET`. Each: `coupons == []`, `message` non-empty plain text, lines priced as without coupon.
  - `test_per_user_limit_for_registered_customer_and_guest` — `usage_limit_per_user` 1 with one prior use by user/e-mail → refused for that user (`user_id` set) and for a guest with that e-mail.
  - `test_line_the_shop_will_not_add` — product with `set_stock_status( 'outofstock' )` → `in_cart_quantity === 0`, `total == 0`, a notice present; sold-individually product ×3 → `in_cart_quantity === 1`.
  - `test_duplicate_product_lines_share_the_item` — same product ×1 and ×2 → `lines[0].total == 319`, `lines[1].total == 638`, both `unit_price == 319`.
  - `test_tax_is_included` — enable taxes with a 21 % standard rate and prices entered incl. tax (`woocommerce_prices_include_tax` yes) → `lines[0].total == 319`, `lines[0].tax == 55.36`.
  - `test_shipping_only_with_a_method` — flat_rate zone CZ cost 89, `shipping_method => 'flat_rate:<instance>'`, shipping country CZ → `totals.shipping == 89`.
  - `test_coupon_usage_is_not_counted` — after a successful percent run, `( new WC_Coupon( 'c20' ) )->get_usage_count() === 0` and no `shop_order` exists (`wc_get_orders( array( 'limit' => -1 ) )` empty).

- [ ] **Step 2: Run, expect FAIL** — `--filter Test_Miguel_Coupon_Cart_Probe`.

- [ ] **Step 3: Implement `price()`** — inside `run()`: `add_to_cart()` each line, remembering keys by index; when `$coupon_code` given, capture `woocommerce_coupon_error` (code, message) for the `apply_coupon()` call as `check_coupon()` on the branch does; `calculate_shipping()` when a method is set; `calculate_totals()`; read the snapshot from `$cart->get_cart()` and the totals getters.

- [ ] **Step 4: Run, expect PASS.**

- [ ] **Step 5: Commit** — `feat(coupons): price a throwaway cart with or without a coupon (SMP-21)`.

---

### Task 3: `POST /coupons/preview` replaces `/coupons/validate`

**Files:**
- Create: `includes/class-miguel-coupon-preview-api.php`
- Delete: `includes/class-miguel-coupon-validate-api.php`, `tests/unit/test-coupon-validate-api.php`
- Modify: `includes/class-miguel.php` (include, container key `coupon_preview_api` replaces `coupon_validate_api`, `register_hooks()`)
- Modify: `includes/class-miguel-order-create-api.php` (the `get_coupon_codes_from_payload` docblock names `/coupons/preview`)
- Modify: `docs/openapi.yaml` (path `/coupons/preview`, schemas `CouponPreviewRequest`, `CouponPreviewResponse` replacing the three `CouponValidate*`/`CouponError`; `coupon_lines` description), `CHANGELOG.md` (rewrite the 1.12.0 `/coupons/validate` bullet), `readme.txt` (same), `languages/miguel-cs_CZ.po`, `languages/miguel-en_US.po` (new/changed strings)
- Test: `tests/unit/test-coupon-preview-api.php`

**Interfaces:**
- Consumes: `Miguel_Coupon_Cart_Probe::price()` (Task 2), `Miguel_Product_Code_Resolver::resolve_product_code()`, `Miguel_Rest_Auth_Trait`.
- Produces: `Miguel_Coupon_Preview_Api` with `__construct( Miguel_Hook_Manager_Interface $hook_manager, $resolver = null, $probe = null )`, `register_hooks()`, `register_routes()`, `preview_coupon( WP_REST_Request $request ): WP_REST_Response|WP_Error`. Private `build_customer( array $payload ): array` and `unchecked_restrictions( WC_Coupon $coupon, array $customer ): string[]` (returns `array()` in this task; Task 4 fills it).

Response assembly (spec "Response"): `before = price( $lines, null, $customer )`, `after = price( $lines, $code, $customer )`.
- `applied` = formatted code ∈ `after.coupons`. When not applied: `error_code` = `after.error_code ?? 0`, `message` = `after.message` ?? last notice ?? `__( 'The coupon was removed from the cart.', 'miguel' )`.
- `coupon` = null when the coupon does not exist (`get_id()` 0), else `code`, `discount_type`, `amount` (float), `description`, `free_shipping` (bool), `expires_at` (`get_date_expires()` → `DATE_ATOM` or null).
- `lines[i]` = `product_code`, `quantity` (sent), `in_cart_quantity` (after), `before`/`after` = `{unit_price,total,tax}`, `discount = before.total − after.total`, `discounted = discount > 0`.
- `added_lines` = `after.extra` whose `key` is not in `before.extra`; `product_code` via the product's Miguel code (reverse of the resolver: the code under which `get_product_code_details_map()` lists that product id, else null), plus `product_id`, `sku`, `name`, `quantity`, `unit_price`, `total`, `tax`, `source` = `'smart_coupons_action'` when `source` is set, else `'unknown'`.
- `removed_lines` = lines with `after.in_cart_quantity < before.in_cart_quantity`: `{product_code, quantity: difference}`.
- `gift_choices` = null (Task 4). `totals` = `{before, after}`. `other_coupons` = (`before.coupons` ∪ `after.coupons`) minus the code. `unchecked` = `unchecked_restrictions()`. `notices` = `after.notices` (deduplicated). `currency` = `get_woocommerce_currency()`.
- Request: `code`, `line_items[{product_code, quantity}]` (a sent `price` is ignored), optional `email`, `shipping_method` (string matching `/^[a-z0-9_-]+:\d+$/i`, else 400 `coupon.shipping_method_invalid`), `billing`/`shipping` objects (country/state/city/postcode strings; anything else ignored). `user_id` = shop user with that e-mail, else 0.
- Any `Throwable` from the probe → `WP_Error( 'coupon.preview_failed', esc_html__( 'The shop could not price the cart.', 'miguel' ), array( 'status' => 500 ) )`, logged with `Miguel::debug_log`.

- [ ] **Step 1: Write the failing tests** (`Test_Miguel_Coupon_Preview_Api`; port the hook/route/auth/400/409 tests from the validate test with the new names, then):
  - `test_route_is_registered_and_validate_is_gone` — `/miguel/v1/coupons/preview` present with `validate_api_access`; `/miguel/v1/coupons/validate` absent.
  - `test_applied_percent_coupon_response_shape` — one line BOOK-A 319, coupon `c20` 20 % → status 200; `applied === true`; `error_code === null`; `coupon.discount_type === 'percent'`; `lines[0].before.total == 319`, `lines[0].after.total == 255.2`, `lines[0].discount == 63.8`, `lines[0].discounted === true`; `added_lines === []`; `totals.after.total == 255.2`; `other_coupons === []`; `unchecked === []`; `currency === get_woocommerce_currency()`.
  - `test_refused_coupon_is_200_with_the_cart` — expired coupon → 200, `applied === false`, `error_code === WC_Coupon::E_WC_COUPON_EXPIRED`, `lines[0].after == lines[0].before`, `discounted === false`.
  - `test_unknown_coupon_has_null_coupon` — `coupon === null`, `error_code === WC_Coupon::E_WC_COUPON_NOT_EXIST`.
  - `test_code_is_normalised` — `' C20 '` → `applied === true`, `coupon.code === 'c20'`.
  - `test_free_shipping_flag` — coupon with `free_shipping` true → `coupon.free_shipping === true`, `totals.after.shipping === null`.
  - `test_line_not_in_cart_is_reported` — out-of-stock line → 200, `lines[0].in_cart_quantity === 0`.
  - `test_malformed_shipping_method_is_400` — `'flat_rate'` → 400 `coupon.shipping_method_invalid`.
  - `test_probe_failure_is_500_and_restores` — probe double whose `price()` throws `Error` → `WP_Error` code `coupon.preview_failed`, status 500.
  - `test_nothing_persists` — after an applied call: no orders, `usage_count` 0, sessions table count unchanged.

- [ ] **Step 2: Run, expect FAIL** — `--filter Test_Miguel_Coupon_Preview_Api`.

- [ ] **Step 3: Implement the class, delete the validate class and test, update registration and docs.** Remove the two now-unused 400 strings that mention `price` from the `.po` files; add the new strings with Czech translations.

- [ ] **Step 4: Run, expect PASS**; then `scripts/phpunit_docker.sh` — whole suite green.

- [ ] **Step 5: Commit** — `feat(coupons): POST /coupons/preview replaces /coupons/validate (SMP-21)`.

---

### Task 4: Smart Coupons and Melvil behaviour, pinned with mocks

**Files:**
- Create: `tests/helpers/class-miguel-helper-coupon-plugins.php` (mocks; required by the tests that use them, not by `tests/bootstrap.php`)
- Modify: `includes/class-miguel-coupon-preview-api.php`
- Test: `tests/unit/test-coupon-preview-plugins.php`

**Interfaces:**
- Consumes: Tasks 1–3.
- Produces in the API class:
  - `unchecked_restrictions()` → `'location'` when the coupon's meta `sa_cbl_locations_lookup_in['address']` is `billing` or `shipping` and that address was not sent; `'shipping_method'` when meta `wc_sc_shipping_method_ids` is a non-empty array and no `shipping_method` was sent.
  - While pricing with `'location'` unchecked: `remove_filter( 'woocommerce_coupon_is_valid', array( WC_SC_Coupons_By_Location::get_instance(), 'validate' ), 11 )` for the two probe calls, re-added in `finally` (only when that class exists).
  - `gift_choices` → when meta `wc_sc_no_of_selectable_product` is `'yes'`: `array( 'products' => [ {product_code|null, product_id, sku, name, quantity, discount_type, discount_amount} ] )` from meta `wc_sc_add_product_details`; else null.
- Mocks (one class each, each with `install()` adding its hooks and `uninstall()` removing them; tests call `uninstall()` in `tearDown`): mimic hooks and observable effects only.
  - `Miguel_Mock_Sc_Gift` — on `woocommerce_applied_coupon` for coupons with meta `wc_sc_add_product_details`: `WC()->cart->add_to_cart( $id, $qty, 0, array(), array( 'wc_sc_product_source' => $code ) )`; on `woocommerce_add_to_cart` touches `WC()->session->set( 'wc_sc_cheapest_costliest_items_session', … )` and `wc_add_notice( 'Gift added', 'success' )`.
  - `WC_SC_Coupons_By_Location` (same name as the real class, with `get_instance()` and `validate( $valid, $coupon, $discounts )` at priority 11) — throws `new Exception( 'Coupon is not valid for the billing address' )` when the coupon has location meta and `WC()->customer` has an empty billing country.
  - `Miguel_Mock_Sc_Payment_Method` — `woocommerce_coupon_is_valid` throws code 0 when meta `wc_sc_payment_method_ids` is set and `WC()->session->get( 'chosen_payment_method' )` is not in it.
  - `Miguel_Mock_Sc_Revalidate` — on `woocommerce_after_calculate_totals` removes a coupon with meta `mock_remove_on_totals` from `WC()->cart` and `wc_add_notice( 'Coupon removed by rule', 'error' )`.
  - `Miguel_Mock_Sc_Store_Credit` — coupon type `smart_coupon` registered via `woocommerce_coupon_discount_types`; on `woocommerce_after_calculate_totals` subtracts the coupon amount (once, guarded by a property set on the cart) from `$cart->set_total()` and `set_discount_total()`.
  - `Miguel_Mock_Sc_Auto_Apply` — on `woocommerce_add_to_cart` applies coupon `auto5` to `WC()->cart` if not applied.
  - `Miguel_Mock_Melvil_Pricing` — on `woocommerce_before_calculate_totals` priority 20 sets each item's price to 80 % when `c20` is applied, and `woocommerce_coupon_get_discount_amount` returns 0.
  - The test class is skipped (`markTestSkipped`) when the real `WC_SC_Coupons_By_Location` is already loaded before the helper is required (Task 5's local run).

- [ ] **Step 1: Write the failing tests** (`Test_Miguel_Coupon_Preview_Plugins`):
  - `test_gift_product_is_an_added_line` — gift BOOK-G ×1 on coupon `gift` → `added_lines[0].product_code === 'BOOK-G'`, `source === 'smart_coupons_action'`, `notices` contains `Gift added`, no PHP error from the session use.
  - `test_selectable_gift_lists_choices` — `wc_sc_no_of_selectable_product` yes, two products in `wc_sc_add_product_details` → `added_lines === []`, `gift_choices.products` has both with product codes.
  - `test_location_rule_without_address_is_unchecked_not_refused` — `applied === true`, `unchecked === ['location']`; with `billing` country `SK` against a CZ rule → `applied === false`, `unchecked === []`.
  - `test_location_filter_is_restored` — after the call `has_filter( 'woocommerce_coupon_is_valid', array( WC_SC_Coupons_By_Location::get_instance(), 'validate' ) ) === 11`.
  - `test_shipping_method_rule_without_method_is_unchecked` — meta `wc_sc_shipping_method_ids` `['flat_rate']` → `unchecked === ['shipping_method']`.
  - `test_payment_method_rule_passes_for_miguel` — `wc_sc_payment_method_ids` `['miguel']` → applied; `['bacs']` → refused, `error_code === 0`, message from the mock.
  - `test_coupon_removed_during_totals_is_not_applied` — Review Focus: `applied === false`, `error_code === 0`, `message === 'Coupon removed by rule'`.
  - `test_store_credit_lands_in_totals` — `smart_coupon` 100 on a 319 cart → `totals.after.total == 219`, `lines[0].after.total == 319`; the *before* run does not leave state that changes it (run the route twice in one test, same result).
  - `test_auto_applied_coupon_is_reported` — `other_coupons === ['auto5']`, present in both totals.
  - `test_melvil_style_discount_in_line_price` — `lines[0].after.total == 255.2`, `discounted === true`, `totals.after.discount == 0`.

- [ ] **Step 2: Run, expect FAIL** — `--filter Test_Miguel_Coupon_Preview_Plugins`.

- [ ] **Step 3: Implement** `unchecked_restrictions()`, the location filter bypass and `gift_choices` in the API class; fix whatever the mocks expose in Tasks 1–2 (session use, state between runs).

- [ ] **Step 4: Run, expect PASS**; then `scripts/phpunit_docker.sh`.

- [ ] **Step 5: Commit** — `feat(coupons): Smart Coupons gifts, unchecked rules and store credit in the preview (SMP-21)`.

---

### Task 5: Local-only check against the real plugins, then delete it

**Files:**
- Modify: `tests/bootstrap.php` — committed: when env `MIGUEL_EXTRA_PLUGINS` is set (colon-separated paths relative to the project root), `require_once` each in `_manually_load_plugin()` after WooCommerce, before Miguel. No plugin names in the code.
- Local only (never `git add`; delete in Step 6): `temp/plugins/` (unpacked `woocommerce-smart-coupons.zip` from the repo root of the main checkout; `woocommerce-melvil.zip`, `wc-bundle.zip`, `melvil-customer.zip` from EMMA-424's attachments, fetched through the YouTrack MCP), `tests/unit/test-local-coupon-plugins.php` (listed in `.git/info/exclude`, not `.gitignore`).

- [ ] **Step 1: Commit the bootstrap hook** — test: the suite still passes without the env var (`scripts/phpunit_docker.sh`). Commit `test: load extra plugins from MIGUEL_EXTRA_PLUGINS for local runs`.

- [ ] **Step 2: Unpack the zips into `temp/plugins/`** and add `tests/unit/test-local-coupon-plugins.php` to `.git/info/exclude`. Check: `git status --short` shows nothing new.

- [ ] **Step 3: Write the local tests** — the Task 4 cases rebuilt on real Smart Coupons coupons (meta written as the admin would: `wc_sc_add_product_details`, `sa_cbl_locations_lookup_in` + `sa_cbl_billing_locations`, `wc_sc_payment_method_ids`, `wc_sc_shipping_method_ids`, `smart_coupon` type with `woocommerce_smart_coupon_apply_before_tax` both `no` and `yes`, auto-apply on), plus one Melvil case: with `woocommerce-melvil` active, a logged-in customer and a 20 % coupon → `lines[0].after.total < lines[0].before.total`, `totals.after.discount == 0`. Also: no PHP error/notice, `woocommerce_sessions` count unchanged.

- [ ] **Step 4: Run** — `docker compose -p miguel-woocommerce -f docker-compose.test.yml run --rm -e MIGUEL_EXTRA_PLUGINS=temp/plugins/woocommerce-smart-coupons/woocommerce-smart-coupons.php:temp/plugins/… phpunit --filter Test_Miguel_Local_Coupon_Plugins`. Every failure is a bug in Tasks 1–4 or a wrong mock: fix the code, update the mock and its committed test to match the real behaviour, commit those fixes normally.

- [ ] **Step 5: Run the committed suite** — `scripts/phpunit_docker.sh` green.

- [ ] **Step 6: Delete the local material** — `rm -rf temp/plugins tests/unit/test-local-coupon-plugins.php`, remove the line from `.git/info/exclude`. Check: `git status --short` clean, `ls temp/plugins` fails.
