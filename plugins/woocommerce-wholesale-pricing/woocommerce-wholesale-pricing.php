<?php
/**
 * Plugin Name:       WooCommerce Wholesale Pricing
 * Plugin URI:        https://sandydigital.io
 * Description:       Role-based wholesale pricing with quantity breaks, minimum orders, and price hiding. Perfect for B2B stores.
 * Version:           1.0.0
 * Author:            AcidBurn
 * Author URI:        https://sandydigital.io
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       wc-wholesale-pricing
 * Domain Path:       /languages
 * Requires at least: 5.0
 * Requires PHP:      7.4
 * WC requires at least: 4.0
 * WC tested up to:   9.0
 *
 * @package WC_Wholesale_Pricing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ), true ) ) {
	return;
}

final class WC_Wholesale_Pricing {

	private static $instance = null;

	const OPTION_KEY     = 'wc_wholesale_settings';
	const META_PRICE     = '_wc_wholesale_price';
	const META_QTY_RULES = '_wc_wholesale_qty_rules';
	const META_MIN_QTY   = '_wc_wholesale_min_qty';
	const META_DISABLE   = '_wc_wholesale_disable';

	/** @var array|null Cached current user roles. */
	private $user_roles = null;

	/** @var string|null Cached matched wholesale role. */
	private $wholesale_role = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'load_textdomain' ) );

		// Price hooks.
		add_filter( 'woocommerce_product_get_price', array( $this, 'filter_price' ), 99, 2 );
		add_filter( 'woocommerce_product_get_regular_price', array( $this, 'filter_price' ), 99, 2 );
		add_filter( 'woocommerce_product_get_sale_price', array( $this, 'filter_sale_price' ), 99, 2 );
		add_filter( 'woocommerce_product_variation_get_price', array( $this, 'filter_price' ), 99, 2 );
		add_filter( 'woocommerce_product_variation_get_regular_price', array( $this, 'filter_price' ), 99, 2 );
		add_filter( 'woocommerce_variation_prices_price', array( $this, 'filter_variation_price' ), 99, 3 );
		add_filter( 'woocommerce_variation_prices_regular_price', array( $this, 'filter_variation_price' ), 99, 3 );
		add_filter( 'woocommerce_get_price_html', array( $this, 'filter_price_html' ), 99, 2 );

		// Add to cart validation (minimum order).
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_min_qty' ), 99, 3 );
		add_filter( 'woocommerce_update_cart_validation', array( $this, 'validate_min_qty_update' ), 99, 4 );
		add_action( 'woocommerce_check_cart_items', array( $this, 'check_cart_quantities' ) );

		// Cart price recalculation.
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'recalculate_cart_prices' ), 99 );

		// Admin.
		add_action( 'admin_menu', array( $this, 'add_admin_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_product_meta_box' ) );
		add_action( 'save_post_product', array( $this, 'save_product_meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

		// Product list column.
		add_filter( 'manage_edit-product_columns', array( $this, 'add_product_column' ) );
		add_action( 'manage_product_posts_custom_column', array( $this, 'render_product_column' ), 10, 2 );

		// Price hiding.
		add_action( 'wp', array( $this, 'maybe_hide_prices' ) );

		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'plugin_action_links' ) );
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'wc-wholesale-pricing', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}

	// ─── Role Detection ─────────────────────────────────────

	/**
	 * Get the matched wholesale role for the current user.
	 *
	 * @return string|null Role key or null if not a wholesaler.
	 */
	private function get_wholesale_role() {
		if ( null !== $this->wholesale_role ) {
			return $this->wholesale_role;
		}

		$settings = $this->get_settings();
		$roles    = $this->get_user_roles();

		foreach ( $roles as $role ) {
			if ( ! empty( $settings[ "role_{$role}_enabled" ] ) ) {
				$this->wholesale_role = $role;
				return $role;
			}
		}

		$this->wholesale_role = false;
		return null;
	}

	/**
	 * Check if current user has wholesale pricing.
	 *
	 * @return bool
	 */
	public function is_wholesale_user() {
		return null !== $this->get_wholesale_role();
	}

	/**
	 * Get the current user's roles.
	 *
	 * @return array
	 */
	private function get_user_roles() {
		if ( null !== $this->user_roles ) {
			return $this->user_roles;
		}

		if ( is_user_logged_in() ) {
			$user = wp_get_current_user();
			$this->user_roles = array_values( $user->roles );
		} else {
			$this->user_roles = array( 'guest' );
		}

		return $this->user_roles;
	}

	// ─── Settings ───────────────────────────────────────────

	private function defaults() {
		return array(
			'role_wholesale_customer_enabled'   => 'yes',
			'role_wholesale_customer_discount'  => '30',
			'role_wholesale_customer_type'      => 'percent',
			'role_wholesale_customer_label'     => 'Wholesale',

			'role_wholesale_vip_enabled'        => 'no',
			'role_wholesale_vip_discount'       => '40',
			'role_wholesale_vip_type'           => 'percent',
			'role_wholesale_vip_label'          => 'VIP Wholesale',

			'guest_enabled'                     => 'no',

			'global_min_order'                  => 0,
			'hide_prices_guests'               => 'no',
			'hide_add_to_cart_guests'          => 'no',
			'hide_price_message'               => 'Login to see wholesale pricing.',
			'show_retail_price_strikethrough'  => 'yes',
			'apply_to_variations'              => 'yes',
		);
	}

	public function get_settings() {
		$saved = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( $saved, $this->defaults() );
	}

	/**
	 * Get settings for a specific role.
	 *
	 * @param string $role Role key.
	 * @return array
	 */
	private function get_role_settings( $role ) {
		$settings = $this->get_settings();

		return array(
			'enabled'  => ! empty( $settings[ "role_{$role}_enabled" ] ),
			'discount' => isset( $settings[ "role_{$role}_discount" ] ) ? floatval( $settings[ "role_{$role}_discount" ] ) : 0,
			'type'     => isset( $settings[ "role_{$role}_type" ] ) ? $settings[ "role_{$role}_type" ] : 'percent',
			'label'    => isset( $settings[ "role_{$role}_label" ] ) ? $settings[ "role_{$role}_label" ] : 'Wholesale',
		);
	}

	// ─── Price Calculation ──────────────────────────────────

	/**
	 * Calculate wholesale price for a product.
	 *
	 * @param float      $price      Original price.
	 * @param WC_Product $product    Product object.
	 * @param int        $quantity   Quantity (for tier breaks).
	 * @return float
	 */
	private function calculate_wholesale_price( $price, $product, $quantity = 1 ) {
		if ( $price <= 0 ) {
			return $price;
		}

		$product_id = $product->get_id();

		// Check per-product disable.
		if ( 'yes' === get_post_meta( $this->get_parent_id( $product ), self::META_DISABLE, true ) ) {
			return $price;
		}

		// Check per-product fixed wholesale price.
		$wholesale_price = get_post_meta( $product_id, self::META_PRICE, true );
		if ( '' !== $wholesale_price && is_numeric( $wholesale_price ) ) {
			return floatval( $wholesale_price );
		}

		// Check quantity break tiers.
		$qty_rules = $this->get_qty_rules( $product );
		if ( ! empty( $qty_rules ) ) {
			$matched_price = $this->match_quantity_tier( $qty_rules, $quantity, $price );
			if ( null !== $matched_price ) {
				return $matched_price;
			}
		}

		// Apply role-based discount.
		$role = $this->get_wholesale_role();
		if ( ! $role ) {
			return $price;
		}

		$role_settings = $this->get_role_settings( $role );

		if ( 'fixed' === $role_settings['type'] ) {
			return max( 0, $price - $role_settings['discount'] );
		}

		// Percent discount.
		$discount = $role_settings['discount'];
		if ( $discount > 0 ) {
			$price = $price * ( 1 - ( $discount / 100 ) );
		}

		return round( $price, 4 );
	}

	/**
	 * Get quantity break rules for a product.
	 *
	 * @param WC_Product $product Product.
	 * @return array
	 */
	private function get_qty_rules( $product ) {
		$parent_id = $this->get_parent_id( $product );
		$rules = get_post_meta( $parent_id, self::META_QTY_RULES, true );

		if ( empty( $rules ) || ! is_array( $rules ) ) {
			return array();
		}

		// Sort by minimum quantity ascending.
		usort( $rules, function ( $a, $b ) {
			$qa = isset( $a['min'] ) ? absint( $a['min'] ) : 0;
			$qb = isset( $b['min'] ) ? absint( $b['min'] ) : 0;
			return $qa - $qb;
		} );

		return $rules;
	}

	/**
	 * Match quantity tier and return the price or discount.
	 *
	 * @param array $rules    Quantity break rules.
	 * @param int   $qty      Quantity.
	 * @param float $price    Base price.
	 * @return float|null
	 */
	private function match_quantity_tier( $rules, $qty, $price ) {
		$matched = null;

		foreach ( $rules as $rule ) {
			$min = isset( $rule['min'] ) ? absint( $rule['min'] ) : 0;
			if ( $qty >= $min && $min > 0 ) {
				if ( 'fixed' === $rule['type'] && isset( $rule['price'] ) ) {
					$matched = floatval( $rule['price'] );
				} elseif ( isset( $rule['discount'] ) ) {
					$matched = $price * ( 1 - ( floatval( $rule['discount'] ) / 100 ) );
				}
			}
		}

		return $matched;
	}

	/**
	 * Get parent product ID for variations.
	 *
	 * @param WC_Product $product Product.
	 * @return int
	 */
	private function get_parent_id( $product ) {
		if ( $product->is_type( 'variation' ) ) {
			return $product->get_parent_id();
		}
		return $product->get_id();
	}

	// ─── Price Hooks ────────────────────────────────────────

	public function filter_price( $price, $product ) {
		if ( ! $this->is_wholesale_user() ) {
			return $price;
		}

		if ( is_admin() && ! wp_doing_ajax() ) {
			return $price;
		}

		return $this->calculate_wholesale_price( (float) $price, $product );
	}

	public function filter_sale_price( $price, $product ) {
		// Wholesale overrides sale for wholesale users.
		if ( ! $this->is_wholesale_user() ) {
			return $price;
		}

		if ( is_admin() && ! wp_doing_ajax() ) {
			return $price;
		}

		// If sale price is set and wholesale price is lower, use wholesale.
		$wp = $this->calculate_wholesale_price( (float) $price, $product );
		if ( (float) $price > 0 && $wp < (float) $price ) {
			return $wp;
		}

		return $price;
	}

	public function filter_variation_price( $price, $variation, $parent ) {
		if ( ! $this->is_wholesale_user() ) {
			return $price;
		}

		$settings = $this->get_settings();
		if ( 'yes' !== $settings['apply_to_variations'] ) {
			return $price;
		}

		return $this->calculate_wholesale_price( (float) $price, $variation );
	}

	// ─── Price HTML ─────────────────────────────────────────

	public function filter_price_html( $html, $product ) {
		if ( ! $this->is_wholesale_user() ) {
			return $html;
		}

		$settings     = $this->get_settings();
		$role         = $this->get_wholesale_role();
		$role_settings = $this->get_role_settings( $role );

		$regular_price = (float) $product->get_regular_price();
		$wholesale_price = (float) $product->get_price();

		if ( $regular_price <= 0 ) {
			return $html;
		}

		$label = $role_settings['label'];
		$new_html = '';

		// Show retail price strikethrough.
		if ( 'yes' === $settings['show_retail_price_strikethrough'] && $wholesale_price < $regular_price ) {
			$new_html .= sprintf(
				'<del aria-hidden="true"><span class="woocommerce-Price-amount amount"><bdi><span class="woocommerce-Price-currencySymbol">%s</span>%s</bdi></span></del> ',
				get_woocommerce_currency_symbol(),
				number_format( $regular_price, wc_get_price_decimals(), wc_get_price_decimal_separator(), wc_get_price_thousand_separator() )
			);
		}

		$new_html .= wc_price( $wholesale_price );

		$new_html .= sprintf(
			' <small class="wc-wholesale-label" style="color:#027a48;font-weight:600;">(%s)</small>',
			esc_html( $label )
		);

		return $new_html;
	}

	// ─── Minimum Quantity Validation ────────────────────────

	/**
	 * Validate minimum quantity on add to cart.
	 */
	public function validate_min_qty( $passed, $product_id, $quantity ) {
		if ( ! $this->is_wholesale_user() ) {
			return $passed;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return $passed;
		}

		$parent_id = $this->get_parent_id( $product );
		$min_qty = $this->get_min_qty( $parent_id );

		if ( $min_qty <= 1 ) {
			return $passed;
		}

		if ( $quantity < $min_qty ) {
			wc_add_notice(
				sprintf(
					/* translators: 1: product name, 2: minimum quantity */
					__( 'Wholesale orders require a minimum of %2$d units for "%1$s". You have %3$d in cart.', 'wc-wholesale-pricing' ),
					$product->get_name(),
					$min_qty,
					$quantity
				),
				'error'
			);
			return false;
		}

		return $passed;
	}

	public function validate_min_qty_update( $passed, $cart_item_key, $values, $quantity ) {
		if ( ! $this->is_wholesale_user() ) {
			return $passed;
		}

		$product = $values['data'];
		$parent_id = $this->get_parent_id( $product );
		$min_qty = $this->get_min_qty( $parent_id );

		if ( $min_qty <= 1 ) {
			return $passed;
		}

		if ( $quantity < $min_qty ) {
			wc_add_notice(
				sprintf(
					__( 'Wholesale orders require a minimum of %1$d units for "%2$s".', 'wc-wholesale-pricing' ),
					$min_qty,
					$product->get_name()
				),
				'error'
			);
			return false;
		}

		return $passed;
	}

	/**
	 * Check cart quantities before checkout.
	 */
	public function check_cart_quantities() {
		if ( ! $this->is_wholesale_user() ) {
			return;
		}

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$product   = $cart_item['data'];
			$parent_id = $this->get_parent_id( $product );
			$min_qty   = $this->get_min_qty( $parent_id );
			$qty       = $cart_item['quantity'];

			if ( $min_qty > 1 && $qty < $min_qty ) {
				wc_add_notice(
					sprintf(
						__( '"%1$s" requires a minimum wholesale order of %2$d units. Current quantity: %3$d.', 'wc-wholesale-pricing' ),
						$product->get_name(),
						$min_qty,
						$qty
					),
					'error'
				);
			}
		}
	}

	/**
	 * Get minimum quantity for a product.
	 *
	 * @param int $product_id Product ID.
	 * @return int
	 */
	private function get_min_qty( $product_id ) {
		$min = get_post_meta( $product_id, self::META_MIN_QTY, true );
		if ( '' !== $min && is_numeric( $min ) && absint( $min ) > 0 ) {
			return absint( $min );
		}

		return absint( $this->get_settings()['global_min_order'] );
	}

	// ─── Cart Price Recalculation ───────────────────────────

	public function recalculate_cart_prices( $cart ) {
		if ( ! $this->is_wholesale_user() ) {
			return;
		}

		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_item ) {
			$product = $cart_item['data'];
			$price   = (float) $product->get_regular_price();

			if ( $price <= 0 ) {
				continue;
			}

			$wp = $this->calculate_wholesale_price( $price, $product, $cart_item['quantity'] );

			if ( $wp < $price ) {
				$cart_item['data']->set_price( $wp );
			}
		}
	}

	// ─── Price Hiding ───────────────────────────────────────

	public function maybe_hide_prices() {
		$settings = $this->get_settings();

		if ( ! is_user_logged_in() && 'yes' === $settings['hide_prices_guests'] ) {
			add_filter( 'woocommerce_get_price_html', array( $this, 'hide_price_html' ), 99, 2 );
			add_filter( 'woocommerce_cart_item_price', '__return_empty_string', 99 );
			add_filter( 'woocommerce_cart_item_subtotal', '__return_empty_string', 99 );
			add_filter( 'woocommerce_cart_subtotal', '__return_empty_string', 99 );
			add_filter( 'woocommerce_cart_total', '__return_empty_string', 99 );

			if ( 'yes' === $settings['hide_add_to_cart_guests'] ) {
				add_filter( 'woocommerce_is_purchasable', '__return_false', 99 );
				add_filter( 'woocommerce_variation_is_purchasable', '__return_false', 99 );
			}
		}
	}

	public function hide_price_html( $html, $product ) {
		$settings = $this->get_settings();
		$message  = $settings['hide_price_message'];
		return '<span class="wc-wholesale-login-msg">' . esc_html( $message ) . ' <a href="' . esc_url( wc_get_page_permalink( 'myaccount' ) ) . '">' . esc_html__( 'Login', 'wc-wholesale-pricing' ) . '</a></span>';
	}

	// ─── Admin ──────────────────────────────────────────────

	public function add_admin_page() {
		add_submenu_page(
			'woocommerce',
			esc_html__( 'Wholesale Pricing', 'wc-wholesale-pricing' ),
			esc_html__( 'Wholesale Pricing', 'wc-wholesale-pricing' ),
			'manage_woocommerce',
			'wc-wholesale-pricing',
			array( $this, 'render_admin_page' )
		);
	}

	public function register_settings() {
		register_setting( 'wc_wholesale_group', self::OPTION_KEY, array(
			'sanitize_callback' => array( $this, 'sanitize_settings' ),
		) );
	}

	public function sanitize_settings( $input ) {
		$clean = array();
		$roles = array( 'wholesale_customer', 'wholesale_vip', 'guest' );

		foreach ( $roles as $role ) {
			$clean[ "role_{$role}_enabled" ]  = isset( $input[ "role_{$role}_enabled" ] ) ? 'yes' : 'no';
			$clean[ "role_{$role}_discount" ] = isset( $input[ "role_{$role}_discount" ] ) ? floatval( $input[ "role_{$role}_discount" ] ) : 0;
			$clean[ "role_{$role}_type" ]     = isset( $input[ "role_{$role}_type" ] ) ? sanitize_text_field( $input[ "role_{$role}_type" ] ) : 'percent';
			$clean[ "role_{$role}_label" ]    = isset( $input[ "role_{$role}_label" ] ) ? sanitize_text_field( $input[ "role_{$role}_label" ] ) : '';
		}

		$clean['global_min_order']            = isset( $input['global_min_order'] ) ? absint( $input['global_min_order'] ) : 0;
		$clean['hide_prices_guests']          = isset( $input['hide_prices_guests'] ) ? 'yes' : 'no';
		$clean['hide_add_to_cart_guests']     = isset( $input['hide_add_to_cart_guests'] ) ? 'yes' : 'no';
		$clean['hide_price_message']          = isset( $input['hide_price_message'] ) ? sanitize_text_field( $input['hide_price_message'] ) : '';
		$clean['show_retail_price_strikethrough'] = isset( $input['show_retail_price_strikethrough'] ) ? 'yes' : 'no';
		$clean['apply_to_variations']         = isset( $input['apply_to_variations'] ) ? 'yes' : 'no';

		return $clean;
	}

	public function render_admin_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'wc-wholesale-pricing' ) );
		}

		$settings = $this->get_settings();
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'wc_wholesale_group' );
				wp_nonce_field( 'wc_wholesale_save', 'wc_wholesale_nonce' );
				?>

				<h2><?php esc_html_e( 'Wholesale Roles', 'wc-wholesale-pricing' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Configure discounts for each user role. Users must be logged in and have the matching role to receive wholesale pricing.', 'wc-wholesale-pricing' ); ?>
					<?php esc_html_e( 'Create roles under Users → Add New Role, or use a role editor plugin.', 'wc-wholesale-pricing' ); ?>
				</p>

				<table class="form-table">
					<?php
					$roles_config = array(
						'wholesale_customer' => __( 'Wholesale Customer', 'wc-wholesale-pricing' ),
						'wholesale_vip'      => __( 'Wholesale VIP', 'wc-wholesale-pricing' ),
					);

					foreach ( $roles_config as $role_key => $role_name ) :
						?>
						<tr>
							<th scope="row" colspan="2">
								<h3 style="margin:0;"><?php echo esc_html( $role_name ); ?></h3>
							</th>
						</tr>
						<tr>
							<th scope="row"><label><?php esc_html_e( 'Enable', 'wc-wholesale-pricing' ); ?></label></th>
							<td>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[role_<?php echo esc_attr( $role_key ); ?>_enabled]" value="yes" <?php checked( 'yes', $settings[ "role_{$role_key}_enabled" ] ); ?> />
							</td>
						</tr>
						<tr>
							<th scope="row"><label><?php esc_html_e( 'Discount Type', 'wc-wholesale-pricing' ); ?></label></th>
							<td>
								<select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[role_<?php echo esc_attr( $role_key ); ?>_type]">
									<option value="percent" <?php selected( $settings[ "role_{$role_key}_type" ], 'percent' ); ?>><?php esc_html_e( 'Percentage (%)', 'wc-wholesale-pricing' ); ?></option>
									<option value="fixed" <?php selected( $settings[ "role_{$role_key}_type" ], 'fixed' ); ?>><?php esc_html_e( 'Fixed Amount ($)', 'wc-wholesale-pricing' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label><?php esc_html_e( 'Discount Value', 'wc-wholesale-pricing' ); ?></label></th>
							<td>
								<input type="number" step="0.01" min="0" max="100" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[role_<?php echo esc_attr( $role_key ); ?>_discount]" value="<?php echo esc_attr( $settings[ "role_{$role_key}_discount" ] ); ?>" class="small-text" />
								<p class="description"><?php esc_html_e( 'For percentage: e.g. 30 = 30% off. For fixed: e.g. 5 = $5 off.', 'wc-wholesale-pricing' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label><?php esc_html_e( 'Price Label', 'wc-wholesale-pricing' ); ?></label></th>
							<td>
								<input type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[role_<?php echo esc_attr( $role_key ); ?>_label]" value="<?php echo esc_attr( $settings[ "role_{$role_key}_label" ] ); ?>" class="regular-text" />
								<p class="description"><?php esc_html_e( 'Shown next to wholesale prices, e.g. "(Wholesale)"', 'wc-wholesale-pricing' ); ?></p>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>

				<h2><?php esc_html_e( 'Price Visibility', 'wc-wholesale-pricing' ); ?></h2>
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Hide Prices from Guests', 'wc-wholesale-pricing' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[hide_prices_guests]" value="yes" <?php checked( 'yes', $settings['hide_prices_guests'] ); ?> />
								<?php esc_html_e( 'Replace prices with a login message for logged-out visitors.', 'wc-wholesale-pricing' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Hide Add to Cart from Guests', 'wc-wholesale-pricing' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[hide_add_to_cart_guests]" value="yes" <?php checked( 'yes', $settings['hide_add_to_cart_guests'] ); ?> />
								<?php esc_html_e( 'Remove Add to Cart buttons for logged-out visitors. Requires "Hide Prices" to be enabled.', 'wc-wholesale-pricing' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label><?php esc_html_e( 'Price Hidden Message', 'wc-wholesale-pricing' ); ?></label></th>
						<td>
							<input type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[hide_price_message]" value="<?php echo esc_attr( $settings['hide_price_message'] ); ?>" class="large-text" />
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Display Options', 'wc-wholesale-pricing' ); ?></h2>
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Show Retail Price', 'wc-wholesale-pricing' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[show_retail_price_strikethrough]" value="yes" <?php checked( 'yes', $settings['show_retail_price_strikethrough'] ); ?> />
								<?php esc_html_e( 'Show the original retail price with a strikethrough next to the wholesale price.', 'wc-wholesale-pricing' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Apply to Variations', 'wc-wholesale-pricing' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[apply_to_variations]" value="yes" <?php checked( 'yes', $settings['apply_to_variations'] ); ?> />
								<?php esc_html_e( 'Apply wholesale pricing to variable product variations.', 'wc-wholesale-pricing' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label><?php esc_html_e( 'Global Minimum Order Qty', 'wc-wholesale-pricing' ); ?></label></th>
						<td>
							<input type="number" min="0" max="9999" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[global_min_order]" value="<?php echo esc_attr( $settings['global_min_order'] ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( 'Minimum units per product for wholesale customers. 0 = no minimum. Can be overridden per product.', 'wc-wholesale-pricing' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	// ─── Product Meta Box ───────────────────────────────────

	public function enqueue_admin_assets( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen || 'product' !== $screen->id ) {
			return;
		}
	}

	public function add_product_meta_box() {
		add_meta_box(
			'wc_wholesale_product',
			esc_html__( 'Wholesale Pricing', 'wc-wholesale-pricing' ),
			array( $this, 'render_product_meta_box' ),
			'product',
			'normal',
			'default'
		);
	}

	public function render_product_meta_box( $post ) {
		wp_nonce_field( 'wc_wholesale_product_save', 'wc_wholesale_product_nonce' );

		$disable   = get_post_meta( $post->ID, self::META_DISABLE, true );
		$price     = get_post_meta( $post->ID, self::META_PRICE, true );
		$min_qty   = get_post_meta( $post->ID, self::META_MIN_QTY, true );
		$qty_rules = get_post_meta( $post->ID, self::META_QTY_RULES, true );

		if ( ! is_array( $qty_rules ) ) {
			$qty_rules = array();
		}
		?>
		<style>
			.wc-wp-qty-rules { margin: 12px 0; }
			.wc-wp-qty-rule { display: flex; gap: 8px; align-items: center; margin-bottom: 6px; }
			.wc-wp-qty-rule input { width: 90px; }
			.wc-wp-qty-rule select { width: 110px; }
		</style>

		<p>
			<label>
				<input type="checkbox" name="_wc_wholesale_disable" value="yes" <?php checked( 'yes', $disable ); ?> />
				<strong><?php esc_html_e( 'Disable wholesale pricing for this product', 'wc-wholesale-pricing' ); ?></strong>
			</label>
		</p>

		<hr />

		<p>
			<label><strong><?php esc_html_e( 'Fixed Wholesale Price', 'wc-wholesale-pricing' ); ?></strong></label><br />
			<input type="number" step="0.01" min="0" name="_wc_wholesale_price" value="<?php echo esc_attr( $price ); ?>" placeholder="<?php esc_attr_e( 'Override all role discounts', 'wc-wholesale-pricing' ); ?>" style="width:200px;" />
			<span class="description">
				<?php esc_html_e( 'Set a specific wholesale price for this product. Overrides all role-based and quantity discounts.', 'wc-wholesale-pricing' ); ?>
			</span>
		</p>

		<p>
			<label><strong><?php esc_html_e( 'Minimum Order Quantity', 'wc-wholesale-pricing' ); ?></strong></label><br />
			<input type="number" min="0" name="_wc_wholesale_min_qty" value="<?php echo esc_attr( $min_qty ); ?>" placeholder="<?php esc_attr_e( 'Uses global setting', 'wc-wholesale-pricing' ); ?>" style="width:100px;" />
			<span class="description"><?php esc_html_e( 'Override the global minimum quantity for this product.', 'wc-wholesale-pricing' ); ?></span>
		</p>

		<hr />

		<h4><?php esc_html_e( 'Quantity Break Tiers', 'wc-wholesale-pricing' ); ?></h4>
		<p class="description">
			<?php esc_html_e( 'Offer progressive discounts based on quantity purchased. Leave blank for no tier discounts.', 'wc-wholesale-pricing' ); ?>
		</p>

		<div class="wc-wp-qty-rules" id="wc-wp-qty-rules-container">
			<?php if ( ! empty( $qty_rules ) ) : ?>
				<?php foreach ( $qty_rules as $index => $rule ) : ?>
					<div class="wc-wp-qty-rule">
						<span><?php esc_html_e( 'Buy', 'wc-wholesale-pricing' ); ?></span>
						<input type="number" min="1" name="_wc_wholesale_qty_rules[<?php echo (int) $index; ?>][min]" value="<?php echo esc_attr( $rule['min'] ); ?>" placeholder="<?php esc_attr_e( 'Qty', 'wc-wholesale-pricing' ); ?>" />
						<span><?php esc_html_e( 'or more →', 'wc-wholesale-pricing' ); ?></span>
						<select name="_wc_wholesale_qty_rules[<?php echo (int) $index; ?>][type]">
							<option value="percent" <?php selected( isset( $rule['type'] ) ? $rule['type'] : '', 'percent' ); ?>>% <?php esc_html_e( 'off', 'wc-wholesale-pricing' ); ?></option>
							<option value="fixed" <?php selected( isset( $rule['type'] ) ? $rule['type'] : '', 'fixed' ); ?>>$ <?php esc_html_e( 'each', 'wc-wholesale-pricing' ); ?></option>
						</select>
						<input type="number" step="0.01" min="0" name="_wc_wholesale_qty_rules[<?php echo (int) $index; ?>][<?php echo isset( $rule['type'] ) && 'fixed' === $rule['type'] ? 'price' : 'discount'; ?>]" value="<?php echo esc_attr( isset( $rule['price'] ) ? $rule['price'] : ( isset( $rule['discount'] ) ? $rule['discount'] : '' ) ); ?>" />
						<button type="button" class="button button-small wc-wp-remove-tier">×</button>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>

		<button type="button" class="button" id="wc-wp-add-tier"><?php esc_html_e( '+ Add Quantity Tier', 'wc-wholesale-pricing' ); ?></button>

		<script>
			jQuery(document).ready(function($){
				var tierIndex = <?php echo count( $qty_rules ); ?>;
				$('#wc-wp-add-tier').on('click', function(){
					var html = '<div class="wc-wp-qty-rule">' +
						'<span>Buy</span> ' +
						'<input type="number" min="1" name="_wc_wholesale_qty_rules[' + tierIndex + '][min]" placeholder="Qty" /> ' +
						'<span>or more →</span> ' +
						'<select name="_wc_wholesale_qty_rules[' + tierIndex + '][type]"><option value="percent">% off</option><option value="fixed">$ each</option></select> ' +
						'<input type="number" step="0.01" min="0" name="_wc_wholesale_qty_rules[' + tierIndex + '][discount]" /> ' +
						'<button type="button" class="button button-small wc-wp-remove-tier">×</button>' +
						'</div>';
					$('#wc-wp-qty-rules-container').append(html);
					tierIndex++;
				});
				$(document).on('click', '.wc-wp-remove-tier', function(){
					$(this).closest('.wc-wp-qty-rule').remove();
				});
			});
		</script>
		<?php
	}

	public function save_product_meta_box( $post_id ) {
		if ( ! isset( $_POST['wc_wholesale_product_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wc_wholesale_product_nonce'] ) ), 'wc_wholesale_product_save' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Disable toggle.
		$disable = isset( $_POST['_wc_wholesale_disable'] ) ? 'yes' : 'no';
		update_post_meta( $post_id, self::META_DISABLE, $disable );

		// Fixed price.
		if ( isset( $_POST['_wc_wholesale_price'] ) ) {
			$val = sanitize_text_field( wp_unslash( $_POST['_wc_wholesale_price'] ) );
			if ( '' === $val ) {
				delete_post_meta( $post_id, self::META_PRICE );
			} else {
				update_post_meta( $post_id, self::META_PRICE, floatval( $val ) );
			}
		}

		// Min quantity.
		if ( isset( $_POST['_wc_wholesale_min_qty'] ) ) {
			$val = sanitize_text_field( wp_unslash( $_POST['_wc_wholesale_min_qty'] ) );
			if ( '' === $val ) {
				delete_post_meta( $post_id, self::META_MIN_QTY );
			} else {
				update_post_meta( $post_id, self::META_MIN_QTY, absint( $val ) );
			}
		}

		// Quantity rules.
		if ( isset( $_POST['_wc_wholesale_qty_rules'] ) && is_array( $_POST['_wc_wholesale_qty_rules'] ) ) {
			$rules = array();

			foreach ( wp_unslash( $_POST['_wc_wholesale_qty_rules'] ) as $rule ) {
				$min = isset( $rule['min'] ) ? absint( $rule['min'] ) : 0;
				if ( $min <= 0 ) {
					continue;
				}

				$type = isset( $rule['type'] ) ? sanitize_text_field( $rule['type'] ) : 'percent';

				$clean_rule = array(
					'min'  => $min,
					'type' => $type,
				);

				if ( 'fixed' === $type && isset( $rule['price'] ) ) {
					$clean_rule['price'] = floatval( $rule['price'] );
				} elseif ( isset( $rule['discount'] ) ) {
					$clean_rule['discount'] = floatval( $rule['discount'] );
				}

				$rules[] = $clean_rule;
			}

			if ( ! empty( $rules ) ) {
				update_post_meta( $post_id, self::META_QTY_RULES, $rules );
			} else {
				delete_post_meta( $post_id, self::META_QTY_RULES );
			}
		}
	}

	// ─── Product List Column ────────────────────────────────

	public function add_product_column( $columns ) {
		$new = array();
		foreach ( $columns as $key => $value ) {
			$new[ $key ] = $value;
			if ( 'price' === $key ) {
				$new['wholesale_price'] = esc_html__( 'Wholesale', 'wc-wholesale-pricing' );
			}
		}
		return $new;
	}

	public function render_product_column( $column, $post_id ) {
		if ( 'wholesale_price' !== $column ) {
			return;
		}

		$price = get_post_meta( $post_id, self::META_PRICE, true );
		$rules = get_post_meta( $post_id, self::META_QTY_RULES, true );
		$disabled = get_post_meta( $post_id, self::META_DISABLE, true );

		if ( 'yes' === $disabled ) {
			echo '<span style="color:#999;">—</span>';
			return;
		}

		if ( '' !== $price && is_numeric( $price ) ) {
			echo '<span style="color:#027a48;font-weight:600;">' . wp_kses_post( wc_price( $price ) ) . '</span>';
		} elseif ( ! empty( $rules ) ) {
			echo '<span style="color:#b54708;">' . esc_html( count( $rules ) ) . ' ' . esc_html__( 'tiers', 'wc-wholesale-pricing' ) . '</span>';
		} else {
			$settings = $this->get_settings();
			if ( 'yes' === $settings['role_wholesale_customer_enabled'] ) {
				echo '<span style="color:#027a48;">' . esc_html( $settings['role_wholesale_customer_discount'] ) . '% ' . esc_html__( 'off', 'wc-wholesale-pricing' ) . '</span>';
			} else {
				echo '<span style="color:#999;">—</span>';
			}
		}
	}

	// ─── Plugin Links ───────────────────────────────────────

	public function plugin_action_links( $links ) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=wc-wholesale-pricing' ) ),
			esc_html__( 'Settings', 'wc-wholesale-pricing' )
		);
		array_unshift( $links, $settings_link );
		return $links;
	}
}

add_action( 'plugins_loaded', array( 'WC_Wholesale_Pricing', 'instance' ) );

/**
 * Uninstall cleanup.
 */
register_uninstall_hook( __FILE__, 'wc_wholesale_pricing_uninstall' );

function wc_wholesale_pricing_uninstall() {
	delete_option( 'wc_wholesale_settings' );

	$meta_keys = array(
		'_wc_wholesale_price',
		'_wc_wholesale_qty_rules',
		'_wc_wholesale_min_qty',
		'_wc_wholesale_disable',
	);

	$products = get_posts( array(
		'post_type'      => 'product',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	) );

	foreach ( $products as $pid ) {
		foreach ( $meta_keys as $key ) {
			delete_post_meta( $pid, $key );
		}
	}
}