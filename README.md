# Miguel for WooCommerce

Sell watermarked e-books and audiobooks directly from WooCommerce e-shop via [Miguel](https://servantes.cz/en/miguel).

- **Requires at least:** WooCommerce 3.9
- **Tested up to:** WooCommerce 10.0
- **Version:** 1.6.4

## Setup

To setup Miguel plugin go to Wordpress Admin > WooCommerce > Settings > Miguel (usually located on path `/wp-admin/admin.php?page=wc-settings&tab=miguel`). Then you need to paste API key from [App Servantes > Miguel Settings > API keys](https://app.servantes.cz/miguel/settings).

## Usage

Create products using WooCommerce UI, set it to _Downloadable_ and in _Downloadable files_ use shortcode `[miguel id="<book id>" format="<book format>"]` to set _File URL_. For example `[miguel id="book-id" format="epub"]`. Create multiple downloadable files for each format that you want to provide.

Currently supported formats are: `epub`, `mobi`, `pdf` and `audio`.

## REST API

The plugin exposes three authenticated REST endpoints:

- `GET /wp-json/miguel/v1/products` returns WooCommerce products with pricing, stock data, SKU, and extracted Miguel items.
- `POST /wp-json/miguel/v1/orders` creates a WooCommerce order.
- `GET /wp-json/miguel/v1/product-code-map` returns the current `product_code -> product_id` mapping.

Authentication uses `Authorization: Bearer <token>` with the token configured in WooCommerce > Settings > Miguel.

### Order Request Rules

- `line_items` is required and must be a non-empty array.
- `status` is optional (if omitted, WooCommerce determines the resulting status).
- `send_emails` is optional; if set to `true`, the API attempts to dispatch standard WooCommerce order emails after the order is created.
- `email_template` is optional; if provided, the API sends that specific WooCommerce email template after order creation and treats email sending as enabled.
- `customer_id` is optional; if provided and valid, the order is linked to that WordPress user.
- If `customer_id` is missing or invalid, the API falls back to `user_email` and links the order to a matching user when one exists.
- If neither `customer_id` nor `user_email` resolves to a user, the order is created as a guest order.
- `payment_method` is required.
- `billing` is required and must be a non-empty object.
- `shipping` is required and must be a non-empty object.
- `shipping_lines` is required and must be a non-empty array.
- Each line item must be an object.
- Each line item must include `quantity` as a positive integer.
- Each line item must include `product_id` or `product_code`.
- If both `product_id` and `product_code` are sent, they must resolve to the same product.
- `product_code` can be resolved from `[miguel ...]`, `[wosa ...]`, `[audio ...]`, or from product SKU when no supported shortcode is present.

### Allowed Values and Enums

- If `status` is provided, use WooCommerce order statuses (without `wc-` prefix): `pending`, `on-hold`, `processing`, `completed`, `cancelled`, `refunded`, `failed`.
- `send_emails` accepts boolean-like values such as `true`, `false`, `1`, `0`, `"true"`, `"false"`.
- `email_template` accepts one of: `new_order`, `customer_invoice`, `customer_on_hold_order`, `customer_processing_order`, `customer_completed_order`, `customer_failed_order`.

### Email Dispatch Note

- If `send_emails=true` and `email_template` is not set, the API dispatches WooCommerce's standard admin new order email and the matching customer email for the final order status when WooCommerce provides one.
- If `email_template` is set, the API dispatches only that specific WooCommerce email template.
- Current customer email mapping is: `pending` -> customer invoice, `on-hold` -> on-hold order, `processing` -> processing order, `completed` -> completed order, `failed` -> failed order.
- This triggers WooCommerce email dispatch logic, but actual delivery still depends on WooCommerce email settings and the site's mail infrastructure.

### Stock Availability Note

- Current behavior: order creation via this API does not enforce stock availability during payload validation.
- As a result, the API may allow creating orders with out-of-stock items depending on WooCommerce product/backorder configuration.

### Products Response Notes

- `GET /wp-json/miguel/v1/products` returns all WooCommerce products and variations included by the plugin query.
- Each product entry contains pricing, tax, stock fields, SKU, type, parent relation, and `miguel_items` extracted from supported shortcodes in downloadable files.
- This endpoint is read-only and uses the same bearer token authentication as the other Miguel REST endpoints.

### Error Codes

Error messages are returned in English and use stable dot-separated codes.

- `auth.token_missing` - missing bearer token, HTTP `401`
- `auth.token_invalid` - invalid bearer token, HTTP `403`
- `auth.configuration_missing` - Miguel API configuration is missing, HTTP `500`
- `auth.token_not_configured` - Miguel API token is empty in plugin settings, HTTP `500`
- `auth.unexpected_error` - unexpected authentication error, HTTP `500`
- `idempotency.key_required` - idempotency key is missing, HTTP `400`
- `idempotency.in_progress` - the same idempotency key is already being processed, HTTP `409`
- `idempotency.payload_mismatch` - the same idempotency key was already used with different payload, HTTP `409`
- `line_items.required` - request does not contain `line_items`, HTTP `409`
- `line_items.invalid_structure` - `line_items` is not an array, HTTP `409`
- `line_items.empty` - `line_items` is an empty array, HTTP `409`
- `line_item.invalid_structure` - one line item is not an object, HTTP `409`
- `line_item.quantity_required` - line item is missing `quantity`, HTTP `409`
- `line_item.invalid_quantity` - `quantity` is not a positive integer, HTTP `409`
- `line_item.product_reference_required` - line item is missing both `product_id` and `product_code`, HTTP `409`
- `line_item.product_reference_conflict` - `product_id` and `product_code` resolve to different products, HTTP `409`
- `order.email_template_invalid` - request contains unsupported `email_template`, HTTP `409`
- `order.payment_method_required` - request is missing `payment_method`, HTTP `409`
- `order.billing_required` - request is missing valid `billing`, HTTP `409`
- `order.shipping_required` - request is missing valid `shipping`, HTTP `409`
- `order.shipping_lines_required` - request is missing valid `shipping_lines`, HTTP `409`
- `product_code.required` - `product_code` is empty, HTTP `409`
- `product_code.not_found` - `product_code` was not matched to any product, HTTP `409`
- `product_code.ambiguous` - `product_code` matches multiple products, HTTP `409`
- `order.rest_unavailable` - WooCommerce order controller is unavailable, HTTP `500`
- `order.creation_failed` - WooCommerce did not return a created order ID, HTTP `500`

You can find more details on [our docs](https://docs.miguel.servantes.cz/en/docs/platforms/woocommerce/install/).

By using this plugin (and therefore Miguel) you agree to the [terms of service](https://miguel.servantes.cz/assets/license-agreement-en.pdf).
