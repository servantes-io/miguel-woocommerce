# GET /orders/{id} Single-Order Endpoint Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `GET /wp-json/miguel/v1/orders/{id}` to `Miguel_Orders_Api`, returning a richer single-order detail view.

**Architecture:** A new route on the existing `Miguel_Orders_Api` class, handled by `get_order()`. Response is built by a new private `format_order_detail()` that reuses the existing `format_order()` for the base 8 fields and merges in extra groups (line items, totals, structured addresses, payment/shipping meta) via small private helpers. Shortcode-scanning logic is factored into one shared helper used by both `products[]` and `line_items[]`.

**Tech Stack:** PHP, WordPress REST API, WooCommerce, PHPUnit (via WooCommerce test framework), Docker for the test runner.

## Global Constraints

- Run tests in Docker: full suite `make test-docker`; single test `docker compose -f docker-compose.test.yml run --rm phpunit --filter <test_method>`. Do NOT run `vendor/bin/phpunit` directly.
- Follow WooCommerce PHPCS style already used in the file: **tabs** for indentation, **Yoda conditions** (`'shop_order' !== $order->get_type()`), spaces inside parentheses, i18n via `esc_html__( ..., 'miguel' )`.
- Text domain is `miguel`.
- Do NOT change the existing list endpoint (`get_orders`) response shape. The base 8 fields must stay sourced from `format_order()`.
- Bearer-token auth is provided by the existing `Miguel_Rest_Auth_Trait` (`validate_api_access`) and is not modified.
- Response is a **bare order object** (no `{ order: ... }` envelope).
- Only top-level `shop_order` objects are returned; refunds and other types → 404.
- Money fields (order totals, line totals, shipping line totals) are returned as **strings** via `wc_format_decimal()`. The pre-existing `products[].price.sold_without_vat` float is unchanged.
- Spec: `docs/superpowers/specs/2026-07-09-orders-api-get-single-order-design.md`.

---

## File Structure

- `includes/class-miguel-orders-api.php` — new route + `get_order()` handler + `format_order_detail()` + private helpers (`format_line_items`, `format_billing_address`, `format_shipping_address`, `format_shipping_lines`, `get_miguel_codes_for_item`). All new code lives here (Approach A from the spec).
- `tests/unit/test-orders-api.php` — new test methods appended to the existing `Test_Miguel_Orders_Api` class.
- `docs/openapi.yaml` — new `GET /orders/{id}` path + `OrderDetail`, `OrderLineItem`, `OrderShippingAddress` schemas.

Test helpers already available (in `tests/helpers/`, autoloaded by the bootstrap):
- `Miguel_Helper_Order::create_order()` → `WC_Order` with a virtual product, billing set to `Jan Miguel`, country `CZ`, city `Brno`, postcode `60200`, email `test@melvil.cz`, status `processing`, date paid set.
- `Miguel_Helper_Order::create_order_downloadable()` → `array( 'order_id', 'product_id', 'download_url' )`; the order has the virtual product **plus** a downloadable product whose Miguel file is `[miguel id="dummy-name" format="epub"]`.

---

## Task 1: Route + `get_order()` handler with base fields and 404 handling

**Files:**
- Modify: `includes/class-miguel-orders-api.php` (add route in `register_routes()`; add `get_order()` and `format_order_detail()`)
- Test: `tests/unit/test-orders-api.php`

**Interfaces:**
- Consumes: existing `Miguel_Orders_Api::format_order( WC_Order $order ): array`, `Miguel_Rest_Auth_Trait::validate_api_access()`.
- Produces:
  - `Miguel_Orders_Api::get_order( WP_REST_Request $request ): WP_REST_Response|WP_Error`
  - `Miguel_Orders_Api::format_order_detail( WC_Order $order ): array` (private) — returns `array_merge( format_order( $order ), array() )` for now; later tasks fill the second array.

- [ ] **Step 1: Write the failing tests**

Append these three methods inside the `Test_Miguel_Orders_Api` class in `tests/unit/test-orders-api.php` (before the closing `}`):

