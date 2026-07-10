<?php
/**
 * OAuth callback handler.
 *
 * Processes the redirect from Constant Contact after the user authorizes the app.
 *
 * @since 2.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handle the OAuth callback on our settings page.
 */
function pmprocc_handle_oauth_callback() {
	if ( ! is_admin() || empty( $_GET['pmprocc_oauth_callback'] ) ) {
		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// Handle disconnect request.
	if ( ! empty( $_GET['pmprocc_disconnect'] ) ) {
		check_admin_referer( 'pmprocc_disconnect' );
		$api = PMPro_Constant_Contact_API::get_instance();
		$api->disconnect();
		delete_transient( 'pmprocc_all_lists' );
		delete_transient( 'pmprocc_all_tags' );
		wp_safe_redirect( admin_url( 'admin.php?page=pmpro-constantcontact&pmprocc_disconnected=1' ) );
		exit;
	}

	// Handle OAuth error response.
	if ( ! empty( $_GET['error'] ) ) {
		pmprocc_log( 'OAuth error: ' . sanitize_text_field( wp_unslash( $_GET['error'] ) ) );
		wp_safe_redirect( admin_url( 'admin.php?page=pmpro-constantcontact&pmprocc_error=oauth_denied' ) );
		exit;
	}

	// Handle successful authorization code.
	if ( empty( $_GET['code'] ) || empty( $_GET['state'] ) ) {
		return;
	}

	$code  = sanitize_text_field( wp_unslash( $_GET['code'] ) );
	$state = sanitize_text_field( wp_unslash( $_GET['state'] ) );

	// Validate state parameter. Transients are keyed per user (see
	// get_authorization_url()) so concurrent admins don't clobber each other.
	$stored_state = get_transient( 'pmprocc_oauth_state_' . get_current_user_id() );
	if ( empty( $stored_state ) || ! hash_equals( $stored_state, $state ) ) {
		pmprocc_log( 'OAuth state mismatch.' );
		wp_safe_redirect( admin_url( 'admin.php?page=pmpro-constantcontact&pmprocc_error=state_mismatch' ) );
		exit;
	}

	// Get the stored PKCE verifier.
	$verifier = get_transient( 'pmprocc_oauth_verifier_' . get_current_user_id() );
	if ( empty( $verifier ) ) {
		pmprocc_log( 'OAuth PKCE verifier expired.' );
		wp_safe_redirect( admin_url( 'admin.php?page=pmpro-constantcontact&pmprocc_error=verifier_expired' ) );
		exit;
	}

	// Clean up transients.
	delete_transient( 'pmprocc_oauth_state_' . get_current_user_id() );
	delete_transient( 'pmprocc_oauth_verifier_' . get_current_user_id() );

	// Exchange code for tokens.
	$api     = PMPro_Constant_Contact_API::get_instance();
	$success = $api->exchange_code( $code, $verifier );

	if ( $success ) {
		wp_safe_redirect( admin_url( 'admin.php?page=pmpro-constantcontact&pmprocc_connected=1' ) );
	} else {
		wp_safe_redirect( admin_url( 'admin.php?page=pmpro-constantcontact&pmprocc_error=token_exchange' ) );
	}
	exit;
}
add_action( 'admin_init', 'pmprocc_handle_oauth_callback', 5 );
