# SMP-12 Order Note — Plugin Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `POST /miguel/v1/orders` accepts an optional `order_note` string and records it as a private (merchant-only) note on the order it creates.

**Architecture:** Everything lives in `Miguel_Order_Create_Api` (`includes/class-miguel-order-create-api.php`); no new class. `validate_required_order_fields()` rejects a non-string `order_note`; `create_order()` reads and sanitizes the note from the payload as sent, `prepare_payload_for_wc_order()` strips it so WooCommerce never sees it, and after the order exists a new helper adds it with `WC_Order::add_order_note()`.

**Tech Stack:** PHP 8.1, WordPress 6.5+, WooCommerce 7.9+ (tests run against WC 9.9.5), PHPUnit via the WooCommerce unit-test framework, Docker.

**Spec:** `docs/superpowers/specs/2026-09-13-smp-12-order-note-design.md` — this plan implements its **Plugin design** section only. The **Backend design** section is implemented by a separate plan in `servantes-io/miguel` (branch `feat/smp-12-woocommerce-order-note`).

## Global Constraints

- **Contract:** field `order_note`, optional. Absent / `null` / blank after sanitizing → no note. Non-empty string → one private note, text sanitized with `wp_kses_post`. Any other type → HTTP 409, code `order.order_note_invalid`, message `Order order_note must be a string.`, `data.field = "order_note"`, no order created. No response field changes.
- **Private note only:** `$order->add_order_note( $note )` with the defaults (`$is_customer_note = 0`, `$added_by_user = false`). Never a customer note, never emailed.
- **A failed note never fails the request:** if `add_order_note()` returns `0`, write a debug log entry and still answer 201.
- **Never log the note text** — only its length (`order_note_length`).
- **Tests run via Docker only.** Full suite: `make test-docker`. One class: `docker compose -f docker-compose.test.yml run --rm phpunit --filter=Test_Miguel_Order_Create_Api`. One test: `--filter=<test_method_name>`. Never call `vendor/bin/phpunit` on the host.
- **Fresh worktree:** `vendor/` is not committed. Before the first test run, install the plugin's dev dependencies inside the test image: `docker compose -f docker-compose.test.yml run --rm --entrypoint composer phpunit install --no-interaction`. To reuse the WordPress/WooCommerce download cached by the main checkout instead of fetching it again, prefix every `docker compose` command with `COMPOSE_PROJECT_NAME=miguel-woocommerce`.
- **House style:** WordPress coding standards — tabs, Yoda conditions, `esc_html__( ..., 'miguel' )`, docblocks with `@param`/`@return`. Methods in this file carry types in docblocks, not in signatures; match that.
- **Refer to code by method name, not line number** — SMP-13 and SMP-14 change the same file after this.
- **Test SKU gotcha:** `Miguel_Helper_Product::create_downloadable_product()` saves every product with SKU `DUMMY SKU`, and WooCommerce refuses a second product with the same SKU. Create the product **once** per test and reuse its ID for every order in that test.
- **WooCommerce may add its own notes** while creating an order (stock adjustments, status transitions). Tests must look for the note by its text, or compare against a control order created in the same test — never assert "the order has exactly N notes" in isolation.

---

## File Structure

| Action | Path | Responsibility |
|--------|------|----------------|
| Modify | `includes/class-miguel-order-create-api.php` | Validate, read, strip and record `order_note` |
| Modify | `tests/unit/test-order-create-api.php` | Validation, stripping, recording, sanitizing, replay tests + two private helpers |
| Modify | `languages/miguel-cs_CZ.po`, `languages/miguel-en_US.po` | The new error message |
| Modify | `docs/openapi.yaml` | `order_note` in `CreateOrderRequest` |
| Modify | `CHANGELOG.md`, `readme.txt` | Entry under the unreleased 1.10.0 |

---

### Task 1: Reject a non-string `order_note`

