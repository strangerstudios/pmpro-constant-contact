<?php
/**
 * Core sync functions and PMPro integration hooks.
 *
 * Handles syncing membership level changes, profile updates,
 * and checkout events to Constant Contact.
 *
 * @since 2.0
 */

defined( 'ABSPATH' ) || exit;

// ------------------------------------------------------------------
// Logging
// ------------------------------------------------------------------

/**
 * Log a message if logging is enabled.
 *
 * @param string $message Log message.
 */
function pmprocc_log( $message ) {
	$options = get_option( 'pmprocc_options', array() );
	if ( empty( $options['logging_enabled'] ) ) {
		return;
	}

	$log_file = pmprocc_get_log_file_path();
	if ( empty( $log_file ) ) {
		return;
	}

	$entry = '[' . gmdate( 'Y-m-d H:i:s' ) . '] ' . $message . "\n";

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	file_put_contents( $log_file, $entry, FILE_APPEND | LOCK_EX );
}

/**
 * Mask an email address for logging (PII).
 *
 * Turns "jane@example.com" into "j****e@example.com".
 *
 * @param string $email Email address.
 * @return string Masked email.
 */
function pmprocc_mask_email( $email ) {
	if ( empty( $email ) || false === strpos( $email, '@' ) ) {
		return '';
	}

	list( $local, $domain ) = explode( '@', $email, 2 );

	$len = strlen( $local );
	if ( $len <= 2 ) {
		$masked_local = substr( $local, 0, 1 ) . '****';
	} else {
		$masked_local = substr( $local, 0, 1 ) . '****' . substr( $local, -1 );
	}

	return $masked_local . '@' . $domain;
}

/**
 * Get the path to the debug log file.
 *
 * Uses PMPro's restricted files directory (uploads/pmpro-<random>/logs/),
 * which core protects from direct web access, matching how the Stripe
 * webhook log is stored.
 *
 * @return string Log file path, or empty string if unavailable.
 */
function pmprocc_get_log_file_path() {
	if ( ! function_exists( 'pmpro_get_restricted_file_path' ) ) {
		return '';
	}

	return pmpro_get_restricted_file_path( 'logs', 'pmpro-constant-contact.log' );
}

// ------------------------------------------------------------------
// Sync: Enqueue for User
// ------------------------------------------------------------------

/**
 * Enqueue a sync for a user, either via Action Scheduler or immediately.
 *
 * @param int  $user_id     WordPress user ID.
 * @param bool $update_tags Whether to sync tags in addition to subscriber data.
 */
function pmprocc_enqueue_sync_for_user( $user_id, $update_tags = true ) {
	$options = get_option( 'pmprocc_options', array() );

	if ( ! empty( $options['background_sync'] ) && class_exists( 'PMPro_Action_Scheduler' ) ) {
		PMPro_Action_Scheduler::instance()->maybe_add_task(
			'pmprocc_sync_contact_for_user',
			array( $user_id, $update_tags ),
			'pmprocc_sync_tasks'
		);
	} else {
		pmprocc_sync_contact_for_user( $user_id, $update_tags );
	}
}

/**
 * Register the Action Scheduler callback.
 */
function pmprocc_register_action_scheduler() {
	add_action( 'pmprocc_sync_contact_for_user', 'pmprocc_sync_contact_for_user', 10, 2 );
}
add_action( 'init', 'pmprocc_register_action_scheduler' );

// ------------------------------------------------------------------
// Sync: Core Logic
// ------------------------------------------------------------------

/**
 * Sync a single user to Constant Contact.
 *
 * Adds members to the configured list and optionally manages tags. Matching
 * the Kit Add On, contacts are never removed from the list — losing a
 * membership level only removes the PMPro-controlled tags.
 *
 * @param int  $user_id     WordPress user ID.
 * @param bool $update_tags Whether to sync tags.
 */
