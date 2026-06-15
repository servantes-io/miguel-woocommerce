# Miguel API v2 Migration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Migrate all outbound WooCommerce→Miguel API calls from v1 to v2 via a typed v2 client built from plain PHP value objects (DTOs) and mappers, with a hard cutover.

**Architecture:** A new `Miguel_V2_Client` owns HTTP transport and the 4 endpoints the plugin calls. Request payloads are plain value objects (`to_array()` → exact v2 JSON). Two mappers convert WooCommerce objects into those DTOs. `Miguel_API` is reduced to configuration/factory concerns; `Miguel_Request` is removed.

**Tech Stack:** PHP 7.2+ (no typed class properties / no constructor promotion), WordPress + WooCommerce, PHPUnit 9 via Docker (`make test-docker`), WP Coding Standards (tabs, Yoda conditions, `array()`).

**Spec:** `docs/superpowers/specs/2026-06-15-miguel-api-v2-migration-design.md`

---

## Conventions for every task

- **Indentation is tabs.** Use Yoda conditions (`null === $x`). Arrays use `array()`.
- Every new PHP file starts with:
  ```php
  <?php
  if ( ! defined( 'ABSPATH' ) ) {
  	exit; // Exit if accessed directly.
  }
  ```
- **Run all tests:** `make test-docker`
- **Run one class:** `docker compose -f docker-compose.test.yml run --rm phpunit --filter <ClassName>`
- New classes must be registered in `Miguel::includes()` (`includes/class-miguel.php`) **before** their test can pass. Each task that creates a class includes the exact edit.

### Include registration anchor

In `includes/class-miguel.php`, `includes()` currently contains this line (around line 76):

```php
		include_once dirname( MIGUEL_PLUGIN_FILE ) . '/includes/class-miguel-request.php';
```

Tasks insert their `include_once` lines immediately **after** `miguel-functions.php` (line 71) and before `class-miguel-api.php`, in this final order (build it up task by task):

```php
		include_once dirname( MIGUEL_PLUGIN_FILE ) . '/includes/api/v2/dto/class-miguel-v2-watermark-user.php';
		include_once dirname( MIGUEL_PLUGIN_FILE ) . '/includes/api/v2/dto/class-miguel-v2-order-address.php';
		include_once dirname( MIGUEL_PLUGIN_FILE ) . '/includes/api/v2/dto/class-miguel-v2-order-create-item.php';
		include_once dirname( MIGUEL_PLUGIN_FILE ) . '/includes/api/v2/dto/class-miguel-v2-order-create.php';
		include_once dirname( MIGUEL_PLUGIN_FILE ) . '/includes/api/v2/dto/class-miguel-v2-watermarked-file-request.php';
		include_once dirname( MIGUEL_PLUGIN_FILE ) . '/includes/api/v2/dto/class-miguel-v2-connect-request.php';
		include_once dirname( MIGUEL_PLUGIN_FILE ) . '/includes/api/v2/mappers/class-miguel-watermark-mapper.php';
		include_once dirname( MIGUEL_PLUGIN_FILE ) . '/includes/api/v2/mappers/class-miguel-order-mapper.php';
		include_once dirname( MIGUEL_PLUGIN_FILE ) . '/includes/api/v2/class-miguel-v2-client.php';
```

---

## File Structure

**Create:**
- `includes/api/v2/dto/class-miguel-v2-watermark-user.php` — `Miguel_V2_Watermark_User`
- `includes/api/v2/dto/class-miguel-v2-order-address.php` — `Miguel_V2_Order_Address`
- `includes/api/v2/dto/class-miguel-v2-order-create-item.php` — `Miguel_V2_Order_Create_Item`
- `includes/api/v2/dto/class-miguel-v2-order-create.php` — `Miguel_V2_Order_Create`
- `includes/api/v2/dto/class-miguel-v2-watermarked-file-request.php` — `Miguel_V2_Watermarked_File_Request`
- `includes/api/v2/dto/class-miguel-v2-connect-request.php` — `Miguel_V2_Connect_Request`
- `includes/api/v2/mappers/class-miguel-watermark-mapper.php` — `Miguel_Watermark_Mapper`
- `includes/api/v2/mappers/class-miguel-order-mapper.php` — `Miguel_Order_Mapper`
- `includes/api/v2/class-miguel-v2-client.php` — `Miguel_V2_Client`
- Tests: `tests/unit/test-v2-dtos.php`, `tests/unit/test-v2-client.php`, `tests/unit/test-watermark-mapper.php`, `tests/unit/test-order-mapper.php`

**Modify:**
- `includes/class-miguel.php` — register includes; add `v2_client` container service; rewire `download`/`orders`.
- `includes/class-miguel-download.php` — use client + watermark mapper.
- `includes/class-miguel-orders.php` — use client + order mapper.
- `includes/class-miguel-api.php` — remove instance transport methods.
- `includes/admin/class-miguel-settings.php` — use client + connect DTO.
- `tests/helpers/class-miguel-test-case.php` — update `create_service_with_mocks`.
- `tests/unit/test-orders.php` — update to v2 client/mapper expectations.
- `tests/unit/test-api.php` — drop transport tests, keep config tests.

**Delete:**
- `includes/class-miguel-request.php`
- `tests/unit/test-request.php`

---

## Task 1: `Miguel_V2_Watermark_User` DTO

**Files:**
- Create: `includes/api/v2/dto/class-miguel-v2-watermark-user.php`
- Modify: `includes/class-miguel.php` (register include)
- Test: `tests/unit/test-v2-dtos.php`

- [ ] **Step 1: Write the failing test**

Create `tests/unit/test-v2-dtos.php`:

```php
<?php
/**
 * Tests for v2 DTO value objects.
 *
 * @package Miguel\Tests
 */
class Miguel_Test_V2_Dtos extends WP_UnitTestCase {

	public function test_watermark_user_full(): void {
		$user = new Miguel_V2_Watermark_User( 'a@b.cz', 'cs_CZ', '42', 'John Doe', 'Main St 1, Prague' );

		$this->assertEquals(
			array(
				'id'       => '42',
				'name'     => 'John Doe',
				'address'  => 'Main St 1, Prague',
				'email'    => 'a@b.cz',
				'language' => 'cs_CZ',
			),
			$user->to_array()
		);
	}

	public function test_watermark_user_guest_has_null_optionals(): void {
		$user = new Miguel_V2_Watermark_User( 'guest@b.cz', 'en_US' );

		$arr = $user->to_array();
		$this->assertNull( $arr['id'] );
		$this->assertNull( $arr['name'] );
		$this->assertNull( $arr['address'] );
		$this->assertSame( 'guest@b.cz', $arr['email'] );
		$this->assertSame( 'en_US', $arr['language'] );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose -f docker-compose.test.yml run --rm phpunit --filter Miguel_Test_V2_Dtos`
Expected: FAIL — `Error: Class "Miguel_V2_Watermark_User" not found`.

- [ ] **Step 3: Create the DTO**

Create `includes/api/v2/dto/class-miguel-v2-watermark-user.php`:

```php
<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * v2 WatermarkUser value object.
 *
 * @package Miguel
 */
class Miguel_V2_Watermark_User {

	/** @var string|null */
	private $id;

	/** @var string|null */
	private $name;

	/** @var string|null */
	private $address;

	/** @var string */
	private $email;

	/** @var string */
	private $language;

	/**
	 * Constructor.
	 *
	 * @param string      $email    Required user email.
	 * @param string      $language Required user language.
	 * @param string|null $id       E-shop user id (null for guests).
	 * @param string|null $name     Full name.
	 * @param string|null $address  Address string.
	 */
	public function __construct( $email, $language, $id = null, $name = null, $address = null ) {
		$this->email    = (string) $email;
		$this->language = (string) $language;
		$this->id       = ( null === $id ) ? null : (string) $id;
		$this->name     = ( null === $name ) ? null : (string) $name;
		$this->address  = ( null === $address ) ? null : (string) $address;
	}

	/**
	 * Serialize to the v2 JSON shape.
	 *
	 * @return array
	 */
	public function to_array() {
		return array(
			'id'       => $this->id,
			'name'     => $this->name,
			'address'  => $this->address,
			'email'    => $this->email,
			'language' => $this->language,
		);
	}
}
```

Then in `includes/class-miguel.php`, immediately after the `miguel-functions.php` include line, add:

```php
		include_once dirname( MIGUEL_PLUGIN_FILE ) . '/includes/api/v2/dto/class-miguel-v2-watermark-user.php';
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose -f docker-compose.test.yml run --rm phpunit --filter Miguel_Test_V2_Dtos`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add includes/api/v2/dto/class-miguel-v2-watermark-user.php includes/class-miguel.php tests/unit/test-v2-dtos.php
git commit -m "feat: add Miguel_V2_Watermark_User DTO"
```

---

## Task 2: `Miguel_V2_Order_Address` DTO

**Files:**
- Create: `includes/api/v2/dto/class-miguel-v2-order-address.php`
- Modify: `includes/class-miguel.php`, `tests/unit/test-v2-dtos.php`

- [ ] **Step 1: Add the failing test**

Append to `tests/unit/test-v2-dtos.php` (inside the class):

```php
	public function test_order_address_maps_known_keys(): void {
		$address = new Miguel_V2_Order_Address(
			array(
				'fullName' => 'John Doe',
				'company'  => 'Acme',
				'address1' => 'Main St 1',
				'address2' => null,
				'city'     => 'Prague',
				'state'    => '',
				'zip'      => '11000',
				'country'  => 'CZ',
				'phone'    => '+420123',
			)
		);

		$this->assertEquals(
			array(
				'fullName' => 'John Doe',
				'company'  => 'Acme',
				'address1' => 'Main St 1',
				'address2' => null,
				'city'     => 'Prague',
				'state'    => null,
				'zip'      => '11000',
				'country'  => 'CZ',
				'phone'    => '+420123',
			),
			$address->to_array()
		);
	}
```

Note: empty strings are normalized to `null`.

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose -f docker-compose.test.yml run --rm phpunit --filter Miguel_Test_V2_Dtos`
Expected: FAIL — `Class "Miguel_V2_Order_Address" not found`.