**Files:**
- Modify: `includes/class-miguel-order-create-api.php` — method `validate_required_order_fields()`
- Modify: `languages/miguel-cs_CZ.po`, `languages/miguel-en_US.po`
- Test: `tests/unit/test-order-create-api.php`

**Interfaces:**
- Consumes: existing `build_order_payload_error( $code, $message, $data )` (private, returns `WP_Error` with status 409 merged into `$data`).
- Produces: `validate_required_order_fields( $payload )` returns `WP_Error` `order.order_note_invalid` (status 409, `field` = `order_note`) for a present, non-null, non-string `order_note`; returns `true` for a string or `null` note on an otherwise valid payload. Task 2 relies on a request with an invalid note never reaching order creation.

- [x] **Step 1: Write the failing tests**

Add to `Test_Miguel_Order_Create_Api`, next to `test_validate_required_order_fields_rejects_invalid_email_template()`:

```php
	/**
	 * Test that a non-string order_note is rejected.
	 */
	public function test_validate_required_order_fields_rejects_non_string_order_note() {
		$api = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$reflection = new ReflectionClass( $api );
		$method = $reflection->getMethod( 'validate_required_order_fields' );
		$method->setAccessible( true );

		foreach ( array( 42, true, array( 'note' ), array( 'text' => 'note' ) ) as $invalid_note ) {
			$payload = $this->get_minimal_valid_payload();
			$payload['order_note'] = $invalid_note;

			$result = $method->invoke( $api, $payload );

			$this->assertTrue( is_wp_error( $result ), 'A ' . gettype( $invalid_note ) . ' order_note must be rejected.' );
			$this->assertEquals( 'order.order_note_invalid', $result->get_error_code() );
			$this->assertEquals( 409, $result->get_error_data()['status'] );
			$this->assertEquals( 'order_note', $result->get_error_data()['field'] );
		}
	}

	/**
	 * Test that a string or null order_note passes validation.
	 */
	public function test_validate_required_order_fields_accepts_string_or_null_order_note() {
		$api = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$reflection = new ReflectionClass( $api );
		$method = $reflection->getMethod( 'validate_required_order_fields' );
		$method->setAccessible( true );

		foreach ( array( 'Objednávka vytvořena v aplikaci Melvil.', '', null ) as $valid_note ) {
			$payload = $this->get_minimal_valid_payload();
			$payload['order_note'] = $valid_note;

			$this->assertTrue( true === $method->invoke( $api, $payload ) );
		}
	}

	/**
	 * A request with an invalid order_note must not create an order.
	 */
	public function test_create_order_with_non_string_order_note_creates_no_order() {
		$product = Miguel_Helper_Product::create_downloadable_product();
		$orders_before = wc_get_orders( array( 'limit' => -1, 'return' => 'ids' ) );

		$api      = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );
		$response = $api->create_order( $this->build_order_request( $product->get_id(), array( 'order_note' => array( 'not', 'a', 'string' ) ) ) );

		$this->assertTrue( is_wp_error( $response ) );
		$this->assertEquals( 'order.order_note_invalid', $response->get_error_code() );
		$this->assertEquals( 409, $response->get_error_data()['status'] );
		$this->assertCount( count( $orders_before ), wc_get_orders( array( 'limit' => -1, 'return' => 'ids' ) ) );
	}
```

Add this private helper at the bottom of the class, after `build_musk_order_request()`. Task 2 uses it too:

