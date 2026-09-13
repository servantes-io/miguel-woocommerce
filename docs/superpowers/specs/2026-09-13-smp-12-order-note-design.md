# SMP-12 — Order Note Naming Where a Miguel Order Came From — Design

**Date:** 2026-09-13
**Status:** Approved for planning
**Issue:** [SMP-12](https://neatech.myjetbrains.com/issue/SMP-12) — *Woocommerce: Přidat poznámku po vytvoření objednávky*
**Repos:** `servantes-io/miguel-woocommerce` (plugin) and `servantes-io/miguel` (backend). This document is committed to both, unchanged, so each PR carries the whole design.

## Goal

When Miguel creates an order in a WooCommerce shop (a purchase made in a Miguel mobile app), the shop gets a private order note saying where the order came from. The **backend writes the text**, so it can word it per app. The **plugin only receives it and records it**.

## Background

- The backend creates the shop order through the plugin's `POST /miguel/v1/orders` (`WoocommerceManager._CreateOrderOnWoocommerceInternal` → `WoocommerceClient.CreateOrderAsync`). The plugin forwards the payload to WooCommerce's own `WC_REST_Orders_Controller::create_item()`.
- Only mobile-app purchases take this path. The same MobileApi serves both the **Miguel** and **Melvil** apps (`Core.Mobile.Common.Constants`). The web storefront (`src/Frontend`) calls the same MobileApi.
- Today nothing tells the merchant where such an order came from. The payload sends no note (`customer_note` exists on the backend model but is never assigned), and the backend does not record which app placed the order — `Order` has only `Source = "mobile"`.
- **Precedent:** Shoptet gets a localized remark after the order finishes (`ShoptetManager._CreateRemarkOnShoptetForOrder`, string `shoptet.orderFinishedNote`, localized in `order.Workspace.DefaultLanguage`). This design follows that pattern for the wording.
- WooCommerce's order controller maps only its own schema keys onto the order (`WC_REST_Orders_V2_Controller::prepare_object_for_database()` iterates `get_item_schema()['properties']`). **A plugin version that does not know `order_note` silently ignores it.** The backend therefore needs no plugin-version gate.

## Decisions

| Question | Decision |
|---|---|
| Who sees the note | **Merchant only** — a private order note in the admin order timeline. Never a customer note, never emailed. |
| Who writes the text | The backend. The plugin stores what it receives, sanitized. |
| Scope | Plugin and backend. |
| App attribution | The backend snapshots the app's display name (`Application.Name`) on the order when the MobileApi creates it. |

## Contract

`POST /miguel/v1/orders` gains one optional top-level field:

```json
{ "order_note": "Objednávka vytvořena v aplikaci Melvil." }
```

| Value | Result |
|---|---|
| absent, `null`, or empty/whitespace-only string | No note. Order created as today. |
| non-empty string | Private order note with that text (sanitized with `wp_kses_post`, so the backend may use basic inline HTML such as `<strong>`). |
| any other type (number, bool, array, object) | HTTP 409, error code `order.order_note_invalid`, `data.field = "order_note"`. No order is created. |

No response field changes.

## Plugin design (`miguel-woocommerce`)

All changes live in `includes/class-miguel-order-create-api.php`; no new class.

1. **Validation** — `validate_required_order_fields()` rejects a present, non-null, non-string `order_note` with `order.order_note_invalid` (status 409, via the existing `build_order_payload_error()`). Runs before the idempotency lookup, like the other top-level checks.
2. **Extraction** — a small private helper `get_order_note_from_payload( $payload )` (types in the docblock, like the rest of the file: array in, string out) returns `trim( wp_kses_post( $payload['order_note'] ) )` for a string, otherwise `''`. Trimming after sanitizing means a note made only of stripped markup (`<script></script>`) counts as empty and adds nothing. It is read from the **original** payload before `prepare_payload_for_wc_order()` runs.
3. **Stripping** — `prepare_payload_for_wc_order()` unsets `order_note` next to `send_emails` / `send_email` / `email_template`, so it never reaches the WooCommerce controller.
4. **Recording** — after the order is created and its idempotency meta saved, and before emails are queued: if the note is non-empty, `$order->add_order_note( $note )` (defaults: `$is_customer_note = 0`, `$added_by_user = false`, i.e. a private system note). If it returns `0`, the plugin writes a debug log entry and still answers 201 — the order exists and must not be reported as failed.
5. **Idempotency** — `order_note` is part of the raw payload and therefore of the payload hash. A replay with the same key returns the existing order before step 4, so the note is never added twice. A retry that changes the note text with the same key gets the existing `idempotency.payload_mismatch` 409, like any other changed field.
6. **Logging** — the "Received order create payload" debug entry gains `order_note_length` (the text itself is not logged).
7. **Docs** — `docs/openapi.yaml`: `order_note` added to `CreateOrderRequest` with the table above; `CHANGELOG.md`: an entry under the unreleased `1.10.0`; `languages/miguel-cs_CZ.po` and `miguel-en_US.po`: the new error message (`Order order_note must be a string.`).

### Plugin tests (`tests/unit/test-order-create-api.php`)

- A string `order_note` produces exactly one note on the order, with that text, `customer_note` false.
- The note is sanitized: `<script>` is stripped, `<strong>` kept.
- Absent / `null` / `"   "` produce no Miguel note.
- A non-string `order_note` returns 409 `order.order_note_invalid` and creates no order.
- `order_note` is not forwarded to WooCommerce: the order's `customer_note` stays empty and no meta named `order_note` appears.
- An idempotent replay adds no second note.

Existing behaviour for payloads without `order_note` is unchanged; the full suite stays green.

## Backend design (`miguel`)

### Recording the app on the order

1. **`Order.SourceApplicationName`** (`src/Core/Db/Tables/Order.cs`) — new nullable `string`, max length 200. A snapshot of the app's display name at purchase time: renaming an app later must not rewrite history, and the API process that pushes the order to WooCommerce does not need the mobile database to read it. EF migration generated with `./bin/ef.sh migrations add Order_AddSourceApplicationName` (Core context).
2. **`MobileOrderCreate.SourceApplicationName`** (`src/API/Features/Internal/Models/MobileOrderCreate.cs`) — new optional `string?`, copied through `IOrderManager.CreateOrUpdateOrderParams.SourceApplicationName` onto the new column by the order manager. Only set on creation; an update never overwrites it. `OrderCreate` (the e-shop plugins' inbound model) does **not** get the field — those orders are the shop's own.
3. **MobileApi** (`src/MobileApi/Code/MobileOrderService.CreateOrderAsync`) — resolves `applicationApiFactory.Get(userInfo.ApplicationId).GetApplicationAsync()` and sends its `Name` as `SourceApplicationName`. The service already resolves the application for the Apple-token check, but only when a token is present; the lookup moves up so it runs once per order and both uses share it. The NSwag client (`src/Core/Mobile/Miguel/V2/ApiClient.cs`) is regenerated with `./src/Core/Mobile/Miguel/V2/update.sh --local`.

### Sending the note

4. **`WoocommerceCreateOrderRequest.OrderNote`** (`src/Core/Features/Woocommerce/Models/WoocommerceOrderModels.cs`) — new `string?`, serialized as `order_note`.
5. **Text** — two new resx strings in `src/I18N/Resources/Strings.cs.resx` (Czech, drives the T4 factory) and `Strings.en.resx`:

   | Key | cs | en |
   |---|---|---|
   | `woocommerce.orderCreatedNote` | `Objednávka vytvořena v aplikaci {{ appName }}.` | `Order placed in the {{ appName }} app.` |
   | `woocommerce.orderCreatedNoteUnknownApp` | `Objednávka vytvořena v mobilní aplikaci.` | `Order placed in a mobile app.` |

   `_CreateOrderOnWoocommerceInternal` picks the first when `order.SourceApplicationName` is non-blank and the second otherwise (orders created before this change, and the Hangfire safety-net path for them), and localizes it with `order.Workspace.DefaultLanguage` exactly as Shoptet does. The workspace is loaded if the caller did not include it.
6. **Idempotency** — the text is a pure function of the order and its workspace language, so retries of the same order send the same `order_note` and keep matching the plugin's stored payload hash.
7. **No capability gate** — older plugins ignore the field (see Background); `WoocommercePluginCapabilities` is unchanged.

### Backend tests

- `WoocommerceManagerTests` (captured request): an order with `SourceApplicationName = "Melvil"` sends `order_note = "Objednávka vytvořena v aplikaci Melvil."` for a Czech workspace and the English text for an English one; an order without it sends the unknown-app text.
- `WoocommerceClientTests`: `OrderNote` serializes as `order_note`.
- Order creation through `POST v2/internal/mobile/orders` persists `SourceApplicationName`; the MobileApi test for `CreateOrderAsync` asserts the app name reaches the client request.
- I18N: the build regenerates the factory (`Strings.Woocommerce.OrderCreatedNote(appName)`) and both keys render in both languages.

## Rollout

Independent in either order. Plugin first: nothing changes until the backend sends a note. Backend first: older plugins ignore the field; the note appears once the shop updates the plugin.

## Out of scope

- Telling the web storefront apart from the app. Nothing in a MobileApi request says which client sent it (`ICurrentUser.UserInfo` has `ApplicationId`, `DeviceId`, `DeviceName` only), so the note names the app. A client-type signal is a separate change.
- Notes for other platforms (Shoptet already has its own; PrestaShop and Shopify do not create orders).
- Customer-visible notes, or several notes per order.
- Back-filling `SourceApplicationName` on existing orders.
