<?php
/**
 * Plugin Name: WooCommerce Custom Thank You Pages
 * Plugin URI:  https://acidburn.dev/plugins/woocommerce-thank-you-pages
 * Description: Redirect or display custom content on WooCommerce thank-you pages based on products in the order or order total.
 * Author:      AcidBurn
 * Version:     1.0.0
 * Text Domain: wc-thank-you-pages
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 9.0
 *
 * @package WC_Thank_You_Pages
 */

defined( 'ABSPATH' ) || exit;

// --------------------------------------------------
// Constants
// --------------------------------------------------
define( 'WCTYP_VERSION', '1.0.0' );
define( 'WCTYP_OPTION_RULES', 'wc_typ_rules' );
define( 'WCTYP_OPTION_DEFAULT', 'wc_typ_default' );
define( 'WCTYP_MENU_SLUG', 'wc-thank-you-pages' );

// --------------------------------------------------
// Uninstall hook
// --------------------------------------------------
register_uninstall_hook( __FILE__, 'wc_typ_uninstall' );
/**
 * Clean up all plugin options on uninstall.
 */
function wc_typ_uninstall() {
	delete_option( WCTYP_OPTION_RULES );
	delete_option( WCTYP_OPTION_DEFAULT );
}

// --------------------------------------------------
// Bootstrap — only when WooCommerce is active
// --------------------------------------------------
add_action( 'plugins_loaded', 'wc_typ_init' );
/**
 * Plugin init — check WC dependency, then hook everything in.
 */
function wc_typ_init() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'wc_typ_missing_wc_notice' );
		return;
	}

	add_action( 'admin_menu', 'wc_typ_admin_menu', 50 );
	add_action( 'admin_init', 'wc_typ_register_settings' );
	add_action( 'admin_post_wc_typ_save_rules', 'wc_typ_handle_save_rules' );
	add_action( 'admin_post_wc_typ_save_default', 'wc_typ_handle_save_default' );
	add_action( 'woocommerce_thankyou', 'wc_typ_process_thankyou', 1 );
	add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'wc_typ_action_links' );
}

/**
 * Admin notice when WooCommerce is missing.
 */
function wc_typ_missing_wc_notice() {
	?>
	<div class="notice notice-warning is-dismissible">
		<p><?php esc_html_e( 'WooCommerce Custom Thank You Pages requires WooCommerce to be installed and activated.', 'wc-thank-you-pages' ); ?></p>
	</div>
	<?php
}

// --------------------------------------------------
// Admin menu: WooCommerce → Thank You Pages
// --------------------------------------------------
/**
 * Register the submenu page under WooCommerce.
 */
function wc_typ_admin_menu() {
	add_submenu_page(
		'woocommerce',
		__( 'Thank You Pages', 'wc-thank-you-pages' ),
		__( 'Thank You Pages', 'wc-thank-you-pages' ),
		'manage_woocommerce',
		WCTYP_MENU_SLUG,
		'wc_typ_admin_page'
	);
}

/**
 * Admin page renderer.
 */
function wc_typ_admin_page() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions.', 'wc-thank-you-pages' ) );
	}

	$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'rules';
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Custom Thank You Pages', 'wc-thank-you-pages' ); ?></h1>

		<h2 class="nav-tab-wrapper">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . WCTYP_MENU_SLUG . '&tab=rules' ) ); ?>"
			   class="nav-tab <?php echo 'rules' === $active_tab ? 'nav-tab-active' : ''; ?>">
				<?php esc_html_e( 'Rules', 'wc-thank-you-pages' ); ?>
			</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . WCTYP_MENU_SLUG . '&tab=default' ) ); ?>"
			   class="nav-tab <?php echo 'default' === $active_tab ? 'nav-tab-active' : ''; ?>">
				<?php esc_html_e( 'Default Page', 'wc-thank-you-pages' ); ?>
			</a>
		</h2>

		<?php
		if ( 'default' === $active_tab ) {
			wc_typ_render_default_tab();
		} else {
			wc_typ_render_rules_tab();
		}
		?>
	</div>
	<?php
}

// --------------------------------------------------
// Settings registration
// --------------------------------------------------
/**
 * Register settings with sanitize callbacks.
 */
function wc_typ_register_settings() {
	register_setting(
		'wc_typ_rules_group',
		WCTYP_OPTION_RULES,
		array(
			'sanitize_callback' => 'wc_typ_sanitize_rules',
			'default'           => array(),
		)
	);
	register_setting(
		'wc_typ_default_group',
		WCTYP_OPTION_DEFAULT,
		array(
			'sanitize_callback' => 'wc_typ_sanitize_default',
			'default'           => array(
				'action' => 'default',
			),
		)
	);
}

