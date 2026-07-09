<?php
/**
 * Admin settings page.
 *
 * @since 2.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the settings page under PMPro menu.
 */
function pmprocc_admin_menu() {
	add_submenu_page(
		'pmpro-dashboard',
		__( 'PMPro Constant Contact', 'pmpro-constant-contact' ),
		__( 'Constant Contact', 'pmpro-constant-contact' ),
		'manage_options',
		'pmpro-constantcontact',
		'pmprocc_settings_page'
	);
}
add_action( 'admin_menu', 'pmprocc_admin_menu' );

/**
 * Register settings.
 */
function pmprocc_admin_init() {
	register_setting( 'pmprocc_options', 'pmprocc_options', 'pmprocc_options_validate' );
}
add_action( 'admin_init', 'pmprocc_admin_init' );

/**
 * Validate and sanitize options on save.
 *
 * @param array $input Raw input from form.
 * @return array Sanitized options.
 */
function pmprocc_options_validate( $input ) {
	// Start from the saved options so settings that weren't rendered on the
	// form (e.g. while disconnected, only the API key field is shown) are
	// preserved instead of being wiped.
	$output = get_option( 'pmprocc_options', array() );
	if ( ! is_array( $output ) ) {
		$output = array();
	}

	// API Key (client ID) and API Secret.
	$output['api_key']    = ! empty( $input['api_key'] ) ? sanitize_text_field( $input['api_key'] ) : '';
	$output['api_secret'] = ! empty( $input['api_secret'] ) ? sanitize_text_field( $input['api_secret'] ) : '';

	// If the full settings form wasn't rendered, only update the API credentials.
	if ( empty( $input['full_form'] ) ) {
		return $output;
	}

	// Behavioral settings. Defaults here match the activation defaults so
	// upgraded and fresh installs behave the same.
	$output['sync_profile_update'] = ! empty( $input['sync_profile_update'] ) ? sanitize_text_field( $input['sync_profile_update'] ) : 'yes';
	$output['remove_tags']         = ! empty( $input['remove_tags'] ) ? 1 : 0;
	$output['unsubscribe']         = ! empty( $input['unsubscribe'] ) ? sanitize_text_field( $input['unsubscribe'] ) : 'yes';
	$output['background_sync']     = ! empty( $input['background_sync'] ) ? 1 : 0;
	$output['logging_enabled']     = ! empty( $input['logging_enabled'] ) ? 1 : 0;

	// Non-member lists.
	$output['users_lists'] = ! empty( $input['users_lists'] ) ? array_map( 'sanitize_text_field', $input['users_lists'] ) : array();

	// Per-level lists and tags.
	$levels = pmpro_getAllLevels( true, true );
	foreach ( $levels as $level ) {
		$list_key = 'level_' . $level->id . '_lists';
		$tag_key  = 'level_' . $level->id . '_tags';

		$output[ $list_key ] = ! empty( $input[ $list_key ] ) ? array_map( 'sanitize_text_field', $input[ $list_key ] ) : array();
		$output[ $tag_key ]  = ! empty( $input[ $tag_key ] ) ? array_map( 'sanitize_text_field', $input[ $tag_key ] ) : array();
	}

	return $output;
}

/**
 * Render the settings page.
 */