```php
	public function test_get_order_returns_404_when_not_found() {
		$api     = new Miguel_Orders_Api( new Miguel_Hook_Manager() );
		$request = new WP_REST_Request( 'GET', '/miguel/v1/orders/999999' );
		$request->set_param( 'id', 999999 );

		$response = $api->get_order( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertEquals( 'order.not_found', $response->get_error_code() );
		$data = $response->get_error_data();
		$this->assertSame( 404, $data['status'] );
	}

	public function test_get_order_returns_404_for_refund() {
		$order  = Miguel_Helper_Order::create_order();
		$refund = wc_create_refund( array( 'order_id' => $order->get_id() ) );

		$api     = new Miguel_Orders_Api( new Miguel_Hook_Manager() );
		$request = new WP_REST_Request( 'GET', '/miguel/v1/orders/' . $refund->get_id() );
		$request->set_param( 'id', $refund->get_id() );

		$response = $api->get_order( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertEquals( 'order.not_found', $response->get_error_code() );
		$data = $response->get_error_data();
		$this->assertSame( 404, $data['status'] );
	}

	public function test_get_order_returns_base_fields() {
		$order = Miguel_Helper_Order::create_order();

		$api     = new Miguel_Orders_Api( new Miguel_Hook_Manager() );
		$request = new WP_REST_Request( 'GET', '/miguel/v1/orders/' . $order->get_id() );
		$request->set_param( 'id', $order->get_id() );

		$response = $api->get_order( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		foreach ( array( 'id', 'status', 'currency_code', 'paid', 'purchase_date', 'update_date', 'user', 'products' ) as $key ) {
			$this->assertArrayHasKey( $key, $data );
		}
		$this->assertSame( strval( $order->get_id() ), $data['id'] );
	}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker compose -f docker-compose.test.yml run --rm phpunit --filter test_get_order`
Expected: FAIL — `Call to undefined method Miguel_Orders_Api::get_order()`.

- [ ] **Step 3: Add the route**

In `includes/class-miguel-orders-api.php`, inside `register_routes()`, add a second `register_rest_route()` call after the existing `/orders` registration (keep the existing one):

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

- [ ] **Step 4: Add the handler and detail formatter**

In `includes/class-miguel-orders-api.php`, add these two methods (place `get_order()` right after `get_orders()`, and `format_order_detail()` right after `format_order()`):

```php
	/**
	 * Return a single order by ID with a richer detail view.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_order( $request ) {
		$order_id = absint( $request->get_param( 'id' ) );
		$order    = wc_get_order( $order_id );

		if ( ! $order || 'shop_order' !== $order->get_type() ) {
			return new WP_Error(
				'order.not_found',
				esc_html__( 'Order was not found.', 'miguel' ),
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response( $this->format_order_detail( $order ), 200 );
	}
```

```php
	/**
	 * Format a single order with the richer detail field set.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return array
	 */
	private function format_order_detail( $order ) {
		return array_merge(
			$this->format_order( $order ),
			array()
		);
	}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `docker compose -f docker-compose.test.yml run --rm phpunit --filter test_get_order`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add includes/class-miguel-orders-api.php tests/unit/test-orders-api.php
git commit -m "feat(orders-api): add GET /orders/{id} with base fields and 404 handling"
```

---

## Task 2: Extract shared `get_miguel_codes_for_item()` helper (behavior-preserving refactor)

Refactor the shortcode-scanning logic out of `collect_products_from_order()` into a reusable helper, so Task 3 can reuse it for `line_items[].code`. No behavior change — the existing suite must stay green.

**Files:**
- Modify: `includes/class-miguel-orders-api.php`

**Interfaces:**
- Produces: `Miguel_Orders_Api::get_miguel_codes_for_item( WC_Order_Item $item ): array` (private) — returns an array of Miguel codes (strings) for a downloadable Miguel line item, or `array()` for any other item.
- `collect_products_from_order()` keeps the exact same output as before.

- [ ] **Step 1: Add the shared helper**

In `includes/class-miguel-orders-api.php`, add this private method (place it right after `collect_products_from_order()`):

```php
	/**
	 * Collect Miguel product codes from a single order line item.
	 * Returns an empty array for non-product, non-downloadable, or non-Miguel items.
	 *
	 * @param WC_Order_Item $item Order line item.
	 * @return array List of Miguel codes (strings).
	 */
	private function get_miguel_codes_for_item( $item ) {
		$codes = array();

		if ( ! ( $item instanceof WC_Order_Item_Product ) ) {
			return $codes;
		}

		$product = $item->get_product();
		if ( ! $product || ! $product->is_downloadable() ) {
			return $codes;
		}

		foreach ( $product->get_downloads() as $download ) {
			$file = is_array( $download )
				? ( isset( $download['file'] ) ? $download['file'] : '' )
				: ( method_exists( $download, 'get_file' ) ? $download->get_file() : '' );

			if ( empty( $file ) || ! Miguel_Order_Utils::is_miguel_shortcode( $file ) ) {
				continue;
			}

			$code = Miguel_Order_Utils::extract_miguel_code( $file );
			if ( $code ) {
				$codes[] = $code;
			}
		}

		return $codes;
	}
```

