<?php
/**
 * Plugin Name: WooCommerce Product Extra Options
 * Plugin URI:  https://acidburn.dev/plugins/woocommerce-product-addons
 * Description: Add extra options to WooCommerce products — text fields, textareas, checkboxes, radio buttons, dropdown select, file upload, and date picker. Each option can carry a price (one-time or quantity-multiplied) and be required or optional. Supports reusable add-on groups for rapid application across products.
 * Author:      AcidBurn
 * Author URI:  https://acidburn.dev
 * Version:     1.0.0
 * Text Domain: wc-product-addons
 * Domain Path: /languages
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

defined( 'ABSPATH' ) || exit;

/**
 * --------------------------------------------------------------------------
 * Constants
 * --------------------------------------------------------------------------
 */
define( 'WCPA_VERSION', '1.0.0' );
define( 'WCPA_PLUGIN_FILE', __FILE__ );
define( 'WCPA_ABSPATH', dirname( __FILE__ ) . '/' );
define( 'WCPA_TEXT_DOMAIN', 'wc-product-addons' );

/**
 * --------------------------------------------------------------------------
 * Main Plugin Class
 * --------------------------------------------------------------------------
 */
final class WooCommerce_Product_Addons {

	/**
	 * Singleton instance.
	 *
	 * @var self
	 */
	private static $instance = null;

	/**
	 * Option name for global settings.
	 *
	 * @var string
	 */
	private $settings_option = 'wcpa_settings';

	/**
	 * Post type slug for reusable add-on groups.
	 *
	 * @var string
	 */
	private $group_post_type = 'wcpa_group';

	/**
	 * Meta key for per-product addons.
	 *
	 * @var string
	 */
	private $product_meta_key = '_wcpa_product_addons';

	/**
	 * Meta key for assigned groups.
	 *
	 * @var string
	 */
	private $groups_meta_key = '_wcpa_assigned_groups';

	// ---------------------------------------------------------------
	// Singleton
	// ---------------------------------------------------------------

	/**
	 * Return singleton instance.
	 */
	public static function get_instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	// ---------------------------------------------------------------
	// Constructor
	// ---------------------------------------------------------------

	private function __construct() {
		$this->init_hooks();
	}

	/**
	 * Hook into WordPress & WooCommerce.
	 */
	private function init_hooks() {
		// Plugin lifecycle
		register_activation_hook( WCPA_PLUGIN_FILE, array( $this, 'activate' ) );
		register_deactivation_hook( WCPA_PLUGIN_FILE, array( $this, 'deactivate' ) );
		register_uninstall_hook( WCPA_PLUGIN_FILE, array( __CLASS__, 'uninstall' ) );

		add_action( 'init', array( $this, 'init' ) );

		// Admin
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ), 99 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_product_meta_box' ) );
		add_action( 'save_post_product', array( $this, 'save_product_meta_box' ), 10, 2 );
		add_action( 'save_post_product_variation', array( $this, 'save_product_meta_box' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
		add_filter( 'manage_edit-wcpa_group_columns', array( $this, 'group_list_columns' ) );
		add_action( 'manage_wcpa_group_posts_custom_column', array( $this, 'group_list_column_content' ), 10, 2 );

		// Front-end display
		add_action( 'wp_enqueue_scripts', array( $this, 'frontend_enqueue_scripts' ) );
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'display_product_addons' ) );

