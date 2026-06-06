<?php
/**
 * Plugin Name: WooCommerce Smart Coupons
 * Plugin URI:  https://example.com/woocommerce-smart-coupons
 * Description: Advanced coupon management for WooCommerce — auto-apply coupons, BOGO deals, URL triggers, coupon scheduler, restrictions, and admin dashboard.
 * Version:     1.0.0
 * Author:      AcidBurn
 * Author URI:  https://example.com
 * Text Domain: wc-smart-coupons
 * Domain Path: /languages
 * Requires Plugins: woocommerce
 * WC requires at least: 6.0
 * WC tested up to: 8.5
 *
 * @package WooCommerce_Smart_Coupons
 */

defined( 'ABSPATH' ) || exit;

// ──────────────────────────────────────────────
//  CONSTANTS
// ──────────────────────────────────────────────

define( 'WCSC_VERSION', '1.0.0' );
define( 'WCSC_PLUGIN_FILE', __FILE__ );
define( 'WCSC_TEXT_DOMAIN', 'wc-smart-coupons' );
define( 'WCSC_OPTION_GROUP', 'wc_smart_coupons_settings' );
define( 'WCSC_SETTINGS_KEY', 'wc_smart_coupons_settings' );
define( 'WCSC_BOGO_KEY', 'wc_smart_coupons_bogo' );
define( 'WCSC_URL_COUPONS_KEY', 'wc_smart_coupons_url_coupons' );
define( 'WCSC_SCHEDULER_KEY', 'wc_smart_coupons_scheduler' );
define( 'WCSC_NONCE_ACTION', 'wc_smart_coupons_nonce' );
define( 'WCSC_NONCE_AJAX', 'wc_smart_coupons_ajax_nonce' );
define( 'WCSC_CAPABILITY', 'manage_woocommerce' );

// ──────────────────────────────────────────────
//  MAIN PLUGIN CLASS
// ──────────────────────────────────────────────

