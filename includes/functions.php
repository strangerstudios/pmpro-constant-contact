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
 * Creates or updates the contact, manages list memberships
 * based on membership levels, and optionally manages tags.
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

	$options = get_option( 'pmprocc_options', array() );

	// Get the user's current membership levels.
	$levels    = pmpro_getMembershipLevelsForUser( $user_id );
	$level_ids = wp_list_pluck( $levels, 'id' );

	pmprocc_log( "Syncing user {$user_id} ({$user->user_email}), levels: " . implode( ',', $level_ids ) );

	// ------------------------------------------------------------------
	// Build list membership array.
	// ------------------------------------------------------------------
	$subscribe_lists = array();

	// Lists for current levels.
	foreach ( $level_ids as $lid ) {
		$level_lists = ! empty( $options[ 'level_' . $lid . '_lists' ] ) ? $options[ 'level_' . $lid . '_lists' ] : array();
		$subscribe_lists = array_merge( $subscribe_lists, $level_lists );
	}

	// If no membership, add to non-member lists.
	if ( empty( $level_ids ) ) {
		$nonmember_lists = ! empty( $options['users_lists'] ) ? $options['users_lists'] : array();
		$subscribe_lists = array_merge( $subscribe_lists, $nonmember_lists );
	}

	$subscribe_lists = array_unique( array_filter( $subscribe_lists ) );

	// ------------------------------------------------------------------
	// Build custom fields.
	// ------------------------------------------------------------------
	$field_map     = get_option( 'pmprocc_custom_field_map', array() );
	$custom_fields = array();

	if ( ! empty( $field_map['pmpro_level_id'] ) ) {
		$custom_fields[] = array(
			'custom_field_id' => $field_map['pmpro_level_id'],
			'value'           => ! empty( $level_ids ) ? implode( ',', $level_ids ) : '',
		);
	}

	if ( ! empty( $field_map['pmpro_level_name'] ) ) {
		$level_names = wp_list_pluck( $levels, 'name' );
		$custom_fields[] = array(
			'custom_field_id' => $field_map['pmpro_level_name'],
			'value'           => ! empty( $level_names ) ? implode( ', ', $level_names ) : '',
		);
	}

	/**
	 * Filter custom fields sent to Constant Contact for a user.
	 *
	 * @param array    $custom_fields Array of custom field data.
	 * @param WP_User  $user          The WordPress user.
	 * @param array    $levels        The user's membership levels.
	 */
	$custom_fields = apply_filters( 'pmprocc_custom_fields', $custom_fields, $user, $levels );

	// ------------------------------------------------------------------
	// Upsert the contact.
	// ------------------------------------------------------------------
	$contact_data = array(
		'email_address'    => $user->user_email,
		'create_source'    => 'Account',
		'list_memberships' => array_values( $subscribe_lists ),
	);

	// The sign_up_form endpoint rejects empty name fields.
	if ( '' !== $user->first_name ) {
		$contact_data['first_name'] = $user->first_name;
	}
	if ( '' !== $user->last_name ) {
		$contact_data['last_name'] = $user->last_name;
	}

	if ( ! empty( $custom_fields ) ) {
		$contact_data['custom_fields'] = $custom_fields;
	}

	/**
	 * Filter contact data before sending to Constant Contact.
	 *
	 * @param array   $contact_data Contact data for the upsert.
	 * @param WP_User $user         The WordPress user.
	 * @param array   $levels       The user's membership levels.
	 */
	$contact_data = apply_filters( 'pmprocc_contact_data', $contact_data, $user, $levels );

	if ( ! empty( $contact_data['list_memberships'] ) ) {
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
	} else {
		// The sign_up_form endpoint requires at least one list, so we can't upsert.
		// Look up the existing contact so the list removals and tag changes below
		// still run (e.g. a member cancels and no non-member lists are configured).
		$contact    = $api->get_contact_by_email( $user->user_email );
		$contact_id = ! empty( $contact['contact_id'] ) ? $contact['contact_id'] : '';
		pmprocc_log( "No lists apply to user {$user_id}; skipped upsert. Existing contact: " . ( $contact_id ? $contact_id : 'none' ) );
	}

	// ------------------------------------------------------------------
	// Handle unsubscription from old level lists.
	// ------------------------------------------------------------------
	if ( ! empty( $options['unsubscribe'] ) && 'no' !== $options['unsubscribe'] && $contact_id ) {
		$all_configured_lists = pmprocc_get_all_configured_lists();
		$lists_to_remove      = array_diff( $all_configured_lists, $subscribe_lists );

		if ( ! empty( $lists_to_remove ) ) {
			$api->remove_contacts_from_lists( array( $contact_id ), array_values( $lists_to_remove ) );
			pmprocc_log( "Removed contact {$contact_id} from lists: " . implode( ',', $lists_to_remove ) );
		}
	}

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

	// Get all controlled tags (any tag mapped to any level).
	$controlled_tags = pmprocc_get_all_configured_tags();

	// Get the contact's current tags.
	$contact = $api->get_contact_by_email( get_userdata( $user_id )->user_email );
	$current_tags = ! empty( $contact['taggings'] ) ? $contact['taggings'] : array();

	// Only look at controlled tags.
	$current_controlled = array_intersect( $current_tags, $controlled_tags );

	// Tags to add.
	$tags_to_add = array_diff( $required_tags, $current_controlled );
	if ( ! empty( $tags_to_add ) ) {
		$api->add_tags_to_contacts( array( $contact_id ), array_values( $tags_to_add ) );
		pmprocc_log( "Added tags to contact {$contact_id}: " . implode( ',', $tags_to_add ) );
	}

	// Tags to remove.
	if ( ! empty( $options['remove_tags'] ) ) {
		$tags_to_remove = array_diff( $current_controlled, $required_tags );
		if ( ! empty( $tags_to_remove ) ) {
			$api->remove_tags_from_contacts( array( $contact_id ), array_values( $tags_to_remove ) );
			pmprocc_log( "Removed tags from contact {$contact_id}: " . implode( ',', $tags_to_remove ) );
		}
	}
}

