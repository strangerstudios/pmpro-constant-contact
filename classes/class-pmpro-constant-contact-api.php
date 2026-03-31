<?php
/**
 * Constant Contact v3 API wrapper.
 *
 * Handles OAuth 2.0 PKCE authentication and all API calls
 * to the Constant Contact v3 REST API.
 *
 * @since 2.0
 */

defined( 'ABSPATH' ) || exit;

class PMPro_Constant_Contact_API {

	/**
	 * Singleton instance.
	 *
	 * @var PMPro_Constant_Contact_API|null
	 */
	private static $instance = null;

	/**
	 * API base URL.
	 *
	 * @var string
	 */
	private $api_url = 'https://api.cc.email/v3';

	/**
	 * OAuth authorization URL.
	 *
	 * @var string
	 */
	private $auth_url = 'https://authz.constantcontact.com/oauth2/default/v1/authorize';

	/**
	 * OAuth token URL.
	 *
	 * @var string
	 */
	private $token_url = 'https://authz.constantcontact.com/oauth2/default/v1/token';

	/**
	 * Stored access token.
	 *
	 * @var string
	 */
	private $access_token = '';

	/**
	 * Whether the API is connected and ready.
	 *
	 * @var bool
	 */
	private $connected = false;

	/**
	 * Cached lists.
	 *
	 * @var array|null
	 */
	private $lists_cache = null;

	/**
	 * Cached tags.
	 *
	 * @var array|null
	 */
	private $tags_cache = null;

	/**
	 * Cached custom fields.
	 *
	 * @var array|null
	 */
	private $custom_fields_cache = null;

	/**
	 * Get singleton instance.
	 *
	 * @return PMPro_Constant_Contact_API|null
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor. Loads stored tokens.
	 */
	private function __construct() {
		$tokens = get_option( 'pmprocc_tokens', array() );
		if ( ! empty( $tokens['access_token'] ) ) {
			// Check if token is expired and refresh if needed.
			if ( ! empty( $tokens['expires_at'] ) && time() >= ( $tokens['expires_at'] - 300 ) ) {
				$refreshed = $this->refresh_access_token();
				if ( $refreshed ) {
					$tokens = get_option( 'pmprocc_tokens', array() );
				}
			}
			$this->access_token = $tokens['access_token'];
			$this->connected    = true;
		}
	}

	/**
	 * Check if the API is connected.
	 *
	 * @return bool
	 */
	public function is_connected() {
		return $this->connected;
	}

	/**
	 * Get the OAuth authorization URL.
	 *
	 * Uses PKCE flow so no client_secret is needed.
	 *
	 * @return string
	 */
	public function get_authorization_url() {
		$options = get_option( 'pmprocc_options', array() );
		$api_key = ! empty( $options['api_key'] ) ? $options['api_key'] : '';

		if ( empty( $api_key ) ) {
			return '';
		}

		// Generate PKCE verifier and challenge.
		$verifier  = wp_generate_password( 64, false );
		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );

		// Store verifier and state for callback validation.
		$state = wp_generate_password( 32, false );
		set_transient( 'pmprocc_oauth_verifier', $verifier, HOUR_IN_SECONDS );
		set_transient( 'pmprocc_oauth_state', $state, HOUR_IN_SECONDS );

		$redirect_uri = admin_url( 'admin.php?page=pmpro-constantcontact&pmprocc_oauth_callback=1' );

		$params = array(
			'client_id'             => $api_key,
			'redirect_uri'          => $redirect_uri,
			'response_type'         => 'code',
			'scope'                 => 'contact_data offline_access',
			'state'                 => $state,
			'code_challenge'        => $challenge,
			'code_challenge_method' => 'S256',
		);