- [ ] **Step 2: Refactor `collect_products_from_order()` to use the helper**

Replace the entire body of `collect_products_from_order()` with:

```php
	private function collect_products_from_order( $order ) {
		$products = array();

		foreach ( $order->get_items() as $item ) {
			$codes = $this->get_miguel_codes_for_item( $item );
			if ( empty( $codes ) ) {
				continue;
			}

			$item_total = $order->get_item_total( $item, false, false ); // exc. tax, exc. rounding

			foreach ( $codes as $code ) {
				$products[] = array(
					'code'  => $code,
					'price' => array(
						'sold_without_vat' => $item_total,
					),
				);
			}
		}

		return $products;
	}
```

(Keep the existing docblock above the method.)

- [ ] **Step 3: Run the full suite to confirm no behavior change**

Run: `make test-docker`
Expected: PASS — all existing tests still green (this refactor produces identical `products[]` output).

- [ ] **Step 4: Commit**

```bash
git add includes/class-miguel-orders-api.php
git commit -m "refactor(orders-api): extract get_miguel_codes_for_item helper"
```

---

## Task 3: Add `line_items` (all product lines, with Miguel code)

**Files:**
- Modify: `includes/class-miguel-orders-api.php`
- Test: `tests/unit/test-orders-api.php`

**Interfaces:**
- Consumes: `get_miguel_codes_for_item()` from Task 2.
- Produces: `Miguel_Orders_Api::format_line_items( WC_Order $order ): array` (private). Each entry: `product_id` (int), `name` (string), `sku` (string), `quantity` (int), `total` (string), `tax` (string), `code` (string|null).
- `format_order_detail()` now includes a `line_items` key.

- [ ] **Step 1: Write the failing test**

Append to `Test_Miguel_Orders_Api`:

```php
	public function test_get_order_line_items_include_all_products_with_miguel_code() {
		$created  = Miguel_Helper_Order::create_order_downloadable();
		$order_id = $created['order_id'];

		$api     = new Miguel_Orders_Api( new Miguel_Hook_Manager() );
		$request = new WP_REST_Request( 'GET', '/miguel/v1/orders/' . $order_id );
		$request->set_param( 'id', $order_id );

		$data = $api->get_order( $request )->get_data();

		$this->assertArrayHasKey( 'line_items', $data );
		$this->assertNotEmpty( $data['line_items'] );

		foreach ( $data['line_items'] as $line ) {
			foreach ( array( 'product_id', 'name', 'sku', 'quantity', 'total', 'tax', 'code' ) as $key ) {
				$this->assertArrayHasKey( $key, $line );
			}
		}

		$codes = array_column( $data['line_items'], 'code' );
		$this->assertContains( 'dummy-name', $codes );                         // downloadable Miguel product
		$this->assertTrue( in_array( null, $codes, true ), 'Expected a line item with null code.' ); // virtual non-Miguel product
	}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose -f docker-compose.test.yml run --rm phpunit --filter test_get_order_line_items_include_all_products_with_miguel_code`
Expected: FAIL — `Failed asserting that an array has the key 'line_items'`.

- [ ] **Step 3: Add the `format_line_items()` helper**

In `includes/class-miguel-orders-api.php`, add this private method (place it after `get_miguel_codes_for_item()`):

```php
	/**
	 * Format all product line items of an order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return array
	 */
	private function format_line_items( $order ) {
		$line_items = array();

		foreach ( $order->get_items() as $item ) {
			if ( ! ( $item instanceof WC_Order_Item_Product ) ) {
				continue;
			}

			$product = $item->get_product();
			$codes   = $this->get_miguel_codes_for_item( $item );

			$line_items[] = array(
				'product_id' => $item->get_product_id(),
				'name'       => $item->get_name(),
				'sku'        => $product ? $product->get_sku() : '',
				'quantity'   => $item->get_quantity(),
				'total'      => wc_format_decimal( $item->get_total() ),
				'tax'        => wc_format_decimal( $item->get_total_tax() ),
				'code'       => ! empty( $codes ) ? $codes[0] : null,
			);
		}

		return $line_items;
	}
```

