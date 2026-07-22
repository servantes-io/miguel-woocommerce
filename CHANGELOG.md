# Changelog

## Unreleased

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