// ------------------------------------------------------------------
// Helpers
// ------------------------------------------------------------------

/**
 * Get all list IDs configured across all levels + non-member lists.
 *
 * @return array
 */
function pmprocc_get_all_configured_lists() {
	$options   = get_option( 'pmprocc_options', array() );
	$all_lists = array();

	// Non-member lists.
	if ( ! empty( $options['users_lists'] ) ) {
		$all_lists = array_merge( $all_lists, $options['users_lists'] );
	}

	// Level-specific lists.
	$levels = pmpro_getAllLevels( true, true );
	foreach ( $levels as $level ) {
		$key = 'level_' . $level->id . '_lists';
		if ( ! empty( $options[ $key ] ) ) {
			$all_lists = array_merge( $all_lists, $options[ $key ] );
		}
	}

	return array_unique( array_filter( $all_lists ) );
}

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
 * @param int $user_id WordPress user ID.
 */
function pmprocc_profile_update( $user_id ) {
	$options = get_option( 'pmprocc_options', array() );

	if ( empty( $options['sync_profile_update'] ) || 'no' === $options['sync_profile_update'] ) {
		return;
	}

	$update_tags = ( 'yes' === $options['sync_profile_update'] );
	pmprocc_enqueue_sync_for_user( $user_id, $update_tags );
}
add_action( 'profile_update', 'pmprocc_profile_update' );

/**
 * Sync when admin saves a member's profile via PMPro Edit Member.
 */
function pmprocc_admin_member_edit() {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked by PMPro.
	if ( empty( $_POST['pmpro_member_edit_panel'] ) || empty( $_POST['user_id'] ) ) {
		return;
	}
	pmprocc_enqueue_sync_for_user( intval( $_POST['user_id'] ), true );
}
add_action( 'admin_init', 'pmprocc_admin_member_edit', 20 );

/**
 * Subscribe new non-member users to non-member lists.
 *
 * @param int $user_id New user ID.
 */
function pmprocc_user_register( $user_id ) {
	$options = get_option( 'pmprocc_options', array() );

	// Don't sync during checkout — pmpro_after_all_membership_level_changes handles that.
	if ( did_action( 'pmpro_checkout_before_change_membership_level' ) ) {
		return;
	}

	if ( empty( $options['users_lists'] ) ) {
		return;
	}

	pmprocc_enqueue_sync_for_user( $user_id, false );
}
add_action( 'user_register', 'pmprocc_user_register' );
