# Miguel Send-Order-Email Setting Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an admin setting that controls whether the outbound OrderCreate DTO tells Miguel's backend to send order emails (`sendEmail: "auto"`) or not (`sendEmail: "disable"`).

**Architecture:** A WooCommerce checkbox setting stores a `'yes'`/`'no'` option. `Miguel_Orders` reads it and passes a boolean into `Miguel_Order_Mapper::map()`, which encapsulates the `'auto'`/`'disable'` mapping when building the `Miguel_V2_Order_Create` DTO. Default is disabled, preserving today's behavior.

**Tech Stack:** PHP (WordPress/WooCommerce plugin), PHPUnit integration test suite run in Docker.

## Global Constraints

- **Default behavior:** disabled. Unset option → `'disable'` (identical to current behavior). Copy this verbatim: `get_option( ..., 'no' )`.
- **DTO value set:** exactly two values — `'auto'` (enabled) and `'disable'` (disabled). No other values.
- **Option name:** `'miguel_send_order_email'`, declared as a constant `Miguel_Settings::SEND_EMAIL_OPTION`.
- **Mapper signature:** `Miguel_Order_Mapper::map( $order, $send_email = false )`. The `$send_email` boolean is passed at the call site — the mapper does NOT read `get_option()` itself.
- **Do not touch** the inbound order-create REST API ([includes/class-miguel-order-create-api.php](../../../includes/class-miguel-order-create-api.php)) or the download mapper's 3-arg `map()` in [includes/class-miguel-download.php](../../../includes/class-miguel-download.php).
- **Tests run in Docker only.** Full suite: `make test-docker`. Single test: `docker compose -f docker-compose.test.yml run --rm phpunit --filter <name>`. Never run host `vendor/bin/phpunit` directly.
- The WordPress integration test suite wraps each test in a DB transaction and rolls it back, so `update_option()` calls inside a test are automatically reverted for the next test — no manual option cleanup is required.

---

### Task 1: Mapper accepts a `send_email` boolean

