# Changelog

## 1.12.0

* Added `POST /coupons/preview`, which shows Miguel what a WooCommerce discount coupon does to the cart a customer has in the app: Miguel sends the code, the customer's e-mail and the cart lines (and, when it has them, the delivery method and the addresses), and the shop puts the cart together in a temporary WooCommerce cart with and without the coupon, so WooCommerce and any coupon plugin the shop runs (Smart Coupons included) decide. The answer gives, for each line, the price before and after with tax, the gift products a coupon adds, the totals, and, for a coupon the shop refuses, WooCommerce's error code and message. Nothing is saved and no usage is counted
* `POST /orders` accepts `coupon_lines: [{code}]`. The coupon is recorded on the order with a discount of 0 and the lines keep the prices Miguel sent, which already include the discount, so WooCommerce never applies the coupon a second time, whatever other plugins the shop runs. WooCommerce counts the coupon's use as it does for a web order. Before, WooCommerce would have applied the coupon to Miguel's prices again
* `POST /orders` and `POST /coupons/preview` no longer load the whole catalogue on every request to find the products of Miguel's product codes: the code map is kept between requests and rebuilt after a product's SKU, downloads or Miguel code changes, after it is published, trashed or deleted, or when the print suffix changes. On a large shop that cost seconds per request, enough for Miguel to give up on a coupon preview

## 1.11.0

Released 2026-10-23

* An order Miguel creates with a pickup point (the `_miguel_pickup_point` order meta: carrier, id, name, address) now reaches the shop's carrier plugin, so Shipmall exports it with its branch: Zásilkovna (wc-zasilkovna) gets `_wc_zasilkovna_point_id` and `_wc_zasilkovna_point_name`, written to wp_postmeta too since that plugin reads only there, plus Shipmall's `ftx_shm_packeta_id` and `ftx_shm_packeta_name`; Balíkovna (toret-ceska-posta) `tcp_pickup_id` with its `b` prefix and the related name and address keys; GLS (wpify-woo-gls) `_gls_id` and `_gls_data`. They are written while the order is created, before its status changes. Without the matching active plugin the order keeps only the neutral entry and the WooCommerce log (source `miguel`) gets a warning with the order number and the carrier
* Fixed outbound order creation from Miguel so repeated requests with the same idempotency key replay the original result, while reusing the key with a different payload is rejected instead of creating or changing another order
* Fixed outbound order totals: prices received from Miguel are treated as customer-facing prices including tax and WooCommerce calculates the tax-exclusive values and tax rows using the shop's configured product and shipping tax rules, keeping the order total aligned with Miguel
* Added unit coverage for outbound order idempotency, payload conflicts, inclusive product prices and inclusive shipping prices
* Updated the local Docker Compose setup to use persistent named WordPress/MySQL volumes and the host gateway/opcache configuration needed for reliable local plugin development and tests
* The plugin reports the errors it catches to Miguel (`POST /v2/eshop/errors`), which forwards them to Servantes' error tracking: a failed call to Miguel (unreachable, API key rejected, an HTTP error, an unreadable or incomplete answer) and a failed e-book download (`DOWNLOAD_FILE_INVALID`, `DOWNLOAD_ORDER_NOT_FOUND` when the order is gone and so is its download permission, `DOWNLOAD_ORDER_NOT_PAID`, `DOWNLOAD_FAILED`). Reports wait in the `miguel_error_reports` option (at most 50, the oldest dropped) and are sent by WP-Cron, hourly and soon after the next successful call to Miguel, never during a customer's request. They carry ids only, never a customer's e-mail, name or address

## 1.10.3

Released 2026-10-16

* Fixed e-book downloads from orders that no longer exist, typically old orders deleted outside WooCommerce (directly in the database or by a clean-up plugin): WooCommerce keeps such an order's download permissions and still offers the link, but the download stopped with "Invalid order." because the watermark was built from the order. It is now built from the download permission instead — the customer's e-mail and account, access granted date as the purchase date, the deleted order's ID as the order code — with no name or address, and a sold price of 0 in the shop's currency, since the price paid is gone with the order

