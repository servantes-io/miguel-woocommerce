# SMP-13 — Miguel Payment Gateway — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Orders Miguel creates in a WooCommerce shop (sent with `payment_method: "miguel"`) show "Miguel" as their payment method instead of WooCommerce's "Other" / "Jiná".

**Architecture:** Register a real WooCommerce payment gateway with id `miguel` that is enabled (so the order edit dropdown lists it) but never available at checkout. `POST /miguel/v1/orders` fills `payment_method_title` from that gateway when Miguel sends none. A block-checkout integration registers the method in JavaScript with `canMakePayment` always false, so the Checkout block editor does not flag the enabled gateway as incompatible.

**Tech Stack:** PHP 8.1, WordPress 6.5+, WooCommerce 7.9+ (`WC_Payment_Gateway`, `WC_Payment_Gateways`, WooCommerce Blocks `AbstractPaymentMethodType` / `PaymentMethodRegistry`), plain browser JavaScript (no build step), PHPUnit on the WooCommerce unit-test framework, run in Docker.

**Spec:** `docs/superpowers/specs/2026-09-13-smp-13-miguel-payment-gateway-design.md`

## Global Constraints

- **Sequencing.** SMP-12 (adds `order_note` to `includes/class-miguel-order-create-api.php`) is implemented before this plan and SMP-14 after it. Rebase this branch onto SMP-12's branch (or `main`, once SMP-12 is merged) before starting. Every code reference below is by **method name**, never by line number; when an edit anchor quoted below has moved or gained lines from SMP-12, find the same method and apply the same change.
- **Worktree setup (once).** A git worktree has no `vendor/`. Before the first test run: `docker compose -f docker-compose.test.yml run --rm --entrypoint composer phpunit install --no-interaction --prefer-dist`. The first test run in a new worktree also downloads WordPress and WooCommerce into that worktree's own Docker volumes; it is slow once.
- **Tests run via Docker only.** Full suite: `make test-docker`. One class: `docker compose -f docker-compose.test.yml run --rm phpunit --filter=<ClassName>`. Never call `vendor/bin/phpunit` on the host.
- **Lint:** `docker compose -f docker-compose.test.yml run --rm --entrypoint vendor/bin/phpcs phpunit <paths>` (ruleset `phpcs.xml`).
- **Floors:** PHP 8.1, WordPress 6.5, WooCommerce 7.9 (`miguel.php` header). Every WooCommerce API this plan uses exists at 7.9. `FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', … )` returns `false` silently on versions that do not know the feature; that is fine.
- **Gateway id is `miguel`, exactly.** The Miguel backend hardcodes `payment_method: "miguel"`. Never rename it.
- **Do not add types to properties inherited from WooCommerce classes** (`$id`, `$method_title`, `$supports`, `$name`, `$settings`, …). Their parents declare them untyped, and PHP fatals on a typed redeclaration.
- **Coding standard:** WordPress (tabs, Yoda conditions, `array()` long syntax, docblocks on every method). Text domain `miguel` (enforced by `phpcs.xml`). Every new string goes into **both** catalogues, `languages/miguel-cs_CZ.po` (Czech) and `languages/miguel-en_US.po` (msgstr = msgid). `msgid "Miguel"` already exists in `miguel-cs_CZ.po`; do not add it a second time to either file (a duplicate msgid breaks `msgfmt`).
- **Release notes:** entries go under the existing, unreleased `1.10.0` heading in `CHANGELOG.md` and in the `== Changelog ==` section of `readme.txt`. No version bump.
- **Plugin load order:** `miguel/miguel.php` loads before `woocommerce/woocommerce.php`, so `WC_Payment_Gateway` does not exist while `Miguel::includes()` runs. The gateway file is included on `plugins_loaded`. In the test bootstrap WooCommerce loads first, then the plugin, both on `muplugins_loaded`; `plugins_loaded` fires after that, so the class is loaded once for the whole run.
- **Test isolation gotcha:** `Miguel_Test_Case::setUp()` calls `Miguel::reset_instance()`, which removes every hook the plugin registered — including the gateway filter. A test that needs the gateway in WooCommerce's list calls `Miguel::instance()` (re-registers the hooks) and then `WC()->payment_gateways()->init()` (re-reads the filter and the gateway settings).

---

## File Structure

| Action | Path | Responsibility |
|--------|------|----------------|
| Create | `includes/class-miguel-payment-gateway.php` | `Miguel_Payment_Gateway` — the `miguel` gateway: settings, never available, no refunds, fails closed |
| Create | `includes/class-miguel-payment-gateway-blocks.php` | `Miguel_Payment_Gateway_Blocks` — block-checkout integration for the gateway |
| Create | `assets/js/payment-method-blocks.js` | Registers `miguel` with the block checkout, `canMakePayment` always false |
| Modify | `includes/class-miguel.php` | `init_hooks()`: include the gateway on `plugins_loaded`, add it to `woocommerce_payment_gateways`, register the block integration, declare `cart_checkout_blocks` compatibility; new public callbacks |
| Modify | `includes/class-miguel-order-create-api.php` | `prepare_payload_for_wc_order()` calls new `prepare_payment_method_title_for_wc_order()` |
| Create | `tests/unit/test-payment-gateway.php` | Gateway and block-integration tests |
| Modify | `tests/unit/test-order-create-api.php` | `payment_method_title` fill-in tests |
| Modify | `languages/miguel-cs_CZ.po`, `languages/miguel-en_US.po` | New gateway strings |
| Modify | `docs/openapi.yaml` | Document `miguel` and the title fill-in in `CreateOrderRequest` |
| Modify | `CHANGELOG.md`, `readme.txt` | 1.10.0 entries |

