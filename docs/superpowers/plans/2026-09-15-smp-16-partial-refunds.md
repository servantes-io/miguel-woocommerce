# SMP-16 — Products Refunded in WooCommerce Leave Miguel — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When a shop refunds some products of an order, Miguel receives only the products and quantities the customer is still entitled to; refunding every Miguel product removes the order from Miguel, and deleting a refund gives the products back.

**Architecture:** One new static class, `Miguel_Order_Refunds`, holds the entitlement rule (refunded quantity vs. whole units covered by the refunded amount). The push (`Miguel_Order_Mapper::map()` → `Miguel_Orders::sync_order()`) and the pull (`Miguel_Orders_Api`) both ask it instead of reading `$item->get_quantity()`. `Miguel_Orders` also re-saves the order when a refund is deleted, because WooCommerce does not.

**Tech Stack:** PHP 8.1, WordPress, WooCommerce (tests run 9.9.5, CPT order storage), PHPUnit 9.6 via the WooCommerce unit-test framework, Docker.

**Spec:** `docs/superpowers/specs/2026-09-15-smp-16-partial-refunds-design.md`

## Global Constraints

- **Tests run in Docker only, from the worktree root.** Full suite: `docker compose -p miguel-woocommerce -f docker-compose.test.yml run --rm phpunit`. One class or test: append `--filter=<ClassName or test_method>`. Never run `vendor/bin/phpunit` on the host. `vendor/` is already present in the worktree; `-p miguel-woocommerce` reuses the cached WordPress/WooCommerce volumes — do not drop it. Baseline before this plan: **234 tests, all green**.
- **phpcs, both rulesets, on every file you touch:**
  `docker compose -p miguel-woocommerce -f docker-compose.test.yml run --rm --no-deps --entrypoint vendor/bin/phpcs phpunit --standard=phpcs.xml <files>`
  `docker compose -p miguel-woocommerce -f docker-compose.test.yml run --rm --no-deps --entrypoint vendor/bin/phpcs phpunit --standard=phpcs-woocommerce.xml <files>` (the CI ruleset; it excludes `tests/`, and CI ignores warnings — errors must be 0).
- **WordPress coding standards:** tabs, spaces inside parentheses, Yoda conditions for `==`/`===`, `array()` not `[]`, docblocks with `@param`/`@return` types (the files use docblock types, not native type declarations, on public methods), files start with the `ABSPATH` guard.
- **The rule, verbatim from the spec:** `entitled = max( 0, ordered − max( refunded_qty, money_units ) )`, `money_units = unit_price > 0 ? floor( ( refunded_total + tolerance ) / unit_price ) : 0`, `unit_price = line_total / ordered` (`line_total = $item->get_total()`, without tax, after discounts), `tolerance = 0.5 × 10^(−wc_get_price_decimals())`.
- **WooCommerce facts (verified on 9.9.5):** `get_qty_refunded_for_item()` returns a **negative** int; `get_total_refunded_for_item()` returns a **positive**, rounded float; both compare `absint( meta ) === $item_id`, so pass an **int** item ID. They see a refund's creation and deletion immediately, even on an order object loaded before. `wc_create_refund()` requires `amount` ≤ the order's remaining refundable total — **call `$order->calculate_totals( false ); $order->save();` after adding products** in tests, or the refund fails with "Invalid refund amount". Creating a refund fires `woocommerce_update_order` on the parent; `$order->save()` always fires `woocommerce_update_order`, even with no other change. Deleting a refund (`$refund->delete( true )`) fires nothing on the parent.
- **Test helpers:** `Miguel_Helper_Order::create_order()` already adds one non-Miguel virtual line at 10.00 (status `processing`, date paid set). `Miguel_Helper_Product::create_downloadable_product()` is a 10.00 product whose downloads carry Miguel code `dummy-name`. `WC_Helper_Product::create_simple_product()` gives each product a unique SKU on 9.9.5 (`DUMMY SKU<n>`), so several products per test are fine. The plugin's own hooks are **not** registered in tests (`MIGUEL_TESTS`), so creating a refund does not trigger a sync on its own — tests call `sync_order()` explicitly.
- **No new user-facing strings.** No backend change.
- **Commits:** one per task, message as given, each ending with a blank line and then:
  `Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>`
  `Claude-Session: https://claude.ai/code/session_015Um27cH9CVKtk5nxFmjFzV`

---

## File Structure

| Action | Path | Responsibility |
|---|---|---|
| Create | `includes/class-miguel-order-refunds.php` | `Miguel_Order_Refunds` — the entitlement rule and "everything refunded" |
| Modify | `includes/class-miguel.php` | Include the new file |
| Modify | `includes/api/v2/mappers/class-miguel-order-mapper.php` | Send entitled quantities, skip lines with none |
| Modify | `includes/class-miguel-orders.php` | Delete in Miguel when every Miguel line is refunded; re-save the order when a refund is deleted |
| Modify | `includes/class-miguel-orders-api.php` | Pull: omit refunded lines from `products`, report `deleted` when everything is refunded |
| Modify | `tests/helpers/class-miguel-helper-order.php` | `refund_line()` test helper |
| Modify | `tests/helpers/class-miguel-helper-product.php` | `create_miguel_product( $code )` test helper |
| Create | `tests/unit/test-order-refunds.php` | The rule |
| Modify | `tests/unit/test-order-mapper.php`, `tests/unit/test-orders.php`, `tests/unit/test-orders-api.php` | Behaviour of each consumer |
| Modify | `docs/openapi.yaml`, `CHANGELOG.md`, `readme.txt` | Docs (README.md only lists endpoints — no change) |

---

### Task 1: The entitlement rule — `Miguel_Order_Refunds`