- [ ] **Step 3: Create the DTO**

Create `includes/api/v2/dto/class-miguel-v2-order-address.php`:

```php
<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * v2 OrderAddressModel value object (all fields nullable).
 *
 * @package Miguel
 */
class Miguel_V2_Order_Address {

	const KEYS = array( 'fullName', 'company', 'address1', 'address2', 'city', 'state', 'zip', 'country', 'phone' );

	/** @var array */
	private $fields;

	/**
	 * Constructor.
	 *
	 * @param array $fields Associative array keyed by the v2 field names. Empty
	 *                      strings and missing keys normalize to null.
	 */
	public function __construct( array $fields = array() ) {
		$this->fields = array();
		foreach ( self::KEYS as $key ) {
			$value = isset( $fields[ $key ] ) ? $fields[ $key ] : null;
			if ( null === $value || '' === $value ) {
				$this->fields[ $key ] = null;
			} else {
				$this->fields[ $key ] = (string) $value;
			}
		}
	}

	/**
	 * Whether every field is null (used to omit empty addresses).
	 *
	 * @return bool
	 */
	public function is_empty() {
		foreach ( $this->fields as $value ) {
			if ( null !== $value ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Serialize to the v2 JSON shape.
	 *
	 * @return array
	 */
	public function to_array() {
		return $this->fields;
	}
}
```

Register the include in `includes/class-miguel.php` after the watermark-user line:

```php
		include_once dirname( MIGUEL_PLUGIN_FILE ) . '/includes/api/v2/dto/class-miguel-v2-order-address.php';
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose -f docker-compose.test.yml run --rm phpunit --filter Miguel_Test_V2_Dtos`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add includes/api/v2/dto/class-miguel-v2-order-address.php includes/class-miguel.php tests/unit/test-v2-dtos.php
git commit -m "feat: add Miguel_V2_Order_Address DTO"
```

---

## Task 3: `Miguel_V2_Order_Create_Item` DTO

**Files:**
- Create: `includes/api/v2/dto/class-miguel-v2-order-create-item.php`
- Modify: `includes/class-miguel.php`, `tests/unit/test-v2-dtos.php`

- [ ] **Step 1: Add the failing test**

Append to `tests/unit/test-v2-dtos.php`:

```php
	public function test_order_create_item_without_delivery_method(): void {
		$item = new Miguel_V2_Order_Create_Item( 'book-1', 10.0, 2 );

		$this->assertEquals(
			array(
				'code'      => 'book-1',
				'soldPrice' => 10.0,
				'quantity'  => 2,
			),
			$item->to_array()
		);
	}

	public function test_order_create_item_with_delivery_method(): void {
		$item = new Miguel_V2_Order_Create_Item( 'book-1', 9.5, 1, 7 );

		$arr = $item->to_array();
		$this->assertSame( 7, $arr['deliveryMethodId'] );
		$this->assertSame( 9.5, $arr['soldPrice'] );
		$this->assertSame( 1, $arr['quantity'] );
	}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose -f docker-compose.test.yml run --rm phpunit --filter Miguel_Test_V2_Dtos`
Expected: FAIL — `Class "Miguel_V2_Order_Create_Item" not found`.

- [ ] **Step 3: Create the DTO**

Create `includes/api/v2/dto/class-miguel-v2-order-create-item.php`:

```php
<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * v2 OrderCreateItem value object.
 *
 * @package Miguel
 */
class Miguel_V2_Order_Create_Item {

	/** @var string */
	private $code;

	/** @var float */
	private $sold_price;

	/** @var int */
	private $quantity;

	/** @var int|null */
	private $delivery_method_id;

	/**
	 * Constructor.
	 *
	 * @param string   $code               Product code.
	 * @param float    $sold_price         Per-unit price (excluding VAT).
	 * @param int      $quantity           Number of units.
	 * @param int|null $delivery_method_id Optional delivery method id.
	 */
	public function __construct( $code, $sold_price, $quantity, $delivery_method_id = null ) {
		$this->code               = (string) $code;
		$this->sold_price         = (float) $sold_price;
		$this->quantity           = (int) $quantity;
		$this->delivery_method_id = ( null === $delivery_method_id ) ? null : (int) $delivery_method_id;
	}

	/**
	 * Serialize to the v2 JSON shape.
	 *
	 * @return array
	 */
	public function to_array() {
		$arr = array(
			'code'      => $this->code,
			'soldPrice' => $this->sold_price,
			'quantity'  => $this->quantity,
		);

		if ( null !== $this->delivery_method_id ) {
			$arr['deliveryMethodId'] = $this->delivery_method_id;
		}

		return $arr;
	}
}
```

Register the include after the order-address line:

```php
		include_once dirname( MIGUEL_PLUGIN_FILE ) . '/includes/api/v2/dto/class-miguel-v2-order-create-item.php';
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose -f docker-compose.test.yml run --rm phpunit --filter Miguel_Test_V2_Dtos`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add includes/api/v2/dto/class-miguel-v2-order-create-item.php includes/class-miguel.php tests/unit/test-v2-dtos.php
git commit -m "feat: add Miguel_V2_Order_Create_Item DTO"
```

---

## Task 4: `Miguel_V2_Order_Create` DTO

**Files:**
- Create: `includes/api/v2/dto/class-miguel-v2-order-create.php`
- Modify: `includes/class-miguel.php`, `tests/unit/test-v2-dtos.php`

- [ ] **Step 1: Add the failing test**

Append to `tests/unit/test-v2-dtos.php`:

```php
	public function test_order_create_serializes_nested_and_omits_empty_addresses(): void {
		$user  = new Miguel_V2_Watermark_User( 'a@b.cz', 'cs_CZ', '42', 'John', 'Main St' );
		$items = array( new Miguel_V2_Order_Create_Item( 'book-1', 10.0, 1 ) );

		$order = new Miguel_V2_Order_Create(
			'1001',                       // code
			$user,
			'2023-01-15T10:00:00+00:00',  // purchasedAt
			'CZK',                        // currencyCode
			$items,
			'disable',                    // sendEmail
			'1001',                       // eshopId
			'2023-01-14T09:00:00+00:00',  // eshopCreatedAt
			'2023-01-15T10:00:00+00:00',  // eshopUpdatedAt
			null,                         // source
			null,                         // socialDrmContent
			null,                         // billingAddress
			null                          // shippingAddress
		);

		$arr = $order->to_array();

		$this->assertSame( '1001', $arr['code'] );
		$this->assertSame( 'disable', $arr['sendEmail'] );
		$this->assertSame( 'CZK', $arr['currencyCode'] );
		$this->assertNull( $arr['source'] );
		$this->assertNull( $arr['socialDrmContent'] );
		$this->assertSame( $user->to_array(), $arr['user'] );
		$this->assertSame( array( $items[0]->to_array() ), $arr['items'] );
		$this->assertArrayNotHasKey( 'billingAddress', $arr );
		$this->assertArrayNotHasKey( 'shippingAddress', $arr );
	}

	public function test_order_create_includes_non_empty_addresses(): void {
		$user    = new Miguel_V2_Watermark_User( 'a@b.cz', 'cs_CZ' );
		$billing = new Miguel_V2_Order_Address( array( 'city' => 'Prague' ) );

		$order = new Miguel_V2_Order_Create(
			'1', $user, null, 'CZK', array(), 'disable', '1', null, null, null, null, $billing, null
		);

		$arr = $order->to_array();
		$this->assertSame( $billing->to_array(), $arr['billingAddress'] );
		$this->assertArrayNotHasKey( 'shippingAddress', $arr );
	}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose -f docker-compose.test.yml run --rm phpunit --filter Miguel_Test_V2_Dtos`
Expected: FAIL — `Class "Miguel_V2_Order_Create" not found`.

- [ ] **Step 3: Create the DTO**

Create `includes/api/v2/dto/class-miguel-v2-order-create.php`:

```php
<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * v2 OrderCreate value object.
 *
 * @package Miguel
 */
class Miguel_V2_Order_Create {

	/** @var string */
	private $code;

	/** @var Miguel_V2_Watermark_User */
	private $user;

	/** @var string|null */
	private $purchased_at;

	/** @var string */
	private $currency_code;

	/** @var Miguel_V2_Order_Create_Item[] */
	private $items;

	/** @var string */
	private $send_email;

	/** @var string|null */
	private $eshop_id;

	/** @var string|null */
	private $eshop_created_at;

	/** @var string|null */
	private $eshop_updated_at;

	/** @var string|null */
	private $source;

	/** @var string|null */
	private $social_drm_content;

	/** @var Miguel_V2_Order_Address|null */
	private $billing_address;

	/** @var Miguel_V2_Order_Address|null */
	private $shipping_address;

	/**
	 * Constructor.
	 *
	 * @param string                       $code               Order code.
	 * @param Miguel_V2_Watermark_User     $user               Order user.
	 * @param string|null                  $purchased_at       ISO-8601 or null.
	 * @param string                       $currency_code      Currency code.
	 * @param Miguel_V2_Order_Create_Item[] $items             Order items.
	 * @param string                       $send_email         "auto" or "disable".
	 * @param string|null                  $eshop_id           E-shop order id.
	 * @param string|null                  $eshop_created_at   ISO-8601 or null.
	 * @param string|null                  $eshop_updated_at   ISO-8601 or null.
	 * @param string|null                  $source             Optional source.
	 * @param string|null                  $social_drm_content Optional DRM content.
	 * @param Miguel_V2_Order_Address|null $billing_address    Optional billing.
	 * @param Miguel_V2_Order_Address|null $shipping_address   Optional shipping.
	 */
	public function __construct(
		$code,
		Miguel_V2_Watermark_User $user,
		$purchased_at,
		$currency_code,
		array $items,
		$send_email,
		$eshop_id,
		$eshop_created_at,
		$eshop_updated_at,
		$source = null,
		$social_drm_content = null,
		Miguel_V2_Order_Address $billing_address = null,
		Miguel_V2_Order_Address $shipping_address = null
	) {
		$this->code               = (string) $code;
		$this->user               = $user;
		$this->purchased_at       = ( null === $purchased_at ) ? null : (string) $purchased_at;
		$this->currency_code      = (string) $currency_code;
		$this->items              = $items;
		$this->send_email         = (string) $send_email;
		$this->eshop_id           = ( null === $eshop_id ) ? null : (string) $eshop_id;
		$this->eshop_created_at   = ( null === $eshop_created_at ) ? null : (string) $eshop_created_at;
		$this->eshop_updated_at   = ( null === $eshop_updated_at ) ? null : (string) $eshop_updated_at;
		$this->source             = ( null === $source ) ? null : (string) $source;
		$this->social_drm_content = ( null === $social_drm_content ) ? null : (string) $social_drm_content;
		$this->billing_address    = $billing_address;
		$this->shipping_address   = $shipping_address;
	}

	/**
	 * Serialize to the v2 JSON shape.
	 *
	 * @return array
	 */
	public function to_array() {
		$items = array();
		foreach ( $this->items as $item ) {
			$items[] = $item->to_array();
		}

		$arr = array(
			'code'            => $this->code,
			'user'            => $this->user->to_array(),
			'purchasedAt'     => $this->purchased_at,
			'currencyCode'    => $this->currency_code,
			'items'           => $items,
			'sendEmail'       => $this->send_email,
			'eshopId'         => $this->eshop_id,
			'eshopCreatedAt'  => $this->eshop_created_at,
			'eshopUpdatedAt'  => $this->eshop_updated_at,
			'source'          => $this->source,
			'socialDrmContent' => $this->social_drm_content,
		);

		if ( $this->billing_address instanceof Miguel_V2_Order_Address && ! $this->billing_address->is_empty() ) {
			$arr['billingAddress'] = $this->billing_address->to_array();
		}

		if ( $this->shipping_address instanceof Miguel_V2_Order_Address && ! $this->shipping_address->is_empty() ) {
			$arr['shippingAddress'] = $this->shipping_address->to_array();
		}

		return $arr;
	}
}
```