/**
 * Sanitize rules array.
 *
 * @param array $input Raw input.
 * @return array Sanitized rules.
 */
function wc_typ_sanitize_rules( $input ) {
	if ( ! is_array( $input ) ) {
		return array();
	}

	$sanitized = array();
	foreach ( $input as $index => $rule ) {
		if ( ! is_array( $rule ) ) {
			continue;
		}
		$r = array();

		$r['id']       = isset( $rule['id'] ) ? sanitize_key( $rule['id'] ) : uniqid( 'typ_' );
		$r['name']     = isset( $rule['name'] ) ? sanitize_text_field( $rule['name'] ) : '';
		$r['type']     = isset( $rule['type'] ) && in_array( $rule['type'], array( 'products', 'order_total' ), true ) ? $rule['type'] : 'products';
		$r['action']   = isset( $rule['action'] ) && in_array( $rule['action'], array( 'redirect', 'html' ), true ) ? $rule['action'] : 'redirect';

		if ( 'products' === $r['type'] ) {
			$raw_ids = isset( $rule['product_ids'] ) ? $rule['product_ids'] : '';
			$ids     = array();
			foreach ( explode( ',', $raw_ids ) as $part ) {
				$id = absint( trim( $part ) );
				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}
			$r['product_ids'] = $ids;
		} else {
			$r['compare']  = isset( $rule['compare'] ) && in_array( $rule['compare'], array( '>', '>=', '==', '!=', '<', '<=' ), true ) ? $rule['compare'] : '>=';
			$r['amount']   = isset( $rule['amount'] ) ? floatval( $rule['amount'] ) : 0;
		}

		if ( 'redirect' === $r['action'] ) {
			$r['redirect_url'] = isset( $rule['redirect_url'] ) ? esc_url_raw( $rule['redirect_url'] ) : '';
		} else {
			$r['html_content'] = isset( $rule['html_content'] ) ? wp_kses_post( $rule['html_content'] ) : '';
		}

		if ( ! empty( $r['name'] ) ) {
			$sanitized[] = $r;
		}
	}

	return $sanitized;
}

/**
 * Sanitize default page settings.
 *
 * @param array $input Raw input.
 * @return array Sanitized defaults.
 */
function wc_typ_sanitize_default( $input ) {
	if ( ! is_array( $input ) ) {
		return array( 'action' => 'default' );
	}

	$default = array();
	$default['action'] = isset( $input['action'] ) && in_array( $input['action'], array( 'default', 'redirect', 'html' ), true )
		? $input['action']
		: 'default';

	if ( 'redirect' === $default['action'] ) {
		$default['redirect_url'] = isset( $input['redirect_url'] ) ? esc_url_raw( $input['redirect_url'] ) : '';
	} elseif ( 'html' === $default['action'] ) {
		$default['html_content'] = isset( $input['html_content'] ) ? wp_kses_post( $input['html_content'] ) : '';
	}

	return $default;
}

// --------------------------------------------------
// Rules Tab
// --------------------------------------------------
/**
 * Render the Rules tab.
 */
function wc_typ_render_rules_tab() {
	$rules = get_option( WCTYP_OPTION_RULES, array() );

	if ( isset( $_GET['deleted'] ) && '1' === $_GET['deleted'] ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Rule deleted.', 'wc-thank-you-pages' ) . '</p></div>';
	}
	if ( isset( $_GET['saved'] ) && '1' === $_GET['saved'] ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Rules saved.', 'wc-thank-you-pages' ) . '</p></div>';
	}

	$edit_rule = null;
	if ( isset( $_GET['edit'] ) ) {
		$edit_key = sanitize_key( $_GET['edit'] );
		foreach ( $rules as $r ) {
			if ( $r['id'] === $edit_key ) {
				$edit_rule = $r;
				break;
			}
		}
	}

	if ( $edit_rule ) {
		wc_typ_render_rule_form( $edit_rule, $rules );
	} else {
		wc_typ_render_rule_list( $rules );
		echo '<hr>';
		wc_typ_render_rule_form( null, $rules );
	}
}

/**
 * Render the rule list table.
 *
 * @param array $rules Current rules.
 */