```php
	/**
	 * Build an order-create request for one unit of the given product.
	 *
	 * The shipping block is always present so the request stays valid whatever the
	 * plugin requires of shipping; each call gets its own idempotency key unless the
	 * overrides set one.
	 *
	 * @param int   $product_id WooCommerce product ID.
	 * @param array $overrides  Top-level payload fields to add or replace.
	 * @return WP_REST_Request
	 */
	private function build_order_request( $product_id, $overrides = array() ) {
		$payload = array_merge(
			array(
				'idempotency_key' => 'order-note-' . wp_generate_uuid4(),
				'payment_method'  => 'cod',
				'billing'         => array(
					'first_name' => 'Test',
					'last_name'  => 'User',
					'email'      => 'buyer@example.com',
				),
				'shipping'        => array(
					'first_name' => 'Test',
					'last_name'  => 'User',
				),
				'shipping_lines'  => array(
					array(
						'method_id'    => 'flat_rate',
						'method_title' => 'Flat rate',
						'total'        => '0.00',
					),
				),
				'line_items'      => array(
					array(
						'product_id' => $product_id,
						'quantity'   => 1,
					),
				),
			),
			$overrides
		);

		$request = new WP_REST_Request( 'POST', '/miguel/v1/orders' );
		$request->add_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $payload ) );

		return $request;
	}
```

- [x] **Step 2: Run the tests to verify they fail**

Run: `docker compose -f docker-compose.test.yml run --rm phpunit --filter='order_note'`
Expected: `test_validate_required_order_fields_rejects_non_string_order_note` FAILS with "A integer order_note must be rejected." and `test_create_order_with_non_string_order_note_creates_no_order` FAILS (an order is created, so `is_wp_error()` is false). `test_validate_required_order_fields_accepts_string_or_null_order_note` passes already — it guards Step 3 against rejecting `null` or `''`.

- [x] **Step 3: Implement the check**

In `validate_required_order_fields()`, directly after the `email_template` block (before the `payment_method` check), add:

```php
		if ( array_key_exists( 'order_note', $payload ) && null !== $payload['order_note'] && ! is_string( $payload['order_note'] ) ) {
			return $this->build_order_payload_error(
				'order.order_note_invalid',
				esc_html__( 'Order order_note must be a string.', 'miguel' ),
				array( 'field' => 'order_note' )
			);
		}
```

- [x] **Step 4: Add the message to both catalogues**

Append to the end of `languages/miguel-cs_CZ.po`:

```
#: includes/class-miguel-order-create-api.php
msgid "Order order_note must be a string."
msgstr "Poznámka objednávky (order_note) musí být text."
```

Append to the end of `languages/miguel-en_US.po`:

```
#: includes/class-miguel-order-create-api.php
msgid "Order order_note must be a string."
msgstr "Order order_note must be a string."
```

Each block is preceded by one blank line, like the entries above it. Check both files compile (the test image has no gettext, so use a throwaway container if the host has no `msgfmt`):

```bash
docker run --rm -v "$PWD":/w -w /w debian:stable-slim sh -c \
  'apt-get update -qq && apt-get install -y -qq gettext >/dev/null \
   && msgfmt --check -o /dev/null languages/miguel-cs_CZ.po \
   && msgfmt --check -o /dev/null languages/miguel-en_US.po && echo OK'
```

Expected: `OK`. The `.mo` files are built by `make build` at release time; do not commit them.

- [x] **Step 5: Run the tests to verify they pass**

Run: `docker compose -f docker-compose.test.yml run --rm phpunit --filter=Test_Miguel_Order_Create_Api`
Expected: PASS, including every pre-existing test in the class.

- [x] **Step 6: Commit**

```bash
git add includes/class-miguel-order-create-api.php tests/unit/test-order-create-api.php languages/miguel-cs_CZ.po languages/miguel-en_US.po
git commit -m "feat(orders): reject an order_note that is not a string"
```

---

### Task 2: Record `order_note` as a private order note

**Files:**
- Modify: `includes/class-miguel-order-create-api.php` — methods `create_order()`, `prepare_payload_for_wc_order()`; new private methods `get_order_note_from_payload()` and `add_miguel_order_note()`
- Modify: `docs/openapi.yaml` — schema `CreateOrderRequest`
- Modify: `CHANGELOG.md`, `readme.txt` — section `1.10.0`
- Test: `tests/unit/test-order-create-api.php`

