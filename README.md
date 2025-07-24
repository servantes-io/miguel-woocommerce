# Miguel for WooCommerce

Sell watermarked e-books and audiobooks directly from WooCommerce e-shop via [Miguel](https://servantes.cz/en/miguel).

- __Requires at least:__ WooCommerce 3.9
- __Tested up to:__ WooCommerce 8.7
- __Version:__ 1.3.0

## Setup

To setup Miguel plugin go to Wordpress Admin > WooCommerce > Settings > Miguel (usually located on path `/wp-admin/admin.php?page=wc-settings&tab=miguel`). Then you need to paste API key from [App Servantes > Miguel Settings > API keys](https://app.servantes.cz/miguel/settings).

## Usage

Create products using WooCommerce UI, set it to _Downloadable_ and in _Downloadable files_ use shortcode `[miguel id="<book id>" format="<book format>"]` to set _File URL_. For example `[miguel id="book-id" format="epub"]`. Create multiple downloadable files for each format that you want to provide.

Currently supported formats are: `epub`, `mobi`, `pdf` and `audio`.

You can find more details on [our docs](https://docs.miguel.servantes.cz/en/docs/platforms/woocommerce/install/).

## Order Synchronization

The plugin automatically synchronizes orders with the Miguel API whenever:

- A new order is created
- Order status changes (pending, processing, completed, etc.)
- Payment is completed
- Order is cancelled or refunded

This ensures that Miguel has up-to-date information about all orders containing digital products, enabling better customer support and order tracking.

### Synchronized Data

For each order containing Miguel products, the following data is sent to the Miguel API:

- Order code (WooCommerce order ID)
- Customer information (email, name, address, language)
- Product codes and quantities
- Pricing and currency information
- Order status and purchase date

Only orders containing products with Miguel shortcodes in their downloadable files are synchronized.

## Architecture

The plugin uses a clean, modular architecture:

- **`Miguel_Orders`** - Handles automatic order synchronization with WooCommerce hooks
- **`Miguel_Request`** - Manages individual product download requests
- **`Miguel_Order_Utils`** - Shared utility class for consistent order/user data formatting
- **`Miguel_API`** - Handles all communication with Miguel API endpoints

### Code Quality Features

- **DRY Principle**: Common formatting logic is centralized in `Miguel_Order_Utils`
- **Consistent Data Format**: Both download and sync features use the same utility functions
- **Enhanced Address Formatting**: Includes postcode and country for better customer identification
- **Improved Shortcode Parsing**: Uses WordPress's built-in shortcode parsing instead of regex
- **Comprehensive Testing**: Full test coverage for all utility functions

By using this plugin (and therefore Miguel) you agree to the [terms of service](https://miguel.servantes.cz/assets/license-agreement-en.pdf).
