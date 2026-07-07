# Settings flag: control whether Miguel sends order emails

**Date:** 2026-07-07
**Status:** Approved (design)

## Problem

When WooCommerce syncs an order to Miguel's backend, it sends a
`Miguel_V2_Order_Create` DTO whose `send_email` field tells Miguel's server
whether to send its own order/delivery email to the customer. Today the mapper
hardcodes this to `'disable'` ([class-miguel-order-mapper.php:63](../../../includes/api/v2/mappers/class-miguel-order-mapper.php#L63)),
so Miguel's backend never emails the customer, and admins have no way to change
that.

We want an admin setting that controls this: when enabled, the DTO carries
`send_email = 'auto'` (Miguel sends its email); when disabled, `'disable'`
(current behavior).

## Scope

This concerns only the **outbound** order-create flow (WooCommerce → Miguel).
It does **not** touch the inbound order-create REST API
([class-miguel-order-create-api.php](../../../includes/class-miguel-order-create-api.php)),
whose separate `send_email`/`send_emails` payload flag controls WooCommerce's
own emails. The two flows stay independent.

## Decisions

- **Default:** disabled. Existing installs keep today's `'disable'` behavior;
  admins opt in.
- **Value mapping:** checkbox on → `'auto'`; off → `'disable'` (the DTO field's
  two documented values).
- **Wiring:** the resolved value is passed into `Miguel_Order_Mapper::map()` as
  a boolean argument (dependency passed at the call, not read inside the
  mapper). The mapper keeps the `'auto'`/`'disable'` vocabulary encapsulated.
- **Option key home:** the option name lives as a constant on `Miguel_Settings`.

## Design

### 1. Option constant (`Miguel_Settings`)

Add to `Miguel_Settings`:

```php
const SEND_EMAIL_OPTION = 'miguel_send_order_email';
```

Stored as a WooCommerce checkbox value: `'yes'` / `'no'`, default `'no'`.

### 2. Settings checkbox (`Miguel_Settings::get_settings()`)

Add a `checkbox` field inside the existing `miguel_api_options` section
(after the API-server select), for example:

```php
array(
    'id'      => self::SEND_EMAIL_OPTION,
    'type'    => 'checkbox',
    'title'   => __( 'Send order emails from Miguel', 'miguel' ),
    'desc'    => __( "When enabled, Miguel's server sends the order/delivery email to the customer. When disabled, Miguel does not send any email.", 'miguel' ),
    'default' => 'no',
),
```

The exact copy is not load-bearing and can be adjusted during implementation.

### 3. Reader helper (`Miguel_Orders`)

`Miguel_Orders` reads the option once, via a private helper:

```php
private function is_send_order_email_enabled() {
    return 'yes' === get_option( Miguel_Settings::SEND_EMAIL_OPTION, 'no' );
}
```

### 4. Pass the value at both `map()` call sites (`Miguel_Orders`)

`Miguel_Order_Mapper::map()` is called in two places, and **both must pass the
same value**:

- [class-miguel-orders.php:225](../../../includes/class-miguel-orders.php#L225)
  — `sync_order()`, the actual sync.
- [class-miguel-orders.php:160](../../../includes/class-miguel-orders.php#L160)
  — `generate_order_hash()`, used for change-detection / dedup.

Both become:

```php
$order_create = $this->mapper->map( $order, $this->is_send_order_email_enabled() );
```

### 5. Mapper signature (`Miguel_Order_Mapper::map()`)

```php
public function map( $order, $send_email = false ) {
    // ...
    // line 63, previously hardcoded 'disable':
    $send_email ? 'auto' : 'disable',
    // ...
}
```

The default `false` preserves the current `'disable'` behavior for every
existing caller (including the three `map($order)` test call sites), so no other
caller needs to change.

## Data flow

```
Admin toggles checkbox
  -> option miguel_send_order_email = 'yes' | 'no'
  -> Miguel_Orders::is_send_order_email_enabled() -> bool
  -> Miguel_Order_Mapper::map($order, $bool)
  -> DTO send_email = 'auto' | 'disable'
  -> serialized as "sendEmail"
  -> POST to Miguel backend
```

## Edge cases

- **Backward compatibility:** option unset → `get_option(..., 'no')` → `false`
  → `'disable'`. Identical to today.
- **Hash consistency:** the same resolved boolean feeds both the dedup-hash path
  (line 160) and the sync path (line 225), so the change-detection hash always
  matches what is sent. Flipping the setting changes the hash, so the next
  order sync re-sends with the new value. This is the desired behavior.
- **Non-`'yes'` values:** anything other than exactly `'yes'` → `'disable'`.

## Testing

Integration test suite (real `get_option`/`update_option`).

- [test-order-mapper.php](../../../tests/unit/test-order-mapper.php):
  - `map($order, true)` → `to_array()['sendEmail'] === 'auto'`.
  - `map($order, false)` → `'disable'`.
  - `map($order)` (default) → `'disable'`.
- [test-orders.php](../../../tests/unit/test-orders.php):
  - With `update_option(Miguel_Settings::SEND_EMAIL_OPTION, 'yes')`, a synced
    order produces a DTO carrying `sendEmail === 'auto'`, and the dedup hash
    reflects it; clean up the option in tear-down.

Run tests via `make test-docker`.

## Out of scope

- Per-order or per-product overrides (YAGNI).
- Any change to the inbound order-create REST API.
- Changing which specific Miguel email templates are sent (that is Miguel
  backend behavior driven by `'auto'`).
