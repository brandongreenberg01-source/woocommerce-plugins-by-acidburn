=== WooCommerce Order Auto-Complete ===
Contributors: sandydigital
Tags: woocommerce, auto-complete, orders, automation, virtual, downloadable
Requires at least: 5.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically mark WooCommerce orders as "Completed" when they contain only virtual or downloadable products. Save time and streamline your store.

== Description ==

WooCommerce Order Auto-Complete is a lightweight PHP plugin that automatically transitions qualifying orders to "Completed" status — no manual work required.

= Key Features =

* **Smart Auto-Detection** — Automatically detects when an order contains only virtual or downloadable products and completes it instantly after payment.
* **Payment Method Filtering** — Choose which payment gateways trigger auto-completion. Only auto-complete BACS/cheque orders? You got it.
* **Per-Product Override** — Add a simple checkbox on any product edit screen to disable auto-complete for that specific product.
* **Category Exclusions** — Exclude entire product categories from auto-completion.
* **Bulk Actions** — Apply or remove auto-complete behavior on multiple orders at once from the WooCommerce Orders screen.
* **Settings Page** — Clean, native WordPress settings page under WooCommerce → Order Auto-Complete.
* **Uninstall Cleanup** — Removes all plugin data when uninstalled. No orphaned options or post meta.

= How It Works =

1. Install and activate the plugin.
2. Go to WooCommerce → Order Auto-Complete to configure your preferences.
3. When a customer places an order that qualifies (virtual/downloadable products, approved payment method, not excluded), the order is automatically marked "Completed."
4. Optionally, the customer receives the "Order Completed" email notification.

= Use Cases =

* Digital product stores (ebooks, software licenses, music, courses)
* Membership sites with instant access
* Any store where fulfillment doesn't require shipping

== Installation ==

1. Upload the `woocommerce-auto-complete.php` file to your `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Go to WooCommerce → Order Auto-Complete to configure settings.
4. Done! Orders will now auto-complete based on your rules.

== Frequently Asked Questions ==

= Will this affect existing orders? =

No. The plugin only auto-completes orders placed after activation. You can use the bulk action feature to retroactively complete existing qualifying orders.

= Does it work with all payment gateways? =

Yes. All standard WooCommerce payment gateways are supported. You can optionally restrict auto-completion to specific gateways in the settings.

= What happens if I deactivate the plugin? =

Auto-completion stops immediately. All order statuses remain as they were. Your settings are preserved and will resume if you reactivate.

= Is it compatible with WooCommerce Subscriptions? =

The plugin checks product types on a per-item basis. Subscription products are not virtual/downloadable in the traditional sense, so they will not trigger auto-completion by default unless explicitly configured.

== Screenshots ==

1. Plugin settings page — configure triggers, payment methods, and exclusions.
2. Per-product meta box — disable auto-complete on individual products.
3. Bulk action on Orders screen — complete or skip auto-complete for multiple orders.

== Changelog ==

See changelog.txt for full version history.

= 1.0.0 =
* Initial release
* Smart auto-complete for virtual/downloadable orders
* Payment method filtering
* Per-product override
* Category exclusions
* Bulk order actions
* Uninstall cleanup

== Upgrade Notice ==

= 1.0.0 =
Initial release. No upgrade path needed.