---

### Task 1: The `miguel` payment gateway

**Files:**
- Create: `includes/class-miguel-payment-gateway.php`
- Modify: `includes/class-miguel.php` (`init_hooks()`, new methods after `init()`)
- Create: `tests/unit/test-payment-gateway.php`
- Modify: `languages/miguel-cs_CZ.po`, `languages/miguel-en_US.po`

**Interfaces:**
- Consumes: nothing from other tasks.
- Produces:
  - `class Miguel_Payment_Gateway extends WC_Payment_Gateway` with `const ID = 'miguel';`, `public function is_available()` → `false`, `public function process_payment( $order_id )` → `array( 'result' => 'failure' )`. Settings option `woocommerce_miguel_settings` with keys `enabled` (default `'yes'`), `title` (default `'Miguel'`), `description` (default `''`).
  - `Miguel::include_payment_gateway(): void` (on `plugins_loaded`) and `Miguel::register_payment_gateway( array $gateways ): array` (on `woocommerce_payment_gateways`).

- [x] **Step 0: Worktree setup (skip if `vendor/` exists)**

Run: `docker compose -f docker-compose.test.yml run --rm --entrypoint composer phpunit install --no-interaction --prefer-dist`
Expected: `vendor/bin/phpunit` exists afterwards.

- [x] **Step 1: Write the failing tests**

Create `tests/unit/test-payment-gateway.php`:

```php
<?php
/**
 * Test the Miguel payment gateway.
 *
 * @package Miguel\Tests
 */
class Test_Miguel_Payment_Gateway extends Miguel_Test_Case {

	/**
	 * Put the gateway in WooCommerce's list the way a shop has it.
	 *
	 * The parent setUp() resets the plugin instance, which removes its hooks, the
	 * woocommerce_payment_gateways filter included. Re-creating the instance re-adds it, and
	 * re-initialising the gateways re-reads that filter and woocommerce_miguel_settings.
	 */
	public function setUp(): void {
		parent::setUp();

		Miguel::instance();
		WC()->payment_gateways()->init();
	}

	/**
	 * Drop saved gateway settings, so a renamed or disabled gateway does not leak into later tests.
	 */
	public function tearDown(): void {
		delete_option( 'woocommerce_miguel_settings' );
		WC()->payment_gateways()->init();

		parent::tearDown();
	}

	/**
	 * The registered Miguel gateway.
	 *
	 * @return WC_Payment_Gateway
	 */
	private function get_gateway() {
		$gateways = WC()->payment_gateways()->payment_gateways();
		$this->assertArrayHasKey( Miguel_Payment_Gateway::ID, $gateways, 'The Miguel gateway should be registered with WooCommerce.' );

		return $gateways[ Miguel_Payment_Gateway::ID ];
	}

	/**
	 * The gateway id is what the Miguel backend sends as payment_method.
	 */
	public function test_gateway_id_is_what_miguel_sends() {
		$this->assertSame( 'miguel', Miguel_Payment_Gateway::ID );
	}

	/**
	 * WooCommerce lists the gateway under its id.
	 */
	public function test_gateway_is_registered_with_woocommerce() {
		$this->assertInstanceOf( Miguel_Payment_Gateway::class, $this->get_gateway() );
	}

	/**
	 * A customer can never choose it at checkout.
	 */
	public function test_gateway_is_never_available_at_checkout() {
		$this->assertFalse( $this->get_gateway()->is_available() );
		$this->assertArrayNotHasKey(
			Miguel_Payment_Gateway::ID,
			WC()->payment_gateways()->get_available_payment_gateways()
		);
	}

	/**
	 * Enabled by default: that is what lists it in the order screen's payment dropdown.
	 */
	public function test_gateway_is_enabled_by_default() {
		$this->assertSame( 'yes', $this->get_gateway()->enabled );
	}

	/**
	 * The title is "Miguel" until the merchant renames it.
	 */
	public function test_title_defaults_to_miguel() {
		$this->assertSame( 'Miguel', $this->get_gateway()->get_title() );
	}

	/**
	 * A title saved in WooCommerce → Settings → Payments wins.
	 */
	public function test_title_comes_from_the_saved_settings() {
		update_option(
			'woocommerce_miguel_settings',
			array(
				'enabled'     => 'yes',
				'title'       => 'Zaplaceno v aplikaci',
				'description' => '',
			)
		);
		WC()->payment_gateways()->init();

		$this->assertSame( 'Zaplaceno v aplikaci', $this->get_gateway()->get_title() );
	}

	/**
	 * Still never available when enabled explicitly.
	 */
	public function test_gateway_is_not_available_even_when_enabled_explicitly() {
		update_option( 'woocommerce_miguel_settings', array( 'enabled' => 'yes' ) );
		WC()->payment_gateways()->init();

		$this->assertFalse( $this->get_gateway()->is_available() );
	}

	/**
	 * The money is in Stripe, so WooCommerce offers no automatic refund.
	 */
	public function test_gateway_does_not_support_refunds() {
		$this->assertFalse( $this->get_gateway()->supports( 'refunds' ) );
		$this->assertTrue( $this->get_gateway()->supports( 'products' ) );
	}

	/**
	 * A checkout that ignored is_available() still takes no money.
	 */
	public function test_process_payment_fails_closed() {
		$order = Miguel_Helper_Order::create_order();

		$this->assertSame(
			array( 'result' => 'failure' ),
			$this->get_gateway()->process_payment( $order->get_id() )
		);

		Miguel_Helper_Order::delete_order( $order->get_id() );
	}

	/**
	 * The plugin adds the gateway class to WooCommerce's list.
	 */
	public function test_register_payment_gateway_appends_the_class() {
		$this->assertSame(
			array( 'WC_Gateway_BACS', 'Miguel_Payment_Gateway' ),
			Miguel::instance()->register_payment_gateway( array( 'WC_Gateway_BACS' ) )
		);
	}
}
```