function wc_typ_render_rule_list( $rules ) {
	if ( empty( $rules ) ) {
		echo '<p>' . esc_html__( 'No rules defined yet. Create one below.', 'wc-thank-you-pages' ) . '</p>';
		return;
	}
	?>
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Name', 'wc-thank-you-pages' ); ?></th>
				<th><?php esc_html_e( 'Condition', 'wc-thank-you-pages' ); ?></th>
				<th><?php esc_html_e( 'Action', 'wc-thank-you-pages' ); ?></th>
				<th><?php esc_html_e( 'Preview', 'wc-thank-you-pages' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'wc-thank-you-pages' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $rules as $rule ) : ?>
			<tr>
				<td><strong><?php echo esc_html( $rule['name'] ); ?></strong></td>
				<td>
					<?php if ( 'products' === $rule['type'] ) : ?>
						<?php esc_html_e( 'Products:', 'wc-thank-you-pages' ); ?>
						<?php echo esc_html( implode( ', ', $rule['product_ids'] ) ); ?>
					<?php else : ?>
						<?php esc_html_e( 'Total', 'wc-thank-you-pages' ); ?>
						<?php echo esc_html( $rule['compare'] . ' ' . wc_price( $rule['amount'] ) ); ?>
					<?php endif; ?>
				</td>
				<td>
					<?php if ( 'redirect' === $rule['action'] ) : ?>
						<?php esc_html_e( 'Redirect', 'wc-thank-you-pages' ); ?>
						<code><?php echo esc_url( $rule['redirect_url'] ); ?></code>
					<?php else : ?>
						<?php esc_html_e( 'HTML Content', 'wc-thank-you-pages' ); ?>
					<?php endif; ?>
				</td>
				<td>
					<?php
					$preview_url = add_query_arg(
						array(
							'wc_typ_preview' => $rule['id'],
							'_wpnonce'       => wp_create_nonce( 'wc_typ_preview_' . $rule['id'] ),
						),
						wc_get_page_permalink( 'shop' )
					);
					?>
					<a href="<?php echo esc_url( $preview_url ); ?>" target="_blank" class="button button-small">
						<?php esc_html_e( 'Preview', 'wc-thank-you-pages' ); ?>
					</a>
				</td>
				<td>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . WCTYP_MENU_SLUG . '&tab=rules&edit=' . esc_attr( $rule['id'] ) ) ); ?>" class="button button-small">
						<?php esc_html_e( 'Edit', 'wc-thank-you-pages' ); ?>
					</a>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
						<?php wp_nonce_field( 'wc_typ_delete_rule', '_wpnonce' ); ?>
						<input type="hidden" name="action" value="wc_typ_delete_rule">
						<input type="hidden" name="rule_id" value="<?php echo esc_attr( $rule['id'] ); ?>">
						<button type="submit" class="button button-small button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Delete this rule?', 'wc-thank-you-pages' ) ); ?>');">
							<?php esc_html_e( 'Delete', 'wc-thank-you-pages' ); ?>
						</button>
					</form>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

/**
 * Render the add/edit rule form.
 *
 * @param array|null $rule  Rule data for editing, or null for new.
 * @param array      $rules Full rules list (for ID collision avoidance).
 */
