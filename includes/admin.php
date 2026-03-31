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
	$output = array();

	// API Key (client ID).
	$output['api_key'] = ! empty( $input['api_key'] ) ? sanitize_text_field( $input['api_key'] ) : '';

	// Behavioral settings.
	$output['sync_profile_update'] = ! empty( $input['sync_profile_update'] ) ? sanitize_text_field( $input['sync_profile_update'] ) : 'no';
	$output['remove_tags']         = ! empty( $input['remove_tags'] ) ? 1 : 0;
	$output['unsubscribe']         = ! empty( $input['unsubscribe'] ) ? sanitize_text_field( $input['unsubscribe'] ) : 'no';
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

	$force_refresh = ! empty( $_GET['pmprocc_refresh'] );
	$lists         = $api->is_connected() ? $api->get_lists( $force_refresh ) : array();
	$tags          = $api->is_connected() ? $api->get_tags( $force_refresh ) : array();
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
							<span class="description"><?php esc_html_e( 'Enter your API Key and save to connect.', 'pmpro-constant-contact' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
			</table>

			<?php if ( $api->is_connected() ) : ?>

				<hr />
				<h2>
					<?php esc_html_e( 'List & Tag Settings', 'pmpro-constant-contact' ); ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=pmpro-constantcontact&pmprocc_refresh=1' ) ); ?>" class="page-title-action">
						<?php esc_html_e( 'Refresh Lists & Tags', 'pmpro-constant-contact' ); ?>
					</a>
				</h2>

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
							$log_file = ( defined( 'PMPRO_DIR' ) ? PMPRO_DIR . '/logs/' : PMPROCC_DIR . 'logs/' ) . 'pmpro-constant-contact.log';
							if ( file_exists( $log_file ) ) :
								?>
								<p class="description">
									<?php
									printf(
										/* translators: %s: Log file size */
										esc_html__( 'Log file size: %s', 'pmpro-constant-contact' ),
										esc_html( size_format( filesize( $log_file ) ) )
									);
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
	if ( ! empty( $_GET['pmprocc_connected'] ) ) {
		echo '<div class="notice notice-success"><p>' . esc_html__( 'Successfully connected to Constant Contact!', 'pmpro-constant-contact' ) . '</p></div>';
	}

	if ( ! empty( $_GET['pmprocc_disconnected'] ) ) {
		echo '<div class="notice notice-info"><p>' . esc_html__( 'Disconnected from Constant Contact.', 'pmpro-constant-contact' ) . '</p></div>';
	}

	if ( ! empty( $_GET['pmprocc_error'] ) ) {
		$error = sanitize_text_field( $_GET['pmprocc_error'] );
		$messages = array(
			'oauth_denied'    => __( 'Authorization was denied. Please try again.', 'pmpro-constant-contact' ),
			'state_mismatch'  => __( 'Security validation failed. Please try connecting again.', 'pmpro-constant-contact' ),
			'verifier_expired' => __( 'Authorization session expired. Please try connecting again.', 'pmpro-constant-contact' ),
			'token_exchange'  => __( 'Failed to complete authorization. Check your API Key and try again.', 'pmpro-constant-contact' ),
		);
		$message = isset( $messages[ $error ] ) ? $messages[ $error ] : __( 'An error occurred. Please try again.', 'pmpro-constant-contact' );
		echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
	}
}
