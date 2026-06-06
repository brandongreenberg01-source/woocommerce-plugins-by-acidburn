=== WooCommerce Stock Alert System ===
Contributors: sandydigital
Tags: woocommerce, stock alerts, low stock, inventory, email notifications, back in stock
Requires at least: 5.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Get instant email notifications when WooCommerce products run low on stock. Per-product thresholds, multiple recipients, daily digest emails, and a breach audit log.

== Description ==

Never run out of stock without knowing it. WooCommerce Stock Alert System monitors your inventory in real time and sends email notifications the moment a product's stock falls to or below your defined threshold.

= Key Features =

* **Real-Time Alerts** — Instant email when stock drops below threshold (triggered on order, manual stock change, or status change).
* **Per-Product Thresholds** — Set a global default, then override it for high-priority products that need more buffer.
* **Multiple Recipients** — Comma-separated list of email addresses. CC your warehouse manager, assistant, and yourself.
* **Daily Digest** — One summary email per day listing every product that's below threshold. Silent when everything is healthy.
* **Out-of-Stock Alerts** — Optional instant notification when a product goes completely out of stock.
* **Breach Audit Log** — Every alert is recorded in a database table. View, search, and clear the log from the WordPress admin.
* **Admin Column** — See stock alert status at a glance on the Products list table. ⚠ for low, ✓ for healthy.
* **Smart Deduplication** — No duplicate alerts in the same request, even if multiple hooks fire.
* **Uninstall Cleanup** — Removes options, post meta, scheduled cron, and the log table on uninstall.

= How It Works =

1. Set a global threshold (e.g., 5).
2. When stock for any product drops to 5 or below, an email fires to all recipients.
3. Optionally, receive a daily digest summarizing all products below threshold.
4. Review alerts in the Stock Alert Log at WooCommerce → Stock Alert Log.

= Triggers Covered =

* Customer places an order (stock reduced)
* Admin manually changes stock quantity
* Stock status changes (in stock → out of stock)
* Variation stock changes for variable products

== Installation ==

1. Upload `woocommerce-stock-alerts.php` to `/wp-content/plugins/`.
2. Activate the plugin.
3. Go to WooCommerce → Stock Alerts to set your threshold and recipients.
4. The log table is created automatically on activation.

== Frequently Asked Questions ==

= Will this send too many emails? =

No. The plugin deduplicates within a single request and the daily digest only sends if at least one product is below threshold. You can also disable instant alerts per product.

= Does it work with variable products? =

Yes. Each variation's stock is checked independently. The alert identifies the parent product and the specific variation attributes.

= What happens on uninstall? =

Everything is removed: the options, per-product meta, scheduled cron, and the log table. Zero traces left.

== Screenshots ==

1. Settings page — global threshold, recipients, email templates, digest toggle.
2. Per-product meta box — custom threshold and disable toggle.
3. Breach log page — audit trail of every alert with product links.

== Changelog ==

See changelog.txt.

= 1.0.0 =
* Initial release