- [x] **Step 2: Run the tests to verify they fail**

Run: `docker compose -f docker-compose.test.yml run --rm phpunit --filter=Test_Miguel_Payment_Gateway`
Expected: FAIL — `Error: Class "Miguel_Payment_Gateway" not found`.

- [x] **Step 3: Create the gateway**

Create `includes/class-miguel-payment-gateway.php`:

```php
<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * The payment method of the orders Miguel creates in the shop.
 *
 * A customer pays for these orders in a Miguel app, through Stripe run by Miguel, and Miguel
 * then creates the order here with payment method "miguel". Without a gateway of that id,
 * WooCommerce shows the order's payment method as "Other". This gateway exists only to give
 * those orders a name: it is never available at checkout and takes no money.
 *
 * @package Miguel
 */
class Miguel_Payment_Gateway extends WC_Payment_Gateway {

	/**
	 * Gateway id, and the payment_method Miguel sends when it creates an order.
	 */
	const ID = 'miguel';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = self::ID;
		$this->method_title       = __( 'Miguel', 'miguel' );
		$this->method_description = __( 'Orders placed and paid in a Miguel app. Miguel creates these orders itself; this method is never offered at checkout.', 'miguel' );
		$this->has_fields         = false;
		// No 'refunds': the money is in Stripe, so a refund in WooCommerce stays a manual one.
		$this->supports = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		// WooCommerce constructs gateways itself, outside the plugin's hook manager; this is the
		// standard way every gateway saves its settings screen.
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Settings shown in WooCommerce → Settings → Payments → Miguel.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'     => array(
				'title'   => __( 'Enable/Disable', 'miguel' ),
				'type'    => 'checkbox',
				'label'   => __( 'Show Miguel as the payment method of orders from the Miguel app', 'miguel' ),
				// Enabled is what lists the gateway in the order screen's payment dropdown.
				'default' => 'yes',
			),
			'title'       => array(
				'title'       => __( 'Title', 'miguel' ),
				'type'        => 'text',
				'description' => __( 'The payment method name shown on orders from the Miguel app.', 'miguel' ),
				'default'     => __( 'Miguel', 'miguel' ),
				'desc_tip'    => true,
			),
			'description' => array(
				'title'   => __( 'Description', 'miguel' ),
				'type'    => 'textarea',
				'default' => '',
			),
		);
	}

	/**
	 * Never available: Miguel creates these orders itself, a customer never picks this at checkout.
	 *
	 * @return bool
	 */
	public function is_available() {
		return false;
	}

	/**
	 * Take no money. Unreachable while is_available() is false; a checkout that ignores it fails closed.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		return array( 'result' => 'failure' );
	}
}
```

- [x] **Step 4: Register it from the plugin**

In `includes/class-miguel.php`, method `init_hooks()`: directly after the `before_woocommerce_init` block (the closure that declares `custom_order_tables` compatibility) and **before** `if ( ! defined( 'MIGUEL_TESTS' ) ) {`, add:

```php
		// The payment method of the orders Miguel creates. Outside the MIGUEL_TESTS block below:
		// unlike those services, the gateway talks to nothing outside the shop, so the tests see it
		// exactly as a shop does.
		$this->hook_manager->add_action( 'plugins_loaded', array( $this, 'include_payment_gateway' ) );
		$this->hook_manager->add_filter( 'woocommerce_payment_gateways', array( $this, 'register_payment_gateway' ) );
```

Then add these two public methods directly after `init()` (the "Localize." method):

```php
	/**
	 * Load the Miguel payment gateway class.
	 *
	 * The class extends WC_Payment_Gateway, which does not exist yet while this plugin's files are
	 * included (this plugin loads before WooCommerce). WooCommerce defines it while it loads, so it
	 * exists by plugins_loaded. Loaded here rather than inside the gateways filter because the
	 * order API reads Miguel_Payment_Gateway::ID on requests that never initialise the gateways.
	 */
	public function include_payment_gateway() {
		if ( class_exists( 'WC_Payment_Gateway' ) ) {
			include_once dirname( MIGUEL_PLUGIN_FILE ) . '/includes/class-miguel-payment-gateway.php';
		}
	}

	/**
	 * Add the Miguel payment gateway to WooCommerce's gateways.
	 *
	 * @param array $gateways Gateway class names or instances.
	 * @return array
	 */
	public function register_payment_gateway( $gateways ) {
		$gateways[] = 'Miguel_Payment_Gateway';
		return $gateways;
	}
```

- [x] **Step 5: Run the tests to verify they pass**

Run: `docker compose -f docker-compose.test.yml run --rm phpunit --filter=Test_Miguel_Payment_Gateway`
Expected: PASS (10 tests).

- [x] **Step 6: Add the strings to both catalogues**