		return $this->auth_url . '?' . http_build_query( $params );
	}

	/**
	 * Exchange an authorization code for tokens.
	 *
	 * @param string $code     The authorization code.
	 * @param string $verifier The PKCE code verifier.
	 * @return bool True on success.
	 */
	public function exchange_code( $code, $verifier ) {
		$options      = get_option( 'pmprocc_options', array() );
		$api_key      = ! empty( $options['api_key'] ) ? $options['api_key'] : '';
		$redirect_uri = admin_url( 'admin.php?page=pmpro-constantcontact&pmprocc_oauth_callback=1' );

		$response = wp_remote_post( $this->token_url, array(
			'body' => array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'redirect_uri'  => $redirect_uri,
				'client_id'     => $api_key,
				'code_verifier' => $verifier,
			),
			'timeout' => 15,
		) );

		if ( is_wp_error( $response ) ) {
			pmprocc_log( 'Token exchange error: ' . $response->get_error_message() );
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['access_token'] ) ) {
			pmprocc_log( 'Token exchange failed: ' . wp_remote_retrieve_body( $response ) );
			return false;
		}

		$this->store_tokens( $body );
		$this->access_token = $body['access_token'];
		$this->connected    = true;

		return true;
	}

	/**
	 * Refresh the access token using the refresh token.
	 *
	 * @return bool True on success.
	 */
	public function refresh_access_token() {
		$tokens  = get_option( 'pmprocc_tokens', array() );
		$options = get_option( 'pmprocc_options', array() );
		$api_key = ! empty( $options['api_key'] ) ? $options['api_key'] : '';

		if ( empty( $tokens['refresh_token'] ) || empty( $api_key ) ) {
			return false;
		}

		$response = wp_remote_post( $this->token_url, array(
			'body' => array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $tokens['refresh_token'],
				'client_id'     => $api_key,
			),
			'timeout' => 15,
		) );

		if ( is_wp_error( $response ) ) {
			pmprocc_log( 'Token refresh error: ' . $response->get_error_message() );
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['access_token'] ) ) {
			pmprocc_log( 'Token refresh failed: ' . wp_remote_retrieve_body( $response ) );
			// If refresh fails, disconnect.
			$this->disconnect();
			return false;
		}

		$this->store_tokens( $body );
		$this->access_token = $body['access_token'];

		return true;
	}

	/**
	 * Store tokens in the database.
	 *
	 * @param array $token_data Response from token endpoint.
	 */
	private function store_tokens( $token_data ) {
		$tokens = array(
			'access_token'  => $token_data['access_token'],
			'refresh_token' => ! empty( $token_data['refresh_token'] ) ? $token_data['refresh_token'] : '',
			'expires_at'    => time() + ( ! empty( $token_data['expires_in'] ) ? intval( $token_data['expires_in'] ) : 86400 ),
		);
		update_option( 'pmprocc_tokens', $tokens );
	}

	/**
	 * Disconnect from Constant Contact (clear tokens).
	 */
	public function disconnect() {
		delete_option( 'pmprocc_tokens' );
		$this->access_token = '';
		$this->connected    = false;
	}

	/**
	 * Make an API request.
	 *
	 * @param string $endpoint Relative endpoint (e.g. '/contacts').
	 * @param string $method   HTTP method.
	 * @param array  $body     Request body (for POST/PUT).
	 * @param array  $query    Query parameters.
	 * @return array|WP_Error Decoded response body or error.
	 */
	public function request( $endpoint, $method = 'GET', $body = array(), $query = array() ) {
		if ( ! $this->connected || empty( $this->access_token ) ) {
			return new WP_Error( 'not_connected', __( 'Not connected to Constant Contact.', 'pmpro-constant-contact' ) );
		}

		$url = $this->api_url . $endpoint;
		if ( ! empty( $query ) ) {
			$url = add_query_arg( $query, $url );
		}

		$args = array(
			'method'  => $method,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->access_token,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
			'timeout' => 15,
		);

		if ( ! empty( $body ) && in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			pmprocc_log( "API error ({$method} {$endpoint}): " . $response->get_error_message() );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		// Handle 401 — try refreshing the token once.
		if ( 401 === $code ) {
			$refreshed = $this->refresh_access_token();
			if ( $refreshed ) {
				$args['headers']['Authorization'] = 'Bearer ' . $this->access_token;
				$response = wp_remote_request( $url, $args );
				if ( is_wp_error( $response ) ) {
					return $response;
				}
				$code = wp_remote_retrieve_response_code( $response );
				$data = json_decode( wp_remote_retrieve_body( $response ), true );
			}
		}

		if ( $code < 200 || $code >= 300 ) {
			$error_message = ! empty( $data['error_message'] ) ? $data['error_message'] : "HTTP {$code}";
			pmprocc_log( "API error ({$method} {$endpoint}): {$error_message}" );
			return new WP_Error( 'api_error', $error_message, array( 'status' => $code, 'response' => $data ) );
		}

		return $data;
	}

	// ------------------------------------------------------------------
	// Contact Lists
	// ------------------------------------------------------------------

	/**
	 * Get all contact lists.
	 *
	 * @param bool $force_refresh Skip cache.
	 * @return array
	 */
	public function get_lists( $force_refresh = false ) {
		if ( null !== $this->lists_cache && ! $force_refresh ) {
			return $this->lists_cache;
		}

		$cached = get_transient( 'pmprocc_all_lists' );
		if ( false !== $cached && ! $force_refresh ) {
			$this->lists_cache = $cached;
			return $cached;
		}

		$all_lists = array();
		$endpoint  = '/contact_lists';
		$query     = array( 'include_count' => 'true' );

		// Paginate through results.
		while ( $endpoint ) {
			$result = $this->request( $endpoint, 'GET', array(), $query );
			if ( is_wp_error( $result ) ) {
				return array();
			}

			if ( ! empty( $result['lists'] ) ) {
				foreach ( $result['lists'] as $list ) {
					$all_lists[] = array(
						'list_id'       => $list['list_id'],
						'name'          => $list['name'],
						'member_count'  => ! empty( $list['membership_count'] ) ? $list['membership_count'] : 0,
					);
				}
			}

			// Check for next page.
			$endpoint = null;
			$query    = array();
			if ( ! empty( $result['_links']['next']['href'] ) ) {
				$next_url = $result['_links']['next']['href'];
				$parsed   = wp_parse_url( $next_url );
				$endpoint = $parsed['path'];
				if ( ! empty( $parsed['query'] ) ) {
					wp_parse_str( $parsed['query'], $query );
				}
			}
		}

		usort( $all_lists, function( $a, $b ) {
			return strcasecmp( $a['name'], $b['name'] );
		} );

		$this->lists_cache = $all_lists;
		set_transient( 'pmprocc_all_lists', $all_lists, 12 * HOUR_IN_SECONDS );

		return $all_lists;
	}

	// ------------------------------------------------------------------
	// Tags
	// ------------------------------------------------------------------

	/**
	 * Get all tags.
	 *
	 * @param bool $force_refresh Skip cache.
	 * @return array
	 */
	public function get_tags( $force_refresh = false ) {
		if ( null !== $this->tags_cache && ! $force_refresh ) {
			return $this->tags_cache;
		}

		$cached = get_transient( 'pmprocc_all_tags' );
		if ( false !== $cached && ! $force_refresh ) {
			$this->tags_cache = $cached;
			return $cached;
		}

		$all_tags = array();
		$endpoint = '/contact_tags';
		$query    = array( 'limit' => 500 );

		while ( $endpoint ) {
			$result = $this->request( $endpoint, 'GET', array(), $query );
			if ( is_wp_error( $result ) ) {
				return array();
			}

			if ( ! empty( $result['tags'] ) ) {
				foreach ( $result['tags'] as $tag ) {
					$all_tags[] = array(
						'tag_id' => $tag['tag_id'],
						'name'   => $tag['name'],
					);
				}
			}

			$endpoint = null;
			$query    = array();
			if ( ! empty( $result['_links']['next']['href'] ) ) {
				$next_url = $result['_links']['next']['href'];
				$parsed   = wp_parse_url( $next_url );
				$endpoint = $parsed['path'];
				if ( ! empty( $parsed['query'] ) ) {
					wp_parse_str( $parsed['query'], $query );
				}
			}
		}

		usort( $all_tags, function( $a, $b ) {
			return strcasecmp( $a['name'], $b['name'] );
		} );

		$this->tags_cache = $all_tags;
		set_transient( 'pmprocc_all_tags', $all_tags, 12 * HOUR_IN_SECONDS );

		return $all_tags;
	}

	// ------------------------------------------------------------------
	// Custom Fields
	// ------------------------------------------------------------------

	/**
	 * Get all custom fields.
	 *
	 * @param bool $force_refresh Skip cache.
	 * @return array
	 */
	public function get_custom_fields( $force_refresh = false ) {
		if ( null !== $this->custom_fields_cache && ! $force_refresh ) {
			return $this->custom_fields_cache;
		}

		$result = $this->request( '/contact_custom_fields' );
		if ( is_wp_error( $result ) ) {
			return array();
		}

		$fields = ! empty( $result['custom_fields'] ) ? $result['custom_fields'] : array();
		$this->custom_fields_cache = $fields;

		return $fields;
	}

	/**
	 * Ensure our custom fields exist in Constant Contact.
	 *
	 * Creates 'pmpro_level_id' and 'pmpro_level_name' fields if they don't exist.
	 *
	 * @return array Map of field name => custom_field_id.
	 */
	public function ensure_custom_fields() {
		$fields = $this->get_custom_fields( true );
		$map    = array();

		foreach ( $fields as $field ) {
			if ( in_array( $field['label'], array( 'pmpro_level_id', 'pmpro_level_name' ), true ) ) {
				$map[ $field['label'] ] = $field['custom_field_id'];
			}
		}

		$needed = array(
			'pmpro_level_id'   => 'string',
			'pmpro_level_name' => 'string',
		);

		foreach ( $needed as $label => $type ) {
			if ( empty( $map[ $label ] ) ) {
				$result = $this->request( '/contact_custom_fields', 'POST', array(
					'label' => $label,
					'type'  => $type,
				) );
				if ( ! is_wp_error( $result ) && ! empty( $result['custom_field_id'] ) ) {
					$map[ $label ] = $result['custom_field_id'];
				}
			}
		}

		// Cache the field ID map.
		update_option( 'pmprocc_custom_field_map', $map );

		return $map;
	}

	// ------------------------------------------------------------------
	// Contacts
	// ------------------------------------------------------------------

	/**
	 * Create or update a contact using the sign_up_form endpoint (upsert).
	 *
	 * @param array $contact_data Contact data.
	 * @return array|WP_Error Response data.
	 */
	public function upsert_contact( $contact_data ) {
		return $this->request( '/contacts/sign_up_form', 'POST', $contact_data );
	}

	/**
	 * Get a contact by email.
	 *
	 * @param string $email Email address.
	 * @return array|null Contact data or null if not found.
	 */
	public function get_contact_by_email( $email ) {
		$result = $this->request( '/contacts', 'GET', array(), array(
			'email'   => $email,
			'include' => 'list_memberships,taggings,custom_fields',
			'status'  => 'all',
		) );

		if ( is_wp_error( $result ) || empty( $result['contacts'] ) ) {
			return null;
		}

		return $result['contacts'][0];
	}

	/**
	 * Update an existing contact.
	 *
	 * @param string $contact_id Contact ID.
	 * @param array  $data       Data to update.
	 * @return array|WP_Error Response data.
	 */
	public function update_contact( $contact_id, $data ) {
		return $this->request( '/contacts/' . $contact_id, 'PUT', $data );
	}

	/**
	 * Delete (remove) a contact.
	 *
	 * @param string $contact_id Contact ID.
	 * @return array|WP_Error Response data.
	 */
	public function delete_contact( $contact_id ) {
		return $this->request( '/contacts/' . $contact_id, 'DELETE' );
	}

	// ------------------------------------------------------------------
	// Bulk Tag Operations
	// ------------------------------------------------------------------

	/**
	 * Add tags to contacts in bulk.
	 *
	 * @param array $contact_ids Array of contact IDs.
	 * @param array $tag_ids     Array of tag IDs.
	 * @return array|WP_Error Activity response.
	 */
	public function add_tags_to_contacts( $contact_ids, $tag_ids ) {
		return $this->request( '/activities/contacts_taggings_add', 'POST', array(
			'source'  => array( 'contact_ids' => $contact_ids ),
			'tag_ids' => $tag_ids,
		) );
	}

	/**
	 * Remove tags from contacts in bulk.
	 *
	 * @param array $contact_ids Array of contact IDs.
	 * @param array $tag_ids     Array of tag IDs.
	 * @return array|WP_Error Activity response.
	 */
	public function remove_tags_from_contacts( $contact_ids, $tag_ids ) {
		return $this->request( '/activities/contacts_taggings_remove', 'POST', array(
			'source'  => array( 'contact_ids' => $contact_ids ),
			'tag_ids' => $tag_ids,
		) );
	}

	// ------------------------------------------------------------------
	// Bulk List Operations
	// ------------------------------------------------------------------

	/**
	 * Remove contacts from lists in bulk.
	 *
	 * @param array $contact_ids Array of contact IDs.
	 * @param array $list_ids    Array of list IDs.
	 * @return array|WP_Error Activity response.
	 */
	public function remove_contacts_from_lists( $contact_ids, $list_ids ) {
		return $this->request( '/activities/remove_list_memberships', 'POST', array(
			'source'   => array( 'contact_ids' => $contact_ids ),
			'list_ids' => $list_ids,
		) );
	}
}
