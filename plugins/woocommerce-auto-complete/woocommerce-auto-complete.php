<?php
/**
 * Plugin Name:       WooCommerce Order Auto-Complete
 * Plugin URI:        https://sandydigital.io
 * Description:       Automatically mark virtual and downloadable orders as completed. Filter by payment method, per-product toggle, and bulk actions.
 * Version:           1.0.0
 * Author:            AcidBurn
 * Author URI:        https://sandydigital.io
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       wc-order-auto-complete
 * Domain Path:       /languages
 * Requires at least: 5.0
 * Requires PHP:      7.4
 * WC requires at least: 4.0
 * WC tested up to:   9.0
 *
 * @package WC_Order_Auto_Complete
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// WooCommerce dependency check.
if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ), true ) ) {
	return;
}

/**
 * Main plugin class.
 */
final class WC_Order_Auto_Complete {

	/**
	 * Plugin instance.
	 *
	 * @var WC_Order_Auto_Complete|null
	 */
	private static $instance = null;

	/**
	 * Option name.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'wc_auto_complete_settings';

	/**
	 * Get single instance.
	 *
	 * @return WC_Order_Auto_Complete
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_filter( 'woocommerce_payment_complete_order_status', array( $this, 'maybe_auto_complete' ), 10, 3 );
		add_action( 'woocommerce_order_status_processing', array( $this, 'auto_complete_on_processing' ), 10, 2 );
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'plugin_action_links' ) );

		// Product-level meta box.
		add_action( 'add_meta_boxes', array( $this, 'add_product_meta_box' ) );
		add_action( 'save_post_product', array( $this, 'save_product_meta_box' ) );

		// Bulk action on orders.
		add_filter( 'bulk_actions-edit-shop_order', array( $this, 'add_bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-shop_order', array( $this, 'handle_bulk_actions' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'bulk_action_notice' ) );
	}

	/**
	 * Load plugin textdomain.
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'wc-order-auto-complete',
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/languages'
		);
	}

	/**
	 * Get default settings.
	 *
	 * @return array
	 */
	private function defaults() {
		return array(
			'enabled'              => 'yes',
			'auto_complete_virtual' => 'yes',
			'auto_complete_downloadable' => 'yes',
			'allowed_payment_methods' => array(),
			'excluded_categories'  => array(),
			'send_completed_email' => 'yes',
		);
	}

	/**
	 * Get plugin settings.
	 *
	 * @return array
	 */
	public function get_settings() {
		$saved = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( $saved, $this->defaults() );
	}

	/**
	 * Check if order qualifies for auto-complete.
	 *
	 * @param int $order_id Order ID.
	 * @return bool
	 */
	private function qualifies_for_auto_complete( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return false;
		}

		$settings = $this->get_settings();

		if ( 'yes' !== $settings['enabled'] ) {
			return false;
		}

