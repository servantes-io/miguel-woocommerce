# Printed-Book / E-book Product-Code Collision — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let printed books (SKU = `<slug>`) be sold through Miguel alongside their e-book twins (Miguel code = `<slug>` from a shortcode) without a code collision and without changing any existing eshop data.

**Architecture:** Introduce one shared extractor, `Miguel_Product_Code_Source`, that is the single source of truth for "what Miguel code(s) does this product expose." A printed (non-downloadable) product with SKU `<slug>` is exposed as `<slug><SUFFIX>`; the e-book keeps bare `<slug>`. The suffix is applied only at extraction; the resolver's `code → product_id` map is built from these codes, so resolution stays a symmetric map lookup. Four existing consumers (resolver, products API, order mapper, orders-API GET) stop hand-rolling extraction and call the shared source.

**Tech Stack:** PHP 7.4+, WooCommerce, WP_REST_Response/WP_Error, WP_UnitTestCase via WC test framework, PHPUnit. Tests run in Docker.

## Global Constraints

- **Tests run via Docker only.** Full suite: `make test-docker`. Single class: `docker compose -f docker-compose.test.yml run --rm phpunit --filter=<ClassName>`. Never call `vendor/bin/phpunit` directly (per project convention).
- **PHP 7.4 / WordPress coding standards.** Tabs for indentation, Yoda conditions, `esc_html__( ..., 'miguel' )` for i18n, guard files with `if ( ! defined( 'ABSPATH' ) ) { exit; }`. Use `function () { ... }` closures (not needed elsewhere) — match the surrounding style.
- **Discriminator:** `WC_Product::is_downloadable()` true ⇒ digital; false ⇒ printed/physical.
- **Suffix:** stored in option `miguel_print_code_suffix` (no default), read through the `miguel_print_code_suffix` filter. Empty suffix ⇒ the print rule never fires.
- **Digital-by-SKU fallback is resolver-only.** Only `Miguel_Product_Code_Resolver` passes `$include_digital_sku_fallback = true`. The products API, order mapper, and orders-API GET must NOT enable it (they were shortcode-only before and must stay that way apart from the new print rule).
- **Test SKU-uniqueness gotcha:** `WC_Helper_Product::create_simple_product()` and `Miguel_Helper_Product::create_downloadable_product()` both save with SKU `DUMMY SKU`. WooCommerce rejects a duplicate SKU on save. When a test needs two products, give the first a unique SKU (and save it) BEFORE creating the second, and set every print/SKU product's SKU explicitly.
- **No eshop data migration.** `_miguel_code` override meta is optional and additive; nothing back-fills it.

---

## File Structure

| Action | Path | Responsibility |
|--------|------|----------------|
| Create | `includes/class-miguel-product-code-source.php` | `Miguel_Product_Code_Source` — the single product→code extractor |
| Modify | `includes/class-miguel.php` | Include the new file |
| Modify | `includes/admin/class-miguel-settings.php` | Add the "Printed-book code suffix" setting |
| Modify | `includes/class-miguel-product-code-resolver.php` | Delegate extraction to the source (with SKU fallback) |
| Modify | `includes/class-miguel-products-api.php` | Emit print items + `code` field via the source |
| Modify | `includes/api/v2/mappers/class-miguel-order-mapper.php` | Replace `is_downloadable()` gate with the source; preserve bundles |
| Modify | `includes/class-miguel-orders-api.php` | Replace `is_downloadable()` gate with the source |
| Create | `tests/unit/test-product-code-source.php` | Rule-table tests for the source |
| Create | `tests/unit/test-settings.php` | Assert the suffix field is registered |
| Create | `tests/unit/test-products-api.php` | Print item + `code` field tests |
| Modify | `tests/unit/test-product-code-resolver.php` | Update SKU-fallback test; add print-collision test |
| Modify | `tests/unit/test-order-mapper.php` | Add print-sync test |
| Modify | `tests/unit/test-orders-api.php` | Add print line-item test |

**Note — `Miguel_Order_Create_Api` needs no change.** It resolves via `Miguel_Product_Code_Resolver`, whose map now contains `<slug><SUFFIX>` keys, so a `product_code` of `harry-potter:print` resolves to the print product automatically. This is covered by the resolver tests in Task 3.

---

## Task 1: The shared extractor `Miguel_Product_Code_Source`

