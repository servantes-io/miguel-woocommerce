# SMP-14 — No Shipping on Digital-Only Orders — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An order Miguel creates through `POST /miguel/v1/orders` that holds only digital formats carries no shipping address and no shipping line; orders with something to deliver keep requiring both.

**Architecture:** The shipping checks move out of `validate_required_order_fields()` (which runs before line items are resolved) into a new step at the end of `prepare_payload_for_wc_order()`, where every line item has a `product_id`. A new `order_needs_delivery()` applies the rule "product needs shipping and is not downloadable"; `prepare_shipping_for_wc_order()` then either enforces the two old checks or drops `shipping` / `shipping_lines` from a digital-only payload when every line is free. WooCommerce stores no shipping item, so it hides the address by itself.

**Tech Stack:** PHP 8.1, WordPress 6.5+, WooCommerce 7.9+ (`WC_REST_Orders_Controller`), PHPUnit on `WC_Unit_Test_Case` (via `Miguel_Test_Case`), Docker.

**Spec:** `docs/superpowers/specs/2026-09-13-smp-14-digital-orders-without-shipping-design.md`

## Global Constraints

- **Order of work:** this plan is implemented **after SMP-12** (`order_note`: a check in `validate_required_order_fields()`, an `unset` in `prepare_payload_for_wc_order()`, note recording in `create_order()`) **and SMP-13** (payment-title fill-in inside `prepare_payload_for_wc_order()`) have landed in the same file. Every step names methods, never line numbers. Keep whatever those issues added; only the shipping blocks move.
- **Delivery rule (verbatim from the spec):** a line item needs delivery when `$product->needs_shipping() && ! $product->is_downloadable()`, where the product is `wc_get_product( variation_id )` for a positive `variation_id`, else `wc_get_product( product_id )`. A product that cannot be loaded needs delivery. The order needs delivery when at least one item does.
- **Free shipping line:** `total` absent, `null`, `''`, or numerically `0`. A non-numeric total counts as a cost. No lines at all counts as free.
- **No new error codes.** `order.shipping_required` / `order.shipping_lines_required` keep their exact messages (`Order shipping object is required.` / `Order shipping_lines array is required.` — existing translations must still match), status 409 and `data.field`.
- **Idempotency is untouched:** the payload hash is computed in `create_order()` before `prepare_payload_for_wc_order()` runs. Do not move that.
- **`billing` stays required.**
- **WordPress coding standards, as in the surrounding file:** tabs, Yoda conditions, `esc_html__( ..., 'miguel' )`, docblock on every method, untyped parameters documented in the docblock (the file's private methods declare no parameter types).
- **Tests run in Docker only; never call `vendor/bin/phpunit` directly on the host.** A git worktree has no `vendor/`, and `docker compose` names its volumes after the directory, so from a worktree:
  - once per worktree: `docker compose -p miguel-woocommerce -f docker-compose.test.yml run --rm --no-deps --entrypoint composer phpunit install --no-interaction --prefer-dist`
  - one class: `docker compose -p miguel-woocommerce -f docker-compose.test.yml run --rm phpunit --filter=Test_Miguel_Order_Create_Api`
  - one test: `docker compose -p miguel-woocommerce -f docker-compose.test.yml run --rm phpunit --filter='Test_Miguel_Order_Create_Api::test_name'`
  - full suite: `docker compose -p miguel-woocommerce -f docker-compose.test.yml run --rm phpunit` (what `make test-docker` runs, with the shared caches)
  - `-p miguel-woocommerce` reuses the WooCommerce/WordPress caches of the main checkout. Don't run two suites at once under the same project name — they share one test database.
- **Coding standard:** `docker compose -p miguel-woocommerce -f docker-compose.test.yml run --rm --no-deps --entrypoint vendor/bin/phpcs phpunit includes/class-miguel-order-create-api.php tests/unit/test-order-create-api.php` must report no errors (CI runs `vendor/bin/phpcs .`).
- **Test products and SKUs:** when a test creates more than one product, set an explicit unique SKU on each one it creates with `WC_Helper_Product::create_simple_product()`, and create those **before** `Miguel_Helper_Product::create_downloadable_product()`. Older WooCommerce test helpers save every simple product with the same `DUMMY SKU`, and WooCommerce rejects a duplicate SKU on save. `Miguel_Helper_Product::create_virtual_product()` does **not** save its `set_virtual()` call — don't use it where virtual-ness matters; build and save the product yourself.
- **`needs_shipping_address()` depends on the `woocommerce_calc_shipping` option** (false when it is `'no'`). Only assert it as `false` (true in both cases for an order without shipping items); prove "shipping kept" with `get_shipping_methods()` and `has_shipping_address()` instead.

---

## File Structure

| Action | Path | Responsibility |
|--------|------|----------------|
| Modify | `includes/class-miguel-order-create-api.php` | New `order_needs_delivery()`, `shipping_lines_are_free()`, `prepare_shipping_for_wc_order()`; `prepare_payload_for_wc_order()` ends by calling the latter; the shipping blocks leave `validate_required_order_fields()` |
| Modify | `tests/unit/test-order-create-api.php` | Test helpers; unit tests for the rule and the payload step; end-to-end `create_order()` tests; three existing tests adjusted |
| Modify | `docs/openapi.yaml` | `CreateOrderRequest`: `shipping` / `shipping_lines` optional, with when-required wording |
| Modify | `CHANGELOG.md` | Entry under the unreleased `1.10.0` |

No new files, no new classes, no new translatable strings.

### Existing tests in `tests/unit/test-order-create-api.php` — impact

| Test | Posts without shipping / relies on order | After this change |
|---|---|---|
| `test_prepare_payload_for_wc_order_maps_product_code_to_product_id` | no shipping; downloadable product | passes (digital-only) |
| `test_prepare_payload_for_wc_order_maps_print_code_to_product_id` | no shipping; **printed** product | **fails** with `order.shipping_required` → add `shipping` + `shipping_lines` to its payload (Task 2) |
| `test_prepare_payload_for_wc_order_strips_send_email_flags` | no shipping; downloadable | passes |
| `test_prepare_payload_for_wc_order_strips_email_template_flag` | no shipping; downloadable | passes |
| `test_prepare_payload_for_wc_order_rejects_ambiguous_product_code` | fails in the line-item loop | passes (error before shipping) |
| `…_rejects_zero_quantity`, `…_negative_quantity`, `…_missing_quantity`, `…_non_integer_quantity` | fail in the line-item loop | pass |
| `…_rejects_missing_line_items`, `…_empty_line_items`, `…_invalid_line_item_structure`, `…_missing_product_reference`, `…_conflicting_product_references` | fail before/in the loop | pass |
| `test_validate_required_order_fields_accepts_missing_status` / `…_rejects_invalid_email_template` / `…_rejects_missing_payment_method` / `…_rejects_missing_billing` / `…_accepts_minimal_valid_payload` | full payload | pass |
| `test_validate_required_order_fields_rejects_missing_shipping` | relies on the check in `validate_required_order_fields()` | **fails** (returns `true`) → replaced by a printed-product test on `prepare_payload_for_wc_order()` (Task 2) |
| `test_validate_required_order_fields_rejects_missing_shipping_lines` | same | **fails** → replaced likewise (Task 2) |
| `…_falls_back_from_invalid_customer_id_to_user_email`, `…_keeps_valid_customer_id`, `…_removes_customer_id_when_no_email_match_exists` | minimal payload (`flat_rate` 0.00) + downloadable | pass; shipping is now dropped from the result, which they don't assert on |
| `test_create_order_does_not_queue_sync_back_to_miguel` | downloadable + `flat_rate` 0.00 | passes; the order is now created without shipping |
| `test_create_order_for_printed_book_musk_succeeds_by_bare_sku_when_no_suffix` / `…_with_suffixed_code` | printed + `wc_zasilkovna` line without total | pass (needs delivery; both present) |

---

### Task 1: The delivery rule — `order_needs_delivery()`

**Files:**
- Modify: `includes/class-miguel-order-create-api.php` (new private method, placed right after `prepare_line_item_for_wc_order()`)
- Test: `tests/unit/test-order-create-api.php`

**Interfaces:**
- Consumes: nothing from other tasks.
- Produces: `private function order_needs_delivery( $line_items )`, returning `bool` (no declared types, like the rest of the file) — `$line_items` is the prepared `line_items` array (each item has `product_id`, optionally `variation_id`). Test helpers on the test class, reused by Tasks 2 and 3: `private function invoke_private( $object, $method_name, array $args = array() )`, `private function line_item( $product_id )` (returns `array( 'product_id' => $product_id, 'quantity' => 1 )`), `private function create_printed_product( $sku )`, `private function create_virtual_product_without_download( $sku )`.

- [ ] **Step 1: Add the test helpers**

Add these private helpers to `Test_Miguel_Order_Create_Api`, directly below `get_minimal_valid_payload()`:

```php
	/**
	 * Invoke a private method of the API.
	 *
	 * @param object $object      Object under test.
	 * @param string $method_name Method name.
	 * @param array  $args        Arguments.
	 * @return mixed
	 */
	private function invoke_private( $object, $method_name, array $args = array() ) {
		$method = ( new ReflectionClass( $object ) )->getMethod( $method_name );
		$method->setAccessible( true );

		return $method->invokeArgs( $object, $args );
	}

	/**
	 * A one-piece line item for a product. One key per line: WPCS rejects single-line
	 * associative arrays with more than one key.
	 *
	 * @param int $product_id Product ID.
	 * @return array
	 */
	private function line_item( $product_id ) {
		return array(
			'product_id' => $product_id,
			'quantity'   => 1,
		);
	}

	/**
	 * Create a printed book: neither virtual nor downloadable, with an explicit SKU.
	 *
	 * @param string $sku Unique SKU.
	 * @return WC_Product
	 */
	private function create_printed_product( $sku ) {
		$product = WC_Helper_Product::create_simple_product();
		$product->set_downloadable( false );
		$product->set_virtual( false );
		$product->set_sku( $sku );
		$product->save();

		return $product;
	}

	/**
	 * Create a virtual product that is not downloadable (e.g. a voucher), with an explicit SKU.
	 *
	 * @param string $sku Unique SKU.
	 * @return WC_Product
	 */
	private function create_virtual_product_without_download( $sku ) {
		$product = WC_Helper_Product::create_simple_product();
		$product->set_downloadable( false );
		$product->set_virtual( true );
		$product->set_sku( $sku );
		$product->save();

		return $product;
	}
```

- [ ] **Step 2: Write the failing tests**

Append to `Test_Miguel_Order_Create_Api`:

```php
	/**
	 * An e-book (virtual + downloadable) needs no delivery.
	 */
	public function test_order_needs_delivery_is_false_for_downloadable_product() {
		$ebook = Miguel_Helper_Product::create_downloadable_product();
		$api   = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$this->assertFalse(
			$this->invoke_private( $api, 'order_needs_delivery', array( array( $this->line_item( $ebook->get_id() ) ) ) )
		);
	}

	/**
	 * A downloadable product whose "virtual" box nobody ticked still needs no delivery.
	 */
	public function test_order_needs_delivery_is_false_for_downloadable_product_that_is_not_virtual() {
		$ebook = WC_Helper_Product::create_simple_product();
		$ebook->set_downloadable( true );
		$ebook->set_virtual( false );
		$ebook->set_sku( 'smp14-downloadable-not-virtual' );
		$ebook->save();

		$api = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$this->assertFalse(
			$this->invoke_private( $api, 'order_needs_delivery', array( array( $this->line_item( $ebook->get_id() ) ) ) )
		);
	}

	/**
	 * A virtual product that is not downloadable (e.g. a voucher) needs no delivery.
	 */
	public function test_order_needs_delivery_is_false_for_virtual_product() {
		$voucher = $this->create_virtual_product_without_download( 'smp14-voucher' );
		$api     = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$this->assertFalse(
			$this->invoke_private( $api, 'order_needs_delivery', array( array( $this->line_item( $voucher->get_id() ) ) ) )
		);
	}

	/**
	 * A printed book needs delivery.
	 */
	public function test_order_needs_delivery_is_true_for_printed_product() {
		$printed = $this->create_printed_product( 'smp14-printed' );
		$api     = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$this->assertTrue(
			$this->invoke_private( $api, 'order_needs_delivery', array( array( $this->line_item( $printed->get_id() ) ) ) )
		);
	}

	/**
	 * One printed book among e-books makes the whole order need delivery.
	 */
	public function test_order_needs_delivery_is_true_for_mixed_order() {
		$printed = $this->create_printed_product( 'smp14-printed-mixed' );
		$ebook   = Miguel_Helper_Product::create_downloadable_product();
		$api     = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$this->assertTrue(
			$this->invoke_private(
				$api,
				'order_needs_delivery',
				array(
					array(
						$this->line_item( $ebook->get_id() ),
						$this->line_item( $printed->get_id() ),
					),
				)
			)
		);
	}

	/**
	 * A product that cannot be loaded counts as needing delivery.
	 */
	public function test_order_needs_delivery_is_true_for_unknown_product() {
		$api = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$this->assertTrue(
			$this->invoke_private( $api, 'order_needs_delivery', array( array( $this->line_item( 999999 ) ) ) )
		);
	}

	/**
	 * With a variation_id the variation decides, not its parent.
	 */
	public function test_order_needs_delivery_uses_the_variation_when_given() {
		$variable     = WC_Helper_Product::create_variation_product();
		$variation_id = $variable->get_children()[0];
		$variation    = wc_get_product( $variation_id );
		$variation->set_virtual( true );
		$variation->save();

		$api = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$this->assertFalse(
			$this->invoke_private(
				$api,
				'order_needs_delivery',
				array( array( array_merge( $this->line_item( $variable->get_id() ), array( 'variation_id' => $variation_id ) ) ) )
			),
			'A virtual variation needs no delivery.'
		);
		$this->assertTrue(
			$this->invoke_private(
				$api,
				'order_needs_delivery',
				array( array( $this->line_item( $variable->get_id() ) ) )
			),
			'Without variation_id the non-virtual parent decides.'
		);
	}
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `docker compose -p miguel-woocommerce -f docker-compose.test.yml run --rm phpunit --filter='Test_Miguel_Order_Create_Api::test_order_needs_delivery'`
Expected: 7 errors, `ReflectionException: Method Miguel_Order_Create_Api::order_needs_delivery() does not exist`.

- [ ] **Step 4: Implement `order_needs_delivery()`**

In `includes/class-miguel-order-create-api.php`, add directly after `prepare_line_item_for_wc_order()`:

```php
	/**
	 * Determine whether at least one line item of the order has to be delivered.
	 *
	 * An item needs delivery when its product needs shipping and is not downloadable. The
	 * downloadable test catches an e-book whose "virtual" box nobody ticked: it has nothing to
	 * deliver either. A product that cannot be loaded counts as needing delivery, which keeps
	 * the stricter validation and leaves WooCommerce to reject the unknown product itself.
	 *
	 * @param array $line_items Prepared line items, each carrying a product_id.
	 * @return bool
	 */
	private function order_needs_delivery( $line_items ) {
		foreach ( $line_items as $line_item ) {
			$variation_id = absint( $line_item['variation_id'] ?? 0 );
			$product = wc_get_product( $variation_id > 0 ? $variation_id : absint( $line_item['product_id'] ?? 0 ) );

			if ( ! $product || ( $product->needs_shipping() && ! $product->is_downloadable() ) ) {
				return true;
			}
		}

		return false;
	}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `docker compose -p miguel-woocommerce -f docker-compose.test.yml run --rm phpunit --filter='Test_Miguel_Order_Create_Api::test_order_needs_delivery'`
Expected: `OK (7 tests, 8 assertions)`.

- [ ] **Step 6: Commit**

```bash
git add includes/class-miguel-order-create-api.php tests/unit/test-order-create-api.php
git commit -m "feat(orders): tell whether a Miguel order has anything to deliver"
```

---

### Task 2: Enforce or drop shipping after line items are resolved

**Files:**
- Modify: `includes/class-miguel-order-create-api.php` — `validate_required_order_fields()`, `prepare_payload_for_wc_order()`; new `shipping_lines_are_free()` and `prepare_shipping_for_wc_order()` placed right after `order_needs_delivery()`
- Test: `tests/unit/test-order-create-api.php`

**Interfaces:**
- Consumes: `order_needs_delivery( $line_items )` (returns `bool`) and the test helpers `invoke_private()`, `line_item()`, `create_printed_product()` from Task 1; the existing `build_order_payload_error( $code, $message, $data )` (returns `WP_Error`).
- Produces: `private function prepare_shipping_for_wc_order( $payload )` returning `array|WP_Error`; `private function shipping_lines_are_free( $shipping_lines )` returning `bool`; `prepare_payload_for_wc_order()` now returns the payload **without** `shipping` / `shipping_lines` for a digital-only order with free lines. Test helper `private function get_placeholder_shipping(): array` (today's backend shape), reused by Task 3.

- [ ] **Step 1: Add the placeholder-shipping helper**

Add below `create_virtual_product_without_download()` in the test class:

```php
	/**
	 * The shipping the Miguel backend sends today with a digital-only order: a copy of the
	 * billing address and a zero-cost free_shipping line.
	 *
	 * @return array
	 */
	private function get_placeholder_shipping() {
		return array(
			'shipping'       => array(
				'first_name' => 'Jan Novák',
				'address_1'  => 'Václavské náměstí 1',
				'city'       => 'Praha',
				'postcode'   => '11000',
				'country'    => 'CZ',
			),
			'shipping_lines' => array(
				array(
					'method_id'    => 'free_shipping',
					'method_title' => 'Free Shipping',
					'total'        => '0.00',
				),
			),
		);
	}
```

- [ ] **Step 2: Write the failing tests**

Append to the test class:

```php
	/**
	 * Today's backend payload for an e-book: the placeholder shipping is dropped.
	 */
	public function test_prepare_payload_drops_free_shipping_from_digital_only_order() {
		$ebook = Miguel_Helper_Product::create_downloadable_product();
		$api   = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$payload = array_merge(
			$this->get_placeholder_shipping(),
			array( 'line_items' => array( $this->line_item( $ebook->get_id() ) ) )
		);

		$result = $this->invoke_private( $api, 'prepare_payload_for_wc_order', array( $payload ) );

		$this->assertIsArray( $result );
		$this->assertArrayNotHasKey( 'shipping', $result );
		$this->assertArrayNotHasKey( 'shipping_lines', $result );
	}

	/**
	 * A digital-only order may omit shipping altogether.
	 */
	public function test_prepare_payload_accepts_digital_only_order_without_shipping() {
		$ebook = Miguel_Helper_Product::create_downloadable_product();
		$api   = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$result = $this->invoke_private(
			$api,
			'prepare_payload_for_wc_order',
			array( array( 'line_items' => array( $this->line_item( $ebook->get_id() ) ) ) )
		);

		$this->assertIsArray( $result );
		$this->assertArrayNotHasKey( 'shipping', $result );
		$this->assertArrayNotHasKey( 'shipping_lines', $result );
	}

	/**
	 * A shipping line without a total counts as free.
	 */
	public function test_prepare_payload_drops_shipping_line_without_total_from_digital_only_order() {
		$ebook = Miguel_Helper_Product::create_downloadable_product();
		$api   = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$payload                   = array_merge(
			$this->get_placeholder_shipping(),
			array( 'line_items' => array( $this->line_item( $ebook->get_id() ) ) )
		);
		$payload['shipping_lines'] = array( array( 'method_id' => 'free_shipping' ) );

		$result = $this->invoke_private( $api, 'prepare_payload_for_wc_order', array( $payload ) );

		$this->assertArrayNotHasKey( 'shipping', $result );
		$this->assertArrayNotHasKey( 'shipping_lines', $result );
	}

	/**
	 * A digital-only order whose shipping line has a cost keeps it, and its address, as sent.
	 */
	public function test_prepare_payload_keeps_paid_shipping_on_digital_only_order() {
		$ebook = Miguel_Helper_Product::create_downloadable_product();
		$api   = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$payload                   = array_merge(
			$this->get_placeholder_shipping(),
			array( 'line_items' => array( $this->line_item( $ebook->get_id() ) ) )
		);
		$payload['shipping_lines'] = array(
			array(
				'method_id' => 'flat_rate',
				'total'     => '49.00',
			),
		);

		$result = $this->invoke_private( $api, 'prepare_payload_for_wc_order', array( $payload ) );

		$this->assertSame( $payload['shipping'], $result['shipping'] );
		$this->assertSame( $payload['shipping_lines'], $result['shipping_lines'] );
	}

	/**
	 * A total that is not a number counts as a cost: the line is kept for WooCommerce to judge.
	 */
	public function test_prepare_payload_keeps_shipping_line_with_non_numeric_total_on_digital_only_order() {
		$ebook = Miguel_Helper_Product::create_downloadable_product();
		$api   = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$payload                   = array_merge(
			$this->get_placeholder_shipping(),
			array( 'line_items' => array( $this->line_item( $ebook->get_id() ) ) )
		);
		$payload['shipping_lines'] = array(
			array(
				'method_id' => 'flat_rate',
				'total'     => 'free',
			),
		);

		$result = $this->invoke_private( $api, 'prepare_payload_for_wc_order', array( $payload ) );

		$this->assertArrayHasKey( 'shipping', $result );
		$this->assertSame( $payload['shipping_lines'], $result['shipping_lines'] );
	}

	/**
	 * A printed book still requires a shipping address.
	 */
	public function test_prepare_payload_rejects_printed_order_without_shipping() {
		$printed = $this->create_printed_product( 'smp14-printed-no-address' );
		$api     = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$payload = array_merge(
			$this->get_placeholder_shipping(),
			array( 'line_items' => array( $this->line_item( $printed->get_id() ) ) )
		);
		unset( $payload['shipping'] );

		$result = $this->invoke_private( $api, 'prepare_payload_for_wc_order', array( $payload ) );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertEquals( 'order.shipping_required', $result->get_error_code() );
		$this->assertEquals( 409, $result->get_error_data()['status'] );
		$this->assertEquals( 'shipping', $result->get_error_data()['field'] );
	}

	/**
	 * A printed book still requires shipping lines.
	 */
	public function test_prepare_payload_rejects_printed_order_without_shipping_lines() {
		$printed = $this->create_printed_product( 'smp14-printed-no-lines' );
		$api     = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$payload = array_merge(
			$this->get_placeholder_shipping(),
			array( 'line_items' => array( $this->line_item( $printed->get_id() ) ) )
		);
		unset( $payload['shipping_lines'] );

		$result = $this->invoke_private( $api, 'prepare_payload_for_wc_order', array( $payload ) );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertEquals( 'order.shipping_lines_required', $result->get_error_code() );
		$this->assertEquals( 409, $result->get_error_data()['status'] );
		$this->assertEquals( 'shipping_lines', $result->get_error_data()['field'] );
	}

	/**
	 * An order with a printed book keeps its shipping exactly as sent, zero-cost lines included.
	 */
	public function test_prepare_payload_keeps_shipping_on_mixed_order() {
		$printed = $this->create_printed_product( 'smp14-printed-mixed-payload' );
		$ebook   = Miguel_Helper_Product::create_downloadable_product();
		$api     = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$payload = array_merge(
			$this->get_placeholder_shipping(),
			array(
				'line_items' => array(
					$this->line_item( $ebook->get_id() ),
					$this->line_item( $printed->get_id() ),
				),
			)
		);

		$result = $this->invoke_private( $api, 'prepare_payload_for_wc_order', array( $payload ) );

		$this->assertSame( $payload['shipping'], $result['shipping'] );
		$this->assertSame( $payload['shipping_lines'], $result['shipping_lines'] );
	}

	/**
	 * An unknown product counts as needing delivery, so the old validation applies.
	 */
	public function test_prepare_payload_rejects_unknown_product_without_shipping() {
		$api = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$result = $this->invoke_private(
			$api,
			'prepare_payload_for_wc_order',
			array( array( 'line_items' => array( $this->line_item( 999999 ) ) ) )
		);

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertEquals( 'order.shipping_required', $result->get_error_code() );
	}

	/**
	 * Top-level validation no longer asks for shipping: it cannot know yet whether the order ships.
	 */
	public function test_validate_required_order_fields_accepts_payload_without_shipping() {
		$api = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$payload = $this->get_minimal_valid_payload();
		unset( $payload['shipping'], $payload['shipping_lines'] );

		$this->assertTrue( true === $this->invoke_private( $api, 'validate_required_order_fields', array( $payload ) ) );
	}
```

- [ ] **Step 3: Adjust the existing tests that assumed the old placement**

1. **Delete** `test_validate_required_order_fields_rejects_missing_shipping` and `test_validate_required_order_fields_rejects_missing_shipping_lines` — `test_prepare_payload_rejects_printed_order_without_shipping` / `…_without_shipping_lines` above replace them.
2. In `test_prepare_payload_for_wc_order_maps_print_code_to_product_id`, the printed product now needs delivery, so give its payload shipping. Replace the invoke call's payload array:

```php
		$result = $method->invoke(
			$api,
			array_merge(
				$this->get_placeholder_shipping(),
				array(
					'line_items' => array(
						array(
							'product_code' => 'printed-book-42:print',
							'quantity' => 1,
						),
					),
				)
			)
		);
```

- [ ] **Step 4: Run the tests to verify the new ones fail**

Run: `docker compose -p miguel-woocommerce -f docker-compose.test.yml run --rm phpunit --filter=Test_Miguel_Order_Create_Api`
Expected failures (the rest pass):
- `test_prepare_payload_drops_free_shipping_from_digital_only_order`, `…_drops_shipping_line_without_total_…` — shipping keys still present;
- `test_prepare_payload_rejects_printed_order_without_shipping`, `…_without_shipping_lines`, `…_rejects_unknown_product_without_shipping` — an array is returned instead of a `WP_Error`;
- `test_validate_required_order_fields_accepts_payload_without_shipping` — `order.shipping_required` returned.

(`…_accepts_digital_only_order_without_shipping`, `…_keeps_paid_shipping_…`, `…_keeps_shipping_line_with_non_numeric_total_…`, `…_keeps_shipping_on_mixed_order` already pass on the old code — they pin behaviour the change must not break.)

- [ ] **Step 5: Move the shipping checks**

In `validate_required_order_fields()`, delete the two blocks that return `order.shipping_required` and `order.shipping_lines_required` — the `email_template`, `payment_method` and `billing` checks (and SMP-12's `order_note` check) stay. Update its docblock summary to:

```php
	/**
	 * Validate the top-level fields that do not depend on the line items.
	 *
	 * Shipping is validated later, in prepare_shipping_for_wc_order(), once the line items are
	 * resolved and it is known whether the order has anything to deliver.
	 *
```

Add after `order_needs_delivery()`:

```php
	/**
	 * Whether every shipping line is free: no total, an empty total, or a total of zero.
	 *
	 * A total that is not a number counts as a cost, so a malformed line is kept for
	 * WooCommerce to judge rather than silently dropped.
	 *
	 * @param array $shipping_lines Shipping lines from the payload.
	 * @return bool
	 */
	private function shipping_lines_are_free( $shipping_lines ) {
		foreach ( $shipping_lines as $shipping_line ) {
			$total = is_array( $shipping_line ) ? ( $shipping_line['total'] ?? '' ) : '';
			if ( '' === $total ) {
				continue;
			}

			if ( ! is_numeric( $total ) || 0.0 !== (float) $total ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Require shipping on an order that has something to deliver, and drop it from one that does not.
	 *
	 * A digital-only order loses its shipping address and shipping lines when every line is free,
	 * so WooCommerce stores no shipping and shows no address. A line with a cost is kept, address
	 * and all: dropping it would take the order total away from what the customer paid.
	 *
	 * @param array $payload Request payload with prepared line items.
	 * @return array|WP_Error
	 */
	private function prepare_shipping_for_wc_order( $payload ) {
		if ( $this->order_needs_delivery( $payload['line_items'] ) ) {
			if ( ! array_key_exists( 'shipping', $payload ) || ! is_array( $payload['shipping'] ) || empty( $payload['shipping'] ) ) {
				return $this->build_order_payload_error(
					'order.shipping_required',
					esc_html__( 'Order shipping object is required.', 'miguel' ),
					array( 'field' => 'shipping' )
				);
			}

			if ( ! array_key_exists( 'shipping_lines', $payload ) || ! is_array( $payload['shipping_lines'] ) || empty( $payload['shipping_lines'] ) ) {
				return $this->build_order_payload_error(
					'order.shipping_lines_required',
					esc_html__( 'Order shipping_lines array is required.', 'miguel' ),
					array( 'field' => 'shipping_lines' )
				);
			}

			return $payload;
		}

		$shipping_lines = isset( $payload['shipping_lines'] ) && is_array( $payload['shipping_lines'] ) ? $payload['shipping_lines'] : array();

		if ( ! $this->shipping_lines_are_free( $shipping_lines ) ) {
			Miguel::debug_log(
				'Kept shipping on a digital-only order because a shipping line has a cost',
				array(
					'shipping_line_totals' => array_column( $shipping_lines, 'total' ),
				)
			);

			return $payload;
		}

		unset( $payload['shipping'], $payload['shipping_lines'] );

		Miguel::debug_log(
			'Dropped shipping from a digital-only order',
			array(
				'shipping_lines_dropped' => count( $shipping_lines ),
			)
		);

		return $payload;
	}
```

In `prepare_payload_for_wc_order()`, replace the final `return $payload;` (after the `foreach` over `line_items`, and after anything SMP-13 added there) with:

```php
		return $this->prepare_shipping_for_wc_order( $payload );
```

- [ ] **Step 6: Run the class to verify everything passes**

Run: `docker compose -p miguel-woocommerce -f docker-compose.test.yml run --rm phpunit --filter=Test_Miguel_Order_Create_Api`
Expected: `OK` — every test in the class, including the two musk tests and `test_create_order_does_not_queue_sync_back_to_miguel`.

- [ ] **Step 7: Commit**

```bash
git add includes/class-miguel-order-create-api.php tests/unit/test-order-create-api.php
git commit -m "feat(orders): drop the placeholder shipping from digital-only Miguel orders"
```

---

### Task 3: End to end through `create_order()`, API docs and changelog

**Files:**
- Test: `tests/unit/test-order-create-api.php`
- Modify: `docs/openapi.yaml` (`components.schemas.CreateOrderRequest`)
- Modify: `CHANGELOG.md` (section `## 1.10.0`)

**Interfaces:**
- Consumes: the behaviour of Task 2; helpers `create_printed_product()`, `get_placeholder_shipping()`.
- Produces: helpers `private function post_order( array $payload )` (returns `WP_REST_Response|WP_Error`) and `private function get_digital_order_payload( $product_id, $idempotency_key )` (returns `array`).

- [ ] **Step 1: Write the end-to-end tests**

Add these helpers below `get_placeholder_shipping()`:

```php
	/**
	 * POST an order payload through the Miguel create API.
	 *
	 * @param array $payload Request body.
	 * @return WP_REST_Response|WP_Error
	 */
	private function post_order( array $payload ) {
		$request = new WP_REST_Request( 'POST', '/miguel/v1/orders' );
		$request->add_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $payload ) );

		return ( new Miguel_Order_Create_Api( new Miguel_Hook_Manager() ) )->create_order( $request );
	}

	/**
	 * The payload the Miguel backend sends for a one-item mobile order, without shipping.
	 *
	 * @param int    $product_id      Product ID.
	 * @param string $idempotency_key Idempotency key.
	 * @return array
	 */
	private function get_digital_order_payload( $product_id, $idempotency_key ) {
		return array(
			'idempotency_key' => $idempotency_key,
			'payment_method'  => 'miguel',
			'user_email'      => 'buyer@example.com',
			'billing'         => array(
				'first_name' => 'Jan Novák',
				'address_1'  => 'Václavské náměstí 1',
				'city'       => 'Praha',
				'postcode'   => '11000',
				'country'    => 'CZ',
				'email'      => 'buyer@example.com',
			),
			'line_items'      => array(
				array(
					'product_id' => $product_id,
					'quantity'   => 1,
				),
			),
		);
	}
```

Append the tests:

```php
	/**
	 * Today's backend payload for an e-book creates an order with no shipping at all.
	 */
	public function test_create_order_digital_only_has_no_shipping() {
		$ebook   = Miguel_Helper_Product::create_downloadable_product();
		$payload = array_merge( $this->get_digital_order_payload( $ebook->get_id(), 'smp14-digital' ), $this->get_placeholder_shipping() );

		$response = $this->post_order( $payload );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 201, $response->get_status() );

		$order = wc_get_order( $response->get_data()['id'] );
		$this->assertCount( 0, $order->get_shipping_methods(), 'No shipping line may be stored.' );
		$this->assertSame( '', $order->get_shipping_address_1() );
		$this->assertSame( '', $order->get_shipping_first_name() );
		$this->assertFalse( $order->has_shipping_address() );
		$this->assertFalse( $order->needs_shipping_address() );
		$this->assertSame( 'Václavské náměstí 1', $order->get_billing_address_1(), 'Billing stays.' );

		Miguel_Helper_Order::delete_order( $order->get_id() );
	}

	/**
	 * A digital-only order without any shipping is accepted (it was a 409 before).
	 */
	public function test_create_order_accepts_digital_only_order_without_shipping() {
		$ebook = Miguel_Helper_Product::create_downloadable_product();

		$response = $this->post_order( $this->get_digital_order_payload( $ebook->get_id(), 'smp14-digital-bare' ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 201, $response->get_status() );

		$order = wc_get_order( $response->get_data()['id'] );
		$this->assertCount( 0, $order->get_shipping_methods() );

		Miguel_Helper_Order::delete_order( $order->get_id() );
	}

	/**
	 * A digital-only order with a paid shipping line keeps it, so the total matches the payment.
	 */
	public function test_create_order_digital_only_keeps_paid_shipping() {
		$ebook                     = Miguel_Helper_Product::create_downloadable_product();
		$payload                   = array_merge( $this->get_digital_order_payload( $ebook->get_id(), 'smp14-digital-paid' ), $this->get_placeholder_shipping() );
		$payload['shipping_lines'] = array(
			array(
				'method_id'    => 'flat_rate',
				'method_title' => 'Flat rate',
				'total'        => '49.00',
			),
		);

		$response = $this->post_order( $payload );

		$this->assertSame( 201, $response->get_status() );

		$order = wc_get_order( $response->get_data()['id'] );
		$this->assertCount( 1, $order->get_shipping_methods() );
		$this->assertEquals( 49.0, (float) $order->get_shipping_total() );
		$this->assertGreaterThanOrEqual( 49.0, (float) $order->get_total() );
		$this->assertTrue( $order->has_shipping_address() );

		Miguel_Helper_Order::delete_order( $order->get_id() );
	}

	/**
	 * An order with a printed book keeps its shipping line and address.
	 */
	public function test_create_order_mixed_keeps_shipping() {
		$printed                   = $this->create_printed_product( 'smp14-printed-e2e' );
		$ebook                     = Miguel_Helper_Product::create_downloadable_product();
		$payload                   = array_merge( $this->get_digital_order_payload( $ebook->get_id(), 'smp14-mixed' ), $this->get_placeholder_shipping() );
		$payload['line_items'][]   = array(
			'product_id' => $printed->get_id(),
			'quantity'   => 1,
		);
		$payload['shipping_lines'] = array(
			array(
				'method_id'    => 'flat_rate',
				'method_title' => 'Flat rate',
				'total'        => '89.00',
			),
		);

		$response = $this->post_order( $payload );

		$this->assertSame( 201, $response->get_status() );

		$order = wc_get_order( $response->get_data()['id'] );
		$this->assertCount( 1, $order->get_shipping_methods() );
		$this->assertSame( 'Václavské náměstí 1', $order->get_shipping_address_1() );

		Miguel_Helper_Order::delete_order( $order->get_id() );
	}

	/**
	 * A retried digital-only request replays the order it created (the hash covers the payload as sent).
	 */
	public function test_create_order_digital_only_replays_idempotently() {
		$ebook   = Miguel_Helper_Product::create_downloadable_product();
		$payload = array_merge( $this->get_digital_order_payload( $ebook->get_id(), 'smp14-replay' ), $this->get_placeholder_shipping() );

		$first  = $this->post_order( $payload );
		$second = $this->post_order( $payload );

		$this->assertSame( 201, $first->get_status() );
		$this->assertSame( 200, $second->get_status() );
		$this->assertTrue( $second->get_data()['idempotent_replay'] );
		$this->assertSame( $first->get_data()['id'], $second->get_data()['id'] );

		Miguel_Helper_Order::delete_order( $first->get_data()['id'] );
	}
```

- [ ] **Step 2: Run them**

Run: `docker compose -p miguel-woocommerce -f docker-compose.test.yml run --rm phpunit --filter='Test_Miguel_Order_Create_Api::test_create_order'`
Expected: `OK` for all `test_create_order_*` tests (the five new ones plus the three existing). These pin the whole route on top of Task 2; if one fails, the bug is in Task 2's code or in how WooCommerce stores the payload — fix the code, not the assertion.

- [ ] **Step 3: Update `docs/openapi.yaml`**

In `components.schemas.CreateOrderRequest`:

1. Change `required: [payment_method, billing, shipping, shipping_lines, line_items]` to:

```yaml
      required: [payment_method, billing, line_items]
```

2. Replace the `shipping` and `shipping_lines` properties (drop `minItems: 1`) with:

```yaml
        shipping:
          description: >
            Required when at least one line item needs delivery, i.e. its product needs
            shipping and is not downloadable. On an order where no item needs delivery it is
            dropped together with `shipping_lines` when every shipping line is free.
          allOf:
            - $ref: '#/components/schemas/ShippingAddress'
        shipping_lines:
          type: array
          description: >
            Required, with at least one line, when at least one line item needs delivery.
            On an order where no item needs delivery, lines whose `total` is absent, empty or
            zero are dropped together with `shipping`, so the order carries no shipping and
            WooCommerce shows no shipping address. If any such line has a non-zero total,
            the lines and `shipping` are kept as sent, so the order total still matches what
            the customer paid.
          items:
            $ref: '#/components/schemas/ShippingLine'
```

- [ ] **Step 4: Update `CHANGELOG.md`**

Append as the last bullet of the `## 1.10.0` section:

```markdown
* `POST /orders` no longer requires a shipping address or shipping lines on an order that holds only digital formats, and drops the free placeholder shipping Miguel sends with such an order, so it shows no shipping address. An item needs delivery when its product needs shipping and is not downloadable; an order with at least one such item still requires both, with the same errors as before. A digital-only order whose shipping line has a cost keeps it, so the order total still matches what the customer paid
```

- [ ] **Step 5: Run the full suite and the coding standard**

Run: `docker compose -p miguel-woocommerce -f docker-compose.test.yml run --rm phpunit`
Expected: `OK`, no failures or errors.

Run: `docker compose -p miguel-woocommerce -f docker-compose.test.yml run --rm --no-deps --entrypoint vendor/bin/phpcs phpunit includes/class-miguel-order-create-api.php tests/unit/test-order-create-api.php`
Expected: no errors (fix any reported alignment/spacing issues in the new code, then re-run).

- [ ] **Step 6: Commit**

```bash
git add tests/unit/test-order-create-api.php docs/openapi.yaml CHANGELOG.md
git commit -m "docs(orders): shipping is only required on orders with something to deliver"
```

---

## Self-review against the spec

| Spec requirement | Task |
|---|---|
| Rule `needs_shipping() && ! is_downloadable()`, variation first, unloadable ⇒ needs delivery | 1 |
| Shipping checks leave `validate_required_order_fields()` | 2 (Step 5) |
| Needs delivery ⇒ same two checks, codes, messages, 409, `data.field` | 2 |
| Digital-only + all lines free (absent / `''` / 0 / no lines) ⇒ unset both, debug log | 2 |
| Digital-only + a costed line ⇒ kept as sent, debug log | 2 |
| Line-item errors now precede shipping errors | 2 (shipping step runs after the loop; existing line-item tests still pass) |
| Idempotency unchanged | 3 (replay test) |
| WooCommerce hides the address; billing kept | 3 |
| Edge-case table rows (e-book w/ and w/o placeholder, costed line, mixed, printed missing either, downloadable-not-virtual, virtual-not-downloadable, unknown product) | 1, 2, 3 |
| `openapi.yaml` required list, descriptions, no `minItems` | 3 |
| `CHANGELOG.md` under 1.10.0 | 3 |
