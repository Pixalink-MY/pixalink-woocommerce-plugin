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

= Which phone number earns the points? =

The mobile number entered in the "Mobile number for loyalty points" field at checkout (also on the registration and Account details forms). It is stored in international format, so 012-345 6789 in Malaysia becomes +60123456789. Once a member verifies a number, forms never overwrite it; they verify a new number on the Loyalty tab instead.

= Is the marketing box ever ticked for the shopper? =

No. It starts unticked at checkout and registration, and an unticked box records nothing. Under Account details it shows the shopper's saved choice so they can withdraw at any time. The exact wording shown is recorded (as a hash) with every answer.

= Does uninstalling delete my data? =

Only if you enable **Delete all data on uninstall** in the plugin settings. Order notes and order meta stay with your orders either way.

== External services ==

This plugin connects to the **Pixalink Commerce API** (`https://explore.pixalink.io/api/commerce/v1`), operated by Pixalink, to run the loyalty programme you configure in Pixalink. Nothing is sent until a store administrator connects the store.

What is sent, and when:

* **When you connect the store:** your site URL, the plugin, WordPress and WooCommerce versions, and a one-time authorisation code. You sign in on pixalink.io; your Pixalink password is never entered on your site.
* **When you choose "Request access" (stores not on Pixalink yet):** your store name, site URL, your name and e-mail address, the store's country, currency, time zone, language and checkout type, the plugin, WordPress, WooCommerce and PHP versions, and your choice in the optional Pixalink marketing box, so Pixalink's team can contact you. Nothing is sent until you click the button, and you submit the request on pixalink.io.
* **Periodically while connected (heartbeat, every 6 hours):** your site URL, plugin and WooCommerce versions, and the number of queued and failed order events.
* **When you disconnect:** a request asking Pixalink to revoke the store's key.
* **When an order is paid for, completed, cancelled, refunded or (unpaid) deleted:** the order number and key, totals, currency, line items (product name, SKU, category, quantity, price), coupons, shipping total, and the shopper's name, e-mail address, phone number, WordPress user id (if logged in) and marketing-consent choice with its time and the hash of the consent text shown.
* **When an administrator sends a test ping from the settings:** an empty test event.
* **When a shopper verifies their phone number (My Account › Loyalty):** the phone number to look the member up; if Pixalink has no member with it, the shopper's WordPress user id, name, e-mail address, phone number and country, and their recorded marketing choice, to create one. Pixalink then sends a one-time code by WhatsApp or SMS, and the plugin sends the code the shopper types.
* **When a member arrives from the Pixalink member portal (the `pixalink_sso` link):** the single-use sign-in ticket in the link and, if they are logged in, their WordPress user id. Pixalink answers with the member's id, phone number, name and e-mail address, which the plugin uses to sign the member in or create their customer account.
* **When a verified member views My Account › Loyalty or the loyalty shortcodes:** their Pixalink customer id, to show points, credits, tier, vouchers, memberships, recent activity and the member QR code (drawn in the browser; no image service is used).
* **When a shopper changes their loyalty phone number or marketing choice (checkout, registration, Account details) or links their account:** a customer update with their WordPress user id, Pixalink customer id, phone number and country, e-mail address, name and marketing choice with its time, where it was given and the hash of the wording shown.
* **When a shopper redeems at checkout:** the shopper's Pixalink customer id and the cart contents needed to price vouchers and points.

Pixalink also calls the store: `POST /wp-json/pixalink-loyalty/v1/webhook` receives signed notifications (a member's details or balance changed, a voucher was issued) so cached balances refresh and a withdrawn marketing consent is recorded. Requests are verified with the same key and rejected unless correctly signed and recent.

Every request is signed with a key derived from your outlet API key. Pixalink processes this data as your service provider under its terms.

* Pixalink Terms and Conditions: https://pixalink.io/terms
* Pixalink Privacy Policy: https://pixalink.io/privacy-policy

The member QR code is drawn with [qrcode-generator](https://github.com/kazuhikoarase/qrcode-generator) by Kazuhiko Arase (MIT License, see `assets/js/THIRD-PARTY-LICENSES.txt`), bundled in the plugin.

Self-hosted builds downloaded from pixalink.io (not the WordPress.org version) also check `https://updates.pixalink.io` for plugin updates, sending the site URL and installed plugin version as part of the standard WordPress update request.

== Changelog ==

= 0.1.0 =
* Foundation: requirements checks, HPOS and Cart & Checkout blocks compatibility, encrypted credential storage, signed API client.
* Connect screen under WooCommerce › Settings › Pixalink Loyalty: connect with your Pixalink sign-in or an outlet API key, request access, test and disconnect; connection heartbeat and admin notices.
* Phone number (international format) and a separate, unticked marketing-consent box at checkout (blocks and classic), registration and Account details.
* My Account › Loyalty: phone verification with a one-time code, points, credits, tier, vouchers, memberships, activity and a member QR code; `[pixalink_loyalty_summary]` and `[pixalink_loyalty_qr]` shortcodes.
* Sign-in from the Pixalink member portal, signed webhooks from Pixalink, and personal data export and erasure.