function wc_typ_render_rule_form( $rule, $rules ) {
	$is_edit   = null !== $rule;
	$rule_id   = $is_edit ? $rule['id'] : '';
	$name      = $is_edit ? $rule['name'] : '';
	$type      = $is_edit ? $rule['type'] : 'products';
	$action    = $is_edit ? $rule['action'] : 'redirect';
	$prod_ids  = $is_edit && isset( $rule['product_ids'] ) ? implode( ', ', $rule['product_ids'] ) : '';
	$compare   = $is_edit && isset( $rule['compare'] ) ? $rule['compare'] : '>=';
	$amount    = $is_edit && isset( $rule['amount'] ) ? $rule['amount'] : '';
	$redir_url = $is_edit && isset( $rule['redirect_url'] ) ? $rule['redirect_url'] : '';
	$html      = $is_edit && isset( $rule['html_content'] ) ? $rule['html_content'] : '';
	?>
	<h3><?php echo $is_edit ? esc_html__( 'Edit Rule', 'wc-thank-you-pages' ) : esc_html__( 'Add New Rule', 'wc-thank-you-pages' ); ?></h3>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'wc_typ_save_rules', 'wc_typ_nonce' ); ?>
		<input type="hidden" name="action" value="wc_typ_save_rules">
		<?php if ( $is_edit ) : ?>
			<input type="hidden" name="rule_id" value="<?php echo esc_attr( $rule_id ); ?>">
		<?php endif; ?>

		<table class="form-table">
			<tr>
				<th scope="row"><label for="wc_typ_rule_name"><?php esc_html_e( 'Rule Name', 'wc-thank-you-pages' ); ?></label></th>
				<td>
					<input type="text" id="wc_typ_rule_name" name="wc_typ_rule[name]"
						value="<?php echo esc_attr( $name ); ?>" class="regular-text" required>
					<p class="description"><?php esc_html_e( 'A label to identify this rule.', 'wc-thank-you-pages' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Condition Type', 'wc-thank-you-pages' ); ?></th>
				<td>
					<label><input type="radio" name="wc_typ_rule[type]" value="products" <?php checked( $type, 'products' ); ?>
						onchange="wcTypToggleCondition(this.value)"> <?php esc_html_e( 'Products in order', 'wc-thank-you-pages' ); ?></label><br>
					<label><input type="radio" name="wc_typ_rule[type]" value="order_total" <?php checked( $type, 'order_total' ); ?>
						onchange="wcTypToggleCondition(this.value)"> <?php esc_html_e( 'Order total', 'wc-thank-you-pages' ); ?></label>
				</td>
			</tr>

			<tr class="wc_typ_condition_products">
				<th scope="row"><label for="wc_typ_product_ids"><?php esc_html_e( 'Product IDs', 'wc-thank-you-pages' ); ?></label></th>
				<td>
					<input type="text" id="wc_typ_product_ids" name="wc_typ_rule[product_ids]"
						value="<?php echo esc_attr( $prod_ids ); ?>" class="regular-text"
						placeholder="<?php esc_attr_e( 'e.g. 123, 456, 789', 'wc-thank-you-pages' ); ?>">
					<p class="description"><?php esc_html_e( 'Comma-separated product IDs. Order must contain at least one.', 'wc-thank-you-pages' ); ?></p>
				</td>
			</tr>

			<tr class="wc_typ_condition_total" style="display:none">
				<th scope="row"><?php esc_html_e( 'Order Total Condition', 'wc-thank-you-pages' ); ?></th>
				<td>
					<select name="wc_typ_rule[compare]">
						<option value=">="  <?php selected( $compare, '>=' ); ?>>&gt;=</option>
						<option value=">"   <?php selected( $compare, '>' ); ?>>&gt;</option>
						<option value="=="  <?php selected( $compare, '==' ); ?>>==</option>
						<option value="!="  <?php selected( $compare, '!=' ); ?>>!=</option>
						<option value="<"   <?php selected( $compare, '<' ); ?>>&lt;</option>
						<option value="<="  <?php selected( $compare, '<=' ); ?>>&lt;=</option>
					</select>
					<input type="number" name="wc_typ_rule[amount]" step="0.01" min="0"
						value="<?php echo esc_attr( $amount ); ?>"
						placeholder="<?php esc_attr_e( '0.00', 'wc-thank-you-pages' ); ?>">
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Action', 'wc-thank-you-pages' ); ?></th>
				<td>
					<label><input type="radio" name="wc_typ_rule[action]" value="redirect" <?php checked( $action, 'redirect' ); ?>
						onchange="wcTypToggleAction(this.value)"> <?php esc_html_e( 'Redirect to URL', 'wc-thank-you-pages' ); ?></label><br>
					<label><input type="radio" name="wc_typ_rule[action]" value="html" <?php checked( $action, 'html' ); ?>
						onchange="wcTypToggleAction(this.value)"> <?php esc_html_e( 'Show custom HTML', 'wc-thank-you-pages' ); ?></label>
				</td>
			</tr>

			<tr class="wc_typ_action_redirect">
				<th scope="row"><label for="wc_typ_redirect_url"><?php esc_html_e( 'Redirect URL', 'wc-thank-you-pages' ); ?></label></th>
				<td>
					<input type="url" id="wc_typ_redirect_url" name="wc_typ_rule[redirect_url]"
						value="<?php echo esc_attr( $redir_url ); ?>" class="regular-text code"
						placeholder="<?php esc_attr_e( 'https://example.com/thank-you', 'wc-thank-you-pages' ); ?>">
				</td>
			</tr>

			<tr class="wc_typ_action_html" style="display:none">
				<th scope="row"><label for="wc_typ_html_content"><?php esc_html_e( 'HTML Content', 'wc-thank-you-pages' ); ?></label></th>
				<td>
					<?php
					wp_editor(
						$html,
						'wc_typ_html_content',
						array(
							'textarea_name' => 'wc_typ_rule[html_content]',
							'textarea_rows' => 10,
							'media_buttons' => true,
							'teeny'         => false,
							'quicktags'     => true,
						)
					);
					?>
				</td>
			</tr>
		</table>

		<?php submit_button(
			$is_edit ? __( 'Update Rule', 'wc-thank-you-pages' ) : __( 'Add Rule', 'wc-thank-you-pages' ),
			'primary',
			'submit',
			true
		); ?>
	</form>

	<script>
	function wcTypToggleCondition(val) {
		document.querySelectorAll('.wc_typ_condition_products').forEach(function(el) {
			el.style.display = val === 'products' ? '' : 'none';
		});
		document.querySelectorAll('.wc_typ_condition_total').forEach(function(el) {
			el.style.display = val === 'order_total' ? '' : 'none';
		});
	}
	function wcTypToggleAction(val) {
		document.querySelectorAll('.wc_typ_action_redirect').forEach(function(el) {
			el.style.display = val === 'redirect' ? '' : 'none';
		});
		document.querySelectorAll('.wc_typ_action_html').forEach(function(el) {
			el.style.display = val === 'html' ? '' : 'none';
		});
	}
	(function() {
		var t = document.querySelector('input[name="wc_typ_rule[type]"]:checked');
		if (t) wcTypToggleCondition(t.value);
		var a = document.querySelector('input[name="wc_typ_rule[action]"]:checked');
		if (a) wcTypToggleAction(a.value);
	})();
	</script>
	<?php
}