if ( ! class_exists( 'WooCommerce_Smart_Coupons' ) ) {

	/**
	 * WooCommerce Smart Coupons main class.
	 */
	class WooCommerce_Smart_Coupons {

		/**
		 * Singleton instance.
		 *
		 * @var self
		 */
		private static $instance = null;

		/**
		 * Get singleton instance.
		 *
		 * @return self
		 */
		public static function get_instance() {
			if ( is_null( self::$instance ) ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Constructor.
		 */
		private function __construct() {
			add_action( 'init', array( $this, 'init' ) );

			// Admin hooks.
			add_action( 'admin_menu', array( $this, 'add_admin_menu' ), 50 );
			add_action( 'admin_init', array( $this, 'register_settings' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
			add_action( 'wp_ajax_wcsc_bogo_save', array( $this, 'ajax_bogo_save' ) );
			add_action( 'wp_ajax_wcsc_bogo_delete', array( $this, 'ajax_bogo_delete' ) );
			add_action( 'wp_ajax_wcsc_url_coupon_save', array( $this, 'ajax_url_coupon_save' ) );
			add_action( 'wp_ajax_wcsc_url_coupon_delete', array( $this, 'ajax_url_coupon_delete' ) );
			add_action( 'wp_ajax_wcsc_scheduler_save', array( $this, 'ajax_scheduler_save' ) );
			add_action( 'wp_ajax_wcsc_scheduler_delete', array( $this, 'ajax_scheduler_delete' ) );
			add_action( 'wp_ajax_wcsc_dashboard_stats', array( $this, 'ajax_dashboard_stats' ) );

			// Front-end hooks.
			add_action( 'template_redirect', array( $this, 'handle_url_coupon' ) );
			add_action( 'woocommerce_before_cart', array( $this, 'auto_apply_coupons' ) );
			add_action( 'woocommerce_before_checkout_form', array( $this, 'auto_apply_coupons' ), 10 );
			add_filter( 'woocommerce_coupon_is_valid', array( $this, 'apply_coupon_restrictions' ), 10, 3 );
			add_filter( 'woocommerce_coupon_is_valid_for_product', array( $this, 'coupon_valid_for_product' ), 10, 4 );
			add_action( 'woocommerce_check_cart_items', array( $this, 'handle_bogo_in_cart' ) );
			add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_bogo_discounts' ), 20 );

			// Scheduler cron.
			add_action( 'wcsc_daily_scheduler_check', array( $this, 'daily_scheduler_check' ) );

			// Plugin action links.
			add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'plugin_action_links' ) );
		}

		/**
		 * Init hook.
		 *
		 * @return void
		 */
		public function init() {
			load_plugin_textdomain( WCSC_TEXT_DOMAIN, false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

			if ( ! wp_next_scheduled( 'wcsc_daily_scheduler_check' ) ) {
				wp_schedule_event( time(), 'daily', 'wcsc_daily_scheduler_check' );
			}
		}

		// ──────────────────────────────────────────
		//  ADMIN MENU & SETTINGS
		// ──────────────────────────────────────────

		/**
		 * Add admin menu page under WooCommerce.
		 *
		 * @return void
		 */
		public function add_admin_menu() {
			if ( ! current_user_can( WCSC_CAPABILITY ) ) {
				return;
			}

			add_submenu_page(
				'woocommerce',
				esc_html__( 'Smart Coupons', WCSC_TEXT_DOMAIN ),
				esc_html__( 'Smart Coupons', WCSC_TEXT_DOMAIN ),
				WCSC_CAPABILITY,
				'wc-smart-coupons',
				array( $this, 'render_admin_page' )
			);
		}

		/**
		 * Register plugin settings.
		 *
		 * @return void
		 */
		public function register_settings() {
			register_setting(
				WCSC_OPTION_GROUP,
				WCSC_SETTINGS_KEY,
				array(
					'sanitize_callback' => array( $this, 'sanitize_settings' ),
					'default'           => array(
						'enable_auto_apply'  => 'yes',
						'enable_url_coupons' => 'yes',
						'enable_scheduler'   => 'yes',
						'enable_bogo'        => 'yes',
						'enable_restrictions' => 'yes',
					),
				)
			);
		}

		/**
		 * Sanitize settings.
		 *
		 * @param  array $input Raw input.
		 * @return array
		 */
		public function sanitize_settings( $input ) {
			if ( ! is_array( $input ) ) {
				return array();
			}

			$sanitized = array();
			$allowed   = array( 'yes', 'no' );

			$sanitized['enable_auto_apply']   = in_array( $input['enable_auto_apply'] ?? '', $allowed, true )
				? sanitize_text_field( $input['enable_auto_apply'] )
				: 'yes';
			$sanitized['enable_url_coupons']  = in_array( $input['enable_url_coupons'] ?? '', $allowed, true )
				? sanitize_text_field( $input['enable_url_coupons'] )
				: 'yes';
			$sanitized['enable_scheduler']    = in_array( $input['enable_scheduler'] ?? '', $allowed, true )
				? sanitize_text_field( $input['enable_scheduler'] )
				: 'yes';
			$sanitized['enable_bogo']         = in_array( $input['enable_bogo'] ?? '', $allowed, true )
				? sanitize_text_field( $input['enable_bogo'] )
				: 'yes';
			$sanitized['enable_restrictions'] = in_array( $input['enable_restrictions'] ?? '', $allowed, true )
				? sanitize_text_field( $input['enable_restrictions'] )
				: 'yes';

			// Auto-apply conditions.
			if ( isset( $input['auto_conditions'] ) && is_array( $input['auto_conditions'] ) ) {
				$sanitized['auto_conditions'] = array();
				foreach ( $input['auto_conditions'] as $idx => $cond ) {
					$c = array();
					if ( ! empty( $cond['type'] ) ) {
						$c['type']   = sanitize_key( $cond['type'] );
						$c['value']  = sanitize_text_field( $cond['value'] );
						$c['coupon'] = sanitize_text_field( $cond['coupon'] );
						if ( 'category' === $c['type'] ) {
							$c['category_id'] = absint( $cond['category_id'] ?? 0 );
						}
						if ( 'product' === $c['type'] ) {
							$c['product_id'] = absint( $cond['product_id'] ?? 0 );
						}
						$sanitized['auto_conditions'][] = $c;
					}
				}
			}

			return $sanitized;
		}

		/**
		 * Enqueue admin scripts and styles.
		 *
		 * @param  string $hook Current admin page hook.
		 * @return void
		 */
		public function admin_enqueue_scripts( $hook ) {
			if ( 'woocommerce_page_wc-smart-coupons' !== $hook ) {
				return;
			}
			wp_enqueue_style( 'wcsc-admin', false, array(), WCSC_VERSION );
			wp_add_inline_style( 'wcsc-admin', $this->get_admin_css() );
			wp_enqueue_script( 'wcsc-admin', false, array( 'jquery' ), WCSC_VERSION, true );
			wp_add_inline_script( 'wcsc-admin', $this->get_admin_js() );
			wp_localize_script(
				'wcsc-admin',
				'wcsc_admin',
				array(
					'ajax_url' => esc_url( admin_url( 'admin-ajax.php' ) ),
					'nonce'    => wp_create_nonce( WCSC_NONCE_AJAX ),
				)
			);
		}

		/**
		 * Render the admin settings page with tabs.
		 *
		 * @return void
		 */
		public function render_admin_page() {
			if ( ! current_user_can( WCSC_CAPABILITY ) ) {
				wp_die( esc_html__( 'You do not have sufficient permissions.', WCSC_TEXT_DOMAIN ) );
			}

			$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'dashboard';
			$tabs       = array(
				'dashboard'   => __( 'Dashboard', WCSC_TEXT_DOMAIN ),
				'settings'    => __( 'Settings', WCSC_TEXT_DOMAIN ),
				'auto_apply'  => __( 'Auto-Apply', WCSC_TEXT_DOMAIN ),
				'bogo'        => __( 'BOGO Deals', WCSC_TEXT_DOMAIN ),
				'url_coupons' => __( 'URL Coupons', WCSC_TEXT_DOMAIN ),
				'scheduler'   => __( 'Scheduler', WCSC_TEXT_DOMAIN ),
			);

			?>
			<div class="wrap wcsc-wrap">
				<h1><?php echo esc_html__( 'WooCommerce Smart Coupons', WCSC_TEXT_DOMAIN ); ?></h1>
				<h2 class="nav-tab-wrapper">
					<?php foreach ( $tabs as $tab_key => $tab_label ) : ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-smart-coupons&tab=' . $tab_key ) ); ?>"
							class="nav-tab <?php echo $active_tab === $tab_key ? 'nav-tab-active' : ''; ?>">
							<?php echo esc_html( $tab_label ); ?>
						</a>
					<?php endforeach; ?>
				</h2>
				<?php
				switch ( $active_tab ) {
					case 'settings':
						$this->render_settings_tab();
						break;
					case 'auto_apply':
						$this->render_auto_apply_tab();
						break;
					case 'bogo':
						$this->render_bogo_tab();
						break;
					case 'url_coupons':
						$this->render_url_coupons_tab();
						break;
					case 'scheduler':
						$this->render_scheduler_tab();
						break;
					default:
						$this->render_dashboard_tab();
						break;
				}
				?>
			</div>
			<?php
		}

		// ──────────────────────────────────────────
		//  DASHBOARD TAB
		// ──────────────────────────────────────────

		/**
		 * Render the Dashboard tab.
		 *
		 * @return void
		 */
		private function render_dashboard_tab() {
			if ( ! current_user_can( WCSC_CAPABILITY ) ) {
				return;
			}

			$active_coupons = $this->get_active_coupons_count();
			$coupon_stats   = $this->get_coupon_usage_stats();
			$bogo_count     = count( get_option( WCSC_BOGO_KEY, array() ) );
			$url_count      = count( get_option( WCSC_URL_COUPONS_KEY, array() ) );
			$scheduled      = count( get_option( WCSC_SCHEDULER_KEY, array() ) );

			$settings = get_option( WCSC_SETTINGS_KEY, array() );
			$auto_conditions = ! empty( $settings['auto_conditions'] ) ? $settings['auto_conditions'] : array();

			?>
			<div class="wcsc-dashboard">
				<div class="wcsc-stats-grid">
					<div class="wcsc-stat-box">
						<span class="wcsc-stat-number"><?php echo esc_html( number_format_i18n( $active_coupons ) ); ?></span>
						<span class="wcsc-stat-label"><?php echo esc_html__( 'Active Coupons', WCSC_TEXT_DOMAIN ); ?></span>
					</div>
					<div class="wcsc-stat-box">
						<span class="wcsc-stat-number"><?php echo esc_html( number_format_i18n( $coupon_stats['total_used'] ) ); ?></span>
						<span class="wcsc-stat-label"><?php echo esc_html__( 'Total Uses', WCSC_TEXT_DOMAIN ); ?></span>
					</div>
					<div class="wcsc-stat-box">
						<span class="wcsc-stat-number"><?php echo esc_html( $coupon_stats['total_savings_formatted'] ); ?></span>
						<span class="wcsc-stat-label"><?php echo esc_html__( 'Total Savings', WCSC_TEXT_DOMAIN ); ?></span>
					</div>
					<div class="wcsc-stat-box">
						<span class="wcsc-stat-number"><?php echo esc_html( number_format_i18n( $bogo_count ) ); ?></span>
						<span class="wcsc-stat-label"><?php echo esc_html__( 'BOGO Deals', WCSC_TEXT_DOMAIN ); ?></span>
					</div>
					<div class="wcsc-stat-box">
						<span class="wcsc-stat-number"><?php echo esc_html( number_format_i18n( $url_count ) ); ?></span>
						<span class="wcsc-stat-label"><?php echo esc_html__( 'URL Coupons', WCSC_TEXT_DOMAIN ); ?></span>
					</div>
					<div class="wcsc-stat-box">
						<span class="wcsc-stat-number"><?php echo esc_html( number_format_i18n( $scheduled ) ); ?></span>
						<span class="wcsc-stat-label"><?php echo esc_html__( 'Scheduled Rules', WCSC_TEXT_DOMAIN ); ?></span>
					</div>
				</div>

				<div class="wcsc-dashboard-section">
					<h2><?php echo esc_html__( 'Top Coupons by Usage', WCSC_TEXT_DOMAIN ); ?></h2>
					<?php $this->render_top_coupons_table(); ?>
				</div>

				<div class="wcsc-dashboard-section">
					<h2><?php echo esc_html__( 'Active Auto-Apply Rules', WCSC_TEXT_DOMAIN ); ?></h2>
					<?php if ( empty( $auto_conditions ) ) : ?>
						<p><?php echo esc_html__( 'No auto-apply rules configured.', WCSC_TEXT_DOMAIN ); ?></p>
					<?php else : ?>
						<table class="wp-list-table widefat fixed striped">
							<thead>
								<tr>
									<th><?php echo esc_html__( 'Condition', WCSC_TEXT_DOMAIN ); ?></th>
									<th><?php echo esc_html__( 'Value', WCSC_TEXT_DOMAIN ); ?></th>
									<th><?php echo esc_html__( 'Coupon', WCSC_TEXT_DOMAIN ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $auto_conditions as $cond ) : ?>
									<tr>
										<td><?php echo esc_html( ucfirst( $cond['type'] ?? '' ) ); ?></td>
										<td><?php echo esc_html( $cond['value'] ?? '' ); ?></td>
										<td><?php echo esc_html( $cond['coupon'] ?? '' ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>
			</div>
			<?php
		}

		/**
		 * Render top coupons usage table.
		 *
		 * @return void
		 */
		private function render_top_coupons_table() {
			$coupons = $this->get_top_coupons( 10 );
			if ( empty( $coupons ) ) {
				echo '<p>' . esc_html__( 'No coupon usage data yet.', WCSC_TEXT_DOMAIN ) . '</p>';
				return;
			}
			?>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Coupon Code', WCSC_TEXT_DOMAIN ); ?></th>
						<th><?php echo esc_html__( 'Usage Count', WCSC_TEXT_DOMAIN ); ?></th>
						<th><?php echo esc_html__( 'Total Discount', WCSC_TEXT_DOMAIN ); ?></th>
						<th><?php echo esc_html__( 'Expiry', WCSC_TEXT_DOMAIN ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $coupons as $c ) : ?>
						<tr>
							<td>
								<a href="<?php echo esc_url( admin_url( 'post.php?post=' . absint( $c['id'] ) . '&action=edit' ) ); ?>">
									<?php echo esc_html( $c['code'] ); ?>
								</a>
							</td>
							<td><?php echo esc_html( number_format_i18n( $c['usage_count'] ) ); ?></td>
							<td><?php echo esc_html( $c['total_discount_formatted'] ); ?></td>
							<td><?php echo esc_html( $c['expiry'] ? $c['expiry'] : '—' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php
		}

		// ──────────────────────────────────────────
		//  SETTINGS TAB
		// ──────────────────────────────────────────

		/**
		 * Render the Settings tab.
		 *
		 * @return void
		 */
		private function render_settings_tab() {
			if ( ! current_user_can( WCSC_CAPABILITY ) ) {
				return;
			}

			$settings = get_option( WCSC_SETTINGS_KEY, array() );
			?>
			<form method="post" action="options.php">
				<?php settings_fields( WCSC_OPTION_GROUP ); ?>
				<table class="form-table">
					<tbody>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Enable Auto-Apply', WCSC_TEXT_DOMAIN ); ?></th>
							<td>
								<label>
									<input type="checkbox"
										name="<?php echo esc_attr( WCSC_SETTINGS_KEY ); ?>[enable_auto_apply]"
										value="yes"
										<?php checked( $settings['enable_auto_apply'] ?? 'yes', 'yes' ); ?>>
									<?php echo esc_html__( 'Automatically apply coupons when cart conditions are met.', WCSC_TEXT_DOMAIN ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Enable URL Coupons', WCSC_TEXT_DOMAIN ); ?></th>
							<td>
								<label>
									<input type="checkbox"
										name="<?php echo esc_attr( WCSC_SETTINGS_KEY ); ?>[enable_url_coupons]"
										value="yes"
										<?php checked( $settings['enable_url_coupons'] ?? 'yes', 'yes' ); ?>>
									<?php echo esc_html__( 'Allow coupon application via URL parameters.', WCSC_TEXT_DOMAIN ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Enable Scheduler', WCSC_TEXT_DOMAIN ); ?></th>
							<td>
								<label>
									<input type="checkbox"
										name="<?php echo esc_attr( WCSC_SETTINGS_KEY ); ?>[enable_scheduler]"
										value="yes"
										<?php checked( $settings['enable_scheduler'] ?? 'yes', 'yes' ); ?>>
									<?php echo esc_html__( 'Enable coupon scheduling with start/end dates.', WCSC_TEXT_DOMAIN ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Enable BOGO', WCSC_TEXT_DOMAIN ); ?></th>
							<td>
								<label>
									<input type="checkbox"
										name="<?php echo esc_attr( WCSC_SETTINGS_KEY ); ?>[enable_bogo]"
										value="yes"
										<?php checked( $settings['enable_bogo'] ?? 'yes', 'yes' ); ?>>
									<?php echo esc_html__( 'Enable Buy One Get One (BOGO) deals.', WCSC_TEXT_DOMAIN ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Enable Restrictions', WCSC_TEXT_DOMAIN ); ?></th>
							<td>
								<label>
									<input type="checkbox"
										name="<?php echo esc_attr( WCSC_SETTINGS_KEY ); ?>[enable_restrictions]"
										value="yes"
										<?php checked( $settings['enable_restrictions'] ?? 'yes', 'yes' ); ?>>
									<?php echo esc_html__( 'Apply product, category, user role, and minimum spend restrictions to coupons.', WCSC_TEXT_DOMAIN ); ?>
								</label>
							</td>
						</tr>
					</tbody>
				</table>
				<?php submit_button(); ?>
			</form>
			<?php
		}

		// ──────────────────────────────────────────
		//  AUTO-APPLY TAB
		// ──────────────────────────────────────────

		/**
		 * Render the Auto-Apply tab.
		 *
		 * @return void
		 */
		private function render_auto_apply_tab() {
			if ( ! current_user_can( WCSC_CAPABILITY ) ) {
				return;
			}

			$settings   = get_option( WCSC_SETTINGS_KEY, array() );
			$conditions = isset( $settings['auto_conditions'] ) ? $settings['auto_conditions'] : array();
			?>
			<form method="post" action="options.php">
				<?php settings_fields( WCSC_OPTION_GROUP ); ?>
				<h2><?php echo esc_html__( 'Auto-Apply Conditions', WCSC_TEXT_DOMAIN ); ?></h2>
				<p><?php echo esc_html__( 'Define conditions that will automatically apply a coupon to the customer\'s cart.', WCSC_TEXT_DOMAIN ); ?></p>

				<div id="wcsc-auto-conditions">
					<?php if ( empty( $conditions ) ) : ?>
						<div class="wcsc-condition-row">
							<select name="<?php echo esc_attr( WCSC_SETTINGS_KEY ); ?>[auto_conditions][0][type]">
								<option value="cart_total"><?php echo esc_html__( 'Cart Total > X', WCSC_TEXT_DOMAIN ); ?></option>
								<option value="product"><?php echo esc_html__( 'Specific Product in Cart', WCSC_TEXT_DOMAIN ); ?></option>
								<option value="category"><?php echo esc_html__( 'Specific Category in Cart', WCSC_TEXT_DOMAIN ); ?></option>
							</select>
							<input type="text" name="<?php echo esc_attr( WCSC_SETTINGS_KEY ); ?>[auto_conditions][0][value]"
								placeholder="<?php echo esc_attr__( 'Value (e.g. 50)', WCSC_TEXT_DOMAIN ); ?>">
							<input type="text" name="<?php echo esc_attr( WCSC_SETTINGS_KEY ); ?>[auto_conditions][0][coupon]"
								placeholder="<?php echo esc_attr__( 'Coupon code', WCSC_TEXT_DOMAIN ); ?>">
							<button type="button" class="button wcsc-remove-condition"><?php echo esc_html__( 'Remove', WCSC_TEXT_DOMAIN ); ?></button>
						</div>
					<?php else : ?>
						<?php foreach ( $conditions as $idx => $cond ) : ?>
							<div class="wcsc-condition-row">
								<select name="<?php echo esc_attr( WCSC_SETTINGS_KEY ); ?>[auto_conditions][<?php echo esc_attr( $idx ); ?>][type]">
									<option value="cart_total" <?php selected( $cond['type'], 'cart_total' ); ?>><?php echo esc_html__( 'Cart Total > X', WCSC_TEXT_DOMAIN ); ?></option>
									<option value="product" <?php selected( $cond['type'], 'product' ); ?>><?php echo esc_html__( 'Specific Product in Cart', WCSC_TEXT_DOMAIN ); ?></option>
									<option value="category" <?php selected( $cond['type'], 'category' ); ?>><?php echo esc_html__( 'Specific Category in Cart', WCSC_TEXT_DOMAIN ); ?></option>
								</select>
								<input type="text" name="<?php echo esc_attr( WCSC_SETTINGS_KEY ); ?>[auto_conditions][<?php echo esc_attr( $idx ); ?>][value]"
									value="<?php echo esc_attr( $cond['value'] ?? '' ); ?>"
									placeholder="<?php echo esc_attr__( 'Value (e.g. 50)', WCSC_TEXT_DOMAIN ); ?>">
								<input type="text" name="<?php echo esc_attr( WCSC_SETTINGS_KEY ); ?>[auto_conditions][<?php echo esc_attr( $idx ); ?>][coupon]"
									value="<?php echo esc_attr( $cond['coupon'] ?? '' ); ?>"
									placeholder="<?php echo esc_attr__( 'Coupon code', WCSC_TEXT_DOMAIN ); ?>">
								<button type="button" class="button wcsc-remove-condition"><?php echo esc_html__( 'Remove', WCSC_TEXT_DOMAIN ); ?></button>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>

				<p>
					<button type="button" class="button" id="wcsc-add-condition">
						<?php echo esc_html__( '+ Add Condition', WCSC_TEXT_DOMAIN ); ?>
					</button>
				</p>

				<p class="description">
					<?php echo esc_html__( 'For "Cart Total > X", enter a numeric value. For "Specific Product", enter the product ID. For "Specific Category", enter the category slug.', WCSC_TEXT_DOMAIN ); ?>
				</p>

				<?php submit_button( __( 'Save Auto-Apply Rules', WCSC_TEXT_DOMAIN ) ); ?>
			</form>
			<?php
		}

		// ──────────────────────────────────────────
		//  BOGO TAB
		// ──────────────────────────────────────────

		/**
		 * Render the BOGO tab.
		 *
		 * @return void
		 */
		private function render_bogo_tab() {
			if ( ! current_user_can( WCSC_CAPABILITY ) ) {
				return;
			}

			$bogo_rules = get_option( WCSC_BOGO_KEY, array() );
			?>
			<h2><?php echo esc_html__( 'BOGO Deals', WCSC_TEXT_DOMAIN ); ?></h2>
			<p><?php echo esc_html__( 'Create Buy X Get Y Free deals. These automatically generate WooCommerce coupons.', WCSC_TEXT_DOMAIN ); ?></p>

			<table class="wp-list-table widefat fixed striped" id="wcsc-bogo-table">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Name', WCSC_TEXT_DOMAIN ); ?></th>
						<th><?php echo esc_html__( 'Buy Quantity', WCSC_TEXT_DOMAIN ); ?></th>
						<th><?php echo esc_html__( 'Get Free', WCSC_TEXT_DOMAIN ); ?></th>
						<th><?php echo esc_html__( 'Target Product', WCSC_TEXT_DOMAIN ); ?></th>
						<th><?php echo esc_html__( 'Generated Coupon', WCSC_TEXT_DOMAIN ); ?></th>
						<th><?php echo esc_html__( 'Status', WCSC_TEXT_DOMAIN ); ?></th>
						<th><?php echo esc_html__( 'Actions', WCSC_TEXT_DOMAIN ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $bogo_rules ) ) : ?>
						<tr class="wcsc-no-bogo">
							<td colspan="7"><?php echo esc_html__( 'No BOGO deals configured yet.', WCSC_TEXT_DOMAIN ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $bogo_rules as $bogo_id => $rule ) : ?>
							<tr>
								<td><?php echo esc_html( $rule['name'] ?? '' ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $rule['buy_qty'] ?? 1 ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $rule['free_qty'] ?? 1 ) ); ?></td>
								<td><?php echo esc_html( $rule['product_id'] ? get_the_title( $rule['product_id'] ) . ' (#' . $rule['product_id'] . ')' : 'Any' ); ?></td>
								<td>
									<?php if ( ! empty( $rule['coupon_code'] ) ) : ?>
										<a href="<?php echo esc_url( admin_url( 'post.php?post=' . absint( $rule['coupon_id'] ) . '&action=edit' ) ); ?>">
											<?php echo esc_html( $rule['coupon_code'] ); ?>
										</a>
									<?php else : ?>
										<?php echo esc_html__( '—', WCSC_TEXT_DOMAIN ); ?>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( ! empty( $rule['enabled'] ) && 'yes' === $rule['enabled'] ) : ?>
										<span class="wcsc-status-active"><?php echo esc_html__( 'Active', WCSC_TEXT_DOMAIN ); ?></span>
									<?php else : ?>
										<span class="wcsc-status-inactive"><?php echo esc_html__( 'Inactive', WCSC_TEXT_DOMAIN ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<button class="button wcsc-edit-bogo" data-id="<?php echo esc_attr( $bogo_id ); ?>">
										<?php echo esc_html__( 'Edit', WCSC_TEXT_DOMAIN ); ?>
									</button>
									<button class="button wcsc-delete-bogo" data-id="<?php echo esc_attr( $bogo_id ); ?>">
										<?php echo esc_html__( 'Delete', WCSC_TEXT_DOMAIN ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<h3><?php echo esc_html__( 'Add New BOGO Deal', WCSC_TEXT_DOMAIN ); ?></h3>
			<form id="wcsc-bogo-form" class="wcsc-form" method="post">
				<?php wp_nonce_field( WCSC_NONCE_ACTION, 'wcsc_bogo_nonce' ); ?>
				<table class="form-table">
					<tbody>
						<tr>
							<th scope="row"><label for="wcsc_bogo_name"><?php echo esc_html__( 'Deal Name', WCSC_TEXT_DOMAIN ); ?></label></th>
							<td>
								<input type="text" id="wcsc_bogo_name" name="bogo_name" class="regular-text" required>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wcsc_bogo_buy_qty"><?php echo esc_html__( 'Buy Quantity', WCSC_TEXT_DOMAIN ); ?></label></th>
							<td>
								<input type="number" id="wcsc_bogo_buy_qty" name="buy_qty" class="small-text" min="1" value="2" required>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wcsc_bogo_free_qty"><?php echo esc_html__( 'Free Quantity', WCSC_TEXT_DOMAIN ); ?></label></th>
							<td>
								<input type="number" id="wcsc_bogo_free_qty" name="free_qty" class="small-text" min="1" value="1" required>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wcsc_bogo_product"><?php echo esc_html__( 'Target Product (optional)', WCSC_TEXT_DOMAIN ); ?></label></th>
							<td>
								<select id="wcsc_bogo_product" name="product_id" class="wcsc-product-select">
									<option value="0"><?php echo esc_html__( 'Any product', WCSC_TEXT_DOMAIN ); ?></option>
									<?php
									$products = wc_get_products( array( 'limit' => 200 ) );
									foreach ( $products as $product ) {
										echo '<option value="' . esc_attr( $product->get_id() ) . '">'
											. esc_html( $product->get_name() . ' (#' . $product->get_id() . ')' )
											. '</option>';
									}
									?>
								</select>
								<p class="description"><?php echo esc_html__( 'Leave as "Any product" to apply to all products.', WCSC_TEXT_DOMAIN ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="wcsc_bogo_status"><?php echo esc_html__( 'Status', WCSC_TEXT_DOMAIN ); ?></label>
							</th>
							<td>
								<select id="wcsc_bogo_status" name="enabled">
									<option value="yes"><?php echo esc_html__( 'Active', WCSC_TEXT_DOMAIN ); ?></option>
									<option value="no"><?php echo esc_html__( 'Inactive', WCSC_TEXT_DOMAIN ); ?></option>
								</select>
							</td>
						</tr>
					</tbody>
				</table>
				<?php submit_button( __( 'Create BOGO Deal', WCSC_TEXT_DOMAIN ), 'primary', 'wcsc_bogo_submit' ); ?>
			</form>
			<?php
		}

		// ──────────────────────────────────────────
		//  URL COUPONS TAB
		// ──────────────────────────────────────────

		/**
		 * Render the URL Coupons tab.
		 *
		 * @return void
		 */
		private function render_url_coupons_tab() {
			if ( ! current_user_can( WCSC_CAPABILITY ) ) {
				return;
			}

			$url_coupons = get_option( WCSC_URL_COUPONS_KEY, array() );
			?>
			<h2><?php echo esc_html__( 'URL-Triggered Coupons', WCSC_TEXT_DOMAIN ); ?></h2>
			<p>
				<?php echo esc_html__( 'Create special URLs that automatically apply a coupon code when visited. Example:', WCSC_TEXT_DOMAIN ); ?>
				<code><?php echo esc_url( home_url( '/?wcsc_coupon=summer20' ) ); ?></code>
			</p>

			<table class="wp-list-table widefat fixed striped" id="wcsc-url-coupons-table">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Coupon Code', WCSC_TEXT_DOMAIN ); ?></th>
						<th><?php echo esc_html__( 'URL Slug', WCSC_TEXT_DOMAIN ); ?></th>
						<th><?php echo esc_html__( 'Direct URL', WCSC_TEXT_DOMAIN ); ?></th>
						<th><?php echo esc_html__( 'Description', WCSC_TEXT_DOMAIN ); ?></th>
						<th><?php echo esc_html__( 'Hit Count', WCSC_TEXT_DOMAIN ); ?></th>
						<th><?php echo esc_html__( 'Actions', WCSC_TEXT_DOMAIN ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $url_coupons ) ) : ?>
						<tr class="wcsc-no-url-coupons">
							<td colspan="6"><?php echo esc_html__( 'No URL coupons configured yet.', WCSC_TEXT_DOMAIN ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $url_coupons as $uc_id => $uc ) : ?>
							<tr>
								<td><?php echo esc_html( $uc['coupon_code'] ?? '' ); ?></td>
								<td><code><?php echo esc_html( $uc['slug'] ?? '' ); ?></code></td>
								<td>
									<a href="<?php echo esc_url( home_url( '/?wcsc_coupon=' . rawurlencode( $uc['slug'] ) ) ); ?>" target="_blank">
										<?php echo esc_url( home_url( '/?wcsc_coupon=' . rawurlencode( $uc['slug'] ) ) ); ?>
									</a>
								</td>
								<td><?php echo esc_html( $uc['description'] ?? '' ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $uc['hits'] ?? 0 ) ); ?></td>
								<td>
									<button class="button wcsc-delete-url-coupon" data-id="<?php echo esc_attr( $uc_id ); ?>">
										<?php echo esc_html__( 'Delete', WCSC_TEXT_DOMAIN ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<h3><?php echo esc_html__( 'Add New URL Coupon', WCSC_TEXT_DOMAIN ); ?></h3>
			<form id="wcsc-url-coupon-form" class="wcsc-form" method="post">
				<?php wp_nonce_field( WCSC_NONCE_ACTION, 'wcsc_url_coupon_nonce' ); ?>
				<table class="form-table">
					<tbody>
						<tr>
							<th scope="row"><label for="wcsc_uc_coupon"><?php echo esc_html__( 'Coupon Code', WCSC_TEXT_DOMAIN ); ?></label></th>
							<td>
								<select id="wcsc_uc_coupon" name="coupon_code" class="regular-text wcsc-coupon-select" required>
									<option value=""><?php echo esc_html__( 'Select a coupon...', WCSC_TEXT_DOMAIN ); ?></option>
									<?php
									$coupons = $this->get_all_coupon_codes();
									foreach ( $coupons as $code ) {
										echo '<option value="' . esc_attr( $code ) . '">' . esc_html( $code ) . '</option>';
									}
									?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wcsc_uc_slug"><?php echo esc_html__( 'URL Slug', WCSC_TEXT_DOMAIN ); ?></label></th>
							<td>
								<input type="text" id="wcsc_uc_slug" name="slug" class="regular-text"
									placeholder="<?php echo esc_attr__( 'e.g. summer20', WCSC_TEXT_DOMAIN ); ?>" required>
								<p class="description">
									<?php echo esc_html__( 'The slug used in the URL: ' . home_url( '/?wcsc_coupon=' ) . '&lt;slug&gt;', WCSC_TEXT_DOMAIN ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wcsc_uc_desc"><?php echo esc_html__( 'Description', WCSC_TEXT_DOMAIN ); ?></label></th>
							<td>
								<textarea id="wcsc_uc_desc" name="description" rows="2" class="large-text"></textarea>
							</td>
						</tr>
					</tbody>
				</table>
				<?php submit_button( __( 'Add URL Coupon', WCSC_TEXT_DOMAIN ), 'primary', 'wcsc_url_coupon_submit' ); ?>
			</form>
			<?php
		}

		// ──────────────────────────────────────────
		//  SCHEDULER TAB
		// ──────────────────────────────────────────

		/**
		 * Render the Scheduler tab.
		 *
		 * @return void
		 */
		private function render_scheduler_tab() {
			if ( ! current_user_can( WCSC_CAPABILITY ) ) {
				return;
			}

			$schedules = get_option( WCSC_SCHEDULER_KEY, array() );
			$now       = current_time( 'mysql' );
			?>
			<h2><?php echo esc_html__( 'Coupon Scheduler', WCSC_TEXT_DOMAIN ); ?></h2>
			<p><?php echo esc_html__( 'Schedule when coupons become active (start date) and when they expire (end date).', WCSC_TEXT_DOMAIN ); ?></p>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Coupon Code', WCSC_TEXT_DOMAIN ); ?></th>
						<th><?php echo esc_html__( 'Start Date', WCSC_TEXT_DOMAIN ); ?></th>
						<th><?php echo esc_html__( 'End Date', WCSC_TEXT_DOMAIN ); ?></th>
						<th><?php echo esc_html__( 'Status', WCSC_TEXT_DOMAIN ); ?></th>
						<th><?php echo esc_html__( 'Actions', WCSC_TEXT_DOMAIN ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $schedules ) ) : ?>
						<tr class="wcsc-no-schedules">
							<td colspan="5"><?php echo esc_html__( 'No schedules configured yet.', WCSC_TEXT_DOMAIN ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $schedules as $sched_id => $sched ) : ?>
							<?php
							$start  = $sched['start_date'] ?? '';
							$end    = $sched['end_date'] ?? '';
							$active = ( $start <= $now && ( empty( $end ) || $end >= $now ) );
							?>
							<tr>
								<td><?php echo esc_html( $sched['coupon_code'] ?? '' ); ?></td>
								<td><?php echo esc_html( $start ? $start : '—' ); ?></td>
								<td><?php echo esc_html( $end ? $end : '—' ); ?></td>
								<td>
									<?php if ( $active ) : ?>
										<span class="wcsc-status-active"><?php echo esc_html__( 'Active', WCSC_TEXT_DOMAIN ); ?></span>
									<?php elseif ( $start > $now ) : ?>
										<span class="wcsc-status-pending"><?php echo esc_html__( 'Pending', WCSC_TEXT_DOMAIN ); ?></span>
									<?php else : ?>
										<span class="wcsc-status-expired"><?php echo esc_html__( 'Expired', WCSC_TEXT_DOMAIN ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<button class="button wcsc-delete-scheduler" data-id="<?php echo esc_attr( $sched_id ); ?>">
										<?php echo esc_html__( 'Delete', WCSC_TEXT_DOMAIN ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<h3><?php echo esc_html__( 'Add Schedule', WCSC_TEXT_DOMAIN ); ?></h3>
			<form id="wcsc-scheduler-form" class="wcsc-form" method="post">
				<?php wp_nonce_field( WCSC_NONCE_ACTION, 'wcsc_scheduler_nonce' ); ?>
				<table class="form-table">
					<tbody>
						<tr>
							<th scope="row"><label for="wcsc_sched_coupon"><?php echo esc_html__( 'Coupon Code', WCSC_TEXT_DOMAIN ); ?></label></th>
							<td>
								<select id="wcsc_sched_coupon" name="coupon_code" class="regular-text wcsc-coupon-select" required>
									<option value=""><?php echo esc_html__( 'Select a coupon...', WCSC_TEXT_DOMAIN ); ?></option>
									<?php
									$coupons = $this->get_all_coupon_codes();
									foreach ( $coupons as $code ) {
										echo '<option value="' . esc_attr( $code ) . '">' . esc_html( $code ) . '</option>';
									}
									?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wcsc_sched_start"><?php echo esc_html__( 'Start Date', WCSC_TEXT_DOMAIN ); ?></label></th>
							<td>
								<input type="datetime-local" id="wcsc_sched_start" name="start_date" class="regular-text" required>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wcsc_sched_end"><?php echo esc_html__( 'End Date (optional)', WCSC_TEXT_DOMAIN ); ?></label></th>
							<td>
								<input type="datetime-local" id="wcsc_sched_end" name="end_date" class="regular-text">
								<p class="description"><?php echo esc_html__( 'Leave empty for no end date.', WCSC_TEXT_DOMAIN ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>
				<?php submit_button( __( 'Add Schedule', WCSC_TEXT_DOMAIN ), 'primary', 'wcsc_scheduler_submit' ); ?>
			</form>
			<?php
		}

		// ──────────────────────────────────────────
		//  AUTO-APPLY LOGIC
		// ──────────────────────────────────────────

		/**
		 * Auto-apply coupons when cart conditions are met.
		 *
		 * @return void
		 */
		public function auto_apply_coupons() {
			$settings = get_option( WCSC_SETTINGS_KEY, array() );
			if ( empty( $settings['enable_auto_apply'] ) || 'yes' !== $settings['enable_auto_apply'] ) {
				return;
			}

			if ( ! WC()->cart ) {
				return;
			}

			$conditions = isset( $settings['auto_conditions'] ) ? $settings['auto_conditions'] : array();
			if ( empty( $conditions ) ) {
				return;
			}

			foreach ( $conditions as $cond ) {
				if ( empty( $cond['type'] ) || empty( $cond['coupon'] ) ) {
					continue;
				}

				$coupon_code = sanitize_text_field( $cond['coupon'] );
				$apply       = false;

				switch ( $cond['type'] ) {
					case 'cart_total':
						$threshold = floatval( $cond['value'] );
						if ( WC()->cart->get_subtotal() > $threshold ) {
							$apply = true;
						}
						break;

					case 'product':
						$product_id = absint( $cond['value'] );
						if ( $this->cart_has_product( $product_id ) ) {
							$apply = true;
						}
						break;

					case 'category':
						$category_slug = sanitize_text_field( $cond['value'] );
						if ( $this->cart_has_category( $category_slug ) ) {
							$apply = true;
						}
						break;
				}

				if ( $apply && ! WC()->cart->has_discount( $coupon_code ) ) {
					WC()->cart->apply_coupon( $coupon_code );
				}
			}
		}

		/**
		 * Check if cart has a specific product.
		 *
		 * @param  int $product_id Product ID.
		 * @return bool
		 */
		private function cart_has_product( $product_id ) {
			if ( ! WC()->cart ) {
				return false;
			}
			foreach ( WC()->cart->get_cart() as $item ) {
				$pid = $item['product_id'];
				$vid = $item['variation_id'] ?? 0;
				if ( absint( $pid ) === absint( $product_id ) || absint( $vid ) === absint( $product_id ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * Check if cart has a product from a specific category.
		 *
		 * @param  string $category_slug Category slug.
		 * @return bool
		 */
		private function cart_has_category( $category_slug ) {
			if ( ! WC()->cart ) {
				return false;
			}
			foreach ( WC()->cart->get_cart() as $item ) {
				$product_id = $item['product_id'];
				if ( has_term( $category_slug, 'product_cat', $product_id ) ) {
					return true;
				}
			}
			return false;
		}

		// ──────────────────────────────────────────
		//  BOGO LOGIC
		// ──────────────────────────────────────────

		/**
		 * Handle BOGO rules in cart — ensure WC coupon exists.
		 *
		 * @return void
		 */
		public function handle_bogo_in_cart() {
			$settings = get_option( WCSC_SETTINGS_KEY, array() );
			if ( empty( $settings['enable_bogo'] ) || 'yes' !== $settings['enable_bogo'] ) {
				return;
			}

			$bogo_rules = get_option( WCSC_BOGO_KEY, array() );
			if ( empty( $bogo_rules ) ) {
				return;
			}

			foreach ( $bogo_rules as $bogo_id => $rule ) {
				if ( empty( $rule['enabled'] ) || 'yes' !== $rule['enabled'] ) {
					continue;
				}

				// Ensure the coupon exists in WooCommerce.
				if ( empty( $rule['coupon_code'] ) || ! $this->coupon_code_exists( $rule['coupon_code'] ) ) {
					$coupon_code = $this->create_bogo_coupon( $rule, $bogo_id );
					$rule['coupon_code'] = $coupon_code;
					$rule['coupon_id']   = $this->get_coupon_id_by_code( $coupon_code );
					$bogo_rules[ $bogo_id ] = $rule;
					update_option( WCSC_BOGO_KEY, $bogo_rules );
				}
			}
		}

		/**
		 * Apply BOGO discounts via coupon.
		 *
		 * @return void
		 */
		public function apply_bogo_discounts() {
			// BOGO is handled via the auto-created WooCommerce coupons.
			// This hook is reserved for any future calculation overrides.
		}

		/**
		 * Create a WooCommerce coupon from a BOGO rule.
		 *
		 * @param  array  $rule    BOGO rule data.
		 * @param  string $bogo_id Rule ID.
		 * @return string Coupon code.
		 */
		private function create_bogo_coupon( $rule, $bogo_id ) {
			$code = 'BOGO-' . strtoupper( sanitize_title( $rule['name'] ) ) . '-' . substr( md5( $bogo_id ), 0, 6 );

			$coupon = array(
				'post_title'   => $code,
				'post_content' => '',
				'post_status'  => 'publish',
				'post_author'  => get_current_user_id(),
				'post_type'    => 'shop_coupon',
			);

			$coupon_id = wp_insert_post( $coupon );
			if ( is_wp_error( $coupon_id ) ) {
				return '';
			}

			$discount_type = 'percent';
			$coupon_amount = 100; // 100% off the free items.

			update_post_meta( $coupon_id, 'discount_type', $discount_type );
			update_post_meta( $coupon_id, 'coupon_amount', $coupon_amount );
			update_post_meta( $coupon_id, 'individual_use', 'no' );
			update_post_meta( $coupon_id, 'product_ids', array() );
			update_post_meta( $coupon_id, 'exclude_product_ids', array() );
			update_post_meta( $coupon_id, 'usage_limit', '' );
			update_post_meta( $coupon_id, 'usage_limit_per_user', '' );
			update_post_meta( $coupon_id, 'limit_usage_to_x_items', absint( $rule['free_qty'] ) );
			update_post_meta( $coupon_id, 'free_shipping', 'no' );
			update_post_meta( $coupon_id, 'product_categories', array() );
			update_post_meta( $coupon_id, 'exclude_product_categories', array() );
			update_post_meta( $coupon_id, 'exclude_sale_items', 'no' );
			update_post_meta( $coupon_id, 'minimum_amount', '' );
			update_post_meta( $coupon_id, 'maximum_amount', '' );
			update_post_meta( $coupon_id, 'customer_email', array() );
			update_post_meta( $coupon_id, 'usage_count', 0 );
			update_post_meta( $coupon_id, 'wcsc_bogo_id', $bogo_id );

			return $code;
		}

		// ──────────────────────────────────────────
		//  COUPON RESTRICTIONS
		// ──────────────────────────────────────────

		/**
		 * Apply coupon restrictions from Smart Coupons meta.
		 *
		 * @param  bool        $valid  Is coupon valid.
		 * @param  WC_Coupon   $coupon Coupon object.
		 * @param  WC_Discount $discount Discount object.
		 * @return bool
		 */
		public function apply_coupon_restrictions( $valid, $coupon, $discount ) {
			$settings = get_option( WCSC_SETTINGS_KEY, array() );
			if ( empty( $settings['enable_restrictions'] ) || 'yes' !== $settings['enable_restrictions'] ) {
				return $valid;
			}

			if ( ! $valid ) {
				return $valid;
			}

			$coupon_id = $coupon->get_id();

			// Minimum spend check.
			$min_spend = get_post_meta( $coupon_id, '_wcsc_min_spend', true );
			if ( ! empty( $min_spend ) ) {
				$min_spend = floatval( $min_spend );
				if ( WC()->cart && WC()->cart->get_subtotal() < $min_spend ) {
					return false;
				}
			}

			// User role restriction.
			$allowed_roles = get_post_meta( $coupon_id, '_wcsc_allowed_roles', true );
			if ( ! empty( $allowed_roles ) && is_array( $allowed_roles ) ) {
				$user = wp_get_current_user();
				$has_role = false;
				foreach ( (array) $user->roles as $role ) {
					if ( in_array( $role, $allowed_roles, true ) ) {
						$has_role = true;
						break;
					}
				}
				if ( ! $has_role ) {
					return false;
				}
			}

			// Scheduler check.
			$schedules = get_option( WCSC_SCHEDULER_KEY, array() );
			$code      = $coupon->get_code();
			$now       = current_time( 'mysql' );

			foreach ( $schedules as $sched ) {
				if ( $sched['coupon_code'] !== $code ) {
					continue;
				}

				$start = $sched['start_date'] ?? '';
				$end   = $sched['end_date'] ?? '';

				if ( ! empty( $start ) && $start > $now ) {
					return false; // Not yet active.
				}
				if ( ! empty( $end ) && $end < $now ) {
					return false; // Expired.
				}
			}

			return $valid;
		}

		/**
		 * Filter product-level validity for coupons with Smart Coupons restrictions.
		 *
		 * @param  bool       $valid  Is valid for product.
		 * @param  WC_Product $product Product object.
		 * @param  WC_Coupon  $coupon Coupon object.
		 * @param  array      $values Cart item values.
		 * @return bool
		 */
		public function coupon_valid_for_product( $valid, $product, $coupon, $values ) {
			$settings = get_option( WCSC_SETTINGS_KEY, array() );
			if ( empty( $settings['enable_restrictions'] ) || 'yes' !== $settings['enable_restrictions'] ) {
				return $valid;
			}

			if ( ! $valid ) {
				return $valid;
			}

			$coupon_id = $coupon->get_id();

			// Product restriction.
			$allowed_products = get_post_meta( $coupon_id, '_wcsc_allowed_products', true );
			if ( ! empty( $allowed_products ) && is_array( $allowed_products ) ) {
				if ( ! in_array( $product->get_id(), $allowed_products, true ) &&
					! in_array( $product->get_parent_id(), $allowed_products, true ) ) {
					return false;
				}
			}

			// Category restriction.
			$allowed_categories = get_post_meta( $coupon_id, '_wcsc_allowed_categories', true );
			if ( ! empty( $allowed_categories ) && is_array( $allowed_categories ) ) {
				$product_cats = wc_get_product_term_ids( $product->get_id(), 'product_cat' );
				$intersect    = array_intersect( $allowed_categories, $product_cats );
				if ( empty( $intersect ) ) {
					return false;
				}
			}

			return $valid;
		}

		// ──────────────────────────────────────────
		//  URL-TRIGGERED COUPONS
		// ──────────────────────────────────────────

		/**
		 * Handle URL-triggered coupon application.
		 *
		 * @return void
		 */
		public function handle_url_coupon() {
			$settings = get_option( WCSC_SETTINGS_KEY, array() );
			if ( empty( $settings['enable_url_coupons'] ) || 'yes' !== $settings['enable_url_coupons'] ) {
				return;
			}

			// Non-Admin: we can't use wp_verify_nonce here since this runs on template_redirect
			// on the front-end without a form POST. Instead, we validate the slug against stored data.
			if ( ! isset( $_GET['wcsc_coupon'] ) ) { // WPCS: Input var okay.
				return;
			}

			$slug = sanitize_key( $_GET['wcsc_coupon'] ); // WPCS: Input var okay.
			if ( empty( $slug ) ) {
				return;
			}

			$url_coupons = get_option( WCSC_URL_COUPONS_KEY, array() );
			$found_code  = '';
			$found_key   = null;

			foreach ( $url_coupons as $key => $uc ) {
				if ( $uc['slug'] === $slug ) {
					$found_code = $uc['coupon_code'];
					$found_key  = $key;
					break;
				}
			}

			if ( empty( $found_code ) ) {
				return;
			}

			// Redirect to cart/checkout with coupon applied.
			if ( WC()->cart && ! WC()->cart->has_discount( $found_code ) ) {
				WC()->cart->apply_coupon( sanitize_text_field( $found_code ) );

				// Increment hit counter.
				$url_coupons[ $found_key ]['hits'] = ( $url_coupons[ $found_key ]['hits'] ?? 0 ) + 1;
				update_option( WCSC_URL_COUPONS_KEY, $url_coupons );

				// Add notice.
				wc_add_notice(
					sprintf(
						/* translators: %s: coupon code */
						__( 'Coupon "%s" has been applied to your cart!', WCSC_TEXT_DOMAIN ),
						esc_html( $found_code )
					)
				);
			}

			// Clean URL: redirect to cart without the query parameter.
			$clean_url = remove_query_arg( 'wcsc_coupon' );
			if ( $clean_url !== home_url( add_query_arg( null, null ) ) ) {
				wp_safe_redirect( esc_url( wc_get_cart_url() ) );
				exit;
			}
		}

		// ──────────────────────────────────────────
		//  SCHEDULER CRON
		// ──────────────────────────────────────────

		/**
		 * Daily cron to enforce coupon schedules.
		 *
		 * @return void
		 */
		public function daily_scheduler_check() {
			$schedules = get_option( WCSC_SCHEDULER_KEY, array() );
			if ( empty( $schedules ) ) {
				return;
			}

			$now = current_time( 'mysql' );

			foreach ( $schedules as $sched ) {
				$code  = $sched['coupon_code'] ?? '';
				$start = $sched['start_date'] ?? '';
				$end   = $sched['end_date'] ?? '';

				if ( empty( $code ) ) {
					continue;
				}

				$coupon_id = $this->get_coupon_id_by_code( $code );
				if ( ! $coupon_id ) {
					continue;
				}

				$is_active_start = empty( $start ) || $start <= $now;
				$is_active_end   = empty( $end ) || $end >= $now;

				if ( $is_active_start && $is_active_end ) {
					wp_update_post(
						array(
							'ID'          => $coupon_id,
							'post_status' => 'publish',
						)
					);
				} else {
					wp_update_post(
						array(
							'ID'          => $coupon_id,
							'post_status' => 'draft',
						)
					);
				}
			}
		}

		// ──────────────────────────────────────────
		//  AJAX HANDLERS
		// ──────────────────────────────────────────

		/**
		 * AJAX: Save BOGO deal.
		 *
		 * @return void
		 */
		public function ajax_bogo_save() {
			check_ajax_referer( WCSC_NONCE_AJAX, 'nonce' );

			if ( ! current_user_can( WCSC_CAPABILITY ) ) {
				wp_send_json_error( array( 'message' => __( 'Unauthorized.', WCSC_TEXT_DOMAIN ) ) );
			}

			$name      = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
			$buy_qty   = isset( $_POST['buy_qty'] ) ? absint( $_POST['buy_qty'] ) : 2;
			$free_qty  = isset( $_POST['free_qty'] ) ? absint( $_POST['free_qty'] ) : 1;
			$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
			$enabled   = isset( $_POST['enabled'] ) && 'yes' === sanitize_key( $_POST['enabled'] ) ? 'yes' : 'no';
			$edit_id   = isset( $_POST['edit_id'] ) ? sanitize_key( $_POST['edit_id'] ) : '';

			if ( empty( $name ) ) {
				wp_send_json_error( array( 'message' => __( 'Deal name is required.', WCSC_TEXT_DOMAIN ) ) );
			}

			$bogo_rules = get_option( WCSC_BOGO_KEY, array() );
			$bogo_id    = ! empty( $edit_id ) ? $edit_id : uniqid( 'bogo_' );

			$rule = array(
				'name'       => $name,
				'buy_qty'    => $buy_qty,
				'free_qty'   => $free_qty,
				'product_id' => $product_id,
				'enabled'    => $enabled,
			);

			if ( ! empty( $bogo_rules[ $bogo_id ]['coupon_code'] ) ) {
				$rule['coupon_code'] = $bogo_rules[ $bogo_id ]['coupon_code'];
				$rule['coupon_id']   = $bogo_rules[ $bogo_id ]['coupon_id'];
			}

			$bogo_rules[ $bogo_id ] = $rule;
			update_option( WCSC_BOGO_KEY, $bogo_rules );

			wp_send_json_success(
				array(
					'message' => __( 'BOGO deal saved.', WCSC_TEXT_DOMAIN ),
					'id'      => $bogo_id,
				)
			);
		}

		/**
		 * AJAX: Delete BOGO deal.
		 *
		 * @return void
		 */
		public function ajax_bogo_delete() {
			check_ajax_referer( WCSC_NONCE_AJAX, 'nonce' );

			if ( ! current_user_can( WCSC_CAPABILITY ) ) {
				wp_send_json_error( array( 'message' => __( 'Unauthorized.', WCSC_TEXT_DOMAIN ) ) );
			}

			$bogo_id = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
			if ( empty( $bogo_id ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid ID.', WCSC_TEXT_DOMAIN ) ) );
			}

			$bogo_rules = get_option( WCSC_BOGO_KEY, array() );
			if ( isset( $bogo_rules[ $bogo_id ] ) ) {
				// Trash the associated coupon.
				if ( ! empty( $bogo_rules[ $bogo_id ]['coupon_id'] ) ) {
					wp_trash_post( absint( $bogo_rules[ $bogo_id ]['coupon_id'] ) );
				}
				unset( $bogo_rules[ $bogo_id ] );
				update_option( WCSC_BOGO_KEY, $bogo_rules );
			}

			wp_send_json_success( array( 'message' => __( 'BOGO deal deleted.', WCSC_TEXT_DOMAIN ) ) );
		}

		/**
		 * AJAX: Save URL coupon.
		 *
		 * @return void
		 */
		public function ajax_url_coupon_save() {
			check_ajax_referer( WCSC_NONCE_AJAX, 'nonce' );

			if ( ! current_user_can( WCSC_CAPABILITY ) ) {
				wp_send_json_error( array( 'message' => __( 'Unauthorized.', WCSC_TEXT_DOMAIN ) ) );
			}

			$coupon_code = isset( $_POST['coupon_code'] ) ? sanitize_text_field( wp_unslash( $_POST['coupon_code'] ) ) : '';
			$slug        = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';
			$description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';

			if ( empty( $coupon_code ) || empty( $slug ) ) {
				wp_send_json_error( array( 'message' => __( 'Coupon code and slug are required.', WCSC_TEXT_DOMAIN ) ) );
			}

			$url_coupons = get_option( WCSC_URL_COUPONS_KEY, array() );

			// Check for duplicate slug.
			foreach ( $url_coupons as $uc ) {
				if ( $uc['slug'] === $slug ) {
					wp_send_json_error( array( 'message' => __( 'This slug is already in use.', WCSC_TEXT_DOMAIN ) ) );
				}
			}

			$url_coupons[ uniqid( 'uc_' ) ] = array(
				'coupon_code' => $coupon_code,
				'slug'        => $slug,
				'description' => $description,
				'hits'        => 0,
			);

			update_option( WCSC_URL_COUPONS_KEY, $url_coupons );

			wp_send_json_success( array( 'message' => __( 'URL coupon added.', WCSC_TEXT_DOMAIN ) ) );
		}

		/**
		 * AJAX: Delete URL coupon.
		 *
		 * @return void
		 */
		public function ajax_url_coupon_delete() {
			check_ajax_referer( WCSC_NONCE_AJAX, 'nonce' );

			if ( ! current_user_can( WCSC_CAPABILITY ) ) {
				wp_send_json_error( array( 'message' => __( 'Unauthorized.', WCSC_TEXT_DOMAIN ) ) );
			}

			$uc_id = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
			if ( empty( $uc_id ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid ID.', WCSC_TEXT_DOMAIN ) ) );
			}

			$url_coupons = get_option( WCSC_URL_COUPONS_KEY, array() );
			if ( isset( $url_coupons[ $uc_id ] ) ) {
				unset( $url_coupons[ $uc_id ] );
				update_option( WCSC_URL_COUPONS_KEY, $url_coupons );
			}

			wp_send_json_success( array( 'message' => __( 'URL coupon deleted.', WCSC_TEXT_DOMAIN ) ) );
		}

		/**
		 * AJAX: Save scheduler entry.
		 *
		 * @return void
		 */
		public function ajax_scheduler_save() {
			check_ajax_referer( WCSC_NONCE_AJAX, 'nonce' );

			if ( ! current_user_can( WCSC_CAPABILITY ) ) {
				wp_send_json_error( array( 'message' => __( 'Unauthorized.', WCSC_TEXT_DOMAIN ) ) );
			}

			$coupon_code = isset( $_POST['coupon_code'] ) ? sanitize_text_field( wp_unslash( $_POST['coupon_code'] ) ) : '';
			$start_date  = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '';
			$end_date    = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '';

			if ( empty( $coupon_code ) || empty( $start_date ) ) {
				wp_send_json_error( array( 'message' => __( 'Coupon code and start date are required.', WCSC_TEXT_DOMAIN ) ) );
			}

			$schedules = get_option( WCSC_SCHEDULER_KEY, array() );

			$schedules[ uniqid( 'sched_' ) ] = array(
				'coupon_code' => $coupon_code,
				'start_date'  => $start_date,
				'end_date'    => $end_date,
			);

			update_option( WCSC_SCHEDULER_KEY, $schedules );

			wp_send_json_success( array( 'message' => __( 'Schedule added.', WCSC_TEXT_DOMAIN ) ) );
		}

		/**
		 * AJAX: Delete scheduler entry.
		 *
		 * @return void
		 */
		public function ajax_scheduler_delete() {
			check_ajax_referer( WCSC_NONCE_AJAX, 'nonce' );

			if ( ! current_user_can( WCSC_CAPABILITY ) ) {
				wp_send_json_error( array( 'message' => __( 'Unauthorized.', WCSC_TEXT_DOMAIN ) ) );
			}

			$sched_id = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
			if ( empty( $sched_id ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid ID.', WCSC_TEXT_DOMAIN ) ) );
			}

			$schedules = get_option( WCSC_SCHEDULER_KEY, array() );
			if ( isset( $schedules[ $sched_id ] ) ) {
				unset( $schedules[ $sched_id ] );
				update_option( WCSC_SCHEDULER_KEY, $schedules );
			}

			wp_send_json_success( array( 'message' => __( 'Schedule deleted.', WCSC_TEXT_DOMAIN ) ) );
		}

		/**
		 * AJAX: Dashboard stats refresh.
		 *
		 * @return void
		 */
		public function ajax_dashboard_stats() {
			check_ajax_referer( WCSC_NONCE_AJAX, 'nonce' );

			if ( ! current_user_can( WCSC_CAPABILITY ) ) {
				wp_send_json_error( array( 'message' => __( 'Unauthorized.', WCSC_TEXT_DOMAIN ) ) );
			}

			$stats = $this->get_coupon_usage_stats();

			wp_send_json_success( $stats );
		}

		// ──────────────────────────────────────────
		//  HELPERS
		// ──────────────────────────────────────────

		/**
		 * Get count of active (published) WooCommerce coupons.
		 *
		 * @return int
		 */
		private function get_active_coupons_count() {
			$count_posts = wp_count_posts( 'shop_coupon' );
			return isset( $count_posts->publish ) ? (int) $count_posts->publish : 0;
		}

		/**
		 * Get aggregated coupon usage stats.
		 *
		 * @return array
		 */
		private function get_coupon_usage_stats() {
			$coupons = $this->get_top_coupons( 9999 );
			$total_used   = 0;
			$total_savings = 0.0;

			foreach ( $coupons as $c ) {
				$total_used   += $c['usage_count'];
				$total_savings += floatval( $c['total_discount'] );
			}

			return array(
				'total_used'               => $total_used,
				'total_savings'            => $total_savings,
				'total_savings_formatted'  => wc_price( $total_savings ),
			);
		}

		/**
		 * Get top coupons by usage count.
		 *
		 * @param  int $limit Max number to return.
		 * @return array
		 */
		private function get_top_coupons( $limit = 10 ) {
			$coupon_posts = get_posts(
				array(
					'post_type'      => 'shop_coupon',
					'post_status'    => 'publish',
					'posts_per_page' => $limit,
					'orderby'        => 'meta_value_num',
					'meta_key'       => 'usage_count',
					'order'          => 'DESC',
				)
			);

			$results = array();
			foreach ( $coupon_posts as $post ) {
				$coupon = new WC_Coupon( $post->ID );
				$results[] = array(
					'id'                      => $post->ID,
					'code'                    => $coupon->get_code(),
					'usage_count'             => $coupon->get_usage_count(),
					'total_discount'          => $coupon->get_total_discount() ?: 0,
					'total_discount_formatted' => wc_price( $coupon->get_total_discount() ?: 0 ),
					'expiry'                  => $coupon->get_date_expires() ? $coupon->get_date_expires()->date_i18n( 'Y-m-d' ) : '',
				);
			}

			return $results;
		}

		/**
		 * Get all coupon codes.
		 *
		 * @return array
		 */
		private function get_all_coupon_codes() {
			$coupons = get_posts(
				array(
					'post_type'      => 'shop_coupon',
					'post_status'    => 'publish',
					'posts_per_page' => -1,
				)
			);

			$codes = array();
			foreach ( $coupons as $c ) {
				$codes[] = $c->post_title;
			}
			return $codes;
		}

		/**
		 * Check if a coupon code exists.
		 *
		 * @param  string $code Coupon code.
		 * @return bool
		 */
		private function coupon_code_exists( $code ) {
			$coupon_id = $this->get_coupon_id_by_code( $code );
			return $coupon_id > 0;
		}

		/**
		 * Get coupon ID by code.
		 *
		 * @param  string $code Coupon code.
		 * @return int
		 */
		private function get_coupon_id_by_code( $code ) {
			global $wpdb;
			$id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'shop_coupon' AND post_title = %s AND post_status = 'publish' LIMIT 1",
					$code
				)
			);
			return $id ? (int) $id : 0;
		}

		/**
		 * Plugin action links.
		 *
		 * @param  array $links Existing links.
		 * @return array
		 */
		public function plugin_action_links( $links ) {
			$settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=wc-smart-coupons' ) ) . '">'
				. esc_html__( 'Settings', WCSC_TEXT_DOMAIN ) . '</a>';
			array_unshift( $links, $settings_link );
			return $links;
		}

		// ──────────────────────────────────────────
		//  ADMIN CSS / JS
		// ──────────────────────────────────────────

		/**
		 * Get admin inline CSS.
		 *
		 * @return string
		 */
		private function get_admin_css() {
			return '
				.wcsc-wrap .nav-tab-wrapper { margin-bottom: 1em; }
				.wcsc-stats-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px; }
				.wcsc-stat-box { background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,0.04); }
				.wcsc-stat-number { display: block; font-size: 28px; font-weight: 700; color: #1d2327; line-height: 1.2; }
				.wcsc-stat-label { display: block; font-size: 13px; color: #787c82; margin-top: 6px; }
				.wcsc-condition-row { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 10px; background: #f6f7f7; padding: 12px; border: 1px solid #dcdcde; border-radius: 4px; }
				.wcsc-condition-row select, .wcsc-condition-row input { flex: 1; min-width: 120px; }
				.wcsc-condition-row .button { flex: 0 0 auto; }
				.wcsc-dashboard-section { margin-top: 24px; }
				.wcsc-dashboard-section h2 { margin-bottom: 12px; }
				.wcsc-status-active { color: #00a32a; font-weight: 600; }
				.wcsc-status-inactive { color: #d63638; font-weight: 600; }
				.wcsc-status-pending { color: #dba617; font-weight: 600; }
				.wcsc-status-expired { color: #787c82; font-weight: 600; }
				.wcsc-form .form-table th { width: 200px; }
				@media (max-width: 782px) {
					.wcsc-stats-grid { grid-template-columns: repeat(2, 1fr); }
					.wcsc-condition-row { flex-direction: column; }
					.wcsc-condition-row select, .wcsc-condition-row input { width: 100%; }
				}
			';
		}

		/**
		 * Get admin inline JS.
		 *
		 * @return string
		 */
		private function get_admin_js() {
			return '
				jQuery(function($){
					var condIndex = ' . ( count( get_option( WCSC_SETTINGS_KEY, array() )['auto_conditions'] ?? array() ) ) . ';

					$("#wcsc-add-condition").on("click", function(){
						var row = $(".wcsc-condition-row:first").clone();
						row.find("select, input").each(function(){
							var name = $(this).attr("name");
							if(name) {
								name = name.replace(/\[\d+\]/, "[" + condIndex + "]");
								$(this).attr("name", name).val("");
							}
						});
						$("#wcsc-auto-conditions").append(row);
						condIndex++;
					});

					$(document).on("click", ".wcsc-remove-condition", function(){
						if($(".wcsc-condition-row").length > 1) {
							$(this).closest(".wcsc-condition-row").remove();
						} else {
							$(this).closest(".wcsc-condition-row").find("input").val("");
						}
					});

					// BOGO AJAX.
					$("#wcsc_bogo_submit").on("click", function(e){
						e.preventDefault();
						var form = $("#wcsc-bogo-form");
						var data = {
							action: "wcsc_bogo_save",
							nonce: wcsc_admin.nonce,
							name: $("#wcsc_bogo_name").val(),
							buy_qty: $("#wcsc_bogo_buy_qty").val(),
							free_qty: $("#wcsc_bogo_free_qty").val(),
							product_id: $("#wcsc_bogo_product").val(),
							enabled: $("#wcsc_bogo_status").val()
						};
						$.post(wcsc_admin.ajax_url, data, function(res){
							if(res.success) {
								location.reload();
							} else {
								alert(res.data.message || "Error saving BOGO deal.");
							}
						});
					});

					$(document).on("click", ".wcsc-delete-bogo", function(){
						if(!confirm("Delete this BOGO deal?")) return;
						var id = $(this).data("id");
						$.post(wcsc_admin.ajax_url, {
							action: "wcsc_bogo_delete",
							nonce: wcsc_admin.nonce,
							id: id
						}, function(res){
							if(res.success) location.reload();
						});
					});

					// URL Coupons AJAX.
					$("#wcsc_url_coupon_submit").on("click", function(e){
						e.preventDefault();
						$.post(wcsc_admin.ajax_url, {
							action: "wcsc_url_coupon_save",
							nonce: wcsc_admin.nonce,
							coupon_code: $("#wcsc_uc_coupon").val(),
							slug: $("#wcsc_uc_slug").val(),
							description: $("#wcsc_uc_desc").val()
						}, function(res){
							if(res.success) location.reload();
							else alert(res.data.message || "Error.");
						});
					});

					$(document).on("click", ".wcsc-delete-url-coupon", function(){
						if(!confirm("Delete this URL coupon?")) return;
						$.post(wcsc_admin.ajax_url, {
							action: "wcsc_url_coupon_delete",
							nonce: wcsc_admin.nonce,
							id: $(this).data("id")
						}, function(res){
							if(res.success) location.reload();
						});
					});

					// Scheduler AJAX.
					$("#wcsc_scheduler_submit").on("click", function(e){
						e.preventDefault();
						$.post(wcsc_admin.ajax_url, {
							action: "wcsc_scheduler_save",
							nonce: wcsc_admin.nonce,
							coupon_code: $("#wcsc_sched_coupon").val(),
							start_date: $("#wcsc_sched_start").val(),
							end_date: $("#wcsc_sched_end").val()
						}, function(res){
							if(res.success) location.reload();
							else alert(res.data.message || "Error.");
						});
					});

					$(document).on("click", ".wcsc-delete-scheduler", function(){
						if(!confirm("Delete this schedule?")) return;
						$.post(wcsc_admin.ajax_url, {
							action: "wcsc_scheduler_delete",
							nonce: wcsc_admin.nonce,
							id: $(this).data("id")
						}, function(res){
							if(res.success) location.reload();
						});
					});
				});
			';
		}
	}

	// ──────────────────────────────────────────
	//  INITIALIZE PLUGIN
	// ──────────────────────────────────────────

	/**
	 * Initialize the plugin.
	 *
	 * @return WooCommerce_Smart_Coupons
	 */
	function wc_smart_coupons_init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					?>
					<div class="notice notice-warning is-dismissible">
						<p><?php echo esc_html__( 'WooCommerce Smart Coupons requires WooCommerce to be installed and activated.', WCSC_TEXT_DOMAIN ); ?></p>
					</div>
					<?php
				}
			);
			return;
		}
		return WooCommerce_Smart_Coupons::get_instance();
	}

	add_action( 'plugins_loaded', 'wc_smart_coupons_init' );
}

// ──────────────────────────────────────────────
//  UNINSTALL HOOK
// ──────────────────────────────────────────────

/**
 * Uninstall cleanup: remove all plugin options and meta.
 *
 * @return void
 */
function wc_smart_coupons_uninstall() {
	if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
		exit;
	}

	delete_option( WCSC_SETTINGS_KEY );
	delete_option( WCSC_BOGO_KEY );
	delete_option( WCSC_URL_COUPONS_KEY );
	delete_option( WCSC_SCHEDULER_KEY );

	// Clean up post meta on coupon posts.
	global $wpdb;
	$wpdb->delete(
		$wpdb->postmeta,
		array(
			'meta_key' => '_wcsc_min_spend',
		)
	);
	$wpdb->delete(
		$wpdb->postmeta,
		array(
			'meta_key' => '_wcsc_allowed_roles',
		)
	);
	$wpdb->delete(
		$wpdb->postmeta,
		array(
			'meta_key' => '_wcsc_allowed_products',
		)
	);
	$wpdb->delete(
		$wpdb->postmeta,
		array(
			'meta_key' => '_wcsc_allowed_categories',
		)
	);
	$wpdb->delete(
		$wpdb->postmeta,
		array(
			'meta_key' => 'wcsc_bogo_id',
		)
	);

	// Clear scheduled cron.
	$timestamp = wp_next_scheduled( 'wcsc_daily_scheduler_check' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'wcsc_daily_scheduler_check' );
	}
}
register_uninstall_hook( __FILE__, 'wc_smart_coupons_uninstall' );