## 1.10.2

Released 2026-10-09

* Fixed e-book downloads from paid orders that have no payment date recorded, typically older or free orders: the download stopped with "Invalid request." because the watermark needs a purchase date and the plugin took it only from the payment date. A paid order without one now uses its completion date, or its creation date when it has none. The same date fills `purchase_date` in `GET /orders` and `GET /orders/{id}` and the purchase date sent to Miguel with the order, which were empty for these orders. An unpaid order still has no purchase date
* Tested up to WordPress 7.1 and WooCommerce 11.2

## 1.10.1

* Fixed e-book downloads: 1.10.0 declared the download ID as a number, but WooCommerce identifies a product's downloads by string IDs (UUIDs), so every download of a Miguel or Wosa file stopped with a PHP fatal error (`TypeError` on `Miguel_File::$download_id`) before reaching Miguel

## 1.10.0

Released 2026-10-01

* Raised the minimum supported versions to WooCommerce 7.9, WordPress 6.5 and PHP 8.1. WordPress blocks activation below the PHP and WordPress floors, so a site under them keeps whatever version it has rather than updating into a broken state
* `PATCH /orders/{id}/status` marks an order paid on shops whose payment gateway keeps orders in its own status. Success is judged by whether payment completion actually ran, not by whether the order reached WooCommerce's built-in "processing" or "completed" — statuses such a shop never reaches. Judging by status made the route report HTTP 500 even though the payment had completed, and Miguel then retried the same order every minute indefinitely, re-running payment completion in the shop each time and re-sending whatever mail the gateway sends with it
* A shop that genuinely declines to complete the payment answers 200 with `paid: false` and a reason rather than an error, so Miguel records it and stops instead of retrying a configuration it cannot change. The refusal is deliberately not remembered: once the gateway is fixed, the same request can succeed
* Added the "Statuses that remove the order from Miguel" setting (WooCommerce → Settings → Miguel): the order statuses that mean the customer no longer has the order, and that therefore revoke their access and expire their download links. Defaults to Refunded, Cancelled and Failed, which is what the plugin has always done
* Fixed orders coming back after being removed: the reconciliation endpoint Miguel polls now reports whether an order's status means it is gone, so Miguel removes it instead of re-creating it. It previously re-created the order it had just deleted, and the customer regained access on the next sync. Reporting rather than withholding these orders also lets the sync repair a removal whose original call never reached Miguel
* Added automatic order status change when Miguel finishes an order: choose a target status for orders holding only Miguel books and another for mixed orders, in WooCommerce → Settings → Miguel. Both default to "Do not change status", so nothing changes until an admin opts in
* Added `POST /orders/{id}/finished`, the callback Miguel calls when an order settles
* `POST /orders` accepts an optional `order_note` and records it on the created order as a private note, visible to the shop only. Miguel uses it to say which app an order was placed in; the text comes from Miguel, so its wording can change without a plugin release
* Orders Miguel creates show "Miguel" as their payment method instead of "Other": the plugin now registers a Miguel payment gateway and names it on those orders when Miguel sends no title of its own. The gateway is never offered at checkout, classic or block, and supports no automatic refunds, since the money is taken by Miguel. Rename it in WooCommerce → Settings → Payments; disabling it only brings the "Other" label back
* `POST /orders` no longer requires a shipping address or shipping lines on an order that holds only digital formats, and drops the free placeholder shipping Miguel sends with such an order, so it shows no shipping address. An item needs delivery when its product needs shipping and is not downloadable; an order with at least one such item still requires both, with the same errors as before. A digital-only order whose shipping line has a cost keeps it, so the order total still matches what the customer paid
* The plugin header now declares its `Domain Path`, so WordPress finds the bundled translations even when something asks for them before `init`. Without it, WordPress 6.7 and later looked in the plugin root; the Miguel gateway translates its labels when WooCommerce builds its gateways, which some payment plugins do that early, and every Miguel string then stayed in English for the rest of the request
* A partial refund takes the refunded products out of the order Miguel holds, so the customer loses access to what they were refunded for. A product counts as refunded by its refunded quantity or by a refunded amount covering whole units, whichever takes more; money short of a unit's price (compensation) keeps the product in the order Miguel holds (this is about Miguel, not WooCommerce's own download links: WooCommerce always removes a line's My Account download links once it carries any refund, whole or partial, and never restores them). Refunding every product Miguel delivers removes the order from Miguel even when WooCommerce keeps the order's status, and deleting a refund puts the products back in the order Miguel holds on the next sync. Deleting a *full* refund is the exception: WooCommerce leaves the order in the `refunded` status, so it stays removed from Miguel until the status changes. The reconciliation endpoint Miguel polls reports the same. The rule also applies to refunds made before this version, the next time such an order syncs

