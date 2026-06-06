<?php
/**
 * Plugin Name:       WooCommerce Stock Alert System
 * Plugin URI:        https://sandydigital.io
 * Description:       Low stock email notifications for WooCommerce. Per-product thresholds, multiple recipients, daily digest, and breach logging.
 * Version:           1.0.0
 * Author:            AcidBurn
 * Author URI:        https://sandydigital.io
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       wc-stock-alerts
 * Domain Path:       /languages
 * Requires at least: 5.0
 * Requires PHP:      7.4
 * WC requires at least: 4.0
 * WC tested up to:   9.0
 *
 * @package WC_Stock_Alerts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ), true ) ) {
	return;
}

final class WC_Stock_Alerts {

	private static $instance = null;

	const OPTION_KEY      = 'wc_stock_alerts_settings';
	const META_THRESHOLD  = '_wc_stock_alert_threshold';
	const META_DISABLE    = '_wc_stock_alert_disable';
	const LOG_TABLE       = 'wc_stock_alert_log';

	/** @var array Cache of already-sent alerts this request to prevent duplicates. */
	private $sent_this_request = array();

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( $this, 'maybe_create_log_table' ) );

		// Core triggers.
		add_action( 'woocommerce_reduce_order_stock', array( $this, 'on_reduce_stock' ) );
		add_action( 'woocommerce_product_set_stock_status', array( $this, 'on_stock_status_change' ), 10, 3 );
		add_action( 'woocommerce_product_set_stock', array( $this, 'on_stock_quantity_set' ) );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'on_variation_stock_set' ) );

		// Admin.
		add_action( 'admin_menu', array( $this, 'add_admin_pages' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_product_meta_box' ) );
		add_action( 'save_post', array( $this, 'save_product_meta_box' ) );

		// Product list column.
		add_filter( 'manage_edit-product_columns', array( $this, 'add_stock_alert_column' ) );
		add_action( 'manage_product_posts_custom_column', array( $this, 'render_stock_alert_column' ), 10, 2 );

		// Daily digest cron.
		add_action( 'wc_stock_alerts_daily_digest', array( $this, 'send_daily_digest' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'plugin_action_links' ) );

		// Activation hook.
		register_activation_hook( __FILE__, array( $this, 'activate' ) );
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'wc-stock-alerts', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}

	// ─── Activator ──────────────────────────────────────────

	public function activate() {
		$this->maybe_create_log_table();
		if ( ! wp_next_scheduled( 'wc_stock_alerts_daily_digest' ) ) {
			wp_schedule_event( strtotime( 'tomorrow 08:00' ), 'daily', 'wc_stock_alerts_daily_digest' );
		}
	}

	// ─── Database ───────────────────────────────────────────

	public function maybe_create_log_table() {
		global $wpdb;
		$table = $wpdb->prefix . self::LOG_TABLE;

		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists === $table ) {
			return;
		}

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			product_id BIGINT UNSIGNED NOT NULL,
			variation_id BIGINT UNSIGNED DEFAULT 0,
			old_stock INT NOT NULL,
			new_stock INT NOT NULL,
			threshold INT NOT NULL,
			alert_sent TINYINT(1) DEFAULT 1,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY product_id (product_id),
			KEY created_at (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	// ─── Settings ───────────────────────────────────────────

	private function defaults() {
		return array(
			'global_threshold'  => 5,
			'recipients'        => get_option( 'admin_email' ),
			'email_subject'     => __( '[{site_name}] Low Stock Alert: {product_name}', 'wc-stock-alerts' ),
			'email_heading'     => __( 'Low Stock Alert', 'wc-stock-alerts' ),
			'daily_digest'      => 'yes',
			'digest_time'       => '08:00',
			'enable_logging'    => 'yes',
			'out_of_stock_alert' => 'yes',
		);
	}

	public function get_settings() {
		$saved = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( $saved, $this->defaults() );
	}

	/**
	 * Get threshold for a product, falling back to global.
	 *
	 * @param int $product_id Product ID.
	 * @return int
	 */
	private function get_threshold( $product_id ) {
		$meta = get_post_meta( $product_id, self::META_THRESHOLD, true );
		if ( '' !== $meta && is_numeric( $meta ) ) {
			return absint( $meta );
		}
		return absint( $this->get_settings()['global_threshold'] );
	}

	// ─── Alert Logic ────────────────────────────────────────

	/**
	 * Triggered when stock is reduced by an order.
	 *
	 * @param WC_Order $order Order object.
	 */
	public function on_reduce_stock( $order ) {
		foreach ( $order->get_items() as $item ) {
			$product    = $item->get_product();
			if ( ! $product ) {
				continue;
			}

			$product_id  = $product->get_id();
			$variation_id = 0;

			if ( $product->is_type( 'variation' ) ) {
				$variation_id = $product_id;
				$product_id   = $product->get_parent_id();
			}

			if ( $this->is_disabled( $product_id ) ) {
				continue;
			}

			$this->check_and_alert( $product_id, $variation_id, $product );
		}
	}

	/**
	 * Triggered when stock status changes.
	 *
	 * @param int    $product_id   Product ID.
	 * @param string $stock_status New stock status.
	 * @param object $product      Product object.
	 */
	public function on_stock_status_change( $product_id, $stock_status, $product ) {
		if ( 'outofstock' === $stock_status && 'yes' === $this->get_settings()['out_of_stock_alert'] ) {
			if ( $this->is_disabled( $product_id ) ) {
				return;
			}
			$this->send_alert( $product_id, 0, 0, 0, $product );
		}
	}

	/**
	 * Triggered when stock quantity is set directly.
	 *
	 * @param WC_Product $product Product object.
	 */
	public function on_stock_quantity_set( $product ) {
		if ( $product->is_type( 'variation' ) ) {
			return; // Handled by on_variation_stock_set.
		}

		if ( $this->is_disabled( $product->get_id() ) ) {
			return;
		}

		if ( ! $product->managing_stock() ) {
			return;
		}

		$this->check_and_alert( $product->get_id(), 0, $product );
	}

	/**
	 * Variation stock change.
	 *
	 * @param WC_Product_Variation $variation Variation object.
	 */
	public function on_variation_stock_set( $variation ) {
		$parent_id = $variation->get_parent_id();

		if ( $this->is_disabled( $parent_id ) ) {
			return;
		}

		if ( ! $variation->managing_stock() ) {
			return;
		}

		$this->check_and_alert( $parent_id, $variation->get_id(), $variation );
	}

	/**
	 * Check stock level against threshold and send alert if needed.
	 *
	 * @param int        $product_id   Parent product ID.
	 * @param int        $variation_id Variation ID (0 if simple product).
	 * @param WC_Product $product      Product object.
	 */
	private function check_and_alert( $product_id, $variation_id, $product ) {
		$stock     = $product->get_stock_quantity();
		$threshold = $this->get_threshold( $product_id );

		if ( null === $stock ) {
			return;
		}

		if ( $stock > $threshold ) {
			return;
		}

		$this->send_alert( $product_id, $variation_id, $stock, $threshold, $product );
	}

	// ─── Sending ────────────────────────────────────────────

	/**
	 * Send alert email.
	 *
	 * @param int        $product_id   Product ID.
	 * @param int        $variation_id Variation ID.
	 * @param int        $stock        Current stock.
	 * @param int        $threshold    Alert threshold.
	 * @param WC_Product $product      Product object.
	 */
	private function send_alert( $product_id, $variation_id, $stock, $threshold, $product ) {
		// Prevent duplicates in same request.
		$key = $product_id . '_' . $variation_id;
		if ( isset( $this->sent_this_request[ $key ] ) ) {
			return;
		}
		$this->sent_this_request[ $key ] = true;

		$settings = $this->get_settings();

		$recipients = array_map( 'trim', explode( ',', $settings['recipients'] ) );
		$recipients = array_filter( $recipients, 'is_email' );

		if ( empty( $recipients ) ) {
			return;
		}

		$product_name = $product->get_name();
		if ( $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( $variation ) {
				$attributes = $variation->get_attribute_summary();
				if ( $attributes ) {
					$product_name .= ' — ' . $attributes;
				}
			}
		}

		$site_name = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );

		$subject = str_replace(
			array( '{site_name}', '{product_name}' ),
			array( $site_name, $product_name ),
			$settings['email_subject']
		);

		$edit_url = admin_url( 'post.php?post=' . $product_id . '&action=edit' );

		$message  = '<h2>' . esc_html( $settings['email_heading'] ) . '</h2>';
		$message .= '<p><strong>' . esc_html__( 'Product:', 'wc-stock-alerts' ) . '</strong> <a href="' . esc_url( $edit_url ) . '">' . esc_html( $product_name ) . '</a></p>';

		if ( 0 === $stock ) {
			$message .= '<p style="color:#e74c3c;"><strong>' . esc_html__( 'OUT OF STOCK', 'wc-stock-alerts' ) . '</strong></p>';
		} else {
			$message .= '<p><strong>' . esc_html__( 'Current Stock:', 'wc-stock-alerts' ) . '</strong> ' . esc_html( $stock ) . '</p>';
			$message .= '<p><strong>' . esc_html__( 'Alert Threshold:', 'wc-stock-alerts' ) . '</strong> ' . esc_html( $threshold ) . '</p>';
		}

		$message .= '<p><a href="' . esc_url( $edit_url ) . '" style="display:inline-block;padding:8px 16px;background:#007cba;color:#fff;text-decoration:none;border-radius:3px;">' . esc_html__( 'Edit Product', 'wc-stock-alerts' ) . '</a></p>';

		add_filter( 'wp_mail_content_type', array( $this, 'set_html_content_type' ) );
		wp_mail( $recipients, $subject, $message );
		remove_filter( 'wp_mail_content_type', array( $this, 'set_html_content_type' ) );

		// Log the alert.
		if ( 'yes' === $settings['enable_logging'] ) {
			$this->log_alert( $product_id, $variation_id, $stock, $threshold );
		}
	}

	/**
	 * Set HTML content type for emails.
	 *
	 * @return string
	 */
	public function set_html_content_type() {
		return 'text/html';
	}

	/**
	 * Log alert to database.
	 *
	 * @param int $product_id   Product ID.
	 * @param int $variation_id Variation ID.
	 * @param int $stock        Current stock.
	 * @param int $threshold    Threshold.
	 */
	private function log_alert( $product_id, $variation_id, $stock, $threshold ) {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . self::LOG_TABLE,
			array(
				'product_id'   => $product_id,
				'variation_id' => $variation_id,
				'old_stock'    => 0, // We don't track old vs new on initial alert.
				'new_stock'    => $stock,
				'threshold'    => $threshold,
				'alert_sent'   => 1,
				'created_at'   => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%d', '%d', '%d', '%d', '%s' )
		);
	}

	// ─── Daily Digest ───────────────────────────────────────

	/**
	 * Send daily digest of all products below threshold.
	 */
	public function send_daily_digest() {
		$settings = $this->get_settings();

		if ( 'yes' !== $settings['daily_digest'] ) {
			return;
		}

		$recipients = array_map( 'trim', explode( ',', $settings['recipients'] ) );
		$recipients = array_filter( $recipients, 'is_email' );

		if ( empty( $recipients ) ) {
			return;
		}

		$low_stock_products = $this->get_low_stock_products();

		if ( empty( $low_stock_products ) ) {
			return; // Nothing to report — all stock levels are healthy.
		}

		$site_name = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );

		$subject = sprintf(
			/* translators: %s: site name */
			__( '[%s] Daily Low Stock Digest', 'wc-stock-alerts' ),
			$site_name
		);

		$message  = '<h2>' . esc_html__( 'Daily Low Stock Digest', 'wc-stock-alerts' ) . '</h2>';
		$message .= '<p>' . esc_html__( 'The following products are at or below their stock alert threshold:', 'wc-stock-alerts' ) . '</p>';
		$message .= '<table border="0" cellpadding="8" cellspacing="0" style="border-collapse:collapse;width:100%;">';
		$message .= '<thead><tr style="background:#f1f1f1;text-align:left;">';
		$message .= '<th>' . esc_html__( 'Product', 'wc-stock-alerts' ) . '</th>';
		$message .= '<th>' . esc_html__( 'SKU', 'wc-stock-alerts' ) . '</th>';
		$message .= '<th>' . esc_html__( 'Stock', 'wc-stock-alerts' ) . '</th>';
		$message .= '<th>' . esc_html__( 'Threshold', 'wc-stock-alerts' ) . '</th>';
		$message .= '<th>' . esc_html__( 'Status', 'wc-stock-alerts' ) . '</th>';
		$message .= '</tr></thead><tbody>';

		foreach ( $low_stock_products as $item ) {
			$edit_url  = admin_url( 'post.php?post=' . $item['id'] . '&action=edit' );
			$status    = 0 === $item['stock'] ? 'OUT' : __( 'Low', 'wc-stock-alerts' );
			$row_style = 0 === $item['stock'] ? 'color:#e74c3c;font-weight:bold;' : '';

			$message .= '<tr>';
			$message .= '<td><a href="' . esc_url( $edit_url ) . '" style="' . esc_attr( $row_style ) . '">' . esc_html( $item['name'] ) . '</a></td>';
			$message .= '<td>' . esc_html( $item['sku'] ) . '</td>';
			$message .= '<td style="' . esc_attr( $row_style ) . '">' . esc_html( $item['stock'] ) . '</td>';
			$message .= '<td>' . esc_html( $item['threshold'] ) . '</td>';
			$message .= '<td style="' . esc_attr( $row_style ) . '">' . esc_html( $status ) . '</td>';
			$message .= '</tr>';
		}

		$message .= '</tbody></table>';
		$message .= '<p style="margin-top:20px;"><a href="' . esc_url( admin_url( 'admin.php?page=wc-stock-alerts-log' ) ) . '" style="display:inline-block;padding:8px 16px;background:#007cba;color:#fff;text-decoration:none;border-radius:3px;">' . esc_html__( 'View Full Log', 'wc-stock-alerts' ) . '</a></p>';

		add_filter( 'wp_mail_content_type', array( $this, 'set_html_content_type' ) );
		wp_mail( $recipients, $subject, $message );
		remove_filter( 'wp_mail_content_type', array( $this, 'set_html_content_type' ) );
	}

	/**
	 * Get all products below threshold.
	 *
	 * @return array
	 */
	private function get_low_stock_products() {
		$products = wc_get_products( array(
			'limit'  => -1,
			'status' => 'publish',
			'type'   => array( 'simple', 'variable' ),
		) );

		$result = array();

		foreach ( $products as $product ) {
			if ( $this->is_disabled( $product->get_id() ) ) {
				continue;
			}

			$threshold = $this->get_threshold( $product->get_id() );

			if ( $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $variation_id ) {
					$variation = wc_get_product( $variation_id );
					if ( ! $variation || ! $variation->managing_stock() ) {
						continue;
					}

					$stock = $variation->get_stock_quantity();
					if ( null === $stock || $stock > $threshold ) {
						continue;
					}

					$result[] = array(
						'id'        => $product->get_id(),
						'name'      => $product->get_name() . ' — ' . $variation->get_attribute_summary(),
						'sku'       => $variation->get_sku(),
						'stock'     => $stock,
						'threshold' => $threshold,
					);
				}
			} elseif ( $product->managing_stock() ) {
				$stock = $product->get_stock_quantity();
				if ( null === $stock || $stock > $threshold ) {
					continue;
				}

				$result[] = array(
					'id'        => $product->get_id(),
					'name'      => $product->get_name(),
					'sku'       => $product->get_sku(),
					'stock'     => $stock,
					'threshold' => $threshold,
				);
			}
		}

		return $result;
	}

	// ─── Admin ──────────────────────────────────────────────

	public function add_admin_pages() {
		add_submenu_page(
			'woocommerce',
			esc_html__( 'Stock Alerts', 'wc-stock-alerts' ),
			esc_html__( 'Stock Alerts', 'wc-stock-alerts' ),
			'manage_woocommerce',
			'wc-stock-alerts',
			array( $this, 'render_settings_page' )
		);

		add_submenu_page(
			'woocommerce',
			esc_html__( 'Stock Alert Log', 'wc-stock-alerts' ),
			esc_html__( 'Stock Alert Log', 'wc-stock-alerts' ),
			'manage_woocommerce',
			'wc-stock-alerts-log',
			array( $this, 'render_log_page' )
		);
	}

	public function register_settings() {
		register_setting( 'wc_stock_alerts_group', self::OPTION_KEY, array(
			'sanitize_callback' => array( $this, 'sanitize_settings' ),
		) );
	}

	public function sanitize_settings( $input ) {
		$clean = array();

		$clean['global_threshold']   = isset( $input['global_threshold'] ) ? absint( $input['global_threshold'] ) : 5;
		$clean['recipients']         = isset( $input['recipients'] ) ? sanitize_textarea_field( $input['recipients'] ) : get_option( 'admin_email' );
		$clean['email_subject']      = isset( $input['email_subject'] ) ? sanitize_text_field( $input['email_subject'] ) : '';
		$clean['email_heading']      = isset( $input['email_heading'] ) ? sanitize_text_field( $input['email_heading'] ) : '';
		$clean['daily_digest']       = isset( $input['daily_digest'] ) ? 'yes' : 'no';
		$clean['digest_time']        = isset( $input['digest_time'] ) ? sanitize_text_field( $input['digest_time'] ) : '08:00';
		$clean['enable_logging']     = isset( $input['enable_logging'] ) ? 'yes' : 'no';
		$clean['out_of_stock_alert'] = isset( $input['out_of_stock_alert'] ) ? 'yes' : 'no';

		return $clean;
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'wc-stock-alerts' ) );
		}

		$settings = $this->get_settings();
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'wc_stock_alerts_group' );
				wp_nonce_field( 'wc_stock_alerts_save', 'wc_stock_alerts_nonce' );
				?>
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="wc_stock_global_threshold"><?php esc_html_e( 'Global Low-Stock Threshold', 'wc-stock-alerts' ); ?></label>
						</th>
						<td>
							<input type="number" id="wc_stock_global_threshold" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[global_threshold]" value="<?php echo esc_attr( $settings['global_threshold'] ); ?>" class="small-text" min="0" max="9999" />
							<p class="description"><?php esc_html_e( 'Send an alert when stock falls to or below this number. Can be overridden per product.', 'wc-stock-alerts' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="wc_stock_recipients"><?php esc_html_e( 'Alert Recipients', 'wc-stock-alerts' ); ?></label>
						</th>
						<td>
							<textarea id="wc_stock_recipients" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[recipients]" rows="4" class="large-text" placeholder="admin@example.com, manager@example.com"><?php echo esc_textarea( $settings['recipients'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Comma-separated list of email addresses to receive stock alerts and daily digests.', 'wc-stock-alerts' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="wc_stock_email_subject"><?php esc_html_e( 'Email Subject', 'wc-stock-alerts' ); ?></label>
						</th>
						<td>
							<input type="text" id="wc_stock_email_subject" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[email_subject]" value="<?php echo esc_attr( $settings['email_subject'] ); ?>" class="large-text" />
							<p class="description">
								<?php esc_html_e( 'Available placeholders:', 'wc-stock-alerts' ); ?>
								<code>{site_name}</code> <code>{product_name}</code>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="wc_stock_email_heading"><?php esc_html_e( 'Email Heading', 'wc-stock-alerts' ); ?></label>
						</th>
						<td>
							<input type="text" id="wc_stock_email_heading" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[email_heading]" value="<?php echo esc_attr( $settings['email_heading'] ); ?>" class="regular-text" />
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Out of Stock Alerts', 'wc-stock-alerts' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[out_of_stock_alert]" value="yes" <?php checked( 'yes', $settings['out_of_stock_alert'] ); ?> />
								<?php esc_html_e( 'Send an immediate alert when a product goes out of stock.', 'wc-stock-alerts' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Daily Digest', 'wc-stock-alerts' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[daily_digest]" value="yes" <?php checked( 'yes', $settings['daily_digest'] ); ?> />
								<?php esc_html_e( 'Send a daily summary email listing all products below threshold.', 'wc-stock-alerts' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'The digest only sends if at least one product is below threshold. Silent when everything is stocked.', 'wc-stock-alerts' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Alert Logging', 'wc-stock-alerts' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enable_logging]" value="yes" <?php checked( 'yes', $settings['enable_logging'] ); ?> />
								<?php esc_html_e( 'Record every stock alert in the database for audit and review.', 'wc-stock-alerts' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'View the log at WooCommerce → Stock Alert Log.', 'wc-stock-alerts' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the breach log page.
	 */
	public function render_log_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'wc-stock-alerts' ) );
		}

		// Handle clear log action.
		if ( isset( $_GET['clear_log'] ) && '1' === $_GET['clear_log'] ) {
			if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'wc_stock_alerts_clear_log' ) ) {
				wp_die( esc_html__( 'Security check failed.', 'wc-stock-alerts' ) );
			}

			global $wpdb;
			$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}" . self::LOG_TABLE );
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Log cleared.', 'wc-stock-alerts' ) . '</p></div>';
		}

		global $wpdb;
		$table = $wpdb->prefix . self::LOG_TABLE;

		$per_page = 50;
		$page     = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$offset   = ( $page - 1 ) * $per_page;

		$total = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$logs  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d OFFSET %d", $per_page, $offset ) );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Stock Alert Log', 'wc-stock-alerts' ); ?></h1>

			<?php
			$clear_url = wp_nonce_url( admin_url( 'admin.php?page=wc-stock-alerts-log&clear_log=1' ), 'wc_stock_alerts_clear_log' );
			?>
			<p>
				<a href="<?php echo esc_url( $clear_url ); ?>" class="button button-secondary" onclick="return confirm('<?php echo esc_js( __( 'Are you sure you want to clear all log entries?', 'wc-stock-alerts' ) ); ?>');">
					<?php esc_html_e( 'Clear Log', 'wc-stock-alerts' ); ?>
				</a>
			</p>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', 'wc-stock-alerts' ); ?></th>
						<th><?php esc_html_e( 'Product', 'wc-stock-alerts' ); ?></th>
						<th><?php esc_html_e( 'Stock Level', 'wc-stock-alerts' ); ?></th>
						<th><?php esc_html_e( 'Threshold', 'wc-stock-alerts' ); ?></th>
						<th><?php esc_html_e( 'Alert Sent', 'wc-stock-alerts' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $logs ) ) : ?>
						<tr>
							<td colspan="5"><?php esc_html_e( 'No log entries yet.', 'wc-stock-alerts' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $logs as $log ) : ?>
							<tr>
								<td><?php echo esc_html( $log->created_at ); ?></td>
								<td>
									<?php
									$product = wc_get_product( $log->product_id );
									if ( $product ) {
										$edit_url = admin_url( 'post.php?post=' . $log->product_id . '&action=edit' );
										echo '<a href="' . esc_url( $edit_url ) . '">' . esc_html( $product->get_name() ) . '</a>';
										if ( $log->variation_id ) {
											$var = wc_get_product( $log->variation_id );
											if ( $var ) {
												echo ' — <small>' . esc_html( $var->get_attribute_summary() ) . '</small>';
											}
										}
									} else {
										echo esc_html( '#' . $log->product_id );
									}
									?>
								</td>
								<td>
									<span style="<?php echo 0 === (int) $log->new_stock ? 'color:#e74c3c;font-weight:bold;' : ''; ?>">
										<?php echo 0 === (int) $log->new_stock ? esc_html__( 'OUT', 'wc-stock-alerts' ) : esc_html( $log->new_stock ); ?>
									</span>
								</td>
								<td><?php echo esc_html( $log->threshold ); ?></td>
								<td><?php echo $log->alert_sent ? '✅' : '❌'; ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $total > $per_page ) : ?>
				<div class="tablenav">
					<div class="tablenav-pages">
						<?php
						$total_pages = ceil( $total / $per_page );
						for ( $i = 1; $i <= $total_pages; $i++ ) {
							$url = admin_url( 'admin.php?page=wc-stock-alerts-log&paged=' . $i );
							$class = $i === $page ? 'current button' : 'button';
							printf( '<a href="%s" class="%s">%d</a> ', esc_url( $url ), esc_attr( $class ), esc_html( $i ) );
						}
						?>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	// ─── Product Meta ───────────────────────────────────────

	public function add_product_meta_box() {
		add_meta_box(
			'wc_stock_alert_product',
			esc_html__( 'Stock Alert Settings', 'wc-stock-alerts' ),
			array( $this, 'render_product_meta_box' ),
			'product',
			'side',
			'default'
		);
	}

	public function render_product_meta_box( $post ) {
		wp_nonce_field( 'wc_stock_alerts_product_save', 'wc_stock_alerts_product_nonce' );

		$disable   = get_post_meta( $post->ID, self::META_DISABLE, true );
		$threshold = get_post_meta( $post->ID, self::META_THRESHOLD, true );
		$global    = $this->get_settings()['global_threshold'];
		?>
		<p>
			<label>
				<input type="checkbox" name="_wc_stock_alert_disable" value="yes" <?php checked( 'yes', $disable ); ?> />
				<?php esc_html_e( 'Disable alerts for this product', 'wc-stock-alerts' ); ?>
			</label>
		</p>
		<hr />
		<p>
			<label><strong><?php esc_html_e( 'Custom Threshold', 'wc-stock-alerts' ); ?></strong></label><br />
			<input type="number" name="_wc_stock_alert_threshold" value="<?php echo esc_attr( $threshold ); ?>" min="0" max="9999" style="width:100%;" placeholder="<?php echo esc_attr( (string) $global ); ?>" />
			<span class="description">
				<?php
				printf(
					/* translators: %d: global threshold number */
					esc_html__( 'Leave empty to use global threshold (%d).', 'wc-stock-alerts' ),
					$global
				);
				?>
			</span>
		</p>
		<?php
	}

	public function save_product_meta_box( $post_id ) {
		if ( ! isset( $_POST['wc_stock_alerts_product_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wc_stock_alerts_product_nonce'] ) ), 'wc_stock_alerts_product_save' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$disable = isset( $_POST['_wc_stock_alert_disable'] ) ? 'yes' : 'no';
		update_post_meta( $post_id, self::META_DISABLE, $disable );

		if ( isset( $_POST['_wc_stock_alert_threshold'] ) ) {
			$threshold = sanitize_text_field( wp_unslash( $_POST['_wc_stock_alert_threshold'] ) );
			if ( '' === $threshold ) {
				delete_post_meta( $post_id, self::META_THRESHOLD );
			} else {
				update_post_meta( $post_id, self::META_THRESHOLD, absint( $threshold ) );
			}
		}
	}

	// ─── Admin Column ───────────────────────────────────────

	public function add_stock_alert_column( $columns ) {
		$new = array();
		foreach ( $columns as $key => $value ) {
			$new[ $key ] = $value;
			if ( 'is_in_stock' === $key ) {
				$new['stock_alert'] = esc_html__( 'Alert', 'wc-stock-alerts' );
			}
		}
		return $new;
	}

	public function render_stock_alert_column( $column, $post_id ) {
		if ( 'stock_alert' !== $column ) {
			return;
		}

		if ( $this->is_disabled( $post_id ) ) {
			echo '<span style="color:#999;">—</span>';
			return;
		}

		$product   = wc_get_product( $post_id );
		$threshold = $this->get_threshold( $post_id );

		if ( ! $product || ! $product->managing_stock() ) {
			echo '<span style="color:#999;">—</span>';
			return;
		}

		$stock = $product->get_stock_quantity();
		if ( null === $stock ) {
			echo '<span style="color:#999;">—</span>';
			return;
		}

		if ( 0 === $stock ) {
			echo '<span style="color:#e74c3c;font-weight:bold;" title="' . esc_attr__( 'Out of stock', 'wc-stock-alerts' ) . '">⚠ ' . esc_html__( 'OUT', 'wc-stock-alerts' ) . '</span>';
		} elseif ( $stock <= $threshold ) {
			echo '<span style="color:#e67e22;" title="' . esc_attr( sprintf( __( 'Below threshold of %d', 'wc-stock-alerts' ), $threshold ) ) . '">⚠ ' . esc_html( $stock ) . '</span>';
		} else {
			echo '<span style="color:#27ae60;">✓</span>';
		}
	}

	// ─── Helpers ────────────────────────────────────────────

	/**
	 * Check if a product has alerts disabled.
	 *
	 * @param int $product_id Product ID.
	 * @return bool
	 */
	private function is_disabled( $product_id ) {
		return 'yes' === get_post_meta( $product_id, self::META_DISABLE, true );
	}

	public function plugin_action_links( $links ) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=wc-stock-alerts' ) ),
			esc_html__( 'Settings', 'wc-stock-alerts' )
		);
		array_unshift( $links, $settings_link );
		return $links;
	}
}

add_action( 'plugins_loaded', array( 'WC_Stock_Alerts', 'instance' ) );

// Uninstall.
register_uninstall_hook( __FILE__, 'wc_stock_alerts_uninstall' );

function wc_stock_alerts_uninstall() {
	global $wpdb;

	delete_option( 'wc_stock_alerts_settings' );
	wp_clear_scheduled_hook( 'wc_stock_alerts_daily_digest' );

	// Drop log table.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wc_stock_alert_log" );

	// Clean post meta.
	$meta_keys = array( '_wc_stock_alert_threshold', '_wc_stock_alert_disable' );
	$products  = get_posts( array(
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