// --------------------------------------------------
// Default Tab
// --------------------------------------------------
/**
 * Render the Default Page tab.
 */
function wc_typ_render_default_tab() {
	$default = get_option( WCTYP_OPTION_DEFAULT, array( 'action' => 'default' ) );
	$action  = isset( $default['action'] ) ? $default['action'] : 'default';
	$url     = isset( $default['redirect_url'] ) ? $default['redirect_url'] : '';
	$html    = isset( $default['html_content'] ) ? $default['html_content'] : '';
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'wc_typ_save_default', 'wc_typ_nonce_default' ); ?>
		<input type="hidden" name="action" value="wc_typ_save_default">

		<table class="form-table">
			<tr>
				<th scope="row"><?php esc_html_e( 'Fallback Behavior', 'wc-thank-you-pages' ); ?></th>
				<td>
					<label><input type="radio" name="wc_typ_default[action]" value="default" <?php checked( $action, 'default' ); ?>
						onchange="wcTypDefToggle(this.value)"> <?php esc_html_e( 'Show default WooCommerce thank-you page', 'wc-thank-you-pages' ); ?></label><br>
					<label><input type="radio" name="wc_typ_default[action]" value="redirect" <?php checked( $action, 'redirect' ); ?>
						onchange="wcTypDefToggle(this.value)"> <?php esc_html_e( 'Redirect to URL', 'wc-thank-you-pages' ); ?></label><br>
					<label><input type="radio" name="wc_typ_default[action]" value="html" <?php checked( $action, 'html' ); ?>
						onchange="wcTypDefToggle(this.value)"> <?php esc_html_e( 'Show custom HTML', 'wc-thank-you-pages' ); ?></label>
				</td>
			</tr>

			<tr class="wc_typ_def_redirect" style="<?php echo 'redirect' !== $action ? 'display:none' : ''; ?>">
				<th scope="row"><label for="wc_typ_def_redirect_url"><?php esc_html_e( 'Redirect URL', 'wc-thank-you-pages' ); ?></label></th>
				<td>
					<input type="url" id="wc_typ_def_redirect_url" name="wc_typ_default[redirect_url]"
						value="<?php echo esc_attr( $url ); ?>" class="regular-text code"
						placeholder="<?php esc_attr_e( 'https://example.com/thank-you', 'wc-thank-you-pages' ); ?>">
				</td>
			</tr>

			<tr class="wc_typ_def_html" style="<?php echo 'html' !== $action ? 'display:none' : ''; ?>">
				<th scope="row"><label for="wc_typ_def_html_content"><?php esc_html_e( 'HTML Content', 'wc-thank-you-pages' ); ?></label></th>
				<td>
					<?php
					wp_editor(
						$html,
						'wc_typ_def_html_content',
						array(
							'textarea_name' => 'wc_typ_default[html_content]',
							'textarea_rows' => 10,
							'media_buttons' => true,
							'teeny'         => false,
							'quicktags'     => true,
						)
					);
					?>
				</td>
			</tr>
		</table>

		<?php submit_button( __( 'Save Default Settings', 'wc-thank-you-pages' ), 'primary' ); ?>
	</form>

	<script>
	function wcTypDefToggle(val) {
		document.querySelectorAll('.wc_typ_def_redirect').forEach(function(el) {
			el.style.display = val === 'redirect' ? '' : 'none';
		});
		document.querySelectorAll('.wc_typ_def_html').forEach(function(el) {
			el.style.display = val === 'html' ? '' : 'none';
		});
	}
	</script>
	<?php
}

