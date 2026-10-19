# SMP-23: pickup point → carrier plugin meta Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** an order created through `POST miguel/v1/orders` with Miguel's neutral `_miguel_pickup_point` meta
entry carries the pickup point in the meta of the shop's active carrier plugin (Zásilkovna, Balíkovna, GLS)
before its status changes, so Shipmall exports it with the branch.

**Architecture:** a new `Miguel_Pickup_Point` class reads the entry out of the prepared payload's `meta_data`,
decides from `active_plugins` which carrier plugin's keys to write, and returns them. `create_order` appends
those keys to `meta_data` (so WooCommerce's REST controller stores them in the same save that creates the
order) and, for Zásilkovna, which reads `wp_postmeta` and is not HPOS aware, writes the same two keys with
`update_post_meta()` from a `woocommerce_new_order` callback added only around the controller call — that
action fires inside the data store's `create()`, before `WC_Order::save()` runs `status_transition()`. No
matching plugin (or an unusable entry) → a `warning` through `Miguel::log` (WooCommerce log, source `miguel`)
with the order number and the carrier, after the order exists.

**Tech Stack:** PHP, WordPress, WooCommerce 9.9.5 (tests run without HPOS by default; see Task 1), PHPUnit via `scripts/phpunit_docker.sh`.

**Spec:** YouTrack SMP-23 (criteria in the pull request); the contract is miguel#378 (EMMA-483): value
`{carrier: packeta|balikovna|gls, id, name, address?}`, `address` one string. Meta keys from SERGEN-32
("Order meta dopravců a výdejních míst", verified against the plugins' code 2026-10-15).

## Global Constraints

- Zásilkovna (`wc-zasilkovna`): `_wc_zasilkovna_point_id`, `_wc_zasilkovna_point_name` (wp_postmeta);
  Shipmall's `ftx_shm_packeta_id`, `ftx_shm_packeta_name` (order meta).
- Balíkovna (`toret-ceska-posta`): `tcp_pickup_id` = `tcp_pickup_linkid` = the id with a leading `b`
  (added only when missing), `tcp_pickup_name` = `tcp_pickup_parcelshopname` = name, `tcp_pickup_adresa`
  = address when there is one.
- GLS (`wpify-woo-gls`): `_gls_id` = id; `_gls_data` = `array( id, name, address, zipcode => '', city => '' )`.
- Active = an `active_plugins` entry (or a network-active one) whose directory is the slug above.
- The neutral entry always stays; a request without it is untouched; an idempotent replay writes nothing.

## Review Focus

- A Balíkovna id that already starts with `b` must not become `bb…` → test `balikovna_keeps_prefixed_id`.
- `address` absent or null (optional in the contract) must not write an empty `tcp_pickup_adresa` or warn →
  covered by the GLS/Balíkovna tests sending no address in one case.
- The `woocommerce_new_order` callback must not outlive the request (a later order in the same PHP process
  would get Zásilkovna meta) → removed on its first run and in `finally`; tests `post_meta_callback_is_removed_after_the_request`
  and `order_created_by_another_plugin_during_the_request_gets_no_post_meta`.
- A value that is not an object or lacks `id` → treated as untranslatable, warning, order still created.
- A plugin directory that merely starts with the slug (`wc-zasilkovna-pro/`) is not that plugin → match on
  `slug/` exactly.

---

### Task 1: `Miguel_Pickup_Point` and its use in `create_order`

**Files:**
- Create: `includes/class-miguel-pickup-point.php`
- Modify: `includes/class-miguel.php` (include it beside `class-miguel-order-create-api.php`)
- Modify: `includes/class-miguel-order-create-api.php` (`create_order`)
- Test: `tests/unit/test-pickup-point.php`
- Modify: `CHANGELOG.md` (1.11.0 is unreleased)

**Interfaces:**
- `Miguel_Pickup_Point::META_KEY = '_miguel_pickup_point'`
- `Miguel_Pickup_Point::from_payload( array $payload ): ?Miguel_Pickup_Point` — null when `meta_data` has no
  entry with that key.
- `->carrier(): string` — the value's `carrier` as sent ('' when missing).
- `->order_meta(): array` — key ⇒ value to add to the order; empty when untranslatable.
- `->post_meta(): array` — key ⇒ value for `update_post_meta()` (Zásilkovna only).

- [ ] **Step 1: Write the failing tests** (`Test_Miguel_Pickup_Point`, posting through the API like
  `test-order-create-api.php`'s `post_order`, payload: a printed product, flat-rate shipping,
  `'status' => 'processing'`, `meta_data` with the neutral entry; the plugin activated by
  `update_option( 'active_plugins', array( '<slug>/<slug>.php' ) )`):
  - `test_packeta_writes_zasilkovna_post_meta_and_shipmall_meta` — order meta `ftx_shm_packeta_id`/`_name`,
    `get_post_meta( $id, '_wc_zasilkovna_point_id', true )` and `_name`, neutral entry still there.
  - `test_balikovna_writes_toret_meta_with_b_prefix`, `test_balikovna_keeps_prefixed_id`,
    `test_balikovna_without_address_writes_no_address`.
  - `test_gls_writes_gls_id_and_data`.
  - `test_carrier_meta_is_on_the_order_when_its_status_changes` — a `woocommerce_order_status_changed`
    callback reads a fresh `wc_get_order()` and `get_post_meta()`; Zásilkovna's keys present there.
  - `test_without_active_plugin_logs_warning_and_keeps_only_neutral_entry` — a spy logger through the
    `woocommerce_logging_class` filter records one `warning`, source `miguel`, message with the order number
    and `packeta`; no carrier key on the order.
  - `test_unknown_carrier_logs_warning`, `test_order_without_entry_gets_no_carrier_meta_and_no_warning`,
    `test_replay_writes_no_further_meta` (one row per key after two identical posts),
    `test_post_meta_callback_is_removed_after_the_request` (an order created later gets no Zásilkovna postmeta),
    `test_order_created_by_another_plugin_during_the_request_gets_no_post_meta` (the callback unhooks itself on
    its first run), `test_value_that_is_not_an_object_logs_warning`,
    `test_plugin_with_similar_directory_is_not_active`.
  - The test environment runs WooCommerce **without** HPOS, where order meta is wp_postmeta, so the
    wp_postmeta assertions alone prove nothing for an HPOS shop: two subclasses run every test again with
    WooCommerce's `HPOSToggleTrait`, `_Hpos` (no posts sync — the case the `woocommerce_new_order` write
    exists for) and `_Hpos_Sync`. Their tearDown sets `wc_allow_changing_orders_storage_while_sync_is_pending`
    to switch back while the test's orders exist in the orders table alone.
- [ ] **Step 2: Run them, expect failures** (class missing / keys absent):
  `docker compose --env-file /dev/null -f docker-compose.test.yml -p smp23 run --rm phpunit --filter Test_Miguel_Pickup_Point`
- [ ] **Step 3: Implement the class and wire it in `create_order`** right after `prepare_payload_for_wc_order`:
  append `order_meta()` to `$payload['meta_data']`; when `post_meta()` is non-empty add a
  `woocommerce_new_order` closure writing it to the given order id, removed in the `finally`; after the order
  is loaded, `Miguel::log( …, 'warning' )` when `order_meta()` is empty.
- [ ] **Step 4: Run the filter again, then `scripts/phpunit_docker.sh`** — all pass.
- [ ] **Step 5: CHANGELOG entry under 1.11.0; commit through `implement finish`.**
