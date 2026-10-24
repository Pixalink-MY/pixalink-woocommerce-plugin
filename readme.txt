=== Pixalink Loyalty for WooCommerce ===
Contributors: pixalink
Tags: loyalty, rewards, points, vouchers, membership
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Shoppers earn Pixalink points on every order, redeem vouchers and points at checkout, and track rewards in My Account.

== Description ==

Pixalink is a customer loyalty platform for retail and F&B businesses. This plugin connects a WooCommerce store to a Pixalink outlet so online orders count exactly like in-store purchases.

* **Earn on every order.** Paid orders are sent to Pixalink and the shopper earns points on the same programme as your outlets.
* **Vouchers and points at checkout.** Verified members redeem Pixalink vouchers and convert points into a discount in both the Cart & Checkout blocks and the classic checkout.
* **My Account › Loyalty.** Members see their balance, tier, vouchers and a QR code to scan at your outlets.
* **Phone number and marketing consent.** Checkout captures an international phone number and an explicit, separately recorded marketing consent.
* **Reliable delivery.** Order events are queued on your site and retried with Action Scheduler, so short outages never lose an order.

The plugin is compatible with High-Performance Order Storage and the Cart & Checkout blocks. It sends no telemetry.

A Pixalink account is required. Store owners who are not on Pixalink yet can request access from the plugin's Connect screen.

== Installation ==

1. Install and activate WooCommerce.
2. Upload the plugin and activate it through **Plugins**.
3. Open **WooCommerce › Settings › Pixalink Loyalty** and choose **Connect to Pixalink**. Sign in with your Pixalink vendor account and pick the outlet that represents your online store.

Advanced: the outlet API key can be set in `wp-config.php` with `define( 'PIXALINK_LOYALTY_API_KEY', '...' );`, which overrides the key stored by the Connect screen.

== Frequently Asked Questions ==

= Do shoppers need a Pixalink account? =

No. Guests earn points with the phone number they enter at checkout. Shoppers who want to spend points or vouchers verify their phone number once with a one-time code.

= What happens if Pixalink is unreachable? =

Checkout keeps working. Order events wait in a queue on your site and are retried automatically; redemptions are only offered while Pixalink confirms them.

= Does uninstalling delete my data? =

Only if you enable **Delete all data on uninstall** in the plugin settings. Order notes and order meta stay with your orders either way.

== External services ==

This plugin connects to the **Pixalink Commerce API** (`https://explore.pixalink.io/api/commerce/v1`), operated by Pixalink, to run the loyalty programme you configure in Pixalink. Nothing is sent until a store administrator connects the store.

What is sent, and when:

* **When you connect the store:** your site URL, the plugin, WordPress and WooCommerce versions, and a one-time authorisation code. You sign in on pixalink.io; your Pixalink password is never entered on your site.
* **When you choose "Request access" (stores not on Pixalink yet):** your store name, site URL, your name and e-mail address, the store's country, currency, time zone, language and checkout type, the plugin, WordPress, WooCommerce and PHP versions, and your choice in the optional Pixalink marketing box, so Pixalink's team can contact you. Nothing is sent until you click the button, and you submit the request on pixalink.io.
* **Periodically while connected (heartbeat, every 6 hours):** your site URL, plugin and WooCommerce versions, and the number of queued and failed order events.
* **When you disconnect:** a request asking Pixalink to revoke the store's key.
* **When a shopper places, pays for, cancels or refunds an order:** the order number and key, totals, currency, line items (product name, SKU, category, quantity, price), coupons, shipping total, and the shopper's name, e-mail address, phone number, WordPress user id (if logged in) and marketing-consent choice with its time and the hash of the consent text shown.
* **When a shopper views My Account › Loyalty or redeems at checkout:** the shopper's phone number or Pixalink customer id and the cart contents needed to price vouchers and points.
* **When a shopper verifies their phone number:** the phone number, so Pixalink can send a one-time code by WhatsApp or SMS, and the code the shopper enters.

Every request is signed with a key derived from your outlet API key. Pixalink processes this data as your service provider under its terms.

* Pixalink Terms and Conditions: https://pixalink.io/terms
* Pixalink Privacy Policy: https://pixalink.io/privacy-policy

Self-hosted builds downloaded from pixalink.io (not the WordPress.org version) also check `https://updates.pixalink.io` for plugin updates, sending the site URL and installed plugin version as part of the standard WordPress update request.

== Changelog ==

= 0.1.0 =
* Foundation: requirements checks, HPOS and Cart & Checkout blocks compatibility, encrypted credential storage, signed API client.
* Connect screen under WooCommerce › Settings › Pixalink Loyalty: connect with your Pixalink sign-in or an outlet API key, request access, test and disconnect; connection heartbeat and admin notices.
