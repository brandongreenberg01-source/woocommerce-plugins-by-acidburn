=== WooCommerce Wholesale Pricing ===
Contributors: sandydigital
Tags: woocommerce, wholesale, b2b, role-based pricing, quantity discounts, tiered pricing
Requires at least: 5.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Role-based wholesale pricing with quantity breaks, minimum order enforcement, and price hiding. Turn your WooCommerce store into a B2B powerhouse.

== Description ==

WooCommerce Wholesale Pricing brings enterprise-level B2B features to your WooCommerce store. Assign role-based discounts, set quantity break tiers, enforce minimum orders, and hide prices from guests — all from a clean admin interface.

= Key Features =

* **Role-Based Pricing** — Assign different discount percentages or fixed amounts per WordPress user role. Wholesale Customer gets 30% off, VIP gets 40% off. You control who sees what.
* **Quantity Break Tiers** — Progressive discounts based on quantity. "Buy 10+ → 20% off. Buy 50+ → 35% off. Buy 100+ → $8.50 each." Fully configurable per product.
* **Per-Product Override** — Set a fixed wholesale price on any product that overrides all role and tier discounts. Your bestseller at an exact price point.
* **Minimum Order Quantity** — Enforce minimum purchase quantities per product for wholesale customers. Prevent single-unit wholesale orders.
* **Price Hiding** — Hide all prices and Add to Cart buttons from logged-out visitors. Perfect for private wholesale catalogs.
* **Retail Price Display** — Show the original retail price with a strikethrough next to the wholesale price. Customers see exactly what they're saving.
* **Custom Price Labels** — Each role gets a custom label (e.g., "Wholesale", "VIP", "Dealer") displayed next to prices.
* **Variable Product Support** — Wholesale pricing applies to each variation independently with per-variation filters.
* **Cart Recalculation** — Prices are recalculated in the cart based on quantity tiers. Add 1 item at regular wholesale, add 50 and the tier kicks in.
* **Full Admin Column** — See wholesale pricing status at a glance on the Products list.
* **Uninstall Cleanup** — Removes all options and post meta.

= How It Works =

1. Create wholesale user roles (e.g., "Wholesale Customer", "Wholesale VIP") under Users.
2. Assign customers to those roles.
3. Configure discounts per role at WooCommerce → Wholesale Pricing.
4. Set per-product overrides, quantity tiers, and minimums on each product.
5. Logged-in wholesale customers see discounted prices automatically.

= Pricing Logic (Priority Order) =

1. Per-product fixed wholesale price (overrides everything)
2. Quantity break tier match (quantity in cart determines tier)
3. Role-based percentage or fixed discount

== Installation ==

1. Upload `woocommerce-wholesale-pricing.php` to `/wp-content/plugins/`.
2. Activate through WordPress Plugins screen.
3. Create wholesale roles under Users → Add New Role (or use a role editor plugin).
4. Go to WooCommerce → Wholesale Pricing to configure.
5. Assign customers to wholesale roles on their user profiles.

== Frequently Asked Questions ==

= Do I need a role editor plugin? =

WordPress doesn't have a built-in role editor, so you'll need one (e.g., User Role Editor, Members, or custom code). Create roles like "wholesale_customer" and "wholesale_vip", then configure them in the plugin settings.

= Will retail customers see wholesale prices? =

No. Only logged-in users with the configured wholesale roles see discounted prices. Retail customers and guests continue to see standard pricing.

= What happens if a wholesale user logs out? =

They immediately see retail prices (or the "login to see prices" message if price hiding is enabled).

= Does it work with WooCommerce Subscriptions? =

Yes. The plugin hooks into core WooCommerce price filters, so subscription products are supported.

== Screenshots ==

1. Role settings — configure discount type, value, and label per role.
2. Per-product meta box — fixed price, quantity tiers, and minimum order.
3. Frontend — wholesale price with strikethrough retail price and role label.

== Changelog ==

See changelog.txt.

= 1.0.0 =
* Initial release