function pmprocc_sync_contact_for_user( $user_id, $update_tags = true ) {
	$api = PMPro_Constant_Contact_API::get_instance();
	if ( ! $api->is_connected() ) {
		return;
	}

	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return;
	}

	$options     = get_option( 'pmprocc_options', array() );
	$master_list = ! empty( $options['master_list'] ) ? $options['master_list'] : '';

	// Contacts can only be created/updated through a list, so nothing can sync
	// until a list is selected on the settings page.
	if ( empty( $master_list ) ) {
		pmprocc_log( "No list selected in settings; skipping sync for user {$user_id}." );
		return;
	}

	// Get the user's current membership levels.
	$levels    = pmpro_getMembershipLevelsForUser( $user_id );
	$level_ids = wp_list_pluck( $levels, 'id' );

	pmprocc_log( "Syncing user {$user_id} (" . pmprocc_mask_email( $user->user_email ) . '), levels: ' . implode( ',', $level_ids ) );

	// Nothing to sync for a user with no levels and no stored contact.
	// Mirrors pmpro-kit's "no levels and no subscriber ID" bail to avoid
	// needless remote calls.
	$stored_contact_id = get_user_meta( $user_id, 'pmprocc_contact_id', true );
	if ( empty( $level_ids ) && empty( $stored_contact_id ) ) {
		pmprocc_log( "Nothing to sync for user {$user_id} (no levels, no stored contact)." );
		return;
	}

	// ------------------------------------------------------------------
	// Upsert the contact.
	// ------------------------------------------------------------------
	$contact_data = array(
		'email_address'    => $user->user_email,
		'create_source'    => 'Account',
		'list_memberships' => array( $master_list ),
	);

	// The sign_up_form endpoint rejects empty name fields.
	if ( '' !== $user->first_name ) {
		$contact_data['first_name'] = $user->first_name;
	}
	if ( '' !== $user->last_name ) {
		$contact_data['last_name'] = $user->last_name;
	}

	/**
	 * Filter contact data before sending to Constant Contact.
	 *
	 * Custom fields can be added here as a 'custom_fields' array of
	 * { custom_field_id, value } entries.
	 *
	 * @param array   $contact_data Contact data for the upsert.
	 * @param WP_User $user         The WordPress user.
	 * @param array   $levels       The user's membership levels.
	 */
	$contact_data = apply_filters( 'pmprocc_contact_data', $contact_data, $user, $levels );

	$result = $api->upsert_contact( $contact_data );

	if ( is_wp_error( $result ) ) {
		pmprocc_log( "Failed to upsert contact for user {$user_id}: " . $result->get_error_message() );
		return;
	}

	$contact_id = ! empty( $result['contact_id'] ) ? $result['contact_id'] : '';
	if ( $contact_id ) {
		update_user_meta( $user_id, 'pmprocc_contact_id', $contact_id );
	}

	pmprocc_log( "Upserted contact {$contact_id} for user {$user_id}" );

	// ------------------------------------------------------------------
	// Handle tags.
	// ------------------------------------------------------------------
	if ( $update_tags && $contact_id ) {
		pmprocc_sync_tags_for_contact( $user_id, $contact_id, $level_ids );
	}
}

/**
 * Sync tags for a contact based on their membership levels.
 *
 * Only modifies tags that PMPro controls (mapped to levels).
 *
 * @param int    $user_id    WordPress user ID.
 * @param string $contact_id Constant Contact contact ID.
 * @param array  $level_ids  Current membership level IDs.
 */