		// Check if order contains only virtual/downloadable products or
		// if all non-virtual/downloadable products have auto-complete enabled.
		$all_qualify = true;
		$has_qualifying_items = false;

		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}

			$product_id = $product->get_id();

			// Check per-product disable flag.
			$disable = get_post_meta( $product_id, '_wc_auto_complete_disable', true );
			if ( 'yes' === $disable ) {
				$all_qualify = false;
				continue;
			}

			$is_virtual = $product->is_virtual();
			$is_downloadable = $product->is_downloadable();

			if ( 'yes' === $settings['auto_complete_virtual'] && $is_virtual ) {
				$has_qualifying_items = true;
				continue;
			}

			if ( 'yes' === $settings['auto_complete_downloadable'] && $is_downloadable ) {
				$has_qualifying_items = true;
				continue;
			}

			// Check excluded categories.
			$categories = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );
			$excluded = $settings['excluded_categories'];
			if ( ! empty( $excluded ) && ! empty( $categories ) ) {
				$intersection = array_intersect( $categories, $excluded );
				if ( ! empty( $intersection ) ) {
					$all_qualify = false;
					continue;
				}
			}

			$all_qualify = false;
		}

		if ( ! $has_qualifying_items && ! $all_qualify ) {
			return false;
		}

		// Check allowed payment methods.
		$payment_method = $order->get_payment_method();
		$allowed_methods = $settings['allowed_payment_methods'];

		if ( ! empty( $allowed_methods ) && ! in_array( $payment_method, $allowed_methods, true ) ) {
			return false;
		}

		return $all_qualify;
	}

	/**
	 * Maybe auto-complete when payment completes.
	 *
	 * @param string   $status   New status.
	 * @param int      $order_id Order ID.
	 * @param WC_Order $order    Order object.
	 * @return string
	 */
	public function maybe_auto_complete( $status, $order_id, $order ) {
		if ( $this->qualifies_for_auto_complete( $order_id ) ) {
			return 'completed';
		}
		return $status;
	}

	/**
	 * Auto-complete when order moves to processing.
	 *
	 * @param int      $order_id Order ID.
	 * @param WC_Order $order    Order object.
	 */
	public function auto_complete_on_processing( $order_id, $order ) {
		if ( ! $this->qualifies_for_auto_complete( $order_id ) ) {
			return;
		}

		$settings = $this->get_settings();

		$order->update_status(
			'completed',
			esc_html__( 'Order auto-completed by WooCommerce Order Auto-Complete plugin.', 'wc-order-auto-complete' )
		);

		// Optionally trigger completed email.
		if ( 'yes' === $settings['send_completed_email'] ) {
			WC()->mailer()->get_emails()['WC_Email_Customer_Completed_Order']->trigger( $order_id );
		}
	}

	/**
	 * Add settings page.
	 */
	public function add_settings_page() {
		add_submenu_page(
			'woocommerce',
			esc_html__( 'Order Auto-Complete', 'wc-order-auto-complete' ),
			esc_html__( 'Order Auto-Complete', 'wc-order-auto-complete' ),
			'manage_woocommerce',
			'wc-auto-complete',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register settings.
	 */
	public function register_settings() {
		register_setting( 'wc_auto_complete_group', self::OPTION_KEY, array(
			'sanitize_callback' => array( $this, 'sanitize_settings' ),
		) );

		add_settings_section(
			'wc_auto_complete_main',
			esc_html__( 'General Settings', 'wc-order-auto-complete' ),
			'__return_empty_string',
			'wc-auto-complete'
		);
	}

	/**
	 * Sanitize settings.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$clean = array();

		$clean['enabled'] = isset( $input['enabled'] ) ? 'yes' : 'no';
		$clean['auto_complete_virtual'] = isset( $input['auto_complete_virtual'] ) ? 'yes' : 'no';
		$clean['auto_complete_downloadable'] = isset( $input['auto_complete_downloadable'] ) ? 'yes' : 'no';
		$clean['send_completed_email'] = isset( $input['send_completed_email'] ) ? 'yes' : 'no';

		$clean['allowed_payment_methods'] = isset( $input['allowed_payment_methods'] ) && is_array( $input['allowed_payment_methods'] )
			? array_map( 'sanitize_text_field', $input['allowed_payment_methods'] )
			: array();

		$clean['excluded_categories'] = isset( $input['excluded_categories'] ) && is_array( $input['excluded_categories'] )
			? array_map( 'absint', $input['excluded_categories'] )
			: array();

		return $clean;
	}

	/**
	 * Render settings page.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wc-order-auto-complete' ) );
		}

		$settings = $this->get_settings();
		$payment_gateways = WC()->payment_gateways()->get_available_payment_gateways();
		$categories = get_terms( array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
		) );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'wc_auto_complete_group' );
				wp_nonce_field( 'wc_auto_complete_save', 'wc_auto_complete_nonce' );
				?>
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="wc_auto_complete_enabled">
								<?php esc_html_e( 'Enable Auto-Complete', 'wc-order-auto-complete' ); ?>
							</label>
						</th>
						<td>
							<input type="checkbox" id="wc_auto_complete_enabled" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enabled]" value="yes" <?php checked( 'yes', $settings['enabled'] ); ?> />
							<p class="description"><?php esc_html_e( 'Automatically complete orders that only contain virtual/downloadable products.', 'wc-order-auto-complete' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<?php esc_html_e( 'Auto-Complete Triggers', 'wc-order-auto-complete' ); ?>
						</th>
						<td>
							<fieldset>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[auto_complete_virtual]" value="yes" <?php checked( 'yes', $settings['auto_complete_virtual'] ); ?> />
									<?php esc_html_e( 'Auto-complete orders with virtual products', 'wc-order-auto-complete' ); ?>
								</label><br />
								<label>
									<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[auto_complete_downloadable]" value="yes" <?php checked( 'yes', $settings['auto_complete_downloadable'] ); ?> />
									<?php esc_html_e( 'Auto-complete orders with downloadable products', 'wc-order-auto-complete' ); ?>
								</label>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label><?php esc_html_e( 'Allowed Payment Methods', 'wc-order-auto-complete' ); ?></label>
						</th>
						<td>
							<?php if ( ! empty( $payment_gateways ) ) : ?>
								<fieldset>
									<p class="description"><?php esc_html_e( 'Only auto-complete orders using these payment methods. Leave empty to allow all.', 'wc-order-auto-complete' ); ?></p>
									<?php foreach ( $payment_gateways as $id => $gateway ) : ?>
										<label>
											<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[allowed_payment_methods][]" value="<?php echo esc_attr( $id ); ?>" <?php checked( in_array( $id, $settings['allowed_payment_methods'], true ) ); ?> />
											<?php echo esc_html( $gateway->get_title() ); ?>
										</label><br />
									<?php endforeach; ?>
								</fieldset>
							<?php else : ?>
								<p class="description"><?php esc_html_e( 'No payment gateways available.', 'wc-order-auto-complete' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label><?php esc_html_e( 'Excluded Categories', 'wc-order-auto-complete' ); ?></label>
						</th>
						<td>
							<?php if ( ! empty( $categories ) && ! is_wp_error( $categories ) ) : ?>
								<fieldset>
									<p class="description"><?php esc_html_e( 'Orders containing products from these categories will NOT be auto-completed.', 'wc-order-auto-complete' ); ?></p>
									<?php foreach ( $categories as $cat ) : ?>
										<label>
											<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[excluded_categories][]" value="<?php echo esc_attr( $cat->term_id ); ?>" <?php checked( in_array( (string) $cat->term_id, array_map( 'strval', $settings['excluded_categories'] ), true ) ); ?> />
											<?php echo esc_html( $cat->name ); ?>
										</label><br />
									<?php endforeach; ?>
								</fieldset>
							<?php else : ?>
								<p class="description"><?php esc_html_e( 'No product categories found.', 'wc-order-auto-complete' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="wc_auto_complete_email">
								<?php esc_html_e( 'Send Completed Email', 'wc-order-auto-complete' ); ?>
							</label>
						</th>
						<td>
							<input type="checkbox" id="wc_auto_complete_email" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[send_completed_email]" value="yes" <?php checked( 'yes', $settings['send_completed_email'] ); ?> />
							<p class="description"><?php esc_html_e( 'Send the "Order Completed" email notification to the customer.', 'wc-order-auto-complete' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Add product meta box for per-product override.
	 */
	public function add_product_meta_box() {
		add_meta_box(
			'wc_auto_complete_product',
			esc_html__( 'Order Auto-Complete', 'wc-order-auto-complete' ),
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
		wp_nonce_field( 'wc_auto_complete_product_save', 'wc_auto_complete_product_nonce' );
		$disable = get_post_meta( $post->ID, '_wc_auto_complete_disable', true );
		?>
		<label>
			<input type="checkbox" name="_wc_auto_complete_disable" value="yes" <?php checked( 'yes', $disable ); ?> />
			<?php esc_html_e( 'Disable auto-complete for this product', 'wc-order-auto-complete' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'When checked, orders containing this product will not be auto-completed.', 'wc-order-auto-complete' ); ?>
		</p>
		<?php
	}

	/**
	 * Save product meta box.
	 *
	 * @param int $post_id Post ID.
	 */
	public function save_product_meta_box( $post_id ) {
		if ( ! isset( $_POST['wc_auto_complete_product_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wc_auto_complete_product_nonce'] ) ), 'wc_auto_complete_product_save' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$disable = isset( $_POST['_wc_auto_complete_disable'] ) ? 'yes' : 'no';
		update_post_meta( $post_id, '_wc_auto_complete_disable', $disable );
	}

	/**
	 * Add bulk actions to orders list.
	 *
	 * @param array $actions Existing bulk actions.
	 * @return array
	 */
	public function add_bulk_actions( $actions ) {
		$actions['wc_auto_complete']   = esc_html__( 'Auto-Complete Orders', 'wc-order-auto-complete' );
		$actions['wc_unauto_complete'] = esc_html__( 'Un-Auto-Complete Orders', 'wc-order-auto-complete' );
		return $actions;
	}

	/**
	 * Handle bulk actions.
	 *
	 * @param string $redirect_to Redirect URL.
	 * @param string $action      Action name.
	 * @param array  $post_ids    Post IDs.
	 * @return string
	 */
	public function handle_bulk_actions( $redirect_to, $action, $post_ids ) {
		if ( ! in_array( $action, array( 'wc_auto_complete', 'wc_unauto_complete' ), true ) ) {
			return $redirect_to;
		}

		$count = 0;

		foreach ( $post_ids as $post_id ) {
			$order = wc_get_order( $post_id );
			if ( ! $order ) {
				continue;
			}

			if ( 'wc_auto_complete' === $action ) {
				if ( $this->qualifies_for_auto_complete( $post_id ) ) {
					$order->update_status(
						'completed',
						esc_html__( 'Bulk auto-completed.', 'wc-order-auto-complete' )
					);
					$count++;
				}
			} else {
				$order->add_order_note(
					esc_html__( 'Auto-complete disabled for this order via bulk action.', 'wc-order-auto-complete' )
				);
				add_post_meta( $post_id, '_wc_auto_complete_skipped', 'yes', true );
				$count++;
			}
		}

		return add_query_arg( array(
			'wc_auto_complete_bulk' => $action,
			'wc_auto_complete_count' => $count,
		), $redirect_to );
	}

	/**
	 * Display admin notice after bulk action.
	 */
	public function bulk_action_notice() {
		$screen = get_current_screen();
		if ( ! $screen || 'edit-shop_order' !== $screen->id ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['wc_auto_complete_bulk'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = sanitize_text_field( wp_unslash( $_GET['wc_auto_complete_bulk'] ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$count = isset( $_GET['wc_auto_complete_count'] ) ? absint( $_GET['wc_auto_complete_count'] ) : 0;

		if ( 'wc_auto_complete' === $action ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d: number of orders */
						_n(
							'%d order auto-completed.',
							'%d orders auto-completed.',
							$count,
							'wc-order-auto-complete'
						),
						$count
					)
				)
			);
		} else {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d: number of orders */
						_n(
							'%d order marked to skip auto-complete.',
							'%d orders marked to skip auto-complete.',
							$count,
							'wc-order-auto-complete'
						),
						$count
					)
				)
			);
		}
	}

	/**
	 * Plugin action links.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function plugin_action_links( $links ) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=wc-auto-complete' ) ),
			esc_html__( 'Settings', 'wc-order-auto-complete' )
		);
		array_unshift( $links, $settings_link );
		return $links;
	}
}

// Initialize plugin.
add_action( 'plugins_loaded', array( 'WC_Order_Auto_Complete', 'instance' ) );

/**
 * Uninstall cleanup.
 */
register_uninstall_hook( __FILE__, 'wc_order_auto_complete_uninstall' );

/**
 * Clean up on uninstall.
 */
function wc_order_auto_complete_uninstall() {
	delete_option( 'wc_auto_complete_settings' );

	// Delete all post meta.
	$products = get_posts( array(
		'post_type'      => array( 'product', 'shop_order' ),
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'meta_key'       => '_wc_auto_complete_disable',
	) );

	if ( ! empty( $products ) ) {
		foreach ( $products as $product_id ) {
			delete_post_meta( $product_id, '_wc_auto_complete_disable' );
		}
	}

	$skipped = get_posts( array(
		'post_type'      => 'shop_order',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'meta_key'       => '_wc_auto_complete_skipped',
	) );

	if ( ! empty( $skipped ) ) {
		foreach ( $skipped as $order_id ) {
			delete_post_meta( $order_id, '_wc_auto_complete_skipped' );
		}
	}
}