// --------------------------------------------------
// Form handlers (admin-post)
// --------------------------------------------------
/**
 * Handle saving rules.
 */
function wc_typ_handle_save_rules() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( esc_html__( 'Unauthorized.', 'wc-thank-you-pages' ) );
	}

	if ( ! isset( $_POST['wc_typ_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['wc_typ_nonce'] ), 'wc_typ_save_rules' ) ) {
		wp_die( esc_html__( 'Security check failed.', 'wc-thank-you-pages' ) );
	}

	$rules   = get_option( WCTYP_OPTION_RULES, array() );
	$new_raw = isset( $_POST['wc_typ_rule'] ) ? $_POST['wc_typ_rule'] : array();

	if ( ! is_array( $new_raw ) ) {
		wp_die( esc_html__( 'Invalid input.', 'wc-thank-you-pages' ) );
	}

	// Sanitize each field individually.
	$sanitized_new = array(
		'id'            => isset( $_POST['rule_id'] ) ? sanitize_key( $_POST['rule_id'] ) : uniqid( 'typ_' ),
		'name'          => isset( $new_raw['name'] ) ? sanitize_text_field( $new_raw['name'] ) : '',
		'type'          => isset( $new_raw['type'] ) && in_array( $new_raw['type'], array( 'products', 'order_total' ), true ) ? $new_raw['type'] : 'products',
		'action'        => isset( $new_raw['action'] ) && in_array( $new_raw['action'], array( 'redirect', 'html' ), true ) ? $new_raw['action'] : 'redirect',
	);

	if ( 'products' === $sanitized_new['type'] ) {
		$raw_ids    = isset( $new_raw['product_ids'] ) ? sanitize_text_field( $new_raw['product_ids'] ) : '';
		$ids        = array();
		foreach ( explode( ',', $raw_ids ) as $part ) {
			$id = absint( trim( $part ) );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
		$sanitized_new['product_ids'] = $ids;
	} else {
		$sanitized_new['compare'] = isset( $new_raw['compare'] ) && in_array( $new_raw['compare'], array( '>', '>=', '==', '!=', '<', '<=' ), true )
			? $new_raw['compare']
			: '>=';
		$sanitized_new['amount']  = isset( $new_raw['amount'] ) ? floatval( $new_raw['amount'] ) : 0;
	}

	if ( 'redirect' === $sanitized_new['action'] ) {
		$sanitized_new['redirect_url'] = isset( $new_raw['redirect_url'] ) ? esc_url_raw( $new_raw['redirect_url'] ) : '';
	} else {
		$sanitized_new['html_content'] = isset( $new_raw['html_content'] ) ? wp_kses_post( $new_raw['html_content'] ) : '';
	}

	// Update existing or add new.
	$updated    = false;
	$edit_id    = isset( $_POST['rule_id'] ) ? sanitize_key( $_POST['rule_id'] ) : '';
	foreach ( $rules as $key => $existing_rule ) {
		if ( $existing_rule['id'] === $edit_id ) {
			$rules[ $key ] = $sanitized_new;
			$updated       = true;
			break;
		}
	}
	if ( ! $updated && ! empty( $sanitized_new['name'] ) ) {
		$rules[] = $sanitized_new;
	}

	update_option( WCTYP_OPTION_RULES, $rules );

	wp_safe_redirect( admin_url( 'admin.php?page=' . WCTYP_MENU_SLUG . '&tab=rules&saved=1' ) );
	exit;
}

/**
 * Handle saving default settings.
 */
function wc_typ_handle_save_default() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( esc_html__( 'Unauthorized.', 'wc-thank-you-pages' ) );
	}

	if ( ! isset( $_POST['wc_typ_nonce_default'] ) || ! wp_verify_nonce( sanitize_key( $_POST['wc_typ_nonce_default'] ), 'wc_typ_save_default' ) ) {
		wp_die( esc_html__( 'Security check failed.', 'wc-thank-you-pages' ) );
	}

	$input  = isset( $_POST['wc_typ_default'] ) ? $_POST['wc_typ_default'] : array();
	if ( ! is_array( $input ) ) {
		$input = array();
	}

	$default = array();
	$default['action'] = isset( $input['action'] ) && in_array( $input['action'], array( 'default', 'redirect', 'html' ), true )
		? $input['action']
		: 'default';

	if ( 'redirect' === $default['action'] ) {
		$default['redirect_url'] = isset( $input['redirect_url'] ) ? esc_url_raw( $input['redirect_url'] ) : '';
	} elseif ( 'html' === $default['action'] ) {
		$default['html_content'] = isset( $input['html_content'] ) ? wp_kses_post( $input['html_content'] ) : '';
	}

	update_option( WCTYP_OPTION_DEFAULT, $default );

	wp_safe_redirect( admin_url( 'admin.php?page=' . WCTYP_MENU_SLUG . '&tab=default&saved=1' ) );
	exit;
}