**Files:**
- Create: `includes/class-miguel-product-code-source.php`
- Modify: `includes/class-miguel.php` (add include)
- Test: `tests/unit/test-product-code-source.php`

**Interfaces:**
- Produces:
  - `const Miguel_Product_Code_Source::SUFFIX_OPTION = 'miguel_print_code_suffix'`
  - `static Miguel_Product_Code_Source::get_print_suffix(): string`
  - `Miguel_Product_Code_Source::get_items( WC_Product $product, bool $include_digital_sku_fallback = false ): array` — list of `array{ code:string, book_id:string, format:string, type:string }` (`type` is `'digital'` or `'print'`)
  - `Miguel_Product_Code_Source::get_codes( WC_Product $product, bool $include_digital_sku_fallback = false ): string[]` — unique `code` values

- [ ] **Step 1: Write the failing test**

Create `tests/unit/test-product-code-source.php`:

```php
<?php
/**
 * Test Miguel_Product_Code_Source.
 *
 * @package Miguel\Tests
 */
class Test_Miguel_Product_Code_Source extends Miguel_Test_Case {

	public function test_digital_shortcode_product_exposes_book_id_format_and_code() {
		$product = Miguel_Helper_Product::create_downloadable_product();
		$source  = new Miguel_Product_Code_Source();

		$items   = $source->get_items( $product );
		$formats = array_column( $items, 'format' );

		$this->assertNotEmpty( $items );
		foreach ( $items as $item ) {
			$this->assertSame( 'dummy-name', $item['book_id'] );
			$this->assertSame( 'digital', $item['type'] );
			$this->assertSame( $item['book_id'], $item['code'] ); // code == book_id for digital
		}
		$this->assertContains( 'epub', $formats );
		$this->assertContains( 'mobi', $formats );
	}

	public function test_get_codes_deduplicates_multi_format_shortcodes() {
		$product = Miguel_Helper_Product::create_downloadable_product();
		$source  = new Miguel_Product_Code_Source();

		$this->assertSame( array( 'dummy-name' ), $source->get_codes( $product ) );
	}

	public function test_physical_product_exposes_suffixed_print_code() {
		add_filter( 'miguel_print_code_suffix', function () {
			return ':print';
		} );

		$product = WC_Helper_Product::create_simple_product();
		$product->set_downloadable( false );
		$product->set_virtual( false );
		$product->set_sku( 'harry-potter' );
		$product->save();

		$items = ( new Miguel_Product_Code_Source() )->get_items( $product );

		$this->assertCount( 1, $items );
		$this->assertSame( 'harry-potter:print', $items[0]['code'] );
		$this->assertSame( 'harry-potter', $items[0]['book_id'] );
		$this->assertSame( 'print', $items[0]['format'] );
		$this->assertSame( 'print', $items[0]['type'] );
	}

	public function test_physical_product_exposes_nothing_when_suffix_empty() {
		$product = WC_Helper_Product::create_simple_product();
		$product->set_downloadable( false );
		$product->set_sku( 'harry-potter' );
		$product->save();

		$this->assertSame( array(), ( new Miguel_Product_Code_Source() )->get_codes( $product ) );
	}

	public function test_override_meta_is_used_verbatim() {
		add_filter( 'miguel_print_code_suffix', function () {
			return ':print';
		} );

		$product = WC_Helper_Product::create_simple_product();
		$product->set_sku( 'harry-potter' );
		$product->save();
		update_post_meta( $product->get_id(), '_miguel_code', 'custom-code-123' );

		$items = ( new Miguel_Product_Code_Source() )->get_items( $product );

		$this->assertCount( 1, $items );
		$this->assertSame( 'custom-code-123', $items[0]['code'] ); // no suffix appended
		$this->assertSame( 'custom-code-123', $items[0]['book_id'] );
	}

	public function test_digital_sku_fallback_only_when_enabled() {
		$product = WC_Helper_Product::create_simple_product();
		$product->set_virtual( true );
		$product->set_downloadable( true );
		$product->set_sku( 'ebook-by-sku' );
		$product->save();

		$source = new Miguel_Product_Code_Source();

		$this->assertSame( array(), $source->get_codes( $product ) );                   // default: no fallback
		$this->assertSame( array( 'ebook-by-sku' ), $source->get_codes( $product, true ) ); // resolver mode
	}

	public function test_non_downloadable_without_sku_exposes_nothing() {
		add_filter( 'miguel_print_code_suffix', function () {
			return ':print';
		} );

		$product = WC_Helper_Product::create_simple_product();
		$product->set_downloadable( false );
		$product->set_sku( '' );
		$product->save();

		$this->assertSame( array(), ( new Miguel_Product_Code_Source() )->get_codes( $product ) );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose -f docker-compose.test.yml run --rm phpunit --filter=Test_Miguel_Product_Code_Source`