Append to `languages/miguel-cs_CZ.po`:

```
#: includes/class-miguel-payment-gateway.php
msgid "Orders placed and paid in a Miguel app. Miguel creates these orders itself; this method is never offered at checkout."
msgstr "Objednávky zadané a zaplacené v aplikaci Miguel. Miguel je zakládá sám; na pokladně se tento způsob platby nikdy nenabízí."

#: includes/class-miguel-payment-gateway.php
msgid "Enable/Disable"
msgstr "Povolit/Zakázat"

#: includes/class-miguel-payment-gateway.php
msgid "Show Miguel as the payment method of orders from the Miguel app"
msgstr "Zobrazovat Miguel jako způsob platby u objednávek z aplikace Miguel"

#: includes/class-miguel-payment-gateway.php
msgid "Title"
msgstr "Název"

#: includes/class-miguel-payment-gateway.php
msgid "The payment method name shown on orders from the Miguel app."
msgstr "Název způsobu platby zobrazený u objednávek z aplikace Miguel."

#: includes/class-miguel-payment-gateway.php
msgid "Description"
msgstr "Popis"
```

Append to `languages/miguel-en_US.po`:

```
#: includes/class-miguel-payment-gateway.php
msgid "Orders placed and paid in a Miguel app. Miguel creates these orders itself; this method is never offered at checkout."
msgstr "Orders placed and paid in a Miguel app. Miguel creates these orders itself; this method is never offered at checkout."

#: includes/class-miguel-payment-gateway.php
msgid "Enable/Disable"
msgstr "Enable/Disable"

#: includes/class-miguel-payment-gateway.php
msgid "Show Miguel as the payment method of orders from the Miguel app"
msgstr "Show Miguel as the payment method of orders from the Miguel app"

#: includes/class-miguel-payment-gateway.php
msgid "Title"
msgstr "Title"

#: includes/class-miguel-payment-gateway.php
msgid "The payment method name shown on orders from the Miguel app."
msgstr "The payment method name shown on orders from the Miguel app."

#: includes/class-miguel-payment-gateway.php
msgid "Description"
msgstr "Description"
```

Also add a source reference to the existing `msgid "Miguel"` entry in `miguel-cs_CZ.po`: change its comment line `#: includes/admin/class-miguel-settings.php:18` to

```
#: includes/admin/class-miguel-settings.php:18
#: includes/class-miguel-payment-gateway.php
```

Check both catalogues compile (the host and the test image have no gettext; this uses a throwaway container):

Run: `docker run --rm -v "$PWD":/w -w /w debian:stable-slim sh -c 'apt-get update -qq >/dev/null && apt-get install -y -qq gettext >/dev/null && msgfmt --check -o /dev/null languages/miguel-cs_CZ.po && msgfmt --check -o /dev/null languages/miguel-en_US.po && echo OK'`
Expected: `OK`

- [x] **Step 7: Lint and commit**

Run: `docker compose -f docker-compose.test.yml run --rm --entrypoint vendor/bin/phpcs phpunit includes/class-miguel-payment-gateway.php includes/class-miguel.php tests/unit/test-payment-gateway.php`
Expected: no errors.

```bash
git add includes/class-miguel-payment-gateway.php includes/class-miguel.php tests/unit/test-payment-gateway.php languages/miguel-cs_CZ.po languages/miguel-en_US.po
git commit -m "feat(payment): a Miguel gateway, so orders from the app stop saying \"Other\""
```

---

### Task 2: Fill `payment_method_title` on orders Miguel creates

**Files:**
- Modify: `includes/class-miguel-order-create-api.php` (`prepare_payload_for_wc_order()`, new `prepare_payment_method_title_for_wc_order()`)
- Modify: `tests/unit/test-order-create-api.php`
- Modify: `docs/openapi.yaml` (`components.schemas.CreateOrderRequest`)

**Interfaces:**
- Consumes: `Miguel_Payment_Gateway::ID` and the gateway registration from Task 1.
- Produces: `private function prepare_payment_method_title_for_wc_order( $payload )` — array in, array out. Called by `prepare_payload_for_wc_order()` right after `prepare_customer_id_for_wc_order()`. SMP-14 later adds shipping preparation to the same method after the line-item loop; the two do not touch.

- [x] **Step 1: Write the failing tests**

Add to `tests/unit/test-order-create-api.php`, inside `class Test_Miguel_Order_Create_Api`, after the last test method (before the private `build_musk_order_request()` helper):