Register the include after the order-create-item line:

```php
		include_once dirname( MIGUEL_PLUGIN_FILE ) . '/includes/api/v2/dto/class-miguel-v2-order-create.php';
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose -f docker-compose.test.yml run --rm phpunit --filter Miguel_Test_V2_Dtos`
Expected: PASS (7 tests).

- [ ] **Step 5: Commit**

```bash
git add includes/api/v2/dto/class-miguel-v2-order-create.php includes/class-miguel.php tests/unit/test-v2-dtos.php
git commit -m "feat: add Miguel_V2_Order_Create DTO"
```

---

## Task 5: `Miguel_V2_Watermarked_File_Request` DTO

**Files:**
- Create: `includes/api/v2/dto/class-miguel-v2-watermarked-file-request.php`
- Modify: `includes/class-miguel.php`, `tests/unit/test-v2-dtos.php`

- [ ] **Step 1: Add the failing test**

Append to `tests/unit/test-v2-dtos.php`:

```php
	public function test_watermarked_file_request(): void {
		$user = new Miguel_V2_Watermark_User( 'a@b.cz', 'cs_CZ', '42', 'John', 'Main St' );

		$req = new Miguel_V2_Watermarked_File_Request(
			'epub',
			$user,
			'2023-01-15T10:00:00+00:00',
			'1001',
			'CZK',
			10.0
		);

		$this->assertEquals(
			array(
				'target'      => 'epub',
				'userInfo'    => $user->to_array(),
				'purchaseDate' => '2023-01-15T10:00:00+00:00',
				'orderInfo'   => array(
					'code'         => '1001',
					'currencyCode' => 'CZK',
					'soldPrice'    => 10.0,
				),
			),
			$req->to_array()
		);
	}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose -f docker-compose.test.yml run --rm phpunit --filter Miguel_Test_V2_Dtos`
Expected: FAIL — `Class "Miguel_V2_Watermarked_File_Request" not found`.

- [ ] **Step 3: Create the DTO**

Create `includes/api/v2/dto/class-miguel-v2-watermarked-file-request.php`:

```php
<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * v2 GetWatermarkedFileFromVariantRequest value object.
 *
 * @package Miguel
 */
class Miguel_V2_Watermarked_File_Request {

	/** @var string */
	private $target;

	/** @var Miguel_V2_Watermark_User */
	private $user_info;

	/** @var string */
	private $purchase_date;

	/** @var string */
	private $order_code;

	/** @var string */
	private $currency_code;

	/** @var float */
	private $sold_price;

	/**
	 * Constructor.
	 *
	 * @param string                   $target        Target FileFormat (epub, mobi, pdf, audio).
	 * @param Miguel_V2_Watermark_User $user_info     User info.
	 * @param string                   $purchase_date ISO-8601 purchase date.
	 * @param string                   $order_code    Order code for orderInfo.
	 * @param string                   $currency_code Currency code for orderInfo.
	 * @param float                    $sold_price    Per-unit price (excluding VAT).
	 */
	public function __construct( $target, Miguel_V2_Watermark_User $user_info, $purchase_date, $order_code, $currency_code, $sold_price ) {
		$this->target        = (string) $target;
		$this->user_info     = $user_info;
		$this->purchase_date = (string) $purchase_date;
		$this->order_code    = (string) $order_code;
		$this->currency_code = (string) $currency_code;
		$this->sold_price    = (float) $sold_price;
	}

	/**
	 * Target file format.
	 *
	 * @return string
	 */
	public function get_target() {
		return $this->target;
	}

	/**
	 * Serialize to the v2 JSON shape.
	 *
	 * @return array
	 */
	public function to_array() {
		return array(
			'target'       => $this->target,
			'userInfo'     => $this->user_info->to_array(),
			'purchaseDate' => $this->purchase_date,
			'orderInfo'    => array(
				'code'         => $this->order_code,
				'currencyCode' => $this->currency_code,
				'soldPrice'    => $this->sold_price,
			),
		);
	}
}
```

Register the include after the order-create line:

```php
		include_once dirname( MIGUEL_PLUGIN_FILE ) . '/includes/api/v2/dto/class-miguel-v2-watermarked-file-request.php';
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose -f docker-compose.test.yml run --rm phpunit --filter Miguel_Test_V2_Dtos`
Expected: PASS (8 tests).

- [ ] **Step 5: Commit**

```bash
git add includes/api/v2/dto/class-miguel-v2-watermarked-file-request.php includes/class-miguel.php tests/unit/test-v2-dtos.php
git commit -m "feat: add Miguel_V2_Watermarked_File_Request DTO"
```

---

## Task 6: `Miguel_V2_Connect_Request` DTO

**Files:**
- Create: `includes/api/v2/dto/class-miguel-v2-connect-request.php`
- Modify: `includes/class-miguel.php`, `tests/unit/test-v2-dtos.php`

- [ ] **Step 1: Add the failing test**

Append to `tests/unit/test-v2-dtos.php`:

```php
	public function test_connect_request(): void {
		$req = new Miguel_V2_Connect_Request( '8.0.0', '1.6.3', 'https://shop.cz/', '/' );

		$this->assertEquals(
			array(
				'wcVersion'     => '8.0.0',
				'moduleVersion' => '1.6.3',
				'baseUrl'       => 'https://shop.cz/',
				'baseUri'       => '/',
			),
			$req->to_array()
		);
	}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose -f docker-compose.test.yml run --rm phpunit --filter Miguel_Test_V2_Dtos`
Expected: FAIL — `Class "Miguel_V2_Connect_Request" not found`.

- [ ] **Step 3: Create the DTO**

Create `includes/api/v2/dto/class-miguel-v2-connect-request.php`:

```php
<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * v2 WooCommerce ConnectRequest value object.
 *
 * @package Miguel
 */
class Miguel_V2_Connect_Request {

	/** @var string */
	private $wc_version;

	/** @var string */
	private $module_version;

	/** @var string */
	private $base_url;

	/** @var string */
	private $base_uri;

	/**
	 * Constructor.
	 *
	 * @param string $wc_version     WooCommerce version.
	 * @param string $module_version Plugin version.
	 * @param string $base_url       Absolute shop base URL.
	 * @param string $base_uri       Canonical base URI path.
	 */
	public function __construct( $wc_version, $module_version, $base_url, $base_uri ) {
		$this->wc_version     = (string) $wc_version;
		$this->module_version = (string) $module_version;
		$this->base_url       = (string) $base_url;
		$this->base_uri       = (string) $base_uri;
	}

	/**
	 * Serialize to the v2 JSON shape.
	 *
	 * @return array
	 */
	public function to_array() {
		return array(
			'wcVersion'     => $this->wc_version,
			'moduleVersion' => $this->module_version,
			'baseUrl'       => $this->base_url,
			'baseUri'       => $this->base_uri,
		);
	}
}
```

Register the include after the watermarked-file-request line:

```php
		include_once dirname( MIGUEL_PLUGIN_FILE ) . '/includes/api/v2/dto/class-miguel-v2-connect-request.php';
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose -f docker-compose.test.yml run --rm phpunit --filter Miguel_Test_V2_Dtos`
Expected: PASS (9 tests).

- [ ] **Step 5: Commit**

```bash
git add includes/api/v2/dto/class-miguel-v2-connect-request.php includes/class-miguel.php tests/unit/test-v2-dtos.php
git commit -m "feat: add Miguel_V2_Connect_Request DTO"
```

---

## Task 7: `Miguel_Watermark_Mapper`

Builds a `Miguel_V2_Watermarked_File_Request` from a WooCommerce order, item, and `Miguel_File`. Replaces `Miguel_Request`. Returns `null` when the order has no paid date (preserving `Miguel_Request::is_valid()`).

**Files:**
- Create: `includes/api/v2/mappers/class-miguel-watermark-mapper.php`
- Modify: `includes/class-miguel.php`
- Test: `tests/unit/test-watermark-mapper.php`

- [ ] **Step 1: Write the failing test**

Create `tests/unit/test-watermark-mapper.php`:

```php
<?php
/**
 * Tests for Miguel_Watermark_Mapper.
 *
 * @package Miguel\Tests
 */
class Miguel_Test_Watermark_Mapper extends Miguel_Test_Case {

	public function test_maps_order_item_and_file(): void {
		$order = Miguel_Helper_Order::create_order();
		$item  = array_values( $order->get_items() )[0];

		$file = $this->getMockBuilder( Miguel_File::class )
			->disableOriginalConstructor()
			->getMock();
		$file->method( 'get_format' )->willReturn( 'epub' );

		$mapper = new Miguel_Watermark_Mapper();
		$req    = $mapper->map( $order, $item, $file );

		$this->assertInstanceOf( Miguel_V2_Watermarked_File_Request::class, $req );

		$arr = $req->to_array();
		$this->assertSame( 'epub', $arr['target'] );
		$this->assertSame( strval( $order->get_id() ), $arr['orderInfo']['code'] );
		$this->assertSame( $order->get_currency(), $arr['orderInfo']['currencyCode'] );
		$this->assertSame( 10.0, $arr['orderInfo']['soldPrice'] );
		$this->assertSame( $order->get_billing_email(), $arr['userInfo']['email'] );
		$this->assertSame( $order->get_date_paid()->format( DateTime::ATOM ), $arr['purchaseDate'] );

		Miguel_Helper_Order::delete_order( $order->get_id() );
	}

	public function test_returns_null_when_not_paid(): void {
		$order = Miguel_Helper_Order::create_order();
		$order->set_date_paid( null );
		$order->save();
		$item = array_values( $order->get_items() )[0];

		$file = $this->getMockBuilder( Miguel_File::class )
			->disableOriginalConstructor()
			->getMock();
		$file->method( 'get_format' )->willReturn( 'epub' );

		$mapper = new Miguel_Watermark_Mapper();
		$this->assertNull( $mapper->map( $order, $item, $file ) );

		Miguel_Helper_Order::delete_order( $order->get_id() );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose -f docker-compose.test.yml run --rm phpunit --filter Miguel_Test_Watermark_Mapper`
Expected: FAIL — `Class "Miguel_Watermark_Mapper" not found`.

- [ ] **Step 3: Create the mapper**

Create `includes/api/v2/mappers/class-miguel-watermark-mapper.php`:

```php
<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Maps a WooCommerce order/item/file to a v2 watermarked-file request.
 *
 * @package Miguel
 */
class Miguel_Watermark_Mapper {

	/**
	 * Build the request DTO.
	 *
	 * @param WC_Order              $order Order object.
	 * @param WC_Order_Item_Product $item  Order item.
	 * @param Miguel_File           $file  File entity (provides target format).
	 * @return Miguel_V2_Watermarked_File_Request|null Null when the order is not paid.
	 */
	public function map( $order, $item, $file ) {
		$paid_date = $order->get_date_paid();
		if ( ! $paid_date ) {
			return null;
		}

		$user_data = Miguel_Order_Utils::get_user_data_for_order( $order );

		$user = new Miguel_V2_Watermark_User(
			$user_data['email'],
			$user_data['lang'],
			$user_data['id'],
			$user_data['full_name'],
			$user_data['address']
		);

		return new Miguel_V2_Watermarked_File_Request(
			$file->get_format(),
			$user,
			$paid_date->format( DateTime::ATOM ),
			strval( $order->get_id() ),
			$order->get_currency(),
			$order->get_item_total( $item, false, false )
		);
	}
}
```

Register the include after the connect-request DTO line:

```php
		include_once dirname( MIGUEL_PLUGIN_FILE ) . '/includes/api/v2/mappers/class-miguel-watermark-mapper.php';
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose -f docker-compose.test.yml run --rm phpunit --filter Miguel_Test_Watermark_Mapper`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add includes/api/v2/mappers/class-miguel-watermark-mapper.php includes/class-miguel.php tests/unit/test-watermark-mapper.php
git commit -m "feat: add Miguel_Watermark_Mapper"
```

---

## Task 8: `Miguel_Order_Mapper`

Builds `Miguel_V2_Order_Create` from a `WC_Order`. Absorbs the Miguel-code extraction and bundle price-proportioning logic currently in `Miguel_Orders`, now also producing `quantity`, plus billing/shipping addresses. Returns `null` when the order has no Miguel items.

**Files:**
- Create: `includes/api/v2/mappers/class-miguel-order-mapper.php`
- Modify: `includes/class-miguel.php`
- Test: `tests/unit/test-order-mapper.php`

- [ ] **Step 1: Write the failing test**

Create `tests/unit/test-order-mapper.php`:

```php
<?php
/**
 * Tests for Miguel_Order_Mapper.
 *
 * @package Miguel\Tests
 */
class Miguel_Test_Order_Mapper extends Miguel_Test_Case {

	public function test_maps_single_product_with_quantity_and_address(): void {
		$product = Miguel_Helper_Product::create_downloadable_product();

		$order = Miguel_Helper_Order::create_order();
		$order->add_product( $product, 2 );
		$order->set_status( 'processing' );
		$order->set_date_paid( '2023-01-15 10:00:00' );
		$order->save();

		$mapper = new Miguel_Order_Mapper();
		$dto    = $mapper->map( $order );

		$this->assertInstanceOf( Miguel_V2_Order_Create::class, $dto );

		$arr = $dto->to_array();
		$this->assertSame( strval( $order->get_id() ), $arr['code'] );
		$this->assertSame( strval( $order->get_id() ), $arr['eshopId'] );
		$this->assertSame( 'disable', $arr['sendEmail'] );
		$this->assertSame( $order->get_currency(), $arr['currencyCode'] );
		$this->assertNull( $arr['source'] );
		$this->assertNull( $arr['socialDrmContent'] );

		$this->assertCount( 1, $arr['items'] );
		$this->assertSame( 'dummy-name', $arr['items'][0]['code'] );
		$this->assertSame( 10.0, $arr['items'][0]['soldPrice'] );
		$this->assertSame( 2, $arr['items'][0]['quantity'] );

		// Guest order => null user id.
		$this->assertNull( $arr['user']['id'] );
		$this->assertSame( $order->get_billing_email(), $arr['user']['email'] );

		// Billing address present (helper sets billing fields).
		$this->assertArrayHasKey( 'billingAddress', $arr );

		Miguel_Helper_Order::delete_order( $order->get_id() );
	}

	public function test_returns_null_without_miguel_items(): void {
		$product = Miguel_Helper_Product::create_simple_product();

		$order = Miguel_Helper_Order::create_order();
		$order->add_product( $product, 1 );
		$order->set_status( 'processing' );
		$order->set_date_paid( '2023-01-15 10:00:00' );
		$order->save();

		$mapper = new Miguel_Order_Mapper();
		$this->assertNull( $mapper->map( $order ) );

		Miguel_Helper_Order::delete_order( $order->get_id() );
	}
}
```

> Note: confirm `Miguel_Helper_Product::create_simple_product()` exists; if the helper only exposes `create_downloadable_product()`, create a non-Miguel product inline (a product whose downloads contain no `[miguel]`/`[wosa]`/`[audio]` shortcode) instead.

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose -f docker-compose.test.yml run --rm phpunit --filter Miguel_Test_Order_Mapper`
Expected: FAIL — `Class "Miguel_Order_Mapper" not found`.

- [ ] **Step 3: Create the mapper**

Create `includes/api/v2/mappers/class-miguel-order-mapper.php`:

```php
<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Maps a WooCommerce order to a v2 OrderCreate DTO.
 *
 * @package Miguel
 */
class Miguel_Order_Mapper {

	/**
	 * Build the OrderCreate DTO.
	 *
	 * @param WC_Order $order Order object.
	 * @return Miguel_V2_Order_Create|null Null when there are no Miguel items.
	 */
	public function map( $order ) {
		$items = array();

		foreach ( $order->get_items() as $item ) {
			if ( ! ( $item instanceof WC_Order_Item_Product ) ) {
				continue;
			}

			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}

			$item_total = $order->get_item_total( $item, false, false );
			$quantity   = (int) $item->get_quantity();

			foreach ( $this->get_miguel_products_from_item( $product, $item_total ) as $miguel_product ) {
				$items[] = new Miguel_V2_Order_Create_Item(
					$miguel_product['code'],
					$miguel_product['sold_price'],
					$quantity
				);
			}
		}

		if ( empty( $items ) ) {
			return null;
		}

		$user_data = Miguel_Order_Utils::get_user_data_for_order( $order, true );
		$user      = new Miguel_V2_Watermark_User(
			$user_data['email'],
			$user_data['lang'],
			$user_data['id'],
			$user_data['full_name'],
			$user_data['address']
		);

		return new Miguel_V2_Order_Create(
			strval( $order->get_id() ),
			$user,
			Miguel_Order_Utils::get_purchase_date_for_order( $order ),
			$order->get_currency(),
			$items,
			'disable',
			strval( $order->get_id() ),
			$this->format_date( $order->get_date_created() ),
			$this->format_date( $order->get_date_modified() ),
			null,
			null,
			$this->build_billing_address( $order ),
			$this->build_shipping_address( $order )
		);
	}

	/**
	 * Format a WC date as ISO-8601, or null.
	 *
	 * @param WC_DateTime|null $date Date object.
	 * @return string|null
	 */
	private function format_date( $date ) {
		return $date ? $date->format( DateTime::ATOM ) : null;
	}

	/**
	 * Build billing address DTO from the order.
	 *
	 * @param WC_Order $order Order object.
	 * @return Miguel_V2_Order_Address
	 */
	private function build_billing_address( $order ) {
		return new Miguel_V2_Order_Address(
			array(
				'fullName' => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'company'  => $order->get_billing_company(),
				'address1' => $order->get_billing_address_1(),
				'address2' => $order->get_billing_address_2(),
				'city'     => $order->get_billing_city(),
				'state'    => $order->get_billing_state(),
				'zip'      => $order->get_billing_postcode(),
				'country'  => $order->get_billing_country(),
				'phone'    => $order->get_billing_phone(),
			)
		);
	}

	/**
	 * Build shipping address DTO from the order.
	 *
	 * @param WC_Order $order Order object.
	 * @return Miguel_V2_Order_Address
	 */
	private function build_shipping_address( $order ) {
		// get_shipping_phone() was added in WooCommerce 5.6; guard for older versions.
		$phone = method_exists( $order, 'get_shipping_phone' ) ? $order->get_shipping_phone() : null;

		return new Miguel_V2_Order_Address(
			array(
				'fullName' => trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() ),
				'company'  => $order->get_shipping_company(),
				'address1' => $order->get_shipping_address_1(),
				'address2' => $order->get_shipping_address_2(),
				'city'     => $order->get_shipping_city(),
				'state'    => $order->get_shipping_state(),
				'zip'      => $order->get_shipping_postcode(),
				'country'  => $order->get_shipping_country(),
				'phone'    => $phone,
			)
		);
	}

	/**
	 * Get Miguel products (code + per-unit price) from an order item's product.
	 *
	 * @param WC_Product $product    Product object.
	 * @param float      $item_total Per-unit price of the item (excluding VAT).
	 * @return array Array of array{code:string, sold_price:float}.
	 */
	private function get_miguel_products_from_item( $product, $item_total ) {
		$products = array();

		$bundle_ids = $product->get_meta( '_bundle_ids', true );
		if ( ! empty( $bundle_ids ) ) {
			$products = $this->get_miguel_products_from_bundle( $bundle_ids, $item_total );
		}

		if ( $product->is_downloadable() ) {
			foreach ( $this->extract_miguel_codes_from_product( $product ) as $code ) {
				$products[] = array(
					'code'       => $code,
					'sold_price' => $item_total,
				);
			}
		}

		return $products;
	}

	/**
	 * Get Miguel products from a bundle with proportional per-unit prices.
	 *
	 * @param array $bundle_ids   Bundled product IDs (array keys).
	 * @param float $bundle_total Per-unit price of the bundle (excluding VAT).
	 * @return array Array of array{code:string, sold_price:float}.
	 */
	private function get_miguel_products_from_bundle( $bundle_ids, $bundle_total ) {
		$products            = array();
		$bundled_items       = array();
		$total_regular_price = 0;

		foreach ( array_keys( $bundle_ids ) as $bundle_product_id ) {
			$bundled_product = wc_get_product( $bundle_product_id );
			if ( ! $bundled_product ) {
				continue;
			}

			$nested_codes = $this->extract_all_miguel_codes( $bundled_product );
			if ( empty( $nested_codes ) ) {
				continue;
			}

			$regular_price = floatval( $bundled_product->get_regular_price() );
			if ( $regular_price <= 0 ) {
				$regular_price = floatval( $bundled_product->get_price() );
			}

			$bundled_items[]      = array(
				'codes'         => $nested_codes,
				'regular_price' => $regular_price,
			);
			$total_regular_price += $regular_price;
		}

		foreach ( $bundled_items as $bundled_item ) {
			if ( $total_regular_price > 0 ) {
				$price_ratio      = $bundled_item['regular_price'] / $total_regular_price;
				$calculated_price = $bundle_total * $price_ratio;
			} else {
				$calculated_price = $bundle_total / count( $bundled_items );
			}

			foreach ( $bundled_item['codes'] as $code ) {
				$products[] = array(
					'code'       => $code,
					'sold_price' => round( $calculated_price, 2 ),
				);
			}
		}

		return $products;
	}

	/**
	 * Extract all Miguel codes from a product, including nested bundles.
	 *
	 * @param WC_Product $product Product object.
	 * @return array Unique Miguel codes.
	 */
	private function extract_all_miguel_codes( $product ) {
		$miguel_codes = array();

		$bundle_ids = $product->get_meta( '_bundle_ids', true );
		if ( ! empty( $bundle_ids ) ) {
			foreach ( array_keys( $bundle_ids ) as $bundle_product_id ) {
				$bundled_product = wc_get_product( $bundle_product_id );
				if ( $bundled_product ) {
					foreach ( $this->extract_all_miguel_codes( $bundled_product ) as $code ) {
						if ( ! in_array( $code, $miguel_codes, true ) ) {
							$miguel_codes[] = $code;
						}
					}
				}
			}
		}

		if ( $product->is_downloadable() ) {
			foreach ( $this->extract_miguel_codes_from_product( $product ) as $code ) {
				if ( ! in_array( $code, $miguel_codes, true ) ) {
					$miguel_codes[] = $code;
				}
			}
		}

		return $miguel_codes;
	}

	/**
	 * Extract Miguel codes from a product's downloadable files.
	 *
	 * @param WC_Product $product Product object.
	 * @return array Unique Miguel codes.
	 */
	private function extract_miguel_codes_from_product( $product ) {
		$miguel_codes = array();

		foreach ( $product->get_downloads() as $download ) {
			if ( Miguel_Order_Utils::is_miguel_shortcode( $download['file'] ) ) {
				$code = Miguel_Order_Utils::extract_miguel_code( $download['file'] );
				if ( $code && ! in_array( $code, $miguel_codes, true ) ) {
					$miguel_codes[] = $code;
				}
			}
		}

		return $miguel_codes;
	}
}
```

Register the include after the watermark-mapper line:

```php
		include_once dirname( MIGUEL_PLUGIN_FILE ) . '/includes/api/v2/mappers/class-miguel-order-mapper.php';
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose -f docker-compose.test.yml run --rm phpunit --filter Miguel_Test_Order_Mapper`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add includes/api/v2/mappers/class-miguel-order-mapper.php includes/class-miguel.php tests/unit/test-order-mapper.php
git commit -m "feat: add Miguel_Order_Mapper"
```

---

## Task 9: `Miguel_V2_Client`

Typed transport + the 4 v2 endpoints.

**Files:**
- Create: `includes/api/v2/class-miguel-v2-client.php`
- Modify: `includes/class-miguel.php`
- Test: `tests/unit/test-v2-client.php`

- [ ] **Step 1: Write the failing test**

Create `tests/unit/test-v2-client.php`:

```php
<?php
/**
 * Tests for Miguel_V2_Client.
 *
 * @package Miguel\Tests
 */
class Miguel_Test_V2_Client extends WP_UnitTestCase {

	private $token = 'tok123';
	private $sut;

	public function setUp(): void {
		parent::setUp();
		$this->sut = new Miguel_V2_Client( 'https://miguel.servantes.cz', $this->token );
	}

	public function tearDown(): void {
		Miguel_Helper_HTTP::clear();
		parent::tearDown();
	}

	private function watermark_request() {
		$user = new Miguel_V2_Watermark_User( 'a@b.cz', 'cs_CZ' );
		return new Miguel_V2_Watermarked_File_Request( 'epub', $user, '2023-01-15T10:00:00+00:00', '1', 'CZK', 10.0 );
	}

	public function test_get_watermarked_file_url_body_headers(): void {
		Miguel_Helper_HTTP::mock_api_responses(
			array(
				'POST' => array(
					'body'     => wp_json_encode( array( 'downloadUrl' => 'https://dl/x', 'downloadExpiresAt' => '2023-01-16T00:00:00+00:00' ) ),
					'response' => array( 'code' => 200 ),
				),
			)
		);

		$result = $this->sut->get_watermarked_file( 'book-1', $this->watermark_request() );

		$this->assertSame( 'https://dl/x', $result['downloadUrl'] );

		$req = Miguel_Helper_HTTP::get_last_request();
		$this->assertSame( 'https://miguel.servantes.cz/v2/product-variants/book-1/watermarked-file', $req['url'] );
		$this->assertSame( 'POST', $req['method'] );
		$this->assertSame( 'Bearer ' . $this->token, $req['headers']['Authorization'] );

		$body = json_decode( $req['body'], true );
		$this->assertSame( 'epub', $body['target'] );
	}

	public function test_get_watermarked_file_rejects_disallowed_format(): void {
		$user = new Miguel_V2_Watermark_User( 'a@b.cz', 'cs_CZ' );
		$req  = new Miguel_V2_Watermarked_File_Request( 'doc', $user, '2023-01-15T10:00:00+00:00', '1', 'CZK', 10.0 );

		$result = $this->sut->get_watermarked_file( 'book-1', $req );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( __( 'Format is not allowed.', 'miguel' ), $result->get_error_message() );
	}

	public function test_create_order_accepts_201(): void {
		Miguel_Helper_HTTP::mock_api_responses(
			array( 'POST' => array( 'body' => '{}', 'response' => array( 'code' => 201 ) ) )
		);

		$user  = new Miguel_V2_Watermark_User( 'a@b.cz', 'cs_CZ' );
		$order = new Miguel_V2_Order_Create( '1', $user, null, 'CZK', array(), 'disable', '1', null, null );

		$this->assertTrue( $this->sut->create_order( $order ) );

		$req = Miguel_Helper_HTTP::get_last_request();
		$this->assertSame( 'https://miguel.servantes.cz/v2/orders', $req['url'] );
	}

	public function test_create_order_parses_problem_on_409(): void {
		Miguel_Helper_HTTP::mock_api_responses(
			array(
				'POST' => array(
					'body'     => wp_json_encode( array( 'status' => 409, 'title' => 'Conflict', 'detail' => 'Duplicate' ) ),
					'response' => array( 'code' => 409 ),
				),
			)
		);

		$user  = new Miguel_V2_Watermark_User( 'a@b.cz', 'cs_CZ' );
		$order = new Miguel_V2_Order_Create( '1', $user, null, 'CZK', array(), 'disable', '1', null, null );

		$result = $this->sut->create_order( $order );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertStringContainsString( 'Conflict', $result->get_error_message() );
	}

	public function test_delete_order_url_and_204(): void {
		Miguel_Helper_HTTP::mock_api_responses(
			array( 'DELETE' => array( 'body' => '', 'response' => array( 'code' => 204 ) ) )
		);

		$this->assertTrue( $this->sut->delete_order( '123' ) );

		$req = Miguel_Helper_HTTP::get_last_request();
		$this->assertSame( 'https://miguel.servantes.cz/v2/orders/123', $req['url'] );
		$this->assertSame( 'DELETE', $req['method'] );
	}

