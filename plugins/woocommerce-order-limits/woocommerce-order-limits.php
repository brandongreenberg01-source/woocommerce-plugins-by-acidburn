<?php
/**
 * Plugin Name: WooCommerce Minimum & Maximum Order Limits
 * Plugin URI:  https://acidburnplugins.com/woocommerce-order-limits
 * Description: Set global, per-product, and per-category minimum/maximum order limits. Validate on add-to-cart and checkout with user-friendly error messages and role-based exclusions.
 * Version:     1.0.0
 * Author:      AcidBurn
 * Author URI:  https://acidburnplugins.com
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wc-order-limits
 * Domain Path: /languages
 * Requires Plugins: woocommerce
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 9.0
 *
 * @package WC_Order_Limits
 */

defined( 'ABSPATH' ) || exit;

// ---------------------------------------------------------------------------
// Uninstall hook
// ---------------------------------------------------------------------------
register_uninstall_hook( __FILE__, 'wc_order_limits_uninstall' );

/**
 * Clean up all plugin options on uninstall.
 */
function wc_order_limits_uninstall() {
	delete_option( 'wc_order_limits_settings' );
	delete_option( 'wc_order_limits_category_limits' );
}

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------
define( 'WC_ORDER_LIMITS_VERSION', '1.0.0' );
define( 'WC_ORDER_LIMITS_TEXT_DOMAIN', 'wc-order-limits' );

