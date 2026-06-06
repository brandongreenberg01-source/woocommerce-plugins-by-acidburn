<?php
/**
 * Plugin Name: WooCommerce Dynamic Pricing Rules
 * Plugin URI:  https://acidburn.agency/
 * Description: Advanced dynamic pricing rules for WooCommerce — product/category discounts, BOGO deals, cart-based discounts, and per-role pricing.
 * Version:     1.0.0
 * Author:      AcidBurn
 * Author URI:  https://acidburn.agency/
 * License:     GPL v2 or later
 * Text Domain: wc-dynamic-pricing
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP:      7.4
 * WC requires at least: 4.0
 * WC tested up to:      8.5
 *
 * @package WC_Dynamic_Pricing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin class — Singleton final pattern
 */
final class WC_Dynamic_Pricing {

	/**
	 * Singleton instance
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Option key for saved rules
	 *
	 * @var string
	 */
	const RULES_OPTION_KEY = 'wc_dynamic_pricing_rules';

	/**
	 * Option key for plugin settings
	 *
	 * @var string
	 */
	const SETTINGS_OPTION_KEY = 'wc_dynamic_pricing_settings';

	// -------------------------------------------------------------------------
	//  Singleton
	// -------------------------------------------------------------------------

	/**
	 * Get singleton instance
	 *
	 * @return self
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor — hooks all actions / filters
	 */
	private function __construct() {
		// Bail if WooCommerce is not active.
		if ( ! function_exists( 'WC' ) ) {
			return;
		}

		// Admin hooks.
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ), 99 );
		add_filter( 'woocommerce_screen_ids', array( $this, 'add_screen_id' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

		// Register settings.
		add_action( 'admin_init', array( $this, 'register_settings' ) );

		// AJAX handlers.
		add_action( 'wp_ajax_wcdp_save_rule', array( $this, 'ajax_save_rule' ) );
		add_action( 'wp_ajax_wcdp_delete_rule', array( $this, 'ajax_delete_rule' ) );
		add_action( 'wp_ajax_wcdp_toggle_rule', array( $this, 'ajax_toggle_rule' ) );

		// Price filters.
		add_filter( 'woocommerce_product_get_price', array( $this, 'apply_product_price_rule' ), 99, 2 );
		add_filter( 'woocommerce_product_get_sale_price', array( $this, 'apply_product_price_rule' ), 99, 2 );
		add_filter( 'woocommerce_product_variation_get_price', array( $this, 'apply_product_price_rule' ), 99, 2 );
		add_filter( 'woocommerce_product_variation_get_sale_price', array( $this, 'apply_product_price_rule' ), 99, 2 );
		add_filter( 'woocommerce_variable_product_sale_price', array( $this, 'apply_variable_min_price_rule' ), 99, 2 );
		add_filter( 'woocommerce_variable_product_price', array( $this, 'apply_variable_min_price_rule' ), 99, 2 );

		// Display filters.
		add_filter( 'woocommerce_get_price_html', array( $this, 'modify_price_html' ), 99, 2 );

		// Cart hooks.
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_cart_rules' ), 99, 1 );
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'apply_cart_total_discount' ), 99, 1 );
		add_filter( 'woocommerce_cart_item_price', array( $this, 'modify_cart_item_price' ), 99, 3 );

		// BOGO.
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_bogo_rules' ), 98, 1 );

		// Uninstall hook.
		register_uninstall_hook( __FILE__, array( __CLASS__, 'uninstall' ) );
	}

	/**
	 * Prevent cloning
	 */
	private function __clone() {}

	/**
	 * Prevent unserialization
	 */
	public function __wakeup() {
		throw new \Exception( 'Cannot unserialize singleton' );
	}

	// -------------------------------------------------------------------------
	//  Uninstall
	// -------------------------------------------------------------------------

	/**
	 * Plugin uninstall — full cleanup
	 */
	public static function uninstall() {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			exit;
		}
		delete_option( self::RULES_OPTION_KEY );
		delete_option( self::SETTINGS_OPTION_KEY );
	}

	// -------------------------------------------------------------------------
	//  Admin Menu
	// -------------------------------------------------------------------------

	/**
	 * Add sub-menu page under WooCommerce
	 */
	public function add_admin_menu() {
		add_submenu_page(
			'woocommerce',
			esc_html__( 'Dynamic Pricing', 'wc-dynamic-pricing' ),
			esc_html__( 'Dynamic Pricing', 'wc-dynamic-pricing' ),
			'manage_woocommerce',
			'wc-dynamic-pricing',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Register screen ID for WooCommerce compatibility
	 *
	 * @param array $screens Screen IDs.
	 * @return array
	 */
	public function add_screen_id( $screens ) {
		$screens[] = 'woocommerce_page_wc-dynamic-pricing';
		return $screens;
	}

	// -------------------------------------------------------------------------
	//  Register Settings
	// -------------------------------------------------------------------------

	/**
	 * Register plugin settings with sanitize callback
	 */
	public function register_settings() {
		register_setting(
			'wc_dynamic_pricing_settings_group',
			self::SETTINGS_OPTION_KEY,
			array(
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => array(
					'enable_logging'     => 'no',
					'apply_to_sale_items' => 'no',
				),
			)
		);
	}

	/**
	 * Sanitize settings
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		if ( ! is_array( $input ) ) {
			return array();
		}
		$sanitized = array();
		$sanitized['enable_logging']      = isset( $input['enable_logging'] ) && 'yes' === $input['enable_logging'] ? 'yes' : 'no';
		$sanitized['apply_to_sale_items'] = isset( $input['apply_to_sale_items'] ) && 'yes' === $input['apply_to_sale_items'] ? 'yes' : 'no';
		return $sanitized;
	}

	// -------------------------------------------------------------------------
	//  Admin Assets (inline only)
	// -------------------------------------------------------------------------

	/**
	 * Enqueue admin CSS and JS inline
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( 'woocommerce_page_wc-dynamic-pricing' !== $hook ) {
			return;
		}

		// Inline CSS.
		$css = '
		.wcdp-wrap { max-width: 1200px; margin: 20px 0; }
		.wcdp-wrap h1 { margin-bottom: 16px; }
		.wcdp-rules-table { width: 100%; border-collapse: collapse; margin: 20px 0; background: #fff; }
		.wcdp-rules-table th, .wcdp-rules-table td { text-align: left; padding: 10px 12px; border: 1px solid #c3c4c7; }
		.wcdp-rules-table thead th { background: #f0f0f1; font-weight: 600; }
		.wcdp-rules-table .wcdp-status { display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 12px; font-weight: 600; }
		.wcdp-rules-table .wcdp-status-active { background: #c6e1c6; color: #2c5f2c; }
		.wcdp-rules-table .wcdp-status-inactive { background: #f1adad; color: #6b1a1a; }
		.wcdp-rule-form { background: #fff; padding: 20px; border: 1px solid #c3c4c7; margin: 20px 0; }
		.wcdp-rule-form h2 { margin-top: 0; }
		.wcdp-rule-form table.form-table { margin: 0; }
		.wcdp-rule-form table.form-table th { width: 200px; }
		.wcdp-actions { margin: 16px 0; }
		.wcdp-actions .button { margin-right: 8px; }
		.wcdp-rule-type-toggle { margin: 16px 0; }
		.wcdp-rule-type-toggle label { margin-right: 20px; font-weight: 500; }
		.wcdp-bogo-fields, .wcdp-cart-fields, .wcdp-standard-fields { border-left: 3px solid #2271b1; padding-left: 16px; margin: 12px 0; }
		.wcdp-notice { padding: 10px 14px; margin: 12px 0; border-radius: 4px; }
		.wcdp-notice-success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; }
		.wcdp-notice-error { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; }
		.wcdp-rule-form select[multiple] { min-height: 100px; }
		@media screen and (max-width:782px) { .wcdp-rule-form table.form-table th { width: auto; } }
		';
		wp_add_inline_style( 'woocommerce_admin_styles', $css );

		// Inline JS.
		$js = '
		(function($) {
			"use strict";
			var wcdp = {
				init: function() {
					$(document).on("change", "#wcdp_rule_type", this.toggleRuleType);
					$(document).on("click", ".wcdp-delete-rule", this.confirmDelete);
					$(document).on("click", ".wcdp-toggle-rule", this.toggleStatus);
					$(document).on("click", ".wcdp-edit-rule", this.editRule);
					$(document).on("click", "#wcdp-cancel-edit", this.cancelEdit);
					this.toggleRuleType();
				},
				toggleRuleType: function() {
					var val = $("#wcdp_rule_type").val();
					$(".wcdp-bogo-fields, .wcdp-cart-fields, .wcdp-standard-fields").hide();
					if (val === "bogo") {
						$(".wcdp-bogo-fields").show();
					} else if (val === "cart") {
						$(".wcdp-cart-fields").show();
					} else {
						$(".wcdp-standard-fields").show();
					}
				},
				confirmDelete: function(e) {
					if (!confirm("' . esc_js( __( 'Delete this pricing rule? This cannot be undone.', 'wc-dynamic-pricing' ) ) . '")) {
						e.preventDefault();
					}
				},
				toggleStatus: function(e) {
					e.preventDefault();
					var btn = $(this);
					$.post(ajaxurl, {
						action: "wcdp_toggle_rule",
						rule_id: btn.data("rule-id"),
						_wpnonce: "' . esc_js( wp_create_nonce( 'wcdp_toggle_rule' ) ) . '"
					}, function(resp) {
						if (resp.success) {
							location.reload();
						}
					});
				},
				editRule: function(e) {
					e.preventDefault();
					var row = $(this).closest("tr");
					$("#wcdp_rule_id").val(row.data("rule-id"));
					$("#wcdp_rule_name").val(row.data("name"));
					$("#wcdp_rule_type").val(row.data("type")).trigger("change");
					$("#wcdp_discount_type").val(row.data("discount-type"));
					$("#wcdp_discount_value").val(row.data("discount-value"));
					$("#wcdp_min_qty").val(row.data("min-qty"));
					$("#wcdp_apply_to").val(row.data("apply-to"));
					var products = (row.data("products") || "").split(",").map(function(v){return v.trim();}).filter(Boolean);
					$("#wcdp_product_ids").val(products.join(","));
					var categories = (row.data("categories") || "").split(",").map(function(v){return v.trim();}).filter(Boolean);
					$("#wcdp_category_ids").val(categories.join(","));
					$("#wcdp_user_roles").val((row.data("roles") || "").split(","));
					$("#wcdp_bogo_buy_qty").val(row.data("bogo-buy"));
					$("#wcdp_bogo_free_qty").val(row.data("bogo-free"));
					$("#wcdp_bogo_discount_pct").val(row.data("bogo-pct"));
					$("#wcdp_cart_min_total").val(row.data("cart-min"));
					$("#wcdp_cart_discount_pct").val(row.data("cart-pct"));
					$("html, body").animate({scrollTop: $("#wcdp-rule-form").offset().top - 50}, 400);
				},
				cancelEdit: function(e) {
					e.preventDefault();
					$("#wcdp-rule-form")[0].reset();
					$("#wcdp_rule_id").val("");
					$("#wcdp_rule_type").trigger("change");
				}
			};
			$(document).ready(function(){ wcdp.init(); });
		})(jQuery);
		';
		wp_add_inline_script( 'jquery', $js );
	}

	// -------------------------------------------------------------------------
	//  Admin Page
	// -------------------------------------------------------------------------

	/**
	 * Render the admin settings page
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'wc-dynamic-pricing' ) );
		}
		$rules  = $this->get_rules();
		$nonce  = wp_create_nonce( 'wcdp_save_rule' );
		$del_nonce = wp_create_nonce( 'wcdp_delete_rule' );
		?>
		<div class="wrap wcdp-wrap">
			<h1><?php echo esc_html__( 'Dynamic Pricing Rules', 'wc-dynamic-pricing' ); ?></h1>

			<?php settings_errors( 'wc_dynamic_pricing_settings_group' ); ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'wc_dynamic_pricing_settings_group' ); ?>
				<?php $settings = get_option( self::SETTINGS_OPTION_KEY, array() ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php echo esc_html__( 'Enable Logging', 'wc-dynamic-pricing' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::SETTINGS_OPTION_KEY ); ?>[enable_logging]" value="yes" <?php checked( isset( $settings['enable_logging'] ) ? $settings['enable_logging'] : 'no', 'yes' ); ?> />
								<?php echo esc_html__( 'Log price rule applications for debugging.', 'wc-dynamic-pricing' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Apply to Sale Items', 'wc-dynamic-pricing' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::SETTINGS_OPTION_KEY ); ?>[apply_to_sale_items]" value="yes" <?php checked( isset( $settings['apply_to_sale_items'] ) ? $settings['apply_to_sale_items'] : 'no', 'yes' ); ?> />
								<?php echo esc_html__( 'Apply dynamic pricing rules even if the product is already on sale.', 'wc-dynamic-pricing' ); ?>
							</label>
						</td>
					</tr>
				</table>
				<?php submit_button( esc_html__( 'Save Settings', 'wc-dynamic-pricing' ) ); ?>
			</form>

			<hr />

			<div class="wcdp-actions">
				<a href="#wcdp-rule-form" class="button button-primary" onclick="document.getElementById('wcdp-cancel-edit').click();document.getElementById('wcdp-rule-form').scrollIntoView({behavior:'smooth'});return false;">
					<?php echo esc_html__( '+ Add New Rule', 'wc-dynamic-pricing' ); ?>
				</a>
			</div>

			<?php $this->render_rule_form( $rules, $nonce, $del_nonce ); ?>

			<?php $this->render_rules_table( $rules, $del_nonce ); ?>
		</div>
		<?php
	}

	/**
	 * Render the add/edit rule form
	 *
	 * @param array  $rules     Existing rules.
	 * @param string $nonce     Save nonce.
	 * @param string $del_nonce Delete nonce.
	 */
	private function render_rule_form( $rules, $nonce, $del_nonce ) {
		$product_categories = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);
		$editable_roles     = wp_roles()->roles;
		?>
		<div id="wcdp-rule-form" class="wcdp-rule-form">
			<h2><span id="wcdp-form-title"><?php echo esc_html__( 'Add / Edit Pricing Rule', 'wc-dynamic-pricing' ); ?></span></h2>
			<form id="wcdp-rule-form-fields" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wcdp_save_rule', 'wcdp_save_rule_nonce' ); ?>
				<input type="hidden" name="action" value="wcdp_save_rule_redirect" />
				<input type="hidden" id="wcdp_rule_id" name="rule_id" value="" />

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wcdp_rule_name"><?php echo esc_html__( 'Rule Name', 'wc-dynamic-pricing' ); ?></label></th>
						<td><input type="text" id="wcdp_rule_name" name="rule_name" class="regular-text" maxlength="100" required /></td>
					</tr>
					<tr>
						<th scope="row"><label for="wcdp_rule_type"><?php echo esc_html__( 'Rule Type', 'wc-dynamic-pricing' ); ?></label></th>
						<td>
							<select id="wcdp_rule_type" name="rule_type">
								<option value="standard"><?php echo esc_html__( 'Standard (Product / Category)', 'wc-dynamic-pricing' ); ?></option>
								<option value="bogo"><?php echo esc_html__( 'BOGO (Buy X Get Y)', 'wc-dynamic-pricing' ); ?></option>
								<option value="cart"><?php echo esc_html__( 'Cart Total Based', 'wc-dynamic-pricing' ); ?></option>
							</select>
						</td>
					</tr>

					<?php
					// ── Standard fields ──
					$this->render_std_fields();
					// ── BOGO fields ──
					$this->render_bogo_fields();
					// ── Cart fields ──
					$this->render_cart_fields();
					// ── Role / Status ──
					$this->render_role_status_fields( $editable_roles );
					?>
				</table>

				<p class="submit">
					<?php submit_button( esc_html__( 'Save Rule', 'wc-dynamic-pricing' ), 'primary', 'wcdp_submit_rule', false ); ?>
					<a href="#" id="wcdp-cancel-edit" class="button" style="margin-left:8px;"><?php echo esc_html__( 'Cancel', 'wc-dynamic-pricing' ); ?></a>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Render standard rule fields
	 */
	private function render_std_fields() {
		?>
		<tr class="wcdp-standard-fields">
			<th scope="row"><label for="wcdp_discount_type"><?php echo esc_html__( 'Discount Type', 'wc-dynamic-pricing' ); ?></label></th>
			<td>
				<select id="wcdp_discount_type" name="discount_type">
					<option value="percentage"><?php echo esc_html__( 'Percentage Off (%)', 'wc-dynamic-pricing' ); ?></option>
					<option value="fixed_amount"><?php echo esc_html__( 'Fixed Amount Off ($)', 'wc-dynamic-pricing' ); ?></option>
					<option value="fixed_price"><?php echo esc_html__( 'Fixed Price ($)', 'wc-dynamic-pricing' ); ?></option>
				</select>
			</td>
		</tr>
		<tr class="wcdp-standard-fields">
			<th scope="row"><label for="wcdp_discount_value"><?php echo esc_html__( 'Discount Value', 'wc-dynamic-pricing' ); ?></label></th>
			<td><input type="number" id="wcdp_discount_value" name="discount_value" step="0.01" min="0" max="999999" /></td>
		</tr>
		<tr class="wcdp-standard-fields">
			<th scope="row"><label for="wcdp_min_qty"><?php echo esc_html__( 'Minimum Quantity', 'wc-dynamic-pricing' ); ?></label></th>
			<td><input type="number" id="wcdp_min_qty" name="min_qty" step="1" min="1" value="1" /></td>
		</tr>
		<tr class="wcdp-standard-fields">
			<th scope="row"><label for="wcdp_apply_to"><?php echo esc_html__( 'Apply To', 'wc-dynamic-pricing' ); ?></label></th>
			<td>
				<select id="wcdp_apply_to" name="apply_to">
					<option value="all"><?php echo esc_html__( 'All Products', 'wc-dynamic-pricing' ); ?></option>
					<option value="products"><?php echo esc_html__( 'Specific Products', 'wc-dynamic-pricing' ); ?></option>
					<option value="categories"><?php echo esc_html__( 'Specific Categories', 'wc-dynamic-pricing' ); ?></option>
				</select>
			</td>
		</tr>
		<tr class="wcdp-standard-fields">
			<th scope="row"><label for="wcdp_product_ids"><?php echo esc_html__( 'Product IDs (comma-separated)', 'wc-dynamic-pricing' ); ?></label></th>
			<td><input type="text" id="wcdp_product_ids" name="product_ids" class="regular-text" placeholder="<?php echo esc_attr__( 'e.g. 123,456,789', 'wc-dynamic-pricing' ); ?>" /></td>
		</tr>
		<tr class="wcdp-standard-fields">
			<th scope="row"><label for="wcdp_category_ids"><?php echo esc_html__( 'Category IDs (comma-separated)', 'wc-dynamic-pricing' ); ?></label></th>
			<td><input type="text" id="wcdp_category_ids" name="category_ids" class="regular-text" placeholder="<?php echo esc_attr__( 'e.g. 12,34,56', 'wc-dynamic-pricing' ); ?>" /></td>
		</tr>
		<?php
	}

	/**
	 * Render BOGO fields
	 */
	private function render_bogo_fields() {
		?>
		<tr class="wcdp-bogo-fields">
			<th scope="row"><label for="wcdp_bogo_buy_qty"><?php echo esc_html__( 'Buy Quantity (X)', 'wc-dynamic-pricing' ); ?></label></th>
			<td><input type="number" id="wcdp_bogo_buy_qty" name="bogo_buy_qty" step="1" min="1" value="1" /></td>
		</tr>
		<tr class="wcdp-bogo-fields">
			<th scope="row"><label for="wcdp_bogo_free_qty"><?php echo esc_html__( 'Free/Discounted Quantity (Y)', 'wc-dynamic-pricing' ); ?></label></th>
			<td><input type="number" id="wcdp_bogo_free_qty" name="bogo_free_qty" step="1" min="1" value="1" /></td>
		</tr>
		<tr class="wcdp-bogo-fields">
			<th scope="row"><label for="wcdp_bogo_discount_pct"><?php echo esc_html__( 'Discount % on Y Items', 'wc-dynamic-pricing' ); ?></label></th>
			<td>
				<input type="number" id="wcdp_bogo_discount_pct" name="bogo_discount_pct" step="1" min="0" max="100" value="100" />
				<p class="description"><?php echo esc_html__( '100% = completely free. 50% = half off on the Y items.', 'wc-dynamic-pricing' ); ?></p>
			</td>
		</tr>
		<tr class="wcdp-bogo-fields">
			<th scope="row"><label for="wcdp_bogo_apply_to"><?php echo esc_html__( 'Apply BOGO To', 'wc-dynamic-pricing' ); ?></label></th>
			<td>
				<select id="wcdp_bogo_apply_to" name="bogo_apply_to">
					<option value="all"><?php echo esc_html__( 'All Products', 'wc-dynamic-pricing' ); ?></option>
					<option value="products"><?php echo esc_html__( 'Specific Products', 'wc-dynamic-pricing' ); ?></option>
					<option value="categories"><?php echo esc_html__( 'Specific Categories', 'wc-dynamic-pricing' ); ?></option>
				</select>
			</td>
		</tr>
		<tr class="wcdp-bogo-fields">
			<th scope="row"><label for="wcdp_bogo_product_ids"><?php echo esc_html__( 'BOGO Product IDs', 'wc-dynamic-pricing' ); ?></label></th>
			<td><input type="text" id="wcdp_bogo_product_ids" name="bogo_product_ids" class="regular-text" placeholder="<?php echo esc_attr__( 'e.g. 123,456', 'wc-dynamic-pricing' ); ?>" /></td>
		</tr>
		<tr class="wcdp-bogo-fields">
			<th scope="row"><label for="wcdp_bogo_category_ids"><?php echo esc_html__( 'BOGO Category IDs', 'wc-dynamic-pricing' ); ?></label></th>
			<td><input type="text" id="wcdp_bogo_category_ids" name="bogo_category_ids" class="regular-text" placeholder="<?php echo esc_attr__( 'e.g. 12,34', 'wc-dynamic-pricing' ); ?>" /></td>
		</tr>
		<?php
	}

	/**
	 * Render cart-based fields
	 */
	private function render_cart_fields() {
		?>
		<tr class="wcdp-cart-fields">
			<th scope="row"><label for="wcdp_cart_min_total"><?php echo esc_html__( 'Minimum Cart Total ($)', 'wc-dynamic-pricing' ); ?></label></th>
			<td><input type="number" id="wcdp_cart_min_total" name="cart_min_total" step="0.01" min="0" value="50" /></td>
		</tr>
		<tr class="wcdp-cart-fields">
			<th scope="row"><label for="wcdp_cart_discount_pct"><?php echo esc_html__( 'Cart Discount (%)', 'wc-dynamic-pricing' ); ?></label></th>
			<td><input type="number" id="wcdp_cart_discount_pct" name="cart_discount_pct" step="0.5" min="0" max="100" value="10" /></td>
		</tr>
		<?php
	}

	/**
	 * Render role / status fields
	 *
	 * @param array $editable_roles WordPress roles.
	 */
	private function render_role_status_fields( $editable_roles ) {
		?>
		<tr>
			<th scope="row"><label for="wcdp_user_roles"><?php echo esc_html__( 'User Roles (leave empty = all)', 'wc-dynamic-pricing' ); ?></label></th>
			<td>
				<select id="wcdp_user_roles" name="user_roles[]" multiple style="min-height:100px;min-width:200px;">
					<?php foreach ( $editable_roles as $role_key => $role_data ) : ?>
						<option value="<?php echo esc_attr( $role_key ); ?>"><?php echo esc_html( $role_data['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php echo esc_html__( 'Hold Ctrl/Cmd to select multiple. Leave empty to apply to all roles.', 'wc-dynamic-pricing' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Active', 'wc-dynamic-pricing' ); ?></th>
			<td>
				<label>
					<input type="checkbox" id="wcdp_rule_active" name="rule_active" value="1" checked />
					<?php echo esc_html__( 'Enable this rule immediately.', 'wc-dynamic-pricing' ); ?>
				</label>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render the rules table
	 *
	 * @param array  $rules     Existing rules.
	 * @param string $del_nonce Delete nonce.
	 */
	private function render_rules_table( $rules, $del_nonce ) {
		?>
		<h2><?php echo esc_html__( 'Existing Rules', 'wc-dynamic-pricing' ); ?></h2>
		<?php if ( empty( $rules ) ) : ?>
			<p><?php echo esc_html__( 'No pricing rules defined yet. Create your first rule above.', 'wc-dynamic-pricing' ); ?></p>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped wcdp-rules-table">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Name', 'wc-dynamic-pricing' ); ?></th>
						<th><?php echo esc_html__( 'Type', 'wc-dynamic-pricing' ); ?></th>
						<th><?php echo esc_html__( 'Discount', 'wc-dynamic-pricing' ); ?></th>
						<th><?php echo esc_html__( 'Target', 'wc-dynamic-pricing' ); ?></th>
						<th><?php echo esc_html__( 'Roles', 'wc-dynamic-pricing' ); ?></th>
						<th><?php echo esc_html__( 'Status', 'wc-dynamic-pricing' ); ?></th>
						<th><?php echo esc_html__( 'Actions', 'wc-dynamic-pricing' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rules as $id => $rule ) : ?>
						<?php
						$type_label = $this->get_rule_type_label( $rule );
						$discount_desc = $this->get_rule_discount_desc( $rule );
						$target_desc  = $this->get_rule_target_desc( $rule );
						$role_names   = $this->get_rule_role_names( $rule );
						$is_active    = ! empty( $rule['active'] );
						?>
						<tr data-rule-id="<?php echo esc_attr( $id ); ?>"
							data-name="<?php echo esc_attr( $rule['name'] ?? '' ); ?>"
							data-type="<?php echo esc_attr( $rule['type'] ?? 'standard' ); ?>"
							data-discount-type="<?php echo esc_attr( $rule['discount_type'] ?? '' ); ?>"
							data-discount-value="<?php echo esc_attr( $rule['discount_value'] ?? '' ); ?>"
							data-min-qty="<?php echo esc_attr( $rule['min_qty'] ?? 1 ); ?>"
							data-apply-to="<?php echo esc_attr( $rule['apply_to'] ?? 'all' ); ?>"
							data-products="<?php echo esc_attr( isset( $rule['product_ids'] ) ? implode( ',', (array) $rule['product_ids'] ) : '' ); ?>"
							data-categories="<?php echo esc_attr( isset( $rule['category_ids'] ) ? implode( ',', (array) $rule['category_ids'] ) : '' ); ?>"
							data-roles="<?php echo esc_attr( isset( $rule['user_roles'] ) ? implode( ',', (array) $rule['user_roles'] ) : '' ); ?>"
							data-bogo-buy="<?php echo esc_attr( $rule['bogo_buy_qty'] ?? '' ); ?>"
							data-bogo-free="<?php echo esc_attr( $rule['bogo_free_qty'] ?? '' ); ?>"
							data-bogo-pct="<?php echo esc_attr( $rule['bogo_discount_pct'] ?? '' ); ?>"
							data-cart-min="<?php echo esc_attr( $rule['cart_min_total'] ?? '' ); ?>"
							data-cart-pct="<?php echo esc_attr( $rule['cart_discount_pct'] ?? '' ); ?>">
							<td><?php echo esc_html( $rule['name'] ?? '' ); ?></td>
							<td><?php echo esc_html( $type_label ); ?></td>
							<td><?php echo esc_html( $discount_desc ); ?></td>
							<td><?php echo esc_html( $target_desc ); ?></td>
							<td><?php echo esc_html( $role_names ?: '—' ); ?></td>
							<td>
								<span class="wcdp-status <?php echo $is_active ? 'wcdp-status-active' : 'wcdp-status-inactive'; ?>">
									<?php echo $is_active ? esc_html__( 'Active', 'wc-dynamic-pricing' ) : esc_html__( 'Inactive', 'wc-dynamic-pricing' ); ?>
								</span>
							</td>
							<td>
								<button class="button button-small wcdp-edit-rule"><?php echo esc_html__( 'Edit', 'wc-dynamic-pricing' ); ?></button>
								<button class="button button-small wcdp-toggle-rule" data-rule-id="<?php echo esc_attr( $id ); ?>">
									<?php echo $is_active ? esc_html__( 'Deactivate', 'wc-dynamic-pricing' ) : esc_html__( 'Activate', 'wc-dynamic-pricing' ); ?>
								</button>
								<form method="post" style="display:inline;">
									<?php wp_nonce_field( 'wcdp_delete_rule', 'wcdp_delete_rule_nonce' ); ?>
									<input type="hidden" name="action" value="wcdp_delete_rule_redirect" />
									<input type="hidden" name="rule_id" value="<?php echo esc_attr( $id ); ?>" />
									<button type="submit" class="button button-small wcdp-delete-rule" style="color:#b32d2e;"><?php echo esc_html__( 'Delete', 'wc-dynamic-pricing' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	/**
	 * Get human-readable rule type label
	 *
	 * @param array $rule Rule data.
	 * @return string
	 */
	private function get_rule_type_label( $rule ) {
		$types = array(
			'standard' => __( 'Standard', 'wc-dynamic-pricing' ),
			'bogo'     => __( 'BOGO', 'wc-dynamic-pricing' ),
			'cart'     => __( 'Cart Based', 'wc-dynamic-pricing' ),
		);
		return isset( $types[ $rule['type'] ?? 'standard' ] ) ? $types[ $rule['type'] ?? 'standard' ] : __( 'Standard', 'wc-dynamic-pricing' );
	}

	/**
	 * Get human-readable discount description
	 *
	 * @param array $rule Rule data.
	 * @return string
	 */
	private function get_rule_discount_desc( $rule ) {
		$type = $rule['type'] ?? 'standard';
		if ( 'bogo' === $type ) {
			$buy   = isset( $rule['bogo_buy_qty'] ) ? (int) $rule['bogo_buy_qty'] : 1;
			$free  = isset( $rule['bogo_free_qty'] ) ? (int) $rule['bogo_free_qty'] : 1;
			$pct   = isset( $rule['bogo_discount_pct'] ) ? (int) $rule['bogo_discount_pct'] : 100;
			/* translators: 1: buy qty, 2: free qty, 3: discount percent */
			return sprintf( __( 'Buy %1$d get %2$d at %3$d%% off', 'wc-dynamic-pricing' ), $buy, $free, $pct );
		}
		if ( 'cart' === $type ) {
			$min = isset( $rule['cart_min_total'] ) ? (float) $rule['cart_min_total'] : 0;
			$pct = isset( $rule['cart_discount_pct'] ) ? (float) $rule['cart_discount_pct'] : 0;
			/* translators: 1: cart minimum, 2: discount percent */
			return sprintf( __( 'Cart > $%1$.2f → %2$d%% off', 'wc-dynamic-pricing' ), $min, (int) $pct );
		}
		$val  = isset( $rule['discount_value'] ) ? (float) $rule['discount_value'] : 0;
		$minq = isset( $rule['min_qty'] ) ? (int) $rule['min_qty'] : 1;
		switch ( $rule['discount_type'] ?? 'percentage' ) {
			case 'percentage':
				/* translators: 1: percent, 2: min qty */
				return sprintf( __( '%1$d%% off (min qty: %2$d)', 'wc-dynamic-pricing' ), (int) $val, $minq );
			case 'fixed_amount':
				/* translators: 1: amount, 2: min qty */
				return sprintf( __( '$%1$.2f off (min qty: %2$d)', 'wc-dynamic-pricing' ), $val, $minq );
			case 'fixed_price':
				/* translators: 1: price, 2: min qty */
				return sprintf( __( 'Fixed $%1$.2f (min qty: %2$d)', 'wc-dynamic-pricing' ), $val, $minq );
		}
		return '';
	}

	/**
	 * Get human-readable target description
	 *
	 * @param array $rule Rule data.
	 * @return string
	 */
	private function get_rule_target_desc( $rule ) {
		if ( 'cart' === ( $rule['type'] ?? 'standard' ) ) {
			return __( 'Entire Cart', 'wc-dynamic-pricing' );
		}
		$apply_to = $rule['apply_to'] ?? 'all';
		if ( 'all' === $apply_to ) {
			return __( 'All Products', 'wc-dynamic-pricing' );
		}
		if ( 'products' === $apply_to ) {
			$ids = isset( $rule['product_ids'] ) ? (array) $rule['product_ids'] : array();
			return sprintf(
				/* translators: %d: product count */
				__( '%d Product(s)', 'wc-dynamic-pricing' ),
				count( $ids )
			);
		}
		if ( 'categories' === $apply_to ) {
			$ids = isset( $rule['category_ids'] ) ? (array) $rule['category_ids'] : array();
			return sprintf(
				/* translators: %d: category count */
				__( '%d Category(ies)', 'wc-dynamic-pricing' ),
				count( $ids )
			);
		}
		return '';
	}

	/**
	 * Get comma-separated role display names
	 *
	 * @param array $rule Rule data.
	 * @return string
	 */
	private function get_rule_role_names( $rule ) {
		if ( empty( $rule['user_roles'] ) || ! is_array( $rule['user_roles'] ) ) {
			return '';
		}
		$all_roles  = wp_roles()->roles;
		$names      = array();
		foreach ( $rule['user_roles'] as $role_key ) {
			if ( isset( $all_roles[ $role_key ] ) ) {
				$names[] = $all_roles[ $role_key ]['name'];
			}
		}
		return implode( ', ', $names );
	}

	// -------------------------------------------------------------------------
	//  POST / Redirect Handlers (non-AJAX fallback)
	// -------------------------------------------------------------------------

	/**
	 * Handle rule save via POST redirect
	 */
	public function handle_save_rule_redirect() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'wc-dynamic-pricing' ) );
		}
		if ( ! isset( $_POST['wcdp_save_rule_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['wcdp_save_rule_nonce'] ), 'wcdp_save_rule' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'wc-dynamic-pricing' ) );
		}
		$this->save_rule_from_request( $_POST );
		wp_safe_redirect( admin_url( 'admin.php?page=wc-dynamic-pricing&saved=1' ) );
		exit;
	}

	/**
	 * Handle rule delete via POST redirect
	 */
	public function handle_delete_rule_redirect() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'wc-dynamic-pricing' ) );
		}
		if ( ! isset( $_POST['wcdp_delete_rule_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['wcdp_delete_rule_nonce'] ), 'wcdp_delete_rule' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'wc-dynamic-pricing' ) );
		}
		$rule_id = isset( $_POST['rule_id'] ) ? sanitize_text_field( wp_unslash( $_POST['rule_id'] ) ) : '';
		if ( $rule_id ) {
			$this->delete_rule_by_id( $rule_id );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=wc-dynamic-pricing&deleted=1' ) );
		exit;
	}

	// -------------------------------------------------------------------------
	//  AJAX Handlers
	// -------------------------------------------------------------------------

	/**
	 * AJAX: Save a rule
	 */
	public function ajax_save_rule() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-dynamic-pricing' ) ) );
		}
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ), 'wcdp_save_rule' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'wc-dynamic-pricing' ) ) );
		}
		$rule_id = $this->save_rule_from_request( $_POST );
		wp_send_json_success(
			array(
				'message' => __( 'Rule saved successfully.', 'wc-dynamic-pricing' ),
				'rule_id' => $rule_id,
			)
		);
	}

	/**
	 * AJAX: Delete a rule
	 */
	public function ajax_delete_rule() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-dynamic-pricing' ) ) );
		}
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ), 'wcdp_delete_rule' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'wc-dynamic-pricing' ) ) );
		}
		$rule_id = isset( $_POST['rule_id'] ) ? sanitize_text_field( wp_unslash( $_POST['rule_id'] ) ) : '';
		if ( ! $rule_id ) {
			wp_send_json_error( array( 'message' => __( 'No rule ID provided.', 'wc-dynamic-pricing' ) ) );
		}
		$this->delete_rule_by_id( $rule_id );
		wp_send_json_success( array( 'message' => __( 'Rule deleted.', 'wc-dynamic-pricing' ) ) );
	}

	/**
	 * AJAX: Toggle rule active status
	 */
	public function ajax_toggle_rule() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-dynamic-pricing' ) ) );
		}
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ), 'wcdp_toggle_rule' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'wc-dynamic-pricing' ) ) );
		}
		$rule_id = isset( $_POST['rule_id'] ) ? sanitize_text_field( wp_unslash( $_POST['rule_id'] ) ) : '';
		if ( ! $rule_id ) {
			wp_send_json_error( array( 'message' => __( 'No rule ID.', 'wc-dynamic-pricing' ) ) );
		}
		$rules = $this->get_rules();
		if ( isset( $rules[ $rule_id ] ) ) {
			$rules[ $rule_id ]['active'] = empty( $rules[ $rule_id ]['active'] ) ? true : false;
			$this->update_rules( $rules );
		}
		wp_send_json_success( array( 'message' => __( 'Status toggled.', 'wc-dynamic-pricing' ) ) );
	}

	// -------------------------------------------------------------------------
	//  Rule CRUD
	// -------------------------------------------------------------------------

	/**
	 * Get all rules from options
	 *
	 * @return array
	 */
	private function get_rules() {
		$rules = get_option( self::RULES_OPTION_KEY, array() );
		return is_array( $rules ) ? $rules : array();
	}

	/**
	 * Update rules in options
	 *
	 * @param array $rules Rule data.
	 */
	private function update_rules( $rules ) {
		update_option( self::RULES_OPTION_KEY, $rules );
	}

	/**
	 * Save a rule from a request array
	 *
	 * @param array $request Typically $_POST.
	 * @return string Rule ID.
	 */
	private function save_rule_from_request( $request ) {
		$rules    = $this->get_rules();
		$rule_id  = isset( $request['rule_id'] ) && '' !== $request['rule_id']
			? sanitize_text_field( wp_unslash( $request['rule_id'] ) )
			: uniqid( 'rule_', true );

		$rule_type = isset( $request['rule_type'] ) ? sanitize_key( $request['rule_type'] ) : 'standard';

		$rule = array(
			'name'              => isset( $request['rule_name'] ) ? sanitize_text_field( wp_unslash( $request['rule_name'] ) ) : '',
			'type'              => $rule_type,
			'active'            => isset( $request['rule_active'] ) && '1' === $request['rule_active'],
			'user_roles'        => array(),
		);

		// User roles.
		if ( isset( $request['user_roles'] ) && is_array( $request['user_roles'] ) ) {
			$rule['user_roles'] = array_map( 'sanitize_key', $request['user_roles'] );
		}

		if ( 'bogo' === $rule_type ) {
			$rule['bogo_buy_qty']       = isset( $request['bogo_buy_qty'] ) ? absint( $request['bogo_buy_qty'] ) : 1;
			$rule['bogo_free_qty']      = isset( $request['bogo_free_qty'] ) ? absint( $request['bogo_free_qty'] ) : 1;
			$rule['bogo_discount_pct']  = isset( $request['bogo_discount_pct'] ) ? min( 100, max( 0, floatval( $request['bogo_discount_pct'] ) ) ) : 100;
			$rule['bogo_apply_to']      = isset( $request['bogo_apply_to'] ) ? sanitize_key( $request['bogo_apply_to'] ) : 'all';
			$rule['bogo_product_ids']   = $this->sanitize_comma_ids( $request, 'bogo_product_ids' );
			$rule['bogo_category_ids']  = $this->sanitize_comma_ids( $request, 'bogo_category_ids' );
		} elseif ( 'cart' === $rule_type ) {
			$rule['cart_min_total']     = isset( $request['cart_min_total'] ) ? floatval( $request['cart_min_total'] ) : 0;
			$rule['cart_discount_pct']  = isset( $request['cart_discount_pct'] ) ? min( 100, max( 0, floatval( $request['cart_discount_pct'] ) ) ) : 0;
		} else {
			$rule['discount_type']  = isset( $request['discount_type'] ) ? sanitize_key( $request['discount_type'] ) : 'percentage';
			$rule['discount_value'] = isset( $request['discount_value'] ) ? floatval( $request['discount_value'] ) : 0;
			$rule['min_qty']        = isset( $request['min_qty'] ) ? max( 1, absint( $request['min_qty'] ) ) : 1;
			$rule['apply_to']       = isset( $request['apply_to'] ) ? sanitize_key( $request['apply_to'] ) : 'all';
			$rule['product_ids']    = $this->sanitize_comma_ids( $request, 'product_ids' );
			$rule['category_ids']   = $this->sanitize_comma_ids( $request, 'category_ids' );
		}

		$rules[ $rule_id ] = $rule;
		$this->update_rules( $rules );

		return $rule_id;
	}

	/**
	 * Parse and sanitize comma-separated ID list from request
	 *
	 * @param array  $request Request data.
	 * @param string $key     Parameter key.
	 * @return array
	 */
	private function sanitize_comma_ids( $request, $key ) {
		if ( ! isset( $request[ $key ] ) || '' === trim( wp_unslash( $request[ $key ] ) ) ) {
			return array();
		}
		$raw = sanitize_text_field( wp_unslash( $request[ $key ] ) );
		$ids = array_map( 'trim', explode( ',', $raw ) );
		$ids = array_filter( $ids, function ( $v ) {
			return is_numeric( $v );
		} );
		return array_map( 'absint', $ids );
	}

	/**
	 * Delete a rule by ID
	 *
	 * @param string $rule_id Rule ID.
	 */
	private function delete_rule_by_id( $rule_id ) {
		$rules = $this->get_rules();
		if ( isset( $rules[ $rule_id ] ) ) {
			unset( $rules[ $rule_id ] );
			$this->update_rules( $rules );
		}
	}

	// -------------------------------------------------------------------------
	//  Core: Apply Standard Price Rules
	// -------------------------------------------------------------------------

	/**
	 * Apply standard product / category price rule
	 *
	 * @param float      $price   Current price.
	 * @param \WC_Product $product Product object.
	 * @return float
	 */
	public function apply_product_price_rule( $price, $product ) {
		if ( ! is_a( $product, 'WC_Product' ) || $price <= 0 ) {
			return $price;
		}

		// Skip sale items unless enabled.
		$settings = get_option( self::SETTINGS_OPTION_KEY, array() );
		if ( empty( $settings['apply_to_sale_items'] ) || 'yes' !== $settings['apply_to_sale_items'] ) {
			if ( $product->is_on_sale() && ! is_admin() ) {
				$sale_price = $product->get_sale_price();
				if ( $sale_price && (float) $sale_price !== (float) $product->get_regular_price() ) {
					return $price;
				}
			}
		}

		$product_id = $product->get_id();
		$rules      = $this->get_rules();
		$user       = wp_get_current_user();

		foreach ( $rules as $rule ) {
			if ( empty( $rule['active'] ) || ( $rule['type'] ?? 'standard' ) !== 'standard' ) {
				continue;
			}
			if ( ! $this->is_user_role_match( $rule, $user ) ) {
				continue;
			}
			if ( ! $this->is_product_match( $rule, $product_id ) ) {
				continue;
			}
			// Skip if no discount value.
			if ( empty( $rule['discount_value'] ) && '0' !== (string) $rule['discount_value'] ) {
				continue;
			}
			$price = $this->calculate_discounted_price( $price, $rule );
			break; // First matching rule wins.
		}

		return $price;
	}

	/**
	 * Apply rule to variable product min price display
	 *
	 * @param float      $price   Min price.
	 * @param \WC_Product $product Product object.
	 * @return float
	 */
	public function apply_variable_min_price_rule( $price, $product ) {
		if ( ! is_a( $product, 'WC_Product_Variable' ) || $price <= 0 ) {
			return $price;
		}
		$variations = $product->get_children();
		if ( empty( $variations ) ) {
			return $price;
		}
		$min = PHP_FLOAT_MAX;
		foreach ( $variations as $var_id ) {
			$var   = wc_get_product( $var_id );
			$vprice = $var ? $this->apply_product_price_rule( $var->get_price(), $var ) : $price;
			if ( $vprice < $min ) {
				$min = $vprice;
			}
		}
		return $min < PHP_FLOAT_MAX ? $min : $price;
	}

	/**
	 * Modify the HTML price display on product pages
	 *
	 * @param string     $html    Price HTML.
	 * @param \WC_Product $product Product object.
	 * @return string
	 */
	public function modify_price_html( $html, $product ) {
		if ( ! is_a( $product, 'WC_Product' ) || is_admin() ) {
			return $html;
		}

		$original = $product->get_price();
		$discounted = $this->apply_product_price_rule( $original, $product );

		if ( abs( (float) $discounted - (float) $original ) < 0.0001 ) {
			return $html;
		}

		$regular_price = $product->get_regular_price();
		if ( $regular_price > 0 ) {
			$html = wc_format_sale_price( $regular_price, $discounted );
		} else {
			$html = wc_price( $discounted );
		}

		return $html;
	}

	/**
	 * Modify cart item price display
	 *
	 * @param string $price_html Item price HTML.
	 * @param array  $cart_item  Cart item data.
	 * @param string $cart_item_key Cart item key.
	 * @return string
	 */
	public function modify_cart_item_price( $price_html, $cart_item, $cart_item_key ) {
		if ( ! isset( $cart_item['data'] ) || ! is_a( $cart_item['data'], 'WC_Product' ) ) {
			return $price_html;
		}
		$product   = $cart_item['data'];
		$original  = $product->get_price( 'edit' );
		$discounted = $this->apply_product_price_rule( $original, $product );

		if ( abs( (float) $discounted - (float) $original ) < 0.0001 ) {
			return $price_html;
		}

		return '<del>' . wc_price( $original ) . '</del> <ins>' . wc_price( $discounted ) . '</ins>';
	}

	// -------------------------------------------------------------------------
	//  Core: Apply Cart Rules
	// -------------------------------------------------------------------------

	/**
	 * Apply cart-based product pricing before totals calculated
	 *
	 * @param \WC_Cart $cart Cart object.
	 */
	public function apply_cart_rules( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}
		if ( did_action( 'woocommerce_before_calculate_totals' ) > 1 ) {
			return;
		}

		$rules  = $this->get_rules();
		$user   = wp_get_current_user();

		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
			$product    = $cart_item['data'];
			$product_id = $product->get_id();
			$price      = $product->get_price( 'edit' );
			$new_price  = $price;

			foreach ( $rules as $rule ) {
				if ( empty( $rule['active'] ) || ( $rule['type'] ?? 'standard' ) !== 'standard' ) {
					continue;
				}
				if ( ! $this->is_user_role_match( $rule, $user ) ) {
					continue;
				}
				if ( ! $this->is_product_match( $rule, $product_id ) ) {
					continue;
				}
				// Check minimum quantity in cart for this product.
				$qty     = isset( $cart_item['quantity'] ) ? (int) $cart_item['quantity'] : 1;
				$min_qty = isset( $rule['min_qty'] ) ? (int) $rule['min_qty'] : 1;
				if ( $qty < $min_qty ) {
					continue;
				}
				if ( empty( $rule['discount_value'] ) && '0' !== (string) $rule['discount_value'] ) {
					continue;
				}
				$new_price = $this->calculate_discounted_price( $new_price, $rule );
				break;
			}

			if ( abs( (float) $new_price - (float) $price ) > 0.0001 ) {
				$product->set_price( $new_price );
			}
		}
	}

	/**
	 * Apply cart total-based percentage discount as a fee
	 *
	 * @param \WC_Cart $cart Cart object.
	 */
	public function apply_cart_total_discount( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		$rules   = $this->get_rules();
		$user    = wp_get_current_user();
		$total   = $cart->get_subtotal() + $cart->get_shipping_total();

		foreach ( $rules as $rule_id => $rule ) {
			if ( empty( $rule['active'] ) || ( $rule['type'] ?? 'standard' ) !== 'cart' ) {
				continue;
			}
			if ( ! $this->is_user_role_match( $rule, $user ) ) {
				continue;
			}
			$min_total = isset( $rule['cart_min_total'] ) ? (float) $rule['cart_min_total'] : 0;
			if ( $total < $min_total ) {
				continue;
			}
			$discount_pct = isset( $rule['cart_discount_pct'] ) ? (float) $rule['cart_discount_pct'] : 0;
			if ( $discount_pct <= 0 || $discount_pct > 100 ) {
				continue;
			}
			$fee_amount = -1 * ( $total * ( $discount_pct / 100 ) );
			$cart->add_fee(
				sprintf(
					/* translators: %d: discount percent */
					esc_html__( 'Cart Discount (%d%%)', 'wc-dynamic-pricing' ),
					(int) $discount_pct
				),
				$fee_amount,
				false
			);
			break; // One cart discount per checkout.
		}
	}

	// -------------------------------------------------------------------------
	//  Core: Apply BOGO Rules
	// -------------------------------------------------------------------------

	/**
	 * Apply BOGO rules at cart
	 *
	 * @param \WC_Cart $cart Cart object.
	 */
	public function apply_bogo_rules( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}
		if ( did_action( 'woocommerce_before_calculate_totals' ) > 2 ) {
			return;
		}

		$rules = $this->get_rules();
		$user  = wp_get_current_user();

		foreach ( $rules as $rule ) {
			if ( empty( $rule['active'] ) || ( $rule['type'] ?? 'standard' ) !== 'bogo' ) {
				continue;
			}
			if ( ! $this->is_user_role_match( $rule, $user ) ) {
				continue;
			}

			$buy_qty  = isset( $rule['bogo_buy_qty'] ) ? (int) $rule['bogo_buy_qty'] : 1;
			$free_qty = isset( $rule['bogo_free_qty'] ) ? (int) $rule['bogo_free_qty'] : 1;
			$disc_pct = isset( $rule['bogo_discount_pct'] ) ? (float) $rule['bogo_discount_pct'] : 100;

			if ( $buy_qty < 1 || $free_qty < 1 ) {
				continue;
			}

			// Collect qualifying items.
			$bogo_apply_to = $rule['bogo_apply_to'] ?? 'all';
			$bogo_product_ids  = isset( $rule['bogo_product_ids'] ) ? (array) $rule['bogo_product_ids'] : array();
			$bogo_category_ids = isset( $rule['bogo_category_ids'] ) ? (array) $rule['bogo_category_ids'] : array();

			$qualifying_items = array();

			foreach ( $cart->get_cart() as $item_key => $cart_item ) {
				$pid = $cart_item['data']->get_id();
				if ( ! $this->is_generic_match( $bogo_apply_to, $bogo_product_ids, $bogo_category_ids, $pid ) ) {
					continue;
				}
				$qualifying_items[ $item_key ] = $cart_item;
			}

			// For each qualifying item, count total qty.
			foreach ( $qualifying_items as $item_key => $cart_item ) {
				$real_qty = (int) $cart_item['quantity'];
				$groups   = intdiv( $real_qty, $buy_qty + $free_qty );
				$eligible = $groups * $free_qty;
				$remain   = $real_qty % ( $buy_qty + $free_qty );
				$extra_eligible = intdiv( $remain, $buy_qty ) * $free_qty;

				$total_discounted = $eligible + $extra_eligible;

				if ( $total_discounted <= 0 ) {
					continue;
				}

				$product    = $cart_item['data'];
				$orig_price = $product->get_price( 'edit' );
				$new_price  = $orig_price;

				if ( $disc_pct >= 100 ) {
					// Free items (price = 0 for the discounted portion).
					$free_ratio   = $total_discounted / $real_qty;
					$paid_ratio   = 1 - $free_ratio;
					$blended_price = $orig_price * $paid_ratio;
					$new_price     = $blended_price;
				} else {
					// Partial discount on Y items.
					$free_ratio    = $total_discounted / $real_qty;
					$discount_on_free = $orig_price * ( $disc_pct / 100 );
					$discounted_per_unit = $orig_price - $discount_on_free;
					$paid_ratio    = 1 - $free_ratio;
					$new_price     = ( $paid_ratio * $orig_price ) + ( $free_ratio * $discounted_per_unit );
				}

				if ( $new_price < 0 ) {
					$new_price = 0;
				}

				if ( abs( (float) $new_price - (float) $orig_price ) > 0.0001 ) {
					$product->set_price( $new_price );
				}
			}
		}
	}

	// -------------------------------------------------------------------------
	//  Helpers
	// -------------------------------------------------------------------------

	/**
	 * Calculate a discounted price given a rule
	 *
	 * @param float $price Original price.
	 * @param array $rule  Rule data.
	 * @return float
	 */
	private function calculate_discounted_price( $price, $rule ) {
		$type  = $rule['discount_type'] ?? 'percentage';
		$value = isset( $rule['discount_value'] ) ? (float) $rule['discount_value'] : 0;

		switch ( $type ) {
			case 'percentage':
				$percent = min( 100, max( 0, $value ) );
				return $price * ( 1 - $percent / 100 );
			case 'fixed_amount':
				return max( 0, $price - $value );
			case 'fixed_price':
				return max( 0, $value );
			default:
				return $price;
		}
	}

	/**
	 * Check whether a rule applies to the given product
	 *
	 * @param array $rule       Rule data.
	 * @param int   $product_id Product ID.
	 * @return bool
	 */
	private function is_product_match( $rule, $product_id ) {
		$apply_to = $rule['apply_to'] ?? 'all';
		if ( 'all' === $apply_to ) {
			return true;
		}
		if ( 'products' === $apply_to ) {
			$ids = isset( $rule['product_ids'] ) ? (array) $rule['product_ids'] : array();
			return in_array( $product_id, $ids, true );
		}
		if ( 'categories' === $apply_to ) {
			$cat_ids = isset( $rule['category_ids'] ) ? (array) $rule['category_ids'] : array();
			$product_cats = wc_get_product_term_ids( $product_id, 'product_cat' );
			return ! empty( array_intersect( $cat_ids, $product_cats ) );
		}
		return false;
	}

	/**
	 * Check match for generic apply_to (used by BOGO)
	 *
	 * @param string $apply_to  Scope.
	 * @param array  $prod_ids  Product IDs.
	 * @param array  $cat_ids   Category IDs.
	 * @param int    $product_id Product ID.
	 * @return bool
	 */
	private function is_generic_match( $apply_to, $prod_ids, $cat_ids, $product_id ) {
		if ( 'all' === $apply_to ) {
			return true;
		}
		if ( 'products' === $apply_to ) {
			return in_array( $product_id, $prod_ids, true );
		}
		if ( 'categories' === $apply_to ) {
			$product_cats = wc_get_product_term_ids( $product_id, 'product_cat' );
			return ! empty( array_intersect( $cat_ids, $product_cats ) );
		}
		return false;
	}

	/**
	 * Check if the current user matches a rule's role restrictions
	 *
	 * @param array     $rule Rule data.
	 * @param \WP_User $user Current user.
	 * @return bool
	 */
	private function is_user_role_match( $rule, $user ) {
		$allowed_roles = isset( $rule['user_roles'] ) && is_array( $rule['user_roles'] ) ? $rule['user_roles'] : array();
		if ( empty( $allowed_roles ) ) {
			return true; // No restriction.
		}
		if ( ! $user || ! $user->exists() ) {
			return in_array( 'guest', $allowed_roles, true );
		}
		$user_roles = (array) $user->roles;
		return ! empty( array_intersect( $allowed_roles, $user_roles ) );
	}
}