	public function test_delete_order_treats_404_as_success(): void {
		Miguel_Helper_HTTP::mock_api_responses(
			array( 'DELETE' => array( 'body' => '', 'response' => array( 'code' => 404 ) ) )
		);

		$this->assertTrue( $this->sut->delete_order( '123' ) );
	}

	public function test_connect_posts_to_woocommerce_endpoint(): void {
		Miguel_Helper_HTTP::mock_api_responses(
			array( 'POST' => array( 'body' => '{}', 'response' => array( 'code' => 200 ) ) )
		);

		$req_dto = new Miguel_V2_Connect_Request( '8.0.0', '1.6.3', 'https://shop.cz/', '/' );
		$this->assertTrue( $this->sut->connect( $req_dto ) );

		$req = Miguel_Helper_HTTP::get_last_request();
		$this->assertSame( 'https://miguel.servantes.cz/v2/eshop/woocommerce/connect', $req['url'] );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose -f docker-compose.test.yml run --rm phpunit --filter Miguel_Test_V2_Client`
Expected: FAIL — `Class "Miguel_V2_Client" not found`.

- [ ] **Step 3: Create the client**

Create `includes/api/v2/class-miguel-v2-client.php`:

```php
<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Typed Miguel v2 API client (outbound).
 *
 * @package Miguel
 */
class Miguel_V2_Client {

	/**
	 * Formats the plugin is allowed to request.
	 */
	const ALLOWED_FORMATS = array( 'epub', 'mobi', 'pdf', 'audio' );

	/** @var string */
	private $url;

	/** @var string */
	private $token;

	/**
	 * Constructor.
	 *
	 * @param string $url   API base URL.
	 * @param string $token Bearer token.
	 */
	public function __construct( $url, $token ) {
		$this->url   = untrailingslashit( (string) $url );
		$this->token = (string) $token;
	}

	/**
	 * Request a watermarked file for a product variant.
	 *
	 * @param string                              $variant_code Product variant code.
	 * @param Miguel_V2_Watermarked_File_Request  $request      Request DTO.
	 * @return array|WP_Error Decoded body ({ downloadUrl, downloadExpiresAt, task }) or error.
	 */
	public function get_watermarked_file( $variant_code, Miguel_V2_Watermarked_File_Request $request ) {
		if ( ! in_array( $request->get_target(), self::ALLOWED_FORMATS, true ) ) {
			return new WP_Error( 'miguel', __( 'Format is not allowed.', 'miguel' ) );
		}

		$response = $this->send( 'POST', 'v2/product-variants/' . rawurlencode( $variant_code ) . '/watermarked-file', $request->to_array() );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 === $code ) {
			$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $decoded ) ) {
				return new WP_Error( 'miguel', __( 'Something went wrong.', 'miguel' ) );
			}
			return $decoded;
		}

		return $this->problem_to_wp_error( $response );
	}

	/**
	 * Create (sync) an order.
	 *
	 * @param Miguel_V2_Order_Create $order Order DTO.
	 * @return true|WP_Error
	 */
	public function create_order( Miguel_V2_Order_Create $order ) {
		$response = $this->send( 'POST', 'v2/orders', $order->to_array() );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 === $code || 201 === $code ) {
			return true;
		}

		return $this->problem_to_wp_error( $response );
	}

	/**
	 * Delete an order (idempotent — 404 treated as success).
	 *
	 * @param string $code Order code.
	 * @return true|WP_Error
	 */
	public function delete_order( $code ) {
		$response = $this->send( 'DELETE', 'v2/orders/' . rawurlencode( (string) $code ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 204 === $status || 404 === $status ) {
			return true;
		}

		return $this->problem_to_wp_error( $response );
	}

	/**
	 * Connect the WooCommerce shop to Miguel.
	 *
	 * @param Miguel_V2_Connect_Request $request Connect DTO.
	 * @return true|WP_Error
	 */
	public function connect( Miguel_V2_Connect_Request $request ) {
		$response = $this->send( 'POST', 'v2/eshop/woocommerce/connect', $request->to_array(), 20 );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 === $status ) {
			return true;
		}

		return $this->problem_to_wp_error( $response );
	}

	/**
	 * Send an HTTP request.
	 *
	 * @param string     $method  HTTP method.
	 * @param string     $path    Path relative to the base URL.
	 * @param array|null $body    Optional JSON body.
	 * @param int        $timeout Timeout in seconds.
	 * @return array|WP_Error
	 */
	private function send( $method, $path, $body = null, $timeout = 180 ) {
		if ( '' === trim( $this->url ) || '' === trim( $this->token ) ) {
			return new WP_Error( 'configuration.not_set', __( 'Miguel API configuration is not set.', 'miguel' ) );
		}

		$args = array(
			'method'     => $method,
			'timeout'    => $timeout,
			'user-agent' => $this->user_agent(),
			'headers'    => array(
				'Authorization'   => 'Bearer ' . $this->token,
				'Accept-Language' => get_user_locale(),
			),
		);

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json; charset=utf-8';
			$args['body']                    = wp_json_encode( $body );
		}

		return wp_remote_request( trailingslashit( $this->url ) . ltrim( $path, '/' ), $args );
	}

	/**
	 * Convert a v2 IProblem error response into a WP_Error.
	 *
	 * @param array $response wp_remote_* response.
	 * @return WP_Error
	 */
	private function problem_to_wp_error( $response ) {
		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		$title  = ( is_array( $decoded ) && ! empty( $decoded['title'] ) ) ? $decoded['title'] : wp_remote_retrieve_response_message( $response );
		$detail = ( is_array( $decoded ) && ! empty( $decoded['detail'] ) ) ? $decoded['detail'] : '';

		$message = trim( $title . ( '' !== $detail ? ': ' . $detail : '' ) );
		if ( '' === $message ) {
			$message = __( 'Something went wrong.', 'miguel' );
		}

		return new WP_Error( 'miguel.http_' . $status, $message );
	}

	/**
	 * Build the outbound user-agent string.
	 *
	 * @return string
	 */
	private function user_agent() {
		return 'MiguelForWooCommerce/' . miguel()->version . '; WordPress/' . get_bloginfo( 'version' ) . '; WooCommerce/' . WC()->version . '; PHP/' . phpversion() . '; ' . get_bloginfo( 'url' );
	}
}
```

Register the include after the order-mapper line:

```php
		include_once dirname( MIGUEL_PLUGIN_FILE ) . '/includes/api/v2/class-miguel-v2-client.php';
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose -f docker-compose.test.yml run --rm phpunit --filter Miguel_Test_V2_Client`
Expected: PASS (8 tests).

- [ ] **Step 5: Commit**

```bash
git add includes/api/v2/class-miguel-v2-client.php includes/class-miguel.php tests/unit/test-v2-client.php
git commit -m "feat: add Miguel_V2_Client for v2 endpoints"
```

---

## Task 10: Wire `v2_client` into the container and rewire services

**Files:**
- Modify: `includes/class-miguel.php:95-126` (`register_services`)

- [ ] **Step 1: Add the `v2_client` service**

In `includes/class-miguel.php`, immediately after the `api` service registration block (the closure returning `new Miguel_API( $url, $token )`), add:

```php
		$this->container->register( 'v2_client', function () {
			$configuration = Miguel_API::getCurrentApiConfiguration();
			$url           = Miguel_API::MIGUEL_API_BASE_URL;
			$token         = '';

			if ( false !== $configuration ) {
				$url   = $configuration['url'];
				$token = $configuration['token'];
			}

			return new Miguel_V2_Client( $url, $token );
		} );
```

- [ ] **Step 2: Repoint `download` and `orders` services to `v2_client`**

Replace the `download` service body:

```php
		$this->container->register( 'download', function ( $container ) {
			return new Miguel_Download(
				$container->get( 'hook_manager' ),
				$container->get( 'v2_client' )
			);
		} );
```

Replace the `orders` service body:

```php
		$this->container->register( 'orders', function ( $container ) {
			return new Miguel_Orders(
				$container->get( 'hook_manager' ),
				$container->get( 'v2_client' )
			);
		} );
```

- [ ] **Step 3: Verify the plugin still loads (full suite)**

Run: `make test-docker`
Expected: Existing `Miguel_Download`/`Miguel_Orders` tests now FAIL with a type error (constructors still expect `Miguel_API`). This is expected — Tasks 11–12 fix the consumers. Confirm the failures are confined to download/orders construction and that all new v2 tests still pass.

- [ ] **Step 4: Commit**

```bash
git add includes/class-miguel.php
git commit -m "feat: register v2_client service and wire into download/orders"
```

---

## Task 11: Rewire `Miguel_Download` to the v2 client

**Files:**
- Modify: `includes/class-miguel-download.php`
- Test: `tests/helpers/class-miguel-test-case.php`, plus `tests/unit/test-v2-client.php` already covers transport.

- [ ] **Step 1: Update the test-case factory for `Miguel_Download`**

In `tests/helpers/class-miguel-test-case.php`, in `create_service_with_mocks`, replace the `Miguel_Download` case:

```php
			case 'Miguel_Download':
				$client_mock      = $mocks['client'] ?? $this->createMock( Miguel_V2_Client::class );
				$file_factory     = $mocks['file_factory'] ?? null;
				$error_handler    = $mocks['error_handler'] ?? null;
				$redirect_handler = $mocks['redirect_handler'] ?? null;

				return new Miguel_Download(
					$hook_manager,
					$client_mock,
					$file_factory,
					$error_handler,
					$redirect_handler
				);
```

- [ ] **Step 2: Write the failing test**

Add to `tests/unit/test-v2-client.php`? No — add a focused download test. Create `tests/unit/test-download-v2.php`:

```php
<?php
/**
 * Tests for Miguel_Download with the v2 client.
 *
 * @package Miguel\Tests
 */
class Miguel_Test_Download_V2 extends Miguel_Test_Case {