- [ ] **Step 4: Wire `line_items` into `format_order_detail()`**

Replace the body of `format_order_detail()` with:

```php
	private function format_order_detail( $order ) {
		return array_merge(
			$this->format_order( $order ),
			array(
				'line_items' => $this->format_line_items( $order ),
			)
		);
	}
```

(Keep the existing docblock above the method.)

- [ ] **Step 5: Run the test to verify it passes**

Run: `docker compose -f docker-compose.test.yml run --rm phpunit --filter test_get_order_line_items_include_all_products_with_miguel_code`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add includes/class-miguel-orders-api.php tests/unit/test-orders-api.php
git commit -m "feat(orders-api): add line_items to single-order detail"
```

---

## Task 4: Add order totals

**Files:**
- Modify: `includes/class-miguel-orders-api.php`
- Test: `tests/unit/test-orders-api.php`

**Interfaces:**
- Produces: `format_order_detail()` now includes `total`, `subtotal`, `total_tax`, `shipping_total`, `discount_total` — all strings.

- [ ] **Step 1: Write the failing test**

Append to `Test_Miguel_Orders_Api`:

```php
	public function test_get_order_includes_totals() {
		$order = Miguel_Helper_Order::create_order();

		$api     = new Miguel_Orders_Api( new Miguel_Hook_Manager() );
		$request = new WP_REST_Request( 'GET', '/miguel/v1/orders/' . $order->get_id() );
		$request->set_param( 'id', $order->get_id() );

		$data = $api->get_order( $request )->get_data();

		foreach ( array( 'total', 'subtotal', 'total_tax', 'shipping_total', 'discount_total' ) as $key ) {
			$this->assertArrayHasKey( $key, $data );
			$this->assertIsString( $data[ $key ] );
		}
	}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose -f docker-compose.test.yml run --rm phpunit --filter test_get_order_includes_totals`
Expected: FAIL — `Failed asserting that an array has the key 'total'`.

- [ ] **Step 3: Add the totals to `format_order_detail()`**

Replace the body of `format_order_detail()` with:

```php
	private function format_order_detail( $order ) {
		return array_merge(
			$this->format_order( $order ),
			array(
				'line_items'     => $this->format_line_items( $order ),
				'total'          => wc_format_decimal( $order->get_total() ),
				'subtotal'       => wc_format_decimal( $order->get_subtotal() ),
				'total_tax'      => wc_format_decimal( $order->get_total_tax() ),
				'shipping_total' => wc_format_decimal( $order->get_shipping_total() ),
				'discount_total' => wc_format_decimal( $order->get_discount_total() ),
			)
		);
	}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `docker compose -f docker-compose.test.yml run --rm phpunit --filter test_get_order_includes_totals`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/class-miguel-orders-api.php tests/unit/test-orders-api.php
