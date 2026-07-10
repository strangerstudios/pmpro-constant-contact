<?php
/**
 * Plugin Name: Paid Memberships Pro - Constant Contact Add On
 * Plugin URI: https://www.paidmembershipspro.com/add-ons/pmpro-constant-contact/
 * Description: Sync PMPro members to a Constant Contact list with tags assigned per membership level.
 * Version: 2.0
 * Author: Paid Memberships Pro
 * Author URI: https://www.paidmembershipspro.com
 * License: GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: pmpro-constant-contact
 * Domain Path: /languages
 *
 * Requires PHP: 7.4
 * Requires at least: 5.6
 * Tested up to: 6.9
 * Requires Plugins: paid-memberships-pro
 */

defined( 'ABSPATH' ) || exit;

define( 'PMPROCC_VERSION', '2.0' );
define( 'PMPROCC_DIR', plugin_dir_path( __FILE__ ) );
define( 'PMPROCC_BASENAME', plugin_basename( __FILE__ ) );
define( 'PMPROCC_URL', plugin_dir_url( __FILE__ ) );

/**
 * Load plugin files.
 */
function pmprocc_load_plugin() {
	// Only load if PMPro is active.
	if ( ! defined( 'PMPRO_VERSION' ) ) {
		return;
	}

	require_once PMPROCC_DIR . 'classes/class-pmpro-constant-contact-api.php';
	require_once PMPROCC_DIR . 'includes/oauth.php';
	require_once PMPROCC_DIR . 'includes/functions.php';
	require_once PMPROCC_DIR . 'includes/admin.php';
}
add_action( 'plugins_loaded', 'pmprocc_load_plugin' );

/**
 * Set default options on activation.
 */
function pmprocc_activation() {
	$defaults = array(
		'sync_profile_update' => 'yes',
		'remove_tags'         => 1,
		'background_sync'     => 1,
		'logging_enabled'     => 0,
	);

	// Merge defaults into any existing options so upgrades from a prior version
	// (which already stored pmprocc_options) still receive newly added keys,
	// while preserving any settings the site has already configured.
	$existing = get_option( 'pmprocc_options', array() );
	if ( ! is_array( $existing ) ) {
		$existing = array();
	}

	update_option( 'pmprocc_options', array_merge( $defaults, $existing ) );
}
register_activation_hook( __FILE__, 'pmprocc_activation' );

/**
 * Add plugin action links.
 */
function pmprocc_plugin_action_links( $links ) {
	$settings_link = sprintf(
		'<a href="%s">%s</a>',
		esc_url( admin_url( 'admin.php?page=pmpro-constantcontact' ) ),
		esc_html__( 'Settings', 'pmpro-constant-contact' )
	);
	array_unshift( $links, $settings_link );
	return $links;
}
add_filter( 'plugin_action_links_' . PMPROCC_BASENAME, 'pmprocc_plugin_action_links' );

/**
 * Enqueue admin CSS.
 */
function pmprocc_admin_enqueue_scripts( $hook ) {
	if ( false === strpos( $hook, 'pmpro-constantcontact' ) ) {
		return;
	}
	wp_enqueue_style( 'pmprocc-admin', PMPROCC_URL . 'css/admin.css', array(), PMPROCC_VERSION );
}
add_action( 'admin_enqueue_scripts', 'pmprocc_admin_enqueue_scripts' );

/**
 * Show admin notice if PMPro is not active.
 */
function pmprocc_admin_notice_no_pmpro() {
	if ( defined( 'PMPRO_VERSION' ) ) {
		return;
	}
	?>
	<div class="notice notice-error">
		<p><?php esc_html_e( 'Paid Memberships Pro - Constant Contact Add On requires Paid Memberships Pro to be installed and active.', 'pmpro-constant-contact' ); ?></p>
	</div>
	<?php
}
add_action( 'admin_notices', 'pmprocc_admin_notice_no_pmpro' );