```php
	/**
	 * Put the Miguel gateway in WooCommerce's list the way a shop has it.
	 *
	 * Miguel_Test_Case::setUp() resets the plugin instance, which removes its hooks, the
	 * woocommerce_payment_gateways filter included. Re-creating the instance re-adds it, and
	 * re-initialising the gateways re-reads woocommerce_miguel_settings.
	 */
	private function load_payment_gateways() {
		Miguel::instance();
		WC()->payment_gateways()->init();
	}

	/**
	 * Run prepare_payload_for_wc_order() on a payload.
	 *
	 * @param array $payload Request payload.
	 * @return array|WP_Error
	 */
	private function prepare_payload( $payload ) {
		$api    = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );
		$method = ( new ReflectionClass( $api ) )->getMethod( 'prepare_payload_for_wc_order' );
		$method->setAccessible( true );

		return $method->invoke( $api, $payload );
	}

	/**
	 * A payload for one downloadable product, paid with the given method.
	 *
	 * @param string $payment_method Payment method id.
	 * @return array
	 */
	private function get_payload_paid_with( $payment_method ) {
		$product = Miguel_Helper_Product::create_downloadable_product();

		return array(
			'payment_method' => $payment_method,
			'line_items'     => array(
				array(
					'product_id' => $product->get_id(),
					'quantity'   => 1,
				),
			),
		);
	}

	/**
	 * Miguel sends payment_method "miguel" with no title; the gateway's title fills it in.
	 */
	public function test_prepare_payload_for_wc_order_fills_title_of_miguel_payment_method() {
		$this->load_payment_gateways();

		$result = $this->prepare_payload( $this->get_payload_paid_with( 'miguel' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'Miguel', $result['payment_method_title'] );
	}

	/**
	 * A blank title counts as none.
	 */
	public function test_prepare_payload_for_wc_order_fills_blank_title_of_miguel_payment_method() {
		$this->load_payment_gateways();

		$payload                         = $this->get_payload_paid_with( 'miguel' );
		$payload['payment_method_title'] = '   ';

		$result = $this->prepare_payload( $payload );

		$this->assertSame( 'Miguel', $result['payment_method_title'] );
	}

	/**
	 * A title Miguel sends is kept, so it can word it per app.
	 */
	public function test_prepare_payload_for_wc_order_keeps_title_sent_with_miguel_payment_method() {
		$this->load_payment_gateways();

		$payload                         = $this->get_payload_paid_with( 'miguel' );
		$payload['payment_method_title'] = 'Melvil app';

		$result = $this->prepare_payload( $payload );

		$this->assertSame( 'Melvil app', $result['payment_method_title'] );
	}

	/**
	 * Other payment methods are left exactly as sent.
	 */
	public function test_prepare_payload_for_wc_order_leaves_other_payment_methods_alone() {
		$this->load_payment_gateways();

		$result = $this->prepare_payload( $this->get_payload_paid_with( 'bacs' ) );

		$this->assertArrayNotHasKey( 'payment_method_title', $result );
	}

	/**
	 * A merchant who renamed the gateway gets that name on new orders.
	 */
	public function test_prepare_payload_for_wc_order_uses_renamed_gateway_title() {
		update_option(
			'woocommerce_miguel_settings',
			array(
				'enabled'     => 'yes',
				'title'       => 'Zaplaceno v aplikaci',
				'description' => '',
			)
		);
		$this->load_payment_gateways();

		try {
			$result = $this->prepare_payload( $this->get_payload_paid_with( 'miguel' ) );

			$this->assertSame( 'Zaplaceno v aplikaci', $result['payment_method_title'] );
		} finally {
			delete_option( 'woocommerce_miguel_settings' );
			WC()->payment_gateways()->init();
		}
	}

	/**
	 * With the gateway gone from WooCommerce's list (e.g. filtered out), the title is still "Miguel".
	 */
	public function test_prepare_payload_for_wc_order_falls_back_when_gateway_is_not_registered() {
		$this->load_payment_gateways();
		WC()->payment_gateways()->payment_gateways = array();

		try {
			$result = $this->prepare_payload( $this->get_payload_paid_with( 'miguel' ) );

			$this->assertSame( 'Miguel', $result['payment_method_title'] );
		} finally {
			WC()->payment_gateways()->init();
		}
	}

	/**
	 * End to end: the created order stores the title, so emails and the order list show it.
	 */
	public function test_create_order_with_miguel_payment_method_stores_the_gateway_title() {
		$this->load_payment_gateways();
		$product = Miguel_Helper_Product::create_downloadable_product();

		$payload = array(
			'idempotency_key' => 'miguel-title-' . $product->get_id(),
			'payment_method'  => 'miguel',
			'billing'         => array(
				'first_name' => 'Test',
				'email'      => 'buyer@example.com',
			),
			'shipping'        => array(
				'first_name' => 'Test',
			),
			'shipping_lines'  => array(
				array(
					'method_id' => 'free_shipping',
					'total'     => '0.00',
				),
			),
			'line_items'      => array(
				array(
					'product_id' => $product->get_id(),
					'quantity'   => 1,
				),
			),
		);

		$request = new WP_REST_Request( 'POST', '/miguel/v1/orders' );
		$request->add_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $payload ) );

		$api      = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );
		$response = $api->create_order( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 201, $response->get_status() );

		$order = wc_get_order( $response->get_data()['id'] );
		$this->assertSame( 'miguel', $order->get_payment_method() );
		$this->assertSame( 'Miguel', $order->get_payment_method_title() );

		Miguel_Helper_Order::delete_order( $order->get_id() );
	}
```

- [x] **Step 2: Run the tests to verify they fail**

Run: `docker compose -f docker-compose.test.yml run --rm phpunit --filter=Test_Miguel_Order_Create_Api`
Expected: FAIL — the fill-in tests fail with `Undefined array key "payment_method_title"` / `Failed asserting that '' is identical to 'Miguel'`; `keeps_title_sent` and `leaves_other_payment_methods_alone` already pass.

- [x] **Step 3: Implement the fill-in**

In `includes/class-miguel-order-create-api.php`, method `prepare_payload_for_wc_order()`: after the line

```php
		$payload = $this->prepare_customer_id_for_wc_order( $payload );
```

add

```php
		$payload = $this->prepare_payment_method_title_for_wc_order( $payload );
```

Then add this method directly after `prepare_customer_id_for_wc_order()`:

```php
	/**
	 * Name the Miguel payment method on an order that does not name it itself.
	 *
	 * WooCommerce stores payment_method_title as sent, and emails, the order list and exports
	 * print the stored value, so an order Miguel creates without a title would show none. A
	 * title in the payload is kept, so Miguel can still word it per app.
	 *
	 * @param array $payload Request payload.
	 * @return array
	 */
	private function prepare_payment_method_title_for_wc_order( $payload ) {
		if ( Miguel_Payment_Gateway::ID !== (string) ( $payload['payment_method'] ?? '' ) ) {
			return $payload;
		}

		$title = $payload['payment_method_title'] ?? '';
		if ( is_string( $title ) && '' !== trim( $title ) ) {
			return $payload;
		}

		$gateways = WC()->payment_gateways()->payment_gateways();

		$payload['payment_method_title'] = isset( $gateways[ Miguel_Payment_Gateway::ID ] )
			? $gateways[ Miguel_Payment_Gateway::ID ]->get_title()
			: __( 'Miguel', 'miguel' );

		return $payload;
	}
```

(`__( 'Miguel', 'miguel' )` reuses the existing catalogue entry; nothing to add to the `.po` files.)

- [x] **Step 4: Run the tests to verify they pass**

Run: `docker compose -f docker-compose.test.yml run --rm phpunit --filter=Test_Miguel_Order_Create_Api`
Expected: PASS (all tests in the class, old and new).

- [x] **Step 5: Document it in the OpenAPI spec**

In `docs/openapi.yaml`, under `components.schemas.CreateOrderRequest.properties`, replace

```yaml
        payment_method:
          type: string
          description: WooCommerce payment method ID.
          example: bacs
        payment_method_title:
          type: string
          example: Direct Bank Transfer
```

with

```yaml
        payment_method:
          type: string
          description: >
            WooCommerce payment method ID. Miguel sends `miguel`, the plugin's own payment
            gateway, which names these orders' payment method in the shop and is never offered
            at checkout.
          example: bacs
        payment_method_title:
          type: string
          description: >
            Payment method name stored on the order. When `payment_method` is `miguel` and this
            is missing or blank, the title of the plugin's Miguel gateway is used ("Miguel"
            unless renamed in WooCommerce → Settings → Payments). A non-blank value is kept as sent.
          example: Direct Bank Transfer
```

(Only the `CreateOrderRequest` block. The `Order` response schema has its own `payment_method_title` and stays as it is.)

- [x] **Step 6: Lint and commit**

Run: `docker compose -f docker-compose.test.yml run --rm --entrypoint vendor/bin/phpcs phpunit includes/class-miguel-order-create-api.php`
Expected: no errors.

```bash
git add includes/class-miguel-order-create-api.php tests/unit/test-order-create-api.php docs/openapi.yaml
git commit -m "feat(orders): name the Miguel payment method on orders Miguel creates"
```

---

### Task 3: Keep the block checkout quiet about the gateway

**Files:**
- Create: `includes/class-miguel-payment-gateway-blocks.php`
- Create: `assets/js/payment-method-blocks.js`
- Modify: `includes/class-miguel.php` (`init_hooks()`, the `before_woocommerce_init` closure, new method after `register_payment_gateway()`)
- Modify: `tests/unit/test-payment-gateway.php`

**Interfaces:**
- Consumes: `Miguel_Payment_Gateway::ID`, the `woocommerce_miguel_settings` option keys `enabled` / `title` (Task 1).
- Produces: `final class Miguel_Payment_Gateway_Blocks extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType` (script handle `miguel-payment-method-blocks`); `Miguel::register_payment_gateway_blocks( $registry ): void` on `woocommerce_blocks_payment_method_type_registration`.

Why this is needed (from the spec, checked against WooCommerce 9.9.5): the Checkout block editor flags every **enabled** gateway that has no payment method registered in **JavaScript** as "incompatible with block-based checkout". The Miguel gateway is enabled on purpose, so it registers a JS payment method whose `canMakePayment` is always false. In the editor any registered method counts as compatible; on the real checkout `canMakePayment` keeps it out, and the Store API still refuses it because `is_available()` is false.

- [ ] **Step 1: Write the failing tests**

Add to `tests/unit/test-payment-gateway.php` (inside the class, after the last test):

