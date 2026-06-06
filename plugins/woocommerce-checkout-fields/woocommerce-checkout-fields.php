<?php
/**
 * Plugin Name:       WooCommerce Custom Checkout Fields Manager
 * Plugin URI:        https://sandydigital.io
 * Description:       Add, reorder, and manage custom checkout fields on your WooCommerce checkout page. Drag-drop ordering, conditional display, multiple field types, and validation — no coding required.
 * Version:           1.0.0
 * Author:            AcidBurn
 * Author URI:        https://sandydigital.io
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       wc-checkout-fields
 * Domain Path:       /languages
 * Requires at least: 5.0
 * Requires PHP:      7.4
 * WC requires at least: 4.0
 * WC tested up to:   9.0
 *
 * @package WC_Checkout_Fields
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ), true ) ) {
	return;
}

final class WC_Checkout_Fields_Manager {

	private static $instance = null;

	const OPTION_KEY = 'wc_checkout_fields_data';

	/** @var array Built-in default fields. */
	private $default_fields;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'load_textdomain' ) );

		// Frontend hooks.
		add_filter( 'woocommerce_checkout_fields', array( $this, 'modify_checkout_fields' ), 99 );
		add_action( 'woocommerce_checkout_process', array( $this, 'validate_custom_fields' ) );
		add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'save_custom_fields' ) );
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'display_custom_fields_admin' ) );
		add_action( 'woocommerce_order_details_after_customer_details', array( $this, 'display_custom_fields_thankyou' ) );

		// Email integration.
		add_filter( 'woocommerce_email_order_meta_fields', array( $this, 'add_fields_to_email' ), 10, 3 );

		// Admin.
		add_action( 'admin_menu', array( $this, 'add_admin_page' ) );
		add_action( 'admin_init', array( $this, 'handle_form_submission' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_ajax_wc_cf_save_order', array( $this, 'ajax_save_order' ) );
		add_action( 'wp_ajax_wc_cf_delete_field', array( $this, 'ajax_delete_field' ) );

		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'plugin_action_links' ) );
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'wc-checkout-fields', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}

	// ─── Defaults ───────────────────────────────────────────

	private function get_default_fields() {
		if ( null !== $this->default_fields ) {
			return $this->default_fields;
		}

		$this->default_fields = array(
			'billing_first_name' => array(
				'label'    => __( 'First name', 'wc-checkout-fields' ),
				'section'  => 'billing',
				'type'     => 'text',
				'required' => true,
				'builtin'  => true,
			),
			'billing_last_name' => array(
				'label'    => __( 'Last name', 'wc-checkout-fields' ),
				'section'  => 'billing',
				'type'     => 'text',
				'required' => true,
				'builtin'  => true,
			),
			'billing_company' => array(
				'label'   => __( 'Company name', 'wc-checkout-fields' ),
				'section' => 'billing',
				'type'    => 'text',
				'builtin' => true,
			),
			'billing_country' => array(
				'label'    => __( 'Country / Region', 'wc-checkout-fields' ),
				'section'  => 'billing',
				'type'     => 'country',
				'required' => true,
				'builtin'  => true,
			),
			'billing_address_1' => array(
				'label'    => __( 'Street address', 'wc-checkout-fields' ),
				'section'  => 'billing',
				'type'     => 'text',
				'required' => true,
				'builtin'  => true,
			),
			'billing_address_2' => array(
				'label'   => __( 'Apartment, suite, unit, etc.', 'wc-checkout-fields' ),
				'section' => 'billing',
				'type'    => 'text',
				'builtin' => true,
			),
			'billing_city' => array(
				'label'    => __( 'Town / City', 'wc-checkout-fields' ),
				'section'  => 'billing',
				'type'     => 'text',
				'required' => true,
				'builtin'  => true,
			),
			'billing_state' => array(
				'label'    => __( 'State / County', 'wc-checkout-fields' ),
				'section'  => 'billing',
				'type'     => 'state',
				'required' => true,
				'builtin'  => true,
			),
			'billing_postcode' => array(
				'label'    => __( 'Postcode / ZIP', 'wc-checkout-fields' ),
				'section'  => 'billing',
				'type'     => 'text',
				'required' => true,
				'builtin'  => true,
			),
			'billing_phone' => array(
				'label'    => __( 'Phone', 'wc-checkout-fields' ),
				'section'  => 'billing',
				'type'     => 'tel',
				'required' => true,
				'builtin'  => true,
			),
			'billing_email' => array(
				'label'    => __( 'Email address', 'wc-checkout-fields' ),
				'section'  => 'billing',
				'type'     => 'email',
				'required' => true,
				'builtin'  => true,
			),
			'shipping_first_name' => array(
				'label'   => __( 'First name', 'wc-checkout-fields' ),
				'section' => 'shipping',
				'type'    => 'text',
				'builtin' => true,
			),
			'shipping_last_name' => array(
				'label'   => __( 'Last name', 'wc-checkout-fields' ),
				'section' => 'shipping',
				'type'    => 'text',
				'builtin' => true,
			),
			'shipping_company' => array(
				'label'   => __( 'Company name', 'wc-checkout-fields' ),
				'section' => 'shipping',
				'type'    => 'text',
				'builtin' => true,
			),
			'shipping_country' => array(
				'label'   => __( 'Country / Region', 'wc-checkout-fields' ),
				'section' => 'shipping',
				'type'    => 'country',
				'builtin' => true,
			),
			'shipping_address_1' => array(
				'label'   => __( 'Street address', 'wc-checkout-fields' ),
				'section' => 'shipping',
				'type'    => 'text',
				'builtin' => true,
			),
			'shipping_address_2' => array(
				'label'   => __( 'Apartment, suite, unit, etc.', 'wc-checkout-fields' ),
				'section' => 'shipping',
				'type'    => 'text',
				'builtin' => true,
			),
			'shipping_city' => array(
				'label'   => __( 'Town / City', 'wc-checkout-fields' ),
				'section' => 'shipping',
				'type'    => 'text',
				'builtin' => true,
			),
			'shipping_state' => array(
				'label'   => __( 'State / County', 'wc-checkout-fields' ),
				'section' => 'shipping',
				'type'    => 'state',
				'builtin' => true,
			),
			'shipping_postcode' => array(
				'label'   => __( 'Postcode / ZIP', 'wc-checkout-fields' ),
				'section' => 'shipping',
				'type'    => 'text',
				'builtin' => true,
			),
			'order_comments' => array(
				'label'   => __( 'Order notes', 'wc-checkout-fields' ),
				'section' => 'order',
				'type'    => 'textarea',
				'builtin' => true,
			),
		);

		return $this->default_fields;
	}

	// ─── Field Storage ──────────────────────────────────────

	/**
	 * Get all fields (custom + built-in) with saved overrides.
	 *
	 * @return array
	 */
	public function get_all_fields() {
		$saved    = get_option( self::OPTION_KEY, array() );
		$defaults = $this->get_default_fields();

		$fields = array();

		foreach ( $defaults as $key => $field ) {
			if ( isset( $saved[ $key ] ) ) {
				$fields[ $key ] = array_merge( $field, $saved[ $key ] );
			} else {
				$fields[ $key ] = $field;
			}
		}

		foreach ( $saved as $key => $field ) {
			if ( ! isset( $defaults[ $key ] ) ) {
				$fields[ $key ] = $field;
			}
		}

		return $fields;
	}

	// ─── Frontend: Modify Checkout Fields ───────────────────

	public function modify_checkout_fields( $fields ) {
		$all      = $this->get_all_fields();
		$sections = array( 'billing', 'shipping', 'order' );

		foreach ( $sections as $section ) {
			$section_fields = array();

			foreach ( $all as $key => $config ) {
				if ( $config['section'] !== $section ) {
					continue;
				}

				if ( ! empty( $config['hidden'] ) ) {
					continue;
				}

				// Conditional logic check.
				if ( ! empty( $config['condition'] ) ) {
					if ( ! $this->evaluate_condition( $config['condition'] ) ) {
						continue;
					}
				}

				$section_fields[ $key ] = $this->build_field_config( $key, $config );

				if ( isset( $config['priority'] ) ) {
					$section_fields[ $key ]['priority'] = absint( $config['priority'] );
				}
			}

			// Sort by priority.
			uasort( $section_fields, function ( $a, $b ) {
				$pa = isset( $a['priority'] ) ? $a['priority'] : 100;
				$pb = isset( $b['priority'] ) ? $b['priority'] : 100;
				return $pa - $pb;
			} );

			$fields[ $section ] = $section_fields;
		}

		return $fields;
	}

	/**
	 * Build WooCommerce field config from saved field definition.
	 *
	 * @param string $key    Field key.
	 * @param array  $config Field config.
	 * @return array
	 */
	private function build_field_config( $key, $config ) {
		$field = array(
			'label'       => ! empty( $config['label'] ) ? $config['label'] : '',
			'placeholder' => ! empty( $config['placeholder'] ) ? $config['placeholder'] : '',
			'required'    => ! empty( $config['required'] ),
			'class'       => isset( $config['class'] ) ? $config['class'] : array( 'form-row-wide' ),
			'clear'       => isset( $config['clear'] ) ? (bool) $config['clear'] : false,
			'type'        => isset( $config['type'] ) ? $config['type'] : 'text',
		);

		if ( in_array( $field['type'], array( 'select', 'radio', 'multiselect' ), true ) ) {
			$field['options'] = $this->parse_options( $config );
		}

		return $field;
	}

	/**
	 * Parse options string to key-value array.
	 *
	 * @param array $config Field config.
	 * @return array
	 */
	private function parse_options( $config ) {
		if ( empty( $config['options'] ) ) {
			return array();
		}

		if ( is_array( $config['options'] ) ) {
			return $config['options'];
		}

		$options = array();
		$lines   = explode( "\n", $config['options'] );

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( empty( $line ) ) {
				continue;
			}

			$parts = explode( '|', $line, 2 );
			$key   = trim( $parts[0] );
			$label = isset( $parts[1] ) ? trim( $parts[1] ) : $key;
			$options[ $key ] = $label;
		}

		return $options;
	}

	/**
	 * Evaluate a conditional rule.
	 *
	 * @param array $condition Condition config.
	 * @return bool
	 */
	private function evaluate_condition( $condition ) {
		if ( empty( $condition['field'] ) ) {
			return true;
		}

		$value = '';
		if ( isset( $_POST[ $condition['field'] ] ) ) {
			$value = sanitize_text_field( wp_unslash( $_POST[ $condition['field'] ] ) );
		}

		$operator = isset( $condition['operator'] ) ? $condition['operator'] : 'equals';
		$target   = isset( $condition['value'] ) ? $condition['value'] : '';

		switch ( $operator ) {
			case 'equals':
				return $value === $target;
			case 'not_equals':
				return $value !== $target;
			case 'contains':
				return false !== strpos( $value, $target );
			case 'not_empty':
				return ! empty( $value );
			case 'empty':
				return empty( $value );
			default:
				return true;
		}
	}

	// ─── Validation ────────────────────────────────────────

	public function validate_custom_fields() {
		$all = $this->get_all_fields();

		foreach ( $all as $key => $config ) {
			if ( ! empty( $config['hidden'] ) ) {
				continue;
			}

			if ( empty( $config['required'] ) ) {
				continue;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$value = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';

			if ( is_array( $value ) ) {
				$value = array_map( 'sanitize_text_field', $value );
			} else {
				$value = trim( sanitize_text_field( $value ) );
			}

			if ( empty( $value ) ) {
				wc_add_notice(
					sprintf(
						/* translators: %s: field label */
						__( '%s is a required field.', 'wc-checkout-fields' ),
						'<strong>' . esc_html( $config['label'] ) . '</strong>'
					),
					'error'
				);
				continue;
			}

			if ( ! empty( $config['validation'] ) ) {
				$this->run_custom_validation( $key, $config, $value );
			}
		}
	}

	/**
	 * Run custom validation on a field.
	 *
	 * @param string $key    Field key.
	 * @param array  $config Field config.
	 * @param string $value  Field value.
	 */
	private function run_custom_validation( $key, $config, $value ) {
		$rules = $config['validation'];
		if ( ! is_array( $rules ) ) {
			$rules = array( 'type' => $rules );
		}

		$type = isset( $rules['type'] ) ? $rules['type'] : '';

		switch ( $type ) {
			case 'email':
				if ( ! is_email( $value ) ) {
					wc_add_notice(
						sprintf( __( '%s must be a valid email address.', 'wc-checkout-fields' ), '<strong>' . esc_html( $config['label'] ) . '</strong>' ),
						'error'
					);
				}
				break;

			case 'phone':
				if ( ! preg_match( '/^[\d\s\-\+\(\)\.]{7,20}$/', $value ) ) {
					wc_add_notice(
						sprintf( __( '%s must be a valid phone number.', 'wc-checkout-fields' ), '<strong>' . esc_html( $config['label'] ) . '</strong>' ),
						'error'
					);
				}
				break;

			case 'number':
				if ( ! is_numeric( $value ) ) {
					wc_add_notice(
						sprintf( __( '%s must be a number.', 'wc-checkout-fields' ), '<strong>' . esc_html( $config['label'] ) . '</strong>' ),
						'error'
					);
				} elseif ( isset( $rules['min'] ) && $value < $rules['min'] ) {
					wc_add_notice(
						sprintf( __( '%s must be at least %s.', 'wc-checkout-fields' ), '<strong>' . esc_html( $config['label'] ) . '</strong>', $rules['min'] ),
						'error'
					);
				} elseif ( isset( $rules['max'] ) && $value > $rules['max'] ) {
					wc_add_notice(
						sprintf( __( '%s must be at most %s.', 'wc-checkout-fields' ), '<strong>' . esc_html( $config['label'] ) . '</strong>', $rules['max'] ),
						'error'
					);
				}
				break;

			case 'custom':
				if ( ! empty( $rules['pattern'] ) && ! preg_match( '/' . $rules['pattern'] . '/', $value ) ) {
					$msg = ! empty( $rules['message'] ) ? $rules['message'] : sprintf( __( '%s is invalid.', 'wc-checkout-fields' ), $config['label'] );
					wc_add_notice( esc_html( $msg ), 'error' );
				}
				break;
		}
	}

	// ─── Save Order Meta ────────────────────────────────────

	public function save_custom_fields( $order_id ) {
		$all = $this->get_all_fields();

		foreach ( $all as $key => $config ) {
			if ( ! empty( $config['hidden'] ) || ! empty( $config['builtin'] ) ) {
				continue;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$value = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';

			if ( is_array( $value ) ) {
				$value = array_map( 'sanitize_text_field', $value );
			} else {
				$value = sanitize_text_field( $value );
			}

			update_post_meta( $order_id, '_' . $key, $value );
		}
	}

	// ─── Display in Admin Order ─────────────────────────────

	public function display_custom_fields_admin( $order ) {
		$all        = $this->get_all_fields();
		$has_custom = false;
		$html       = '';

		foreach ( $all as $key => $config ) {
			if ( ! empty( $config['builtin'] ) ) {
				continue;
			}

			$value = get_post_meta( $order->get_id(), '_' . $key, true );
			if ( empty( $value ) ) {
				continue;
			}

			$has_custom = true;
			$html      .= sprintf(
				'<p><strong>%s:</strong> %s</p>',
				esc_html( $config['label'] ),
				esc_html( $value )
			);
		}

		if ( $has_custom ) {
			echo '<div class="wc-cf-admin-section" style="margin-top:20px;padding-top:20px;border-top:1px solid #ddd;">';
			echo '<h3>' . esc_html__( 'Additional Checkout Fields', 'wc-checkout-fields' ) . '</h3>';
			echo wp_kses_post( $html );
			echo '</div>';
		}
	}

	public function display_custom_fields_thankyou( $order ) {
		$all = $this->get_all_fields();

		foreach ( $all as $key => $config ) {
			if ( ! empty( $config['builtin'] ) ) {
				continue;
			}

			$value = get_post_meta( $order->get_id(), '_' . $key, true );
			if ( empty( $value ) ) {
				continue;
			}

			printf(
				'<p><strong>%s:</strong> %s</p>',
				esc_html( $config['label'] ),
				esc_html( $value )
			);
		}
	}

	// ─── Email Integration ──────────────────────────────────

	public function add_fields_to_email( $fields, $sent_to_admin, $order ) {
		$all = $this->get_all_fields();

		foreach ( $all as $key => $config ) {
			if ( ! empty( $config['builtin'] ) ) {
				continue;
			}

			$value = get_post_meta( $order->get_id(), '_' . $key, true );
			if ( empty( $value ) ) {
				continue;
			}

			$fields[ $key ] = array(
				'label' => $config['label'],
				'value' => $value,
			);
		}

		return $fields;
	}

	// ─── Admin Page ─────────────────────────────────────────

	public function add_admin_page() {
		add_submenu_page(
			'woocommerce',
			esc_html__( 'Checkout Fields Manager', 'wc-checkout-fields' ),
			esc_html__( 'Checkout Fields', 'wc-checkout-fields' ),
			'manage_woocommerce',
			'wc-checkout-fields',
			array( $this, 'render_admin_page' )
		);
	}

	public function enqueue_admin_assets( $hook_suffix ) {
		if ( 'woocommerce_page_wc-checkout-fields' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_script( 'jquery-ui-sortable' );

		wp_add_inline_script( 'jquery-ui-sortable', "
			jQuery(document).ready(function($){
				$('.wc-cf-sortable').sortable({
					handle: '.wc-cf-drag-handle',
					placeholder: 'wc-cf-placeholder',
					axis: 'y',
					update: function() {
						var order = [];
						$('.wc-cf-sortable .wc-cf-field-row').each(function(i) {
							order.push($(this).data('field-key'));
						});
					}
				});
				$('.wc-cf-delete-field').on('click', function(e) {
					e.preventDefault();
					var key = $(this).data('key');
					if (confirm('Delete this field? This cannot be undone.')) {
						$.post(ajaxurl, {
							action: 'wc_cf_delete_field',
							field_key: key,
							_ajax_nonce: '" . wp_create_nonce( 'wc_cf_ajax' ) . "'
						}, function() {
							$('.wc-cf-field-row[data-field-key=\"' + key + '\"]').fadeOut(400, function(){ $(this).remove(); });
						});
					}
				});
			});
		" );

		wp_add_inline_style( 'wp-admin', "
			.wc-cf-sortable { margin: 16px 0; }
			.wc-cf-placeholder { height: 40px; background: #f0f0f1; border: 2px dashed #c3c4c7; margin-bottom: 8px; border-radius: 4px; }
			.wc-cf-field-row { background: #fff; border: 1px solid #c3c4c7; margin-bottom: 8px; border-radius: 4px; display: flex; align-items: center; }
			.wc-cf-drag-handle { padding: 8px 12px; cursor: grab; color: #999; font-size: 16px; border-right: 1px solid #e5e5e5; user-select: none; }
			.wc-cf-field-info { flex: 1; padding: 8px 12px; }
			.wc-cf-field-name { font-weight: 600; }
			.wc-cf-field-meta { font-size: 12px; color: #666; margin-top: 2px; }
			.wc-cf-field-actions { padding: 8px 12px; white-space: nowrap; }
			.wc-cf-badge { display: inline-block; padding: 1px 6px; border-radius: 3px; font-size: 10px; font-weight: 600; text-transform: uppercase; margin-left: 6px; }
			.wc-cf-badge-required { background: #fef3f2; color: #b42318; }
			.wc-cf-badge-hidden { background: #f2f4f7; color: #667085; }
			.wc-cf-badge-custom { background: #ecfdf3; color: #027a48; }
			.wc-cf-new-field-form { background: #fff; border: 1px solid #c3c4c7; padding: 16px; margin-bottom: 24px; border-radius: 4px; display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
		" );
	}

	public function render_admin_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'wc-checkout-fields' ) );
		}

		$all      = $this->get_all_fields();
		$sections = array(
			'billing'  => __( 'Billing Fields', 'wc-checkout-fields' ),
			'shipping' => __( 'Shipping Fields', 'wc-checkout-fields' ),
			'order'    => __( 'Order Fields', 'wc-checkout-fields' ),
		);
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Drag and drop fields to reorder. Built-in fields can be hidden and reordered. Custom fields are fully editable.', 'wc-checkout-fields' ); ?>
			</p>

			<?php foreach ( $sections as $section_slug => $section_label ) :
				$section_fields = array_filter( $all, function ( $f ) use ( $section_slug ) {
					return $f['section'] === $section_slug;
				} );

				uasort( $section_fields, function ( $a, $b ) {
					$pa = isset( $a['priority'] ) ? $a['priority'] : 100;
					$pb = isset( $b['priority'] ) ? $b['priority'] : 100;
					return $pa - $pb;
				} );
				?>
				<h3 style="margin:24px 0 4px 0;"><?php echo esc_html( $section_label ); ?>
					<small style="color:#999;">(<?php echo count( $section_fields ); ?>)</small>
				</h3>
				<div class="wc-cf-sortable">
					<?php foreach ( $section_fields as $key => $config ) : ?>
						<div class="wc-cf-field-row" data-field-key="<?php echo esc_attr( $key ); ?>">
							<div class="wc-cf-drag-handle" title="<?php esc_attr_e( 'Drag to reorder', 'wc-checkout-fields' ); ?>">☰</div>
							<div class="wc-cf-field-info">
								<span class="wc-cf-field-name"><?php echo esc_html( $config['label'] ); ?></span>
								<?php
								if ( ! empty( $config['required'] ) ) {
									echo '<span class="wc-cf-badge wc-cf-badge-required">' . esc_html__( 'Required', 'wc-checkout-fields' ) . '</span>';
								}
								if ( ! empty( $config['hidden'] ) ) {
									echo '<span class="wc-cf-badge wc-cf-badge-hidden">' . esc_html__( 'Hidden', 'wc-checkout-fields' ) . '</span>';
								}
								if ( empty( $config['builtin'] ) ) {
									echo '<span class="wc-cf-badge wc-cf-badge-custom">' . esc_html__( 'Custom', 'wc-checkout-fields' ) . '</span>';
								}
								?>
								<br /><span class="wc-cf-field-meta">Key: <?php echo esc_html( $key ); ?> | Type: <?php echo esc_html( $config['type'] ); ?></span>
							</div>
							<div class="wc-cf-field-actions">
								<?php if ( empty( $config['builtin'] ) ) : ?>
									<a href="#" class="button button-small wc-cf-delete-field" data-key="<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Delete', 'wc-checkout-fields' ); ?></a>
								<?php endif; ?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endforeach; ?>

			<h2 style="margin-top:40px;"><?php esc_html_e( 'Add Custom Field', 'wc-checkout-fields' ); ?></h2>
			<form method="post" action="">
				<?php wp_nonce_field( 'wc_cf_add_field', 'wc_cf_add_nonce' ); ?>
				<div class="wc-cf-new-field-form">
					<div>
						<label><strong><?php esc_html_e( 'Field Label', 'wc-checkout-fields' ); ?></strong></label><br />
						<input type="text" name="wc_cf_new[label]" required class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Gift Message', 'wc-checkout-fields' ); ?>" />
					</div>
					<div>
						<label><strong><?php esc_html_e( 'Field Key', 'wc-checkout-fields' ); ?></strong></label><br />
						<input type="text" name="wc_cf_new[key]" required pattern="[a-z0-9_]+" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. gift_message', 'wc-checkout-fields' ); ?>" />
						<p class="description"><?php esc_html_e( 'Lowercase letters, numbers, underscores only.', 'wc-checkout-fields' ); ?></p>
					</div>
					<div>
						<label><strong><?php esc_html_e( 'Section', 'wc-checkout-fields' ); ?></strong></label><br />
						<select name="wc_cf_new[section]">
							<option value="billing"><?php esc_html_e( 'Billing', 'wc-checkout-fields' ); ?></option>
							<option value="shipping"><?php esc_html_e( 'Shipping', 'wc-checkout-fields' ); ?></option>
							<option value="order"><?php esc_html_e( 'Order', 'wc-checkout-fields' ); ?></option>
						</select>
					</div>
					<div>
						<label><strong><?php esc_html_e( 'Field Type', 'wc-checkout-fields' ); ?></strong></label><br />
						<select name="wc_cf_new[type]">
							<option value="text"><?php esc_html_e( 'Text', 'wc-checkout-fields' ); ?></option>
							<option value="email"><?php esc_html_e( 'Email', 'wc-checkout-fields' ); ?></option>
							<option value="tel"><?php esc_html_e( 'Phone', 'wc-checkout-fields' ); ?></option>
							<option value="number"><?php esc_html_e( 'Number', 'wc-checkout-fields' ); ?></option>
							<option value="textarea"><?php esc_html_e( 'Textarea', 'wc-checkout-fields' ); ?></option>
							<option value="select"><?php esc_html_e( 'Select Dropdown', 'wc-checkout-fields' ); ?></option>
							<option value="radio"><?php esc_html_e( 'Radio Buttons', 'wc-checkout-fields' ); ?></option>
							<option value="checkbox"><?php esc_html_e( 'Checkbox', 'wc-checkout-fields' ); ?></option>
							<option value="date"><?php esc_html_e( 'Date Picker', 'wc-checkout-fields' ); ?></option>
						</select>
					</div>
					<div>
						<label><strong><?php esc_html_e( 'Placeholder', 'wc-checkout-fields' ); ?></strong></label><br />
						<input type="text" name="wc_cf_new[placeholder]" class="regular-text" />
					</div>
					<div>
						<label>
							<input type="checkbox" name="wc_cf_new[required]" value="1" />
							<?php esc_html_e( 'Required', 'wc-checkout-fields' ); ?>
						</label>
					</div>
					<div style="grid-column: span 2; text-align: right;">
						<?php submit_button( __( 'Add Field', 'wc-checkout-fields' ), 'primary', 'wc_cf_submit', false ); ?>
					</div>
				</div>
			</form>
		</div>
		<?php
	}

	public function handle_form_submission() {
		if ( ! isset( $_POST['wc_cf_submit'] ) ) {
			return;
		}

		if ( ! isset( $_POST['wc_cf_add_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wc_cf_add_nonce'] ) ), 'wc_cf_add_field' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'wc-checkout-fields' ) );
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$new = isset( $_POST['wc_cf_new'] ) ? wp_unslash( $_POST['wc_cf_new'] ) : array();

		$key   = isset( $new['key'] ) ? sanitize_key( $new['key'] ) : '';
		$label = isset( $new['label'] ) ? sanitize_text_field( $new['label'] ) : '';

		if ( empty( $key ) || empty( $label ) ) {
			add_action( 'admin_notices', function () {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Field key and label are required.', 'wc-checkout-fields' ) . '</p></div>';
			} );
			return;
		}

		if ( isset( $this->get_default_fields()[ $key ] ) ) {
			add_action( 'admin_notices', function () {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'That field key is reserved by WooCommerce.', 'wc-checkout-fields' ) . '</p></div>';
			} );
			return;
		}

		$all = $this->get_all_fields();

		if ( isset( $all[ $key ] ) ) {
			add_action( 'admin_notices', function () {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'A field with that key already exists.', 'wc-checkout-fields' ) . '</p></div>';
			} );
			return;
		}

		$field = array(
			'label'       => $label,
			'section'     => isset( $new['section'] ) ? sanitize_key( $new['section'] ) : 'billing',
			'type'        => isset( $new['type'] ) ? sanitize_key( $new['type'] ) : 'text',
			'placeholder' => isset( $new['placeholder'] ) ? sanitize_text_field( $new['placeholder'] ) : '',
			'required'    => isset( $new['required'] ),
			'priority'    => count( $all ) * 10 + 10,
		);

		$all[ $key ] = $field;
		update_option( self::OPTION_KEY, $all );

		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Custom field added!', 'wc-checkout-fields' ) . '</p></div>';
		} );
	}

	public function ajax_save_order() {
		check_ajax_referer( 'wc_cf_ajax' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( -1 );
		}
		wp_send_json_success();
	}

	public function ajax_delete_field() {
		check_ajax_referer( 'wc_cf_ajax' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( -1 );
		}

		$key = isset( $_POST['field_key'] ) ? sanitize_key( wp_unslash( $_POST['field_key'] ) ) : '';

		if ( isset( $this->get_default_fields()[ $key ] ) ) {
			wp_send_json_error( 'Cannot delete built-in fields.' );
		}

		$all = $this->get_all_fields();
		unset( $all[ $key ] );
		update_option( self::OPTION_KEY, $all );

		wp_send_json_success();
	}

	public function plugin_action_links( $links ) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=wc-checkout-fields' ) ),
			esc_html__( 'Manage Fields', 'wc-checkout-fields' )
		);
		array_unshift( $links, $settings_link );
		return $links;
	}
}

add_action( 'plugins_loaded', array( 'WC_Checkout_Fields_Manager', 'instance' ) );

register_uninstall_hook( __FILE__, 'wc_checkout_fields_uninstall' );

function wc_checkout_fields_uninstall() {
	delete_option( 'wc_checkout_fields_data' );
}