Expected: FAIL — `Class "Miguel_Product_Code_Source" not found`.

- [ ] **Step 3: Create the source class**

Create `includes/class-miguel-product-code-source.php`:

```php
<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Single source of truth for the Miguel code(s) a WooCommerce product exposes.
 *
 * @package Miguel
 */
class Miguel_Product_Code_Source {

	/**
	 * Option holding the printed-book code suffix.
	 */
	const SUFFIX_OPTION = 'miguel_print_code_suffix';

	/**
	 * Get the configured printed-book code suffix.
	 *
	 * @return string
	 */
	public static function get_print_suffix() {
		$suffix = get_option( self::SUFFIX_OPTION, '' );
		$suffix = is_string( $suffix ) ? $suffix : '';

		$filtered = apply_filters( 'miguel_print_code_suffix', $suffix );

		return is_string( $filtered ) ? $filtered : '';
	}

	/**
	 * Get the Miguel code entries a product exposes.
	 *
	 * @param WC_Product $product                       Product object.
	 * @param bool       $include_digital_sku_fallback  Whether a downloadable product with no shortcode falls back to its bare SKU. Resolver only.
	 * @return array List of array{ code:string, book_id:string, format:string, type:string }.
	 */
	public function get_items( $product, $include_digital_sku_fallback = false ) {
		if ( ! ( $product instanceof WC_Product ) ) {
			return array();
		}

		// Rule 1: explicit override, used verbatim.
		$override = $product->get_meta( '_miguel_code', true );
		$override = is_string( $override ) ? trim( $override ) : '';
		if ( '' !== $override ) {
			return array(
				array(
					'code'    => $override,
					'book_id' => $override,
					'format'  => '',
					'type'    => $product->is_downloadable() ? 'digital' : 'print',
				),
			);
		}

		// Rule 2: digital codes from Miguel shortcodes.
		$shortcode_items = $this->get_shortcode_items( $product );
		if ( ! empty( $shortcode_items ) ) {
			return $shortcode_items;
		}

		$sku = (string) $product->get_sku();

		if ( $product->is_downloadable() ) {
			// Rule 3: digital-by-SKU fallback (resolver only).
			if ( $include_digital_sku_fallback && '' !== $sku ) {
				return array(
					array(
						'code'    => $sku,
						'book_id' => $sku,
						'format'  => '',
						'type'    => 'digital',
					),
				);
			}

			return array();
		}

		// Rule 4: printed book — non-downloadable + SKU + configured suffix.
		$suffix = self::get_print_suffix();
		if ( '' === $sku || '' === $suffix ) {
			return array();
		}

		return array(
			array(
				'code'    => $sku . $suffix,
				'book_id' => $sku,
				'format'  => 'print',
				'type'    => 'print',
			),
		);
	}

	/**
	 * Get the unique Miguel codes a product exposes.
	 *
	 * @param WC_Product $product                       Product object.
	 * @param bool       $include_digital_sku_fallback  See get_items().
	 * @return array List of unique code strings.
	 */
	public function get_codes( $product, $include_digital_sku_fallback = false ) {
		$codes = array();

		foreach ( $this->get_items( $product, $include_digital_sku_fallback ) as $item ) {
			if ( ! in_array( $item['code'], $codes, true ) ) {
				$codes[] = $item['code'];
			}
		}

		return $codes;
	}

	/**
	 * Extract digital shortcode items from a product's downloadable files.
	 *
	 * @param WC_Product $product Product object.
	 * @return array List of digital entries, deduplicated by book id + format.
	 */
	private function get_shortcode_items( $product ) {
		$items = array();
		$seen  = array();

		foreach ( $product->get_downloads() as $download ) {
			$file = '';
			if ( is_array( $download ) && isset( $download['file'] ) ) {
				$file = $download['file'];
			} elseif ( is_object( $download ) && method_exists( $download, 'get_file' ) ) {
				$file = $download->get_file();
			}

			if ( empty( $file ) || ! Miguel_Order_Utils::is_miguel_shortcode( $file ) ) {
				continue;
			}

			$atts = Miguel_Order_Utils::parse_shortcode_atts( $file );
			if ( ! $atts || empty( $atts['id'] ) ) {
				continue;
			}

			$code   = $atts['id'];
			$format = isset( $atts['format'] ) ? $atts['format'] : '';
			$key    = $code . '|' . $format;
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;

			$items[] = array(
				'code'    => $code,
				'book_id' => $code,
				'format'  => $format,
				'type'    => 'digital',
			);
		}

		return $items;
	}
}
```