function pmprocc_sync_tags_for_contact( $user_id, $contact_id, $level_ids ) {
	$api     = PMPro_Constant_Contact_API::get_instance();
	$options = get_option( 'pmprocc_options', array() );

	// Build the set of tags this user should have.
	$required_tags = array();
	foreach ( $level_ids as $lid ) {
		$level_tags    = ! empty( $options[ 'level_' . $lid . '_tags' ] ) ? $options[ 'level_' . $lid . '_tags' ] : array();
		$required_tags = array_merge( $required_tags, $level_tags );
	}
	$required_tags = array_unique( array_filter( $required_tags ) );

	$user = get_userdata( $user_id );

	/**
	 * Filter the set of tag IDs that should be assigned to a contact.
	 *
	 * Tag IDs added here are applied even if they are not part of the
	 * level-to-tag mappings (the "controlled" set). This is intentional: a
	 * filter that explicitly adds a tag is expected to take effect. Note,
	 * however, that tags added this way are not in the controlled set and so
	 * will not be removed automatically when they no longer apply.
	 *
	 * @param array   $required_tags Tag IDs to assign based on membership levels.
	 * @param WP_User $user          The WordPress user.
	 * @param array   $level_ids     Current membership level IDs.
	 */
	$required_tags = apply_filters( 'pmprocc_contact_tag_ids', $required_tags, $user, $level_ids );

	// Get all controlled tags (any tag mapped to any level).
	$controlled_tags = pmprocc_get_all_configured_tags();

	/**
	 * Filter the set of tag IDs that PMPro controls (and may add/remove).
	 *
	 * @param array $controlled_tags Tag IDs mapped to any membership level.
	 */
	$controlled_tags = apply_filters( 'pmprocc_controlled_tag_ids', $controlled_tags );

	// Get the contact's current tags. The upsert (sign_up_form) response does not
	// return taggings, but the contact_id is already known, so fetch the contact
	// directly with include=taggings rather than doing a separate email lookup.
	$contact = $api->get_contact( $contact_id, 'taggings' );
	if ( is_wp_error( $contact ) ) {
		pmprocc_log( "Failed to retrieve tags for contact {$contact_id}: " . $contact->get_error_message() );
		return;
	}

	$current_tags = ! empty( $contact['taggings'] ) ? $contact['taggings'] : array();

	// Only look at controlled tags.
	$current_controlled = array_intersect( $current_tags, $controlled_tags );

	// Tags to add.
	$tags_to_add = array_diff( $required_tags, $current_controlled );
	if ( ! empty( $tags_to_add ) ) {
		$result = $api->add_tags_to_contacts( array( $contact_id ), array_values( $tags_to_add ) );
		if ( is_wp_error( $result ) ) {
			pmprocc_log( "Failed to queue tag additions for contact {$contact_id}: " . $result->get_error_message() );
		} else {
			pmprocc_log( "Queued tags to add to contact {$contact_id}: " . implode( ',', $tags_to_add ) );
		}
	}

	// Tags to remove.
	if ( ! empty( $options['remove_tags'] ) ) {
		$tags_to_remove = array_diff( $current_controlled, $required_tags );
		if ( ! empty( $tags_to_remove ) ) {
			$result = $api->remove_tags_from_contacts( array( $contact_id ), array_values( $tags_to_remove ) );
			if ( is_wp_error( $result ) ) {
				pmprocc_log( "Failed to queue tag removals for contact {$contact_id}: " . $result->get_error_message() );
			} else {
				pmprocc_log( "Queued tags to remove from contact {$contact_id}: " . implode( ',', $tags_to_remove ) );
			}
		}
	}
}

// ------------------------------------------------------------------
// Helpers
// ------------------------------------------------------------------

/**
 * Get all tag IDs configured across all levels.
 *
 * @return array
 */
function pmprocc_get_all_configured_tags() {
	$options  = get_option( 'pmprocc_options', array() );
	$all_tags = array();

	$levels = pmpro_getAllLevels( true, true );
	foreach ( $levels as $level ) {
		$key = 'level_' . $level->id . '_tags';
		if ( ! empty( $options[ $key ] ) ) {
			$all_tags = array_merge( $all_tags, $options[ $key ] );
		}
	}

	return array_unique( array_filter( $all_tags ) );
}

// ------------------------------------------------------------------
// PMPro Hooks
// ------------------------------------------------------------------

/**
 * Sync when membership levels change.
 *
 * @param array $old_user_levels Array of old levels keyed by user ID.
 */