/**
 * Handle rule deletion.
 */
add_action( 'admin_post_wc_typ_delete_rule', 'wc_typ_handle_delete_rule' );
function wc_typ_handle_delete_rule() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( esc_html__( 'Unauthorized.', 'wc-thank-you-pages' ) );
	}
	if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ), 'wc_typ_delete_rule' ) ) {
		wp_die( esc_html__( 'Security check failed.', 'wc-thank-you-pages' ) );
	}

	$rules  = get_option( WCTYP_OPTION_RULES, array() );
	$del_id = isset( $_POST['rule_id'] ) ? sanitize_key( $_POST['rule_id'] ) : '';

	foreach ( $rules as $key => $rule ) {
		if ( $rule['id'] === $del_id ) {
			unset( $rules[ $key ] );
			break;
		}
	}

	update_option( WCTYP_OPTION_RULES, array_values( $rules ) );
	wp_safe_redirect( admin_url( 'admin.php?page=' . WCTYP_MENU_SLUG . '&tab=rules&deleted=1' ) );
	exit;
}

// --------------------------------------------------
// Front-end: Process thank-you page
// --------------------------------------------------
/**
 * Intercept the WooCommerce thank-you page.
 *
 * @param int $order_id Order ID.
 */
function wc_typ_process_thankyou( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return;
	}

	$rules   = get_option( WCTYP_OPTION_RULES, array() );
	$matched = null;

	foreach ( $rules as $rule ) {
		if ( wc_typ_rule_matches( $rule, $order ) ) {
			$matched = $rule;
			break;
		}
	}

	if ( null !== $matched ) {
		wc_typ_apply_action( $matched, $order );
		return;
	}

	// No rule matched — apply default.
	$default = get_option( WCTYP_OPTION_DEFAULT, array( 'action' => 'default' ) );
	wc_typ_apply_action( $default, $order );
}

/**
 * Check if a rule matches a given order.
 *
 * @param array     $rule  Rule definition.
 * @param WC_Order  $order Order object.
 * @return bool
 */
function wc_typ_rule_matches( $rule, $order ) {
	if ( 'products' === $rule['type'] ) {
		if ( empty( $rule['product_ids'] ) ) {
			return false;
		}
		$order_items = $order->get_items();
		foreach ( $order_items as $item ) {
			$product = $item->get_product();
			if ( $product && in_array( $product->get_id(), $rule['product_ids'], true ) ) {
				return true;
			}
			// Also check variation parent ID.
			if ( $product && $product->is_type( 'variation' ) ) {
				$parent_id = $product->get_parent_id();
				if ( in_array( $parent_id, $rule['product_ids'], true ) ) {
					return true;
				}
			}
		}
		return false;
	}

	if ( 'order_total' === $rule['type'] ) {
		$total    = $order->get_total();
		$compare  = isset( $rule['compare'] ) ? $rule['compare'] : '>=';
		$amount   = isset( $rule['amount'] ) ? floatval( $rule['amount'] ) : 0;

		switch ( $compare ) {
			case '>':
				return $total > $amount;
			case '>=':
				return $total >= $amount;
			case '==':
				return abs( $total - $amount ) < 0.001;
			case '!=':
				return abs( $total - $amount ) >= 0.001;
			case '<':
				return $total < $amount;
			case '<=':
				return $total <= $amount;
			default:
				return false;
		}
	}

	return false;
}

/**
 * Apply a rule's action (redirect or HTML) for a given order.
 *
 * @param array    $rule  Rule or default config.
 * @param WC_Order $order Order object.
 */
