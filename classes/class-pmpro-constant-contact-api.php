<?php
/**
 * Constant Contact v3 API wrapper.
 *
 * Handles OAuth 2.0 authentication (client secret via Basic auth, or PKCE
 * for public clients) and all API calls to the Constant Contact v3 REST API.
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
		$redirect_uri = admin_url( 'admin.php?page=pmpro-constantcontact&pmprocc_oauth_callback=1' );

		$response = $this->token_request( array(
			'grant_type'    => 'authorization_code',
			'code'          => $code,
			'redirect_uri'  => $redirect_uri,
			'code_verifier' => $verifier,
		) );

		if ( is_wp_error( $response ) ) {
			pmprocc_log( 'Token exchange error: ' . $response->get_error_message() );
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['access_token'] ) ) {
			// Log only the parsed error fields, never the raw response body, which
			// can echo back submitted parameters or token material.
			$error = $this->parse_token_error( $body, $response );
			pmprocc_log( 'Token exchange failed: ' . $error );
			// Surface the OAuth error on the settings page so admins can see why
			// the connection failed without needing to enable debug logging first.
			set_transient( 'pmprocc_last_token_error', $error, 5 * MINUTE_IN_SECONDS );
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

		$response = $this->token_request( array(
			'grant_type'    => 'refresh_token',
			'refresh_token' => $tokens['refresh_token'],
		) );

		if ( is_wp_error( $response ) ) {
			pmprocc_log( 'Token refresh error: ' . $response->get_error_message() );
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['access_token'] ) ) {
			// Log only the parsed error fields, never the raw response body, which
			// can echo back submitted parameters or token material.
			pmprocc_log( 'Token refresh failed: ' . $this->parse_token_error( $body, $response ) );
			// Only disconnect if the refresh token itself was rejected.
			// Keep tokens on transient failures (network issues, 5xx) so we can retry later.
			if ( ! empty( $body['error'] ) && 'invalid_grant' === $body['error'] ) {
				$this->disconnect();
			}
			return false;
		}

		$this->store_tokens( $body );
		$this->access_token = $body['access_token'];

		return true;
	}

	/**
	 * POST to the OAuth token endpoint with the correct client authentication.
	 *
	 * Constant Contact apps created with a client secret (the developer portal
	 * default) are confidential clients: the token endpoint requires the client
	 * ID and secret via HTTP Basic auth and rejects secret-less requests with
	 * "invalid_client". Apps created as public clients instead authenticate with
	 * PKCE only and pass the client_id in the request body. Support both: use
	 * Basic auth when a secret is configured, otherwise fall back to PKCE-only.
	 *
	 * @param array $body Grant-specific body parameters.
	 * @return array|WP_Error Raw wp_remote_post() response.
	 */
	private function token_request( $body ) {
		$options    = get_option( 'pmprocc_options', array() );
		$api_key    = ! empty( $options['api_key'] ) ? $options['api_key'] : '';
		$api_secret = ! empty( $options['api_secret'] ) ? $options['api_secret'] : '';

		$headers = array(
			'Accept'       => 'application/json',
			'Content-Type' => 'application/x-www-form-urlencoded',
		);

		if ( ! empty( $api_secret ) ) {
			// Confidential client: authenticate with Basic auth. The client_id
			// must not also be sent in the body or Okta rejects the request for
			// supplying multiple client credentials.
			$headers['Authorization'] = 'Basic ' . base64_encode( $api_key . ':' . $api_secret );
		} else {
			// Public (PKCE) client: client_id goes in the body.
			$body['client_id'] = $api_key;
		}

		return wp_remote_post( $this->token_url, array(
			'headers' => $headers,
			'body'    => $body,
			'timeout' => 15,
		) );
	}

	/**
	 * Store tokens in the database.
	 *
	 * @param array $token_data Response from token endpoint.
	 */
	private function store_tokens( $token_data ) {
		$existing = get_option( 'pmprocc_tokens', array() );

		// Constant Contact rotates refresh tokens, but if a response omits one,
		// keep the token we already have rather than losing it.
		if ( ! empty( $token_data['refresh_token'] ) ) {
			$refresh_token = $token_data['refresh_token'];
		} else {
			$refresh_token = ! empty( $existing['refresh_token'] ) ? $existing['refresh_token'] : '';
		}

		$tokens = array(
			'access_token'  => $token_data['access_token'],
			'refresh_token' => $refresh_token,
			// Constant Contact access tokens last 28800 seconds (8 hours); use that
			// as the fallback if the response omits expires_in.
			'expires_at'    => time() + ( ! empty( $token_data['expires_in'] ) ? intval( $token_data['expires_in'] ) : 28800 ),
		);
		update_option( 'pmprocc_tokens', $tokens, false );
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
			// Use http_build_query() rather than add_query_arg(), which does not
			// URL-encode values — emails containing '+' and pagination cursors
			// would otherwise be corrupted in transit.
			$url .= ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $query, '', '&' );
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

		if ( ! empty( $body ) && in_array( $method, array( 'POST', 'PUT' ), true ) ) {
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
			$error_message = $this->parse_error_message( $data, $code );
			pmprocc_log( "API error ({$method} {$endpoint}): {$error_message}" );
			return new WP_Error( 'api_error', $error_message, array( 'status' => $code, 'response' => $data ) );
		}

		return $data;
	}

	/**
	 * Extract a human-readable error message from a v3 API error response.
	 *
	 * Constant Contact v3 returns most errors as a JSON array of objects,
	 * e.g. [ { "error_key": "...", "error_message": "..." } ], while the
	 * OAuth/token endpoints return an object ( { "error": "...",
	 * "error_description": "..." } ). Handle both, falling back to "HTTP {code}".
	 *
	 * @param mixed $data The decoded response body.
	 * @param int   $code The HTTP status code.
	 * @return string
	 */
	private function parse_error_message( $data, $code ) {
		if ( is_array( $data ) ) {
			// Array of error objects (most v3 endpoints).
			if ( isset( $data[0] ) && is_array( $data[0] ) ) {
				if ( ! empty( $data[0]['error_message'] ) ) {
					return $data[0]['error_message'];
				}
				if ( ! empty( $data[0]['error_key'] ) ) {
					return $data[0]['error_key'];
				}
			}

			// Object form (token/OAuth endpoints, or single error object).
			if ( ! empty( $data['error_message'] ) ) {
				return $data['error_message'];
			}
			if ( ! empty( $data['error_description'] ) ) {
				return $data['error_description'];
			}
			if ( ! empty( $data['error'] ) ) {
				return $data['error'];
			}
		}

		return "HTTP {$code}";
	}

	/**
	 * Build a safe, non-sensitive log message for an OAuth/token endpoint failure.
	 *
	 * The token endpoint can echo submitted parameters or token material back in
	 * its body, so we surface only the standard OAuth error fields and the HTTP
	 * status code rather than the raw response body.
	 *
	 * @param array|null $body     Decoded JSON response body, if any.
	 * @param array      $response Raw wp_remote_* response.
	 * @return string Sanitized error message.
	 */
	private function parse_token_error( $body, $response ) {
		$parts = array();

		if ( is_array( $body ) ) {
			if ( ! empty( $body['error'] ) && is_string( $body['error'] ) ) {
				$parts[] = $body['error'];
			}
			if ( ! empty( $body['error_description'] ) && is_string( $body['error_description'] ) ) {
				$parts[] = $body['error_description'];
			}
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code ) {
			$parts[] = "HTTP {$code}";
		}

		return ! empty( $parts ) ? implode( ' - ', $parts ) : __( 'Unknown error.', 'pmpro-constant-contact' );
	}

	/**
	 * Convert a pagination link href into a relative endpoint and query args.
	 *
	 * The v3 API returns _links.next.href values that include the /v3 prefix
	 * (e.g. '/v3/contact_lists?cursor=...'), which must be stripped before
	 * passing back into request().
	 *
	 * @param string $href  The _links href value.
	 * @param array  $query Filled with the parsed query args.
	 * @return string|null Relative endpoint, or null if it could not be parsed.
	 */
	private function get_endpoint_from_link( $href, &$query ) {
		$query  = array();
		$parsed = wp_parse_url( $href );

		if ( empty( $parsed['path'] ) ) {
			return null;
		}

		$endpoint = preg_replace( '#^/v3(?=/|$)#', '', $parsed['path'] );

		if ( ! empty( $parsed['query'] ) ) {
			wp_parse_str( $parsed['query'], $query );
		}

		return $endpoint;
	}

	// ------------------------------------------------------------------
	// Contact Lists
	// ------------------------------------------------------------------

	/**
	 * Get all contact lists.
	 *
	 * @param bool $force_refresh Skip cache.
	 * @return array|WP_Error Array of lists, or WP_Error on API failure.
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
		$query     = array( 'include_membership_count' => 'active' );

		// Paginate through results.
		while ( $endpoint ) {
			$result = $this->request( $endpoint, 'GET', array(), $query );
			if ( is_wp_error( $result ) ) {
				// Surface the error to the caller so the settings page can show a
				// real diagnostic instead of an empty "No items found" list.
				return $result;
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
				$endpoint = $this->get_endpoint_from_link( $result['_links']['next']['href'], $query );
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
	 * @return array|WP_Error Array of tags, or WP_Error on API failure.
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
				// Surface the error to the caller so the settings page can show a
				// real diagnostic instead of an empty "No items found" list.
				return $result;
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
				$endpoint = $this->get_endpoint_from_link( $result['_links']['next']['href'], $query );
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
	 * @param string $email   Email address.
	 * @param string $include Comma-separated list of sub-resources to include.
	 * @return array|null Contact data or null if not found.
	 */
	public function get_contact_by_email( $email, $include = 'list_memberships,taggings,custom_fields' ) {
		$result = $this->request( '/contacts', 'GET', array(), array(
			'email'   => $email,
			'include' => $include,
			'status'  => 'all',
		) );

		if ( is_wp_error( $result ) || empty( $result['contacts'] ) ) {
			return null;
		}

		return $result['contacts'][0];
	}

	/**
	 * Get a single contact by ID.
	 *
	 * @param string $contact_id Contact ID.
	 * @param string $include    Comma-separated list of sub-resources to include.
	 * @return array|WP_Error Response data.
	 */
	public function get_contact( $contact_id, $include = '' ) {
		$query = array();
		if ( ! empty( $include ) ) {
			$query['include'] = $include;
		}
		return $this->request( '/contacts/' . $contact_id, 'GET', array(), $query );
	}

	/**
	 * Update an existing contact (full replace via PUT).
	 *
	 * The v3 Contacts API only supports a full-replacement PUT for updating a
	 * single contact (there is no PATCH endpoint), so callers must supply the
	 * complete contact resource, including any fields that should be retained.
	 *
	 * @param string $contact_id Contact ID.
	 * @param array  $data       Full contact resource to write.
	 * @return array|WP_Error Response data.
	 */
	public function update_contact( $contact_id, $data ) {
		return $this->request( '/contacts/' . $contact_id, 'PUT', $data );
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

}