function pmprocc_pmpro_after_all_membership_level_changes( $old_user_levels ) {
	if ( empty( $old_user_levels ) || ! is_array( $old_user_levels ) ) {
		return;
	}

	foreach ( array_keys( $old_user_levels ) as $user_id ) {
		pmprocc_enqueue_sync_for_user( intval( $user_id ), true );
	}
}
add_action( 'pmpro_after_all_membership_level_changes', 'pmprocc_pmpro_after_all_membership_level_changes' );

/**
 * Sync when a user profile is updated.
 *
 * @param int          $user_id       WordPress user ID.
 * @param WP_User|null $old_user_data The user's data before the update.
 */
function pmprocc_profile_update( $user_id, $old_user_data = null ) {
	$options = get_option( 'pmprocc_options', array() );

	// If the user's email changed, reconcile the existing Constant Contact
	// contact (keyed by the OLD email) so the upsert below doesn't orphan it.
	// This always runs, even when "Sync on Profile Update" is "No": that setting
	// gates ongoing field/tag syncing, but an email change must still be
	// reconciled to avoid leaving a stale contact behind (matching Mailchimp's
	// behavior of always following email changes by stored ID).
	if ( $old_user_data && isset( $old_user_data->user_email ) ) {
		$new_user_data = get_userdata( $user_id );
		if ( $new_user_data && $new_user_data->user_email !== $old_user_data->user_email ) {
			pmprocc_handle_email_change( $user_id, $old_user_data->user_email, $new_user_data->user_email );
		}
	}

	if ( empty( $options['sync_profile_update'] ) || 'no' === $options['sync_profile_update'] ) {
		return;
	}

	$update_tags = ( 'yes' === $options['sync_profile_update'] );
	pmprocc_enqueue_sync_for_user( $user_id, $update_tags );
}
add_action( 'profile_update', 'pmprocc_profile_update', 10, 2 );

/**
 * Update an existing Constant Contact contact's email when a WordPress user
 * changes their email address, so we don't orphan the old contact.
 *
 * @param int    $user_id   WordPress user ID.
 * @param string $old_email The user's previous email address.
 * @param string $new_email The user's new email address.
 */
