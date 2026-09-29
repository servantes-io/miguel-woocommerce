# SMP-18 — Product Pairing Table and Case-Insensitive Code Map — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A read-only "Product pairing" section on the WooCommerce → Settings → Miguel tab lists every product code from both sides (paired / only in the e-shop / only in Miguel), and the plugin's product code map groups and looks codes up without regard to letter case, as Miguel does.

**Architecture:** `Miguel_Product_Code_Resolver` stays the one place that turns shop products into codes; it gains `normalize_code()` (lower-case, like Miguel's `ToLower`) and groups/looks up by it, keeping the first-seen spelling as the map key so nothing changes for codes that never differ in case. `Miguel_V2_Client::get_all_product_variants()` walks `GET v2/product-variants` through `meta.nextPage`. A new `Miguel_Product_Pairing` joins the two by the normalized code and returns rows (or a `WP_Error`); `Miguel_Settings` renders them in a new section, hides the save button there and never saves or connects from it.

**Tech Stack:** PHP 8.1, WordPress, WooCommerce (tests run 9.9.5), PHPUnit 9.6 via the WooCommerce unit-test framework, Docker.

**Spec:** YouTrack SMP-18 (the issue's "požadavek" and its twelve acceptance criteria). No Miguel change: the endpoint (`ProductVariantController.GetProductVariants`, `[AuthorizePublisher]`, the same controller as the watermarked-file call the plugin already makes) returns `{ data: [ { code, name, product: { title, … }, … } ], meta: { currentPage, nextPage, totalPages, … } }`, `limit` clamped to 200.

## Global Constraints

- **Tests run in Docker only, from the worktree root.** Full suite: `docker compose -p miguel-woocommerce -f docker-compose.test.yml run --rm phpunit`; one class or test: append `--filter=<name>`. `vendor/` must be present in the worktree (copy it from the main checkout); `-p miguel-woocommerce` reuses the cached WordPress/WooCommerce volumes. Baseline: 267 tests, all green.
- **phpcs** on every touched file, both `phpcs.xml` and `phpcs-woocommerce.xml` (the CI ruleset; errors must be 0): `docker compose -p miguel-woocommerce -f docker-compose.test.yml run --rm --no-deps --entrypoint vendor/bin/phpcs phpunit --standard=<ruleset> <files>`.
- **WordPress coding standards:** tabs, spaces inside parentheses, Yoda conditions, `array()`, docblocks with types, `ABSPATH` guard.
- **Comparison rule, one definition:** `Miguel_Product_Code_Resolver::normalize_code( $code )` = `mb_strtolower( (string) $code, 'UTF-8' )`. The map, the order lookup and the table all use it.
- **The map's keys and `product_code` values are unchanged for codes that never differ in case** — the key is the first spelling seen (products are walked by ascending ID), built through the same `$product_code_entries[ $code ]` array as before, so a numeric SKU still comes out as the same PHP key it did.
- **The page writes nothing:** only `GET` requests to Miguel, no option or product write, no connect call; `save()` on the section returns before `save_fields()`/`connect_to_miguel_api()`.
- **SKUs cannot differ only in case in tests**: WooCommerce's unique-SKU check is a MySQL comparison and case-insensitive. Use the `_miguel_code` meta for case-differing codes.
- New user-facing strings get a Czech `msgstr` in `languages/miguel-cs_CZ.po`.

## Review Focus

- A Miguel product without a `code` (or a non-array item) in `data` — skipped, not a PHP warning. Covered in Task 3's pairing test data.
- `meta.nextPage` pointing backwards or to the same page — the loop stops instead of spinning. Guarded by `$next > $page` and a page cap; not separately tested.
- The not-configured path must make no HTTP request at all. Asserted in Task 3.
- A POST to the pairing section (someone presses Enter in a form) must not save fields or call connect. Asserted in Task 4.
- A code that is numeric (`"123"`) must still resolve after the index is added. Covered by normalizing both the stored key and the lookup through the same function.

---

## File Structure

| Action | Path | Responsibility |
|---|---|---|
| Modify | `includes/class-miguel-product-code-resolver.php` | `normalize_code()`, case-insensitive grouping and lookup |
| Modify | `includes/api/v2/class-miguel-v2-client.php` | `get_all_product_variants()` |
| Create | `includes/class-miguel-product-pairing.php` | `Miguel_Product_Pairing::get_rows()` |
| Modify | `includes/class-miguel.php` | include the new file |
| Modify | `includes/admin/class-miguel-settings.php` | the section, its render, the save guard |
| Modify | `languages/miguel-cs_CZ.po`, `CHANGELOG.md`, `readme.txt` | Czech strings, 1.10.0 notes |
| Modify | `tests/unit/test-product-code-resolver.php`, `test-product-code-map-api.php`, `test-order-create-api.php`, `test-v2-client.php`, `test-settings.php` | behaviour |
| Create | `tests/unit/test-product-pairing.php` | the join |

---

### Task 1: Case-insensitive code map

**Interfaces — Produces:** `Miguel_Product_Code_Resolver::normalize_code( string $code ): string` (public static).

- [ ] Failing tests: `resolve_product_code( 'abc-1' )` finds a product whose `_miguel_code` is `ABC-1`; two products with `_miguel_code` `ABC-1` / `abc-1` are ONE details entry (`is_unique` false, `match_count` 2, key `ABC-1` from the lower ID) and resolving either spelling is `product_code.ambiguous`; `product-code-map` reports `count` 1 / `duplicate_count` 1 for that pair; `prepare_payload_for_wc_order` maps `product_code` `DUMMY-NAME` to the `dummy-name` product and rejects a case-differing pair with `product_code.ambiguous`.
- [ ] Run, see them fail (`product_code.not_found`, two entries).
- [ ] Implement: in `collect_product_code_details_map()`, keep `$display_codes[ normalize ] = first spelling`, and append product IDs to `$product_code_entries[ $display_codes[ normalize ] ]`; in `get_product_code_details_map()` build `$this->product_code_index[ normalize( key ) ] = key`; `resolve_product_code()` looks up through the index.
- [ ] Run the resolver, map API and order-create classes: all green.

### Task 2: Fetch every Miguel product variant

**Interfaces — Produces:** `Miguel_V2_Client::get_all_product_variants(): array|WP_Error` — the concatenated `data` items (arrays) of every page.

- [ ] Failing tests: two pages mocked on URL patterns `page=1&` / `page=2&` (`meta.nextPage` 2 then null) → three variants, two `GET` requests to `…/v2/product-variants?page=N&limit=200` with the bearer token; a 401 → `WP_Error` `miguel.http_401`; a body without `data` → `WP_Error`.
- [ ] Implement: loop `send( 'GET', 'v2/product-variants?' . http_build_query( page, limit ) )`, non-200 → `problem_to_wp_error()`, stop when `meta.nextPage` is not an int greater than the current page, cap at `PRODUCT_VARIANTS_MAX_PAGES` (1000) pages → `WP_Error`.

### Task 3: The pairing join

**Interfaces — Consumes:** Tasks 1 and 2. **Produces:** `Miguel_Product_Pairing::get_rows(): array|WP_Error`; each row `array{ status: 'paired'|'eshop_only'|'miguel_only', code: string, product_ids: int[], is_duplicate: bool, miguel_code: ?string, miguel_name: ?string }`, sorted by normalized code. Constants `STATUS_PAIRED`, `STATUS_ESHOP_ONLY`, `STATUS_MIGUEL_ONLY`.

- [ ] Failing tests (`tests/unit/test-product-pairing.php`): with the API key set and variants `paired-1`, `abc-1`, `miguel-only` (plus one item with no code) against shop codes `paired-1`, `ABC-1`, `shop-only` → `paired-1` paired, `ABC-1` paired with `miguel_code` `abc-1`, `shop-only` eshop_only, `miguel-only` miguel_only with its name "Title (eBook)"; a case-differing shop pair is one row with `is_duplicate` and both product IDs; two API pages both land in the rows; no API key → `WP_Error` and zero HTTP requests; a 500 → `WP_Error` whose message names the failure.
- [ ] Implement `includes/class-miguel-product-pairing.php`; include it from `Miguel::includes()`.

### Task 4: The admin section

- [ ] Failing tests in `test-settings.php`: `get_sections()` has `product-pairing`; `output()` with `$current_section = 'product-pairing'` renders a `<table>` with the code, the translated status label, the shop product's name and its edit link (as an administrator) and the Miguel name, and sets `$GLOBALS['hide_save_button']`; with no API key it renders an error notice and no table; `save()` in that section makes no HTTP request and leaves `miguel_api_connected` untouched, and every request the render makes is a `GET`.
- [ ] Implement: `PRODUCT_PAIRING_SECTION`, `get_own_sections()`, the branch in `output()` and `save()`, `output_product_pairing()` (escaped output, `get_edit_post_link()` of the parent for a variation).
- [ ] Czech strings, CHANGELOG and readme 1.10.0 entries; full suite; phpcs both rulesets.