function pmprocc_settings_page() {
	$options = get_option( 'pmprocc_options', array() );
	$api     = PMPro_Constant_Contact_API::get_instance();

	// The refresh action triggers remote writes (custom-field creation), so gate
	// it behind a nonce to prevent it firing from a forged GET link (CSRF).
	$force_refresh = ! empty( $_GET['pmprocc_refresh'] )
		&& isset( $_GET['_wpnonce'] )
		&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'pmprocc_refresh' );
	$lists         = $api->is_connected() ? $api->get_lists( $force_refresh ) : array();
	$tags          = $api->is_connected() ? $api->get_tags( $force_refresh ) : array();

	// get_lists()/get_tags() return a WP_Error on API failure so a real
	// connection/auth problem can be surfaced instead of an empty list. Capture
	// the messages here and fall back to an empty array for the checkbox lists.
	$lists_error = '';
	$tags_error  = '';
	if ( is_wp_error( $lists ) ) {
		$lists_error = $lists->get_error_message();
		$lists       = array();
	}
	if ( is_wp_error( $tags ) ) {
		$tags_error = $tags->get_error_message();
		$tags       = array();
	}

	// Rebuild the custom field ID map so it doesn't go stale (e.g. when
	// upgrading from a prior version, or if fields were changed in Constant
	// Contact). Refreshes from the remote on the "Refresh Lists & Tags" action
	// or whenever the map is currently empty.
	if ( $api->is_connected() && ( $force_refresh || ! get_option( 'pmprocc_custom_field_map' ) ) ) {
		$api->ensure_custom_fields();
	}
	$levels        = function_exists( 'pmpro_getAllLevels' ) ? pmpro_getAllLevels( true, true ) : array();
	?>
	<div class="wrap pmpro_admin pmpro-admin">
		<h1><?php esc_html_e( 'Constant Contact Integration', 'pmpro-constant-contact' ); ?></h1>

		<?php pmprocc_admin_notices(); ?>

		<form method="post" action="options.php">
			<?php settings_fields( 'pmprocc_options' ); ?>

			<h2><?php esc_html_e( 'Authentication', 'pmpro-constant-contact' ); ?></h2>
			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="pmprocc_api_key"><?php esc_html_e( 'API Key (Client ID)', 'pmpro-constant-contact' ); ?></label>
					</th>
					<td>
						<input type="text" id="pmprocc_api_key" name="pmprocc_options[api_key]"
							value="<?php echo esc_attr( ! empty( $options['api_key'] ) ? $options['api_key'] : '' ); ?>"
							class="regular-text" />
						<p class="description">
							<?php
							printf(
								/* translators: %s: Constant Contact developer portal URL */
								esc_html__( 'Create an application at %s to get your API Key.', 'pmpro-constant-contact' ),
								'<a href="https://app.constantcontact.com/pages/dma/portal/" target="_blank">Constant Contact Developer Portal</a>'
							);
							?>
						</p>
						<p class="description">
							<?php
							printf(
								/* translators: %s: OAuth redirect URI */
								esc_html__( 'Set your application\'s Redirect URI to: %s', 'pmpro-constant-contact' ),
								'<code>' . esc_html( admin_url( 'admin.php?page=pmpro-constantcontact&pmprocc_oauth_callback=1' ) ) . '</code>'
							);
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="pmprocc_api_secret"><?php esc_html_e( 'API Secret', 'pmpro-constant-contact' ); ?></label>
					</th>
					<td>
						<input type="password" id="pmprocc_api_secret" name="pmprocc_options[api_secret]"
							value="<?php echo esc_attr( ! empty( $options['api_secret'] ) ? $options['api_secret'] : '' ); ?>"
							class="regular-text" autocomplete="off" />
						<p class="description">
							<?php esc_html_e( 'The client secret generated for your application. Constant Contact only shows this once when the app is created. Leave blank only if your application was created as a public (PKCE) client without a secret.', 'pmpro-constant-contact' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Connection Status', 'pmpro-constant-contact' ); ?></th>
					<td>
						<?php if ( $api->is_connected() ) : ?>
							<span class="pmprocc-status pmprocc-connected">
								&#10003; <?php esc_html_e( 'Connected to Constant Contact', 'pmpro-constant-contact' ); ?>
							</span>
							<br/>
							<?php
							$disconnect_url = wp_nonce_url(
								admin_url( 'admin.php?page=pmpro-constantcontact&pmprocc_oauth_callback=1&pmprocc_disconnect=1' ),
								'pmprocc_disconnect'
							);
							?>
							<a href="<?php echo esc_url( $disconnect_url ); ?>" class="button button-secondary" style="margin-top: 5px;">
								<?php esc_html_e( 'Disconnect', 'pmpro-constant-contact' ); ?>
							</a>
						<?php elseif ( ! empty( $options['api_key'] ) ) : ?>
							<span class="pmprocc-status pmprocc-disconnected">
								&#10007; <?php esc_html_e( 'Not connected', 'pmpro-constant-contact' ); ?>
							</span>
							<br/>
							<?php
							// Save options first, then authorize.
							$auth_url = $api->get_authorization_url();
							if ( $auth_url ) : ?>
								<a href="<?php echo esc_url( $auth_url ); ?>" class="button button-primary" style="margin-top: 5px;">
									<?php esc_html_e( 'Connect to Constant Contact', 'pmpro-constant-contact' ); ?>
								</a>
							<?php endif; ?>
						<?php else : ?>
							<span class="description"><?php esc_html_e( 'Enter your API Key and Secret, then save to connect.', 'pmpro-constant-contact' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
			</table>

			<?php if ( $api->is_connected() ) : ?>

				<input type="hidden" name="pmprocc_options[full_form]" value="1" />

				<hr />
				<h2>
					<?php esc_html_e( 'List & Tag Settings', 'pmpro-constant-contact' ); ?>
					<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=pmpro-constantcontact&pmprocc_refresh=1' ), 'pmprocc_refresh' ) ); ?>" class="page-title-action">
						<?php esc_html_e( 'Refresh Lists & Tags', 'pmpro-constant-contact' ); ?>
					</a>
				</h2>

				<?php if ( ! empty( $lists_error ) ) : ?>
					<div class="pmpro_message pmpro_error">
						<p><?php echo esc_html__( 'Error fetching lists from Constant Contact.', 'pmpro-constant-contact' ) . ' ' . esc_html( $lists_error ); ?></p>
					</div>
				<?php endif; ?>
				<?php if ( ! empty( $tags_error ) ) : ?>
					<div class="pmpro_message pmpro_error">
						<p><?php echo esc_html__( 'Error fetching tags from Constant Contact.', 'pmpro-constant-contact' ) . ' ' . esc_html( $tags_error ); ?></p>
					</div>
				<?php endif; ?>

				<?php if ( ! empty( $levels ) ) : ?>

					<h3><?php esc_html_e( 'Non-Member Lists', 'pmpro-constant-contact' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Users without a membership level will be added to these lists.', 'pmpro-constant-contact' ); ?></p>
					<table class="form-table">
						<tr>
							<th scope="row"><?php esc_html_e( 'Lists', 'pmpro-constant-contact' ); ?></th>
							<td>
								<?php
								$selected = ! empty( $options['users_lists'] ) ? $options['users_lists'] : array();
								pmprocc_render_checkbox_list( 'pmprocc_options[users_lists][]', $lists, $selected, 'list_id' );
								?>
							</td>
						</tr>
					</table>

					<h3><?php esc_html_e( 'Membership Level Settings', 'pmpro-constant-contact' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Assign lists and tags for each membership level. Members will be added to the selected lists and tagged when they have the corresponding level.', 'pmpro-constant-contact' ); ?></p>

					<?php foreach ( $levels as $level ) : ?>
						<h4><?php echo esc_html( $level->name ); ?></h4>
						<table class="form-table">
							<tr>
								<th scope="row"><?php esc_html_e( 'Lists', 'pmpro-constant-contact' ); ?></th>
								<td>
									<?php
									$key      = 'level_' . $level->id . '_lists';
									$selected = ! empty( $options[ $key ] ) ? $options[ $key ] : array();
									pmprocc_render_checkbox_list( "pmprocc_options[{$key}][]", $lists, $selected, 'list_id' );
									?>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Tags', 'pmpro-constant-contact' ); ?></th>
								<td>
									<?php
									$key      = 'level_' . $level->id . '_tags';
									$selected = ! empty( $options[ $key ] ) ? $options[ $key ] : array();
									pmprocc_render_checkbox_list( "pmprocc_options[{$key}][]", $tags, $selected, 'tag_id' );
									?>
								</td>
							</tr>
						</table>
					<?php endforeach; ?>

				<?php else : ?>
					<p><?php esc_html_e( 'No membership levels found. Create membership levels in PMPro first.', 'pmpro-constant-contact' ); ?></p>
				<?php endif; ?>

				<hr />
				<h2><?php esc_html_e( 'Sync Settings', 'pmpro-constant-contact' ); ?></h2>
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Unsubscribe on Level Change', 'pmpro-constant-contact' ); ?></th>
						<td>
							<?php $unsub = ! empty( $options['unsubscribe'] ) ? $options['unsubscribe'] : 'yes'; ?>
							<select name="pmprocc_options[unsubscribe]">
								<option value="no" <?php selected( $unsub, 'no' ); ?>><?php esc_html_e( 'No', 'pmpro-constant-contact' ); ?></option>
								<option value="yes" <?php selected( $unsub, 'yes' ); ?>><?php esc_html_e( 'Yes (remove from old level lists)', 'pmpro-constant-contact' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'When a member changes or loses a level, remove them from lists they no longer qualify for.', 'pmpro-constant-contact' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Remove Tags on Level Change', 'pmpro-constant-contact' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="pmprocc_options[remove_tags]" value="1"
									<?php checked( ! empty( $options['remove_tags'] ) ); ?> />
								<?php esc_html_e( 'Remove tags from contacts when they lose or change a membership level.', 'pmpro-constant-contact' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Only tags mapped to PMPro levels above are affected. Manually applied tags are never removed.', 'pmpro-constant-contact' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Sync on Profile Update', 'pmpro-constant-contact' ); ?></th>
						<td>
							<?php $sync_profile = ! empty( $options['sync_profile_update'] ) ? $options['sync_profile_update'] : 'yes'; ?>
							<select name="pmprocc_options[sync_profile_update]">
								<option value="no" <?php selected( $sync_profile, 'no' ); ?>><?php esc_html_e( 'No', 'pmpro-constant-contact' ); ?></option>
								<option value="subscriber_only" <?php selected( $sync_profile, 'subscriber_only' ); ?>><?php esc_html_e( 'Yes (contact data only)', 'pmpro-constant-contact' ); ?></option>
								<option value="yes" <?php selected( $sync_profile, 'yes' ); ?>><?php esc_html_e( 'Yes (contact data + tags)', 'pmpro-constant-contact' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Sync contact data to Constant Contact when a user updates their WordPress profile.', 'pmpro-constant-contact' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Process in Background', 'pmpro-constant-contact' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="pmprocc_options[background_sync]" value="1"
									<?php checked( ! empty( $options['background_sync'] ) ); ?> />
								<?php esc_html_e( 'Use Action Scheduler for background processing (recommended).', 'pmpro-constant-contact' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Debug Logging', 'pmpro-constant-contact' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="pmprocc_options[logging_enabled]" value="1"
									<?php checked( ! empty( $options['logging_enabled'] ) ); ?> />
								<?php esc_html_e( 'Enable debug logging for API calls and sync events.', 'pmpro-constant-contact' ); ?>
							</label>
							<?php
							$log_file = pmprocc_get_log_file_path();
							if ( ! empty( $log_file ) && file_exists( $log_file ) ) :
								?>
								<p class="description">
									<?php
									printf(
										/* translators: %s: Log file size */
										esc_html__( 'Log file size: %s', 'pmpro-constant-contact' ),
										esc_html( size_format( filesize( $log_file ) ) )
									);
									$log_file_link = add_query_arg(
										array(
											'pmpro_restricted_file_dir' => 'logs',
											'pmpro_restricted_file'     => 'pmpro-constant-contact.log',
										),
										home_url()
									);
									echo ' <a href="' . esc_url( $log_file_link ) . '" target="_blank">' . esc_html__( 'Download log.', 'pmpro-constant-contact' ) . '</a>';
									?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
				</table>

			<?php endif; ?>

			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}

/**
 * Render a checkbox list for lists or tags.
 *
 * @param string $name     Input name attribute.
 * @param array  $items    Items to display.
 * @param array  $selected Currently selected item IDs.
 * @param string $id_key   Array key for item ID ('list_id' or 'tag_id').
 */
function pmprocc_render_checkbox_list( $name, $items, $selected, $id_key ) {
	if ( empty( $items ) ) {
		echo '<p class="description">' . esc_html__( 'No items found. Check your connection or refresh.', 'pmpro-constant-contact' ) . '</p>';
		return;
	}

	echo '<fieldset class="pmprocc-checkbox-list">';
	foreach ( $items as $item ) {
		$id    = $item[ $id_key ];
		$label = $item['name'];
		$checked = in_array( $id, $selected, true ) ? ' checked' : '';
		printf(
			'<label><input type="checkbox" name="%s" value="%s"%s /> %s</label><br/>',
			esc_attr( $name ),
			esc_attr( $id ),
			$checked,
			esc_html( $label )
		);
	}
	echo '</fieldset>';
}

/**
 * Display admin notices.
 */
function pmprocc_admin_notices() {
	if ( ! empty( $_GET['settings-updated'] ) ) {
		echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'pmpro-constant-contact' ) . '</p></div>';
	}

	if ( ! empty( $_GET['pmprocc_connected'] ) ) {
		echo '<div class="notice notice-success"><p>' . esc_html__( 'Successfully connected to Constant Contact!', 'pmpro-constant-contact' ) . '</p></div>';
	}

	if ( ! empty( $_GET['pmprocc_disconnected'] ) ) {
		echo '<div class="notice notice-info"><p>' . esc_html__( 'Disconnected from Constant Contact.', 'pmpro-constant-contact' ) . '</p></div>';
	}

	if ( ! empty( $_GET['pmprocc_error'] ) ) {
		$error = sanitize_text_field( wp_unslash( $_GET['pmprocc_error'] ) );
		$messages = array(
			'oauth_denied'    => __( 'Authorization was denied. Please try again.', 'pmpro-constant-contact' ),
			'state_mismatch'  => __( 'Security validation failed. Please try connecting again.', 'pmpro-constant-contact' ),
			'verifier_expired' => __( 'Authorization session expired. Please try connecting again.', 'pmpro-constant-contact' ),
			'token_exchange'  => __( 'Failed to complete authorization. Check your API Key and API Secret and try again.', 'pmpro-constant-contact' ),
		);
		$message = isset( $messages[ $error ] ) ? $messages[ $error ] : __( 'An error occurred. Please try again.', 'pmpro-constant-contact' );

		// Include the OAuth error returned by Constant Contact, if we have one,
		// so the admin can see why the token exchange failed.
		if ( 'token_exchange' === $error ) {
			$token_error = get_transient( 'pmprocc_last_token_error' );
			if ( ! empty( $token_error ) ) {
				delete_transient( 'pmprocc_last_token_error' );
				/* translators: %s: OAuth error message returned by Constant Contact */
				$message .= ' ' . sprintf( __( 'Constant Contact said: %s', 'pmpro-constant-contact' ), $token_error );
			}
		}

		echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
	}
}
