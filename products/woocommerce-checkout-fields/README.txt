=== WooCommerce Custom Checkout Fields Manager ===
Contributors: sandydigital
Tags: woocommerce, checkout fields, custom fields, checkout, drag drop, field manager
Requires at least: 5.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Add, reorder, hide, and manage custom checkout fields on your WooCommerce checkout page. Drag-and-drop reorder, conditional display, 9 field types, and validation — no coding required.

== Description ==

WooCommerce Custom Checkout Fields Manager gives you full control over your checkout form. Reorder billing and shipping fields via drag-and-drop, add custom fields of any type, apply conditional logic, and validate input — all from a clean admin interface.

= Key Features =

* **Drag-and-Drop Reorder** — Rearrange billing, shipping, and order fields by dragging. Changes apply instantly to your checkout page.
* **9 Field Types** — Text, Email, Phone, Number, Textarea, Select Dropdown, Radio Buttons, Checkbox, and Date Picker.
* **Custom Fields** — Add unlimited custom fields to Billing, Shipping, or Order sections. Fully editable and deletable.
* **Built-in Field Control** — Hide any default WooCommerce field (e.g., remove Company Name or Address Line 2). Reorder built-in fields alongside your custom ones.
* **Conditional Display** — Show/hide fields based on the value of another field. Show "Delivery Instructions" only when "Ship to different address" is checked.
* **Validation Rules** — Email format, phone format, numeric range (min/max), and custom regex patterns with custom error messages.
* **Required/Optional Toggle** — Make any field required or optional per field.
* **Order & Email Integration** — Custom field values appear in the admin order screen, thank-you page, and order confirmation emails.
* **Priority-Based Sorting** — Each field has a numeric priority. Drag-and-drop updates priorities automatically.
* **Zero Code Required** — Everything managed through the WordPress admin at WooCommerce → Checkout Fields.

= How It Works =

1. Go to WooCommerce → Checkout Fields Manager.
2. Drag fields to reorder them. Hide fields you don't need.
3. Add custom fields with the form at the bottom.
4. Changes apply instantly to your store's checkout page.

== Installation ==

1. Upload `woocommerce-checkout-fields.php` to `/wp-content/plugins/`.
2. Activate through WordPress Plugins screen.
3. Go to WooCommerce → Checkout Fields to start managing.

== Frequently Asked Questions ==

= Will this break my checkout page? =

No. The plugin hooks into WooCommerce's native `woocommerce_checkout_fields` filter and follows WordPress/WooCommerce standards. Deactivating the plugin restores the default checkout layout.

= Does it work with custom themes? =

Yes. The plugin modifies the field array that WooCommerce renders. As long as your theme uses the standard WooCommerce checkout hooks, everything works.

= What happens to saved custom field data on uninstall? =

The plugin configuration is removed, but custom field values saved to orders remain in the order meta.

== Screenshots ==

1. Drag-and-drop field manager — reorder billing, shipping, and order fields with visual drag handles.
2. Add custom field form — choose type, section, label, and validation rules.
3. Checkout page showing custom fields in action.

== Changelog ==

See changelog.txt.

= 1.0.0 =
* Initial release