// ---------------------------------------------------------------------------
// Main plugin class
// ---------------------------------------------------------------------------
if ( ! class_exists( 'WC_Order_Limits' ) ) :

	/**
	 * WooCommerce Minimum & Maximum Order Limits main class.
	 */
	class WC_Order_Limits {

		/**
		 * Plugin settings.
		 *
		 * @var array
		 */
		private static $settings = null;

		/**
		 * Singleton instance.
		 *
		 * @var WC_Order_Limits|null
		 */
		private static $instance = null;

		/**
		 * Return the singleton instance.
		 *
		 * @return WC_Order_Limits
		 */
		public static function get_instance() {
			if ( is_null( self::$instance ) ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Constructor – hook into WordPress and WooCommerce.
		 */
		private function __construct() {
			add_action( 'init', array( $this, 'load_textdomain' ) );

			// Admin: settings page.
			add_action( 'admin_menu', array( $this, 'add_admin_menu' ), 99 );
			add_action( 'admin_init', array( $this, 'register_settings' ) );

			// Admin: product meta box.
			add_action( 'add_meta_boxes', array( $this, 'add_product_meta_box' ) );
			add_action( 'save_post_product', array( $this, 'save_product_meta_box' ) );

			// Admin: category edit screens.
			add_action( 'product_cat_add_form_fields', array( $this, 'category_limits_fields_add' ) );
			add_action( 'product_cat_edit_form_fields', array( $this, 'category_limits_fields_edit' ) );
			add_action( 'created_product_cat', array( $this, 'save_category_limits' ) );
			add_action( 'edited_product_cat', array( $this, 'save_category_limits' ) );

			// Front-end: validation.
			add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add_to_cart' ), 10, 4 );
			add_action( 'woocommerce_check_cart_items', array( $this, 'validate_cart_on_checkout' ) );
			add_action( 'woocommerce_before_cart', array( $this, 'validate_cart_on_checkout' ) );

			// Admin: settings link on plugins page.
			add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'plugin_action_links' ) );

			// Admin: order-by link style.
			add_action( 'admin_enqueue_scripts', array( $this, 'admin_styles' ) );
		}

		/**
		 * Load plugin text domain.
		 */
		public function load_textdomain() {
			load_plugin_textdomain(
				WC_ORDER_LIMITS_TEXT_DOMAIN,
				false,
				dirname( plugin_basename( __FILE__ ) ) . '/languages'
			);
		}

		// -----------------------------------------------------------------------
		// Settings helpers
		// -----------------------------------------------------------------------

		/**
		 * Retrieve all plugin settings with defaults.
		 *
		 * @return array
		 */
		public static function get_settings() {
			if ( is_null( self::$settings ) ) {
				$defaults = array(
					'global_min_total'    => 0,
					'global_max_total'    => 0,
					'excluded_roles'      => array(),
					'enable_roles'        => 'no',
				);
				$saved    = get_option( 'wc_order_limits_settings', array() );
				if ( ! is_array( $saved ) ) {
					$saved = array();
				}
				self::$settings = wp_parse_args( $saved, $defaults );
			}
			return self::$settings;
		}

		/**
		 * Get category limits for all categories or a specific term ID.
		 *
		 * @param int|null $term_id Optional term ID.
		 * @return array
		 */
		public static function get_category_limits( $term_id = null ) {
			$all = get_option( 'wc_order_limits_category_limits', array() );
			if ( ! is_array( $all ) ) {
				$all = array();
			}
			if ( ! is_null( $term_id ) ) {
				return isset( $all[ $term_id ] ) ? $all[ $term_id ] : array();
			}
			return $all;
		}

		/**
		 * Check whether the current user (or a given role) is excluded from limits.
		 *
		 * @param string|null $role Optional role to check.
		 * @return bool
		 */
		private function is_role_excluded( $role = null ) {
			$settings = self::get_settings();
			if ( 'yes' !== $settings['enable_roles'] ) {
				return false;
			}
			$excluded = $settings['excluded_roles'];
			if ( empty( $excluded ) || ! is_array( $excluded ) ) {
				return false;
			}
			if ( is_null( $role ) ) {
				$user = wp_get_current_user();
				if ( ! $user || ! $user->exists() ) {
					return false;
				}
				$roles = (array) $user->roles;
			} else {
				$roles = array( $role );
			}
			foreach ( $roles as $r ) {
				if ( in_array( $r, $excluded, true ) ) {
					return true;
				}
			}
			return false;
		}

		// -----------------------------------------------------------------------
		// Admin menu & settings page
		// -----------------------------------------------------------------------

		/**
		 * Add submenu page under WooCommerce.
		 */
		public function add_admin_menu() {
			add_submenu_page(
				'woocommerce',
				esc_html__( 'Order Limits', 'wc-order-limits' ),
				esc_html__( 'Order Limits', 'wc-order-limits' ),
				'manage_woocommerce',
				'wc-order-limits',
				array( $this, 'render_settings_page' )
			);
		}

		/**
		 * Render the settings page.
		 */
		public function render_settings_page() {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( esc_html__( 'You do not have sufficient permissions.', 'wc-order-limits' ) );
			}
			?>
			<div class="wrap">
				<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
				<form method="post" action="options.php">
					<?php
					settings_fields( 'wc_order_limits_settings_group' );
					do_settings_sections( 'wc-order-limits' );
					submit_button();
					wp_nonce_field( 'wc_order_limits_settings_save', '_wcol_nonce' );
					?>
				</form>
			</div>
			<?php
		}

		/**
		 * Register settings, sections, and fields.
		 */
		public function register_settings() {
			register_setting(
				'wc_order_limits_settings_group',
				'wc_order_limits_settings',
				array(
					'sanitize_callback' => array( $this, 'sanitize_settings' ),
				)
			);

			register_setting(
				'wc_order_limits_settings_group',
				'wc_order_limits_category_limits',
				array(
					'sanitize_callback' => array( $this, 'sanitize_category_limits' ),
				)
			);

			// --- Global Limits section ---
			add_settings_section(
				'wcol_global_section',
				esc_html__( 'Global Order Limits', 'wc-order-limits' ),
				array( $this, 'render_global_section_info' ),
				'wc-order-limits'
			);

			add_settings_field(
				'global_min_total',
				esc_html__( 'Minimum Order Total', 'wc-order-limits' ),
				array( $this, 'render_field_number' ),
				'wc-order-limits',
				'wcol_global_section',
				array(
					'id'          => 'global_min_total',
					'description' => __( 'Minimum cart total required (0 to disable).', 'wc-order-limits' ),
					'step'        => '0.01',
					'min'         => '0',
				)
			);

			add_settings_field(
				'global_max_total',
				esc_html__( 'Maximum Order Total', 'wc-order-limits' ),
				array( $this, 'render_field_number' ),
				'wc-order-limits',
				'wcol_global_section',
				array(
					'id'          => 'global_max_total',
					'description' => __( 'Maximum cart total allowed (0 to disable).', 'wc-order-limits' ),
					'step'        => '0.01',
					'min'         => '0',
				)
			);

			// --- User Role Exclusions section ---
			add_settings_section(
				'wcol_roles_section',
				esc_html__( 'User Role Exclusions', 'wc-order-limits' ),
				array( $this, 'render_roles_section_info' ),
				'wc-order-limits'
			);

			add_settings_field(
				'enable_roles',
				esc_html__( 'Enable Role Exclusions', 'wc-order-limits' ),
				array( $this, 'render_field_checkbox' ),
				'wc-order-limits',
				'wcol_roles_section',
				array(
					'id'          => 'enable_roles',
					'label'       => __( 'Exclude specific user roles from all limits', 'wc-order-limits' ),
				)
			);

			add_settings_field(
				'excluded_roles',
				esc_html__( 'Excluded Roles', 'wc-order-limits' ),
				array( $this, 'render_field_roles' ),
				'wc-order-limits',
				'wcol_roles_section',
				array(
					'id'          => 'excluded_roles',
					'description' => __( 'Select roles that should bypass all order limits.', 'wc-order-limits' ),
				)
			);
		}

		/**
		 * Sanitize settings array.
		 *
		 * @param array $input Raw input.
		 * @return array
		 */
		public function sanitize_settings( $input ) {
			if ( ! isset( $_POST['_wcol_nonce'] ) ||
				! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wcol_nonce'] ) ), 'wc_order_limits_settings_save' ) ) {
				add_settings_error( 'wc_order_limits_settings', 'nonce_fail', __( 'Security check failed.', 'wc-order-limits' ) );
				return get_option( 'wc_order_limits_settings', array() );
			}

			$output = array();

			$output['global_min_total'] = isset( $input['global_min_total'] )
				? floatval( sanitize_text_field( wp_unslash( $input['global_min_total'] ) ) )
				: 0;
			if ( $output['global_min_total'] < 0 ) {
				$output['global_min_total'] = 0;
			}

			$output['global_max_total'] = isset( $input['global_max_total'] )
				? floatval( sanitize_text_field( wp_unslash( $input['global_max_total'] ) ) )
				: 0;
			if ( $output['global_max_total'] < 0 ) {
				$output['global_max_total'] = 0;
			}

			$output['enable_roles'] = isset( $input['enable_roles'] ) && 'yes' === sanitize_text_field( wp_unslash( $input['enable_roles'] ) )
				? 'yes'
				: 'no';

			$output['excluded_roles'] = array();
			if ( isset( $input['excluded_roles'] ) && is_array( $input['excluded_roles'] ) ) {
				$allowed_roles = array_keys( wp_roles()->roles );
				foreach ( $input['excluded_roles'] as $role ) {
					$role = sanitize_text_field( wp_unslash( $role ) );
					if ( in_array( $role, $allowed_roles, true ) ) {
						$output['excluded_roles'][] = $role;
					}
				}
			}

			return $output;
		}

		/**
		 * Sanitize category limits option.
		 *
		 * @param mixed $input Raw input.
		 * @return array
		 */
		public function sanitize_category_limits( $input ) {
			if ( ! isset( $_POST['_wcol_nonce'] ) ||
				! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wcol_nonce'] ) ), 'wc_order_limits_settings_save' ) ) {
				add_settings_error( 'wc_order_limits_category_limits', 'nonce_fail', __( 'Security check failed.', 'wc-order-limits' ) );
				return get_option( 'wc_order_limits_category_limits', array() );
			}

			if ( ! is_array( $input ) ) {
				return array();
			}

			$output = array();
			foreach ( $input as $term_id => $limits ) {
				$term_id = intval( $term_id );
				if ( $term_id <= 0 ) {
					continue;
				}
				$output[ $term_id ] = array(
					'min_qty' => isset( $limits['min_qty'] ) ? intval( sanitize_text_field( wp_unslash( $limits['min_qty'] ) ) ) : 0,
					'max_qty' => isset( $limits['max_qty'] ) ? intval( sanitize_text_field( wp_unslash( $limits['max_qty'] ) ) ) : 0,
				);
				if ( $output[ $term_id ]['min_qty'] < 0 ) {
					$output[ $term_id ]['min_qty'] = 0;
				}
				if ( $output[ $term_id ]['max_qty'] < 0 ) {
					$output[ $term_id ]['max_qty'] = 0;
				}
			}
			return $output;
		}

		// -----------------------------------------------------------------------
		// Settings field renderers
		// -----------------------------------------------------------------------

		/**
		 * Section info: Global.
		 */
		public function render_global_section_info() {
			echo '<p>' . esc_html__( 'Set global cart total limits. Set a value to 0 to disable that limit.', 'wc-order-limits' ) . '</p>';
		}

		/**
		 * Section info: Roles.
		 */
		public function render_roles_section_info() {
			echo '<p>' . esc_html__( 'Optionally exclude specific user roles from all order and quantity limits.', 'wc-order-limits' ) . '</p>';
		}

		/**
		 * Render a number input field.
		 *
		 * @param array $args Field arguments.
		 */
		public function render_field_number( $args ) {
			$settings = self::get_settings();
			$id       = esc_attr( $args['id'] );
			$value    = isset( $settings[ $id ] ) ? esc_attr( $settings[ $id ] ) : 0;
			$step     = isset( $args['step'] ) ? esc_attr( $args['step'] ) : '1';
			$min      = isset( $args['min'] ) ? esc_attr( $args['min'] ) : '0';
			$desc     = isset( $args['description'] ) ? $args['description'] : '';
			printf(
				'<input type="number" id="%1$s" name="wc_order_limits_settings[%1$s]" value="%2$s" step="%3$s" min="%4$s" class="small-text" />',
				esc_attr( $id ),
				esc_attr( $value ),
				esc_attr( $step ),
				esc_attr( $min )
			);
			if ( $desc ) {
				printf( '<p class="description">%s</p>', esc_html( $desc ) );
			}
		}

		/**
		 * Render a checkbox field.
		 *
		 * @param array $args Field arguments.
		 */
		public function render_field_checkbox( $args ) {
			$settings = self::get_settings();
			$id       = esc_attr( $args['id'] );
			$checked  = isset( $settings[ $id ] ) && 'yes' === $settings[ $id ] ? 'checked' : '';
			$label    = isset( $args['label'] ) ? $args['label'] : '';
			printf(
				'<label><input type="checkbox" id="%1$s" name="wc_order_limits_settings[%1$s]" value="yes" %2$s /> %3$s</label>',
				esc_attr( $id ),
				esc_attr( $checked ),
				esc_html( $label )
			);
		}

		/**
		 * Render role checkboxes.
		 *
		 * @param array $args Field arguments.
		 */
		public function render_field_roles( $args ) {
			$settings = self::get_settings();
			$id       = esc_attr( $args['id'] );
			$selected = isset( $settings[ $id ] ) && is_array( $settings[ $id ] ) ? $settings[ $id ] : array();
			$roles    = wp_roles()->roles;
			$desc     = isset( $args['description'] ) ? $args['description'] : '';

			echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Excluded Roles', 'wc-order-limits' ) . '</legend>';
			foreach ( $roles as $role_key => $role_data ) {
				$checked = in_array( $role_key, $selected, true ) ? 'checked' : '';
				printf(
					'<label><input type="checkbox" name="wc_order_limits_settings[%1$s][]" value="%2$s" %3$s /> %4$s</label><br />',
					esc_attr( $id ),
					esc_attr( $role_key ),
					esc_attr( $checked ),
					esc_html( translate_user_role( $role_data['name'] ) )
				);
			}
			if ( $desc ) {
				printf( '<p class="description">%s</p>', esc_html( $desc ) );
			}
			echo '</fieldset>';
		}

		// -----------------------------------------------------------------------
		// Product meta box (per-product overrides)
		// -----------------------------------------------------------------------

		/**
		 * Add meta box to product edit screen.
		 */
		public function add_product_meta_box() {
			add_meta_box(
				'wc_order_limits_product',
				esc_html__( 'Order Limits Override', 'wc-order-limits' ),
				array( $this, 'render_product_meta_box' ),
				'product',
				'side',
				'default'
			);
		}

		/**
		 * Render product meta box.
		 *
		 * @param WP_Post $post Post object.
		 */
		public function render_product_meta_box( $post ) {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				return;
			}

			wp_nonce_field( 'wc_order_limits_product_save', '_wcol_product_nonce' );

			$min_qty = get_post_meta( $post->ID, '_wcol_min_qty', true );
			$max_qty = get_post_meta( $post->ID, '_wcol_max_qty', true );
			?>
			<p>
				<label for="_wcol_min_qty"><?php esc_html_e( 'Min Quantity', 'wc-order-limits' ); ?></label>
				<input type="number" id="_wcol_min_qty" name="_wcol_min_qty" value="<?php echo esc_attr( $min_qty ?: '' ); ?>" class="widefat" step="1" min="0" placeholder="<?php esc_attr_e( '0 = no limit', 'wc-order-limits' ); ?>" />
			</p>
			<p>
				<label for="_wcol_max_qty"><?php esc_html_e( 'Max Quantity', 'wc-order-limits' ); ?></label>
				<input type="number" id="_wcol_max_qty" name="_wcol_max_qty" value="<?php echo esc_attr( $max_qty ?: '' ); ?>" class="widefat" step="1" min="0" placeholder="<?php esc_attr_e( '0 = no limit', 'wc-order-limits' ); ?>" />
			</p>
			<?php
		}

		/**
		 * Save product meta box data.
		 *
		 * @param int $post_id Post ID.
		 */
		public function save_product_meta_box( $post_id ) {
			if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
				return;
			}
			if ( ! isset( $_POST['_wcol_product_nonce'] ) ||
				! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wcol_product_nonce'] ) ), 'wc_order_limits_product_save' ) ) {
				return;
			}
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				return;
			}
			if ( 'product' !== get_post_type( $post_id ) ) {
				return;
			}

			$min_qty = isset( $_POST['_wcol_min_qty'] ) ? intval( sanitize_text_field( wp_unslash( $_POST['_wcol_min_qty'] ) ) ) : 0;
			$max_qty = isset( $_POST['_wcol_max_qty'] ) ? intval( sanitize_text_field( wp_unslash( $_POST['_wcol_max_qty'] ) ) ) : 0;

			if ( $min_qty < 0 ) {
				$min_qty = 0;
			}
			if ( $max_qty < 0 ) {
				$max_qty = 0;
			}

			if ( $min_qty > 0 ) {
				update_post_meta( $post_id, '_wcol_min_qty', $min_qty );
			} else {
				delete_post_meta( $post_id, '_wcol_min_qty' );
			}

			if ( $max_qty > 0 ) {
				update_post_meta( $post_id, '_wcol_max_qty', $max_qty );
			} else {
				delete_post_meta( $post_id, '_wcol_max_qty' );
			}
		}

		// -----------------------------------------------------------------------
		// Category limits (add/edit screens)
		// -----------------------------------------------------------------------

		/**
		 * Fields on the Add New Category screen.
		 */
		public function category_limits_fields_add() {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				return;
			}
			wp_nonce_field( 'wc_order_limits_cat_save', '_wcol_cat_nonce' );
			?>
			<div class="form-field term-wcol-limits-wrap">
				<h3><?php esc_html_e( 'Order Limits', 'wc-order-limits' ); ?></h3>
				<label for="wcol_cat_min_qty"><?php esc_html_e( 'Minimum Quantity', 'wc-order-limits' ); ?></label>
				<input type="number" id="wcol_cat_min_qty" name="wcol_cat_min_qty" value="" step="1" min="0" placeholder="0" />
				<p class="description"><?php esc_html_e( 'Minimum quantity per product in this category (0 = no limit).', 'wc-order-limits' ); ?></p>

				<label for="wcol_cat_max_qty"><?php esc_html_e( 'Maximum Quantity', 'wc-order-limits' ); ?></label>
				<input type="number" id="wcol_cat_max_qty" name="wcol_cat_max_qty" value="" step="1" min="0" placeholder="0" />
				<p class="description"><?php esc_html_e( 'Maximum quantity per product in this category (0 = no limit).', 'wc-order-limits' ); ?></p>
			</div>
			<?php
		}

		/**
		 * Fields on the Edit Category screen.
		 *
		 * @param WP_Term $term Term object.
		 */
		public function category_limits_fields_edit( $term ) {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				return;
			}
			wp_nonce_field( 'wc_order_limits_cat_save', '_wcol_cat_nonce' );
			$limits = self::get_category_limits( $term->term_id );
			$min    = isset( $limits['min_qty'] ) ? intval( $limits['min_qty'] ) : 0;
			$max    = isset( $limits['max_qty'] ) ? intval( $limits['max_qty'] ) : 0;
			?>
			<tr class="form-field term-wcol-limits-wrap">
				<th colspan="2">
					<h3><?php esc_html_e( 'Order Limits', 'wc-order-limits' ); ?></h3>
				</th>
			</tr>
			<tr class="form-field term-wcol-min-qty-wrap">
				<th scope="row">
					<label for="wcol_cat_min_qty"><?php esc_html_e( 'Minimum Quantity', 'wc-order-limits' ); ?></label>
				</th>
				<td>
					<input type="number" id="wcol_cat_min_qty" name="wcol_cat_min_qty" value="<?php echo esc_attr( $min ); ?>" step="1" min="0" />
					<p class="description"><?php esc_html_e( 'Minimum quantity per product in this category (0 = no limit).', 'wc-order-limits' ); ?></p>
				</td>
			</tr>
			<tr class="form-field term-wcol-max-qty-wrap">
				<th scope="row">
					<label for="wcol_cat_max_qty"><?php esc_html_e( 'Maximum Quantity', 'wc-order-limits' ); ?></label>
				</th>
				<td>
					<input type="number" id="wcol_cat_max_qty" name="wcol_cat_max_qty" value="<?php echo esc_attr( $max ); ?>" step="1" min="0" />
					<p class="description"><?php esc_html_e( 'Maximum quantity per product in this category (0 = no limit).', 'wc-order-limits' ); ?></p>
				</td>
			</tr>
			<?php
		}

		/**
		 * Save category limits when a category is created or edited.
		 *
		 * @param int $term_id Term ID.
		 */
		public function save_category_limits( $term_id ) {
			if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
				return;
			}
			if ( ! isset( $_POST['_wcol_cat_nonce'] ) ||
				! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wcol_cat_nonce'] ) ), 'wc_order_limits_cat_save' ) ) {
				return;
			}
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				return;
			}

			$all     = self::get_category_limits();
			$min_qty = isset( $_POST['wcol_cat_min_qty'] ) ? intval( sanitize_text_field( wp_unslash( $_POST['wcol_cat_min_qty'] ) ) ) : 0;
			$max_qty = isset( $_POST['wcol_cat_max_qty'] ) ? intval( sanitize_text_field( wp_unslash( $_POST['wcol_cat_max_qty'] ) ) ) : 0;

			if ( $min_qty < 0 ) {
				$min_qty = 0;
			}
			if ( $max_qty < 0 ) {
				$max_qty = 0;
			}

			if ( $min_qty > 0 || $max_qty > 0 ) {
				$all[ $term_id ] = array(
					'min_qty' => $min_qty,
					'max_qty' => $max_qty,
				);
			} else {
				unset( $all[ $term_id ] );
			}

			update_option( 'wc_order_limits_category_limits', $all );
		}

		// -----------------------------------------------------------------------
		// Validation
		// -----------------------------------------------------------------------

		/**
		 * Validate on add-to-cart.
		 *
		 * @param bool $passed     Whether validation passed.
		 * @param int  $product_id Product ID.
		 * @param int  $quantity   Quantity being added.
		 * @return bool
		 */
		public function validate_add_to_cart( $passed, $product_id, $quantity ) {
			if ( $this->is_role_excluded() ) {
				return $passed;
			}

			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				return $passed;
			}

			// --- Per-product quantity limits ---
			$prod_min_qty = get_post_meta( $product_id, '_wcol_min_qty', true );
			$prod_max_qty = get_post_meta( $product_id, '_wcol_max_qty', true );

			if ( ! empty( $prod_min_qty ) && intval( $prod_min_qty ) > 0 && $quantity < intval( $prod_min_qty ) ) {
				/* translators: 1: product name 2: minimum quantity */
				$message = sprintf(
					__( 'You cannot add %1$s to the cart. The minimum quantity is %2$s.', 'wc-order-limits' ),
					$product->get_name(),
					intval( $prod_min_qty )
				);
				if ( function_exists( 'wc_add_notice' ) ) {
					wc_add_notice( esc_html( $message ), 'error' );
				}
				return false;
			}

			if ( ! empty( $prod_max_qty ) && intval( $prod_max_qty ) > 0 && $quantity > intval( $prod_max_qty ) ) {
				/* translators: 1: product name 2: maximum quantity */
				$message = sprintf(
					__( 'You cannot add %1$s to the cart. The maximum quantity is %2$s.', 'wc-order-limits' ),
					$product->get_name(),
					intval( $prod_max_qty )
				);
				if ( function_exists( 'wc_add_notice' ) ) {
					wc_add_notice( esc_html( $message ), 'error' );
				}
				return false;
			}

			// --- Per-category quantity limits ---
			$term_ids = wc_get_product_term_ids( $product_id, 'product_cat' );
			$cat_limits = self::get_category_limits();

			foreach ( $term_ids as $term_id ) {
				if ( ! isset( $cat_limits[ $term_id ] ) ) {
					continue;
				}
				$limits = $cat_limits[ $term_id ];

				if ( ! empty( $limits['min_qty'] ) && $limits['min_qty'] > 0 && $quantity < $limits['min_qty'] ) {
					$term = get_term( $term_id, 'product_cat' );
					$cat_name = $term ? $term->name : '';
					/* translators: 1: product name 2: category name 3: minimum quantity */
					$message = sprintf(
						__( 'You cannot add %1$s to the cart. Products in category "%2$s" require a minimum quantity of %3$s.', 'wc-order-limits' ),
						$product->get_name(),
						$cat_name,
						intval( $limits['min_qty'] )
					);
					if ( function_exists( 'wc_add_notice' ) ) {
						wc_add_notice( esc_html( $message ), 'error' );
					}
					return false;
				}

				if ( ! empty( $limits['max_qty'] ) && $limits['max_qty'] > 0 && $quantity > $limits['max_qty'] ) {
					$term = get_term( $term_id, 'product_cat' );
					$cat_name = $term ? $term->name : '';
					/* translators: 1: product name 2: category name 3: maximum quantity */
					$message = sprintf(
						__( 'You cannot add %1$s to the cart. Products in category "%2$s" have a maximum quantity of %3$s.', 'wc-order-limits' ),
						$product->get_name(),
						$cat_name,
						intval( $limits['max_qty'] )
					);
					if ( function_exists( 'wc_add_notice' ) ) {
						wc_add_notice( esc_html( $message ), 'error' );
					}
					return false;
				}
			}

			return $passed;
		}

		/**
		 * Validate entire cart on checkout / cart page.
		 */
		public function validate_cart_on_checkout() {
			if ( $this->is_role_excluded() ) {
				return;
			}

			$settings = self::get_settings();
			$min_total = floatval( $settings['global_min_total'] );
			$max_total = floatval( $settings['global_max_total'] );

			if ( $min_total <= 0 && $max_total <= 0 ) {
				// No global total limits, but still check quantity limits per item in cart.
				$this->validate_cart_quantities();
				return;
			}

			if ( ! WC()->cart || WC()->cart->is_empty() ) {
				return;
			}

			$cart_total = floatval( WC()->cart->get_total( 'edit' ) );

			if ( $min_total > 0 && $cart_total < $min_total ) {
				/* translators: %s: formatted minimum total */
				$message = sprintf(
					__( 'The minimum order total is %s. Please add more items to your cart.', 'wc-order-limits' ),
					wc_price( $min_total )
				);
				wc_add_notice( esc_html( $message ), 'error' );
			}

			if ( $max_total > 0 && $cart_total > $max_total ) {
				/* translators: %s: formatted maximum total */
				$message = sprintf(
					__( 'The maximum order total is %s. Please remove some items from your cart.', 'wc-order-limits' ),
					wc_price( $max_total )
				);
				wc_add_notice( esc_html( $message ), 'error' );
			}

			// Also validate per-item quantities on cart/checkout.
			$this->validate_cart_quantities();
		}

		/**
		 * Validate per-product and per-category quantity limits for all cart items.
		 */
		private function validate_cart_quantities() {
			if ( ! WC()->cart || WC()->cart->is_empty() ) {
				return;
			}

			$cat_limits = self::get_category_limits();

			foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
				$product    = $cart_item['data'];
				$product_id = $product->get_id();
				$quantity   = intval( $cart_item['quantity'] );

				// Per-product.
				$prod_min_qty = get_post_meta( $product_id, '_wcol_min_qty', true );
				$prod_max_qty = get_post_meta( $product_id, '_wcol_max_qty', true );

				if ( ! empty( $prod_min_qty ) && intval( $prod_min_qty ) > 0 && $quantity < intval( $prod_min_qty ) ) {
					/* translators: 1: product name 2: minimum quantity */
					$message = sprintf(
						__( 'The quantity of "%1$s" in your cart (%3$d) is below the minimum of %2$s.', 'wc-order-limits' ),
						$product->get_name(),
						intval( $prod_min_qty ),
						$quantity
					);
					wc_add_notice( esc_html( $message ), 'error' );
				}

				if ( ! empty( $prod_max_qty ) && intval( $prod_max_qty ) > 0 && $quantity > intval( $prod_max_qty ) ) {
					/* translators: 1: product name 2: maximum quantity */
					$message = sprintf(
						__( 'The quantity of "%1$s" in your cart (%3$d) exceeds the maximum of %2$s.', 'wc-order-limits' ),
						$product->get_name(),
						intval( $prod_max_qty ),
						$quantity
					);
					wc_add_notice( esc_html( $message ), 'error' );
				}

				// Per-category.
				$term_ids = wc_get_product_term_ids( $product_id, 'product_cat' );
				foreach ( $term_ids as $term_id ) {
					if ( ! isset( $cat_limits[ $term_id ] ) ) {
						continue;
					}
					$limits = $cat_limits[ $term_id ];

					if ( ! empty( $limits['min_qty'] ) && $limits['min_qty'] > 0 && $quantity < $limits['min_qty'] ) {
						$term     = get_term( $term_id, 'product_cat' );
						$cat_name = $term ? $term->name : '';
						/* translators: 1: product name 2: category name 3: minimum quantity */
						$message = sprintf(
							__( 'The quantity of "%1$s" (%4$d) is below the minimum of %3$s for category "%2$s".', 'wc-order-limits' ),
							$product->get_name(),
							$cat_name,
							intval( $limits['min_qty'] ),
							$quantity
						);
						wc_add_notice( esc_html( $message ), 'error' );
					}

					if ( ! empty( $limits['max_qty'] ) && $limits['max_qty'] > 0 && $quantity > $limits['max_qty'] ) {
						$term     = get_term( $term_id, 'product_cat' );
						$cat_name = $term ? $term->name : '';
						/* translators: 1: product name 2: category name 3: maximum quantity */
						$message = sprintf(
							__( 'The quantity of "%1$s" (%4$d) exceeds the maximum of %3$s for category "%2$s".', 'wc-order-limits' ),
							$product->get_name(),
							$cat_name,
							intval( $limits['max_qty'] ),
							$quantity
						);
						wc_add_notice( esc_html( $message ), 'error' );
					}
				}
			}
		}

		// -----------------------------------------------------------------------
		// Plugin action links
		// -----------------------------------------------------------------------

		/**
		 * Add "Settings" link on plugins page.
		 *
		 * @param array $links Existing links.
		 * @return array
		 */
		public function plugin_action_links( $links ) {
			$settings_link = sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=wc-order-limits' ) ),
				esc_html__( 'Settings', 'wc-order-limits' )
			);
			array_unshift( $links, $settings_link );
			return $links;
		}

		/**
		 * Enqueue minimal admin styling.
		 *
		 * @param string $hook Current admin page hook.
		 */
		public function admin_styles( $hook ) {
			if ( 'woocommerce_page_wc-order-limits' !== $hook ) {
				return;
			}
			echo '<style>
				.form-table .description { margin-top: 4px; }
				.term-wcol-limits-wrap h3 { margin: 0 0 8px; }
			</style>';
		}
	}

endif;

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------
add_action( 'plugins_loaded', 'wc_order_limits_init' );

/**
 * Initialize the plugin.
 */
function wc_order_limits_init() {
	// Only run if WooCommerce is active.
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}
	WC_Order_Limits::get_instance();
}