**Files:**
- Modify: `includes/api/v2/mappers/class-miguel-order-mapper.php` (docblock + signature at lines 12-19; hardcoded value at line 63)
- Test: `tests/unit/test-order-mapper.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: `Miguel_Order_Mapper::map( WC_Order $order, bool $send_email = false ): ?Miguel_V2_Order_Create`. When `$send_email` is `true`, the DTO's `to_array()['sendEmail']` is `'auto'`; when `false` or omitted, it is `'disable'`.

- [ ] **Step 1: Write the failing test**

Add this method to `tests/unit/test-order-mapper.php`, inside the `Miguel_Test_Order_Mapper` class (e.g. after `test_maps_single_product_with_quantity_and_address`):

```php
	public function test_send_email_flag_controls_send_email_value(): void {
		$product = Miguel_Helper_Product::create_downloadable_product();

		$order = Miguel_Helper_Order::create_order();
		$order->add_product( $product, 1 );
		$order->set_status( 'processing' );
		$order->set_date_paid( '2023-01-15 10:00:00' );
		$order->save();

		$mapper = new Miguel_Order_Mapper();

		$enabled  = $mapper->map( $order, true );
		$disabled = $mapper->map( $order, false );
		$default  = $mapper->map( $order );

		$this->assertSame( 'auto', $enabled->to_array()['sendEmail'] );
		$this->assertSame( 'disable', $disabled->to_array()['sendEmail'] );
		$this->assertSame( 'disable', $default->to_array()['sendEmail'] );

		Miguel_Helper_Order::delete_order( $order->get_id() );
	}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose -f docker-compose.test.yml run --rm phpunit --filter test_send_email_flag_controls_send_email_value`
Expected: FAIL — `map( $order, true )` still returns `'disable'` (assertSame `'auto'` fails), because the second argument is currently ignored.

- [ ] **Step 3: Update the docblock and method signature**

In `includes/api/v2/mappers/class-miguel-order-mapper.php`, replace the `map()` docblock + signature:

```php
	/**
	 * Build the OrderCreate DTO.
	 *
	 * @param WC_Order $order Order object.
	 * @return Miguel_V2_Order_Create|null Null when there are no Miguel items.
	 */
	public function map( $order ) {
```

with:

```php
	/**
	 * Build the OrderCreate DTO.
	 *
	 * @param WC_Order $order      Order object.
	 * @param bool     $send_email Whether Miguel's backend should send order emails.
	 * @return Miguel_V2_Order_Create|null Null when there are no Miguel items.
	 */
	public function map( $order, $send_email = false ) {
```

- [ ] **Step 4: Replace the hardcoded DTO value**

In the same file, inside the `new Miguel_V2_Order_Create( ... )` constructor call, replace the line:

```php
			'disable',
```

with:

```php
			$send_email ? 'auto' : 'disable',
```

(There is exactly one `'disable'` literal in this file, so this is unambiguous.)

- [ ] **Step 5: Run test to verify it passes**

Run: `docker compose -f docker-compose.test.yml run --rm phpunit --filter test_send_email_flag_controls_send_email_value`
Expected: PASS.

- [ ] **Step 6: Run the mapper test class to confirm no regressions**

Run: `docker compose -f docker-compose.test.yml run --rm phpunit --filter Miguel_Test_Order_Mapper`
Expected: PASS — the existing `test_maps_single_product_with_quantity_and_address` still asserts `'disable'` for `map( $order )` (default), which remains true.

- [ ] **Step 7: Commit**

```bash
git add includes/api/v2/mappers/class-miguel-order-mapper.php tests/unit/test-order-mapper.php
git commit -m "feat: mapper send_email argument controls DTO sendEmail value"
```

---

### Task 2: Settings checkbox + wire it through `Miguel_Orders`

**Files:**
- Modify: `includes/admin/class-miguel-settings.php` (add constant; add checkbox field in `get_settings()`)
- Modify: `includes/class-miguel-orders.php` (add reader helper; update both `map()` call sites at lines 160 and 225)
- Test: `tests/unit/test-orders.php`

**Interfaces:**
- Consumes: `Miguel_Order_Mapper::map( $order, $send_email = false )` from Task 1.
- Produces:
  - `const Miguel_Settings::SEND_EMAIL_OPTION = 'miguel_send_order_email';`
  - `Miguel_Orders::is_send_order_email_enabled(): bool` (private) → `true` when the option equals `'yes'`.

- [ ] **Step 1: Write the failing tests**

Add these two methods to `tests/unit/test-orders.php`, inside the `Test_Miguel_Orders` class:

```php
	/**
	 * When the setting is enabled, the synced order carries sendEmail = "auto".
	 */
	public function test_sync_order_sends_auto_email_flag_when_setting_enabled() {
		update_option( Miguel_Settings::SEND_EMAIL_OPTION, 'yes' );

		Miguel_Helper_HTTP::mock_api_responses(
			array(
				'POST' => array(
					'body'     => '{}',
					'response' => array( 'code' => 201, 'message' => 'Created' ),
				),
			)
		);

		$product = Miguel_Helper_Product::create_downloadable_product();
		$order   = Miguel_Helper_Order::create_order();
		$order->add_product( $product, 1 );
		$order->set_status( 'processing' );
		$order->set_date_paid( '2023-01-15 10:00:00' );
		$order->save();

		$this->get_sut()->sync_order( $order->get_id(), 'new', 'processing', $order );

		$requests = Miguel_Helper_HTTP::get_requests();
		$this->assertCount( 1, $requests, 'Different number of requests: ' . print_r( $requests, true ) );
		$body = json_decode( $requests[0]['body'], true );
		$this->assertSame( 'auto', $body['sendEmail'] );

		Miguel_Helper_Order::delete_order( $order->get_id() );
	}

	/**
	 * With the setting unset (default), the synced order carries sendEmail = "disable".
	 */
	public function test_sync_order_sends_disable_email_flag_by_default() {
		Miguel_Helper_HTTP::mock_api_responses(
			array(
				'POST' => array(
					'body'     => '{}',
					'response' => array( 'code' => 201, 'message' => 'Created' ),
				),
			)
		);

		$product = Miguel_Helper_Product::create_downloadable_product();
		$order   = Miguel_Helper_Order::create_order();
		$order->add_product( $product, 1 );
		$order->set_status( 'processing' );
		$order->set_date_paid( '2023-01-15 10:00:00' );
		$order->save();

		$this->get_sut()->sync_order( $order->get_id(), 'new', 'processing', $order );

		$requests = Miguel_Helper_HTTP::get_requests();
		$this->assertCount( 1, $requests, 'Different number of requests: ' . print_r( $requests, true ) );
		$body = json_decode( $requests[0]['body'], true );
		$this->assertSame( 'disable', $body['sendEmail'] );

		Miguel_Helper_Order::delete_order( $order->get_id() );
	}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose -f docker-compose.test.yml run --rm phpunit --filter test_sync_order_sends_auto_email_flag_when_setting_enabled`
Expected: FAIL — a fatal error / undefined constant `Miguel_Settings::SEND_EMAIL_OPTION` (constant not defined yet). This confirms the test exercises the new constant.

- [ ] **Step 3: Add the option constant to `Miguel_Settings`**

In `includes/admin/class-miguel-settings.php`, add the constant immediately before the `private $hook_manager;` property. Replace:

```php
class Miguel_Settings extends WC_Settings_Page {

	/**
	 * Hook manager instance
	 *
	 * @var Miguel_Hook_Manager_Interface
	 */
	private $hook_manager;
```

with:

```php
class Miguel_Settings extends WC_Settings_Page {

	/**
	 * Option name controlling whether Miguel's backend sends order emails.
	 */
	const SEND_EMAIL_OPTION = 'miguel_send_order_email';

	/**
	 * Hook manager instance
	 *
	 * @var Miguel_Hook_Manager_Interface
	 */
	private $hook_manager;
```

- [ ] **Step 4: Add the checkbox field in `get_settings()`**

In the same file, inside the `get_settings()` array, insert a new field immediately before the `sectionend` entry. Replace:

```php
					array(
						'id' => 'miguel_api_options',
						'type' => 'sectionend',
					),
```

with:

```php
					array(
						'id'      => self::SEND_EMAIL_OPTION,
						'type'    => 'checkbox',
						'title'   => __( 'Send order emails from Miguel', 'miguel' ),
						'desc'    => __( "When enabled, Miguel's server sends the order/delivery email to the customer. When disabled, Miguel does not send any email.", 'miguel' ),
						'default' => 'no',
					),
					array(
						'id' => 'miguel_api_options',
						'type' => 'sectionend',
					),
```

- [ ] **Step 5: Add the reader helper to `Miguel_Orders`**

In `includes/class-miguel-orders.php`, add a private helper method immediately before the `generate_order_hash()` docblock (the block starting with `* Generate hash of order data for deduplication`). Insert:

```php
	/**
	 * Whether Miguel's backend should send order emails.
	 *
	 * @return bool
	 */
	private function is_send_order_email_enabled() {
		return 'yes' === get_option( Miguel_Settings::SEND_EMAIL_OPTION, 'no' );
	}

```

- [ ] **Step 6: Update both `map()` call sites**

In `includes/class-miguel-orders.php` there are two identical lines (in `generate_order_hash()` and in `sync_order()`):

```php
			$order_create = $this->mapper->map( $order );
```

Replace BOTH occurrences with:

```php
			$order_create = $this->mapper->map( $order, $this->is_send_order_email_enabled() );
```

(Use a replace-all edit — both call sites must pass the same value so the dedup hash matches what is sent.)

- [ ] **Step 7: Run the new tests to verify they pass**

Run: `docker compose -f docker-compose.test.yml run --rm phpunit --filter "test_sync_order_sends_auto_email_flag_when_setting_enabled|test_sync_order_sends_disable_email_flag_by_default"`
Expected: PASS for both.

- [ ] **Step 8: Run the full order-sync test class to confirm no regressions**

Run: `docker compose -f docker-compose.test.yml run --rm phpunit --filter Test_Miguel_Orders`
Expected: PASS — existing dedup/sync tests still hold (default value is `'disable'`, unchanged from before).

- [ ] **Step 9: Run the full suite**

Run: `make test-docker`
Expected: PASS — entire suite green.

- [ ] **Step 10: Commit**

```bash
git add includes/admin/class-miguel-settings.php includes/class-miguel-orders.php tests/unit/test-orders.php
git commit -m "feat: add setting controlling whether Miguel sends order emails"
```

---

## Notes for the implementer

- `Miguel_Orders` references `Miguel_Settings::SEND_EMAIL_OPTION`. Both classes are autoloaded by the plugin, so no explicit `require` is needed; if a "class not found" error appears in tests, confirm the plugin bootstrap loads `Miguel_Settings` (it does in normal runtime and under the test bootstrap).
- The checkbox added to `get_settings()` is automatically rendered by `Miguel_Settings::output()` and persisted by `Miguel_Settings::save()`, since both delegate to `WC_Admin_Settings` using the same `get_settings()` array — no extra wiring required.
- Do not change `CHANGELOG` or bump the plugin version as part of these tasks unless separately asked.