**Files:**
- Create: `includes/class-miguel-order-refunds.php`
- Modify: `includes/class-miguel.php` (the `includes()` list)
- Modify: `tests/helpers/class-miguel-helper-order.php`
- Create: `tests/unit/test-order-refunds.php`

**Interfaces:**
- Consumes: nothing from other tasks.
- Produces:
  - `Miguel_Order_Refunds::get_entitled_quantity( WC_Order $order, WC_Order_Item_Product $item ): int` (static)
  - `Miguel_Order_Refunds::has_refunded_all_miguel_items( WC_Order $order, callable $has_miguel_codes ): bool` (static; the callable receives a `WC_Product` and returns bool)
  - Test helper `Miguel_Helper_Order::refund_line( WC_Order $order, int $item_id, int $qty, float $amount ): WC_Order_Refund` (static; throws on failure)

- [x] **Step 1: Add the refund test helper**

In `tests/helpers/class-miguel-helper-order.php`, add this method after `create_order_downloadable()`:

```php
	/**
	 * Refund part of one line the way the admin refund form does.
	 *
	 * The order's totals must be calculated first ($order->calculate_totals( false )), because
	 * WooCommerce refuses a refund larger than what is left to refund. Orders loaded before the
	 * refund see it straight away.
	 *
	 * @param WC_Order $order   Order.
	 * @param int      $item_id Line item ID.
	 * @param int      $qty     Quantity to refund; 0 for an amount-only refund.
	 * @param float    $amount  Amount to refund on the line, without tax.
	 * @return WC_Order_Refund
	 * @throws Exception When WooCommerce refuses the refund.
	 */
	public static function refund_line( $order, $item_id, $qty, $amount ) {
		$refund = wc_create_refund(
			array(
				'order_id'   => $order->get_id(),
				'amount'     => $amount,
				'line_items' => array(
					$item_id => array(
						'qty'          => $qty,
						'refund_total' => $amount,
					),
				),
			)
		);

		if ( is_wp_error( $refund ) ) {
			throw new Exception( 'Refund failed: ' . $refund->get_error_message() );
		}

		return $refund;
	}
```

- [x] **Step 2: Write the failing tests**

Create `tests/unit/test-order-refunds.php`:

```php
<?php
/**
 * Tests for Miguel_Order_Refunds.
 *
 * @package Miguel\Tests
 */
class Miguel_Test_Order_Refunds extends Miguel_Test_Case {

	/**
	 * Rows: line total, ordered quantity, refunded quantity, refunded amount, expected entitled units.
	 *
	 * @return array
	 */
	public function entitlement_cases(): array {
		return array(
			'quantity refunded, no money'           => array( 199.00, 1, 1, 0.00, 0 ),
			'full amount refunded, quantity empty'  => array( 199.00, 1, 0, 199.00, 0 ),
			'compensation keeps access'             => array( 199.00, 1, 0, 40.00, 1 ),
			'one of two units by quantity'          => array( 398.00, 2, 1, 0.00, 1 ),
			'one of two units by amount'            => array( 398.00, 2, 0, 199.00, 1 ),
			'amount over one unit but short of two' => array( 398.00, 2, 0, 250.00, 1 ),
			'amount wins over a smaller quantity'   => array( 398.00, 2, 1, 398.00, 0 ),
			'free line refunded by quantity'        => array( 0.00, 1, 1, 0.00, 0 ),
			'free line, no refund'                  => array( 0.00, 1, 0, 0.00, 1 ),
			'no refund'                             => array( 199.00, 1, 0, 0.00, 1 ),
			'rounding: 33.33 of a 100.00/3 line'    => array( 100.00, 3, 0, 33.33, 2 ),
		);
	}

	/**
	 * @dataProvider entitlement_cases
	 */
	public function test_entitled_quantity( $line_total, $ordered, $refund_qty, $refund_amount, $expected ): void {
		list( $order, $item_id ) = $this->order_with_line( $line_total, $ordered );

		if ( $refund_qty > 0 || $refund_amount > 0 ) {
			Miguel_Helper_Order::refund_line( $order, $item_id, $refund_qty, $refund_amount );
		}

		$order = wc_get_order( $order->get_id() );

		$this->assertSame( $expected, Miguel_Order_Refunds::get_entitled_quantity( $order, $order->get_item( $item_id ) ) );
	}

	public function test_refunds_accumulate_across_several_refunds(): void {
		list( $order, $item_id ) = $this->order_with_line( 597.00, 3 );

		Miguel_Helper_Order::refund_line( $order, $item_id, 1, 0.00 );
		Miguel_Helper_Order::refund_line( $order, $item_id, 1, 0.00 );

		$order = wc_get_order( $order->get_id() );

		$this->assertSame( 1, Miguel_Order_Refunds::get_entitled_quantity( $order, $order->get_item( $item_id ) ) );
	}

	public function test_nothing_is_refunded_when_the_order_has_no_miguel_line(): void {
		$order   = Miguel_Helper_Order::create_order();
		$item_id = key( $order->get_items() );
		$order->calculate_totals( false );
		$order->save();
		Miguel_Helper_Order::refund_line( $order, $item_id, 1, 10.00 );

		$never_miguel = function () {
			return false;
		};

		$this->assertFalse( Miguel_Order_Refunds::has_refunded_all_miguel_items( wc_get_order( $order->get_id() ), $never_miguel ) );
	}

	public function test_everything_is_refunded_only_once_every_miguel_line_is_gone(): void {
		$first  = WC_Helper_Product::create_simple_product();
		$second = WC_Helper_Product::create_simple_product();

		$order          = Miguel_Helper_Order::create_order(); // Also holds a non-Miguel line, which stays.
		$first_item_id  = $order->add_product( $first, 1 );
		$second_item_id = $order->add_product( $second, 1 );
		$order->calculate_totals( false );
		$order->save();

		$miguel_ids = array( $first->get_id(), $second->get_id() );
		$is_miguel  = function ( $product ) use ( $miguel_ids ) {
			return in_array( $product->get_id(), $miguel_ids, true );
		};

		$this->assertFalse( Miguel_Order_Refunds::has_refunded_all_miguel_items( wc_get_order( $order->get_id() ), $is_miguel ), 'nothing refunded yet' );

		Miguel_Helper_Order::refund_line( $order, $first_item_id, 1, 10.00 );
		$this->assertFalse( Miguel_Order_Refunds::has_refunded_all_miguel_items( wc_get_order( $order->get_id() ), $is_miguel ), 'one Miguel line remains' );

		Miguel_Helper_Order::refund_line( $order, $second_item_id, 0, 10.00 );
		$this->assertTrue( Miguel_Order_Refunds::has_refunded_all_miguel_items( wc_get_order( $order->get_id() ), $is_miguel ), 'every Miguel line is gone' );
	}

	/**
	 * An order with one product line of the given total and quantity, totals calculated.
	 *
	 * @param float $line_total Line total, without tax.
	 * @param int   $ordered    Quantity.
	 * @return array Order and line item ID.
	 */
	private function order_with_line( $line_total, $ordered ) {
		$product = WC_Helper_Product::create_simple_product();
		$order   = wc_create_order();
		$item_id = $order->add_product(
			$product,
			$ordered,
			array(
				'subtotal' => $line_total,
				'total'    => $line_total,
			)
		);
		$order->calculate_totals( false );
		$order->save();

		return array( $order, $item_id );
	}
}
```

