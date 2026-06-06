=== WooCommerce Custom Product Badges ===
Contributors: sandydigital
Tags: woocommerce, product badges, sale badge, new badge, featured badge, labels
Requires at least: 5.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Add eye-catching "Sale", "New", "Featured", and custom badges to your WooCommerce products. Full color control, scheduling, and per-product overrides.

== Description ==

WooCommerce Custom Product Badges adds beautiful, customizable badges to your product images on both shop/loop pages and single product pages.

= Key Features =

* **4 Badge Types** — Sale (auto-detects products on sale), New (auto-detects recently published products), Featured (for featured products), and Custom (manual assignment).
* **Full Color Control** — Built-in WordPress color picker for each badge's background and text color. Match your brand perfectly.
* **5 Position Options** — Top left, top right, bottom left, bottom right, or center. Choose where badges appear on product images.
* **Smart Priority System** — Badges display in priority order: Sale > New > Featured > Custom. Only one badge shows per product.
* **"New" Duration** — Set how many days a product is considered "New" after publishing (default: 30 days).
* **Per-Product Override** — Assign any badge type (or no badge) to individual products. Override text, color, start date, and expiry date.
* **Scheduled Badges** — Set start dates and expiry dates for per-product badges. Run a flash sale badge for the weekend.
* **Zero Performance Impact** — Lightweight CSS-only positioning. No JavaScript on the frontend.
* **Uninstall Cleanup** — Removes all options and post meta on uninstall.

= How It Works =

1. Install and activate. Badges appear immediately with sensible defaults.
2. Go to WooCommerce → Product Badges to customize colors, text, position, and sizing.
3. For per-product control, edit any product and use the "Product Badge Override" meta box.
4. That's it — badges render automatically on shop and product pages.

== Installation ==

1. Upload `woocommerce-custom-badges.php` to `/wp-content/plugins/`.
2. Activate the plugin through the WordPress 'Plugins' menu.
3. Go to WooCommerce → Product Badges to customize.
4. Badges appear instantly.

== Frequently Asked Questions ==

= Will this work with my theme? =

Yes. The plugin uses absolute positioning with a z-index of 10, which works with virtually all WooCommerce themes. If your theme uses custom product image markup, you may need minor CSS adjustments.

= Can I show multiple badges at once? =

By design, only one badge renders per product (priority-based). This keeps your product images clean and avoids badge clutter. You can use per-product overrides to pick which badge shows.

= Do badges appear on archive/shop pages? =

Yes — badges render on the shop page, category pages, tag pages, and single product pages.

== Screenshots ==

1. Badges on shop page — Sale, New, and Featured badges in action.
2. Plugin settings page — Color pickers, position selector, and badge configuration.
3. Per-product override meta box — Custom badge, date scheduling, and disable toggle.

== Changelog ==

See changelog.txt.

= 1.0.0 =
* Initial release
* 4 badge types: Sale, New, Featured, Custom
* Color picker for each badge
* 5 position options
* Per-product override with scheduling
* Uninstall cleanup