git commit -m "feat(orders-api): add order totals to single-order detail"
```

---

## Task 5: Add structured billing & shipping addresses

**Files:**
- Modify: `includes/class-miguel-orders-api.php`
- Test: `tests/unit/test-orders-api.php`

**Interfaces:**
- Produces:
  - `format_billing_address( WC_Order $order ): array` — keys: `first_name, last_name, company, address_1, address_2, city, state, postcode, country, email, phone` (`country` = ISO code).
  - `format_shipping_address( WC_Order $order ): array` — same keys minus `email` (WooCommerce shipping has no email); `phone` guarded by `method_exists`.
  - `format_order_detail()` now includes `billing` and `shipping`.

- [ ] **Step 1: Write the failing test**

Append to `Test_Miguel_Orders_Api`:

```php
	public function test_get_order_includes_structured_addresses() {
		$order = Miguel_Helper_Order::create_order();

		$api     = new Miguel_Orders_Api( new Miguel_Hook_Manager() );
		$request = new WP_REST_Request( 'GET', '/miguel/v1/orders/' . $order->get_id() );
		$request->set_param( 'id', $order->get_id() );

		$data = $api->get_order( $request )->get_data();

		$this->assertArrayHasKey( 'billing', $data );
		$this->assertArrayHasKey( 'shipping', $data );

		$billing = $data['billing'];
		$this->assertSame( 'Jan', $billing['first_name'] );
		$this->assertSame( 'Miguel', $billing['last_name'] );
		$this->assertSame( 'CZ', $billing['country'] );
		$this->assertSame( 'Brno', $billing['city'] );
		$this->assertSame( '60200', $billing['postcode'] );
		$this->assertSame( 'test@melvil.cz', $billing['email'] );

		foreach ( array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone' ) as $key ) {
			$this->assertArrayHasKey( $key, $data['shipping'] );
		}
	}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose -f docker-compose.test.yml run --rm phpunit --filter test_get_order_includes_structured_addresses`
Expected: FAIL — `Failed asserting that an array has the key 'billing'`.

- [ ] **Step 3: Add the address helpers**

In `includes/class-miguel-orders-api.php`, add these two private methods (place them after `format_line_items()`):

```php
	/**
	 * Format the billing address of an order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return array
	 */
	private function format_billing_address( $order ) {
		return array(
			'first_name' => $order->get_billing_first_name(),
			'last_name'  => $order->get_billing_last_name(),
			'company'    => $order->get_billing_company(),
			'address_1'  => $order->get_billing_address_1(),
			'address_2'  => $order->get_billing_address_2(),
			'city'       => $order->get_billing_city(),
			'state'      => $order->get_billing_state(),
			'postcode'   => $order->get_billing_postcode(),
			'country'    => $order->get_billing_country(),
			'email'      => $order->get_billing_email(),
			'phone'      => $order->get_billing_phone(),
		);
	}

	/**
	 * Format the shipping address of an order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return array
	 */
	private function format_shipping_address( $order ) {
		return array(
			'first_name' => $order->get_shipping_first_name(),
			'last_name'  => $order->get_shipping_last_name(),
			'company'    => $order->get_shipping_company(),
			'address_1'  => $order->get_shipping_address_1(),
			'address_2'  => $order->get_shipping_address_2(),
			'city'       => $order->get_shipping_city(),
			'state'      => $order->get_shipping_state(),
			'postcode'   => $order->get_shipping_postcode(),
			'country'    => $order->get_shipping_country(),
			'phone'      => method_exists( $order, 'get_shipping_phone' ) ? $order->get_shipping_phone() : '',
		);
	}
```

- [ ] **Step 4: Wire addresses into `format_order_detail()`**

Replace the body of `format_order_detail()` with:

```php
	private function format_order_detail( $order ) {
		return array_merge(
			$this->format_order( $order ),
			array(
				'line_items'     => $this->format_line_items( $order ),
				'total'          => wc_format_decimal( $order->get_total() ),
				'subtotal'       => wc_format_decimal( $order->get_subtotal() ),
				'total_tax'      => wc_format_decimal( $order->get_total_tax() ),
				'shipping_total' => wc_format_decimal( $order->get_shipping_total() ),
				'discount_total' => wc_format_decimal( $order->get_discount_total() ),
				'billing'        => $this->format_billing_address( $order ),
				'shipping'       => $this->format_shipping_address( $order ),
			)
		);
	}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `docker compose -f docker-compose.test.yml run --rm phpunit --filter test_get_order_includes_structured_addresses`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add includes/class-miguel-orders-api.php tests/unit/test-orders-api.php
git commit -m "feat(orders-api): add structured billing/shipping addresses"
```

---

## Task 6: Add payment & shipping metadata

**Files:**
- Modify: `includes/class-miguel-orders-api.php`
- Test: `tests/unit/test-orders-api.php`

**Interfaces:**
- Produces:
  - `format_shipping_lines( WC_Order $order ): array` — each entry `method_id` (string), `method_title` (string), `total` (string).
  - `format_order_detail()` now includes `payment_method`, `payment_method_title`, `transaction_id`, `shipping_lines`, `customer_note`.

- [ ] **Step 1: Write the failing test**

Append to `Test_Miguel_Orders_Api`:

```php
	public function test_get_order_includes_payment_and_shipping_meta() {
		$order = Miguel_Helper_Order::create_order();
		$order->set_payment_method( 'bacs' );
		$order->set_payment_method_title( 'Direct Bank Transfer' );
		$order->set_customer_note( 'Leave at the door' );
		$order->save();

		$api     = new Miguel_Orders_Api( new Miguel_Hook_Manager() );
		$request = new WP_REST_Request( 'GET', '/miguel/v1/orders/' . $order->get_id() );
		$request->set_param( 'id', $order->get_id() );

		$data = $api->get_order( $request )->get_data();

		$this->assertSame( 'bacs', $data['payment_method'] );
		$this->assertSame( 'Direct Bank Transfer', $data['payment_method_title'] );
		$this->assertSame( 'Leave at the door', $data['customer_note'] );
		$this->assertArrayHasKey( 'transaction_id', $data );
		$this->assertArrayHasKey( 'shipping_lines', $data );
		$this->assertIsArray( $data['shipping_lines'] );
	}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose -f docker-compose.test.yml run --rm phpunit --filter test_get_order_includes_payment_and_shipping_meta`
Expected: FAIL — `Undefined array key "payment_method"`.

- [ ] **Step 3: Add the `format_shipping_lines()` helper**

In `includes/class-miguel-orders-api.php`, add this private method (place it after `format_shipping_address()`):

```php
	/**
	 * Format the shipping lines of an order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return array
	 */
	private function format_shipping_lines( $order ) {
		$shipping_lines = array();

		foreach ( $order->get_shipping_methods() as $shipping_item ) {
			$shipping_lines[] = array(
				'method_id'    => $shipping_item->get_method_id(),
				'method_title' => $shipping_item->get_name(),
				'total'        => wc_format_decimal( $shipping_item->get_total() ),
			);
		}

		return $shipping_lines;
	}