- [x] **Step 3: Run the tests to verify they fail**

Run: `docker compose -p miguel-woocommerce -f docker-compose.test.yml run --rm phpunit --filter=Miguel_Test_Order_Refunds`
Expected: FAIL — `Error: Class "Miguel_Order_Refunds" not found` for every test.

- [x] **Step 4: Implement the class**

Create `includes/class-miguel-order-refunds.php`:

```php
<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * What refunds leave the customer: how many units of each line they are still entitled to.
 *
 * WooCommerce records a refund as a separate refund order and never changes the refunded order's
 * line items, so a line's quantity is always the quantity ordered. Everything that tells Miguel
 * what the customer owns asks this class instead.
 *
 * @package Miguel
 */
class Miguel_Order_Refunds {

	/**
	 * Units of a line the customer is still entitled to after the order's refunds.
	 *
	 * A unit is gone when its quantity was refunded, or when the money refunded on the line covers
	 * it — shops refunding digital goods often type only the amount. Whichever removes more wins.
	 * Money short of a whole unit (compensation) takes nothing away, and a free line can lose units
	 * only through its quantity.
	 *
	 * @param WC_Order              $order Order the line belongs to.
	 * @param WC_Order_Item_Product $item  Product line.
	 * @return int
	 */
	public static function get_entitled_quantity( $order, $item ) {
		$ordered = (int) $item->get_quantity();
		if ( $ordered <= 0 ) {
			return 0;
		}

		$item_id        = (int) $item->get_id();
		$refunded_qty   = (int) abs( $order->get_qty_refunded_for_item( $item_id ) );
		$refunded_total = abs( (float) $order->get_total_refunded_for_item( $item_id ) );
		$unit_price     = (float) $item->get_total() / $ordered;

		$money_units = 0;
		if ( $unit_price > 0 ) {
			// Half the smallest price step, so a refund of one unit's rounded price counts as that
			// unit despite floating-point division (33.33 of a 100.00 line of three).
			$tolerance   = 0.5 * pow( 10, -wc_get_price_decimals() );
			$money_units = (int) floor( ( $refunded_total + $tolerance ) / $unit_price );
		}

		return max( 0, $ordered - max( $refunded_qty, $money_units ) );
	}

	/**
	 * Whether refunds took away everything Miguel delivers in this order.
	 *
	 * True only when the order has at least one line whose product carries Miguel codes and every
	 * such line is left with no units. An order without Miguel lines has nothing to take away, so
	 * it is never "all refunded" by this measure.
	 *
	 * @param WC_Order $order            Order.
	 * @param callable $has_miguel_codes Receives a WC_Product; returns whether it carries Miguel codes.
	 * @return bool
	 */
	public static function has_refunded_all_miguel_items( $order, $has_miguel_codes ) {
		$has_miguel_line = false;

		foreach ( $order->get_items() as $item ) {
			if ( ! ( $item instanceof WC_Order_Item_Product ) ) {
				continue;
			}

			$product = $item->get_product();
			if ( ! $product || ! call_user_func( $has_miguel_codes, $product ) ) {
				continue;
			}

			$has_miguel_line = true;
			if ( self::get_entitled_quantity( $order, $item ) > 0 ) {
				return false;
			}
		}

		return $has_miguel_line;
	}
}
```

In `includes/class-miguel.php`, `includes()`, add the new file right after the `class-miguel-order-utils.php` line:

```php
		include_once dirname( MIGUEL_PLUGIN_FILE ) . '/includes/class-miguel-order-utils.php';
		include_once dirname( MIGUEL_PLUGIN_FILE ) . '/includes/class-miguel-order-refunds.php';
```

- [x] **Step 5: Run the tests to verify they pass**

Run: `docker compose -p miguel-woocommerce -f docker-compose.test.yml run --rm phpunit --filter=Miguel_Test_Order_Refunds`
Expected: PASS — `OK (14 tests, …)` (11 data-provider rows + 3 tests).

- [x] **Step 6: phpcs**

Run both phpcs commands from Global Constraints on `includes/class-miguel-order-refunds.php includes/class-miguel.php tests/unit/test-order-refunds.php tests/helpers/class-miguel-helper-order.php`. Expected: 0 errors.

- [x] **Step 7: Commit**