// -------------------------------------------------------------------------
//  Boot
// -------------------------------------------------------------------------

/**
 * Initialize the plugin
 */
function wc_dynamic_pricing_init() {
	WC_Dynamic_Pricing::get_instance();
}
add_action( 'plugins_loaded', 'wc_dynamic_pricing_init' );

// -------------------------------------------------------------------------
//  Admin POST redirect handlers (need to be registered at wp_loaded)
// -------------------------------------------------------------------------

/**
 * Handle POST redirect actions
 */
function wcdp_handle_post_actions() {
	if ( ! is_admin() ) {
		return;
	}
	$plugin = WC_Dynamic_Pricing::get_instance();

	if ( isset( $_POST['action'] ) && 'wcdp_save_rule_redirect' === sanitize_key( $_POST['action'] ) ) {
		$plugin->handle_save_rule_redirect();
	}
	if ( isset( $_POST['action'] ) && 'wcdp_delete_rule_redirect' === sanitize_key( $_POST['action'] ) ) {
		$plugin->handle_delete_rule_redirect();
	}
}
add_action( 'wp_loaded', 'wcdp_handle_post_actions' );

// -------------------------------------------------------------------------
//  HPOS / C.O.T. compatibility
// -------------------------------------------------------------------------

/**
 * Declare HPOS (High-Performance Order Storage) compatibility
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);