function pmprocc_handle_email_change( $user_id, $old_email, $new_email ) {
	$api = PMPro_Constant_Contact_API::get_instance();
	if ( ! $api->is_connected() ) {
		return;
	}

	// Find the existing contact. Prefer the stored ID, but fall back to a lookup
	// by the old email so we can recover the contact either way. We must fetch the
	// full resource (list_memberships, custom_fields, phone_numbers,
	// street_addresses) because the v3 PUT below is a full replacement.
	$include    = 'list_memberships,custom_fields,phone_numbers,street_addresses';
	$contact_id = get_user_meta( $user_id, 'pmprocc_contact_id', true );
	$contact    = '';
	if ( ! empty( $contact_id ) ) {
		$contact = $api->get_contact( $contact_id, $include );
		if ( is_wp_error( $contact ) ) {
			$contact = '';
		}
	}
	if ( empty( $contact ) ) {
		// Request the same full resource set as above so the full-replacement PUT
		// below does not wipe phone_numbers / street_addresses.
		$contact    = $api->get_contact_by_email( $old_email, $include );
		$contact_id = ! empty( $contact['contact_id'] ) ? $contact['contact_id'] : '';
	}

	if ( empty( $contact_id ) || empty( $contact ) ) {
		pmprocc_log( "Email change for user {$user_id}: no existing contact found for " . pmprocc_mask_email( $old_email ) . '.' );
		return;
	}

	// The v3 Contacts API has no PATCH; a single contact is updated with a
	// full-replacement PUT, meaning any updatable property omitted from the body
	// is overwritten with null. We therefore rebuild the complete resource from
	// the GET above, swap in the new email_address, set update_source, and carry
	// over list_memberships, custom_fields, phone_numbers, and street_addresses
	// so they are not wiped. Read-only / non-writable properties (contact_id,
	// created_at, updated_at, taggings, and *_score / activity metadata) are
	// stripped before sending to avoid a 400.
	// Preserve the contact's prior opt-in state. Because the PUT fully replaces
	// the email_address object, omitting permission_to_send would let Constant
	// Contact reset a previously confirmed/unsubscribed contact to its default.
	$prev_permission = isset( $contact['email_address']['permission_to_send'] ) ? $contact['email_address']['permission_to_send'] : '';

	$put_data = $contact;
	unset(
		$put_data['contact_id'],
		$put_data['created_at'],
		$put_data['updated_at'],
		$put_data['taggings'],
		$put_data['email_address'],
		$put_data['notes'],
		$put_data['sms_channel'],
		$put_data['confirmed']
	);

	$put_data['email_address'] = array( 'address' => $new_email );
	if ( ! empty( $prev_permission ) ) {
		$put_data['email_address']['permission_to_send'] = $prev_permission;
	}
	$put_data['update_source'] = 'Account';

	// The v3 PUT /contacts/{id} requires list_memberships with at least one entry;
	// an empty array returns a 400. A contact with no list memberships (e.g. it was
	// only ever tagged, or was removed from every configured list) cannot be
	// reconciled this way, so skip the PUT rather than silently failing on a 400.
	if ( empty( $put_data['list_memberships'] ) ) {
		pmprocc_log( "Email change for user {$user_id}: contact {$contact_id} has no list memberships; cannot reconcile email from " . pmprocc_mask_email( $old_email ) . ' to ' . pmprocc_mask_email( $new_email ) . ' via PUT.' );
		return;
	}

	$result = $api->update_contact( $contact_id, $put_data );

	if ( is_wp_error( $result ) ) {
		pmprocc_log( "Failed to update email for contact {$contact_id} (user {$user_id}): " . $result->get_error_message() );
		return;
	}

	pmprocc_log( "Updated contact {$contact_id} email from " . pmprocc_mask_email( $old_email ) . ' to ' . pmprocc_mask_email( $new_email ) . '.' );
}

/**
 * Sync when a user-fields panel is saved on the PMPro Edit Member screen.
 *
 * PMPro's user fields panel saves directly to user meta without firing
 * profile_update, so we detect the panel save and trigger the sync. Runs at
 * priority 20 to run after PMPro's save at priority 10.
 */
function pmprocc_admin_member_edit() {
	// Only act on the PMPro Edit Member page.
	if ( empty( $_REQUEST['page'] ) || 'pmpro-member' !== $_REQUEST['page'] ) {
		return;
	}

	// Only act on POST requests.
	if ( empty( $_POST ) ) {
		return;
	}

	// Only act when a user-fields panel is being saved.
	$panel_slug = isset( $_REQUEST['pmpro_member_edit_panel'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['pmpro_member_edit_panel'] ) ) : '';
	if ( empty( $panel_slug ) || 0 !== strpos( $panel_slug, 'user-fields-' ) ) {
		return;
	}

	// Verify the panel nonce.
	$nonce = isset( $_REQUEST['pmpro_member_edit_saved_panel_nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['pmpro_member_edit_saved_panel_nonce'] ) ) : '';
	if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, 'pmpro_member_edit_saved_panel_' . $panel_slug ) ) {
		return;
	}

	// Get the user ID.
	$user_id = isset( $_REQUEST['user_id'] ) ? intval( wp_unslash( $_REQUEST['user_id'] ) ) : 0;
	if ( empty( $user_id ) ) {
		return;
	}

	// Respect the sync-on-profile-update setting.
	$options             = get_option( 'pmprocc_options', array() );
	$sync_profile_update = isset( $options['sync_profile_update'] ) ? $options['sync_profile_update'] : 'yes';
	if ( 'no' === $sync_profile_update ) {
		return;
	}

	pmprocc_enqueue_sync_for_user( $user_id, 'yes' === $sync_profile_update );
}
add_action( 'admin_init', 'pmprocc_admin_member_edit', 20 );