```bash
git add includes/class-miguel-order-refunds.php includes/class-miguel.php tests/unit/test-order-refunds.php tests/helpers/class-miguel-helper-order.php docs/superpowers/plans/2026-09-15-smp-16-partial-refunds.md
git commit -m "feat(orders): work out what refunds leave the customer of each line"
```

---

### Task 2: The push sends only what the customer keeps — `Miguel_Order_Mapper`

**Files:**
- Modify: `includes/api/v2/mappers/class-miguel-order-mapper.php` (`map()`)
- Modify: `tests/helpers/class-miguel-helper-product.php`
- Modify: `tests/unit/test-order-mapper.php`

**Interfaces:**
- Consumes: `Miguel_Order_Refunds::get_entitled_quantity( $order, $item ): int`; `Miguel_Helper_Order::refund_line( $order, $item_id, $qty, $amount )` (Task 1).
- Produces: `Miguel_Order_Mapper::map()` — unchanged signature; lines with 0 entitled units are skipped, others carry `quantity = entitled units`, `null` when no line is left. Test helper `Miguel_Helper_Product::create_miguel_product( string $code ): WC_Product` (static).

- [x] **Step 1: Add the product test helper**

In `tests/helpers/class-miguel-helper-product.php`, add after `create_downloadable_product()`:

```php
	/**
	 * A downloadable 10.00 product whose single download carries the given Miguel code.
	 *
	 * @param string $code Miguel code.
	 * @return WC_Product
	 */
	public static function create_miguel_product( $code ) {
		$product = self::create_downloadable_product();

		self::set_product_downloads_bypass_validation(
			$product,
			array(
				$code . '_epub_' . wp_generate_uuid4() => array(
					'name' => 'Book ' . $code,
					'file' => '[miguel id="' . $code . '" format="epub"]',
				),
			)
		);

		// Reload: the downloads were written straight to post meta.
		return wc_get_product( $product->get_id() );
	}
```

- [x] **Step 2: Write the failing tests**

Add to `tests/unit/test-order-mapper.php`, before the closing `}` of the class:

```php
	public function test_a_refunded_quantity_lowers_the_quantity_sent(): void {
		$product = Miguel_Helper_Product::create_downloadable_product();
		$order   = Miguel_Helper_Order::create_order();
		$item_id = $order->add_product( $product, 2 );
		$order->calculate_totals( false );
		$order->save();

		Miguel_Helper_Order::refund_line( $order, $item_id, 1, 10.00 );

		$arr = ( new Miguel_Order_Mapper() )->map( wc_get_order( $order->get_id() ) )->to_array();

		$this->assertCount( 1, $arr['items'] );
		$this->assertSame( 'dummy-name', $arr['items'][0]['code'] );
		$this->assertSame( 1, $arr['items'][0]['quantity'] );
		$this->assertSame( 10.0, $arr['items'][0]['soldPrice'], 'the price of the units kept is unchanged' );

		Miguel_Helper_Order::delete_order( $order->get_id() );
	}

	public function test_a_fully_refunded_line_is_left_out(): void {
		$kept     = Miguel_Helper_Product::create_miguel_product( 'kept-book' );
		$refunded = Miguel_Helper_Product::create_miguel_product( 'refunded-book' );

		$order = Miguel_Helper_Order::create_order();
		$order->add_product( $kept, 1 );
		$refunded_item_id = $order->add_product( $refunded, 1 );
		$order->calculate_totals( false );
		$order->save();

		// Amount only, as shops refunding e-books often do.
		Miguel_Helper_Order::refund_line( $order, $refunded_item_id, 0, 10.00 );

		$arr = ( new Miguel_Order_Mapper() )->map( wc_get_order( $order->get_id() ) )->to_array();

		$this->assertSame( array( 'kept-book' ), array_column( $arr['items'], 'code' ) );

		Miguel_Helper_Order::delete_order( $order->get_id() );
	}

	public function test_a_refunded_bundle_line_leaves_out_all_its_codes(): void {
		$book1 = Miguel_Helper_Product::create_miguel_product( 'bundle-book-1' );
		$book2 = Miguel_Helper_Product::create_miguel_product( 'bundle-book-2' );

		$bundle = WC_Helper_Product::create_simple_product();
		$bundle->set_regular_price( '20' );
		$bundle->save();
		update_post_meta(
			$bundle->get_id(),
			'_bundle_ids',
			array(
				(string) $book1->get_id() => array(),
				(string) $book2->get_id() => array(),
			)
		);

		$order = Miguel_Helper_Order::create_order();
		$order->add_product( Miguel_Helper_Product::create_downloadable_product(), 1 );
		$bundle_item_id = $order->add_product( $bundle, 1 );
		$order->calculate_totals( false );
		$order->save();

		Miguel_Helper_Order::refund_line( $order, $bundle_item_id, 1, 20.00 );

		$arr = ( new Miguel_Order_Mapper() )->map( wc_get_order( $order->get_id() ) )->to_array();

		$this->assertSame( array( 'dummy-name' ), array_column( $arr['items'], 'code' ) );

		Miguel_Helper_Order::delete_order( $order->get_id() );
	}

	public function test_returns_null_once_every_miguel_line_is_refunded(): void {
		$product = Miguel_Helper_Product::create_downloadable_product();
		$order   = Miguel_Helper_Order::create_order();
		$item_id = $order->add_product( $product, 1 );
		$order->calculate_totals( false );
		$order->save();

		Miguel_Helper_Order::refund_line( $order, $item_id, 1, 10.00 );

		$this->assertNull( ( new Miguel_Order_Mapper() )->map( wc_get_order( $order->get_id() ) ) );

		Miguel_Helper_Order::delete_order( $order->get_id() );
	}
```

- [x] **Step 3: Run the tests to verify they fail**