- [ ] **Step 4: Wire the include**

In `includes/class-miguel.php`, in `includes()`, immediately after the line:

```php
		include_once dirname( MIGUEL_PLUGIN_FILE ) . '/includes/class-miguel-order-utils.php';
```

add:

```php
		include_once dirname( MIGUEL_PLUGIN_FILE ) . '/includes/class-miguel-product-code-source.php';
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `docker compose -f docker-compose.test.yml run --rm phpunit --filter=Test_Miguel_Product_Code_Source`
Expected: All tests PASS.

- [ ] **Step 6: Commit**

```bash
git add includes/class-miguel-product-code-source.php includes/class-miguel.php tests/unit/test-product-code-source.php
git commit -m "feat: add Miguel_Product_Code_Source shared extractor"
```

---

## Task 2: Printed-book code suffix setting

**Files:**
- Modify: `includes/admin/class-miguel-settings.php`
- Test: `tests/unit/test-settings.php`

**Interfaces:**
- Consumes: `Miguel_Product_Code_Source::SUFFIX_OPTION` (Task 1)

- [ ] **Step 1: Write the failing test**

Create `tests/unit/test-settings.php`:

```php
<?php
/**
 * Test Miguel_Settings.
 *
 * @package Miguel\Tests
 */
class Test_Miguel_Settings extends Miguel_Test_Case {