**Interfaces:**
- Consumes: Task 1's validation (an invalid note never reaches this code) and the test helper `build_order_request( $product_id, $overrides = array() )`.
- Produces:
  - `get_order_note_from_payload( $payload )` — `@param array $payload`, `@return string`: the sanitized note, or `''` when there is none.
  - `add_miguel_order_note( $order, $note )` — `@param WC_Order $order`, `@param string $note`, `@return void`: adds a private note when `$note` is non-empty, logs when WooCommerce refuses.
  - Test helper `get_order_notes_by_content( $order_id )` — `@return array` of note objects from `wc_get_order_notes()` keyed by their `content`.

- [ ] **Step 1: Write the failing tests**

Add to `Test_Miguel_Order_Create_Api`, next to `test_prepare_payload_for_wc_order_strips_email_template_flag()`:

```php
	/**
	 * Test that order_note is not forwarded to WooCommerce.
	 */
	public function test_prepare_payload_for_wc_order_strips_order_note() {
		Miguel_Helper_Product::create_downloadable_product();
		$api = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$reflection = new ReflectionClass( $api );
		$method = $reflection->getMethod( 'prepare_payload_for_wc_order' );
		$method->setAccessible( true );

		$result = $method->invoke(
			$api,
			array(
				'order_note' => 'Objednávka vytvořena v aplikaci Melvil.',
				'line_items' => array(
					array(
						'product_code' => 'dummy-name',
						'quantity' => 1,
					),
				),
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayNotHasKey( 'order_note', $result );
	}
```

Add after `test_create_order_with_non_string_order_note_creates_no_order()` (from Task 1):