Run: `docker compose -p miguel-woocommerce -f docker-compose.test.yml run --rm phpunit --filter=Miguel_Test_Order_Mapper`
Expected: the four new tests FAIL (quantity 2 instead of 1; the refunded codes still present; a DTO instead of `null`); the existing mapper tests still pass.

- [x] **Step 4: Implement**

In `includes/api/v2/mappers/class-miguel-order-mapper.php`, `map()`, replace the lines between the `$product` check and the inner `foreach`:

```php
			$item_total = $order->get_item_total( $item, false, false );
			$quantity   = (int) $item->get_quantity();
```

with:

```php
			// What the customer still owns of this line: WooCommerce keeps refunded lines as they
			// were ordered and records the refund separately.
			$quantity = Miguel_Order_Refunds::get_entitled_quantity( $order, $item );
			if ( $quantity <= 0 ) {
				continue;
			}

			$item_total = $order->get_item_total( $item, false, false );
```

Also update the `map()` docblock's return line to:

```php
	 * @return Miguel_V2_Order_Create|null Null when no Miguel item is left (none ordered, or all refunded).
```

- [x] **Step 5: Run the tests to verify they pass**

Run: `docker compose -p miguel-woocommerce -f docker-compose.test.yml run --rm phpunit --filter=Miguel_Test_Order_Mapper`
Expected: PASS, all mapper tests.

- [x] **Step 6: phpcs**

Both phpcs commands on `includes/api/v2/mappers/class-miguel-order-mapper.php tests/unit/test-order-mapper.php tests/helpers/class-miguel-helper-product.php`. Expected: 0 errors.

- [x] **Step 7: Commit**

```bash
git add includes/api/v2/mappers/class-miguel-order-mapper.php tests/unit/test-order-mapper.php tests/helpers/class-miguel-helper-product.php docs/superpowers/plans/2026-09-15-smp-16-partial-refunds.md
git commit -m "feat(orders): send Miguel only the products a refund leaves the customer"
```

---

### Task 3: Sync — delete when everything is refunded, resync when a refund is deleted — `Miguel_Orders`

**Files:**
- Modify: `includes/class-miguel-orders.php` (`register_hooks()`, `sync_order()`, new `handle_refund_deleted()`)
- Modify: `tests/unit/test-orders.php`

**Interfaces:**
- Consumes: `Miguel_Order_Refunds::has_refunded_all_miguel_items( $order, callable ): bool` (Task 1); `Miguel_Order_Mapper::has_miguel_codes( WC_Product ): bool` (existing, public); the mapper's refund-aware `map()` (Task 2); test helpers `Miguel_Helper_Order::refund_line()` (Task 1) and `Miguel_Helper_Product::create_miguel_product()` (Task 2).
- Produces: `Miguel_Orders::handle_refund_deleted( int $refund_id, int $order_id ): void` (public), registered on `woocommerce_refund_deleted` with priority 10 and 2 accepted args.

- [x] **Step 1: Write the failing tests**

Add to `tests/unit/test-orders.php`, before the closing `}` of the class:

```php
	/**
	 * A partial refund reaches Miguel as the order without the refunded product; Miguel then
	 * deletes that item and expires its links.
	 */
	public function test_a_partial_refund_sends_the_order_without_the_refunded_product() {
		Miguel_Helper_HTTP::mock_api_responses(
			array(
				'POST' => array(
					'body'     => '{}',
					'response' => array( 'code' => 201, 'message' => 'Created' ),
				),
			)
		);

		$kept     = Miguel_Helper_Product::create_miguel_product( 'kept-book' );
		$refunded = Miguel_Helper_Product::create_miguel_product( 'refunded-book' );

		$order = Miguel_Helper_Order::create_order();
		$order->add_product( $kept, 1 );
		$refunded_item_id = $order->add_product( $refunded, 1 );
		$order->calculate_totals( false );
		$order->save();

		Miguel_Helper_Order::refund_line( $order, $refunded_item_id, 1, 10.00 );
		$order = wc_get_order( $order->get_id() );

		$this->get_sut()->sync_order( $order->get_id(), '', $order->get_status(), $order );

		$requests = Miguel_Helper_HTTP::get_requests();
		$this->assertCount( 1, $requests, 'Different number of requests: ' . print_r( $requests, true ) );
		$this->assertEquals( 'POST', $requests[0]['method'] );
		$body = json_decode( $requests[0]['body'], true );
		$this->assertSame( array( 'kept-book' ), array_column( $body['items'], 'code' ) );

		Miguel_Helper_Order::delete_order( $order->get_id() );
	}

	/**
	 * Refunding every Miguel product removes the order from Miguel even though WooCommerce keeps
	 * the order's status, because something else in it (here a non-Miguel line) was not refunded.
	 */
	public function test_refunding_every_miguel_product_deletes_the_order_in_miguel() {
		Miguel_Helper_HTTP::mock_api_responses(
			array(
				'DELETE' => array(
					'body'     => '',
					'response' => array( 'code' => 204, 'message' => 'No Content' ),
				),
			)
		);

		$product = Miguel_Helper_Product::create_downloadable_product();
		$order   = Miguel_Helper_Order::create_order();
		$item_id = $order->add_product( $product, 1 );
		$order->calculate_totals( false );
		$order->save();

		Miguel_Helper_Order::refund_line( $order, $item_id, 0, 10.00 );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'processing', $order->get_status(), 'only part of the order is refunded, so its status stays' );

		$sut = $this->get_sut();
		$sut->sync_order( $order->get_id(), '', $order->get_status(), $order );

		$requests = Miguel_Helper_HTTP::get_requests();
		$this->assertCount( 1, $requests, 'Different number of requests: ' . print_r( $requests, true ) );
		$this->assertEquals( 'DELETE', $requests[0]['method'] );
		$this->assertStringContains( '/v2/orders/' . $order->get_id(), $requests[0]['url'] );

		// The same state again sends nothing.
		$sut->sync_order( $order->get_id(), '', $order->get_status(), $order );
		$this->assertCount( 1, Miguel_Helper_HTTP::get_requests() );

		Miguel_Helper_Order::delete_order( $order->get_id() );
	}

	/**
	 * An order that never held a Miguel product is not deleted in Miguel because of a refund.
	 */
	public function test_a_refund_on_an_order_without_miguel_products_sends_nothing() {
		Miguel_Helper_HTTP::mock_api_responses( array() );

		$order   = Miguel_Helper_Order::create_order();
		$item_id = $order->add_product( Miguel_Helper_Product::create_virtual_product(), 1 );
		$order->calculate_totals( false );
		$order->save();

		Miguel_Helper_Order::refund_line( $order, $item_id, 1, 10.00 );
		$order = wc_get_order( $order->get_id() );

		$this->get_sut()->sync_order( $order->get_id(), '', $order->get_status(), $order );

		$this->assertCount( 0, Miguel_Helper_HTTP::get_requests() );

		Miguel_Helper_Order::delete_order( $order->get_id() );
	}

	/**
	 * Deleting a refund gives the product back: the order is saved, which queues the usual sync,
	 * and that sync sends the product again.
	 */
	public function test_deleting_a_refund_resyncs_the_order_with_the_product_back() {
		Miguel_Helper_HTTP::mock_api_responses(
			array(
				'POST'   => array(
					'body'     => '{}',
					'response' => array( 'code' => 201, 'message' => 'Created' ),
				),
				'DELETE' => array(
					'body'     => '',
					'response' => array( 'code' => 204, 'message' => 'No Content' ),
				),
			)
		);

		$product = Miguel_Helper_Product::create_downloadable_product();
		$order   = Miguel_Helper_Order::create_order();
		$item_id = $order->add_product( $product, 1 );
		$order->calculate_totals( false );
		$order->save();

		$refund = Miguel_Helper_Order::refund_line( $order, $item_id, 1, 10.00 );

		$sut   = $this->get_sut();
		$order = wc_get_order( $order->get_id() );
		$sut->sync_order( $order->get_id(), '', $order->get_status(), $order );
		$this->assertEquals( 'DELETE', Miguel_Helper_HTTP::get_requests()[0]['method'] );

		$refund_id = $refund->get_id();
		$refund->delete( true );

		$saved    = array();
		$listener = function ( $order_id ) use ( &$saved ) {
			$saved[] = $order_id;
		};
		add_action( 'woocommerce_update_order', $listener );
		$sut->handle_refund_deleted( $refund_id, $order->get_id() );
		remove_action( 'woocommerce_update_order', $listener );

		$this->assertContains( $order->get_id(), $saved, 'deleting a refund must save the order, which is what queues its sync' );

		$order = wc_get_order( $order->get_id() );
		$sut->sync_order( $order->get_id(), '', $order->get_status(), $order );

		$requests = Miguel_Helper_HTTP::get_requests();
		$this->assertCount( 2, $requests, 'Different number of requests: ' . print_r( $requests, true ) );
		$this->assertEquals( 'POST', $requests[1]['method'] );
		$body = json_decode( $requests[1]['body'], true );
		$this->assertSame( array( 'dummy-name' ), array_column( $body['items'], 'code' ) );

		Miguel_Helper_Order::delete_order( $order->get_id() );
	}

	public function test_handle_refund_deleted_ignores_a_missing_order() {
		$saved    = array();
		$listener = function ( $order_id ) use ( &$saved ) {
			$saved[] = $order_id;
		};
		add_action( 'woocommerce_update_order', $listener );
		$this->get_sut()->handle_refund_deleted( 0, 999999 );
		remove_action( 'woocommerce_update_order', $listener );

		$this->assertSame( array(), $saved );
	}

	public function test_registers_the_refund_deleted_hook() {
		$hook_manager = new Miguel_Hook_Manager();
		$sut          = $this->create_service_with_mocks(
			'Miguel_Orders',
			array(
				'hook_manager' => $hook_manager,
				'client'       => new Miguel_V2_Client( 'https://example.com', 'test-token' ),
			)
		);

		$sut->register_hooks();

		try {
			$this->assertTrue( $hook_manager->is_hook_registered( 'woocommerce_refund_deleted', array( $sut, 'handle_refund_deleted' ) ) );

			$registered = array_values(
				array_filter(
					$hook_manager->get_registered_hooks(),
					function ( $hook ) {
						return 'woocommerce_refund_deleted' === $hook['hook'];
					}
				)
			);
			$this->assertSame( 2, $registered[0]['accepted_args'] );
		} finally {
			$hook_manager->remove_all_hooks();
		}
	}
```

- [x] **Step 2: Run the tests to verify they fail**

Run: `docker compose -p miguel-woocommerce -f docker-compose.test.yml run --rm phpunit --filter=Test_Miguel_Orders`
Expected: `test_refunding_every_miguel_product_deletes_the_order_in_miguel` FAILs (0 requests: the mapper returns `null`, so the sync does nothing); `test_deleting_a_refund_resyncs…`, `test_handle_refund_deleted_ignores…` and `test_registers_the_refund_deleted_hook` FAIL (`handle_refund_deleted` does not exist / hook not registered). `test_a_partial_refund_sends…` and `test_a_refund_on_an_order_without_miguel_products_sends_nothing` already pass (Task 2); all existing tests pass.

- [x] **Step 3: Implement**

In `includes/class-miguel-orders.php`:

`register_hooks()` — add the refund hook after the async one:

```php
		$this->hook_manager->add_action( self::ASYNC_SYNC_ACTION, array( $this, 'handle_async_order_sync' ), 10, 3 );
		$this->hook_manager->add_action( 'woocommerce_refund_deleted', array( $this, 'handle_refund_deleted' ), 10, 2 );
```

