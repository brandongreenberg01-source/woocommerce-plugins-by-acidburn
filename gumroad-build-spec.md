# WooCommerce Gumroad Store — Build Spec

## Overview
Build 5 WooCommerce plugin snippets and list them on Gumroad as individual products + bundles.
Gumroad account: greenberg40.gumroad.com
Gumroad API token: CyGGoEVPli0MT01j_6LY_-KJ_S8tmQEyaAy8zDcsBGo
API base: https://api.gumroad.com/v2

## Product 1: Order Auto-Complete
**File:** woocommerce-auto-complete.php
**Price:** $19
**Description:** Automatically completes WooCommerce orders for virtual/downloadable products. Supports product type filtering, payment method restrictions, and bulk processing.
**Hooks:** woocommerce_order_status_processing, woocommerce_payment_complete
**Features:** Toggle per product type, exclude specific payment methods, admin order list bulk action
**Gumroad product:** Auto-complete snippet, individual

## Product 2: Product Badge System
**File:** woocommerce-custom-badges.php
**Price:** $19
**Description:** Add custom "Sale", "New", "Featured" badges to WooCommerce products. Fully customizable CSS, conditional display rules.
**Hooks:** woocommerce_before_shop_loop_item_title, woocommerce_before_single_product_summary
**Features:** Badge text/custom CSS, per-product badge override, position selector, scheduled "New" badge expiry
**Gumroad product:** Badge system, individual

## Product 3: Stock Alert System
**File:** woocommerce-stock-alerts.php
**Price:** $29
**Description:** Email alerts when products hit custom low stock thresholds. Multiple recipients, per-product thresholds, HTML templates.
**Hooks:** woocommerce_reduce_order_stock, woocommerce_product_set_stock_status
**Features:** Per-product threshold, multiple email recipients, daily digest option, threshold breach log
**Gumroad product:** Stock alerts, individual

## Product 4: Custom Checkout Fields
**File:** woocommerce-checkout-fields.php
**Price:** $29
**Description:** Add, remove, reorder WooCommerce checkout fields via admin interface. No coding required.
**Hooks:** woocommerce_checkout_fields, woocommerce_checkout_process, woocommerce_checkout_update_order_meta
**Features:** Drag-drop field reorder, conditional display, custom field types, validation rules
**Gumroad product:** Checkout fields, individual

## Product 5: Wholesale Pricing
**File:** woocommerce-wholesale-pricing.php
**Price:** $39
**Description:** Role-based wholesale pricing with quantity tiers and minimum order amounts.
**Hooks:** woocommerce_get_price_html, woocommerce_product_get_price, woocommerce_variation_prices_price
**Features:** User role tiers, quantity discounts, minimum order by role, hide prices from non-wholesale
**Gumroad product:** Wholesale pricing, individual

## Bundles (List AFTER individual products)
**Essential Pack** (all 5): $99 — created as fixed-price bundle on Gumroad
**Store Manager Pack** (Products 1+3+5): $59 — created as fixed-price bundle

## Product Package Structure (each product)
```
product-name/
  plugin-file.php          # Main PHP file with header, encoding, WP/WC checks
  README.txt               # Installation instructions
  changelog.txt            # Version 1.0.0
  screenshots/             # At least 3 screenshots (before, after, settings)
    01-before.png
    02-after.png  
    03-settings.png
```

## Gumroad Listing Requirements (each product)
- Title: "WooCommerce [Feature Name] — Simple PHP Plugin"
- Price as specified above
- Description: 3 paragraphs — what it does, how to install, what's included
- Tags: woocommerce, wordpress, plugin, ecommerce, [specific keyword]
- Cover image generated via AI (use image_generate tool)
- Screenshots uploaded

## Build Order
1. Product 1 (Order Auto-Complete) — simplest, quickest
2. Product 2 (Product Badges) — also simple
3. Product 3 (Stock Alerts) — medium complexity
4. Product 4 (Checkout Fields) — medium, needs admin UI
5. Product 5 (Wholesale Pricing) — most complex
6. Essential Pack bundle
7. Store Manager Pack bundle

## Code Standards
- WordPress coding style
- Proper escaping (esc_html, esc_attr, wp_kses)
- Nonce verification for admin forms
- WooCommerce dependency check on activation
- Uninstall hook to clean up options
- PHP 7.4+ compatible
- WP 5.0+, WooCommerce 4.0+ compatible

## Verification
After listing each product, verify the Gumroad listing exists and has correct price via API.
After all products, verify the bundle products exist.