	public function test_serve_file_redirects_to_download_url(): void {
		$redirected = null;
		$client     = $this->createMock( Miguel_V2_Client::class );
		$client->method( 'get_watermarked_file' )->willReturn( array( 'downloadUrl' => 'https://dl/x' ) );

		$download = $this->create_service_with_mocks(
			'Miguel_Download',
			array(
				'client'           => $client,
				'redirect_handler' => function ( $url ) use ( &$redirected ) {
					$redirected = $url;
				},
				'error_handler'    => function ( $msg ) {
					$this->fail( 'Unexpected error: ' . $msg );
				},
			)
		);

		$order = Miguel_Helper_Order::create_order();
		$item  = array_values( $order->get_items() )[0];

		$file = $this->getMockBuilder( Miguel_File::class )->disableOriginalConstructor()->getMock();
		$file->method( 'get_name' )->willReturn( 'book-1' );
		$file->method( 'get_format' )->willReturn( 'epub' );

		$download->serve( $file, $order, $item );

		$this->assertSame( 'https://dl/x', $redirected );

		Miguel_Helper_Order::delete_order( $order->get_id() );
	}
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `docker compose -f docker-compose.test.yml run --rm phpunit --filter Miguel_Test_Download_V2`
Expected: FAIL — `Miguel_Download::__construct()` type error (still expects `Miguel_API`) / `serve_file` still calls `$this->api->generate`.

- [ ] **Step 4: Update `Miguel_Download`**

In `includes/class-miguel-download.php`:

1. Replace the `@var Miguel_API $api` property docblock/type with the client and add a mapper property:

```php
	/**
	 * v2 client instance
	 *
	 * @var Miguel_V2_Client
	 */
	private $client;

	/**
	 * Watermark request mapper
	 *
	 * @var Miguel_Watermark_Mapper
	 */
	private $mapper;
```

(Remove the old `private $api;` property.)

2. Replace the constructor signature and body to accept the client and build the mapper:

```php
	public function __construct(
		Miguel_Hook_Manager_Interface $hook_manager,
		Miguel_V2_Client $client,
		$file_factory = null,
		$error_handler = null,
		$redirect_handler = null
	) {
		$this->hook_manager     = $hook_manager;
		$this->client           = $client;
		$this->mapper           = new Miguel_Watermark_Mapper();
		$this->file_factory     = $file_factory ? $file_factory : 'miguel_get_file';
		$this->error_handler    = $error_handler ? $error_handler : 'wp_die';
		$this->redirect_handler = $redirect_handler ? $redirect_handler : array( $this, 'default_redirect_handler' );
	}
```

3. Replace `serve()` to build the DTO via the mapper:

```php
	public function serve( $file, $order, $item ) {
		$request = $this->mapper->map( $order, $item, $file );
		if ( null === $request ) {
			call_user_func( $this->error_handler, esc_html__( 'Invalid request.', 'miguel' ) );
			return;
		}

		$this->serve_file( $file, $request );
	}
```

4. Replace `serve_file()` to call the client and read `downloadUrl`:

```php
	public function serve_file( $file, $request ) {
		$result = $this->client->get_watermarked_file( $file->get_name(), $request );

		if ( is_wp_error( $result ) ) {
			call_user_func( $this->error_handler, esc_html( $result->get_error_message() ) );
			return;
		}

		if ( empty( $result['downloadUrl'] ) ) {
			call_user_func( $this->error_handler, esc_html__( 'Something went wrong.', 'miguel' ) );
			return;
		}

		call_user_func( $this->redirect_handler, $result['downloadUrl'] );
	}
```

The `@param Miguel_Request $request` docblock on `serve_file` becomes `@param Miguel_V2_Watermarked_File_Request $request`.

- [ ] **Step 5: Run tests**

Run: `docker compose -f docker-compose.test.yml run --rm phpunit --filter Miguel_Test_Download_V2`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add includes/class-miguel-download.php tests/helpers/class-miguel-test-case.php tests/unit/test-download-v2.php
git commit -m "feat: rewire Miguel_Download to v2 client and watermark mapper"
```

---

## Task 12: Rewire `Miguel_Orders` to the v2 client + order mapper

**Files:**
- Modify: `includes/class-miguel-orders.php`, `tests/helpers/class-miguel-test-case.php`, `tests/unit/test-orders.php`

- [ ] **Step 1: Update the test-case factory for `Miguel_Orders`**

In `tests/helpers/class-miguel-test-case.php`, replace the `Miguel_Orders` case:

```php
			case 'Miguel_Orders':
				$client_mock = $mocks['client'] ?? $this->createMock( Miguel_V2_Client::class );
				return new Miguel_Orders( $hook_manager, $client_mock );
```

- [ ] **Step 2: Update `Miguel_Orders`**

In `includes/class-miguel-orders.php`:

1. Replace the `@var Miguel_API $api` property with:

```php
	/**
	 * v2 client instance
	 *
	 * @var Miguel_V2_Client
	 */
	private $client;