## 1.9.1

* Delivery methods endpoint now reports a cost for carriers that keep their pricing in their own plugin settings instead of WooCommerce (e.g. Toret Balíkovna): when the `cost` setting is empty, the method is asked to calculate its rates for an empty package addressed to the zone, and the cost of its first rate is reported

## 1.9.0

* Added support for selling printed books through Miguel: a non-downloadable product's Miguel product code is derived from its SKU plus a configurable suffix, keeping it distinct from the e-book edition that shares the same slug
* Added the "Printed-book code suffix" setting (WooCommerce → Settings → Miguel); leave empty to disable printed-book pairing
* Added optional per-product `_miguel_code` meta to override a product's Miguel code

## 1.8.0

Released 2026-07-16

* Added `GET /orders/{id}` endpoint returning a single order with line items, totals, billing and shipping addresses, and payment and shipping metadata
* Added currency to the delivery methods endpoint
* Delivery method title and description are now read from the method settings (with shortcodes expanded) instead of the internal method labels
* Orders created from Miguel are no longer reported back to Miguel as new orders
* Fixed duplicate line items caused by repeated Miguel codes across multi-format downloads
* BREAKING: Raised the minimum required WooCommerce version to 6.0

## 1.7.0

Release 2026-07-07

* Add option to enable sending emails from Miguel (instead of WooCommerce)

## 1.6.4

Released 2026-07-05

* Upgraded communication to Miguel API v2
* Implemented API endpoint for creating orders from Miguel (future feature)

## 1.6.1

Released 2026-05-05

* Fixed communication with older Miguel API

## 1.6.0

Released 2026-05-04

* Added support for connecting to the Miguel API using an access token (API key)
* Implemented API endpoint for retrieving all products including prices and stock information

## 1.5.0

Released 2026-01-10

* Add support for Melvil WooCommerce Bundle products

## 1.4.0

Released 2025-07-31

* Automatically create orders on Miguel to allow users read and listen all audiobooks in our [Miguel Book Reader app](https://servantes.cz/en/ella)

## 1.3.0

Released 2024-07-18

* Dropped support for async watermarking (so we can push this plugin to WordPress Plugin Directory and not rewriting half of the plugin)

## 1.2.2

Released 2024-03-23

* Add support for [WooCommerce's new HPOS](https://github.com/woocommerce/woocommerce/wiki/High-Performance-Order-Storage-Upgrade-Recipe-Book)

## 1.2.1

Released 2023-10-05

* Fix sniff issues found by PHP CS and validation before sending to WooCommerce

## 1.2.0

Released 2023-09-21

* Code preparation for WordPress and WooCommerce
* Changed code style to WordPress standard
* Removed ability to connect to Staging or Test server

## 1.1.2

Released 2023-04-17

* Add support for `/` in book ID
* Fix parsing of error message from Miguel

## 1.1.1

Released 2023-03-30

* Fixed usage of `DateTimeInterface::ISO8601` on older versions of PHP

## 1.1

Released 2023-03-08

* Added link to settings page from plugins page.

## 1.0

Released 2017-01-01

* Init version.
