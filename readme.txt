== Miguel for WooCommerce ==
Contributors: servantesczech
Tags: ebooks, audiobooks, watermarked, social-drm, woocommerce
Requires at least: 4.9
Tested up to: 6.3
Stable tag: 1.2.1
Requires PHP: 7.2
License: MIT

Sell watermarked e-books and audiobooks directly from your WooCommerce e-shop.

== Description ==

Miguel is a system that allows you to sell **secured** e-books and audiobooks directly to your e-shop customers. It's a simple and effective way to increase your overall sales of e-books and audiobooks. You won't lose sales elsewhere, and you'll save tens of percent in commission. You'll also increase your customers' loyalty by providing everything in one place.

- Three formats are available - **EPUB**, **PDF** and even **MOBI**
- Every e-book is protected by complex social DRM
- **Increase your overall sales** of e-books. You won't lose sales elsewhere (verified)
- You'll **save** tens of percent in commission
- Increase your customers' **loyalty** by providing **everything in one place**
- Allows distribution of **audiobooks**

## Usage ##

Create products using WooCommerce UI, set it to _Downloadable_ and in _Downloadable files_ use shortcode `[miguel id="<book id>" format="<book format>"]` to set _File URL_. For example `[miguel id="book-id" format="epub"]`. Create multiple downloadable files for each format that you want to provide. ID is the ID of the book in Miguel system.

Currently supported formats are: `epub`, `mobi`, `pdf` and `audio`.

You can find more details on [our docs](https://docs.miguel.servantes.cz/en/docs/platforms/woocommerce/usage/).

== Installation ==

Install the plugin from the WordPress plugin directory or dwonload it from https://github.com/servantes-io/miguel-woocommerce/releases/latest/download/miguel.zip and upload it to your WordPress. Then activate it.

To setup Miguel plugin go to Wordpress Admin > WooCommerce > Settings > Miguel (usually located on path `/wp-admin/admin.php?page=wc-settings&tab=miguel`). Then you need to paste API key from [App Servantes > Miguel Settings > API keys](https://app.servantes.cz/miguel/settings).

You can find more details on [our docs](https://docs.miguel.servantes.cz/en/docs/platforms/woocommerce/install/).

== Frequently Asked Questions ==


== Changelog ==

= 1.2.1 =
* Fix sniff issues found by PHP CS and validation before sending to WooCommerce

= 1.2.0 =
* Code preparation for WordPress and WooCommerce
* Changed code style to WordPress standard
* Removed ability to connect to Staging or Test server

= 1.1.2 =
* Add support for `/` in book ID
* Fix parsing of error message from Miguel

= 1.1.1 =
* Fixed usage of `DateTimeInterface::ISO8601` on older versions of PHP

= 1.1 =
* Added link to settings page from plugins page.

= 1.0 =
* Init version.