	/**
	 * Order mapper
	 *
	 * @var Miguel_Order_Mapper
	 */
	private $mapper;
```

2. Replace the constructor:

```php
	public function __construct( Miguel_Hook_Manager_Interface $hook_manager, Miguel_V2_Client $client ) {
		$this->hook_manager = $hook_manager;
		$this->client       = $client;
		$this->mapper       = new Miguel_Order_Mapper();
	}
```

3. Replace `sync_order()` to use the mapper + client (`create_order`/`delete_order` now return `true|WP_Error`):

```php
	public function sync_order( $order_id, $from_state, $to_state, $order ) {
		if ( 0 == $order->get_id() || in_array( $to_state, array( 'trash', 'refunded', 'cancelled', 'failed' ), true ) ) {
			if ( ! $this->has_order_data_changed( $order, 'delete' ) ) {
				return;
			}

			$result = $this->client->delete_order( strval( $order_id ) );

			if ( is_wp_error( $result ) ) {
				Miguel::log( 'Failed to delete order ' . $order_id . ': ' . $result->get_error_message(), 'error' );
			} else {
				Miguel::log( 'Successfully deleted order ' . $order_id . ' from Miguel API', 'info' );
				$this->store_order_hash( $order, 'delete' );
			}
		} else {
			if ( ! $this->has_order_data_changed( $order, 'sync' ) ) {
				return;
			}

			$order_create = $this->mapper->map( $order );
			if ( null === $order_create ) {
				return;
			}

			$result = $this->client->create_order( $order_create );

			if ( is_wp_error( $result ) ) {
				Miguel::log( 'Failed to sync order ' . $order_id . ': ' . $result->get_error_message(), 'error' );
			} else {
				Miguel::log( 'Successfully synced order ' . $order_id . ' with Miguel API', 'info' );
				$this->store_order_hash( $order, 'sync' );
			}
		}
	}
```

4. Replace `generate_order_hash()` to hash the mapped DTO instead of the removed `prepare_order_data()`:

```php
	private function generate_order_hash( $order, $action ) {
		if ( 'delete' === $action ) {
			$hash_data = array(
				'action'   => 'delete',
				'order_id' => $order->get_id(),
			);
		} else {
			$order_create = $this->mapper->map( $order );
			$hash_data    = array(
				'action'   => 'sync',
				'order_id' => $order->get_id(),
				'status'   => $order->get_status(),
				'data'     => null === $order_create ? array() : $order_create->to_array(),
			);
		}

		return md5( wp_json_encode( $hash_data ) );
	}
```

5. **Delete** the now-unused private methods that moved into `Miguel_Order_Mapper`: `prepare_order_data`, `get_miguel_products_from_item`, `get_miguel_products_from_bundle`, `extract_all_miguel_codes`, `extract_miguel_codes_from_product`.

- [ ] **Step 3: Update `tests/unit/test-orders.php`**

The existing file mixes order-sync tests with direct `Miguel_API::submit_order/delete_order` transport tests. Rework it:

- Change `get_sut()` to use the client:

```php
	private function get_sut() {
		return $this->create_service_with_mocks( 'Miguel_Orders' );
	}
```

- **Remove** the direct-transport tests now covered by `Miguel_Test_V2_Client`: `test_api_delete_order_success`, `test_api_delete_order_404`, `test_api_delete_order_failure`, `test_api_submit_order_success`, `test_api_submit_order_failure`.
- **Remove** `test_prepare_order_data` and `test_prepare_order_data_multiple_codes` (logic moved to `Miguel_Test_Order_Mapper`; add equivalent multi-code coverage to `tests/unit/test-order-mapper.php` if not already present).
- Update the sync/delete-through-`sync_order` tests (`test_sync_order_delete_on_status_change`, `test_sync_order_post_on_status_change`, `test_sync_order_all_deletion_statuses`, and the no-Miguel-items test) to assert against the v2 URLs/bodies via `Miguel_Helper_HTTP::get_requests()`:
  - create → `…/v2/orders` POST
  - delete → `…/v2/orders/{id}` DELETE
  - For a non-Miguel order, assert zero requests were recorded.

Example replacement for the post-on-status-change test:

```php
	public function test_sync_order_post_on_status_change() {
		$product = Miguel_Helper_Product::create_downloadable_product();
		$order   = Miguel_Helper_Order::create_order();
		$order->add_product( $product, 1 );
		$order->set_status( 'processing' );
		$order->set_date_paid( '2023-01-15 10:00:00' );
		$order->save();

		Miguel_Helper_HTTP::mock_api_responses(
			array( 'POST' => array( 'body' => '{}', 'response' => array( 'code' => 201 ) ) )
		);

		$this->get_sut()->sync_order( $order->get_id(), 'new', 'processing', $order );

		$requests = Miguel_Helper_HTTP::get_requests();
		$this->assertNotEmpty( $requests );
		$last = end( $requests );
		$this->assertStringContainsString( '/v2/orders', $last['url'] );
		$this->assertSame( 'POST', $last['method'] );

		Miguel_Helper_Order::delete_order( $order->get_id() );
	}
```

- [ ] **Step 4: Run tests**

Run: `docker compose -f docker-compose.test.yml run --rm phpunit --filter Test_Miguel_Orders`
Then: `make test-docker`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/class-miguel-orders.php tests/helpers/class-miguel-test-case.php tests/unit/test-orders.php
git commit -m "feat: rewire Miguel_Orders to v2 client and order mapper"
```

---

## Task 13: Reduce `Miguel_API` to configuration only; remove `Miguel_Request`

**Files:**
- Modify: `includes/class-miguel-api.php`, `tests/unit/test-api.php`, `includes/class-miguel.php`
- Delete: `includes/class-miguel-request.php`, `tests/unit/test-request.php`

- [ ] **Step 1: Update `tests/unit/test-api.php`**

Remove the transport tests (`test_generate__url`, `test_generate__headers`, `test_generate__format`) and keep a config-focused test. Replace the file body with:

```php
<?php
/**
 * Test API configuration helpers.
 *
 * @package Miguel\Tests
 */
class Miguel_Test_API extends WP_UnitTestCase {

	public function test_get_server_url_for_environments(): void {
		$this->assertSame( 'https://miguel.servantes.cz', Miguel_API::getServerUrl( Miguel_API::ENV_PROD ) );
		$this->assertSame( 'https://miguel-test.servantes.cz', Miguel_API::getServerUrl( Miguel_API::ENV_TEST ) );
		$this->assertFalse( Miguel_API::getServerUrl( 'nonsense' ) );
	}

	public function test_get_enabled_reflects_api_key_option(): void {
		update_option( Miguel_API::API_KEY_OPTION, '' );
		$this->assertFalse( Miguel_API::getEnabled() );

		update_option( Miguel_API::API_KEY_OPTION, 'abc123' );
		$this->assertTrue( Miguel_API::getEnabled() );

		delete_option( Miguel_API::API_KEY_OPTION );
	}

	public function test_get_current_api_configuration(): void {
		update_option( Miguel_API::API_KEY_OPTION, 'abc123' );
		update_option( Miguel_API::SERVER_OPTION, Miguel_API::ENV_TEST );

		$config = Miguel_API::getCurrentApiConfiguration();

		$this->assertSame( 'https://miguel-test.servantes.cz', $config['url'] );
		$this->assertSame( 'abc123', $config['token'] );
		$this->assertSame( Miguel_API::ENV_TEST, $config['environment'] );

		delete_option( Miguel_API::API_KEY_OPTION );
		delete_option( Miguel_API::SERVER_OPTION );
	}
}
```

- [ ] **Step 2: Run test to verify it fails appropriately**

Run: `docker compose -f docker-compose.test.yml run --rm phpunit --filter Miguel_Test_API`
Expected: PASS already (these config methods exist) — this step confirms the reduced test suite is green before deletion. If `connect`/transport tests elsewhere reference removed methods, note them for Step 3.

- [ ] **Step 3: Reduce `Miguel_API`**

In `includes/class-miguel-api.php`, **remove** these instance methods and their helpers: `generate()`, `submit_order()`, `delete_order()`, `connect_woocommerce()`, `post()`, `delete()`, `build_url()`, `build_base_uri()`, `validate_outbound_configuration()`, `user_agent()`. Keep the constructor, `$url`/`$token` properties, `get_url()`, and **all** static configuration methods and their backward-compat wrappers (`getDefaultValues`, `getServer`, `getServerUrl`, `getServerToken`, `getEnabled`, `setEnabled`, `getCurrentApiConfiguration`, and the `get_*`/`set_*` wrappers).

- [ ] **Step 4: Remove `Miguel_Request` and its test**

```bash
git rm includes/class-miguel-request.php tests/unit/test-request.php
```

In `includes/class-miguel.php`, **delete** the line:

```php
		include_once dirname( MIGUEL_PLUGIN_FILE ) . '/includes/class-miguel-request.php';
```

- [ ] **Step 5: Run the full suite**

Run: `make test-docker`
Expected: PASS. (If any lingering reference to `Miguel_Request`, `submit_order`, `generate`, `delete_order`, or `connect_woocommerce` remains, grep and fix: `grep -rn "Miguel_Request\|submit_order\|connect_woocommerce\|->generate(\|->delete_order(" includes tests`.)

- [ ] **Step 6: Commit**

```bash
git add includes/class-miguel-api.php includes/class-miguel.php tests/unit/test-api.php
git commit -m "refactor: reduce Miguel_API to config-only; remove Miguel_Request"
```

---

## Task 14: Rewire connect in `Miguel_Settings` to the v2 client

**Files:**
- Modify: `includes/admin/class-miguel-settings.php`

- [ ] **Step 1: Update `connect_to_miguel_api()`**

In `includes/admin/class-miguel-settings.php`, replace the body of `connect_to_miguel_api()` so it builds the DTO and calls the client. The client returns `true|WP_Error`; map the error to the appropriate admin message (preserving the unauthorized vs generic distinction via the `WP_Error` code `miguel.http_401` / `miguel.http_403`):

```php
	private function connect_to_miguel_api() {
		$api_key = trim( (string) get_option( Miguel_API::API_KEY_OPTION, '' ) );
		if ( '' === $api_key ) {
			WC_Admin_Settings::add_error( __( 'Miguel connection skipped: API key is required.', 'miguel' ) );
			update_option( 'miguel_api_connected', 'no' );
			return;
		}

		$api_url = Miguel_API::getServerUrl( Miguel_API::getServer() );
		if ( false === $api_url || '' === trim( (string) $api_url ) ) {
			WC_Admin_Settings::add_error( __( 'Miguel API URL is not configured.', 'miguel' ) );
			update_option( 'miguel_api_connected', 'no' );
			return;
		}

		$base_url = $this->get_canonical_shop_url();
		$client   = new Miguel_V2_Client( $api_url, $api_key );
		$result   = $client->connect(
			new Miguel_V2_Connect_Request(
				$this->get_woocommerce_version(),
				$this->get_module_version(),
				$base_url,
				$this->build_base_uri( $base_url )
			)
		);

		if ( true === $result ) {
			update_option( 'miguel_api_connected', 'yes' );
			update_option( 'miguel_api_last_connected_at', gmdate( 'c' ) );
			WC_Admin_Settings::add_message( __( 'Miguel API connection successful.', 'miguel' ) );
			return;
		}

		$code = $result->get_error_code();
		if ( 'miguel.http_401' === $code || 'miguel.http_403' === $code ) {
			Miguel::log( 'Miguel connect unauthorized: ' . $result->get_error_message(), 'error' );
			WC_Admin_Settings::add_error( __( 'Miguel API key is invalid or unauthorized.', 'miguel' ) );
			update_option( 'miguel_api_connected', 'no' );
			return;
		}

		Miguel::log( 'Miguel connect request failed: ' . $code . ' ' . $result->get_error_message(), 'error' );
		WC_Admin_Settings::add_error( __( 'Connection to Miguel API failed. Please verify API key and try again.', 'miguel' ) );
		update_option( 'miguel_api_connected', 'no' );
	}

	/**
	 * Build a canonical base URI path from an absolute shop URL.
	 *
	 * @param string $base_url Absolute shop base URL.
	 * @return string
	 */
	private function build_base_uri( $base_url ) {
		$path = wp_parse_url( (string) $base_url, PHP_URL_PATH );

		if ( ! is_string( $path ) || '' === $path ) {
			return '/';
		}

		return trailingslashit( '/' . ltrim( $path, '/' ) );
	}
```

Delete any now-dead local variables/branches in the old implementation (the manual `wp_remote_retrieve_*` status parsing block below the old `$api->connect_woocommerce(...)` call is fully replaced).

- [ ] **Step 2: Run the full suite**

Run: `make test-docker`
Expected: PASS. Confirm no references to `connect_woocommerce` remain: `grep -rn "connect_woocommerce" includes`.

- [ ] **Step 3: Manual sanity (optional but recommended)**

In a local WP/WC environment, save Miguel settings with a valid test API key against the test environment and confirm the success notice appears and `miguel_api_connected` is `yes`.

- [ ] **Step 4: Commit**

```bash
git add includes/admin/class-miguel-settings.php
git commit -m "feat: rewire WooCommerce connect to v2 client"
```

---

## Task 15: Final verification & cleanup

**Files:**
- Modify (if desired): `miguel.php`, `readme.txt`, `CHANGELOG.md` (version bump)

- [ ] **Step 1: Grep for any v1 / dead references**

Run:
```bash
grep -rn "v1/\|submit_order\|connect_woocommerce\|Miguel_Request\|prepare_order_data\|download_url" includes tests
```
Expected: no remaining matches in production code (the only `downloadUrl` references should be the v2 `downloadUrl` key). Investigate and fix any hit.

- [ ] **Step 2: Run the full test suite**

Run: `make test-docker`
Expected: PASS (all suites green).

- [ ] **Step 3: Run coding standards**

Run: `vendor/bin/phpcs --standard=phpcs.xml includes/api` (and the modified files)
Expected: no errors. Fix any reported issues.

- [ ] **Step 4: Version bump (optional, follow existing release convention)**

If releasing, bump the version in `miguel.php` header, `readme.txt` `Stable tag`, and add a `CHANGELOG.md` entry summarizing the v2 migration.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "chore: finalize Miguel API v2 migration"
```

---

## Self-Review Notes

- **Spec coverage:** all 4 endpoints migrated (watermarked-file → Task 7/9/11; create order → Task 8/9/12; delete order → Task 9/12; connect → Task 6/9/14). DTOs (Tasks 1–6), mappers (7–8), client (9), wiring (10), `Miguel_API` reduction + `Miguel_Request` removal (13). Addresses included (Task 8). `sendEmail=disable` (Task 8). Excl-VAT preserved (Tasks 7–8). Hard cutover (Task 13 removes all v1).
- **Type consistency:** DTO method `to_array()` used uniformly; `Miguel_V2_Watermarked_File_Request::get_target()` consumed by client format validation; client returns `array|WP_Error` (watermarked-file) and `true|WP_Error` (create/delete/connect) consistently across consumers.
- **Known divergence:** `soldPrice` excl-VAT vs v2 "after taxes" doc — recorded in the spec; not a blocker.
- **Helper assumption to verify during execution:** `Miguel_Helper_Product::create_simple_product()` (Task 8 Step 1) — if absent, build a non-Miguel downloadable product inline.
```
