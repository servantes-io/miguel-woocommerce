# Miguel for WooCommerce

Sell watermarked e-books and audiobooks directly from WooCommerce e-shop via [Miguel](https://servantes.cz/en/miguel).

- __Requires at least:__ WooCommerce 3.0
- __Tested up to:__ WooCommerce 7.4
- __Version:__ 1.2.1

## Setup

To setup Miguel plugin go to Wordpress Admin > WooCommerce > Settings > Miguel (usually located on path `/wp-admin/admin.php?page=wc-settings&tab=miguel`). Then you need to paste API key from [App Servantes > Miguel Settings > API keys](https://app.servantes.cz/miguel/settings).

## Usage

Create products using WooCommerce UI, set it to _Downloadable_ and in _Downloadable files_ use shortcode `[miguel id="<book id>" format="<book format>"]` to set _File URL_. For example `[miguel id="book-id" format="epub"]`. Create multiple downloadable files for each format that you want to provide.

Currently supported formats are: `epub`, `mobi`, `pdf` and `audio`.

You can find more details on [our docs](https://docs.miguel.servantes.cz/en/docs/platforms/woocommerce/install/).