```

- [ ] **Step 4: Wire payment/shipping meta into `format_order_detail()`**

Replace the body of `format_order_detail()` with:

```php
	private function format_order_detail( $order ) {
		return array_merge(
			$this->format_order( $order ),
			array(
				'line_items'           => $this->format_line_items( $order ),
				'total'                => wc_format_decimal( $order->get_total() ),
				'subtotal'             => wc_format_decimal( $order->get_subtotal() ),
				'total_tax'            => wc_format_decimal( $order->get_total_tax() ),
				'shipping_total'       => wc_format_decimal( $order->get_shipping_total() ),
				'discount_total'       => wc_format_decimal( $order->get_discount_total() ),
				'billing'              => $this->format_billing_address( $order ),
				'shipping'             => $this->format_shipping_address( $order ),
				'payment_method'       => $order->get_payment_method(),
				'payment_method_title' => $order->get_payment_method_title(),
				'transaction_id'       => $order->get_transaction_id(),
				'shipping_lines'       => $this->format_shipping_lines( $order ),
				'customer_note'        => $order->get_customer_note(),
			)
		);
	}
```

- [ ] **Step 5: Run the new test, then the full suite**

Run: `docker compose -f docker-compose.test.yml run --rm phpunit --filter test_get_order_includes_payment_and_shipping_meta`
Expected: PASS.

Then run the full suite to confirm nothing regressed:
Run: `make test-docker`
Expected: PASS (all tests).

- [ ] **Step 6: Commit**

```bash
git add includes/class-miguel-orders-api.php tests/unit/test-orders-api.php
git commit -m "feat(orders-api): add payment and shipping metadata to single-order detail"
```

---

## Task 7: Document the endpoint in OpenAPI

**Files:**
- Modify: `docs/openapi.yaml`

No automated test (documentation). Validate YAML syntax only.

- [ ] **Step 1: Add the new schemas**

In `docs/openapi.yaml`, under `components:` → `schemas:`, add these three schemas. Place them immediately after the existing `Order:` schema block (before `MiguelItem:`):

```yaml
    OrderLineItem:
      type: object
      properties:
        product_id:
          type: integer
          example: 15
        name:
          type: string
          example: My eBook
        sku:
          type: string
        quantity:
          type: integer
          example: 1
        total:
          type: string
          description: Line total excluding tax (store currency).
          example: "199.00"
        tax:
          type: string
          description: Line tax total (store currency).
          example: "0.00"
        code:
          type: string
          nullable: true
          description: >
            First Miguel product code on the line, or null when the line is not
            a Miguel downloadable. Full code+price detail is in the order's products array.
          example: "9788024271101"

    OrderShippingAddress:
      type: object
      properties:
        first_name:
          type: string
        last_name:
          type: string
        company:
          type: string
        address_1:
          type: string
        address_2:
          type: string
        city:
          type: string
        state:
          type: string
        postcode:
          type: string
        country:
          type: string
          description: ISO 3166-1 alpha-2 country code.
        phone:
          type: string
          description: Empty string on WooCommerce versions without shipping-phone support.

    OrderDetail:
      description: >
        Richer single-order view returned by GET /orders/{id}. Includes all
        properties of Order plus totals, structured addresses, payment/shipping
        metadata, and a full line-item breakdown.
      allOf:
        - $ref: '#/components/schemas/Order'
        - type: object
          properties:
            total:
              type: string
              example: "228.00"
            subtotal:
              type: string
              example: "199.00"
            total_tax:
              type: string
              example: "0.00"
            shipping_total:
              type: string
              example: "29.00"
            discount_total:
              type: string
              example: "0.00"
            billing:
              $ref: '#/components/schemas/BillingAddress'
            shipping:
              $ref: '#/components/schemas/OrderShippingAddress'
            payment_method:
              type: string
              example: bacs
            payment_method_title:
              type: string
              example: Direct Bank Transfer
            transaction_id:
              type: string
            shipping_lines:
              type: array
              items:
                $ref: '#/components/schemas/ShippingLine'
            customer_note:
              type: string
            line_items:
              type: array
              items:
                $ref: '#/components/schemas/OrderLineItem'
