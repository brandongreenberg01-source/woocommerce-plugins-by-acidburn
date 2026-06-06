<?php
/**
 * Plugin Name:       WooCommerce Custom Product Badges
 * Plugin URI:        https://sandydigital.io
 * Description:       Add eye-catching "Sale", "New", "Featured", and custom badges to your WooCommerce products. Color picker, scheduling, and per-product overrides.
 * Version:           1.0.0
 * Author:            Brandon Greenberg
 * Author URI:        https://sandydigital.io
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       wc-custom-badges
 * Domain Path:       /languages
 * Requires at least: 5.0
 * Requires PHP:      7.4
 * WC requires at least: 4.0
 * WC tested up to:   9.0
 *
 * @package WC_Custom_Badges
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ), true ) ) {
	return;
}

final class WC_Custom_Badges {

	private static $instance = null;

	const OPTION_KEY = 'wc_custom_badges_settings';
	const META_DISABLE  = '_wc_badge_disable';
	const META_TYPE     = '_wc_badge_type';
	const META_TEXT     = '_wc_badge_text';
	const META_COLOR    = '_wc_badge_color';
	const META_START    = '_wc_badge_start_date';
	const META_EXPIRY   = '_wc_badge_expiry_date';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'woocommerce_before_shop_loop_item_title', array( $this, 'render_badge_loop' ), 10 );
		add_action( 'woocommerce_before_single_product_summary', array( $this, 'render_badge_single' ), 10 );
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'plugin_action_links' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_product_meta_box' ) );
		add_action( 'save_post_product', array( $this, 'save_product_meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_styles' ) );
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'wc-custom-badges', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}

	private function defaults() {
		return array(
			'sale_enabled'       => 'yes',
			'sale_text'          => 'Sale!',
			'sale_bg_color'      => '#e74c3c',
			'sale_text_color'    => '#ffffff',

			'new_enabled'        => 'yes',
			'new_text'           => 'New',
			'new_bg_color'       => '#2ecc71',
			'new_text_color'     => '#ffffff',
			'new_duration_days'  => 30,

			'featured_enabled'   => 'yes',
			'featured_text'      => 'Featured',
			'featured_bg_color'  => '#f39c12',
			'featured_text_color' => '#ffffff',

			'custom_enabled'     => 'no',
			'custom_text'        => 'Custom',
			'custom_bg_color'    => '#9b59b6',
			'custom_text_color'  => '#ffffff',

			'position'           => 'top-left',
			'font_size'          => 12,
			'border_radius'      => 3,
		);
	}

	private function position_options() {
		return array(
			'top-left'      => __( 'Top Left', 'wc-custom-badges' ),
			'top-right'     => __( 'Top Right', 'wc-custom-badges' ),
			'bottom-left'   => __( 'Bottom Left', 'wc-custom-badges' ),
			'bottom-right'  => __( 'Bottom Right', 'wc-custom-badges' ),
			'center'        => __( 'Center', 'wc-custom-badges' ),
		);
	}

	public function get_settings() {
		$saved = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( $saved, $this->defaults() );
	}

	/**
	 * Determine which badge to show for a product.
	 *
	 * @param int $product_id Product ID.
	 * @return array|false Badge config or false if none.
	 */
	private function get_badge_for_product( $product_id ) {
		$settings = $this->get_settings();

		// Check per-product disable.
		if ( 'yes' === get_post_meta( $product_id, self::META_DISABLE, true ) ) {
			return false;
		}

		// Check per-product override badge.
		$override_type = get_post_meta( $product_id, self::META_TYPE, true );
		if ( ! empty( $override_type ) && 'none' !== $override_type ) {
			$expiry = get_post_meta( $product_id, self::META_EXPIRY, true );
			if ( ! empty( $expiry ) && strtotime( $expiry ) < time() ) {
				return false;
			}

			$start = get_post_meta( $product_id, self::META_START, true );
			if ( ! empty( $start ) && strtotime( $start ) > time() ) {
				return false;
			}

			$text  = get_post_meta( $product_id, self::META_TEXT, true );
			$color = get_post_meta( $product_id, self::META_COLOR, true );

			$badge_key = $override_type;
			$badge_text = ! empty( $text ) ? $text : $settings[ $badge_key . '_text' ];
			$badge_bg   = ! empty( $color ) ? $color : $settings[ $badge_key . '_bg_color' ];
			$badge_text_color = $settings[ $badge_key . '_text_color' ];

			return array(
				'text'       => $badge_text,
				'bg_color'   => $badge_bg,
				'text_color' => $badge_text_color,
			);
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return false;
		}

		// Check sale badge.
		if ( 'yes' === $settings['sale_enabled'] && $product->is_on_sale() ) {
			return array(
				'text'       => $settings['sale_text'],
				'bg_color'   => $settings['sale_bg_color'],
				'text_color' => $settings['sale_text_color'],
			);
		}

		// Check new badge.
		if ( 'yes' === $settings['new_enabled'] ) {
			$created = $product->get_date_created();
			if ( $created ) {
				$days_old = ( time() - $created->getTimestamp() ) / DAY_IN_SECONDS;
				$max_days = absint( $settings['new_duration_days'] );
				if ( $days_old <= $max_days ) {
					return array(
						'text'       => $settings['new_text'],
						'bg_color'   => $settings['new_bg_color'],
						'text_color' => $settings['new_text_color'],
					);
				}
			}
		}

		// Check featured badge.
		if ( 'yes' === $settings['featured_enabled'] && $product->is_featured() ) {
			return array(
				'text'       => $settings['featured_text'],
				'bg_color'   => $settings['featured_bg_color'],
				'text_color' => $settings['featured_text_color'],
			);
		}

		return false;
	}

	/**
	 * Render badge HTML.
	 *
	 * @param int $product_id Product ID.
	 */
	private function output_badge( $product_id ) {
		$badge = $this->get_badge_for_product( $product_id );
		if ( ! $badge ) {
			return;
		}

		$settings = $this->get_settings();
		$position = $settings['position'];

		$style = sprintf(
			'background-color:%s;color:%s;font-size:%dpx;border-radius:%dpx;',
			esc_attr( $badge['bg_color'] ),
			esc_attr( $badge['text_color'] ),
			absint( $settings['font_size'] ),
			absint( $settings['border_radius'] )
		);

		printf(
			'<span class="wc-custom-badge wc-badge-%s" style="%s">%s</span>',
			esc_attr( $position ),
			esc_attr( $style ),
			esc_html( $badge['text'] )
		);
	}

	/**
	 * Render badge on shop/loop pages.
	 */
	public function render_badge_loop() {
		global $product;
		if ( ! $product ) {
			return;
		}
		$this->output_badge( $product->get_id() );
	}

	/**
	 * Render badge on single product page.
	 */
	public function render_badge_single() {
		global $product;
		if ( ! $product ) {
			return;
		}
		$this->output_badge( $product->get_id() );
	}

	/**
	 * Enqueue frontend styles.
	 */
	public function enqueue_frontend_styles() {
		if ( ! is_product() && ! is_shop() && ! is_product_category() && ! is_product_tag() ) {
			return;
		}

		wp_add_inline_style( 'woocommerce-inline', $this->get_inline_css() );
	}

	/**
	 * Generate inline CSS for badge positioning.
	 *
	 * @return string
	 */
	private function get_inline_css() {
		$settings = $this->get_settings();
		$pos_map = array(
			'top-left'      => 'top:8px;left:8px;',
			'top-right'     => 'top:8px;right:8px;',
			'bottom-left'   => 'bottom:8px;left:8px;',
			'bottom-right'  => 'bottom:8px;right:8px;',
			'center'        => 'top:50%;left:50%;transform:translate(-50%,-50%);',
		);

		$css_rule = isset( $pos_map[ $settings['position'] ] ) ? $pos_map[ $settings['position'] ] : $pos_map['top-left'];

		return "
			li.product, .product, .woocommerce-product-gallery {
				position: relative;
			}
			.wc-custom-badge {
				position: absolute;
				z-index: 10;
				padding: 4px 10px;
				font-weight: 700;
				text-transform: uppercase;
				line-height: 1.4;
				letter-spacing: 0.5px;
				pointer-events: none;
				{$css_rule}
			}
		";
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public function enqueue_admin_assets( $hook_suffix ) {
		if ( 'post.php' !== $hook_suffix && 'post-new.php' !== $hook_suffix ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'product' !== $screen->id ) {
			return;
		}

		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wc-badges-admin', '', array( 'wp-color-picker' ), '1.0.0', true );
		wp_add_inline_script( 'wc-badges-admin', "
			jQuery(document).ready(function($){
				$('.wc-badge-color-picker').wpColorPicker();
			});
		" );
	}

	/**
	 * Add settings page.
	 */
	public function add_settings_page() {
		add_submenu_page(
			'woocommerce',
			esc_html__( 'Product Badges', 'wc-custom-badges' ),
			esc_html__( 'Product Badges', 'wc-custom-badges' ),
			'manage_woocommerce',
			'wc-custom-badges',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register settings.
	 */
	public function register_settings() {
		register_setting( 'wc_badges_group', self::OPTION_KEY, array(
			'sanitize_callback' => array( $this, 'sanitize_settings' ),
		) );
	}

	public function sanitize_settings( $input ) {
		$clean = array();
		$badge_types = array( 'sale', 'new', 'featured', 'custom' );

		foreach ( $badge_types as $type ) {
			$clean[ "{$type}_enabled" ]    = isset( $input[ "{$type}_enabled" ] ) ? 'yes' : 'no';
			$clean[ "{$type}_text" ]       = isset( $input[ "{$type}_text" ] ) ? sanitize_text_field( $input[ "{$type}_text" ] ) : '';
			$clean[ "{$type}_bg_color" ]   = isset( $input[ "{$type}_bg_color" ] ) ? sanitize_hex_color( $input[ "{$type}_bg_color" ] ) : '#000000';
			$clean[ "{$type}_text_color" ] = isset( $input[ "{$type}_text_color" ] ) ? sanitize_hex_color( $input[ "{$type}_text_color" ] ) : '#ffffff';
		}

		$clean['new_duration_days'] = isset( $input['new_duration_days'] ) ? absint( $input['new_duration_days'] ) : 30;

		$valid_positions = array_keys( $this->position_options() );
		$clean['position'] = isset( $input['position'] ) && in_array( $input['position'], $valid_positions, true )
			? $input['position']
			: 'top-left';

		$clean['font_size']     = isset( $input['font_size'] ) ? min( max( absint( $input['font_size'] ), 8 ), 32 ) : 12;
		$clean['border_radius'] = isset( $input['border_radius'] ) ? min( max( absint( $input['border_radius'] ), 0 ), 20 ) : 3;

		return $clean;
	}

	/**
	 * Render settings page.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'wc-custom-badges' ) );
		}

		$settings = $this->get_settings();
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'wc_badges_group' );
				wp_nonce_field( 'wc_badges_save', 'wc_badges_nonce' );
				?>

				<h2><?php esc_html_e( 'Badge Types', 'wc-custom-badges' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Configure the appearance and behavior of each badge type. Badges display in priority order: Sale > New > Featured > Custom.', 'wc-custom-badges' ); ?></p>

				<table class="form-table">
					<?php
					$badge_types = array(
						'sale'     => __( 'Sale Badge', 'wc-custom-badges' ),
						'new'      => __( 'New Badge', 'wc-custom-badges' ),
						'featured' => __( 'Featured Badge', 'wc-custom-badges' ),
						'custom'   => __( 'Custom Badge', 'wc-custom-badges' ),
					);

					foreach ( $badge_types as $type => $label ) :
						?>
						<tr>
							<th scope="row" colspan="2">
								<h3 style="margin:0;"><?php echo esc_html( $label ); ?></h3>
							</th>
						</tr>
						<tr>
							<th scope="row">
								<label><?php esc_html_e( 'Enable', 'wc-custom-badges' ); ?></label>
							</th>
							<td>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[<?php echo esc_attr( $type ); ?>_enabled]" value="yes" <?php checked( 'yes', $settings[ "{$type}_enabled" ] ); ?> />
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label><?php esc_html_e( 'Badge Text', 'wc-custom-badges' ); ?></label>
							</th>
							<td>
								<input type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[<?php echo esc_attr( $type ); ?>_text]" value="<?php echo esc_attr( $settings[ "{$type}_text" ] ); ?>" class="regular-text" />
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label><?php esc_html_e( 'Background Color', 'wc-custom-badges' ); ?></label>
							</th>
							<td>
								<input type="text" class="wc-badge-color-picker" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[<?php echo esc_attr( $type ); ?>_bg_color]" value="<?php echo esc_attr( $settings[ "{$type}_bg_color" ] ); ?>" data-default-color="<?php echo esc_attr( $settings[ "{$type}_bg_color" ] ); ?>" />
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label><?php esc_html_e( 'Text Color', 'wc-custom-badges' ); ?></label>
							</th>
							<td>
								<input type="text" class="wc-badge-color-picker" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[<?php echo esc_attr( $type ); ?>_text_color]" value="<?php echo esc_attr( $settings[ "{$type}_text_color" ] ); ?>" data-default-color="<?php echo esc_attr( $settings[ "{$type}_text_color" ] ); ?>" />
							</td>
						</tr>
						<?php if ( 'new' === $type ) : ?>
							<tr>
								<th scope="row">
									<label><?php esc_html_e( '"New" Duration (Days)', 'wc-custom-badges' ); ?></label>
								</th>
								<td>
									<input type="number" min="1" max="365" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[new_duration_days]" value="<?php echo esc_attr( $settings['new_duration_days'] ); ?>" class="small-text" />
									<p class="description"><?php esc_html_e( 'How many days a product is considered "New" after publication.', 'wc-custom-badges' ); ?></p>
								</td>
							</tr>
						<?php endif; ?>
						<?php
					endforeach;
					?>

					<tr>
						<th scope="row" colspan="2">
							<h3><?php esc_html_e( 'Display Settings', 'wc-custom-badges' ); ?></h3>
						</th>
					</tr>
					<tr>
						<th scope="row">
							<label><?php esc_html_e( 'Badge Position', 'wc-custom-badges' ); ?></label>
						</th>
						<td>
							<select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[position]">
								<?php foreach ( $this->position_options() as $val => $label ) : ?>
									<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $settings['position'], $val ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label><?php esc_html_e( 'Font Size (px)', 'wc-custom-badges' ); ?></label>
						</th>
						<td>
							<input type="number" min="8" max="32" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[font_size]" value="<?php echo esc_attr( $settings['font_size'] ); ?>" class="small-text" />
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label><?php esc_html_e( 'Border Radius (px)', 'wc-custom-badges' ); ?></label>
						</th>
						<td>
							<input type="number" min="0" max="20" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[border_radius]" value="<?php echo esc_attr( $settings['border_radius'] ); ?>" class="small-text" />
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Add product meta box.
	 */
	public function add_product_meta_box() {
		add_meta_box(
			'wc_badges_product',
			esc_html__( 'Product Badge Override', 'wc-custom-badges' ),
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
		wp_nonce_field( 'wc_badges_product_save', 'wc_badges_product_nonce' );

		$disable     = get_post_meta( $post->ID, self::META_DISABLE, true );
		$badge_type  = get_post_meta( $post->ID, self::META_TYPE, true );
		$badge_text  = get_post_meta( $post->ID, self::META_TEXT, true );
		$badge_color = get_post_meta( $post->ID, self::META_COLOR, true );
		$start_date  = get_post_meta( $post->ID, self::META_START, true );
		$expiry_date = get_post_meta( $post->ID, self::META_EXPIRY, true );

		$type_options = array(
			''         => __( '— Use Default Rules —', 'wc-custom-badges' ),
			'sale'     => __( 'Sale Badge', 'wc-custom-badges' ),
			'new'      => __( 'New Badge', 'wc-custom-badges' ),
			'featured' => __( 'Featured Badge', 'wc-custom-badges' ),
			'custom'   => __( 'Custom Badge', 'wc-custom-badges' ),
			'none'     => __( 'No Badge', 'wc-custom-badges' ),
		);
		?>
		<p>
			<label>
				<input type="checkbox" name="_wc_badge_disable" value="yes" <?php checked( 'yes', $disable ); ?> />
				<?php esc_html_e( 'Hide all badges for this product', 'wc-custom-badges' ); ?>
			</label>
		</p>
		<hr />
		<p>
			<label><strong><?php esc_html_e( 'Override Badge Type', 'wc-custom-badges' ); ?></strong></label><br />
			<select name="_wc_badge_type" style="width:100%;">
				<?php foreach ( $type_options as $val => $label ) : ?>
					<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $badge_type, $val ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label><strong><?php esc_html_e( 'Custom Badge Text', 'wc-custom-badges' ); ?></strong></label><br />
			<input type="text" name="_wc_badge_text" value="<?php echo esc_attr( $badge_text ); ?>" style="width:100%;" placeholder="<?php esc_attr_e( 'e.g. Best Seller', 'wc-custom-badges' ); ?>" />
		</p>
		<p>
			<label><strong><?php esc_html_e( 'Custom Badge Color', 'wc-custom-badges' ); ?></strong></label><br />
			<input type="text" class="wc-badge-color-picker" name="_wc_badge_color" value="<?php echo esc_attr( $badge_color ); ?>" style="width:100%;" />
		</p>
		<p>
			<label><strong><?php esc_html_e( 'Start Date', 'wc-custom-badges' ); ?></strong></label><br />
			<input type="date" name="_wc_badge_start_date" value="<?php echo esc_attr( $start_date ); ?>" style="width:100%;" />
			<span class="description"><?php esc_html_e( 'Badge appears on or after this date.', 'wc-custom-badges' ); ?></span>
		</p>
		<p>
			<label><strong><?php esc_html_e( 'Expiry Date', 'wc-custom-badges' ); ?></strong></label><br />
			<input type="date" name="_wc_badge_expiry_date" value="<?php echo esc_attr( $expiry_date ); ?>" style="width:100%;" />
			<span class="description"><?php esc_html_e( 'Badge disappears after this date.', 'wc-custom-badges' ); ?></span>
		</p>
		<?php
	}

	/**
	 * Save product meta box.
	 *
	 * @param int $post_id Post ID.
	 */
	public function save_product_meta_box( $post_id ) {
		if ( ! isset( $_POST['wc_badges_product_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wc_badges_product_nonce'] ) ), 'wc_badges_product_save' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$fields = array(
			self::META_DISABLE => array(
				'key'   => '_wc_badge_disable',
				'type'  => 'checkbox',
			),
			self::META_TYPE => array(
				'key'  => '_wc_badge_type',
				'type' => 'text',
			),
			self::META_TEXT => array(
				'key'  => '_wc_badge_text',
				'type' => 'text',
			),
			self::META_COLOR => array(
				'key'  => '_wc_badge_color',
				'type' => 'hex',
			),
			self::META_START => array(
				'key'  => '_wc_badge_start_date',
				'type' => 'date',
			),
			self::META_EXPIRY => array(
				'key'  => '_wc_badge_expiry_date',
				'type' => 'date',
			),
		);

		foreach ( $fields as $meta_key => $config ) {
			if ( ! isset( $_POST[ $config['key'] ] ) ) {
				if ( 'checkbox' === $config['type'] ) {
					update_post_meta( $post_id, $meta_key, 'no' );
				}
				continue;
			}

			$value = wp_unslash( $_POST[ $config['key'] ] );

			switch ( $config['type'] ) {
				case 'checkbox':
					$value = 'yes' === $value ? 'yes' : 'no';
					break;
				case 'text':
					$value = sanitize_text_field( $value );
					break;
				case 'hex':
					$value = sanitize_hex_color( $value );
					break;
				case 'date':
					$value = sanitize_text_field( $value );
					break;
			}

			update_post_meta( $post_id, $meta_key, $value );
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
			esc_url( admin_url( 'admin.php?page=wc-custom-badges' ) ),
			esc_html__( 'Settings', 'wc-custom-badges' )
		);
		array_unshift( $links, $settings_link );
		return $links;
	}
}

add_action( 'plugins_loaded', array( 'WC_Custom_Badges', 'instance' ) );

/**
 * Uninstall cleanup.
 */
register_uninstall_hook( __FILE__, 'wc_custom_badges_uninstall' );

function wc_custom_badges_uninstall() {
	delete_option( 'wc_custom_badges_settings' );

	$meta_keys = array(
		'_wc_badge_disable',
		'_wc_badge_type',
		'_wc_badge_text',
		'_wc_badge_color',
		'_wc_badge_start_date',
		'_wc_badge_expiry_date',
	);

	$products = get_posts( array(
		'post_type'      => 'product',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	) );

	foreach ( $products as $product_id ) {
		foreach ( $meta_keys as $key ) {
			delete_post_meta( $product_id, $key );
		}
	}
}