```php
	/**
	 * A string order_note becomes one private, system-authored note on the order.
	 */
	public function test_create_order_adds_order_note_as_private_note() {
		$product = Miguel_Helper_Product::create_downloadable_product();
		$text    = 'Objednávka vytvořena v aplikaci Melvil.';

		$api      = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );
		$response = $api->create_order( $this->build_order_request( $product->get_id(), array( 'order_note' => $text ) ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 201, $response->get_status() );
		$order_id = $response->get_data()['id'];

		$notes = $this->get_order_notes_by_content( $order_id );
		$this->assertArrayHasKey( $text, $notes, 'The order should carry the note Miguel sent.' );
		$this->assertFalse( $notes[ $text ]->customer_note, 'The note must be private, not a customer note.' );
		$this->assertSame( 'system', $notes[ $text ]->added_by );

		// Not forwarded to WooCommerce as anything else.
		$order = wc_get_order( $order_id );
		$this->assertSame( '', $order->get_customer_note() );
		$this->assertSame( '', $order->get_meta( 'order_note' ) );

		Miguel_Helper_Order::delete_order( $order_id );
	}

	/**
	 * The note is sanitized like post content: scripts go, basic inline HTML stays.
	 */
	public function test_create_order_sanitizes_order_note() {
		$product = Miguel_Helper_Product::create_downloadable_product();

		$api      = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );
		$response = $api->create_order(
			$this->build_order_request(
				$product->get_id(),
				array( 'order_note' => '<script>alert(1)</script>Objednávka z aplikace <strong>Melvil</strong>.' )
			)
		);

		$this->assertSame( 201, $response->get_status() );
		$order_id = $response->get_data()['id'];

		$matching = array_filter(
			array_keys( $this->get_order_notes_by_content( $order_id ) ),
			function ( $content ) {
				return false !== strpos( $content, '<strong>Melvil</strong>' );
			}
		);
		$this->assertCount( 1, $matching, 'The sanitized note should be on the order, <strong> kept.' );
		$this->assertStringNotContainsString( '<script', reset( $matching ) );

		Miguel_Helper_Order::delete_order( $order_id );
	}

	/**
	 * Absent, null, blank and markup-only notes add nothing: the order ends up with
	 * exactly the notes of an identical order sent without the field.
	 */
	public function test_create_order_without_usable_order_note_adds_no_note() {
		$product = Miguel_Helper_Product::create_downloadable_product();
		$api     = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$control  = $api->create_order( $this->build_order_request( $product->get_id() ) );
		$expected = count( $this->get_order_notes_by_content( $control->get_data()['id'] ) );

		foreach ( array( null, '', '   ', '<script></script>' ) as $unusable_note ) {
			$response = $api->create_order( $this->build_order_request( $product->get_id(), array( 'order_note' => $unusable_note ) ) );

			$this->assertSame( 201, $response->get_status() );
			$this->assertCount(
				$expected,
				$this->get_order_notes_by_content( $response->get_data()['id'] ),
				'An order_note of ' . wp_json_encode( $unusable_note ) . ' must not add a note.'
			);

			Miguel_Helper_Order::delete_order( $response->get_data()['id'] );
		}

		Miguel_Helper_Order::delete_order( $control->get_data()['id'] );
	}

	/**
	 * Replaying the same request returns the existing order and adds no second note.
	 */
	public function test_create_order_replay_does_not_duplicate_order_note() {
		$product   = Miguel_Helper_Product::create_downloadable_product();
		$text      = 'Objednávka vytvořena v aplikaci Miguel.';
		$overrides = array(
			'idempotency_key' => 'order-note-replay-' . $product->get_id(),
			'order_note'      => $text,
		);

		$api    = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );
		$first  = $api->create_order( $this->build_order_request( $product->get_id(), $overrides ) );
		$replay = $api->create_order( $this->build_order_request( $product->get_id(), $overrides ) );

		$this->assertSame( 201, $first->get_status() );
		$this->assertSame( 200, $replay->get_status() );
		$this->assertTrue( $replay->get_data()['idempotent_replay'] );
		$this->assertSame( $first->get_data()['id'], $replay->get_data()['id'] );

		// Count raw notes: get_order_notes_by_content() keys by text and would hide a duplicate.
		$with_text = array_filter(
			wc_get_order_notes( array( 'order_id' => $first->get_data()['id'] ) ),
			function ( $note ) use ( $text ) {
				return $text === $note->content;
			}
		);
		$this->assertCount( 1, $with_text );

		Miguel_Helper_Order::delete_order( $first->get_data()['id'] );
	}
```

Add this private helper after `build_order_request()`:

```php
	/**
	 * All notes of an order, keyed by their text.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return array Note objects as returned by wc_get_order_notes(), keyed by content.
	 */
	private function get_order_notes_by_content( $order_id ) {
		$notes = array();
		foreach ( wc_get_order_notes( array( 'order_id' => $order_id ) ) as $note ) {
			$notes[ $note->content ] = $note;
		}

		return $notes;
	}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker compose -f docker-compose.test.yml run --rm phpunit --filter='order_note'`
Expected:
- `test_prepare_payload_for_wc_order_strips_order_note` FAILS ("Failed asserting that an array does not have the key 'order_note'").
- `test_create_order_adds_order_note_as_private_note` FAILS ("The order should carry the note Miguel sent.").
- `test_create_order_sanitizes_order_note` FAILS (count 0, expected 1).
- `test_create_order_replay_does_not_duplicate_order_note` FAILS (count 0, expected 1).
- `test_create_order_without_usable_order_note_adds_no_note` passes already — it guards the implementation against adding blank notes.

- [ ] **Step 3: Implement**

In `includes/class-miguel-order-create-api.php`:

a) Add two private methods directly after `get_user_email_from_payload()`:

```php
	/**
	 * Extract the order note Miguel sent, sanitized like post content.
	 *
	 * Read from the payload as sent, before prepare_payload_for_wc_order() strips it.
	 *
	 * @param array $payload Request payload.
	 * @return string Sanitized note, or '' when there is none.
	 */
	private function get_order_note_from_payload( $payload ) {
		if ( ! isset( $payload['order_note'] ) || ! is_string( $payload['order_note'] ) ) {
			return '';
		}

		return trim( wp_kses_post( $payload['order_note'] ) );
	}

	/**
	 * Record Miguel's note on the order as a private note.
	 *
	 * The order already exists at this point, so a note WooCommerce refuses is logged
	 * rather than reported: failing the request would make Miguel retry an order that
	 * was created.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @param string   $note Sanitized note text.
	 * @return void
	 */
	private function add_miguel_order_note( $order, $note ) {
		if ( '' === $note ) {
			return;
		}

		if ( ! $order->add_order_note( $note ) ) {
			Miguel::debug_log(
				'Failed to add Miguel order note',
				array(
					'order_id' => $order->get_id(),
					'order_note_length' => strlen( $note ),
				)
			);
		}
	}
```

b) In `prepare_payload_for_wc_order()`, extend the first `unset()`:

```php
		unset( $payload['send_emails'], $payload['send_email'], $payload['email_template'], $payload['order_note'] );
```

c) In `create_order()`, in the `'Received order create payload'` debug log context array, add after the `'email_template'` entry:

```php
				'order_note_length' => isset( $payload['order_note'] ) && is_string( $payload['order_note'] ) ? strlen( $payload['order_note'] ) : 0,
```

d) In `create_order()`, directly after the `$top_level_validation` check returns, read the note from the payload as sent:

```php
		$order_note = $this->get_order_note_from_payload( $payload );
```

e) In `create_order()`, in the block that stores the idempotency key on the created order, add the note after `save_meta_data()`:

```php
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$order->update_meta_data( '_miguel_idempotency_key', $idempotency_key );
				$order->save_meta_data();
				$this->add_miguel_order_note( $order, $order_note );
			}
```

The replay paths (`build_replay_response()`) return before this block, which is what keeps a replay from adding the note twice.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `docker compose -f docker-compose.test.yml run --rm phpunit --filter=Test_Miguel_Order_Create_Api`
Expected: PASS, including every pre-existing test in the class.

- [ ] **Step 5: Document the field**

`docs/openapi.yaml`, schema `CreateOrderRequest`, add after the `email_template` property (same indentation as `send_emails`):

```yaml
        order_note:
          type: string
          nullable: true
          description: >
            Private order note to record on the created order, e.g. which app the order
            was placed in. Visible to the shop only — never shown or emailed to the
            customer. Sanitized like post content, so basic inline HTML such as
            `<strong>` is kept. Omit it, or send null or an empty string, for no note.
            Any other type is rejected with 409 `order.order_note_invalid`. Part of
            the idempotency payload: a replay adds no second note.
          example: "Objednávka vytvořena v aplikaci Melvil."
```

`CHANGELOG.md`, append to the end of the `## 1.10.0` list:

```markdown
* `POST /orders` accepts an optional `order_note` and records it on the created order as a private note, visible to the shop only. Miguel uses it to say which app an order was placed in; the text comes from Miguel, so its wording can change without a plugin release
```

`readme.txt`, append to the end of the `= 1.10.0 =` list:

```
* Orders created from the Miguel app can carry a private note, visible to the shop only, saying which app they were placed in
```

- [ ] **Step 6: Run the whole suite and the sniffer**

Run: `make test-docker`
Expected: all tests PASS.

Run: `docker compose -f docker-compose.test.yml run --rm --entrypoint vendor/bin/phpcs phpunit includes/class-miguel-order-create-api.php`
Expected: no errors.

- [ ] **Step 7: Commit**

```bash
git add includes/class-miguel-order-create-api.php tests/unit/test-order-create-api.php docs/openapi.yaml CHANGELOG.md readme.txt
git commit -m "feat(orders): record Miguel's order_note as a private order note (SMP-12)"
```
