=== Rega ===
Contributors: rega
Tags: woocommerce, shopping assistant, product recommendations
Requires at least: 6.5
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connects a WooCommerce store to Rega, the smart shopping assistant.

== Description ==

Rega reads the store's products, variations, categories, attributes and chosen content to build a shopping assistant that helps visitors decide and buy.

This version gives Rega read-only access through a token created by a store manager:

* Products with variations, attributes, custom fields, prices, stock and the upsells and cross-sells set in WooCommerce.
* Product categories with their full paths, and global and custom attributes.
* Published guides and articles from the post types you choose, with the products they mention.
* Site information: WordPress, WooCommerce, theme and active plugin versions.

Customers and orders are never shared. Custom fields that look like costs, supplier details or internal notes are never shared either. There is no write access.

== Installation ==

1. Plugins > Add New > Upload Plugin, choose the zip, install and activate.
2. WooCommerce > Rega > Create token.
3. Copy the token. It is shown only once.

== Changelog ==

= 0.1.1 =
* Categories, tags and brands come in a fixed order, so a product that did not change keeps the same hash. Before, some sites returned them in a different order on each request and Rega saw hundreds of false changes.
* Custom fields that look like costs, supplier details, margins or internal notes are never shared, in English or Hebrew. The field list shows them as sensitive, without values. Add more with the rega_is_sensitive_meta_key filter.

= 0.1.0 =
* Access token, read-only catalog and content API, settings page in English and Hebrew.
