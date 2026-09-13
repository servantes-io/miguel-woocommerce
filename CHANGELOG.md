# Changelog

## 1.10.0

* Raised the minimum supported versions to WooCommerce 7.9, WordPress 6.5 and PHP 8.1. WordPress blocks activation below the PHP and WordPress floors, so a site under them keeps whatever version it has rather than updating into a broken state
* `PATCH /orders/{id}/status` marks an order paid on shops whose payment gateway keeps orders in its own status. Success is judged by whether payment completion actually ran, not by whether the order reached WooCommerce's built-in "processing" or "completed" — statuses such a shop never reaches. Judging by status made the route report HTTP 500 even though the payment had completed, and Miguel then retried the same order every minute indefinitely, re-running payment completion in the shop each time and re-sending whatever mail the gateway sends with it
* A shop that genuinely declines to complete the payment answers 200 with `paid: false` and a reason rather than an error, so Miguel records it and stops instead of retrying a configuration it cannot change. The refusal is deliberately not remembered: once the gateway is fixed, the same request can succeed
* Added the "Statuses that remove the order from Miguel" setting (WooCommerce → Settings → Miguel): the order statuses that mean the customer no longer has the order, and that therefore revoke their access and expire their download links. Defaults to Refunded, Cancelled and Failed, which is what the plugin has always done
* Fixed orders coming back after being removed: the reconciliation endpoint Miguel polls now reports whether an order's status means it is gone, so Miguel removes it instead of re-creating it. It previously re-created the order it had just deleted, and the customer regained access on the next sync. Reporting rather than withholding these orders also lets the sync repair a removal whose original call never reached Miguel
* Added automatic order status change when Miguel finishes an order: choose a target status for orders holding only Miguel books and another for mixed orders, in WooCommerce → Settings → Miguel. Both default to "Do not change status", so nothing changes until an admin opts in
* Added `POST /orders/{id}/finished`, the callback Miguel calls when an order settles
* `POST /orders` accepts an optional `order_note` and records it on the created order as a private note, visible to the shop only. Miguel uses it to say which app an order was placed in; the text comes from Miguel, so its wording can change without a plugin release
* Orders Miguel creates show "Miguel" as their payment method instead of "Other": the plugin now registers a Miguel payment gateway and names it on those orders when Miguel sends no title of its own. The gateway is never offered at checkout, classic or block, and supports no automatic refunds, since the money is taken by Miguel. Rename it in WooCommerce → Settings → Payments; disabling it only brings the "Other" label back

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