Add this method after `handle_async_order_sync()`:

```php
	/**
	 * Resync an order after one of its refunds is deleted.
	 *
	 * Deleting a refund gives the customer back what it took away, but WooCommerce neither saves
	 * the order nor moves its modified date, so nothing would tell Miguel. Saving the order with a
	 * new modified date fires woocommerce_update_order — which queues the usual sync — and makes
	 * the order show up again in the pull (GET /orders?updated_since).
	 *
	 * @param int $refund_id Deleted refund ID.
	 * @param int $order_id  Order the refund belonged to.
	 * @return void
	 */
	public function handle_refund_deleted( $refund_id, $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$order->set_date_modified( time() );
		$order->save();
	}
```

`sync_order()` — change the first condition from:

```php
		if ( 0 == $order->get_id() || self::is_deleted_order_status( $to_state ) ) {
```

to:

```php
		if ( 0 == $order->get_id() || self::is_deleted_order_status( $to_state ) || $this->has_refunded_all_miguel_items( $order ) ) {
```

and add this private method after `sync_order()`:

```php
	/**
	 * Whether refunds took away every Miguel product in the order.
	 *
	 * Such an order has nothing left for Miguel to deliver, yet WooCommerce keeps its status when
	 * something else in it was not refunded (shipping, a non-Miguel product), so the status alone
	 * would never remove it from Miguel.
	 *
	 * @param WC_Order $order Order object.
	 * @return bool
	 */
	private function has_refunded_all_miguel_items( $order ) {
		return Miguel_Order_Refunds::has_refunded_all_miguel_items( $order, array( $this->mapper, 'has_miguel_codes' ) );
	}
```

- [x] **Step 4: Run the tests to verify they pass**

Run: `docker compose -p miguel-woocommerce -f docker-compose.test.yml run --rm phpunit --filter=Test_Miguel_Orders`
Expected: PASS, all tests in the class.

- [x] **Step 5: phpcs**

Both phpcs commands on `includes/class-miguel-orders.php tests/unit/test-orders.php`. Expected: 0 errors.

- [x] **Step 6: Commit**

```bash
git add includes/class-miguel-orders.php tests/unit/test-orders.php docs/superpowers/plans/2026-09-15-smp-16-partial-refunds.md
git commit -m "feat(orders): remove a fully refunded order from Miguel, and resync when a refund is deleted"
```

---

### Task 4: The pull reports the same — `Miguel_Orders_Api`

**Files:**
- Modify: `includes/class-miguel-orders-api.php` (`format_order()`, `collect_products_from_order()`)
- Modify: `tests/unit/test-orders-api.php`

**Interfaces:**
- Consumes: `Miguel_Order_Refunds::get_entitled_quantity()` and `::has_refunded_all_miguel_items()` (Task 1); test helpers `refund_line()` (Task 1), `create_miguel_product()` (Task 2); the existing private test helper `fetch_orders_by_id()` in this test class.
- Produces: `GET /miguel/v1/orders` and `/orders/{id}` — `products` omits lines with 0 entitled units; `deleted` is also true when every Miguel line is refunded. `line_items` unchanged.

- [ ] **Step 1: Write the failing tests**

Add to `tests/unit/test-orders-api.php`, before the private `fetch_orders_by_id()` helper:

```php
	/**
	 * The pull must not hand back a product the customer was refunded for, or Miguel would
	 * re-create the item the push just removed.
	 */
	public function test_get_order_products_leave_out_a_refunded_line() {
		$kept     = Miguel_Helper_Product::create_miguel_product( 'kept-book' );
		$refunded = Miguel_Helper_Product::create_miguel_product( 'refunded-book' );

		$order = Miguel_Helper_Order::create_order();
		$order->add_product( $kept, 1 );
		$refunded_item_id = $order->add_product( $refunded, 1 );
		$order->calculate_totals( false );
		$order->save();

		Miguel_Helper_Order::refund_line( $order, $refunded_item_id, 1, 10.00 );

		$api     = new Miguel_Orders_Api( new Miguel_Hook_Manager() );
		$request = new WP_REST_Request( 'GET', '/miguel/v1/orders/' . $order->get_id() );
		$request->set_param( 'id', $order->get_id() );

		$data = $api->get_order( $request )->get_data();

		$this->assertSame( array( 'kept-book' ), array_column( $data['products'], 'code' ) );
		$this->assertFalse( $data['deleted'], 'one Miguel product is left, so the order stays' );
	}

	/**
	 * Mirrors the push: an order whose Miguel products were all refunded is reported as deleted,
	 * so the pull can repair a delete whose push never arrived.
	 */
	public function test_get_orders_flags_an_order_whose_miguel_products_were_all_refunded() {
		$product = Miguel_Helper_Product::create_downloadable_product();
		$order   = Miguel_Helper_Order::create_order();
		$item_id = $order->add_product( $product, 1 );
		$order->calculate_totals( false );
		$order->save();

		Miguel_Helper_Order::refund_line( $order, $item_id, 0, 10.00 );

		$orders = $this->fetch_orders_by_id();
		$id     = strval( $order->get_id() );

		$this->assertArrayHasKey( $id, $orders );
		$this->assertSame( 'processing', $orders[ $id ]['status'] );
		$this->assertTrue( $orders[ $id ]['deleted'] );
		$this->assertSame( array(), $orders[ $id ]['products'] );
	}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker compose -p miguel-woocommerce -f docker-compose.test.yml run --rm phpunit --filter=Test_Miguel_Orders_Api`
Expected: both new tests FAIL (`refunded-book` still in `products`; `deleted` false); existing tests pass.

- [ ] **Step 3: Implement**

In `includes/class-miguel-orders-api.php`:

`format_order()` — replace the `deleted` entry and its comment with:

```php
			// Whether the customer no longer has the order: its status says so, or refunds took away
			// every Miguel product in it. Reported rather than withheld: Miguel needs the pull to
			// repair a delete whose push never arrived, so the shop states the fact and Miguel acts
			// on it.
			'deleted'       => Miguel_Orders::is_deleted_order_status( $order->get_status() )
				|| Miguel_Order_Refunds::has_refunded_all_miguel_items(
					$order,
					function ( $product ) {
						return ! empty( $this->code_source->get_codes( $product ) );
					}
				),
```

`collect_products_from_order()` — after the `if ( empty( $codes ) ) { continue; }` block, add:

```php
			// A line the customer was refunded for no longer belongs to them.
			if ( 0 === Miguel_Order_Refunds::get_entitled_quantity( $order, $item ) ) {
				continue;
			}
```

and extend its docblock's description with the sentence: `Lines the customer was refunded for in full (see Miguel_Order_Refunds) are omitted too.`

- [ ] **Step 4: Run the tests to verify they pass**

Run: `docker compose -p miguel-woocommerce -f docker-compose.test.yml run --rm phpunit --filter=Test_Miguel_Orders_Api`
Expected: PASS.

- [ ] **Step 5: phpcs**

Both phpcs commands on `includes/class-miguel-orders-api.php tests/unit/test-orders-api.php`. Expected: 0 errors.

- [ ] **Step 6: Commit**

```bash
git add includes/class-miguel-orders-api.php tests/unit/test-orders-api.php docs/superpowers/plans/2026-09-15-smp-16-partial-refunds.md
git commit -m "feat(orders-api): leave refunded products out of the pull, and flag orders refunded in full"
```

---

### Task 5: Docs and full verification

**Files:**
- Modify: `docs/openapi.yaml` (`components.schemas.Order`)
- Modify: `CHANGELOG.md`, `readme.txt`

**Interfaces:**
- Consumes: the behaviour of Tasks 1–4.
- Produces: nothing code relies on.

- [ ] **Step 1: OpenAPI**

In `docs/openapi.yaml`, `components.schemas.Order.properties`:

Add after `status`:

```yaml
        deleted:
          type: boolean
          description: >
            Whether the customer no longer has the order: its status is one of the
            statuses configured to remove orders from Miguel (by default refunded,
            cancelled and failed), or refunds took away every product Miguel delivers
            in it while WooCommerce kept its status. Such orders are still reported so
            that Miguel can repair a removal whose push never arrived.
```

Replace the `products` description with:

```yaml
          description: >
            Line items whose product exposes a Miguel code are included: digital items
            with a Miguel shortcode, and printed books when a printed-book suffix is
            configured. Items with no Miguel code are omitted, and so are items the
            customer was refunded for in full — by refunded quantity, or by a refunded
            amount covering every unit of the line.
```

Check it still parses: `python3 -c "import yaml,sys; yaml.safe_load(open('docs/openapi.yaml'))"`.

- [ ] **Step 2: CHANGELOG.md and readme.txt**

`CHANGELOG.md`, append to the end of the `## 1.10.0` list (after the `Domain Path` entry):

```markdown
* A partial refund takes the refunded products out of the order Miguel holds, so the customer loses access to what they were refunded for. A product counts as refunded by its refunded quantity or by a refunded amount covering whole units, whichever takes more; money short of a unit's price (compensation) keeps access. Refunding every product Miguel delivers removes the order from Miguel even when WooCommerce keeps the order's status, and deleting a refund gives the products back. The reconciliation endpoint Miguel polls reports the same
```

`readme.txt`, append to the end of the `= 1.10.0 =` list:

```text
* A partial refund takes the refunded products out of the order in Miguel, so the customer loses access to what they were refunded for
```

- [ ] **Step 3: Full suite**

Run: `docker compose -p miguel-woocommerce -f docker-compose.test.yml run --rm phpunit`
Expected: `OK (260 tests, …)` — 234 baseline + 14 (Task 1) + 4 (Task 2) + 6 (Task 3) + 2 (Task 4). If the count differs, the difference must be explained by the tests you added, never by a skipped or failing one.

- [ ] **Step 4: phpcs on everything touched**

Both phpcs commands on: `includes/class-miguel-order-refunds.php includes/class-miguel.php includes/api/v2/mappers/class-miguel-order-mapper.php includes/class-miguel-orders.php includes/class-miguel-orders-api.php tests/unit/test-order-refunds.php tests/unit/test-order-mapper.php tests/unit/test-orders.php tests/unit/test-orders-api.php tests/helpers/class-miguel-helper-order.php tests/helpers/class-miguel-helper-product.php`. Expected: 0 errors (warnings only where the same file already had them on `origin/main`).

- [ ] **Step 5: Commit**

```bash
git add docs/openapi.yaml CHANGELOG.md readme.txt docs/superpowers/plans/2026-09-15-smp-16-partial-refunds.md
git commit -m "docs: refunded products leave Miguel, in the API reference and the 1.10.0 notes"
```

---

## Self-Review

- **Spec coverage:** rule and both examples tables → Task 1; `has_refunded_all_miguel_items` and its callable → Task 1 (and used in Tasks 3, 4); change 1 (mapper, bundles, `null`) → Task 2; change 2 (delete when everything refunded) → Task 3; change 3 (`woocommerce_refund_deleted`) → Task 3; change 4 (pull `products`, `deleted`, `line_items` untouched) → Task 4; change 5 (docs) → Task 5; every Testing bullet has a test. Edge case "refund of a non-Miguel item only → no push" is covered by `test_a_refund_on_an_order_without_miguel_products_sends_nothing` and by the hash being computed from the mapper output.
- **Placeholders:** none.
- **Names:** `get_entitled_quantity`, `has_refunded_all_miguel_items`, `handle_refund_deleted`, `refund_line`, `create_miguel_product` are spelled the same in every task.
