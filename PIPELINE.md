# Gumroad WooCommerce Store — Pipeline Architecture

## COMPETITIVE LANDSCAPE
Top WooCommerce products on Gumroad (from discover page):
- ZimmWriter (Subscription): $24.97/mo, 4.8★, 167 reviews — writing tool
- Breakdance plugins (Gallery, Filter) by Bence Boruzs: $29-39, 5.0★ — page-builder specific
- Bricks Mega Menu by Udoro: $50, 4.8★ — theme-specific
- WooCommerce addon for Voxel: $409 — niche premium

Our positioning: Standalone PHP snippets, no page-builder dependency, solves universal WooCommerce pain points.

## PIPELINE

### Phase 1: Research + Create (Plugin Maker Bots)
Each bot studies top-selling Gumroad products in its category, identifies what makes them successful (pricing, description, features), and creates a better version.

5 plugins already exist:
1. Order Auto-Complete ($19) — 19KB
2. Custom Product Badges ($19) — 22KB
3. Stock Alert System ($29) — 32KB
4. Custom Checkout Fields ($29) — 29KB
5. Wholesale Pricing ($39) — 36KB

### Phase 2: Triad Verification
Each plugin reviewed by 3 frontier models (Claude Sonnet 4, GPT-5.4-mini, Gemini 2.5 Pro) for:
- Security vulnerabilities (nonce, escaping, sanitization)
- WordPress/WooCommerce best practices
- Code quality and edge cases
- Only PASS = ready to list

### Phase 3: Listing Bot (deepseek/deepseek-v4-pro:nitro)
Smart model creates ONE listing at a time:
1. Check Gumroad for existing products
2. Create next missing product with proper HTML description
3. Wait 5+ seconds between API calls
4. Verify listing exists
5. After ALL 5 individual products, create bundles:
   - Essential Pack (all 5): $99
   - Store Manager Pack (1+3+5): $59

## Plugin Directory
/opt/data/sandy_ops/gumroad-store/plugins/
├── woocommerce-auto-complete/        # $19 — virtual/downloadable auto-complete
├── woocommerce-custom-badges/        # $19 — Sale/New/Featured badges
├── woocommerce-stock-alerts/         # $29 — low stock notifications
├── woocommerce-checkout-fields/      # $29 — drag-drop checkout fields
└── woocommerce-wholesale-pricing/    # $39 — role-based pricing tiers

## Commands
- Run triad: execute_code with OpenRouter API call to 3 models
- Run listing: bash /opt/data/sandy_ops/gumroad-store/run-listing.sh
- Check store: curl -s "https://api.gumroad.com/v2/products?access_token=TOKEN"
- Git repo: /opt/data/sandy_ops/gumroad-store/