```php
	/**
	 * The block integration, registered through the plugin's hook callback.
	 *
	 * @return Miguel_Payment_Gateway_Blocks
	 */
	private function get_blocks_integration() {
		$registry = new \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry();
		Miguel::instance()->register_payment_gateway_blocks( $registry );

		$this->assertTrue( $registry->is_registered( Miguel_Payment_Gateway::ID ) );

		$integration = $registry->get_registered( Miguel_Payment_Gateway::ID );
		$integration->initialize();

		return $integration;
	}

	/**
	 * Registered with the block checkout under the gateway id.
	 */
	public function test_blocks_integration_registers_under_the_gateway_id() {
		$integration = $this->get_blocks_integration();

		$this->assertInstanceOf( Miguel_Payment_Gateway_Blocks::class, $integration );
		$this->assertSame( 'miguel', $integration->get_name() );
	}

	/**
	 * Active while the gateway is enabled, which is the default.
	 */
	public function test_blocks_integration_is_active_by_default() {
		$this->assertTrue( $this->get_blocks_integration()->is_active() );
	}

	/**
	 * Inactive when the merchant disables the gateway: nothing is flagged then either.
	 */
	public function test_blocks_integration_is_inactive_when_gateway_is_disabled() {
		update_option( 'woocommerce_miguel_settings', array( 'enabled' => 'no' ) );

		$this->assertFalse( $this->get_blocks_integration()->is_active() );
	}

	/**
	 * Its script is what registers the method in JavaScript.
	 */
	public function test_blocks_integration_provides_its_script() {
		$this->assertSame(
			array( 'miguel-payment-method-blocks' ),
			$this->get_blocks_integration()->get_payment_method_script_handles()
		);
		$this->assertTrue( wp_script_is( 'miguel-payment-method-blocks', 'registered' ) );
	}

	/**
	 * The script gets the gateway's title.
	 */
	public function test_blocks_integration_passes_the_title_to_its_script() {
		update_option(
			'woocommerce_miguel_settings',
			array(
				'enabled' => 'yes',
				'title'   => 'Zaplaceno v aplikaci',
			)
		);

		$data = $this->get_blocks_integration()->get_payment_method_data();

		$this->assertSame( 'Zaplaceno v aplikaci', $data['title'] );
		$this->assertSame( array( 'products' ), $data['supports'] );
	}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker compose -f docker-compose.test.yml run --rm phpunit --filter=Test_Miguel_Payment_Gateway`
Expected: FAIL — `Error: Call to undefined method Miguel::register_payment_gateway_blocks()`.

- [ ] **Step 3: Create the integration class**

Create `includes/class-miguel-payment-gateway-blocks.php`:

```php
<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Registers the Miguel payment method with the block checkout, so that it is never offered there.
 *
 * WooCommerce's Checkout block editor lists every enabled gateway that has no payment method
 * registered in JavaScript as incompatible with the block checkout. The Miguel gateway is
 * enabled on purpose (that is what lists it in the order screen's payment dropdown), so this
 * registers a method whose canMakePayment() is always false: the editor counts it as
 * compatible, and the checkout never offers it.
 *
 * @package Miguel
 */
final class Miguel_Payment_Gateway_Blocks extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {

	/**
	 * Payment method name; the gateway id.
	 *
	 * @var string
	 */
	protected $name = Miguel_Payment_Gateway::ID;

	/**
	 * Read the gateway's settings.
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_' . Miguel_Payment_Gateway::ID . '_settings', array() );
	}

	/**
	 * Active while the gateway is enabled — exactly when the editor would otherwise flag it.
	 *
	 * @return bool
	 */
	public function is_active() {
		return filter_var( $this->get_setting( 'enabled', 'yes' ), FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * The script that registers the method in JavaScript.
	 *
	 * @return array
	 */
	public function get_payment_method_script_handles() {
		wp_register_script(
			'miguel-payment-method-blocks',
			plugin_dir_url( MIGUEL_PLUGIN_FILE ) . 'assets/js/payment-method-blocks.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element' ),
			miguel()->version,
			true
		);

		return array( 'miguel-payment-method-blocks' );
	}

	/**
	 * Data the script reads.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		return array(
			'title'    => $this->get_setting( 'title', __( 'Miguel', 'miguel' ) ),
			'supports' => array( 'products' ),
		);
	}
}
```

- [ ] **Step 4: Create the script**

Create `assets/js/payment-method-blocks.js`:

```js
/**
 * Registers the Miguel payment method with the block checkout so that it is never offered.
 *
 * The Miguel gateway only names the orders Miguel creates itself. Registering it here keeps the
 * Checkout block editor from listing it as incompatible; canMakePayment() keeps it off the checkout.
 */
( function () {
	var registerPaymentMethod = window.wc.wcBlocksRegistry.registerPaymentMethod;
	var getSetting = window.wc.wcSettings.getSetting;
	var createElement = window.wp.element.createElement;

	var data = ( getSetting( 'paymentMethodData', {} ) || {} ).miguel || {};
	var title = data.title || 'Miguel';

	registerPaymentMethod( {
		name: 'miguel',
		label: createElement( 'span', null, title ),
		ariaLabel: title,
		content: createElement( 'span', null ),
		edit: createElement( 'span', null ),
		canMakePayment: function () {
			return false;
		},
		supports: {
			features: data.supports || [ 'products' ],
		},
	} );
} )();
```

- [ ] **Step 5: Register it, and declare block-checkout compatibility**

In `includes/class-miguel.php`, method `init_hooks()`, directly after the two lines added in Task 1 (`plugins_loaded` / `woocommerce_payment_gateways`), add:

```php
		$this->hook_manager->add_action( 'woocommerce_blocks_payment_method_type_registration', array( $this, 'register_payment_gateway_blocks' ) );
```

In the same method, inside the `before_woocommerce_init` closure, after the `declare_compatibility( 'custom_order_tables', … )` call and inside the same `class_exists` check, add:

```php
				// The only thing this plugin adds to the checkout is a payment method that is never offered there.
				\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', MIGUEL_PLUGIN_FILE, true );
```

Then add this public method directly after `register_payment_gateway()`:

```php
	/**
	 * Register the Miguel payment method with the block checkout.
	 *
	 * Included here rather than with the other files: its parent class belongs to WooCommerce,
	 * which is loaded by the time this hook fires.
	 *
	 * @param \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry Payment method registry.
	 */
	public function register_payment_gateway_blocks( $registry ) {
		include_once dirname( MIGUEL_PLUGIN_FILE ) . '/includes/class-miguel-payment-gateway-blocks.php';
		$registry->register( new Miguel_Payment_Gateway_Blocks() );
	}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `docker compose -f docker-compose.test.yml run --rm phpunit --filter=Test_Miguel_Payment_Gateway`
Expected: PASS (15 tests).

- [ ] **Step 7: Lint and commit**

Run: `docker compose -f docker-compose.test.yml run --rm --entrypoint vendor/bin/phpcs phpunit includes/class-miguel-payment-gateway-blocks.php includes/class-miguel.php tests/unit/test-payment-gateway.php`
Expected: no errors.

```bash
git add includes/class-miguel-payment-gateway-blocks.php assets/js/payment-method-blocks.js includes/class-miguel.php tests/unit/test-payment-gateway.php
git commit -m "feat(payment): register the Miguel method with the block checkout, which never offers it"
```

---

### Task 4: Release notes, full suite, and a check in a real shop

**Files:**
- Modify: `CHANGELOG.md`, `readme.txt`

**Interfaces:**
- Consumes: everything above.
- Produces: nothing for other tasks.

- [ ] **Step 1: Add the release notes**

In `CHANGELOG.md`, append to the end of the `## 1.10.0` list (after its last `*` line, before `## 1.9.1`):

```markdown
* Orders Miguel creates show "Miguel" as their payment method instead of "Other": the plugin now registers a Miguel payment gateway and names it on those orders when Miguel sends no title of its own. The gateway is never offered at checkout, classic or block, and supports no automatic refunds, since the money is taken by Miguel. Rename it in WooCommerce → Settings → Payments; disabling it only brings the "Other" label back
```

In `readme.txt`, append to the end of the `= 1.10.0 =` list (before the `[Full changelog]` line):

```
* Orders created by Miguel show "Miguel" as their payment method instead of "Other". The new Miguel payment gateway is never offered at checkout
```

- [ ] **Step 2: Run the full suite**

Run: `make test-docker`
Expected: all tests pass, no new warnings or deprecations in the output.

- [ ] **Step 3: Lint everything the branch touched**

Run: `docker compose -f docker-compose.test.yml run --rm --entrypoint vendor/bin/phpcs phpunit includes tests/unit/test-payment-gateway.php tests/unit/test-order-create-api.php`
Expected: no errors.

- [ ] **Step 4: Commit**

```bash
git add CHANGELOG.md readme.txt
git commit -m "docs: the Miguel payment gateway in the 1.10.0 notes"
```

- [ ] **Step 5: Check it in a real shop (manual; record the result in the PR description)**

Needs a WordPress ≥ 6.5 site with WooCommerce ≥ 8.3 (block checkout with compatibility declarations) and this branch's plugin active. `docker-compose.yml` starts one on `http://localhost:8000`, but its image is WordPress 6.4 — below the plugin's floor — so update WordPress from wp-admin → Updates first, install WooCommerce, and copy or symlink the plugin into `run/wordpress/wp-content/plugins/miguel`. Any other test shop works as well.

1. Create an order through the API as Miguel does (use the connection's bearer token):
   ```bash
   curl -s -X POST 'http://localhost:8000/?rest_route=/miguel/v1/orders' \
     -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
     -H 'Idempotency-Key: smp-13-manual-1' \
     -d '{"payment_method":"miguel","billing":{"first_name":"Test","email":"t@example.com"},"shipping":{"first_name":"Test"},"shipping_lines":[{"method_id":"free_shipping","total":"0.00"}],"line_items":[{"product_id":<ID>,"quantity":1}]}'
   ```
2. Open the order in wp-admin. Expected: header "Payment via Miguel" (Czech admin: "Platba přes Miguel"); clicking the billing edit pencil shows "Miguel" selected in the payment dropdown, not "Other"/"Jiná".
3. WooCommerce → Settings → Payments lists "Miguel", enabled. Rename it; the order header shows the new name.
4. Classic checkout (`[woocommerce_checkout]` page) and block checkout (Checkout block page) with a product in the cart: "Miguel" is not offered.
5. Edit the Checkout block page in the block editor: no "incompatible with block-based checkout" notice mentions Miguel. Expected: none. If one does, record it in the PR with a screenshot — the design's reasoning (spec, "Block checkout") would then be wrong for that WooCommerce version and needs revisiting, not a workaround.
6. Plugins screen: no block-checkout incompatibility warning for Miguel.

If no browser or shop is available to the implementer, list steps 1–6 in the PR description as **pending manual verification** rather than skipping them silently.

---

## Self-review

- **Spec coverage:** gateway class and its settings (Task 1); never at checkout — `is_available()` (Task 1) and block checkout (Task 3); no refunds, fails closed (Task 1); registration timing and outside the tests block (Task 1); `cart_checkout_blocks` declaration (Task 3); title fill-in, verbatim title, other methods untouched, renamed gateway, fallback (Task 2); OpenAPI (Task 2); catalogues (Task 1); CHANGELOG/readme (Task 4); manual checks (Task 4). Existing orders need no code: the header and dropdown read the registered gateway. Back-fill, transaction id and per-app titles are out of scope.
- **Names used across tasks:** `Miguel_Payment_Gateway::ID`, `Miguel::include_payment_gateway()`, `Miguel::register_payment_gateway()`, `Miguel::register_payment_gateway_blocks()`, `Miguel_Payment_Gateway_Blocks`, script handle `miguel-payment-method-blocks`, option `woocommerce_miguel_settings`, `prepare_payment_method_title_for_wc_order()`.
