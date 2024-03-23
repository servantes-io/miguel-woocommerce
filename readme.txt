== Miguel for WooCommerce ==
Contributors: servantesczech
Tags: ebooks, audiobooks, watermarked, social-drm, woocommerce
Requires at least: 4.9
Tested up to: 6.4
Stable tag: 1.2.2
Requires PHP: 7.2
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

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

## Pricing ##

Miguel is a paid service. You can find more details on [our page](https://servantes.io/miguel_woocommerce), look for Price.


== Installation ==

## Minimum Requirements ##

- WooCommerce 3.9 or greater
- WordPress 4.9 or greater

## Automatic installation ##

1. Log in to your WordPress dashboard.
2. Navigate to the Plugins menu, and click “Add New”.
3. Search and locate ‘Miguel for WooCommerce’ plugin.
4. Click ‘Install Now’, and WordPress will take it from there.

## Manual installation ##

Manual installation method requires downloading the [Miguel for WooCommerce](https://github.com/servantes-io/miguel-woocommerce/releases/latest/download/miguel.zip) plugin and uploading it to your web server via your FTP application. The WordPress codex contains [instructions on how to do this here](https://wordpress.org/support/article/managing-plugins/#manual-plugin-installation).

## Configuration after installation ##

To setup Miguel plugin go to Wordpress Admin > WooCommerce > Settings > Miguel (usually located on path `/wp-admin/admin.php?page=wc-settings&tab=miguel`). Then you need to paste API key from [App Servantes > Miguel Settings > API keys](https://app.servantes.cz/miguel/settings).

You can find more details on [our docs](https://docs.miguel.servantes.cz/en/docs/platforms/woocommerce/install/).


== Frequently Asked Questions ==

= How does the delivery of audiobooks work? =

Miguel also allows you to send out audiobooks to the customers. The zipped MP3 leave from a secure link, so there is no mass distribution of a public link, which could result in illegal downloads. DRM is not yet part of audiobooks but it is planned for the future.

= What formats can be used? =

The MOBI and PDF formats are automatically converted from the supplied ePUB. The system also automatically checks the validity of the ePUB. Miguel supports the most commonly used MP3 format for audiobooks.

= How do we transfer our e-books and audiobooks to Miguel? =

Uploading e-book files to Miguel is similar to what you're used to with other sellers. The latest uploaded version of the e-book is always the current version.

= What does the "customizable" PDF mean? =

The interactive wizard allows you to set the screen size for which the resulting PDF is generated. Then you can set the font size, font type and you are able to turn on margins for notes.


== Changelog ==

= 1.2.2 =
* Add support for [WooCommerce's new HPOS](https://github.com/woocommerce/woocommerce/wiki/High-Performance-Order-Storage-Upgrade-Recipe-Book)


= 1.2.1 =
* Fix sniff issues found by PHP CS and validation before sending to WooCommerce

[Full changelog](https://github.com/servantes-io/miguel-woocommerce/blob/main/CHANGELOG.md)