	public function test_settings_include_print_code_suffix_field() {
		$settings = ( new Miguel_Settings( new Miguel_Hook_Manager() ) )->get_settings();

		$ids = array_column( $settings, 'id' );
		$this->assertContains( Miguel_Product_Code_Source::SUFFIX_OPTION, $ids );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose -f docker-compose.test.yml run --rm phpunit --filter=Test_Miguel_Settings`
Expected: FAIL — the assertion does not find the suffix id.

- [ ] **Step 3: Add the setting field**

In `includes/admin/class-miguel-settings.php`, inside `get_settings()`, add this entry to the array immediately after the `Miguel_Orders::SEND_EMAIL_OPTION` checkbox entry (before the `sectionend` entry):

```php
				array(
					'id'      => Miguel_Product_Code_Source::SUFFIX_OPTION,
					'css'     => 'min-width: 350px;',
					'type'    => 'text',
					'title'   => __( 'Printed-book code suffix', 'miguel' ),
					'desc'    => __( 'Appended to a printed book\'s SKU to form its Miguel product code — e.g. ":print" makes SKU "harry-potter" resolve as "harry-potter:print". Leave empty to disable printed-book pairing. Must match the suffix configured on the Miguel platform.', 'miguel' ),
					'default' => '',
				),
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose -f docker-compose.test.yml run --rm phpunit --filter=Test_Miguel_Settings`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/admin/class-miguel-settings.php tests/unit/test-settings.php
git commit -m "feat: add printed-book code suffix setting"
```

---

## Task 3: Resolver delegates to the source

**Files:**
- Modify: `includes/class-miguel-product-code-resolver.php`
- Test: `tests/unit/test-product-code-resolver.php`

**Interfaces:**
- Consumes: `Miguel_Product_Code_Source::get_codes( $product, true )` (Task 1)

- [ ] **Step 1: Update the resolver test (rewrite the SKU-fallback case, add print-collision case)**

In `tests/unit/test-product-code-resolver.php`, REPLACE the method `test_get_product_code_details_map_reads_shortcodes_and_sku_fallback()` with the two methods below. Leave `test_debug_log_is_disabled_by_default()` unchanged.

```php
	/**
	 * Shortcode products map by their shortcode id; a downloadable product with
	 * no shortcode falls back to its bare SKU (resolver-only fallback).
	 */
	public function test_maps_shortcode_and_digital_sku_fallback() {
		$shortcode_product = Miguel_Helper_Product::create_downloadable_product();
		$shortcode_product->set_sku( 'shortcode-product-sku' ); // unique SKU, irrelevant (shortcode wins)
		$shortcode_product->save();

		$sku_product = WC_Helper_Product::create_simple_product();
		$sku_product->set_virtual( true );
		$sku_product->set_downloadable( true );
		$sku_product->set_sku( 'sku-fallback-1' );
		$sku_product->save();

		$details_map = ( new Miguel_Product_Code_Resolver() )->get_product_code_details_map();

		$this->assertArrayHasKey( 'dummy-name', $details_map );
		$this->assertEquals( $shortcode_product->get_id(), $details_map['dummy-name']['product_id'] );
		$this->assertArrayHasKey( 'sku-fallback-1', $details_map );
		$this->assertEquals( $sku_product->get_id(), $details_map['sku-fallback-1']['product_id'] );
	}

	/**
	 * An e-book and a printed book that share a slug resolve to distinct products.
	 */
	public function test_ebook_and_print_sharing_slug_resolve_to_distinct_products() {
		add_filter( 'miguel_print_code_suffix', function () {
			return ':print';
		} );

		// E-book: downloadable, shortcode id "harry-potter". Give it a unique SKU first.
		$ebook = Miguel_Helper_Product::create_downloadable_product();
		$ebook->set_sku( 'harry-potter-ebook-sku' );
		$ebook->save();
		Miguel_Helper_Product::set_product_downloads_bypass_validation(
			$ebook,
			array(
				'hp_epub_' . wp_generate_uuid4() => array(
					'name' => 'HP epub',
					'file' => '[miguel id="harry-potter" format="epub"]',
				),
			)
		);

		// Printed book: non-downloadable, SKU "harry-potter".
		$print = WC_Helper_Product::create_simple_product();
		$print->set_downloadable( false );
		$print->set_virtual( false );
		$print->set_sku( 'harry-potter' );
		$print->save();

		$resolver  = new Miguel_Product_Code_Resolver();
		$ebook_res = $resolver->resolve_product_code( 'harry-potter' );
		$print_res = $resolver->resolve_product_code( 'harry-potter:print' );

		$this->assertSame( $ebook->get_id(), $ebook_res['product_id'] );
		$this->assertTrue( $ebook_res['is_unique'] );
		$this->assertSame( $print->get_id(), $print_res['product_id'] );
		$this->assertTrue( $print_res['is_unique'] );
	}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose -f docker-compose.test.yml run --rm phpunit --filter=Test_Miguel_Product_Code_Resolver`
Expected: FAIL — `harry-potter:print` is not yet in the map (still bare-SKU behavior), and/or the non-downloadable SKU is mis-mapped.

- [ ] **Step 3: Delegate extraction to the source**

In `includes/class-miguel-product-code-resolver.php`:

(a) Add a property and constructor. Immediately after the `$product_code_details_map` property declaration block (before `get_product_code_map()`), add:

```php
	/**
	 * Product code source.
	 *
	 * @var Miguel_Product_Code_Source
	 */
	private $code_source;

	/**
	 * Constructor.
	 *
	 * @param Miguel_Product_Code_Source|null $code_source Product code source.
	 */
	public function __construct( $code_source = null ) {
		$this->code_source = $code_source instanceof Miguel_Product_Code_Source ? $code_source : new Miguel_Product_Code_Source();
	}
```

(b) Replace the entire body of `get_product_codes_from_product()` with a delegation (keep the method signature and docblock):

```php
	private function get_product_codes_from_product( $product ) {
		return $this->code_source->get_codes( $product, true );
	}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose -f docker-compose.test.yml run --rm phpunit --filter=Test_Miguel_Product_Code_Resolver`
Expected: All tests PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/class-miguel-product-code-resolver.php tests/unit/test-product-code-resolver.php
git commit -m "refactor: resolver uses Miguel_Product_Code_Source; print codes namespaced"
```

---

## Task 4: Products API emits print items and a `code` field

**Files:**
- Modify: `includes/class-miguel-products-api.php`
- Test: `tests/unit/test-products-api.php`

**Interfaces:**
- Consumes: `Miguel_Product_Code_Source::get_items( $product )` (Task 1)
- Produces: each `miguel_items` entry now has keys `book_id`, `format`, `code`.

- [ ] **Step 1: Write the failing test**

Create `tests/unit/test-products-api.php`:

```php
<?php
/**
 * Test Miguel_Products_Api miguel_items extraction.
 *
 * @package Miguel\Tests
 */
class Test_Miguel_Products_Api extends Miguel_Test_Case {

	private function find_product( $data, $product_id ) {
		foreach ( $data['products'] as $p ) {
			if ( (int) $p['id'] === (int) $product_id ) {
				return $p;
			}
		}
		return null;
	}

	public function test_digital_items_include_code_equal_to_book_id() {
		$product = Miguel_Helper_Product::create_downloadable_product();

		$data  = ( new Miguel_Products_Api( new Miguel_Hook_Manager() ) )->get_products()->get_data();
		$found = $this->find_product( $data, $product->get_id() );

		$this->assertNotNull( $found );
		$this->assertNotEmpty( $found['miguel_items'] );
		foreach ( $found['miguel_items'] as $item ) {
			$this->assertArrayHasKey( 'code', $item );
			$this->assertSame( 'dummy-name', $item['book_id'] );
			$this->assertSame( $item['book_id'], $item['code'] );
		}
	}

	public function test_physical_product_yields_print_item_with_code() {
		add_filter( 'miguel_print_code_suffix', function () {
			return ':print';
		} );

		$product = WC_Helper_Product::create_simple_product();
		$product->set_downloadable( false );
		$product->set_virtual( false );
		$product->set_sku( 'printed-1' );
		$product->save();

		$data  = ( new Miguel_Products_Api( new Miguel_Hook_Manager() ) )->get_products()->get_data();
		$found = $this->find_product( $data, $product->get_id() );

		$this->assertNotNull( $found );
		$this->assertCount( 1, $found['miguel_items'] );
		$this->assertSame( 'printed-1:print', $found['miguel_items'][0]['code'] );
		$this->assertSame( 'printed-1', $found['miguel_items'][0]['book_id'] );
		$this->assertSame( 'print', $found['miguel_items'][0]['format'] );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose -f docker-compose.test.yml run --rm phpunit --filter=Test_Miguel_Products_Api`
Expected: FAIL — `miguel_items` entries lack `code`, and the physical product yields no items.

- [ ] **Step 3: Route the products API through the source**

In `includes/class-miguel-products-api.php`:

(a) Add a property and initialise it in the constructor. Replace the existing constructor with:

```php
	/**
	 * Product code source.
	 *
	 * @var Miguel_Product_Code_Source
	 */
	private $code_source;

	/**
	 * Constructor.
	 *
	 * @param Miguel_Hook_Manager_Interface $hook_manager Hook manager.
	 */
	public function __construct( Miguel_Hook_Manager_Interface $hook_manager ) {
		$this->hook_manager = $hook_manager;
		$this->code_source  = new Miguel_Product_Code_Source();
	}
```

(b) Replace the entire body of `get_miguel_items_from_product()` with:

```php
	private function get_miguel_items_from_product( $product ) {
		$items = array();

		foreach ( $this->code_source->get_items( $product ) as $entry ) {
			$items[] = array(
				'book_id' => $entry['book_id'],
				'format'  => $entry['format'],
				'code'    => $entry['code'],
			);
		}

		return $items;
	}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose -f docker-compose.test.yml run --rm phpunit --filter=Test_Miguel_Products_Api`
Expected: All tests PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/class-miguel-products-api.php tests/unit/test-products-api.php
git commit -m "feat: products API exposes print items and a code field"
```

---

## Task 5: Order mapper syncs printed books

**Files:**
- Modify: `includes/api/v2/mappers/class-miguel-order-mapper.php`
- Test: `tests/unit/test-order-mapper.php`

**Interfaces:**
- Consumes: `Miguel_Product_Code_Source::get_codes( $product )` (Task 1)

- [ ] **Step 1: Write the failing test**

In `tests/unit/test-order-mapper.php`, add this method inside the `Miguel_Test_Order_Mapper` class:

```php
	public function test_maps_physical_product_as_print_code(): void {
		add_filter( 'miguel_print_code_suffix', function () {
			return ':print';
		} );

		$product = WC_Helper_Product::create_simple_product();
		$product->set_downloadable( false );
		$product->set_virtual( false );
		$product->set_sku( 'printed-book-1' );
		$product->save();

		$order = wc_create_order( array( 'status' => 'processing' ) );
		$order->add_product( $product, 1 );
		$order->set_billing_email( 'test@melvil.cz' );
		$order->set_date_paid( '2023-01-15 10:00:00' );
		$order->save();

		$dto = ( new Miguel_Order_Mapper() )->map( $order );

		$this->assertInstanceOf( Miguel_V2_Order_Create::class, $dto );
		$codes = array_column( $dto->to_array()['items'], 'code' );
		$this->assertContains( 'printed-book-1:print', $codes );

		Miguel_Helper_Order::delete_order( $order->get_id() );
	}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose -f docker-compose.test.yml run --rm phpunit --filter=Miguel_Test_Order_Mapper`
Expected: FAIL — the mapper drops the non-downloadable item, so `printed-book-1:print` is absent (map returns null).

- [ ] **Step 3: Replace the `is_downloadable()` gate with the source**

In `includes/api/v2/mappers/class-miguel-order-mapper.php`:

(a) Add a property and constructor. Immediately after the class opening brace / docblock (before `map()`), add:

```php
	/**
	 * Product code source.
	 *
	 * @var Miguel_Product_Code_Source
	 */
	private $code_source;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->code_source = new Miguel_Product_Code_Source();
	}
```

(b) Replace the entire `get_miguel_products_from_item()` method with (bundle wrappers return only their bundled items; every other product is extracted through the source):

```php
	private function get_miguel_products_from_item( $product, $item_total ) {
		$bundle_ids = $product->get_meta( '_bundle_ids', true );
		if ( ! empty( $bundle_ids ) ) {
			return $this->get_miguel_products_from_bundle( $bundle_ids, $item_total );
		}

		$products = array();
		foreach ( $this->code_source->get_codes( $product ) as $code ) {
			$products[] = array(
				'code'       => $code,
				'sold_price' => $item_total,
			);
		}

		return $products;
	}
```

(c) Replace the entire `extract_all_miguel_codes()` method body (keep the docblock) so leaf extraction goes through the source:

```php
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

		foreach ( $this->code_source->get_codes( $product ) as $code ) {
			if ( ! in_array( $code, $miguel_codes, true ) ) {
				$miguel_codes[] = $code;
			}
		}

		return $miguel_codes;
	}
```

(d) Delete the now-unused private method `extract_miguel_codes_from_product()` entirely.

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose -f docker-compose.test.yml run --rm phpunit --filter=Miguel_Test_Order_Mapper`
Expected: All tests PASS (new print test plus the existing single/bundle/null tests).

- [ ] **Step 5: Commit**

```bash
git add includes/api/v2/mappers/class-miguel-order-mapper.php tests/unit/test-order-mapper.php
git commit -m "feat: order mapper syncs printed books via Miguel_Product_Code_Source"
```

---

## Task 6: Orders API GET reports printed books

**Files:**
- Modify: `includes/class-miguel-orders-api.php`
- Test: `tests/unit/test-orders-api.php`

**Interfaces:**
- Consumes: `Miguel_Product_Code_Source::get_codes( $product )` (Task 1)

- [ ] **Step 1: Write the failing test**

In `tests/unit/test-orders-api.php`, add this method inside the `Test_Miguel_Orders_Api` class:

```php
	public function test_get_order_includes_print_code_for_physical_product() {
		add_filter( 'miguel_print_code_suffix', function () {
			return ':print';
		} );

		$product = WC_Helper_Product::create_simple_product();
		$product->set_downloadable( false );
		$product->set_virtual( false );
		$product->set_sku( 'printed-book-9' );
		$product->save();

		$order = Miguel_Helper_Order::create_order();
		$order->add_product( $product, 1 );
		$order->save();

		$api     = new Miguel_Orders_Api( new Miguel_Hook_Manager() );
		$request = new WP_REST_Request( 'GET', '/miguel/v1/orders/' . $order->get_id() );
		$request->set_param( 'id', $order->get_id() );

		$data = $api->get_order( $request )->get_data();

		$this->assertContains( 'printed-book-9:print', array_column( $data['products'], 'code' ) );
		$this->assertContains( 'printed-book-9:print', array_column( $data['line_items'], 'code' ) );

		Miguel_Helper_Order::delete_order( $order->get_id() );
	}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose -f docker-compose.test.yml run --rm phpunit --filter=Test_Miguel_Orders_Api`
Expected: FAIL — the physical product is dropped by the `is_downloadable()` gate, so the print code is absent.

- [ ] **Step 3: Route order-item extraction through the source**

In `includes/class-miguel-orders-api.php`:

(a) Add a property and initialise it in the constructor. Replace the existing constructor with:

```php
	/**
	 * Product code source.
	 *
	 * @var Miguel_Product_Code_Source
	 */
	private $code_source;

	/**
	 * Constructor.
	 *
	 * @param Miguel_Hook_Manager_Interface $hook_manager Hook manager.
	 */
	public function __construct( Miguel_Hook_Manager_Interface $hook_manager ) {
		$this->hook_manager = $hook_manager;
		$this->code_source  = new Miguel_Product_Code_Source();
	}
```

(b) Replace the entire `get_miguel_codes_for_item()` method with (drop the `is_downloadable()` gate; delegate to the source):

```php
	private function get_miguel_codes_for_item( $item ) {
		if ( ! ( $item instanceof WC_Order_Item_Product ) ) {
			return array();
		}

		$product = $item->get_product();
		if ( ! $product ) {
			return array();
		}

		return $this->code_source->get_codes( $product );
	}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose -f docker-compose.test.yml run --rm phpunit --filter=Test_Miguel_Orders_Api`
Expected: All tests PASS (new print test plus existing line-item/dedup tests — the existing `null` code case still holds because no suffix is configured in those tests).

- [ ] **Step 5: Commit**

```bash
git add includes/class-miguel-orders-api.php tests/unit/test-orders-api.php
git commit -m "feat: orders API GET reports printed books via Miguel_Product_Code_Source"
```

---

## Task 7: Full-suite regression

**Files:** none (verification only).

- [ ] **Step 1: Run the entire suite**

Run: `make test-docker`
Expected: All tests PASS — no regressions in downloadable/e-book, audio, bundle, order-create, product-code-map, or orders behavior.

- [ ] **Step 2: If anything fails, fix and re-run**

Investigate failures against the Global Constraints (especially the SKU-uniqueness gotcha and the resolver-only SKU fallback). Fix inline, re-run `make test-docker` until green.

- [ ] **Step 3: Commit (only if fixes were needed)**

```bash
git add -A
git commit -m "test: fix regressions from printed-book code source refactor"
```

---

## Self-Review

### Spec coverage

| Spec requirement | Task |
|------------------|------|
| Single shared extractor `Miguel_Product_Code_Source` | Task 1 |
| Rule 1 override `_miguel_code` verbatim | Task 1 (`test_override_meta_is_used_verbatim`) |
| Rule 2 digital shortcode codes | Task 1 (`test_digital_shortcode_...`) |
| Rule 3 digital-by-SKU fallback, resolver-only | Task 1 + Task 3 |
| Rule 4 printed `<slug><SUFFIX>` | Task 1 + Tasks 4/5/6 |
| Empty suffix ⇒ no print codes | Task 1 (`test_physical_product_exposes_nothing_when_suffix_empty`) |
| Collision resolved (e-book vs print) | Task 3 (`test_ebook_and_print_sharing_slug_...`) |
| Suffix setting field, no default | Task 2 |
| Resolver + `/product-code-map` use source | Task 3 |
| Products API `code` field + print items | Task 4 |
| Order mapper (WooCommerce→Miguel) print sync | Task 5 |
| Orders API GET print reporting | Task 6 |
| Order-create resolves print codes | Covered by Task 3 (resolver map) — no code change |
| Existing errors (`not_found`/`ambiguous`) reused | No new code; validated by Task 3 uniqueness assertions |
| No regressions | Task 7 |

### Placeholder scan

No TBD/TODO/"handle edge cases"/"similar to above". Every code step contains complete code.

### Type consistency

- `Miguel_Product_Code_Source::get_items()`/`get_codes()` signatures identical across Tasks 1, 3, 4, 5, 6.
- `SUFFIX_OPTION` constant referenced identically in Tasks 1 and 2.
- Entry keys `code`/`book_id`/`format`/`type` consistent between the source (Task 1) and every consumer.
- `miguel_print_code_suffix` filter name identical in the source and every test.
- Resolver is the only caller passing `true` for `$include_digital_sku_fallback`; all others use the default `false` — matches the Global Constraint.