		// Cart integration
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_cart_item_data' ), 10, 3 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'display_cart_item_data' ), 10, 2 );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_cart_item_price' ), 10, 1 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'save_order_item_meta' ), 10, 4 );

		// Order display (admin & emails)
		add_filter( 'woocommerce_order_item_display_meta_value', array( $this, 'order_item_display_meta_value' ), 10, 3 );
		add_action( 'woocommerce_order_item_meta_end', array( $this, 'order_item_meta_end' ), 10, 4 );

		// File upload AJAX
		add_action( 'wp_ajax_wcpa_upload_file', array( $this, 'ajax_upload_file' ) );
		add_action( 'wp_ajax_nopriv_wcpa_upload_file', array( $this, 'ajax_upload_file' ) );

		// Group post type management
		add_action( 'save_post_wcpa_group', array( $this, 'save_group_post' ), 10, 3 );
	}

	// ---------------------------------------------------------------
	// Plugin Lifecycle
	// ---------------------------------------------------------------

	public function activate() {
		// Create default global settings
		if ( false === get_option( $this->settings_option ) ) {
			add_option( $this->settings_option, array(
				'enable_file_uploads'    => 'yes',
				'max_file_size'          => 2,  // MB
				'allowed_file_types'     => 'jpg,jpeg,png,gif,pdf,doc,docx',
				'date_format'            => 'Y-m-d',
				'display_label_position' => 'above',
			) );
		}

		// Flush rewrite rules for the group post type
		$this->register_group_post_type();
		flush_rewrite_rules();
	}

	public function deactivate() {
		flush_rewrite_rules();
	}

	/**
	 * Uninstall hook — clean up all plugin data.
	 */
	public static function uninstall() {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			exit;
		}

		// Remove global settings
		delete_option( 'wcpa_settings' );

		// Remove product meta
		delete_post_meta_by_key( '_wcpa_product_addons' );
		delete_post_meta_by_key( '_wcpa_assigned_groups' );

		// Delete all add-on group posts
		$groups = get_posts( array(
			'post_type'      => 'wcpa_group',
			'posts_per_page' => -1,
			'post_status'    => 'any',
			'fields'         => 'ids',
		) );
		foreach ( $groups as $group_id ) {
			wp_delete_post( $group_id, true );
		}
	}

	// ---------------------------------------------------------------
	// Init
	// ---------------------------------------------------------------

	public function init() {
		$this->register_group_post_type();
		$this->load_textdomain();

		// Handle file upload cleanup on cart removal
		add_action( 'woocommerce_remove_cart_item', array( $this, 'cleanup_cart_uploads' ), 10, 2 );
	}

	/**
	 * Register the reusable add-on group custom post type.
	 */
	private function register_group_post_type() {
		register_post_type( $this->group_post_type, array(
			'labels'              => array(
				'name'               => __( 'Add-on Groups', WCPA_TEXT_DOMAIN ),
				'singular_name'      => __( 'Add-on Group', WCPA_TEXT_DOMAIN ),
				'add_new'            => __( 'Add New Group', WCPA_TEXT_DOMAIN ),
				'add_new_item'       => __( 'Add New Add-on Group', WCPA_TEXT_DOMAIN ),
				'edit_item'          => __( 'Edit Add-on Group', WCPA_TEXT_DOMAIN ),
				'new_item'           => __( 'New Add-on Group', WCPA_TEXT_DOMAIN ),
				'view_item'          => __( 'View Add-on Group', WCPA_TEXT_DOMAIN ),
				'search_items'       => __( 'Search Add-on Groups', WCPA_TEXT_DOMAIN ),
				'not_found'          => __( 'No add-on groups found', WCPA_TEXT_DOMAIN ),
				'not_found_in_trash' => __( 'No add-on groups found in Trash', WCPA_TEXT_DOMAIN ),
				'all_items'          => __( 'Add-on Groups', WCPA_TEXT_DOMAIN ),
				'menu_name'          => __( 'Add-on Groups', WCPA_TEXT_DOMAIN ),
			),
			'public'              => false,
			'show_ui'             => true,
			'show_in_menu'        => false, // We will add under WooCommerce manually
			'supports'            => array( 'title' ),
			'rewrite'             => false,
			'capability_type'     => 'post',
			'map_meta_cap'        => true,
			'show_in_rest'        => false,
		) );
	}

	/**
	 * Load text domain.
	 */
	private function load_textdomain() {
		load_plugin_textdomain( WCPA_TEXT_DOMAIN, false, dirname( plugin_basename( WCPA_PLUGIN_FILE ) ) . '/languages' );
	}

	// ---------------------------------------------------------------
	// Admin Menu & Settings
	// ---------------------------------------------------------------

	/**
	 * Add admin menu pages under WooCommerce.
	 */
	public function add_admin_menu() {
		// Top-level settings page
		add_submenu_page(
			'woocommerce',
			__( 'Product Add-Ons', WCPA_TEXT_DOMAIN ),
			__( 'Product Add-Ons', WCPA_TEXT_DOMAIN ),
			'manage_woocommerce',
			'wcpa-settings',
			array( $this, 'render_settings_page' )
		);

		// Sub-menu link to group post type list
		add_submenu_page(
			'woocommerce',
			__( 'Add-on Groups', WCPA_TEXT_DOMAIN ),
			__( 'Add-on Groups', WCPA_TEXT_DOMAIN ),
			'manage_woocommerce',
			'edit.php?post_type=wcpa_group'
		);
	}

	/**
	 * Register the plugin settings using the Settings API.
	 */
	public function register_settings() {
		register_setting(
			'wcpa_settings_group',
			$this->settings_option,
			array(
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
			)
		);

		// Settings section
		add_settings_section(
			'wcpa_general_section',
			__( 'General Settings', WCPA_TEXT_DOMAIN ),
			array( $this, 'render_settings_section_desc' ),
			'wcpa-settings'
		);

		// Fields
		add_settings_field(
			'enable_file_uploads',
			__( 'Enable File Uploads', WCPA_TEXT_DOMAIN ),
			array( $this, 'render_settings_field' ),
			'wcpa-settings',
			'wcpa_general_section',
			array(
				'id'      => 'enable_file_uploads',
				'type'    => 'checkbox',
				'default' => 'yes',
			)
		);

		add_settings_field(
			'max_file_size',
			__( 'Max File Size (MB)', WCPA_TEXT_DOMAIN ),
			array( $this, 'render_settings_field' ),
			'wcpa-settings',
			'wcpa_general_section',
			array(
				'id'      => 'max_file_size',
				'type'    => 'number',
				'default' => 2,
			)
		);

		add_settings_field(
			'allowed_file_types',
			__( 'Allowed File Types', WCPA_TEXT_DOMAIN ),
			array( $this, 'render_settings_field' ),
			'wcpa-settings',
			'wcpa_general_section',
			array(
				'id'      => 'allowed_file_types',
				'type'    => 'text',
				'default' => 'jpg,jpeg,png,gif,pdf,doc,docx',
			)
		);

		add_settings_field(
			'date_format',
			__( 'Date Picker Format', WCPA_TEXT_DOMAIN ),
			array( $this, 'render_settings_field' ),
			'wcpa-settings',
			'wcpa_general_section',
			array(
				'id'      => 'date_format',
				'type'    => 'text',
				'default' => 'Y-m-d',
			)
		);

		add_settings_field(
			'display_label_position',
			__( 'Label Position', WCPA_TEXT_DOMAIN ),
			array( $this, 'render_settings_field' ),
			'wcpa-settings',
			'wcpa_general_section',
			array(
				'id'      => 'display_label_position',
				'type'    => 'select',
				'default' => 'above',
				'options' => array(
					'above'  => __( 'Above field', WCPA_TEXT_DOMAIN ),
					'left'   => __( 'Left of field', WCPA_TEXT_DOMAIN ),
					'below'  => __( 'Below field', WCPA_TEXT_DOMAIN ),
					'hidden' => __( 'Hidden (placeholder only)', WCPA_TEXT_DOMAIN ),
				),
			)
		);
	}

	/**
	 * Sanitize settings callback.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		if ( ! is_array( $input ) ) {
			return array();
		}

		$sanitized                      = array();
		$sanitized['enable_file_uploads'] = isset( $input['enable_file_uploads'] ) ? sanitize_text_field( $input['enable_file_uploads'] ) : 'no';
		$sanitized['max_file_size']     = isset( $input['max_file_size'] ) ? absint( $input['max_file_size'] ) : 2;
		$sanitized['allowed_file_types'] = isset( $input['allowed_file_types'] ) ? sanitize_text_field( $input['allowed_file_types'] ) : 'jpg,jpeg,png,gif,pdf,doc,docx';
		$sanitized['date_format']       = isset( $input['date_format'] ) ? sanitize_text_field( $input['date_format'] ) : 'Y-m-d';
		$sanitized['display_label_position'] = isset( $input['display_label_position'] ) ? sanitize_key( $input['display_label_position'] ) : 'above';

		return $sanitized;
	}

	/**
	 * Render settings section description.
	 */
	public function render_settings_section_desc() {
		echo '<p>' . esc_html__( 'Configure global settings for WooCommerce Product Extra Options.', WCPA_TEXT_DOMAIN ) . '</p>';
	}

	/**
	 * Render a settings field.
	 *
	 * @param array $args Field arguments.
	 */
	public function render_settings_field( $args ) {
		$settings = get_option( $this->settings_option, array() );
		$id       = isset( $args['id'] ) ? sanitize_key( $args['id'] ) : '';
		$type     = isset( $args['type'] ) ? sanitize_key( $args['type'] ) : 'text';
		$default  = isset( $args['default'] ) ? $args['default'] : '';
		$value    = isset( $settings[ $id ] ) ? $settings[ $id ] : $default;

		$name = esc_attr( $this->settings_option . '[' . $id . ']' );

		switch ( $type ) {
			case 'checkbox':
				?>
				<label>
					<input type="checkbox" name="<?php echo $name; ?>" value="yes" <?php checked( $value, 'yes' ); ?> />
					<?php esc_html_e( 'Enable file upload functionality for add-ons', WCPA_TEXT_DOMAIN ); ?>
				</label>
				<?php
				break;

			case 'number':
				?>
				<input type="number" name="<?php echo $name; ?>" value="<?php echo esc_attr( $value ); ?>" class="small-text" min="0" step="0.5" />
				<?php
				break;

			case 'select':
				$options = isset( $args['options'] ) && is_array( $args['options'] ) ? $args['options'] : array();
				?>
				<select name="<?php echo $name; ?>">
					<?php foreach ( $options as $opt_val => $opt_label ) : ?>
						<option value="<?php echo esc_attr( $opt_val ); ?>" <?php selected( $value, $opt_val ); ?>><?php echo esc_html( $opt_label ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php
				break;

			case 'text':
			default:
				?>
				<input type="text" name="<?php echo $name; ?>" value="<?php echo esc_attr( $value ); ?>" class="regular-text" />
				<?php
				break;
		}
	}

	/**
	 * Render the settings page.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', WCPA_TEXT_DOMAIN ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Product Add-Ons Settings', WCPA_TEXT_DOMAIN ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'wcpa_settings_group' );
				do_settings_sections( 'wcpa-settings' );
				submit_button();
				?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Reusable Add-on Groups', WCPA_TEXT_DOMAIN ); ?></h2>
			<p>
				<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=wcpa_group' ) ); ?>" class="button button-primary">
					<?php esc_html_e( 'Create New Add-on Group', WCPA_TEXT_DOMAIN ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=wcpa_group' ) ); ?>" class="button">
					<?php esc_html_e( 'View All Groups', WCPA_TEXT_DOMAIN ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	// ---------------------------------------------------------------
	// Add-on Group List Columns
	// ---------------------------------------------------------------

	/**
	 * Add shortcode column to group list.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function group_list_columns( $columns ) {
		$columns['wcpa_shortcode'] = __( 'Shortcode', WCPA_TEXT_DOMAIN );
		$columns['wcpa_count']     = __( 'Options Count', WCPA_TEXT_DOMAIN );
		return $columns;
	}

	/**
	 * Render custom column content.
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 */
	public function group_list_column_content( $column, $post_id ) {
		switch ( $column ) {
			case 'wcpa_shortcode':
				$shortcode = '[wcpa_group id="' . (int) $post_id . '"]';
				echo '<code>' . esc_html( $shortcode ) . '</code>';
				break;

			case 'wcpa_count':
				$addons = get_post_meta( $post_id, '_wcpa_group_addons', true );
				if ( is_array( $addons ) ) {
					echo count( $addons );
				} else {
					echo '0';
				}
				break;
		}
	}

	// ---------------------------------------------------------------
	// Product Meta Box
	// ---------------------------------------------------------------

	/**
	 * Add meta box to product edit screen.
	 */
	public function add_product_meta_box() {
		add_meta_box(
			'wcpa_product_addons_metabox',
			__( 'Product Extra Options', WCPA_TEXT_DOMAIN ),
			array( $this, 'render_product_meta_box' ),
			'product',
			'normal',
			'default'
		);
	}

	/**
	 * Render the product meta box.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public function render_product_meta_box( $post ) {
		// Nonce
		wp_nonce_field( 'wcpa_save_product_addons', 'wcpa_product_addons_nonce' );

		$product_id      = $post->ID;
		$product_addons  = get_post_meta( $product_id, $this->product_meta_key, true );
		$assigned_groups = get_post_meta( $product_id, $this->groups_meta_key, true );

		if ( ! is_array( $product_addons ) ) {
			$product_addons = array();
		}
		if ( ! is_array( $assigned_groups ) ) {
			$assigned_groups = array();
		}

		$settings      = get_option( $this->settings_option, array() );
		$enable_uploads = isset( $settings['enable_file_uploads'] ) ? $settings['enable_file_uploads'] : 'yes';

		?>
		<div class="wcpa-metabox-wrapper">
			<p>
				<label>
					<input type="checkbox" name="wcpa_disable_all" value="1" <?php checked( get_post_meta( $product_id, '_wcpa_disable_all', true ), '1' ); ?> />
					<?php esc_html_e( 'Disable all add-ons for this product', WCPA_TEXT_DOMAIN ); ?>
				</label>
			</p>

			<!-- Reusable Groups Assignment -->
			<div class="wcpa-groups-section" style="margin-bottom:20px;padding:12px;background:#f8f9fa;border:1px solid #ddd;">
				<h3><?php esc_html_e( 'Assign Reusable Add-on Groups', WCPA_TEXT_DOMAIN ); ?></h3>
				<?php
				$all_groups = get_posts( array(
					'post_type'      => $this->group_post_type,
					'posts_per_page' => -1,
					'post_status'    => 'publish',
					'orderby'        => 'title',
					'order'          => 'ASC',
				) );
				?>
				<select name="wcpa_assigned_groups[]" multiple style="width:100%;min-height:100px;">
					<?php foreach ( $all_groups as $group ) : ?>
						<option value="<?php echo esc_attr( $group->ID ); ?>" <?php echo in_array( $group->ID, $assigned_groups, true ) ? 'selected' : ''; ?>>
							<?php echo esc_html( $group->post_title . ' (ID: ' . $group->ID . ')' ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php esc_html_e( 'Hold Ctrl/Cmd to select multiple groups. Options from assigned groups will appear on this product.', WCPA_TEXT_DOMAIN ); ?></p>
				<?php if ( empty( $all_groups ) ) : ?>
					<p><em><?php esc_html_e( 'No groups created yet.', WCPA_TEXT_DOMAIN ); ?></em></p>
				<?php endif; ?>
			</div>

			<!-- Per-Product Custom Add-ons -->
			<h3><?php esc_html_e( 'Per-Product Custom Add-ons', WCPA_TEXT_DOMAIN ); ?></h3>
			<p class="description"><?php esc_html_e( 'Define custom add-ons specific to this product below.', WCPA_TEXT_DOMAIN ); ?></p>
			<div id="wcpa-addons-container">
				<?php
				if ( ! empty( $product_addons ) ) {
					foreach ( $product_addons as $index => $addon ) {
						$this->render_addon_row( $index, $addon, $enable_uploads );
					}
				}
				?>
			</div>

			<div style="margin-top:12px;">
				<button type="button" class="button button-primary" id="wcpa-add-addon-row">
					<?php esc_html_e( '+ Add Option', WCPA_TEXT_DOMAIN ); ?>
				</button>
			</div>

			<!-- Empty template for JS cloning -->
			<script type="text/html" id="tmpl-wcpa-addon-row">
				<?php $this->render_addon_row( '{{INDEX}}', array(), $enable_uploads ); ?>
			</script>
		</div>

		<style>
			.wcpa-addon-row {
				background: #fff;
				border: 1px solid #ddd;
				padding: 15px;
				margin-bottom: 12px;
				position: relative;
			}
			.wcpa-addon-row .wcpa-row-header {
				display: flex;
				justify-content: space-between;
				align-items: center;
				margin-bottom: 10px;
			}
			.wcpa-addon-row .wcpa-row-header strong {
				font-size: 14px;
			}
			.wcpa-addon-row .wcpa-field-row {
				display: flex;
				flex-wrap: wrap;
				gap: 10px;
				margin-bottom: 8px;
			}
			.wcpa-addon-row .wcpa-field-row label {
				flex: 1 0 150px;
				font-weight: 600;
				font-size: 12px;
			}
			.wcpa-addon-row .wcpa-field-row input,
			.wcpa-addon-row .wcpa-field-row select,
			.wcpa-addon-row .wcpa-field-row textarea {
				flex: 2 0 200px;
			}
			.wcpa-addon-row .wcpa-options-list {
				margin: 10px 0 0 20px;
				padding: 8px;
				background: #f5f5f5;
				border-left: 3px solid #007cba;
			}
			.wcpa-addon-row .wcpa-option-item {
				display: flex;
				gap: 8px;
				align-items: center;
				margin-bottom: 6px;
			}
			.wcpa-remove-addon,
			.wcpa-remove-option {
				color: #b32d2e;
				cursor: pointer;
				text-decoration: none;
			}
			.wcpa-remove-addon:hover,
			.wcpa-remove-option:hover {
				color: #8a1f1f;
			}
		</style>

		<script>
		( function( $ ) {
			var index = <?php echo count( $product_addons ); ?>;
			var template = $( '#tmpl-wcpa-addon-row' ).html();

			$( '#wcpa-add-addon-row' ).on( 'click', function( e ) {
				e.preventDefault();
				var row = template.replace( /\{\{INDEX\}\}/g, index );
				$( '#wcpa-addons-container' ).append( row );
				index++;
			} );

			$( '#wcpa-addons-container' ).on( 'click', '.wcpa-remove-addon', function( e ) {
				e.preventDefault();
				if ( confirm( '<?php echo esc_js( __( 'Remove this add-on option?', WCPA_TEXT_DOMAIN ) ); ?>' ) ) {
					$( this ).closest( '.wcpa-addon-row' ).remove();
				}
			} );

			$( '#wcpa-addons-container' ).on( 'change', '.wcpa-field-type', function() {
				var row = $( this ).closest( '.wcpa-addon-row' );
				var type = $( this ).val();
				var optionsWrap = row.find( '.wcpa-options-list' );
				if ( [ 'checkbox', 'radio', 'select' ].indexOf( type ) !== -1 ) {
					optionsWrap.show();
				} else {
					optionsWrap.hide();
				}
				if ( type === 'file' ) {
					row.find( '.wcpa-price-type' ).val( 'one_time' );
				}
			} );

			$( '#wcpa-addons-container' ).on( 'click', '.wcpa-add-option', function( e ) {
				e.preventDefault();
				var list = $( this ).closest( '.wcpa-addon-row' ).find( '.wcpa-options-list-inner' );
				var count = list.children().length;
				var rowIdx = $( this ).closest( '.wcpa-addon-row' ).data( 'index' );
				if ( typeof rowIdx === 'undefined' ) {
					rowIdx = $( this ).closest( '.wcpa-addon-row' ).find( '.wcpa-field-type' ).attr( 'name' ).match( /\[(\d+)\]/ );
					rowIdx = rowIdx ? rowIdx[1] : count;
				}
				var html = '<div class="wcpa-option-item">';
				html += '<input type="text" name="wcpa_product_addons[' + rowIdx + '][options][' + count + '][label]" value="" placeholder="<?php esc_attr_e( 'Option label', WCPA_TEXT_DOMAIN ); ?>" style="flex:2;" />';
				html += '<input type="number" name="wcpa_product_addons[' + rowIdx + '][options][' + count + '][price]" value="" placeholder="<?php esc_attr_e( 'Price', WCPA_TEXT_DOMAIN ); ?>" step="0.01" min="0" style="width:100px;" />';
				html += '<input type="text" name="wcpa_product_addons[' + rowIdx + '][options][' + count + '][price_label]" value="" placeholder="<?php esc_attr_e( 'Label suffix e.g. +$10', WCPA_TEXT_DOMAIN ); ?>" style="flex:1.5;" />';
				html += ' <a href="#" class="wcpa-remove-option"><?php echo esc_js( __( 'Remove', WCPA_TEXT_DOMAIN ) ); ?></a>';
				html += '</div>';
				list.append( html );
			} );

			$( '#wcpa-addons-container' ).on( 'click', '.wcpa-remove-option', function( e ) {
				e.preventDefault();
				$( this ).closest( '.wcpa-option-item' ).remove();
			} );

			// Toggle options on page load
			$( '.wcpa-field-type' ).each( function() {
				var row = $( this ).closest( '.wcpa-addon-row' );
				var type = $( this ).val();
				var optionsWrap = row.find( '.wcpa-options-list' );
				if ( [ 'checkbox', 'radio', 'select' ].indexOf( type ) !== -1 ) {
					optionsWrap.show();
				} else {
					optionsWrap.hide();
				}
			} );
		} )( jQuery );
		</script>
		<?php
	}

	/**
	 * Render a single add-on row (used by meta box and JS template).
	 *
	 * @param int   $index         Row index.
	 * @param array $addon         Add-on data.
	 * @param string $enable_uploads Whether uploads are enabled.
	 */
	private function render_addon_row( $index, $addon, $enable_uploads = 'yes' ) {
		$type         = isset( $addon['type'] ) ? $addon['type'] : 'text';
		$label        = isset( $addon['label'] ) ? $addon['label'] : '';
		$name_key     = isset( $addon['name_key'] ) ? $addon['name_key'] : '';
		$placeholder  = isset( $addon['placeholder'] ) ? $addon['placeholder'] : '';
		$required     = isset( $addon['required'] ) ? $addon['required'] : '';
		$price        = isset( $addon['price'] ) ? $addon['price'] : '';
		$price_type   = isset( $addon['price_type'] ) ? $addon['price_type'] : 'one_time';
		$description  = isset( $addon['description'] ) ? $addon['description'] : '';
		$options      = isset( $addon['options'] ) && is_array( $addon['options'] ) ? $addon['options'] : array();
		$max_chars    = isset( $addon['max_chars'] ) ? $addon['max_chars'] : '';
		$default_val  = isset( $addon['default_value'] ) ? $addon['default_value'] : '';

		$name_prefix = 'wcpa_product_addons[' . $index . ']';
		?>
		<div class="wcpa-addon-row" data-index="<?php echo esc_attr( $index ); ?>">
			<div class="wcpa-row-header">
				<strong><?php printf( esc_html__( 'Option #%d', WCPA_TEXT_DOMAIN ), (int) $index + 1 ); ?></strong>
				<a href="#" class="wcpa-remove-addon"><?php esc_html_e( 'Remove', WCPA_TEXT_DOMAIN ); ?></a>
			</div>

			<div class="wcpa-field-row">
				<label><?php esc_html_e( 'Type', WCPA_TEXT_DOMAIN ); ?>
					<select name="<?php echo esc_attr( $name_prefix ); ?>[type]" class="wcpa-field-type">
						<option value="text"     <?php selected( $type, 'text' ); ?>><?php esc_html_e( 'Text Field', WCPA_TEXT_DOMAIN ); ?></option>
						<option value="textarea" <?php selected( $type, 'textarea' ); ?>><?php esc_html_e( 'Textarea', WCPA_TEXT_DOMAIN ); ?></option>
						<option value="checkbox" <?php selected( $type, 'checkbox' ); ?>><?php esc_html_e( 'Checkboxes', WCPA_TEXT_DOMAIN ); ?></option>
						<option value="radio"    <?php selected( $type, 'radio' ); ?>><?php esc_html_e( 'Radio Buttons', WCPA_TEXT_DOMAIN ); ?></option>
						<option value="select"   <?php selected( $type, 'select' ); ?>><?php esc_html_e( 'Dropdown Select', WCPA_TEXT_DOMAIN ); ?></option>
						<?php if ( 'yes' === $enable_uploads ) : ?>
							<option value="file"  <?php selected( $type, 'file' ); ?>><?php esc_html_e( 'File Upload', WCPA_TEXT_DOMAIN ); ?></option>
						<?php endif; ?>
						<option value="date"     <?php selected( $type, 'date' ); ?>><?php esc_html_e( 'Date Picker', WCPA_TEXT_DOMAIN ); ?></option>
					</select>
				</label>

				<label><?php esc_html_e( 'Label', WCPA_TEXT_DOMAIN ); ?>
					<input type="text" name="<?php echo esc_attr( $name_prefix ); ?>[label]" value="<?php echo esc_attr( $label ); ?>" />
				</label>

				<label><?php esc_html_e( 'Name Key', WCPA_TEXT_DOMAIN ); ?>
					<input type="text" name="<?php echo esc_attr( $name_prefix ); ?>[name_key]" value="<?php echo esc_attr( $name_key ); ?>" placeholder="slug-like-key" />
				</label>
			</div>

			<div class="wcpa-field-row">
				<label><?php esc_html_e( 'Placeholder', WCPA_TEXT_DOMAIN ); ?>
					<input type="text" name="<?php echo esc_attr( $name_prefix ); ?>[placeholder]" value="<?php echo esc_attr( $placeholder ); ?>" />
				</label>

				<label><?php esc_html_e( 'Default Value', WCPA_TEXT_DOMAIN ); ?>
					<input type="text" name="<?php echo esc_attr( $name_prefix ); ?>[default_value]" value="<?php echo esc_attr( $default_val ); ?>" />
				</label>

				<label><?php esc_html_e( 'Required', WCPA_TEXT_DOMAIN ); ?>
					<input type="checkbox" name="<?php echo esc_attr( $name_prefix ); ?>[required]" value="1" <?php checked( $required, '1' ); ?> />
				</label>
			</div>

			<div class="wcpa-field-row">
				<label><?php esc_html_e( 'Price', WCPA_TEXT_DOMAIN ); ?>
					<input type="number" name="<?php echo esc_attr( $name_prefix ); ?>[price]" value="<?php echo esc_attr( $price ); ?>" step="0.01" min="0" style="width:120px;" />
				</label>

				<label><?php esc_html_e( 'Price Type', WCPA_TEXT_DOMAIN ); ?>
					<select name="<?php echo esc_attr( $name_prefix ); ?>[price_type]" class="wcpa-price-type">
						<option value="one_time" <?php selected( $price_type, 'one_time' ); ?>><?php esc_html_e( 'One-time', WCPA_TEXT_DOMAIN ); ?></option>
						<option value="multiply" <?php selected( $price_type, 'multiply' ); ?>><?php esc_html_e( 'Multiply by quantity', WCPA_TEXT_DOMAIN ); ?></option>
					</select>
				</label>

				<label><?php esc_html_e( 'Max Characters', WCPA_TEXT_DOMAIN ); ?>
					<input type="number" name="<?php echo esc_attr( $name_prefix ); ?>[max_chars]" value="<?php echo esc_attr( $max_chars ); ?>" min="0" style="width:100px;" />
				</label>
			</div>

			<div class="wcpa-field-row">
				<label style="flex:0 0 100%;"><?php esc_html_e( 'Description (shown below field)', WCPA_TEXT_DOMAIN ); ?></label>
				<textarea name="<?php echo esc_attr( $name_prefix ); ?>[description]" rows="2" style="width:100%;"><?php echo esc_textarea( $description ); ?></textarea>
			</div>

			<!-- Options for checkbox / radio / select -->
			<div class="wcpa-options-list" style="display:none;">
				<h4><?php esc_html_e( 'Options', WCPA_TEXT_DOMAIN ); ?> <button type="button" class="button button-small wcpa-add-option"><?php esc_html_e( '+ Add Option', WCPA_TEXT_DOMAIN ); ?></button></h4>
				<div class="wcpa-options-list-inner">
					<?php
					if ( ! empty( $options ) ) {
						foreach ( $options as $opt_idx => $option ) {
							$opt_label      = isset( $option['label'] ) ? $option['label'] : '';
							$opt_price      = isset( $option['price'] ) ? $option['price'] : '';
							$opt_price_label = isset( $option['price_label'] ) ? $option['price_label'] : '';
							?>
							<div class="wcpa-option-item">
								<input type="text" name="<?php echo esc_attr( $name_prefix ); ?>[options][<?php echo esc_attr( $opt_idx ); ?>][label]" value="<?php echo esc_attr( $opt_label ); ?>" placeholder="<?php esc_attr_e( 'Option label', WCPA_TEXT_DOMAIN ); ?>" style="flex:2;" />
								<input type="number" name="<?php echo esc_attr( $name_prefix ); ?>[options][<?php echo esc_attr( $opt_idx ); ?>][price]" value="<?php echo esc_attr( $opt_price ); ?>" placeholder="<?php esc_attr_e( 'Price', WCPA_TEXT_DOMAIN ); ?>" step="0.01" min="0" style="width:100px;" />
								<input type="text" name="<?php echo esc_attr( $name_prefix ); ?>[options][<?php echo esc_attr( $opt_idx ); ?>][price_label]" value="<?php echo esc_attr( $opt_price_label ); ?>" placeholder="<?php esc_attr_e( 'Label suffix e.g. +$10', WCPA_TEXT_DOMAIN ); ?>" style="flex:1.5;" />
								<a href="#" class="wcpa-remove-option"><?php esc_html_e( 'Remove', WCPA_TEXT_DOMAIN ); ?></a>
							</div>
							<?php
						}
					}
					?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Save product meta box data.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public function save_product_meta_box( $post_id, $post ) {
		// Security check
		if ( ! isset( $_POST['wcpa_product_addons_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wcpa_product_addons_nonce'] ) ), 'wcpa_save_product_addons' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// Disable all
		$disable = isset( $_POST['wcpa_disable_all'] ) ? '1' : '';
		update_post_meta( $post_id, '_wcpa_disable_all', sanitize_text_field( $disable ) );

		// Assigned groups
		$assigned_groups = isset( $_POST['wcpa_assigned_groups'] ) && is_array( $_POST['wcpa_assigned_groups'] )
			? array_map( 'absint', wp_unslash( $_POST['wcpa_assigned_groups'] ) )
			: array();
		update_post_meta( $post_id, $this->groups_meta_key, $assigned_groups );

		// Per-product addons
		$addons = isset( $_POST['wcpa_product_addons'] ) && is_array( $_POST['wcpa_product_addons'] )
			? $this->sanitize_addons( wp_unslash( $_POST['wcpa_product_addons'] ) )
			: array();
		update_post_meta( $post_id, $this->product_meta_key, $addons );
	}

	/**
	 * Sanitize add-ons array recursively.
	 *
	 * @param array $addons Raw add-ons data.
	 * @return array
	 */
	private function sanitize_addons( $addons ) {
		$sanitized = array();

		foreach ( $addons as $index => $addon ) {
			if ( ! is_array( $addon ) ) {
				continue;
			}

			$sanitized[ $index ] = array(
				'type'          => isset( $addon['type'] ) ? sanitize_text_field( $addon['type'] ) : 'text',
				'label'         => isset( $addon['label'] ) ? sanitize_text_field( $addon['label'] ) : '',
				'name_key'      => isset( $addon['name_key'] ) ? sanitize_key( $addon['name_key'] ) : '',
				'placeholder'   => isset( $addon['placeholder'] ) ? sanitize_text_field( $addon['placeholder'] ) : '',
				'default_value' => isset( $addon['default_value'] ) ? sanitize_text_field( $addon['default_value'] ) : '',
				'required'      => isset( $addon['required'] ) ? '1' : '',
				'price'         => isset( $addon['price'] ) ? floatval( $addon['price'] ) : 0,
				'price_type'    => isset( $addon['price_type'] ) && 'multiply' === $addon['price_type'] ? 'multiply' : 'one_time',
				'max_chars'     => isset( $addon['max_chars'] ) ? absint( $addon['max_chars'] ) : 0,
				'description'   => isset( $addon['description'] ) ? sanitize_textarea_field( $addon['description'] ) : '',
				'options'       => array(),
			);

			// Sanitize sub-options for select/checkbox/radio
			if ( isset( $addon['options'] ) && is_array( $addon['options'] ) ) {
				foreach ( $addon['options'] as $opt_idx => $option ) {
					if ( ! is_array( $option ) ) {
						continue;
					}
					$sanitized[ $index ]['options'][ $opt_idx ] = array(
						'label'       => isset( $option['label'] ) ? sanitize_text_field( $option['label'] ) : '',
						'price'       => isset( $option['price'] ) ? floatval( $option['price'] ) : 0,
						'price_label' => isset( $option['price_label'] ) ? sanitize_text_field( $option['price_label'] ) : '',
					);
				}
			}
		}

		return $sanitized;
	}

	// ---------------------------------------------------------------
	// Save Group Post (reusable groups)
	// ---------------------------------------------------------------

	/**
	 * Save add-on group data.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @param bool    $update  Whether this is an update.
	 */
	public function save_group_post( $post_id, $post, $update ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( ! isset( $_POST['wcpa_group_addons_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wcpa_group_addons_nonce'] ) ), 'wcpa_save_group_addons' ) ) {
			return;
		}

		$addons = isset( $_POST['wcpa_group_addons'] ) && is_array( $_POST['wcpa_group_addons'] )
			? $this->sanitize_addons( wp_unslash( $_POST['wcpa_group_addons'] ) )
			: array();

		update_post_meta( $post_id, '_wcpa_group_addons', $addons );
	}

	// ---------------------------------------------------------------
	// Admin Enqueue Scripts
	// ---------------------------------------------------------------

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @param string $hook Current admin page.
	 */
	public function admin_enqueue_scripts( $hook ) {
		global $post;

		// Only on product edit or group edit / settings page
		$valid_hooks = array(
			'post.php',
			'post-new.php',
			'woocommerce_page_wcpa-settings',
		);

		if ( ! in_array( $hook, $valid_hooks, true ) ) {
			return;
		}

		if ( ( 'post.php' === $hook || 'post-new.php' === $hook ) && $post ) {
			if ( 'product' !== $post->post_type && 'wcpa_group' !== $post->post_type ) {
				return;
			}
		}

		// Date picker (jQuery UI)
		wp_enqueue_style( 'jquery-ui-style', '//code.jquery.com/ui/1.13.2/themes/smoothness/jquery-ui.css', array(), '1.13.2' );
		wp_enqueue_script( 'jquery-ui-datepicker' );

		// Admin CSS
		wp_add_inline_style( 'woocommerce_admin_styles', '
			.wcpa-metabox-wrapper .wcpa-addon-row { border: 1px solid #ccd0d4; background: #fdfdfd; }
			.wcpa-metabox-wrapper .wcpa-addon-row .wcpa-field-row label { display: flex; flex-direction: column; font-weight: 600; }
		' );
	}

	// ---------------------------------------------------------------
	// Frontend Enqueue Scripts
	// ---------------------------------------------------------------

	/**
	 * Enqueue frontend scripts and styles.
	 */
	public function frontend_enqueue_scripts() {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		// jQuery UI Datepicker
		wp_enqueue_style( 'jquery-ui-style', '//code.jquery.com/ui/1.13.2/themes/smoothness/jquery-ui.css', array(), '1.13.2' );
		wp_enqueue_script( 'jquery-ui-datepicker' );

		// Plugin frontend script
		wp_enqueue_script(
			'wcpa-frontend',
			plugin_dir_url( WCPA_PLUGIN_FILE ) . 'assets/wcpa-frontend.js',
			array( 'jquery', 'jquery-ui-datepicker' ),
			WCPA_VERSION,
			true
		);

		wp_localize_script( 'wcpa-frontend', 'wcpa_params', array(
			'ajax_url'   => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( 'wcpa_frontend_nonce' ),
			'dateFormat' => get_option( 'date_format', 'yy-mm-dd' ),
		) );

		// Frontend styles
		wp_add_inline_style( 'woocommerce-general', $this->get_frontend_css() );
	}

	/**
	 * Get frontend CSS.
	 *
	 * @return string
	 */
	private function get_frontend_css() {
		return '
		.wcpa-addons-wrapper { margin: 1.5em 0; padding: 1em 0; border-top: 1px solid #eee; border-bottom: 1px solid #eee; }
		.wcpa-addons-wrapper h3 { margin-bottom: 1em; }
		.wcpa-addon-field { margin-bottom: 1.2em; }
		.wcpa-addon-field label.wcpa-field-label { display: block; font-weight: 600; margin-bottom: 4px; }
		.wcpa-addon-field label.wcpa-field-label .required { color: #e2401c; margin-left: 3px; }
		.wcpa-addon-field .wcpa-description { font-size: 0.9em; color: #666; margin-top: 4px; }
		.wcpa-addon-field input[type="text"],
		.wcpa-addon-field textarea,
		.wcpa-addon-field select { width: 100%; max-width: 400px; }
		.wcpa-addon-field input[type="checkbox"],
		.wcpa-addon-field input[type="radio"] { margin-right: 8px; }
		.wcpa-addon-field .wcpa-option-label { display: inline-block; margin-right: 16px; }
		.wcpa-addon-field .wcpa-price-suffix { color: #777; font-size: 0.9em; font-weight: normal; }
		.wcpa-addon-field .wcpa-file-input-wrapper { display: flex; align-items: center; gap: 10px; }
		.wcpa-addon-field .wcpa-file-name { font-size: 0.9em; color: #4b4b4b; }
		.wcpa-addon-field .wcpa-upload-progress { display: none; width: 200px; height: 8px; background: #eee; border-radius: 4px; overflow: hidden; }
		.wcpa-addon-field .wcpa-upload-progress .wcpa-progress-bar { height: 100%; width: 0; background: #007cba; transition: width 0.3s; }
		.wcpa-addon-field .wcpa-upload-error { color: #e2401c; font-size: 0.85em; }
		';
	}

	// ---------------------------------------------------------------
	// Frontend Display
	// ---------------------------------------------------------------

	/**
	 * Display add-on fields on the product page.
	 */
	public function display_product_addons() {
		global $product;

		if ( ! $product ) {
			return;
		}

		$product_id = $product->get_id();

		// Check if disabled
		if ( '1' === get_post_meta( $product_id, '_wcpa_disable_all', true ) ) {
			return;
		}

		// Collect addons: per-product + groups
		$addons = array();

		// Per-product custom addons
		$product_addons = get_post_meta( $product_id, $this->product_meta_key, true );
		if ( is_array( $product_addons ) && ! empty( $product_addons ) ) {
			$addons = array_merge( $addons, $product_addons );
		}

		// Group addons
		$assigned_groups = get_post_meta( $product_id, $this->groups_meta_key, true );
		if ( is_array( $assigned_groups ) && ! empty( $assigned_groups ) ) {
			foreach ( $assigned_groups as $group_id ) {
				$group_addons = get_post_meta( (int) $group_id, '_wcpa_group_addons', true );
				if ( is_array( $group_addons ) && ! empty( $group_addons ) ) {
					$addons = array_merge( $addons, $group_addons );
				}
			}
		}

		if ( empty( $addons ) ) {
			return;
		}

		$settings         = get_option( $this->settings_option, array() );
		$label_position   = isset( $settings['display_label_position'] ) ? $settings['display_label_position'] : 'above';
		$date_format      = isset( $settings['date_format'] ) ? $settings['date_format'] : 'Y-m-d';
		$max_file_size    = isset( $settings['max_file_size'] ) ? (int) $settings['max_file_size'] : 2;
		$allowed_types    = isset( $settings['allowed_file_types'] ) ? $settings['allowed_file_types'] : 'jpg,jpeg,png,gif,pdf,doc,docx';

		// Sort by index (preserve order)
		ksort( $addons );

		?>
		<div class="wcpa-addons-wrapper">
			<h3><?php esc_html_e( 'Extra Options', WCPA_TEXT_DOMAIN ); ?></h3>
			<?php foreach ( $addons as $index => $addon ) : ?>
				<?php
				$type        = isset( $addon['type'] ) ? $addon['type'] : 'text';
				$label       = isset( $addon['label'] ) ? $addon['label'] : '';
				$name_key    = isset( $addon['name_key'] ) && ! empty( $addon['name_key'] ) ? $addon['name_key'] : 'addon_' . $index;
				$placeholder = isset( $addon['placeholder'] ) ? $addon['placeholder'] : '';
				$required    = isset( $addon['required'] ) && '1' === $addon['required'];
				$price       = isset( $addon['price'] ) ? floatval( $addon['price'] ) : 0;
				$price_type  = isset( $addon['price_type'] ) ? $addon['price_type'] : 'one_time';
				$desc        = isset( $addon['description'] ) ? $addon['description'] : '';
				$default_val = isset( $addon['default_value'] ) ? $addon['default_value'] : '';
				$max_chars   = isset( $addon['max_chars'] ) ? (int) $addon['max_chars'] : 0;
				$options     = isset( $addon['options'] ) && is_array( $addon['options'] ) ? $addon['options'] : array();

				$field_name    = 'wcpa_addon_' . $name_key;
				$required_attr = $required ? ' required' : '';
				$required_mark = $required ? '<span class="required">*</span>' : '';
				$price_suffix  = '';

				if ( $price > 0 && in_array( $type, array( 'text', 'textarea', 'date' ), true ) ) {
					$price_suffix = ' <span class="wcpa-price-suffix">(' . wp_strip_all_tags( wc_price( $price ) ) . ( 'multiply' === $price_type ? ' &times; qty' : '' ) . ')</span>';
				}
				?>
				<div class="wcpa-addon-field wcpa-addon-field-<?php echo esc_attr( $type ); ?>" data-type="<?php echo esc_attr( $type ); ?>" data-price="<?php echo esc_attr( $price ); ?>" data-price-type="<?php echo esc_attr( $price_type ); ?>" data-name-key="<?php echo esc_attr( $name_key ); ?>" data-required="<?php echo $required ? '1' : '0'; ?>">

					<?php if ( 'hidden' !== $label_position && ! empty( $label ) ) : ?>
						<label class="wcpa-field-label" for="<?php echo esc_attr( $field_name ); ?>">
							<?php echo esc_html( $label ); ?><?php echo $required_mark; ?><?php echo $price_suffix; ?>
						</label>
					<?php endif; ?>

					<?php if ( 'text' === $type ) : ?>
						<input type="text" name="<?php echo esc_attr( $field_name ); ?>" id="<?php echo esc_attr( $field_name ); ?>"
							value="<?php echo esc_attr( $default_val ); ?>"
							placeholder="<?php echo esc_attr( $placeholder ); ?>"
							<?php echo $required_attr; ?>
							<?php echo $max_chars > 0 ? 'maxlength="' . esc_attr( $max_chars ) . '"' : ''; ?>
							class="wcpa-input-text" />

					<?php elseif ( 'textarea' === $type ) : ?>
						<textarea name="<?php echo esc_attr( $field_name ); ?>" id="<?php echo esc_attr( $field_name ); ?>"
							placeholder="<?php echo esc_attr( $placeholder ); ?>"
							<?php echo $required_attr; ?>
							<?php echo $max_chars > 0 ? 'maxlength="' . esc_attr( $max_chars ) . '"' : ''; ?>
							class="wcpa-input-textarea"><?php echo esc_textarea( $default_val ); ?></textarea>

					<?php elseif ( 'date' === $type ) : ?>
						<input type="text" name="<?php echo esc_attr( $field_name ); ?>" id="<?php echo esc_attr( $field_name ); ?>"
							value="<?php echo esc_attr( $default_val ); ?>"
							placeholder="<?php echo esc_attr( $placeholder ); ?>"
							<?php echo $required_attr; ?>
							class="wcpa-input-date wcpa-datepicker"
							data-date-format="<?php echo esc_attr( $date_format ); ?>" />

					<?php elseif ( 'file' === $type ) : ?>
						<div class="wcpa-file-input-wrapper">
							<input type="file" name="<?php echo esc_attr( $field_name ); ?>" id="<?php echo esc_attr( $field_name ); ?>"
								<?php echo $required_attr; ?>
								class="wcpa-input-file"
								data-max-size="<?php echo esc_attr( $max_file_size ); ?>"
								data-allowed-types="<?php echo esc_attr( $allowed_types ); ?>" />
							<input type="hidden" name="<?php echo esc_attr( $field_name ); ?>_file_url" class="wcpa-file-url" value="" />
							<input type="hidden" name="<?php echo esc_attr( $field_name ); ?>_file_name" class="wcpa-file-name-hidden" value="" />
							<span class="wcpa-file-name"></span>
							<div class="wcpa-upload-progress"><div class="wcpa-progress-bar"></div></div>
							<span class="wcpa-upload-error"></span>
						</div>

					<?php elseif ( 'select' === $type && ! empty( $options ) ) : ?>
						<select name="<?php echo esc_attr( $field_name ); ?>" id="<?php echo esc_attr( $field_name ); ?>" <?php echo $required_attr; ?> class="wcpa-input-select">
							<option value=""><?php echo esc_html( $placeholder ? $placeholder : __( '— Select —', WCPA_TEXT_DOMAIN ) ); ?></option>
							<?php foreach ( $options as $option ) : ?>
								<?php
								$opt_label = isset( $option['label'] ) ? $option['label'] : '';
								$opt_price = isset( $option['price'] ) ? floatval( $option['price'] ) : 0;
								$opt_price_label = isset( $option['price_label'] ) ? $option['price_label'] : '';
								$opt_display = $opt_label;
								if ( $opt_price > 0 ) {
									$opt_display .= ' ' . ( ! empty( $opt_price_label ) ? $opt_price_label : '+' . wp_strip_all_tags( wc_price( $opt_price ) ) );
								}
								?>
								<option value="<?php echo esc_attr( $opt_label ); ?>" data-price="<?php echo esc_attr( $opt_price ); ?>">
									<?php echo esc_html( $opt_display ); ?>
								</option>
							<?php endforeach; ?>
						</select>

					<?php elseif ( 'radio' === $type && ! empty( $options ) ) : ?>
						<div class="wcpa-radio-group">
							<?php foreach ( $options as $opt_idx => $option ) : ?>
								<?php
								$opt_label = isset( $option['label'] ) ? $option['label'] : '';
								$opt_price = isset( $option['price'] ) ? floatval( $option['price'] ) : 0;
								$opt_price_label = isset( $option['price_label'] ) ? $option['price_label'] : '';
								$opt_id    = $field_name . '_' . $opt_idx;
								$opt_display = $opt_label;
								if ( $opt_price > 0 ) {
									$opt_display .= ' <span class="wcpa-price-suffix">' . ( ! empty( $opt_price_label ) ? esc_html( $opt_price_label ) : '+' . wp_strip_all_tags( wc_price( $opt_price ) ) ) . '</span>';
								}
								?>
								<label class="wcpa-option-label" for="<?php echo esc_attr( $opt_id ); ?>">
									<input type="radio" name="<?php echo esc_attr( $field_name ); ?>" id="<?php echo esc_attr( $opt_id ); ?>"
										value="<?php echo esc_attr( $opt_label ); ?>"
										data-price="<?php echo esc_attr( $opt_price ); ?>"
										<?php echo $required_attr; ?> />
									<?php echo $opt_display; ?>
								</label>
							<?php endforeach; ?>
						</div>

					<?php elseif ( 'checkbox' === $type && ! empty( $options ) ) : ?>
						<div class="wcpa-checkbox-group">
							<?php foreach ( $options as $opt_idx => $option ) : ?>
								<?php
								$opt_label = isset( $option['label'] ) ? $option['label'] : '';
								$opt_price = isset( $option['price'] ) ? floatval( $option['price'] ) : 0;
								$opt_price_label = isset( $option['price_label'] ) ? $option['price_label'] : '';
								$opt_id    = $field_name . '_' . $opt_idx;
								$opt_display = $opt_label;
								if ( $opt_price > 0 ) {
									$opt_display .= ' <span class="wcpa-price-suffix">' . ( ! empty( $opt_price_label ) ? esc_html( $opt_price_label ) : '+' . wp_strip_all_tags( wc_price( $opt_price ) ) ) . '</span>';
								}
								?>
								<label class="wcpa-option-label" for="<?php echo esc_attr( $opt_id ); ?>">
									<input type="checkbox" name="<?php echo esc_attr( $field_name ); ?>[]" id="<?php echo esc_attr( $opt_id ); ?>"
										value="<?php echo esc_attr( $opt_label ); ?>"
										data-price="<?php echo esc_attr( $opt_price ); ?>" />
									<?php echo $opt_display; ?>
								</label>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>

					<?php if ( ! empty( $desc ) ) : ?>
						<p class="wcpa-description"><?php echo esc_html( $desc ); ?></p>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	// ---------------------------------------------------------------
	// AJAX File Upload Handler
	// ---------------------------------------------------------------

	/**
	 * Handle file upload via AJAX.
	 */
	public function ajax_upload_file() {
		// Verify nonce
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'wcpa_frontend_nonce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', WCPA_TEXT_DOMAIN ) ) );
		}

		$settings = get_option( $this->settings_option, array() );
		if ( ! isset( $settings['enable_file_uploads'] ) || 'yes' !== $settings['enable_file_uploads'] ) {
			wp_send_json_error( array( 'message' => __( 'File uploads are disabled.', WCPA_TEXT_DOMAIN ) ) );
		}

		if ( ! isset( $_FILES['wcpa_file'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No file uploaded.', WCPA_TEXT_DOMAIN ) ) );
		}

		$file = $_FILES['wcpa_file'];

		// Validate file size
		$max_size = isset( $settings['max_file_size'] ) ? (int) $settings['max_file_size'] * 1024 * 1024 : 2 * 1024 * 1024;
		if ( $file['size'] > $max_size ) {
			wp_send_json_error( array(
				'message' => sprintf(
					/* translators: %s: max file size in MB */
					__( 'File exceeds maximum size of %s MB.', WCPA_TEXT_DOMAIN ),
					$settings['max_file_size']
				),
			) );
		}

		// Validate file type
		$allowed_types = isset( $settings['allowed_file_types'] ) ? explode( ',', $settings['allowed_file_types'] ) : array( 'jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx' );
		$allowed_types = array_map( 'trim', $allowed_types );
		$file_ext      = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );

		if ( ! in_array( $file_ext, $allowed_types, true ) ) {
			wp_send_json_error( array(
				'message' => sprintf(
					/* translators: %s: allowed file types */
					__( 'File type not allowed. Allowed types: %s', WCPA_TEXT_DOMAIN ),
					implode( ', ', $allowed_types )
				),
			) );
		}

		// Upload to WordPress media library
		$upload_dir = wp_upload_dir();
		$subdir     = '/wcpa_uploads/';
		$dest_dir   = $upload_dir['basedir'] . $subdir;

		if ( ! file_exists( $dest_dir ) ) {
			wp_mkdir_p( $dest_dir );
		}

		$filename   = sanitize_file_name( $file['name'] );
		$unique_name = uniqid( 'wcpa_', true ) . '_' . $filename;
		$dest_path  = $dest_dir . $unique_name;

		if ( move_uploaded_file( $file['tmp_name'], $dest_path ) ) {
			$file_url = $upload_dir['baseurl'] . $subdir . $unique_name;
			wp_send_json_success( array(
				'url'      => esc_url( $file_url ),
				'filename' => esc_html( $filename ),
				'path'     => $dest_path,
			) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Failed to upload file.', WCPA_TEXT_DOMAIN ) ) );
		}
	}

	// ---------------------------------------------------------------
	// Cart Integration
	// ---------------------------------------------------------------

	/**
	 * Add add-on data to cart item.
	 *
	 * @param array $cart_item_data Cart item data.
	 * @param int   $product_id     Product ID.
	 * @param int   $variation_id   Variation ID.
	 * @return array
	 */
	public function add_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
		$posted = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by WooCommerce

		$addon_fields = array();

		// Collect addons definition for validation
		$all_addons = $this->get_product_addons( $product_id );

		foreach ( $posted as $key => $value ) {
			if ( strpos( $key, 'wcpa_addon_' ) !== 0 ) {
				continue;
			}

			$name_key = substr( $key, 11 ); // strip 'wcpa_addon_'

			// Find the addon definition
			$addon_def = null;
			foreach ( $all_addons as $addon ) {
				$ak = isset( $addon['name_key'] ) && ! empty( $addon['name_key'] ) ? $addon['name_key'] : '';
				if ( $ak === $name_key ) {
					$addon_def = $addon;
					break;
				}
			}

			if ( ! $addon_def ) {
				continue;
			}

			$type = isset( $addon_def['type'] ) ? $addon_def['type'] : 'text';

			// Collect file upload data from hidden fields
			if ( 'file' === $type ) {
				$file_url  = isset( $posted[ $key . '_file_url' ] ) ? esc_url_raw( wp_unslash( $posted[ $key . '_file_url' ] ) ) : '';
				$file_name = isset( $posted[ $key . '_file_name' ] ) ? sanitize_text_field( wp_unslash( $posted[ $key . '_file_name' ] ) ) : '';
				if ( ! empty( $file_url ) ) {
					$addon_fields[] = array(
						'name_key'  => $name_key,
						'label'     => isset( $addon_def['label'] ) ? $addon_def['label'] : $name_key,
						'type'      => 'file',
						'value'     => $file_name,
						'file_url'  => $file_url,
						'price'     => isset( $addon_def['price'] ) ? floatval( $addon_def['price'] ) : 0,
						'price_type' => isset( $addon_def['price_type'] ) ? $addon_def['price_type'] : 'one_time',
					);
				}
				continue;
			}

			// Sanitize based on type
			if ( is_array( $value ) ) {
				$sanitized_value = array_map( 'sanitize_text_field', wp_unslash( $value ) );
				$display_value   = implode( ', ', $sanitized_value );
			} elseif ( 'textarea' === $type ) {
				$sanitized_value = sanitize_textarea_field( wp_unslash( $value ) );
				$display_value   = $sanitized_value;
			} else {
				$sanitized_value = sanitize_text_field( wp_unslash( $value ) );
				$display_value   = $sanitized_value;
			}

			if ( empty( $sanitized_value ) ) {
				continue;
			}

			// Calculate price based on selected options
			$selected_price = 0;

			if ( in_array( $type, array( 'select', 'radio' ), true ) ) {
				if ( is_array( $value ) ) {
					$selected_value = sanitize_text_field( wp_unslash( $value[0] ) );
				} else {
					$selected_value = sanitize_text_field( wp_unslash( $value ) );
				}
				$selected_price = $this->get_option_price( $addon_def, $selected_value );
			} elseif ( 'checkbox' === $type && is_array( $value ) ) {
				foreach ( wp_unslash( $value ) as $v ) {
					$v = sanitize_text_field( $v );
					$selected_price += $this->get_option_price( $addon_def, $v );
				}
			} elseif ( in_array( $type, array( 'text', 'textarea', 'date' ), true ) ) {
				$selected_price = isset( $addon_def['price'] ) ? floatval( $addon_def['price'] ) : 0;
				$price_type     = isset( $addon_def['price_type'] ) ? $addon_def['price_type'] : 'one_time';
			}

			$addon_fields[] = array(
				'name_key'   => $name_key,
				'label'      => isset( $addon_def['label'] ) ? $addon_def['label'] : $name_key,
				'type'       => $type,
				'value'      => $display_value,
				'raw_value'  => is_array( $value ) ? $sanitized_value : $sanitized_value,
				'price'      => $selected_price,
				'price_type' => isset( $addon_def['price_type'] ) ? $addon_def['price_type'] : 'one_time',
			);
		}

		if ( ! empty( $addon_fields ) ) {
			$cart_item_data['wcpa_addons'] = $addon_fields;
			// Make this unique per addon selection so different combos are separate cart items
			$cart_item_data['wcpa_hash'] = md5( wp_json_encode( $addon_fields ) );
		}

		return $cart_item_data;
	}

	/**
	 * Display add-on data in cart.
	 *
	 * @param array $item_data Cart item data for display.
	 * @param array $cart_item Cart item.
	 * @return array
	 */
	public function display_cart_item_data( $item_data, $cart_item ) {
		if ( isset( $cart_item['wcpa_addons'] ) && is_array( $cart_item['wcpa_addons'] ) ) {
			foreach ( $cart_item['wcpa_addons'] as $addon ) {
				$label = isset( $addon['label'] ) ? $addon['label'] : '';
				$value = isset( $addon['value'] ) ? $addon['value'] : '';
				$price = isset( $addon['price'] ) ? floatval( $addon['price'] ) : 0;

				$display = $value;
				if ( $price > 0 ) {
					$display .= ' (' . wp_strip_all_tags( wc_price( $price ) ) . ')';
				}

				$item_data[] = array(
					'name'  => esc_html( $label ),
					'value' => esc_html( $display ),
					'display' => esc_html( $display ),
				);
			}
		}
		return $item_data;
	}

	/**
	 * Apply add-on prices to cart item totals.
	 *
	 * @param WC_Cart $cart Cart object.
	 */
	public function apply_cart_item_price( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
			if ( ! isset( $cart_item['wcpa_addons'] ) || ! is_array( $cart_item['wcpa_addons'] ) ) {
				continue;
			}

			$addon_total = 0;
			$quantity    = $cart_item['quantity'];

			foreach ( $cart_item['wcpa_addons'] as $addon ) {
				$addon_price = isset( $addon['price'] ) ? floatval( $addon['price'] ) : 0;
				$price_type  = isset( $addon['price_type'] ) ? $addon['price_type'] : 'one_time';

				if ( 'multiply' === $price_type ) {
					$addon_total += $addon_price * $quantity;
				} else {
					$addon_total += $addon_price;
				}
			}

			if ( $addon_total > 0 ) {
				$cart_item['data']->set_price( floatval( $cart_item['data']->get_price() ) + $addon_total );
			}
		}
	}

	/**
	 * Save add-on data to order line item meta.
	 *
	 * @param WC_Order_Item_Product $item          Order item.
	 * @param string                $cart_item_key Cart item key.
	 * @param array                 $values        Cart item values.
	 * @param WC_Order              $order         Order.
	 */
	public function save_order_item_meta( $item, $cart_item_key, $values, $order ) {
		if ( isset( $values['wcpa_addons'] ) && is_array( $values['wcpa_addons'] ) ) {
			$item->update_meta_data( '_wcpa_addons', $values['wcpa_addons'] );
		}
	}

	/**
	 * Display order item meta value nicely (for file uploads show link).
	 *
	 * @param string       $display_value Display value.
	 * @param object       $meta          Meta object.
	 * @param WC_Order_Item $item         Order item.
	 * @return string
	 */
	public function order_item_display_meta_value( $display_value, $meta, $item ) {
		if ( $meta->key === '_wcpa_addons' ) {
			return ''; // handled separately
		}
		return $display_value;
	}

	/**
	 * Display add-ons at the end of order item meta in admin/emails.
	 *
	 * @param int           $item_id Item ID.
	 * @param WC_Order_Item $item    Order item.
	 * @param WC_Order      $order   Order.
	 * @param bool          $plain_text Whether plain text.
	 */
	public function order_item_meta_end( $item_id, $item, $order, $plain_text = false ) {
		$addons = $item->get_meta( '_wcpa_addons' );

		if ( empty( $addons ) || ! is_array( $addons ) ) {
			return;
		}

		if ( $plain_text ) {
			foreach ( $addons as $addon ) {
				$label = isset( $addon['label'] ) ? $addon['label'] : '';
				$value = isset( $addon['value'] ) ? $addon['value'] : '';
				$price = isset( $addon['price'] ) ? floatval( $addon['price'] ) : 0;

				if ( 'file' === $addon['type'] && isset( $addon['file_url'] ) ) {
					echo "\n" . esc_html( $label ) . ': ' . esc_url( $addon['file_url'] );
				} else {
					echo "\n" . esc_html( $label ) . ': ' . esc_html( $value );
					if ( $price > 0 ) {
						echo ' (' . wp_strip_all_tags( wc_price( $price ) ) . ')';
					}
				}
			}
		} else {
			echo '<div class="wcpa-order-addons" style="margin-top:6px;padding-top:6px;border-top:1px dashed #ddd;">';
			echo '<small><strong>' . esc_html__( 'Extra Options', WCPA_TEXT_DOMAIN ) . ':</strong></small><br />';
			foreach ( $addons as $addon ) {
				$label = isset( $addon['label'] ) ? esc_html( $addon['label'] ) : '';
				$value = isset( $addon['value'] ) ? esc_html( $addon['value'] ) : '';
				$price = isset( $addon['price'] ) ? floatval( $addon['price'] ) : 0;

				echo '<div style="padding:2px 0;">';
				echo '<small>' . esc_html( $label ) . ': ';

				if ( 'file' === $addon['type'] && isset( $addon['file_url'] ) ) {
					echo '<a href="' . esc_url( $addon['file_url'] ) . '" target="_blank">' . esc_html( $value ) . '</a>';
				} else {
					echo esc_html( $value );
				}

				if ( $price > 0 ) {
					echo ' <em>(' . wp_strip_all_tags( wc_price( $price ) ) . ')</em>';
				}

				echo '</small></div>';
			}
			echo '</div>';
		}
	}

	// ---------------------------------------------------------------
	// Cleanup
	// ---------------------------------------------------------------

	/**
	 * Clean up uploaded files when a cart item is removed.
	 *
	 * @param string $cart_item_key Cart item key.
	 * @param WC_Cart $cart         Cart object.
	 */
	public function cleanup_cart_uploads( $cart_item_key, $cart ) {
		if ( ! isset( $cart->cart_contents[ $cart_item_key ]['wcpa_addons'] ) ) {
			return;
		}

		$addons = $cart->cart_contents[ $cart_item_key ]['wcpa_addons'];
		$upload_dir = wp_upload_dir();
		$base_dir   = $upload_dir['basedir'];

		foreach ( $addons as $addon ) {
			if ( 'file' === $addon['type'] && isset( $addon['file_url'] ) ) {
				$file_path = str_replace( $upload_dir['baseurl'], $base_dir, $addon['file_url'] );
				if ( file_exists( $file_path ) ) {
					// Don't delete immediately; could be used in other carts
					// Schedule a cleanup via transient
					$files_to_clean = get_transient( 'wcpa_cleanup_files' );
					if ( ! is_array( $files_to_clean ) ) {
						$files_to_clean = array();
					}
					$files_to_clean[] = $file_path;
					set_transient( 'wcpa_cleanup_files', $files_to_clean, HOUR_IN_SECONDS * 24 );
				}
			}
		}
	}

	// ---------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------

	/**
	 * Get all add-on definitions for a product (per-product + groups).
	 *
	 * @param int $product_id Product ID.
	 * @return array
	 */
	private function get_product_addons( $product_id ) {
		$addons = array();

		// Per-product
		$product_addons = get_post_meta( $product_id, $this->product_meta_key, true );
		if ( is_array( $product_addons ) ) {
			$addons = array_merge( $addons, $product_addons );
		}

		// From groups
		$assigned_groups = get_post_meta( $product_id, $this->groups_meta_key, true );
		if ( is_array( $assigned_groups ) ) {
			foreach ( $assigned_groups as $group_id ) {
				$group_addons = get_post_meta( (int) $group_id, '_wcpa_group_addons', true );
				if ( is_array( $group_addons ) ) {
					$addons = array_merge( $addons, $group_addons );
				}
			}
		}

		return $addons;
	}

	/**
	 * Get the price for a specific option label in an add-on definition.
	 *
	 * @param array  $addon_def Addon definition.
	 * @param string $selected_value Selected option label.
	 * @return float
	 */
	private function get_option_price( $addon_def, $selected_value ) {
		if ( ! isset( $addon_def['options'] ) || ! is_array( $addon_def['options'] ) ) {
			return 0;
		}
		foreach ( $addon_def['options'] as $option ) {
			if ( isset( $option['label'] ) && $option['label'] === $selected_value ) {
				return isset( $option['price'] ) ? floatval( $option['price'] ) : 0;
			}
		}
		return 0;
	}
}

// ---------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------
add_action( 'plugins_loaded', array( 'WooCommerce_Product_Addons', 'get_instance' ) );

/**
 * Template tag / shortcode for rendering a reusable add-on group.
 *
 * Usage: [wcpa_group id="123"]
 */
function wcpa_group_shortcode( $atts ) {
	$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'wcpa_group' );
	$group_id = absint( $atts['id'] );

	if ( ! $group_id ) {
		return '';
	}

	$addons = get_post_meta( $group_id, '_wcpa_group_addons', true );
	if ( ! is_array( $addons ) || empty( $addons ) ) {
		return '';
	}

	// Render using the same logic as product display
	ob_start();
	$settings         = get_option( 'wcpa_settings', array() );
	$label_position   = isset( $settings['display_label_position'] ) ? $settings['display_label_position'] : 'above';
	$date_format      = isset( $settings['date_format'] ) ? $settings['date_format'] : 'Y-m-d';
	$max_file_size    = isset( $settings['max_file_size'] ) ? (int) $settings['max_file_size'] : 2;
	$allowed_types    = isset( $settings['allowed_file_types'] ) ? $settings['allowed_file_types'] : 'jpg,jpeg,png,gif,pdf,doc,docx';
	?>
	<div class="wcpa-addons-wrapper">
		<h3><?php esc_html_e( 'Extra Options', WCPA_TEXT_DOMAIN ); ?></h3>
		<?php foreach ( $addons as $index => $addon ) : ?>
			<?php
			$type        = isset( $addon['type'] ) ? $addon['type'] : 'text';
			$label       = isset( $addon['label'] ) ? $addon['label'] : '';
			$name_key    = isset( $addon['name_key'] ) && ! empty( $addon['name_key'] ) ? $addon['name_key'] : 'group_' . $group_id . '_' . $index;
			$placeholder = isset( $addon['placeholder'] ) ? $addon['placeholder'] : '';
			$required    = isset( $addon['required'] ) && '1' === $addon['required'];
			$price       = isset( $addon['price'] ) ? floatval( $addon['price'] ) : 0;
			$price_type  = isset( $addon['price_type'] ) ? $addon['price_type'] : 'one_time';
			$desc        = isset( $addon['description'] ) ? $addon['description'] : '';
			$default_val = isset( $addon['default_value'] ) ? $addon['default_value'] : '';
			$max_chars   = isset( $addon['max_chars'] ) ? (int) $addon['max_chars'] : 0;
			$options     = isset( $addon['options'] ) && is_array( $addon['options'] ) ? $addon['options'] : array();

			$field_name    = 'wcpa_addon_' . $name_key;
			$required_attr = $required ? ' required' : '';
			$required_mark = $required ? '<span class="required">*</span>' : '';
			$price_suffix  = '';
			if ( $price > 0 && in_array( $type, array( 'text', 'textarea', 'date' ), true ) ) {
				$price_suffix = ' <span class="wcpa-price-suffix">(' . wp_strip_all_tags( wc_price( $price ) ) . ( 'multiply' === $price_type ? ' &times; qty' : '' ) . ')</span>';
			}
			?>
			<div class="wcpa-addon-field wcpa-addon-field-<?php echo esc_attr( $type ); ?>">
				<?php if ( 'hidden' !== $label_position && ! empty( $label ) ) : ?>
					<label class="wcpa-field-label" for="<?php echo esc_attr( $field_name ); ?>">
						<?php echo esc_html( $label ); ?><?php echo $required_mark; ?><?php echo $price_suffix; ?>
					</label>
				<?php endif; ?>

				<?php if ( 'text' === $type ) : ?>
					<input type="text" name="<?php echo esc_attr( $field_name ); ?>" id="<?php echo esc_attr( $field_name ); ?>"
						value="<?php echo esc_attr( $default_val ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>"
						<?php echo $required_attr; ?> <?php echo $max_chars > 0 ? 'maxlength="' . esc_attr( $max_chars ) . '"' : ''; ?> />
				<?php elseif ( 'textarea' === $type ) : ?>
					<textarea name="<?php echo esc_attr( $field_name ); ?>" id="<?php echo esc_attr( $field_name ); ?>"
						placeholder="<?php echo esc_attr( $placeholder ); ?>" <?php echo $required_attr; ?>
						<?php echo $max_chars > 0 ? 'maxlength="' . esc_attr( $max_chars ) . '"' : ''; ?>><?php echo esc_textarea( $default_val ); ?></textarea>
				<?php elseif ( 'date' === $type ) : ?>
					<input type="text" name="<?php echo esc_attr( $field_name ); ?>" id="<?php echo esc_attr( $field_name ); ?>"
						value="<?php echo esc_attr( $default_val ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>"
						<?php echo $required_attr; ?> class="wcpa-datepicker"
						data-date-format="<?php echo esc_attr( $date_format ); ?>" />
				<?php elseif ( 'file' === $type ) : ?>
					<div class="wcpa-file-input-wrapper">
						<input type="file" name="<?php echo esc_attr( $field_name ); ?>" id="<?php echo esc_attr( $field_name ); ?>"
							<?php echo $required_attr; ?> data-max-size="<?php echo esc_attr( $max_file_size ); ?>"
							data-allowed-types="<?php echo esc_attr( $allowed_types ); ?>" />
						<input type="hidden" name="<?php echo esc_attr( $field_name ); ?>_file_url" class="wcpa-file-url" value="" />
						<input type="hidden" name="<?php echo esc_attr( $field_name ); ?>_file_name" class="wcpa-file-name-hidden" value="" />
						<span class="wcpa-file-name"></span>
						<div class="wcpa-upload-progress"><div class="wcpa-progress-bar"></div></div>
						<span class="wcpa-upload-error"></span>
					</div>
				<?php elseif ( 'select' === $type && ! empty( $options ) ) : ?>
					<select name="<?php echo esc_attr( $field_name ); ?>" id="<?php echo esc_attr( $field_name ); ?>" <?php echo $required_attr; ?>>
						<option value=""><?php echo esc_html( $placeholder ? $placeholder : '— Select —' ); ?></option>
						<?php foreach ( $options as $option ) : ?>
							<?php
							$opt_label = isset( $option['label'] ) ? $option['label'] : '';
							$opt_price = isset( $option['price'] ) ? floatval( $option['price'] ) : 0;
							$opt_price_label = isset( $option['price_label'] ) ? $option['price_label'] : '';
							$opt_display = $opt_label;
							if ( $opt_price > 0 ) {
								$opt_display .= ' ' . ( ! empty( $opt_price_label ) ? $opt_price_label : '+' . wp_strip_all_tags( wc_price( $opt_price ) ) );
							}
							?>
							<option value="<?php echo esc_attr( $opt_label ); ?>" data-price="<?php echo esc_attr( $opt_price ); ?>"><?php echo esc_html( $opt_display ); ?></option>
						<?php endforeach; ?>
					</select>
				<?php elseif ( 'radio' === $type && ! empty( $options ) ) : ?>
					<div class="wcpa-radio-group">
						<?php foreach ( $options as $opt_idx => $option ) : ?>
							<?php
							$opt_label = isset( $option['label'] ) ? $option['label'] : '';
							$opt_price = isset( $option['price'] ) ? floatval( $option['price'] ) : 0;
							$opt_price_label = isset( $option['price_label'] ) ? $option['price_label'] : '';
							$opt_id    = $field_name . '_' . $opt_idx;
							$opt_display = $opt_label;
							if ( $opt_price > 0 ) {
								$opt_display .= ' <span class="wcpa-price-suffix">' . ( ! empty( $opt_price_label ) ? esc_html( $opt_price_label ) : '+' . wp_strip_all_tags( wc_price( $opt_price ) ) ) . '</span>';
							}
							?>
							<label class="wcpa-option-label" for="<?php echo esc_attr( $opt_id ); ?>">
								<input type="radio" name="<?php echo esc_attr( $field_name ); ?>" id="<?php echo esc_attr( $opt_id ); ?>"
									value="<?php echo esc_attr( $opt_label ); ?>" data-price="<?php echo esc_attr( $opt_price ); ?>" <?php echo $required_attr; ?> />
								<?php echo $opt_display; ?>
							</label>
						<?php endforeach; ?>
					</div>
				<?php elseif ( 'checkbox' === $type && ! empty( $options ) ) : ?>
					<div class="wcpa-checkbox-group">
						<?php foreach ( $options as $opt_idx => $option ) : ?>
							<?php
							$opt_label = isset( $option['label'] ) ? $option['label'] : '';
							$opt_price = isset( $option['price'] ) ? floatval( $option['price'] ) : 0;
							$opt_price_label = isset( $option['price_label'] ) ? $option['price_label'] : '';
							$opt_id    = $field_name . '_' . $opt_idx;
							$opt_display = $opt_label;
							if ( $opt_price > 0 ) {
								$opt_display .= ' <span class="wcpa-price-suffix">' . ( ! empty( $opt_price_label ) ? esc_html( $opt_price_label ) : '+' . wp_strip_all_tags( wc_price( $opt_price ) ) ) . '</span>';
							}
							?>
							<label class="wcpa-option-label" for="<?php echo esc_attr( $opt_id ); ?>">
								<input type="checkbox" name="<?php echo esc_attr( $field_name ); ?>[]" id="<?php echo esc_attr( $opt_id ); ?>"
									value="<?php echo esc_attr( $opt_label ); ?>" data-price="<?php echo esc_attr( $opt_price ); ?>" />
								<?php echo $opt_display; ?>
							</label>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<?php if ( ! empty( $desc ) ) : ?>
					<p class="wcpa-description"><?php echo esc_html( $desc ); ?></p>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'wcpa_group', 'wcpa_group_shortcode' );

// ---------------------------------------------------------------
// Ensure WooCommerce is active
// ---------------------------------------------------------------
add_action( 'admin_init', 'wcpa_check_woocommerce' );
function wcpa_check_woocommerce() {
	if ( ! is_plugin_active( 'woocommerce/woocommerce.php' ) && current_user_can( 'activate_plugins' ) ) {
		add_action( 'admin_notices', 'wcpa_missing_woocommerce_notice' );
	}
}

function wcpa_missing_woocommerce_notice() {
	?>
	<div class="notice notice-warning is-dismissible">
		<p><?php esc_html_e( 'WooCommerce Product Extra Options requires WooCommerce to be installed and active.', WCPA_TEXT_DOMAIN ); ?></p>
	</div>
	<?php
}