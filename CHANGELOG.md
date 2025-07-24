# Changelog

## 1.4.0

Released YYYY-MM-DD

* Added automatic order synchronization with Miguel API
* Orders are now automatically sent to Miguel when created, paid, or status changes
* Only orders containing Miguel digital products are synchronized

## 1.3.0

Released YYYY-MM-DD

* Dropped support for async watermarking (so we can push this plugin to WordPress Plugin Directory and not rewriting
half of the plugin)

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