```

- [ ] **Step 2: Add the new path**

In `docs/openapi.yaml`, under `paths:`, add this block immediately **before** the existing `/orders/{id}/status:` path:

```yaml
  /orders/{id}:
    get:
      summary: Get a single order by ID
      operationId: getOrder
      description: >
        Returns a single WooCommerce order by ID with a richer detail view: the
        same base fields as a list item plus order totals, structured
        billing/shipping addresses, payment and shipping metadata, and a full
        line-item breakdown. Only top-level shop orders are returned; refunds and
        other types yield 404.
      parameters:
        - in: path
          name: id
          required: true
          schema:
            type: integer
            minimum: 1
          description: WooCommerce order ID.
      responses:
        "200":
          description: OK
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/OrderDetail'
        "404":
          description: Order not found (unknown ID or non-shop_order type).
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/Error'
              example:
                code: order.not_found
                message: Order was not found.
                data:
                  status: 404
        "401":
          description: Missing bearer token.
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/Error'
        "403":
          description: Invalid bearer token.
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/Error'
```

- [ ] **Step 3: Validate the YAML parses**

Run: `python3 -c "import yaml; yaml.safe_load(open('docs/openapi.yaml')); print('OK')"`
Expected: `OK` (no traceback). If `python3` or `yaml` is unavailable, load the file in any OpenAPI viewer/editor and confirm it parses without errors.

- [ ] **Step 4: Commit**

```bash
git add docs/openapi.yaml
git commit -m "docs(openapi): document GET /orders/{id} endpoint"
```

---

## Self-Review

**Spec coverage:**
- Route `GET /orders/(?P<id>\d+)` on `Miguel_Orders_Api` → Task 1.
- Handler with `absint` id, `wc_get_order`, 404 for missing/non-`shop_order` → Task 1.
- Bare-object response, base 8 fields via `format_order()` → Task 1 (`format_order_detail`).
- Shared shortcode-scan helper reused by `products[]` and `line_items[]` → Task 2 + Task 3.
- `line_items` (all product lines, `code` = first-or-null) → Task 3.
- Order totals (5 string fields) → Task 4.
- Structured billing (ISO country, email, phone) + shipping (no email, guarded phone) → Task 5.
- Payment/shipping meta (payment_method, payment_method_title, transaction_id, shipping_lines, customer_note) → Task 6.
- OpenAPI: `GET /orders/{id}` path + `OrderDetail`/`OrderLineItem`/`OrderShippingAddress`, reuse `ShippingLine` + `BillingAddress` → Task 7.
- Error handling table (404 unknown, 404 refund, regex 404, auth 401/403) → covered by Task 1 tests + the route regex + the unchanged auth trait.
- Tests via `make test-docker` → all tasks.

**Placeholder scan:** No TBD/TODO/"handle edge cases"/"similar to Task N". Every code and test step shows complete content.

**Type consistency:** `format_order_detail()` is shown in full at each task that changes it, so the final field set is unambiguous. Helper names are consistent across tasks: `get_miguel_codes_for_item` (Task 2, used in Tasks 2 & 3), `format_line_items` (Task 3), `format_billing_address`/`format_shipping_address` (Task 5), `format_shipping_lines` (Task 6). `code` is `string|null` in both the implementation (Task 3) and the OpenAPI `nullable: true` (Task 7). Money fields are strings in both implementation (`wc_format_decimal`) and OpenAPI (`type: string`).