function wc_typ_apply_action( $rule, $order ) {
	$action = isset( $rule['action'] ) ? $rule['action'] : 'default';

	// Allow other plugins to bail.
	if ( 'default' === $action ) {
		return;
	}

	if ( 'redirect' === $action && ! empty( $rule['redirect_url'] ) ) {
		// Check for preview flag — skip redirect so preview can render.
		if ( ! isset( $_GET['wc_typ_preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			wp_safe_redirect( esc_url_raw( $rule['redirect_url'] ) );
			exit;
		}
		// Preview mode: show a notice instead of redirecting.
		add_action(
			'woocommerce_thankyou',
			function() use ( $rule ) {
				wc_typ_render_preview_notice( $rule );
			},
			999
		);
		return;
	}

	if ( 'html' === $action && ! empty( $rule['html_content'] ) ) {
		// Suppress default thank-you output and render custom HTML.
		add_action(
			'woocommerce_before_thankyou',
			function () {
				remove_all_actions( 'woocommerce_thankyou' );
				remove_all_actions( 'woocommerce_before_thankyou' );
			},
			0
		);
		add_action(
			'woocommerce_thankyou',
			function () use ( $rule, $order ) {
				// Replace the default thank-you with our custom content.
				echo '<div class="wc-typ-custom-content">';
				echo wp_kses_post( wpautop( $rule['html_content'] ) );
				echo '</div>';
			},
			999
		);
	}
}

// --------------------------------------------------
// Preview
// --------------------------------------------------
/**
 * Render a preview notice when the preview link is clicked.
 *
 * @param array $rule The rule being previewed.
 */
function wc_typ_render_preview_notice( $rule ) {
	?>
	<div class="wc-typ-preview-notice" style="
		margin: 20px 0; padding: 20px 25px;
		background: #f0f6fc; border-left: 4px solid #72aee6;
		font-size: 14px; border-radius: 4px;">
		<h3 style="margin-top:0;"><?php esc_html_e( '🔍 Thank You Page Preview', 'wc-thank-you-pages' ); ?></h3>
		<p><strong><?php esc_html_e( 'Rule:', 'wc-thank-you-pages' ); ?></strong> <?php echo esc_html( $rule['name'] ); ?></p>
		<p><strong><?php esc_html_e( 'Action:', 'wc-thank-you-pages' ); ?></strong>
			<?php echo 'redirect' === $rule['action'] ? esc_html__( 'Redirect to:', 'wc-thank-you-pages' ) : esc_html__( 'Custom HTML Content', 'wc-thank-you-pages' ); ?>
		</p>
		<?php if ( 'redirect' === $rule['action'] && ! empty( $rule['redirect_url'] ) ) : ?>
			<p><code><?php echo esc_url( $rule['redirect_url'] ); ?></code></p>
			<p><em><?php esc_html_e( 'In preview mode, the redirect is not performed. The live checkout will redirect to the URL above.', 'wc-thank-you-pages' ); ?></em></p>
		<?php endif; ?>
		<p>
			<a href="<?php echo esc_url( $rule['redirect_url'] ); ?>" target="_blank" class="button button-primary">
				<?php esc_html_e( 'Test Redirect', 'wc-thank-you-pages' ); ?>
			</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . WCTYP_MENU_SLUG . '&tab=rules' ) ); ?>" class="button">
				<?php esc_html_e( 'Back to Rules', 'wc-thank-you-pages' ); ?>
			</a>
		</p>
	</div>
	<?php
}

/**
 * Handle preview query var on the front-end.
 */
add_action( 'init', 'wc_typ_handle_preview_query' );
function wc_typ_handle_preview_query() {
	if ( ! isset( $_GET['wc_typ_preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}

	$rule_id = sanitize_key( $_GET['wc_typ_preview'] ); // phpcs:ignore WordPress.Security.NonceVerification

	if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'wc_typ_preview_' . $rule_id ) ) {
		return;
	}

	$rules = get_option( WCTYP_OPTION_RULES, array() );
	$rule  = null;
	foreach ( $rules as $r ) {
		if ( $r['id'] === $rule_id ) {
			$rule = $r;
			break;
		}
	}

	if ( ! $rule ) {
		return;
	}

	// We'll let the preview notice render on the thank-you page via woocommerce_thankyou.
	// This just validates the nonce. The actual preview logic is in wc_typ_process_thankyou.
}

// --------------------------------------------------
// Plugin action links
// --------------------------------------------------
/**
 * Add Settings link to plugin row.
 *
 * @param array $links Existing links.
 * @return array
 */
function wc_typ_action_links( $links ) {
	$settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=' . WCTYP_MENU_SLUG ) ) . '">'
		. esc_html__( 'Settings', 'wc-thank-you-pages' ) . '</a>';
	array_unshift( $links, $settings_link );
	return $links;
}

// --------------------------------------------------
// Declare HPOS (High-Performance Order Storage) compatibility
// --------------------------------------------------
add_action( 'before_woocommerce_init', 'wc_typ_declare_hpos_compat' );
function wc_typ_declare_hpos_compat() {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			__FILE__,
			true
		);
	}
}